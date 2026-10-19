<?php
/**
 * "Editorial pipeline" dashboard widget.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use WP_Post;

final class Dashboard {

	public const int OVERDUE_CAP = 99;

	public static function register(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		wp_add_dashboard_widget( 'masthead_pipeline', __( 'Editorial pipeline', 'masthead' ), [ self::class, 'render' ] );
	}

	public static function render(): void {
		echo '<div id="masthead-dashboard-root"><p>' . esc_html__( 'Loading…', 'masthead' ) . '</p></div>';
	}

	public static function enqueue( string $hook ): void {
		if ( 'index.php' !== $hook || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		if ( Assets::enqueue( 'dashboard-widget', [ 'wp-components' ] ) ) {
			wp_add_inline_script( 'masthead-dashboard-widget', 'window.mastheadDashboard = ' . wp_json_encode( self::data() ) . ';', 'before' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$board_id = Boards::default_id();
		$board    = Boards::get( $board_id );
		$wf       = $board ? Workflows::get( $board['workflow'] ) : ( array_values( Workflows::all() )[0] ?? null );
		if ( ! $wf ) {
			return [ 'empty' => true ];
		}
		$counts   = Cards::stage_counts( $wf );
		$stages   = [];
		$progress = 0;
		foreach ( $wf->stages as $s ) {
			$n        = $counts[ $s->id ] ?? 0;
			$stages[] = [
				'id'    => $s->id,
				'name'  => $s->name,
				'color' => $s->color,
				'count' => $n,
				'live'  => $s->wp_status->is_live(),
			];
			if ( ! $s->wp_status->is_live() ) {
				$progress += $n;
			}
		}

		$user       = get_current_user_id();
		$today      = wp_date( 'Y-m-d' );
		$base       = [
			'post_type'              => $wf->post_type,
			'post_status'            => [ 'draft', 'pending' ],
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		];
		$due_before = static fn( string $op ): array => [
			'key'     => Meta::DUE,
			'value'   => $today,
			'compare' => $op,
			'type'    => 'DATE',
		];

		// Overdue: bounded ID query, no COUNT. Shows "100+" past the cap.
		$overdue_ids  = get_posts(
			[
				...$base,
				'fields'         => 'ids',
				'posts_per_page' => self::OVERDUE_CAP + 1,
				'meta_query'     => [ $due_before( '<' ) ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		$overdue      = min( count( $overdue_ids ), self::OVERDUE_CAP );
		$overdue_more = count( $overdue_ids ) > self::OVERDUE_CAP;

		$waiting       = [];
		$add           = static function ( array $posts, string $reason ) use ( &$waiting ): void {
			foreach ( $posts as $post ) {
				if ( count( $waiting ) < 4 && ! isset( $waiting[ $post->ID ] ) ) {
					$waiting[ $post->ID ] = [
						'post'   => $post,
						'reason' => $reason,
					];
				}
			}
		};
		$review_stages = array_values( array_map( static fn( $s ) => $s->id, array_filter( $wf->stages, static fn( $s ) => $s->reviewer === $user ) ) );
		if ( $review_stages ) {
			$add(
				get_posts(
					[
						...$base,
						'posts_per_page' => 4,
						'tax_query'      => [ StageTaxonomy::clause( $wf, $review_stages ) ], // phpcs:ignore WordPress.DB.SlowDBQuery
					]
				),
				'review'
			);
		}
		if ( count( $waiting ) < 4 && $overdue_ids ) {
			$add(
				get_posts(
					[
						...$base,
						'post__in'       => array_slice( $overdue_ids, 0, 4 ),
						'posts_per_page' => 4,
					]
				),
				'overdue'
			);
		}
		if ( count( $waiting ) < 4 ) {
			$add(
				get_posts(
					[
						...$base,
						'author'         => $user,
						'posts_per_page' => 4,
						'orderby'        => 'modified',
					]
				),
				'assigned'
			);
		}
		update_meta_cache( 'post', array_keys( $waiting ) );
		$waiting = array_values( $waiting );

		$next = get_posts(
			[
				'post_type'      => $wf->post_type,
				'post_status'    => 'future',
				'posts_per_page' => 3,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		$item = static function ( WP_Post $post, ?string $reason = null ) use ( $wf ): array {
			$stage = Sync::current_stage( $post, $wf );
			return [
				'id'         => $post->ID,
				'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
				'stage'      => $stage ? [
					'name'  => $stage->name,
					'color' => $stage->color,
				] : null,
				'due'        => Meta::due( $post->ID ) ?: null,
				'date'       => get_post_time( 'Y-m-d H:i', false, $post ),
				// Formatted on the server in the site timezone; the browser's timezone may differ.
				'date_label' => wp_date( _x( 'l, M j · g:i a', 'publish date and time', 'masthead' ), (int) get_post_time( 'U', true, $post ) ),
				'reason'     => $reason,
				'author'     => People::user( (int) $post->post_author ),
				'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
			];
		};

		return [
			'workflow'     => [
				'key'   => $wf->key,
				'name'  => $wf->name,
				'color' => $wf->color,
			],
			'in_progress'  => $progress,
			'overdue'      => $overdue,
			'overdue_more' => $overdue_more,
			'stages'       => $stages,
			'waiting'      => array_map( static fn( $w ) => $item( $w['post'], $w['reason'] ), $waiting ),
			'next'         => array_map( static fn( $p ) => $item( $p ), $next ),
			'board_url'    => admin_url( 'admin.php?page=masthead' ) . ( $board ? '#/board/' . rawurlencode( $board['id'] ) : '' ),
			'today'        => $today,
		];
	}
}
