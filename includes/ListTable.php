<?php
/**
 * Posts list screen (edit.php): Stage column, filter, bulk moves, row action.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Rest\PostsController;
use WP_Query;

final class ListTable {

	public const string COLUMN = 'masthead_stage';

	public static function register(): void {
		foreach ( Workflows::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", [ self::class, 'columns' ] );
			add_action( "manage_{$type}_posts_custom_column", [ self::class, 'column' ], 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", [ self::class, 'sortable' ] );
			add_filter( "bulk_actions-edit-{$type}", [ self::class, 'bulk_actions' ] );
			add_filter( "handle_bulk_actions-edit-{$type}", [ self::class, 'handle_bulk' ], 10, 3 );
		}
		add_filter( 'page_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ self::class, 'filter_dropdown' ], 10, 1 );
		add_action( 'pre_get_posts', [ self::class, 'query' ] );
		add_filter( 'posts_clauses', [ self::class, 'order_clauses' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	private static function workflow(): ?Model\Workflow {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base || ! current_user_can( Capabilities::VIEW ) ) {
			return null;
		}
		return Workflows::for_post_type( $screen->post_type );
	}

	public static function enqueue( string $hook ): void {
		if ( 'edit.php' === $hook && self::workflow() ) {
			Assets::enqueue( 'list-table' );
		}
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return $columns;
		}
		$out   = [];
		$added = false;
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'categories' === $key ) {
				$out[ self::COLUMN ] = __( 'Stage', 'masthead' );
				$added               = true;
			}
		}
		if ( ! $added ) {
			$pos = array_search( 'author', array_keys( $out ), true );
			$pos = false === $pos ? count( $out ) - 1 : $pos + 1;
			$out = array_slice( $out, 0, $pos, true ) + [ self::COLUMN => __( 'Stage', 'masthead' ) ] + array_slice( $out, $pos, null, true );
		}
		return $out;
	}

	public static function column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$post = get_post( $post_id );
		$wf   = $post ? Workflows::for_post( $post ) : null;
		if ( ! $post || ! $wf || 'trash' === $post->post_status ) {
			echo '—';
			return;
		}
		$stage = Sync::current_stage( $post, $wf );
		if ( ! $stage ) {
			echo '—';
			return;
		}
		printf(
			'<span class="masthead-pill" data-stage="%1$s"><span class="masthead-pill__dot" style="background:%2$s" aria-hidden="true"></span>%3$s</span>',
			esc_attr( $stage->id ),
			esc_attr( $stage->color ),
			esc_html( $stage->name )
		);
	}

	/**
	 * @param array<string, string|array<int, mixed>> $columns Sortable columns.
	 * @return array<string, string|array<int, mixed>>
	 */
	public static function sortable( array $columns ): array {
		$columns[ self::COLUMN ] = self::COLUMN;
		return $columns;
	}

	public static function filter_dropdown( string $post_type ): void {
		$wf = Workflows::for_post_type( $post_type );
		if ( ! $wf || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		$current = isset( $_GET[ self::COLUMN ] ) ? sanitize_key( wp_unslash( $_GET[ self::COLUMN ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="masthead-stage-filter">' . esc_html__( 'Filter by stage', 'masthead' ) . '</label>';
		echo '<select name="' . esc_attr( self::COLUMN ) . '" id="masthead-stage-filter">';
		echo '<option value="">' . esc_html__( 'All stages', 'masthead' ) . '</option>';
		foreach ( $wf->stages as $stage ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $stage->id ), selected( $current, $stage->id, false ), esc_html( $stage->name ) );
		}
		echo '</select>';
	}

	public static function query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$type = (string) ( $query->get( 'post_type' ) ?: 'post' );
		if ( ! Workflows::for_post_type( $type ) ) {
			return;
		}
		$stage = isset( $_GET[ self::COLUMN ] ) ? sanitize_key( wp_unslash( $_GET[ self::COLUMN ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $stage ) {
			$tax   = (array) $query->get( 'tax_query' );
			$tax[] = StageTaxonomy::clause( Workflows::for_post_type( $type ), [ $stage ] );
			$query->set( 'tax_query', $tax );
		}
		if ( self::COLUMN === $query->get( 'orderby' ) ) {
			$query->set( 'masthead_stage_order', true );
		}
	}

	/**
	 * Sort by stage position, not by stage ID.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @return array<string, string>
	 */
	public static function order_clauses( array $clauses, WP_Query $query ): array {
		if ( ! $query->get( 'masthead_stage_order' ) ) {
			return $clauses;
		}
		$type = (string) ( $query->get( 'post_type' ) ?: 'post' );
		$wf   = Workflows::for_post_type( $type );
		if ( ! $wf || ! $wf->stages ) {
			return $clauses;
		}
		global $wpdb;
		$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$tts   = implode( ',', array_map( 'intval', StageTaxonomy::ids( $wf ) ) );
		if ( '' === $tts ) {
			return $clauses;
		}
		$clauses['join']   .= " LEFT JOIN {$wpdb->term_relationships} AS masthead_sr ON (masthead_sr.object_id = {$wpdb->posts}.ID AND masthead_sr.term_taxonomy_id IN ({$tts}))";
		$clauses['orderby'] = "FIELD(masthead_sr.term_taxonomy_id, {$tts}) {$order}, {$wpdb->posts}.post_date DESC";
		return $clauses;
	}

	/**
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string|array<string, string>>
	 */
	public static function bulk_actions( array $actions ): array {
		$wf = self::workflow();
		if ( ! $wf ) {
			return $actions;
		}
		$group = [];
		foreach ( $wf->stages as $stage ) {
			/* translators: %s: stage name. */
			$group[ 'masthead_move_' . $stage->id ] = sprintf( __( 'Move to %s', 'masthead' ), $stage->name );
		}
		$actions[ __( 'Masthead', 'masthead' ) ] = $group;
		return $actions;
	}

	/**
	 * @param list<int|string> $ids Post IDs.
	 */
	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( ! str_starts_with( $action, 'masthead_move_' ) || ! current_user_can( Capabilities::VIEW ) ) {
			return $redirect;
		}
		$stage  = substr( $action, strlen( 'masthead_move_' ) );
		$moved  = 0;
		$errors = [];
		$name   = $stage;
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post ) {
				continue;
			}
			$wf   = Workflows::for_post( $post );
			$name = $wf?->stage( $stage )?->name ?? $name;
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				$errors[] = __( 'You can’t edit this post.', 'masthead' );
				continue;
			}
			$result = Rules::move( $post, $stage );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} elseif ( ! $result['noop'] ) {
				++$moved;
			}
		}
		set_transient(
			'masthead_bulk_' . get_current_user_id(),
			[
				'message' => PostsController::bulk_message( $moved, $name, $errors ),
				'error'   => 0 === $moved && $errors,
			],
			120
		);
		return add_query_arg( 'masthead_bulk', 1, remove_query_arg( [ 'masthead_bulk' ], $redirect ) );
	}

	public static function notice(): void {
		if ( empty( $_GET['masthead_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key  = 'masthead_bulk_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );
		wp_admin_notice(
			esc_html( (string) $data['message'] ),
			[
				'type'               => $data['error'] ? 'error' : 'success',
				'dismissible'        => true,
				'additional_classes' => [ 'masthead-bulk-notice' ],
			]
		);
	}

	/**
	 * @param array<string, string> $actions Row actions.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		$wf = Workflows::for_post( $post );
		if ( ! $wf || 'trash' === $post->post_status || ! current_user_can( Capabilities::VIEW ) ) {
			return $actions;
		}
		$board = Boards::default_for_workflow( $wf->key );
		if ( ! $board ) {
			return $actions;
		}
		$url                       = admin_url( 'admin.php?page=masthead' ) . '#/board/' . rawurlencode( $board ) . '?post=' . $post->ID;
		$actions['masthead_board'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Open on board', 'masthead' ) );
		return $actions;
	}
}
