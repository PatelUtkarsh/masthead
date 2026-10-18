<?php
/**
 * Base test case.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Tests;

use Masthead\Boards;
use Masthead\Cards;
use Masthead\Meta;
use Masthead\Options;
use Masthead\People;
use Masthead\Sync;
use Masthead\Workflows;
use WP_Post;

abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * @var array<string, int>
	 */
	protected static array $users = [];

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
		foreach ( [ 'administrator', 'editor', 'author', 'contributor' ] as $role ) {
			self::$users[ $role ] = $factory->user->create(
				[
					'role'         => $role,
					'display_name' => ucfirst( $role ) . ' User',
				]
			);
		}
	}

	public function set_up(): void {
		parent::set_up();
		add_filter( 'masthead_use_transactions', '__return_false' );
		Workflows::replace_all( Workflows::defaults() );
		update_option(
			Boards::OPTION,
			[
				'boards'        => [],
				'default_board' => '',
			]
		);
		Boards::create(
			[
				'name'     => 'Editorial board',
				'workflow' => 'articles',
			]
		);
		update_option( Options::RULES, Options::sanitize_rules( [] ) );
		Cards::flush_counts();
		People::flush();
		$this->as( 'editor' );
	}

	protected function as( string $role ): int {
		wp_set_current_user( self::$users[ $role ] );
		return self::$users[ $role ];
	}

	/**
	 * Create a post in a stage. Status follows the stage.
	 *
	 * @param array<string, mixed> $args Extra post args.
	 */
	protected function post_in( string $stage, array $args = [], string $workflow = 'articles' ): WP_Post {
		$wf     = Workflows::get( $workflow );
		$target = $wf->stage( $stage );
		$status = $target->wp_status->value;
		$extra  = [];
		if ( 'future' === $status ) {
			$extra['post_date'] = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		}
		$id = self::factory()->post->create(
			[
				'post_type'   => $wf->post_type,
				'post_status' => $status,
				'post_author' => self::$users['author'],
				'post_title'  => 'Post in ' . $stage,
				...$extra,
				...$args,
			]
		);
		Sync::write_stage( $id, $stage );
		Cards::flush_counts();
		return get_post( $id );
	}

	protected function tick_all( WP_Post $post, string $workflow = 'articles' ): void {
		$all = [];
		foreach ( Workflows::get( $workflow )->checklist_ids() as $id ) {
			$all[ $id ] = true;
		}
		Meta::set_checklist( $post->ID, $all );
	}
}
