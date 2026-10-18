<?php
/**
 * Notifications: recipients, storage, read state.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\NotifyMode;
use Masthead\Model\Stage;

final class Notifications {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'masthead_notifications';
	}

	/**
	 * Recipients when a post enters a stage. The actor is always excluded.
	 *
	 * @param list<int> $extra Extra user IDs (e.g. @mentions).
	 * @return list<int>
	 */
	public static function recipients( \WP_Post $post, ?Stage $stage, int $actor_id, array $extra = [] ): array {
		$ids = [];
		if ( $stage ) {
			$ids = match ( $stage->notify ) {
				NotifyMode::Assignee => [ (int) $post->post_author ],
				NotifyMode::Editors  => Capabilities::users_with( Capabilities::APPROVE ),
				NotifyMode::Everyone => Capabilities::users_with( Capabilities::VIEW ),
				NotifyMode::None     => [],
			};
			if ( $stage->reviewer ) {
				$ids[] = $stage->reviewer;
			}
		}
		$ids = [ ...$ids, ...$extra ];
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 && $id !== $actor_id ) ) );

		/**
		 * Filters the users notified when a post enters a stage.
		 *
		 * @param list<int>  $ids      User IDs.
		 * @param \WP_Post   $post     Post.
		 * @param Stage|null $stage    Stage entered, or null for notes.
		 * @param int        $actor_id Acting user.
		 */
		return array_values( (array) apply_filters( 'masthead_notification_recipients', $ids, $post, $stage, $actor_id ) );
	}

	/**
	 * Create notifications for an event.
	 *
	 * @param list<int> $user_ids Recipients.
	 */
	public static function notify( int $event_id, array $user_ids ): void {
		global $wpdb;
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return;
		}
		$now = current_time( 'mysql', true );
		foreach ( array_chunk( $user_ids, 500 ) as $chunk ) {
			$rows   = [];
			$values = [];
			foreach ( $chunk as $user_id ) {
				$rows[] = '(%d, %d, %s)';
				array_push( $values, $user_id, $event_id, $now );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are generated.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id, event_id, created_at) VALUES ' . implode( ',', $rows ), self::table(), ...$values ) );
			foreach ( $chunk as $user_id ) {
				wp_cache_delete( 'unread_' . $user_id, 'masthead' );
			}
		}
	}

	public static function unread_count( int $user_id ): int {
		global $wpdb;
		$cached = wp_cache_get( 'unread_' . $user_id, 'masthead' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND read_at IS NULL', self::table(), $user_id ) );
		wp_cache_set( 'unread_' . $user_id, $count, 'masthead', 60 );
		return $count;
	}

	/**
	 * Latest notifications for a user.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function for_user( int $user_id, bool $unread_only = false, int $limit = 20 ): array {
		global $wpdb;
		$where = $unread_only ? 'AND n.read_at IS NULL' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a fixed string.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.id AS notification_id, n.read_at, e.* FROM %i n INNER JOIN %i e ON e.id = n.event_id
				WHERE n.user_id = %d {$where} ORDER BY n.created_at DESC, n.id DESC LIMIT %d",
				self::table(),
				Activity::table(),
				$user_id,
				$limit
			)
		);
		// phpcs:enable
		$out = [];
		foreach ( $rows ? $rows : [] as $row ) {
			$post  = get_post( (int) $row->post_id );
			$event = Activity::format( $row );
			$wf    = $post ? Workflows::for_post( $post ) : null;
			$out[] = [
				'id'       => (int) $row->notification_id,
				'read'     => null !== $row->read_at,
				'event'    => $event,
				'post'     => $post ? [
					'id'    => $post->ID,
					'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
				] : null,
				'board'    => $wf ? Boards::default_for_workflow( $wf->key ) : null,
				'edit_url' => $post ? get_edit_post_link( $post->ID, 'raw' ) : null,
			];
		}
		return $out;
	}

	/**
	 * Mark notifications read.
	 *
	 * @param list<int>|null $ids Notification IDs, or null for all.
	 */
	public static function mark_read( int $user_id, ?array $ids = null ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		if ( null === $ids ) {
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL', self::table(), $now, $user_id ) );
		} else {
			$ids = array_filter( array_map( 'intval', $ids ) );
			if ( ! $ids ) {
				return 0;
			}
			$in = implode( ',', $ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$n = $wpdb->query( $wpdb->prepare( "UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND id IN ({$in})", self::table(), $now, $user_id ) );
		}
		wp_cache_delete( 'unread_' . $user_id, 'masthead' );
		return (int) $n;
	}

	/**
	 * Heartbeat: send the unread count to the admin bar.
	 *
	 * @param array<string, mixed> $response Heartbeat response.
	 * @param array<string, mixed> $data     Heartbeat data.
	 * @return array<string, mixed>
	 */
	public static function heartbeat( array $response, array $data ): array {
		if ( empty( $data['masthead_notifications'] ) || ! current_user_can( Capabilities::VIEW ) ) {
			return $response;
		}
		$user_id                            = get_current_user_id();
		$response['masthead_notifications'] = [
			'unread' => self::unread_count( $user_id ),
		];
		return $response;
	}
}
