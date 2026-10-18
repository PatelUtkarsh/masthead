<?php
/**
 * Behaviour that must hold on large sites: paging without COUNT, batching, bulk writes.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Tests;

use Masthead\Activity;
use Masthead\Boards;
use Masthead\Cards;
use Masthead\Cron;
use Masthead\Meta;
use Masthead\Notifications;
use Masthead\People;
use Masthead\StageTaxonomy;
use Masthead\Sync;
use Masthead\Workflows;
use WP_REST_Request;

final class ScaleTest extends TestCase {

	public function test_user_search_pages_without_count(): void {
		self::factory()->user->create_many( 5, [ 'role' => 'author' ] );
		$page = People::search( [ 'per_page' => 3 ] );
		$this->assertCount( 3, $page['users'] );
		$this->assertTrue( $page['has_more'] );
		$this->assertArrayNotHasKey( 'total', $page );

		$one = People::search( [ 'include' => [ self::$users['editor'] ] ] );
		$this->assertSame( self::$users['editor'], $one['users'][0]['id'] );
		$this->assertFalse( $one['has_more'] );
	}

	public function test_users_route_is_lazy_and_needs_view(): void {
		$res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/masthead/v1/users' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'has_more', $res->get_data() );

		wp_set_current_user( 0 );
		$res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/masthead/v1/users' ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_bootstrap_preloads_no_team_or_categories(): void {
		$boot = \Masthead\Admin::bootstrap();
		$this->assertCount( 1, $boot['team'] );
		$this->assertArrayNotHasKey( 'categories', $boot );
		$this->assertNull( $boot['boards'][0]['count'] );
	}

	public function test_notifications_insert_in_bulk(): void {
		$post  = $this->post_in( 'draft' );
		$event = Activity::log( $post->ID, 'note' );
		$ids   = range( 100000, 100000 + 1199 );
		Notifications::notify( $event, $ids );
		global $wpdb;
		$this->assertSame( 1200, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %d', Notifications::table(), $event ) ) );
	}

	public function test_board_columns_use_has_more_not_found_rows(): void {
		for ( $i = 0; $i < Cards::PER_PAGE + 2; $i++ ) {
			$this->post_in( 'idea' );
		}
		$board = Boards::get( Boards::default_id() );
		$cols  = Cards::for_board( $board );
		$this->assertCount( Cards::PER_PAGE, $cols['idea']['cards'] );
		$this->assertTrue( $cols['idea']['has_more'] );
		$this->assertSame( Cards::PER_PAGE + 2, $cols['idea']['total'] );

		$page2 = Cards::for_board(
			$board,
			[
				'stage' => 'idea',
				'page'  => 2,
			]
		);
		$this->assertCount( 2, $page2['idea']['cards'] );
		$this->assertFalse( $page2['idea']['has_more'] );

		$filtered = Cards::for_board( $board, [ 'search' => 'Post in idea' ] );
		$this->assertNull( $filtered['idea']['total'] );
		$this->assertTrue( $filtered['idea']['has_more'] );
	}

	public function test_import_runs_in_batches(): void {
		$ids = self::factory()->post->create_many( 5, [ 'post_status' => 'draft' ] );
		foreach ( $ids as $id ) {
			wp_delete_object_term_relationships( $id, StageTaxonomy::TAXONOMY );
		}
		$wf     = Workflows::get( 'articles' );
		$result = Sync::import_batch( $wf, 0, 2 );
		$this->assertSame( 2, $result['count'] );
		$this->assertFalse( $result['done'] );

		Cron::run_import( 'articles', $result['last'] );
		foreach ( $ids as $id ) {
			$this->assertSame( 'idea', Meta::stage( $id ) );
		}
	}

	public function test_bulk_stage_write_syncs_status(): void {
		$a  = $this->post_in( 'review' );
		$b  = $this->post_in( 'review' );
		$wf = Workflows::get( 'articles' );
		Sync::set_stage_bulk( $wf, [ $a->ID, $b->ID ], 'draft' );
		$this->assertSame( 'draft', Meta::stage( $a->ID ) );
		$this->assertSame( 'draft', get_post_status( $b->ID ) );
	}

	public function test_status_sync_overflow_is_queued(): void {
		$posts = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$posts[] = $this->post_in( 'review' )->ID;
		}
		$wf = Workflows::get( 'articles' );
		Sync::set_stage_bulk( $wf, $posts, 'draft' );
		$this->assertSame( 'draft', get_post_status( $posts[2] ) );

		$more = [ $this->post_in( 'review' )->ID, $this->post_in( 'review' )->ID ];
		foreach ( $more as $id ) {
			StageTaxonomy::set( $id, $wf, 'draft' );
		}
		Sync::sync_statuses( $wf, $more, 1 );
		$this->assertSame( 'draft', get_post_status( $more[0] ) );
		$this->assertSame( 'pending', get_post_status( $more[1] ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::STATUS_SYNC, [ 'articles', [ $more[1] ] ] ) );
	}

	public function test_calendar_unscheduled_pages(): void {
		for ( $i = 0; $i < 22; $i++ ) {
			$this->post_in( 'draft' );
		}
		$req = new WP_REST_Request( 'GET', '/masthead/v1/calendar' );
		$req->set_query_params( [ 'only' => 'unscheduled' ] );
		$data = rest_get_server()->dispatch( $req )->get_data();
		$this->assertCount( 20, $data['unscheduled'] );
		$this->assertTrue( $data['unscheduled_more'] );
	}
}
