<?php
/**
 * Per-post activity log, stored in {prefix}masthead_events.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Activity {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'masthead_events';
	}

	/**
	 * Log an event.
	 *
	 * @param array<string, mixed> $meta     Event data.
	 * @param int|null             $actor_id Acting user; null means the current user (0 → system).
	 */
	public static function log( int $post_id, string $verb, array $meta = [], ?int $actor_id = null ): int {
		global $wpdb;
		$actor_id ??= get_current_user_id();
		$wpdb->insert(
			self::table(),
			[
				'post_id'    => $post_id,
				'actor_id'   => $actor_id > 0 ? $actor_id : null,
				'verb'       => substr( sanitize_key( $verb ), 0, 40 ),
				'meta'       => wp_json_encode( $meta ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return object{id: string, post_id: string, actor_id: ?string, verb: string, meta: string, created_at: string}|null
	 */
	public static function get( int $event_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $event_id ) );
		return $row ? $row : null;
	}

	/**
	 * Latest events first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function for_post( int $post_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', self::table(), $post_id, $limit )
		);
		return array_map( [ self::class, 'format' ], $rows ? $rows : [] );
	}

	public static function delete_for_post( int $post_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE post_id = %d', self::table(), $post_id ) );
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE event_id IN ({$in})", Notifications::table() ) );
		}
		$wpdb->delete( self::table(), [ 'post_id' => $post_id ], [ '%d' ] );
	}

	/**
	 * REST shape of an event row.
	 *
	 * @return array<string, mixed>
	 */
	public static function format( object $row ): array {
		$meta  = json_decode( (string) $row->meta, true );
		$meta  = is_array( $meta ) ? $meta : [];
		$actor = $row->actor_id ? People::user( (int) $row->actor_id ) : null;
		return [
			'id'         => (int) $row->id,
			'post_id'    => (int) $row->post_id,
			'actor'      => $actor,
			'verb'       => (string) $row->verb,
			'meta'       => $meta,
			'text'       => self::describe( (string) $row->verb, $meta ),
			'created_at' => mysql_to_rfc3339( (string) $row->created_at ) . 'Z',
		];
	}

	/**
	 * Human text that follows the actor's name.
	 *
	 * @param array<string, mixed> $meta Event data.
	 */
	public static function describe( string $verb, array $meta ): string {
		$date = static fn( string $ymd ): string => '' !== $ymd ? wp_date( 'M j', (int) strtotime( $ymd . ' 12:00:00' ) ) : '';
		return match ( $verb ) {
			/* translators: %s: post type label, lowercase. */
			'created'         => sprintf( __( 'created this %s', 'masthead' ), (string) ( $meta['type'] ?? __( 'post', 'masthead' ) ) ),
			/* translators: %s: stage name. */
			'moved_to'        => sprintf( __( 'moved to %s', 'masthead' ), (string) ( $meta['to_name'] ?? $meta['to'] ?? '' ) ),
			/* translators: %s: comma-separated first names. */
			'note'            => ! empty( $meta['mentioned'] ) ? sprintf( __( 'mentioned %s in a note', 'masthead' ), (string) $meta['mentioned'] ) : __( 'left a note', 'masthead' ),
			/* translators: %s: date. */
			'scheduled'       => sprintf( __( 'scheduled it for %s', 'masthead' ), $date( (string) ( $meta['due'] ?? '' ) ) ),
			'due_set'         => '' === (string) ( $meta['due'] ?? '' )
				? __( 'cleared the due date', 'masthead' )
				/* translators: %s: date. */
				: sprintf( __( 'set the date to %s', 'masthead' ), $date( (string) $meta['due'] ) ),
			/* translators: %s: user display name. */
			'assigned'        => sprintf( __( 'assigned it to %s', 'masthead' ), (string) ( $meta['name'] ?? '' ) ),
			/* translators: 1: post status, 2: stage name. */
			'status_unmapped' => sprintf( __( 'was moved to %2$s because no stage matches “%1$s”', 'masthead' ), (string) ( $meta['status'] ?? '' ), (string) ( $meta['to_name'] ?? '' ) ),
			'overdue'         => __( 'is now overdue', 'masthead' ),
			'renamed'         => __( 'renamed this post', 'masthead' ),
			default           => $verb,
		};
	}
}
