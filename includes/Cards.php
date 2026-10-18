<?php
/**
 * Card payloads and board queries.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\Stage;
use Masthead\Model\Workflow;
use Masthead\Model\WpStatus;
use WP_Post;
use WP_Query;

final class Cards {

	public const int PER_PAGE = 20;

	public const array STATUSES = [ 'draft', 'pending', 'future', 'publish', 'private' ];

	private const string COLOR_META = 'masthead_color';

	private const array CATEGORY_COLORS = [ '#0369a1', '#b45309', '#15803d', '#7c3aed', '#be123c', '#50575e', '#c2410c', '#0f766e' ];

	/**
	 * Cards for one board, per column.
	 *
	 * @param array{workflow: string, filters: array{category?: int, author?: int}} $board Board.
	 * @param array{search?: string, author?: int, stage?: string, page?: int}       $args  Extra filters.
	 * @return array<string, array{cards: list<array<string, mixed>>, total: ?int, page: int, has_more: bool}>
	 */
	public static function for_board( array $board, array $args = [] ): array {
		$wf = Workflows::get( $board['workflow'] );
		if ( ! $wf ) {
			return [];
		}
		$out    = [];
		$stages = ! empty( $args['stage'] ) ? array_filter( $wf->stages, static fn( Stage $s ): bool => $s->id === $args['stage'] ) : $wf->stages;
		// Never count rows here. Fetch one extra card to know if there is another page.
		// Unfiltered boards show the cached per-stage totals; filtered boards show "n+".
		$plain  = empty( $board['filters'] ) && '' === trim( (string) ( $args['search'] ?? '' ) ) && empty( $args['author'] );
		$counts = $plain ? self::stage_counts( $wf ) : [];
		$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
		foreach ( $stages as $stage ) {
			$q                   = self::query_args( $wf, $board['filters'], $args, $stage->id, $page );
			$q['no_found_rows']  = true;
			$q['posts_per_page'] = self::PER_PAGE + 1;
			$q['offset']         = ( $page - 1 ) * self::PER_PAGE;
			unset( $q['paged'] );
			$query    = new WP_Query( $q );
			$posts    = array_values( array_filter( $query->posts, static fn( $p ): bool => $p instanceof WP_Post ) );
			$has_more = count( $posts ) > self::PER_PAGE;
			$posts    = array_slice( $posts, 0, self::PER_PAGE );

			$out[ $stage->id ] = [
				'cards'    => self::many( $posts, $wf ),
				'total'    => $plain ? (int) ( $counts[ $stage->id ] ?? 0 ) : null,
				'page'     => $page,
				'has_more' => $has_more,
			];
		}
		return $out;
	}

	/**
	 * Count posts matching a board, optionally per stage, without loading them.
	 *
	 * @param array{workflow: string, filters: array{category?: int, author?: int}} $board Board.
	 */
	public static function count_for_board( array $board ): int {
		$wf = Workflows::get( $board['workflow'] );
		if ( ! $wf ) {
			return 0;
		}
		if ( empty( $board['filters'] ) ) {
			return array_sum( self::stage_counts( $wf ) );
		}
		$key = 'masthead_bcount_' . self::generation() . '_' . md5( (string) wp_json_encode( [ $wf->key, $board['filters'] ] ) );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return (int) $hit;
		}
		$args                   = self::query_args( $wf, $board['filters'], [], null, 1 );
		$args['posts_per_page'] = 1;
		$args['fields']         = 'ids';
		$args['tax_query']      = [ StageTaxonomy::clause( $wf ) ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		$query                  = new WP_Query( $args );
		$count                  = (int) $query->found_posts;
		set_transient( $key, $count, HOUR_IN_SECONDS );
		return $count;
	}

	/**
	 * Cache generation for filtered counts. Bumped whenever posts move.
	 */
	private static function generation(): int {
		$gen = get_transient( 'masthead_count_gen' );
		if ( false === $gen ) {
			$gen = time();
			set_transient( 'masthead_count_gen', $gen, DAY_IN_SECONDS );
		}
		return (int) $gen;
	}

	/**
	 * @param array{category?: int, author?: int}       $filters Board filters.
	 * @param array{search?: string, author?: int}      $args    Extra filters.
	 * @return array<string, mixed>
	 */
	public static function query_args( Workflow $wf, array $filters, array $args, ?string $stage, int $page ): array {
		$q              = [
			'post_type'           => $wf->post_type,
			'post_status'         => self::STATUSES,
			'posts_per_page'      => self::PER_PAGE,
			'paged'               => $page,
			'orderby'             => [
				'modified' => 'DESC',
				'ID'       => 'DESC',
			],
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
		];
		$q['tax_query'] = [ StageTaxonomy::clause( $wf, null === $stage ? null : [ $stage ] ) ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		$author         = (int) ( $args['author'] ?? 0 );
		if ( ! empty( $filters['author'] ) ) {
			$q['author'] = (int) $filters['author'];
			if ( $author && $author !== (int) $filters['author'] ) {
				$q['post__in'] = [ 0 ];
			}
		} elseif ( $author ) {
			$q['author'] = $author;
		}
		if ( ! empty( $filters['category'] ) ) {
			$q['cat'] = (int) $filters['category'];
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$q['s'] = $search;
		}
		return $q;
	}

	/**
	 * Build many cards with primed caches.
	 *
	 * @param list<WP_Post> $posts Posts.
	 * @return list<array<string, mixed>>
	 */
	public static function many( array $posts, ?Workflow $wf = null ): array {
		if ( ! $posts ) {
			return [];
		}
		$ids = array_map( static fn( WP_Post $p ): int => $p->ID, $posts );
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, array_unique( array_map( static fn( WP_Post $p ): string => $p->post_type, $posts ) ) );
		cache_users( array_unique( array_map( static fn( WP_Post $p ): int => (int) $p->post_author, $posts ) ) );
		$notes = Notes::counts( $ids );
		return array_map( static fn( WP_Post $p ): array => self::card( $p, $wf, $notes[ $p->ID ] ?? 0 ), $posts );
	}

	/**
	 * Card payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function card( WP_Post $post, ?Workflow $wf = null, ?int $notes = null ): array {
		$wf    ??= Workflows::for_post( $post );
		$stage   = $wf ? Sync::current_stage( $post, $wf ) : null;
		$due     = Meta::due( $post->ID );
		$live    = $stage && $stage->wp_status->is_live();
		$today   = wp_date( 'Y-m-d' );
		$lock    = self::lock_holder( $post->ID );
		$cat     = self::category( $post );
		$author  = People::user( (int) $post->post_author );
		$content = (string) $post->post_content;

		return [
			'id'        => $post->ID,
			'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
			'stage'     => $stage?->id,
			'workflow'  => $wf?->key,
			'post_type' => $post->post_type,
			'is_page'   => 'page' === $post->post_type,
			'status'    => $post->post_status,
			'date'      => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : get_post_time( 'c', true, $post ),
			'category'  => $cat,
			'author'    => $author,
			'due'       => '' !== $due ? $due : null,
			'overdue'   => '' !== $due && ! $live && $due < $today,
			'checklist' => $wf ? Meta::progress( $post->ID, $wf ) : [
				'done'  => 0,
				'total' => 0,
			],
			'checked'   => (object) Meta::checklist( $post->ID ),
			'notes'     => $notes ?? Notes::count( $post->ID ),
			'locked_by' => $lock,
			'rev'       => Meta::rev( $post->ID ),
			'can_move'  => current_user_can( 'edit_post', $post->ID ),
			'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
			'excerpt'   => wp_trim_words( '' !== $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $content ) ), 30 ),
			'words'     => str_word_count( wp_strip_all_tags( $content ) ),
		];
	}

	/**
	 * @return array{id: int, name: string}|null
	 */
	public static function lock_holder( int $post_id ): ?array {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$user_id = wp_check_post_lock( $post_id );
		if ( ! $user_id ) {
			return null;
		}
		$u = People::user( (int) $user_id );
		return $u ? [
			'id'   => $u['id'],
			'name' => $u['name'],
		] : null;
	}

	/**
	 * @return array{id: int, name: string, color: string}|null
	 */
	public static function category( WP_Post $post ): ?array {
		if ( ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			return null;
		}
		$terms = get_the_terms( $post, 'category' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}
		$term = $terms[0];
		return [
			'id'    => $term->term_id,
			'name'  => html_entity_decode( $term->name, ENT_QUOTES ),
			'color' => self::category_color( $term->term_id ),
		];
	}

	public static function category_color( int $term_id ): string {
		$stored = sanitize_hex_color( (string) get_term_meta( $term_id, self::COLOR_META, true ) );
		return $stored ? $stored : self::CATEGORY_COLORS[ $term_id % count( self::CATEGORY_COLORS ) ];
	}

	/**
	 * Workflow-wide post count per stage (WIP limits, column totals). Read from the
	 * stage terms' count column, which WordPress keeps current on every change.
	 *
	 * @return array<string, int>
	 */
	public static function stage_counts( Workflow $wf, bool $cached = true ): array {
		return StageTaxonomy::counts( $wf );
	}

	public static function count_in_stage( Workflow $wf, string $stage, bool $cached = true ): int {
		return self::stage_counts( $wf, $cached )[ $stage ] ?? 0;
	}

	public static function flush_counts(): void {
		set_transient( 'masthead_count_gen', self::generation() + 1, DAY_IN_SECONDS );
	}

	/**
	 * `save_post` / `deleted_post`: clear counts for workflow post types.
	 */
	public static function on_post_change( int $post_id ): void {
		$type = get_post_type( $post_id );
		if ( $type && in_array( $type, Workflows::post_types(), true ) ) {
			self::flush_counts();
		}
	}

	/**
	 * Is the post's stage live (scheduled/published)?
	 */
	public static function is_live( WP_Post $post ): bool {
		$wf    = Workflows::for_post( $post );
		$stage = $wf ? Sync::current_stage( $post, $wf ) : null;
		return $stage ? $stage->wp_status->is_live() : in_array( WpStatus::from_post_status( $post->post_status ), [ WpStatus::Future, WpStatus::Publish ], true );
	}
}
