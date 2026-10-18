<?php
/**
 * REST API tests (spec §8, acceptance 7–10, 12).
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Tests;

use Masthead\Boards;
use Masthead\Meta;
use Masthead\Notes;
use Masthead\Notifications;
use Masthead\Workflows;
use WP_REST_Request;
use WP_REST_Response;

final class RestTest extends TestCase {

	/**
	 * @param array<string, mixed> $body Body.
	 */
	private function call( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/masthead/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_move_endpoint_success_and_errors(): void {
		$post = $this->post_in( 'idea' );

		$res = $this->call( 'POST', "/posts/{$post->ID}/move", [ 'stage' => 'ready' ] );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'masthead_skip_required', $res->get_data()['code'] );

		$res = $this->call(
			'POST',
			"/posts/{$post->ID}/move",
			[
				'stage' => 'draft',
				'rev'   => Meta::rev( $post->ID ),
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'draft', $res->get_data()['post']['stage'] );
		$this->assertSame( 'Moved to Drafting', $res->get_data()['message'] );

		$res = $this->call(
			'POST',
			"/posts/{$post->ID}/move",
			[
				'stage' => 'review',
				'rev'   => 0,
			]
		);
		$this->assertSame( 409, $res->get_status() );
	}

	public function test_move_requires_login(): void {
		$post = $this->post_in( 'idea' );
		wp_set_current_user( 0 );
		$res = $this->call( 'POST', "/posts/{$post->ID}/move", [ 'stage' => 'draft' ] );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_bulk_move_reports_moved_and_blocked(): void {
		$ok1     = $this->post_in( 'review' );
		$ok2     = $this->post_in( 'review' );
		$blocked = $this->post_in( 'idea' );
		$res     = $this->call(
			'POST',
			'/posts/move',
			[
				'ids'   => [ $ok1->ID, $ok2->ID, $blocked->ID ],
				'stage' => 'ready',
			]
		);
		$data    = $res->get_data();
		$this->assertSame( 2, $data['moved'] );
		$this->assertSame( 1, $data['blocked'] );
		$this->assertSame( 'Moved 2 to Ready · 1 blocked: Can’t skip In review. Move it there first', $data['message'] );
	}

	public function test_calendar_drop_auto_schedules_ready_post(): void {
		$post = $this->post_in( 'ready' );
		$this->tick_all( $post );
		$due = gmdate( 'Y-m-d', time() + 4 * DAY_IN_SECONDS );
		$res = $this->call(
			'PATCH',
			"/posts/{$post->ID}",
			[
				'due'           => $due,
				'auto_schedule' => true,
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['scheduled'] );
		$fresh = get_post( $post->ID );
		$this->assertSame( 'future', $fresh->post_status );
		$this->assertSame( 'scheduled', Meta::stage( $post->ID ) );
		$this->assertSame( $due . ' 09:00:00', $fresh->post_date );
	}

	public function test_calendar_drop_without_checklist_only_sets_date(): void {
		$post = $this->post_in( 'ready' );
		$due  = gmdate( 'Y-m-d', time() + 4 * DAY_IN_SECONDS );
		$res  = $this->call(
			'PATCH',
			"/posts/{$post->ID}",
			[
				'due'           => $due,
				'auto_schedule' => true,
			]
		);
		$this->assertFalse( $res->get_data()['scheduled'] );
		$this->assertNotEmpty( $res->get_data()['blocked'] );
		$this->assertSame( $due, Meta::due( $post->ID ) );
		$this->assertSame( 'ready', Meta::stage( $post->ID ) );
	}

	public function test_delete_stage_moves_posts_left_and_undo_restores(): void {
		$this->as( 'administrator' );
		$a   = $this->post_in( 'ready' );
		$res = $this->call( 'DELETE', '/workflows/articles/stages/ready' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'review', Meta::stage( $a->ID ) );
		$this->assertNull( Workflows::get( 'articles' )->stage( 'ready' ) );

		$res = $this->call( 'POST', '/workflows/undo', [ 'token' => $res->get_data()['token'] ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNotNull( Workflows::get( 'articles' )->stage( 'ready' ) );
		$this->assertSame( 'ready', Meta::stage( $a->ID ) );
		$this->assertSame( 'draft', get_post_status( $a->ID ) );
	}

	public function test_workflow_save_needs_two_stages(): void {
		$this->as( 'administrator' );
		$wf  = Workflows::get( 'articles' )->to_array();
		$res = $this->call( 'PUT', '/workflows/articles', [ 'stages' => [ $wf['stages'][0] ] ] );
		$this->assertSame( 422, $res->get_status() );
	}

	public function test_workflows_need_manage_cap(): void {
		$res = $this->call( 'PUT', '/workflows/articles', [ 'name' => 'X' ] );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_create_workflow_registers_post_type_and_board(): void {
		$this->as( 'administrator' );
		$res = $this->call(
			'POST',
			'/workflows',
			[
				'singular' => 'Recipe',
				'from'     => 'simple',
			]
		);
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'recipe', $data['workflow']['post_type'] );
		$this->assertTrue( post_type_exists( 'recipe' ) );
		$this->assertSame( [ 'draft', 'review', 'published' ], array_column( $data['workflow']['stages'], 'id' ) );
		$this->assertSame( $data['workflow']['key'], $data['board']['workflow'] );
	}

	public function test_mention_creates_notification(): void {
		$post   = $this->post_in( 'draft' );
		$target = self::$users['administrator'];
		$before = Notifications::unread_count( $target );
		$res    = $this->call(
			'POST',
			"/posts/{$post->ID}/notes",
			[
				'text'     => 'Can you check this @Administrator User?',
				'mentions' => [ $target ],
			]
		);
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( $before + 1, Notifications::unread_count( $target ) );
		$this->assertSame( [ $target ], get_comment_meta( $res->get_data()['note']['id'], Notes::MENTIONS, true ) );

		$this->assertSame( 0, (int) get_comments_number( $post->ID ) );
		$this->assertCount( 0, get_comments( [ 'post_id' => $post->ID ] ) );
	}

	public function test_board_filters(): void {
		$cat   = self::factory()->category->create( [ 'name' => 'Food' ] );
		$food  = $this->post_in( 'draft', [ 'post_category' => [ $cat ] ] );
		$other = $this->post_in( 'draft' );
		$board = Boards::create(
			[
				'name'     => 'Food desk',
				'workflow' => 'articles',
				'filters'  => [ 'category' => $cat ],
			]
		);
		$mine  = Boards::create(
			[
				'name'     => 'Mine',
				'workflow' => 'articles',
				'filters'  => [ 'author' => self::$users['author'] ],
			]
		);

		$res = $this->call( 'GET', '/boards/' . $board['id'], [ 'include' => 'cards' ] );
		$ids = array_column( $res->get_data()['columns']['draft']['cards'], 'id' );
		$this->assertContains( $food->ID, $ids );
		$this->assertNotContains( $other->ID, $ids );

		$boards = array_column( Boards::for_post( $food ), 'id' );
		$this->assertContains( $board['id'], $boards );
		$this->assertContains( $mine['id'], $boards );
		$this->assertNotContains( $board['id'], array_column( Boards::for_post( $other ), 'id' ) );
	}

	public function test_card_payload_shape(): void {
		$post = $this->post_in( 'draft' );
		$res  = $this->call( 'GET', '/boards/' . Boards::default_id(), [ 'include' => 'cards' ] );
		$card = $res->get_data()['columns']['draft']['cards'][0];
		foreach ( [ 'id', 'title', 'stage', 'workflow', 'category', 'author', 'due', 'overdue', 'checklist', 'notes', 'locked_by', 'rev', 'can_move' ] as $key ) {
			$this->assertArrayHasKey( $key, $card );
		}
		$this->assertSame( $post->ID, $card['id'] );
	}

	public function test_create_post_in_stage(): void {
		$res = $this->call(
			'POST',
			'/posts',
			[
				'workflow' => 'articles',
				'stage'    => 'draft',
			]
		);
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'draft', $res->get_data()['stage'] );
	}
}
