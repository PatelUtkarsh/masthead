<?php
/**
 * First-run setup.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\Workflow;
use WP_Error;

final class Setup {

	/**
	 * Apply the setup wizard choices.
	 *
	 * @param array<string, mixed> $args `template`, `pages`, `newsletter`, `team` (included user IDs), `import`, `skip`.
	 */
	public static function apply( array $args ): true|WP_Error {
		if ( ! empty( $args['skip'] ) ) {
			Options::update_settings( [ 'setup_complete' => true ] );
			return true;
		}

		$template = in_array( $args['template'] ?? '', [ 'simple', 'team', 'news' ], true ) ? (string) $args['template'] : 'team';
		$import   = (bool) ( $args['import'] ?? true );

		$posts_wf = Workflows::for_post_type( 'post' );
		$key      = $posts_wf?->key ?? 'articles';
		$current  = $posts_wf ?? Workflows::defaults()['articles'];
		$wf       = Workflows::from_template(
			$template,
			$key,
			[
				'name'      => $current->name,
				'post_type' => 'post',
				'color'     => $current->color,
				'singular'  => $current->singular,
				'plural'    => $current->plural,
			]
		);
		Workflows::save( $wf );
		if ( ! Boards::default_for_workflow( $key ) ) {
			Boards::create(
				[
					'name'     => __( 'Editorial board', 'masthead' ),
					'workflow' => $key,
				],
				true
			);
		}

		if ( ! empty( $args['pages'] ) && ! Workflows::for_post_type( 'page' ) ) {
			$pages = Workflows::from_template(
				'pages',
				Workflows::unique_key( 'pages' ),
				[
					'name'      => __( 'Pages', 'masthead' ),
					'post_type' => 'page',
					'color'     => '#0d9488',
					'singular'  => __( 'Page', 'masthead' ),
					'plural'    => __( 'Pages', 'masthead' ),
				]
			);
			self::add( $pages, __( 'Site pages', 'masthead' ) );
		}

		if ( ! empty( $args['newsletter'] ) && ! self::find_registered( 'newsletter' ) ) {
			$news = Workflows::from_template(
				'newsletter',
				Workflows::unique_key( 'newsletter' ),
				[
					'name'      => __( 'Newsletter', 'masthead' ),
					'post_type' => Workflows::post_type_slug( 'newsletter' ),
					'color'     => '#c2410c',
					'singular'  => __( 'Newsletter', 'masthead' ),
					'plural'    => __( 'Newsletters', 'masthead' ),
					'registers' => true,
				]
			);
			self::add( $news, __( 'Newsletter', 'masthead' ) );
			Workflows::register_post_types();
			update_option( 'masthead_flush_rewrite', 1, true );
		}

		if ( isset( $args['team'] ) && is_array( $args['team'] ) ) {
			self::apply_team( $args['team'] );
		}

		StageTaxonomy::register();
		Meta::register();
		foreach ( Workflows::all() as $w ) {
			// Posts in stages that no longer exist always need a valid stage.
			Sync::remap_orphans( $w );
			if ( $import ) {
				Sync::import( $w );
			}
		}

		Options::update_settings( [ 'setup_complete' => true ] );
		Cards::flush_counts();
		return true;
	}

	private static function add( Workflow $wf, string $board_name ): void {
		Workflows::save( $wf );
		Boards::create(
			[
				'name'     => $board_name,
				'workflow' => $wf->key,
			]
		);
	}

	private static function find_registered( string $singular ): ?Workflow {
		foreach ( Workflows::all() as $wf ) {
			if ( $wf->registers && strtolower( $wf->singular ) === $singular ) {
				return $wf;
			}
		}
		return null;
	}

	/**
	 * Include or exclude users. Only users named in the map change, so the
	 * wizard can page through a large user list.
	 *
	 * Accepts a map of user ID => included, or a list of IDs to include.
	 *
	 * @param array<int|string, bool|int> $team Team choices.
	 */
	private static function apply_team( array $team ): void {
		if ( ! $team ) {
			return;
		}
		if ( array_is_list( $team ) ) {
			// A plain list only includes; it never excludes users that were not listed.
			$team = array_fill_keys( array_map( 'intval', $team ), true );
		}
		foreach ( $team as $id => $include ) {
			$user = get_userdata( (int) $id );
			if ( ! $user || get_current_user_id() === $user->ID ) {
				continue;
			}
			if ( $include ) {
				if ( isset( $user->caps[ Capabilities::VIEW ] ) && ! $user->caps[ Capabilities::VIEW ] ) {
					$user->remove_cap( Capabilities::VIEW );
				}
			} elseif ( $user->has_cap( Capabilities::VIEW ) ) {
				$user->add_cap( Capabilities::VIEW, false );
			}
		}
		wp_cache_delete( 'users_with_' . Capabilities::VIEW, 'masthead' );
	}
}
