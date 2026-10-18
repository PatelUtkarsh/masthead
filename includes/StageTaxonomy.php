<?php
/**
 * Stages are stored as terms of an internal taxonomy (one term per workflow stage,
 * slug "{workflow}__{stage}"). Term relationships are indexed by term, so a board
 * column reads only the posts in that stage, and the term count column gives
 * per-stage totals without a query over every post.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\Workflow;

final class StageTaxonomy {

	public const string TAXONOMY = 'masthead_stage';

	private const string SEP = '__';

	/**
	 * Request cache: workflow key => [ stage id => term_taxonomy_id ].
	 *
	 * @var array<string, array<string, int>>
	 */
	private static array $maps = [];

	public static function register(): void {
		Workflows::register_post_types();
		register_taxonomy(
			self::TAXONOMY,
			Workflows::post_types(),
			[
				'label'              => __( 'Masthead stage', 'masthead' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_in_quick_edit' => false,
				'show_admin_column'  => false,
				'show_in_rest'       => false,
				'hierarchical'       => false,
				'query_var'          => false,
				'rewrite'            => false,
			]
		);
		foreach ( Workflows::post_types() as $post_type ) {
			register_taxonomy_for_object_type( self::TAXONOMY, $post_type );
		}
	}

	public static function slug( Workflow $wf, string $stage ): string {
		return $wf->key . self::SEP . $stage;
	}

	/**
	 * Stage ID => term_taxonomy_id for a workflow. Creates missing terms.
	 *
	 * @return array<string, int>
	 */
	public static function map( Workflow $wf ): array {
		if ( isset( self::$maps[ $wf->key ] ) ) {
			return self::$maps[ $wf->key ];
		}
		$cached = wp_cache_get( 'stage_map_' . $wf->key, 'masthead' );
		$map    = is_array( $cached ) ? $cached : self::read_map( $wf );
		$ids    = array_map( static fn( $s ) => $s->id, $wf->stages );
		if ( array_diff( $ids, array_keys( $map ) ) ) {
			$map = self::ensure_terms( $wf );
		}
		if ( array_diff( $ids, array_keys( $map ) ) ) {
			// Terms could not be created yet; don't cache an incomplete map.
			return $map;
		}
		wp_cache_set( 'stage_map_' . $wf->key, $map, 'masthead' );
		self::$maps[ $wf->key ] = $map;
		return $map;
	}

	/**
	 * Every term of this workflow, including stages that were removed.
	 *
	 * @return array<string, int>
	 */
	private static function read_map( Workflow $wf ): array {
		global $wpdb;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.slug, tt.term_taxonomy_id FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy = %s AND t.slug LIKE %s",
				self::TAXONOMY,
				$wpdb->esc_like( $wf->key . self::SEP ) . '%'
			)
		);
		$map    = [];
		$prefix = strlen( $wf->key . self::SEP );
		foreach ( (array) $rows as $row ) {
			$map[ substr( (string) $row->slug, $prefix ) ] = (int) $row->term_taxonomy_id;
		}
		return $map;
	}

	/**
	 * Create terms for every stage of a workflow.
	 *
	 * @return array<string, int>
	 */
	public static function ensure_terms( Workflow $wf ): array {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			self::register();
		}
		$map = self::read_map( $wf );
		foreach ( $wf->stages as $stage ) {
			if ( isset( $map[ $stage->id ] ) ) {
				continue;
			}
			$slug = self::slug( $wf, $stage->id );
			$term = wp_insert_term(
				$slug,
				self::TAXONOMY,
				[ 'slug' => $slug ]
			);
			if ( is_array( $term ) ) {
				$map[ $stage->id ] = (int) $term['term_taxonomy_id'];
			} elseif ( is_wp_error( $term ) && $term->get_error_data( 'term_exists' ) ) {
				$existing = get_term( (int) $term->get_error_data( 'term_exists' ), self::TAXONOMY );
				if ( $existing && ! is_wp_error( $existing ) ) {
					$map[ $stage->id ] = (int) $existing->term_taxonomy_id;
				}
			}
		}
		self::forget( $wf->key );
		return $map;
	}

	/**
	 * term_taxonomy_ids of the current stages of a workflow.
	 *
	 * @return list<int>
	 */
	public static function ids( Workflow $wf ): array {
		$map = self::map( $wf );
		$out = [];
		foreach ( $wf->stages as $stage ) {
			if ( isset( $map[ $stage->id ] ) ) {
				$out[] = $map[ $stage->id ];
			}
		}
		return $out;
	}

	public static function tt_id( Workflow $wf, string $stage ): int {
		return self::map( $wf )[ $stage ] ?? 0;
	}

	/**
	 * A tax_query clause for one stage, several stages, or every stage of a workflow.
	 *
	 * @param list<string>|null $stages Stage IDs, or null for all current stages.
	 * @return array<string, mixed>
	 */
	public static function clause( Workflow $wf, ?array $stages = null ): array {
		$map = self::map( $wf );
		$tts = null === $stages
			? self::ids( $wf )
			: array_values( array_filter( array_map( static fn( $s ) => $map[ $s ] ?? 0, $stages ) ) );
		return [
			'taxonomy'         => self::TAXONOMY,
			'field'            => 'term_taxonomy_id',
			'terms'            => $tts ? $tts : [ 0 ],
			'operator'         => 'IN',
			'include_children' => false,
		];
	}

	/**
	 * Stage ID of a post, or '' when it has none in this workflow.
	 */
	public static function stage_of( int $post_id, Workflow $wf ): string {
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		$prefix = $wf->key . self::SEP;
		foreach ( $terms as $term ) {
			if ( str_starts_with( $term->slug, $prefix ) ) {
				return substr( $term->slug, strlen( $prefix ) );
			}
		}
		return '';
	}

	public static function set( int $post_id, Workflow $wf, string $stage ): void {
		$tt = self::tt_id( $wf, $stage );
		if ( ! $tt ) {
			return;
		}
		$term = get_term_by( 'term_taxonomy_id', $tt, self::TAXONOMY );
		if ( $term ) {
			wp_set_object_terms( $post_id, [ (int) $term->term_id ], self::TAXONOMY, false );
		}
	}

	/**
	 * Put many posts in one stage with set-based SQL, then recount once.
	 *
	 * @param list<int> $ids Post IDs.
	 */
	public static function set_bulk( Workflow $wf, array $ids, string $stage ): void {
		global $wpdb;
		$tt  = self::tt_id( $wf, $stage );
		$all = array_values( array_unique( array_values( self::map( $wf ) ) ) );
		if ( ! $tt || ! $ids ) {
			return;
		}
		$in_tt = implode( ',', array_map( 'intval', $all ) );
		foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- integers only.
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in}) AND term_taxonomy_id IN ({$in_tt})" );
			$values = implode( ',', array_map( static fn( int $id ): string => "({$id},{$tt},0)", $chunk ) );
			$wpdb->query( "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES {$values}" );
			// phpcs:enable
			clean_object_term_cache( $chunk, $wf->post_type );
		}
		wp_update_term_count_now( $all, self::TAXONOMY );
	}

	/**
	 * Post IDs in a stage.
	 *
	 * @return list<int>
	 */
	public static function post_ids( Workflow $wf, string $stage ): array {
		global $wpdb;
		$tt = self::tt_id( $wf, $stage );
		if ( ! $tt ) {
			return [];
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt ) ) );
	}

	/**
	 * Posts per stage, from the term count column (WordPress keeps it current).
	 *
	 * @return array<string, int>
	 */
	public static function counts( Workflow $wf ): array {
		global $wpdb;
		$map = self::map( $wf );
		if ( ! $map ) {
			return [];
		}
		$in = implode( ',', array_map( 'intval', $map ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$rows = $wpdb->get_results( "SELECT term_taxonomy_id, count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$in})" );
		$by   = [];
		foreach ( (array) $rows as $row ) {
			$by[ (int) $row->term_taxonomy_id ] = (int) $row->count;
		}
		$out = [];
		foreach ( $map as $stage => $tt ) {
			$out[ $stage ] = $by[ $tt ] ?? 0;
		}
		return $out;
	}

	/**
	 * `update_post_term_count_statuses`: count every active status for stage terms,
	 * not only "publish". Trash and auto-drafts stay out of the count.
	 *
	 * @param list<string>        $statuses Counted statuses.
	 * @param \WP_Taxonomy|string $taxonomy Taxonomy.
	 * @return list<string>
	 */
	public static function counted_statuses( array $statuses, $taxonomy ): array {
		$name = $taxonomy instanceof \WP_Taxonomy ? $taxonomy->name : (string) $taxonomy;
		return self::TAXONOMY === $name ? Cards::STATUSES : $statuses;
	}

	/**
	 * Delete terms of stages that no longer exist in a workflow.
	 * Posts still attached lose the relationship; move them first.
	 */
	public static function prune( Workflow $wf ): void {
		$current = array_flip( array_map( static fn( $s ) => $s->id, $wf->stages ) );
		foreach ( self::read_map( $wf ) as $stage => $tt ) {
			if ( isset( $current[ $stage ] ) ) {
				continue;
			}
			$term = get_term_by( 'term_taxonomy_id', $tt, self::TAXONOMY );
			if ( $term ) {
				wp_delete_term( (int) $term->term_id, self::TAXONOMY );
			}
		}
		self::forget( $wf->key );
	}

	/**
	 * Stage IDs in this workflow's terms that are not current stages, with their posts.
	 *
	 * @return array<string, list<int>>
	 */
	public static function orphans( Workflow $wf ): array {
		$current = array_flip( array_map( static fn( $s ) => $s->id, $wf->stages ) );
		$out     = [];
		foreach ( array_keys( self::read_map( $wf ) ) as $stage ) {
			if ( ! isset( $current[ $stage ] ) ) {
				self::$maps[ $wf->key ] = self::read_map( $wf );
				$out[ $stage ]          = self::post_ids( $wf, $stage );
			}
		}
		self::forget( $wf->key );
		return $out;
	}

	public static function forget( ?string $key = null ): void {
		if ( null === $key ) {
			foreach ( array_keys( self::$maps ) as $k ) {
				wp_cache_delete( 'stage_map_' . $k, 'masthead' );
			}
			self::$maps = [];
			return;
		}
		unset( self::$maps[ $key ] );
		wp_cache_delete( 'stage_map_' . $key, 'masthead' );
	}
}
