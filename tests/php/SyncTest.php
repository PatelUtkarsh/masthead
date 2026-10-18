<?php
/**
 * Status sync and enforcement tests (spec §4.1, acceptance 3, 4, 6).
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Tests;

use Masthead\Activity;
use Masthead\Guard;
use Masthead\Meta;
use Masthead\Workflows;
use Masthead\Model\Workflow;
use WP_REST_Request;

final class SyncTest extends TestCase {

	public function test_new_post_gets_first_matching_stage(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( 'idea', Meta::stage( $id ) );
		$this->assertSame( 'created', Activity::for_post( $id )[0]['verb'] );

		$pending = self::factory()->post->create( [ 'post_status' => 'pending' ] );
		$this->assertSame( 'review', Meta::stage( $pending ) );
	}

	public function test_publish_elsewhere_moves_card(): void {
		$post = $this->post_in( 'ready' );
		$this->as( 'administrator' );
		$this->tick_all( $post );
		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_status' => 'publish',
			]
		);
		$this->assertSame( 'published', Meta::stage( $post->ID ) );
	}

	public function test_pending_moves_to_first_pending_stage(): void {
		$post = $this->post_in( 'draft' );
		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_status' => 'pending',
			]
		);
		$this->assertSame( 'review', Meta::stage( $post->ID ) );
	}

	public function test_current_stage_kept_when_it_maps(): void {
		$post = $this->post_in( 'ready' );
		wp_update_post(
			[
				'ID'         => $post->ID,
				'post_title' => 'Changed',
			]
		);
		$this->assertSame( 'ready', Meta::stage( $post->ID ) );
	}

	public function test_unmapped_status_goes_to_first_stage(): void {
		$wf             = Workflows::get( 'articles' );
		$data           = $wf->to_array();
		$data['stages'] = array_values( array_filter( $data['stages'], static fn( $s ) => 'pending' !== $s['wp_status'] ) );
		Workflows::save( Workflow::from_array( 'articles', $data ) );

		$post = $this->post_in( 'draft' );
		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_status' => 'pending',
			]
		);
		$this->assertSame( 'idea', Meta::stage( $post->ID ) );
		$this->assertSame( 'status_unmapped', Activity::for_post( $post->ID )[0]['verb'] );
	}

	public function test_trash_and_restore_keeps_stage(): void {
		$post = $this->post_in( 'ready' );
		wp_trash_post( $post->ID );
		wp_untrash_post( $post->ID );
		$this->assertSame( 'ready', Meta::stage( $post->ID ) );
		$this->assertSame( 'draft', get_post_status( $post->ID ) );
	}

	public function test_quick_edit_style_update_is_reverted_when_blocked(): void {
		$post = $this->post_in( 'ready' );
		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_status' => 'publish',
			]
		);
		$this->assertSame( 'draft', get_post_status( $post->ID ), 'Checklist is open, so publish is reverted.' );
		$this->assertSame( 'ready', Meta::stage( $post->ID ) );
	}

	public function test_rest_publish_blocked_with_open_checklist(): void {
		$post    = $this->post_in( 'ready' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'status', 'publish' );
		$result = Guard::rest_pre_insert(
			(object) [
				'ID'          => $post->ID,
				'post_status' => 'publish',
			],
			$request
		);
		$this->assertWPError( $result );
		$this->assertSame( 'masthead_checklist_open', $result->get_error_code() );

		$this->tick_all( $post );
		$this->assertNotWPError(
			Guard::rest_pre_insert(
				(object) [
					'ID'          => $post->ID,
					'post_status' => 'publish',
				],
				$request
			)
		);
	}

	public function test_rest_publish_uses_checklist_from_request(): void {
		$post    = $this->post_in( 'ready' );
		$all     = array_fill_keys( Workflows::get( 'articles' )->checklist_ids(), true );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'meta', [ Meta::CHECKLIST => $all ] );
		$this->assertNotWPError(
			Guard::rest_pre_insert(
				(object) [
					'ID'          => $post->ID,
					'post_status' => 'publish',
				],
				$request
			)
		);
	}

	public function test_contributor_blocked_in_editor(): void {
		$uid  = $this->as( 'contributor' );
		$post = $this->post_in( 'ready', [ 'post_author' => $uid ] );
		$this->tick_all( $post );
		$error = Guard::check( $post, 'publish' );
		$this->assertWPError( $error );
		$this->assertSame( 'masthead_cannot_publish', $error->get_error_code() );
	}

	public function test_full_rest_round_trip_blocks_publish(): void {
		$this->as( 'administrator' );
		$post    = $this->post_in( 'ready' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_body_params( [ 'status' => 'publish' ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'masthead_checklist_open', $response->get_data()['code'] );
		$this->assertSame( 'draft', get_post_status( $post->ID ) );
	}
}
