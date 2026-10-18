<?php
/**
 * Notes, setup, checklist clean-up, capabilities and View as.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Tests;

use Masthead\Capabilities;
use Masthead\Cron;
use Masthead\Meta;
use Masthead\Notes;
use Masthead\Options;
use Masthead\Setup;
use Masthead\ViewAs;
use Masthead\WorkflowEditor;
use Masthead\Workflows;

final class MiscTest extends TestCase {

	public function test_capabilities_per_role(): void {
		$this->assertTrue( user_can( self::$users['administrator'], Capabilities::MANAGE_WORKFLOWS ) );
		$this->assertFalse( user_can( self::$users['editor'], Capabilities::MANAGE_WORKFLOWS ) );
		$this->assertTrue( user_can( self::$users['editor'], Capabilities::APPROVE ) );
		$this->assertFalse( user_can( self::$users['author'], Capabilities::APPROVE ) );
		$this->assertTrue( user_can( self::$users['contributor'], Capabilities::VIEW ) );
		$this->assertFalse( user_can( self::$users['contributor'], Capabilities::MANAGE_BOARDS ) );
	}

	public function test_notes_are_hidden_from_comment_queries_and_counts(): void {
		$post = $this->post_in( 'draft' );
		Notes::add( $post, 'Private editorial note' );
		self::factory()->comment->create( [ 'comment_post_ID' => $post->ID ] );

		$this->assertCount( 1, get_comments( [ 'post_id' => $post->ID ] ) );
		$this->assertSame( 1, (int) get_comments_number( $post->ID ) );
		$this->assertCount( 1, Notes::for_post( $post->ID ) );
		$this->assertSame( 1, (int) wp_count_comments( $post->ID )->approved );
	}

	public function test_removed_checklist_items_are_cleaned_up(): void {
		$post = $this->post_in( 'draft' );
		$this->tick_all( $post );
		$wf      = Workflows::get( 'articles' )->to_array();
		$removed = $wf['checklist'][0]['id'];
		array_shift( $wf['checklist'] );

		$this->as( 'administrator' );
		WorkflowEditor::save( 'articles', $wf );
		$this->assertNotFalse( wp_next_scheduled( Cron::CLEANUP, [ 'post', [ $removed ], 0 ] ) );

		Cron::cleanup_checklist( 'post', [ $removed ] );
		$this->assertArrayNotHasKey( $removed, Meta::checklist( $post->ID ) );
		$this->assertCount( 4, Meta::checklist( $post->ID ) );
	}

	public function test_setup_applies_template_and_marks_complete(): void {
		$this->as( 'administrator' );
		$draft = $this->post_in( 'draft' );
		Setup::apply(
			[
				'template' => 'simple',
				'pages'    => true,
				'import'   => true,
			]
		);
		$this->assertSame( [ 'draft', 'ready', 'published' ], array_map( static fn( $s ) => $s->id, Workflows::get( 'articles' )->stages ) );
		$this->assertNotNull( Workflows::for_post_type( 'page' ) );
		$this->assertTrue( Options::settings()['setup_complete'] );
		$this->assertSame( 'draft', Meta::stage( $draft->ID ) );
	}

	public function test_view_as_only_removes_capabilities(): void {
		if ( ! ViewAs::enabled() ) {
			$this->markTestSkipped( 'WP_DEBUG is off.' );
		}
		$admin = $this->as( 'administrator' );
		update_user_meta( $admin, 'masthead_view_as', 'author' );
		$user = wp_get_current_user();
		$caps = ViewAs::filter_caps( $user->allcaps, [], [], $user );
		$this->assertFalse( $caps['manage_options'] );
		$this->assertFalse( $caps[ Capabilities::APPROVE ] );
		$this->assertTrue( $caps['edit_posts'] );
		$this->assertFalse( ViewAs::filter_caps( [ 'not_a_cap' => false ], [], [], $user )['not_a_cap'] );
	}
}
