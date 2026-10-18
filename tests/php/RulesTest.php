<?php
/**
 * Rule engine tests (spec §4, acceptance 1–5).
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Tests;

use Masthead\Activity;
use Masthead\Meta;
use Masthead\Notifications;
use Masthead\Options;
use Masthead\Rules;
use Masthead\Workflows;
use Masthead\Model\Workflow;

final class RulesTest extends TestCase {

	public function test_noop_returns_true(): void {
		$post = $this->post_in( 'draft' );
		$this->assertTrue( Rules::validate( $post, 'draft' ) );
	}

	public function test_cannot_skip_required_stage(): void {
		$post   = $this->post_in( 'idea' );
		$result = Rules::validate( $post, 'ready' );
		$this->assertWPError( $result );
		$this->assertSame( 'masthead_skip_required', $result->get_error_code() );
		$this->assertSame( 'Can’t skip In review. Move it there first', $result->get_error_message() );
	}

	public function test_step_by_step_path_succeeds(): void {
		$post = $this->post_in( 'idea' );
		foreach ( [ 'draft', 'review', 'ready' ] as $stage ) {
			$result = Rules::move( get_post( $post->ID ), $stage );
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		}
		$this->assertSame( 'ready', Meta::stage( $post->ID ) );
		$this->assertSame( 'draft', get_post_status( $post->ID ) );
	}

	public function test_moving_left_skips_required_check(): void {
		$post = $this->post_in( 'ready' );
		$this->assertTrue( Rules::validate( $post, 'idea' ) );
	}

	public function test_author_cannot_leave_approval_stage(): void {
		$post = $this->post_in( 'review' );
		$this->as( 'author' );
		$result = Rules::validate( $post, 'ready' );
		$this->assertWPError( $result );
		$this->assertSame( 'masthead_needs_approval', $result->get_error_code() );
		$this->assertSame( 'Needs approval: only Editors can move posts out of In review', $result->get_error_message() );

		$this->as( 'editor' );
		$this->assertTrue( Rules::validate( $post, 'ready' ) );
	}

	public function test_approval_lock_respects_rule_toggle(): void {
		Options::update_rules( [ 'enforce_approvals' => false ] );
		$post = $this->post_in( 'review' );
		$this->as( 'author' );
		$this->assertTrue( Rules::validate( $post, 'ready' ) );
	}

	public function test_contributor_cannot_schedule_or_publish(): void {
		$uid  = $this->as( 'contributor' );
		$post = $this->post_in( 'ready', [ 'post_author' => $uid ] );
		$this->tick_all( $post );
		foreach ( [ 'scheduled', 'published' ] as $stage ) {
			$result = Rules::validate( $post, $stage );
			$this->assertWPError( $result );
			$this->assertSame( 'masthead_cannot_publish', $result->get_error_code() );
		}
	}

	public function test_checklist_gate_blocks_until_ticked(): void {
		$post = $this->post_in( 'ready' );
		$wf   = Workflows::get( 'articles' );
		$ids  = $wf->checklist_ids();
		$all  = array_fill_keys( $ids, true );
		unset( $all[ $ids[0] ] );
		Meta::set_checklist( $post->ID, $all );

		foreach ( [ 'scheduled', 'published' ] as $stage ) {
			$result = Rules::validate( $post, $stage );
			$this->assertWPError( $result );
			$this->assertSame( 'masthead_checklist_open', $result->get_error_code() );
			$this->assertSame( 'Blocked: 1 checklist item open on “Post in ready”', $result->get_error_message() );
		}

		$this->tick_all( $post );
		$this->assertTrue( Rules::validate( $post, 'published' ) );
	}

	public function test_wip_limit_block_mode(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->post_in( 'review' );
		}
		$fourth = $this->post_in( 'draft' );
		$result = Rules::validate( $fourth, 'review' );
		$this->assertWPError( $result );
		$this->assertSame( 'masthead_stage_full', $result->get_error_code() );
		$this->assertSame( 'In review is full (3/3). Move something on first', $result->get_error_message() );
	}

	public function test_wip_limit_warn_mode_allows(): void {
		$wf   = Workflows::get( 'articles' );
		$data = $wf->to_array();
		foreach ( $data['stages'] as &$s ) {
			if ( 'review' === $s['id'] ) {
				$s['limit_mode'] = 'warn';
			}
		}
		Workflows::save( Workflow::from_array( 'articles', $data ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->post_in( 'review' );
		}
		$this->assertTrue( Rules::validate( $this->post_in( 'draft' ), 'review' ) );
	}

	public function test_move_publishes_with_now_and_logs_event(): void {
		$post = $this->post_in( 'scheduled' );
		$this->tick_all( $post );
		$rev    = Meta::rev( $post->ID );
		$result = Rules::move( $post, 'published', null, $rev );
		$this->assertIsArray( $result );
		$this->assertSame( 'publish', get_post_status( $post->ID ) );
		$this->assertSame( $rev + 1, Meta::rev( $post->ID ) );
		$this->assertLessThanOrEqual( time(), (int) get_post_time( 'U', true, $post->ID ) );

		$events = Activity::for_post( $post->ID );
		$this->assertSame( 'moved_to', $events[0]['verb'] );
		$this->assertSame( 'published', $events[0]['meta']['to'] );
		$this->assertSame( 'scheduled', $events[0]['meta']['from'] );
	}

	public function test_rev_mismatch_is_conflict(): void {
		$post   = $this->post_in( 'idea' );
		$result = Rules::move( $post, 'draft', null, Meta::rev( $post->ID ) + 5 );
		$this->assertWPError( $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_future_without_date_needs_date(): void {
		$post = $this->post_in( 'ready' );
		$this->tick_all( $post );
		$result = Rules::move( $post, 'scheduled' );
		$this->assertWPError( $result );
		$this->assertSame( 'masthead_needs_date', $result->get_error_code() );
	}

	public function test_future_uses_due_date_at_nine(): void {
		$post = $this->post_in( 'ready' );
		$this->tick_all( $post );
		$due = gmdate( 'Y-m-d', time() + 3 * DAY_IN_SECONDS );
		Meta::set_due( $post->ID, $due );
		$result = Rules::move( $post, 'scheduled' );
		$this->assertIsArray( $result );
		$fresh = get_post( $post->ID );
		$this->assertSame( 'future', $fresh->post_status );
		$this->assertSame( $due . ' 09:00:00', $fresh->post_date );
	}

	public function test_notifications_exclude_actor(): void {
		$editor = self::$users['editor'];
		$post   = $this->post_in( 'draft' );
		$result = Rules::move( $post, 'review' );
		$this->assertIsArray( $result );
		$this->assertNotContains( $editor, $result['notified'] );
		$this->assertContains( self::$users['administrator'], $result['notified'] );
		$this->assertGreaterThan( 0, Notifications::unread_count( self::$users['administrator'] ) );
	}

	public function test_checks_lists_four_items(): void {
		$post   = $this->post_in( 'draft' );
		$checks = Rules::checks( $post );
		$this->assertSame( [ 'checklist', 'required', 'approval', 'permission' ], array_column( $checks['checks'], 'key' ) );
		$this->assertFalse( $checks['ok'] );
	}
}
