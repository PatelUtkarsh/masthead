<?php
/**
 * Scheduled jobs: morning digest and checklist clean-up batches.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Cron {

	public const string DIGEST       = 'masthead_digest';
	public const string DIGEST_BATCH = 'masthead_digest_batch';
	public const string CLEANUP      = 'masthead_checklist_cleanup';
	public const string IMPORT       = 'masthead_import';
	public const string STATUS_SYNC  = 'masthead_status_sync';
	public const int BATCH           = 200;
	public const int DIGEST_USERS    = 50;

	public static function register(): void {
		add_action( self::DIGEST, [ self::class, 'send_digest' ] );
		add_action( self::DIGEST_BATCH, [ self::class, 'send_digest_batch' ], 10, 1 );
		add_action( self::CLEANUP, [ self::class, 'cleanup_checklist' ], 10, 3 );
		add_action( self::IMPORT, [ self::class, 'run_import' ], 10, 2 );
		add_action( self::STATUS_SYNC, [ self::class, 'run_status_sync' ], 10, 2 );
	}

	public static function queue_import( string $workflow, int $after_id ): void {
		wp_schedule_single_event( time(), self::IMPORT, [ $workflow, $after_id ] );
	}

	public static function run_import( string $workflow, int $after_id ): void {
		$wf = Workflows::get( $workflow );
		if ( ! $wf ) {
			return;
		}
		$result = Sync::import_batch( $wf, $after_id, Sync::INLINE_BATCH );
		if ( ! $result['done'] ) {
			wp_schedule_single_event( time() + 5, self::IMPORT, [ $workflow, $result['last'] ] );
		}
	}

	/**
	 * @param list<int> $ids Post IDs.
	 */
	public static function queue_status_sync( string $workflow, array $ids ): void {
		foreach ( array_chunk( array_map( 'intval', $ids ), self::BATCH ) as $i => $chunk ) {
			wp_schedule_single_event( time() + $i * 5, self::STATUS_SYNC, [ $workflow, $chunk ] );
		}
	}

	/**
	 * @param list<int> $ids Post IDs.
	 */
	public static function run_status_sync( string $workflow, array $ids ): void {
		$wf = Workflows::get( $workflow );
		if ( $wf ) {
			Sync::sync_statuses( $wf, array_map( 'intval', $ids ), PHP_INT_MAX );
		}
	}

	/**
	 * Schedule (or unschedule) the daily digest at the configured site time.
	 */
	public static function schedule_digest(): void {
		wp_clear_scheduled_hook( self::DIGEST );
		$rules = Options::rules();
		if ( ! $rules['digest'] ) {
			return;
		}
		$tz   = wp_timezone();
		$next = new \DateTimeImmutable( 'today ' . $rules['digest_time'], $tz );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::DIGEST );
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::DIGEST );
		foreach ( [ self::DIGEST_BATCH, self::CLEANUP, self::IMPORT, self::STATUS_SYNC ] as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Remove checklist item keys from every post of a type, in batches.
	 *
	 * @param list<string> $item_ids Removed checklist item IDs.
	 */
	public static function queue_checklist_cleanup( string $post_type, array $item_ids ): void {
		wp_schedule_single_event( time(), self::CLEANUP, [ $post_type, array_values( $item_ids ), 0 ] );
	}

	/**
	 * @param list<string> $item_ids Removed checklist item IDs.
	 */
	public static function cleanup_checklist( string $post_type, array $item_ids, int $after_id = 0 ): void {
		global $wpdb;
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND pm.post_id > %d ORDER BY pm.post_id ASC LIMIT %d",
				Meta::CHECKLIST,
				$post_type,
				$after_id,
				self::BATCH
			)
		);
		$last = $after_id;
		foreach ( $ids as $id ) {
			$id      = (int) $id;
			$last    = $id;
			$current = Meta::checklist( $id );
			$next    = array_diff_key( $current, array_flip( $item_ids ) );
			if ( count( $next ) !== count( $current ) ) {
				Meta::set_checklist( $id, $next );
			}
		}
		if ( count( $ids ) === self::BATCH ) {
			wp_schedule_single_event( time() + 5, self::CLEANUP, [ $post_type, $item_ids, $last ] );
		}
	}

	/**
	 * Daily entry point: start the digest in batches of users.
	 */
	public static function send_digest(): void {
		if ( Options::rules()['digest'] ) {
			self::send_digest_batch( 0 );
		}
	}

	/**
	 * One email per user: overdue posts, posts due today and unread notifications.
	 * Handles a batch of users, then schedules the next batch.
	 */
	public static function send_digest_batch( int $offset = 0 ): void {
		if ( ! Options::rules()['digest'] ) {
			return;
		}
		$ids = array_map(
			'intval',
			get_users(
				[
					'capability'  => Capabilities::VIEW,
					'fields'      => 'ID',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'number'      => self::DIGEST_USERS,
					'offset'      => $offset,
					'count_total' => false,
				]
			)
		);
		foreach ( $ids as $user_id ) {
			self::send_digest_to( $user_id );
		}
		if ( count( $ids ) >= self::DIGEST_USERS ) {
			wp_schedule_single_event( time() + 10, self::DIGEST_BATCH, [ $offset + self::DIGEST_USERS ] );
		}
	}

	public static function send_digest_to( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$today     = wp_date( 'Y-m-d' );
		$overdue   = [];
		$due_today = [];
		$posts     = get_posts(
			[
				'post_type'      => Workflows::post_types(),
				'post_status'    => [ 'draft', 'pending' ],
				'author'         => $user_id,
				'posts_per_page' => 25,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[
						'key'     => Meta::DUE,
						'value'   => $today,
						'compare' => '<=',
						'type'    => 'DATE',
					],
				],
			]
		);
		foreach ( $posts as $post ) {
			$due  = Meta::due( $post->ID );
			$line = '- ' . html_entity_decode( get_the_title( $post ), ENT_QUOTES ) . ' — ' . get_edit_post_link( $post->ID, 'raw' );
			if ( $due < $today ) {
				$overdue[] = $line;
			} elseif ( $due === $today ) {
				$due_today[] = $line;
			}
		}
		$unread = Notifications::for_user( $user_id, true, 20 );
		if ( ! $overdue && ! $due_today && ! $unread ) {
			return false;
		}
		$body = [];
		if ( $overdue ) {
			$body[] = __( 'Overdue', 'masthead' ) . "\n" . implode( "\n", $overdue );
		}
		if ( $due_today ) {
			$body[] = __( 'Due today', 'masthead' ) . "\n" . implode( "\n", $due_today );
		}
		if ( $unread ) {
			$lines  = array_map(
				static fn( array $n ): string => '- ' . ( $n['event']['actor']['name'] ?? __( 'Masthead', 'masthead' ) ) . ' ' . $n['event']['text'] . ( $n['post'] ? ': ' . $n['post']['title'] : '' ),
				$unread
			);
			$body[] = __( 'Unread notifications', 'masthead' ) . "\n" . implode( "\n", $lines );
		}
		$body[] = admin_url( 'admin.php?page=masthead' );
		return wp_mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Your Masthead morning digest', 'masthead' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			implode( "\n\n", $body )
		);
	}
}
