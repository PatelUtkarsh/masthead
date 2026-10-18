<?php
/**
 * Keeps stages in sync with post status changes made outside Masthead.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\Stage;
use Masthead\Model\Workflow;
use Masthead\Model\WpStatus;
use WP_Post;

final class Sync {

	/**
	 * True while Masthead itself writes a post. Guards against re-entrancy.
	 */
	private static bool $applying = false;

	public static function is_applying(): bool {
		return self::$applying;
	}

	/**
	 * Run a callback with the re-entrancy flag set.
	 *
	 * @template T
	 * @param callable(): T $callback Callback.
	 * @return T
	 */
	public static function applying( callable $callback ): mixed {
		$previous       = self::$applying;
		self::$applying = true;
		try {
			return $callback();
		} finally {
			self::$applying = $previous;
		}
	}

	/**
	 * The stage a post is in. Falls back to the first stage that matches its status, then the first stage.
	 */
	public static function current_stage( WP_Post $post, Workflow $wf ): ?Stage {
		$id = $post->ID > 0 ? Meta::stage( $post->ID ) : '';
		if ( '' !== $id ) {
			$stage = $wf->stage( $id );
			if ( $stage ) {
				return $stage;
			}
		}
		$status = WpStatus::from_post_status( $post->post_status );
		return ( $status ? $wf->first_stage_for( $status ) : null ) ?? $wf->first_stage();
	}

	/**
	 * Stage a post should be in for a status: keep the current stage if it maps, else the first match.
	 */
	public static function target_for_status( Workflow $wf, ?Stage $current, string $post_status ): ?Stage {
		$status = WpStatus::from_post_status( $post_status );
		if ( ! $status ) {
			return null;
		}
		if ( $current && $current->wp_status === $status ) {
			return $current;
		}
		return $wf->first_stage_for( $status );
	}

	/**
	 * `transition_post_status` handler.
	 */
	public static function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( self::$applying || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$wf = Workflows::for_post( $post );
		if ( ! $wf || in_array( $new_status, [ 'auto-draft', 'inherit' ], true ) ) {
			return;
		}

		// Trashed posts keep their stage term (the term count skips trash), so a
		// restored post comes back in the stage it had.
		if ( 'trash' === $new_status ) {
			Cards::flush_counts();
			return;
		}
		if ( 'trash' === $old_status && '' !== Meta::stage( $post->ID ) ) {
			Cards::flush_counts();
			return;
		}

		$is_new = in_array( $old_status, [ 'new', 'auto-draft' ], true ) || '' === Meta::stage( $post->ID );
		self::sync_post( $post, $wf, $is_new );
	}

	/**
	 * Put a post in the stage that matches its status.
	 */
	public static function sync_post( WP_Post $post, Workflow $wf, bool $is_new = false, bool $notify = true ): void {
		$stored  = Meta::stage( $post->ID );
		$current = '' !== $stored ? $wf->stage( $stored ) : null;
		$target  = self::target_for_status( $wf, $current, $post->post_status );

		if ( $target && $current && $target->id === $current->id ) {
			return;
		}

		$actor = get_current_user_id();

		if ( ! $target ) {
			$target = $wf->first_stage();
			if ( ! $target ) {
				return;
			}
			self::write_stage( $post->ID, $target->id );
			Activity::log(
				$post->ID,
				'status_unmapped',
				[
					'status'  => $post->post_status,
					'to'      => $target->id,
					'to_name' => $target->name,
				],
				0
			);
			Cards::flush_counts();
			return;
		}

		self::write_stage( $post->ID, $target->id );
		Cards::flush_counts();

		if ( $is_new && '' === $stored ) {
			$pto = get_post_type_object( $post->post_type );
			Activity::log(
				$post->ID,
				'created',
				[
					'type' => $pto ? strtolower( (string) $pto->labels->singular_name ) : 'post',
					'to'   => $target->id,
				],
				(int) $post->post_author ? $actor : 0
			);
			return;
		}

		$event = Activity::log(
			$post->ID,
			'moved_to',
			[
				'from'      => $current?->id,
				'from_name' => $current?->name,
				'to'        => $target->id,
				'to_name'   => $target->name,
				'source'    => 'status',
			],
			$actor
		);
		if ( $notify ) {
			Notifications::notify( $event, Notifications::recipients( $post, $target, $actor ) );
		}
	}

	/**
	 * Restore the status a post had before it was trashed, so it returns to its stage.
	 */
	public static function untrash_status( string $new_status, int $post_id, string $previous_status ): string {
		$post = get_post( $post_id );
		if ( $post && Workflows::for_post( $post ) && '' !== $previous_status ) {
			return $previous_status;
		}
		return $new_status;
	}

	/**
	 * Posts handled inside the current request before the rest moves to WP-Cron.
	 */
	public const int INLINE_BATCH = 500;

	/**
	 * Map existing posts of a workflow to stages. Maps one batch now and queues the rest,
	 * so large sites don't time out.
	 *
	 * @return int Number of posts mapped in this request.
	 */
	public static function import( Workflow $wf ): int {
		$result = self::import_batch( $wf, 0, self::INLINE_BATCH );
		if ( ! $result['done'] ) {
			Cron::queue_import( $wf->key, $result['last'] );
		}
		return $result['count'];
	}

	/**
	 * Map one batch of posts without a stage in this workflow, by ascending ID after
	 * a cursor. Posts are grouped by target stage and written with set-based SQL.
	 *
	 * @return array{count: int, last: int, done: bool}
	 */
	public static function import_batch( Workflow $wf, int $after_id, int $limit ): array {
		global $wpdb;
		$statuses = "'" . implode( "','", array_map( 'esc_sql', Cards::STATUSES ) ) . "'";
		$tts      = implode( ',', array_map( 'intval', StageTaxonomy::ids( $wf ) ) );
		$tts      = '' !== $tts ? $tts : '0';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- escaped lists and integers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_status FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id IN ({$tts})
				WHERE p.post_type = %s AND p.post_status IN ({$statuses}) AND p.ID > %d AND tr.object_id IS NULL
				ORDER BY p.ID ASC LIMIT %d",
				$wf->post_type,
				$after_id,
				$limit
			)
		);
		// phpcs:enable
		$groups = [];
		$last   = $after_id;
		foreach ( (array) $rows as $row ) {
			$last   = (int) $row->ID;
			$status = WpStatus::from_post_status( (string) $row->post_status );
			$target = ( $status ? $wf->first_stage_for( $status ) : null ) ?? $wf->first_stage();
			if ( $target ) {
				$groups[ $target->id ][] = $last;
			}
		}
		$count = 0;
		foreach ( $groups as $stage => $ids ) {
			StageTaxonomy::set_bulk( $wf, $ids, (string) $stage );
			$count += count( $ids );
		}
		Cards::flush_counts();
		return [
			'count' => $count,
			'last'  => $last,
			'done'  => count( (array) $rows ) < $limit,
		];
	}

	/**
	 * Posts left in stages a workflow no longer has: map them by status, then delete
	 * those stage terms.
	 */
	public static function remap_orphans( Workflow $wf ): int {
		global $wpdb;
		$count = 0;
		foreach ( StageTaxonomy::orphans( $wf ) as $ids ) {
			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
				$rows   = $wpdb->get_results( "SELECT ID, post_status FROM {$wpdb->posts} WHERE ID IN ({$in})" );
				$groups = [];
				foreach ( (array) $rows as $row ) {
					$status = WpStatus::from_post_status( (string) $row->post_status );
					$target = ( $status ? $wf->first_stage_for( $status ) : null ) ?? $wf->first_stage();
					if ( $target ) {
						$groups[ $target->id ][] = (int) $row->ID;
					}
				}
				foreach ( $groups as $stage => $group ) {
					StageTaxonomy::set_bulk( $wf, $group, (string) $stage );
					$count += count( $group );
				}
			}
		}
		StageTaxonomy::prune( $wf );
		Cards::flush_counts();
		return $count;
	}

	/**
	 * Map one post to the first stage that matches its status, without logging.
	 */
	public static function map_one( WP_Post $post, Workflow $wf ): bool {
		$status = WpStatus::from_post_status( $post->post_status );
		$target = ( $status ? $wf->first_stage_for( $status ) : null ) ?? $wf->first_stage();
		if ( ! $target ) {
			return false;
		}
		self::write_stage( $post->ID, $target->id );
		return true;
	}

	/**
	 * IDs of posts of a workflow in a stage.
	 *
	 * @return list<int>
	 */
	public static function ids_in_stage( Workflow $wf, string $stage ): array {
		return StageTaxonomy::post_ids( $wf, $stage );
	}

	/**
	 * Move posts between stages in bulk (stage deleted, or Undo).
	 *
	 * @param array<string, string> $moves Old stage ID → new stage ID. Missing stages fall back to the first stage.
	 */
	public static function reassign( Workflow $wf, array $moves ): int {
		$count = 0;
		foreach ( $moves as $from => $to ) {
			$target = $wf->stage( $to ) ?? $wf->first_stage();
			if ( ! $target ) {
				continue;
			}
			$ids    = self::ids_in_stage( $wf, (string) $from );
			$count += self::set_stage_bulk( $wf, $ids, $target->id );
		}
		return $count;
	}

	/**
	 * Write one stage to many posts with set-based SQL, then fix post statuses.
	 *
	 * @param list<int> $ids Post IDs.
	 */
	public static function set_stage_bulk( Workflow $wf, array $ids, string $stage ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		StageTaxonomy::set_bulk( $wf, $ids, $stage );
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE meta_key = %s AND post_id IN ({$in})", Meta::REV ) );
			foreach ( $chunk as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}
		}
		// Only posts whose status doesn't match the stage need a (slow) wp_update_post.
		$target = $wf->stage( $stage );
		if ( $target && $ids ) {
			$ok  = WpStatus::Publish === $target->wp_status ? [ 'publish', 'private' ] : [ $target->wp_status->value ];
			$bad = [];
			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$in       = implode( ',', $chunk );
				$statuses = "'" . implode( "','", array_map( 'esc_sql', $ok ) ) . "'";
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- integers and escaped statuses.
				$bad = [ ...$bad, ...array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_status NOT IN ({$statuses})" ) ) ];
			}
			self::sync_statuses( $wf, $bad );
		}
		Cards::flush_counts();
		return count( $ids );
	}

	/**
	 * Give posts the status of their stage. A batch runs now; the rest goes to WP-Cron.
	 *
	 * @param list<int> $ids Post IDs.
	 */
	public static function sync_statuses( Workflow $wf, array $ids, int $inline = 100 ): void {
		$now  = array_slice( $ids, 0, $inline );
		$rest = array_slice( $ids, $inline );
		foreach ( $now as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$stage = self::current_stage( $post, $wf );
			if ( ! $stage || WpStatus::from_post_status( $post->post_status ) === $stage->wp_status ) {
				continue;
			}
			$change = Rules::status_change( $post, $stage );
			if ( is_array( $change ) && $change ) {
				self::applying(
					static fn() => wp_update_post(
						[
							'ID' => $id,
							...$change,
						]
					)
				);
			}
		}
		if ( $rest ) {
			Cron::queue_status_sync( $wf->key, $rest );
		}
	}

	/**
	 * Write the stage meta and bump the revision without triggering sync.
	 */
	public static function write_stage( int $post_id, string $stage ): void {
		self::applying(
			static function () use ( $post_id, $stage ): void {
				Meta::set_stage( $post_id, $stage );
				Meta::bump_rev( $post_id );
			}
		);
	}
}
