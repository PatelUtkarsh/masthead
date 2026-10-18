<?php
/**
 * Editorial notes, stored as comments with type `masthead_note`.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use WP_Comment;
use WP_Comment_Query;
use WP_Post;

final class Notes {

	public const string TYPE     = 'masthead_note';
	public const string MENTIONS = '_masthead_mentions';

	/**
	 * Hide notes from every comment query unless the query asks for them.
	 */
	public static function exclude_from_queries( WP_Comment_Query $query ): void {
		$type = $query->query_vars['type'] ?? '';
		$in   = (array) ( $query->query_vars['type__in'] ?? [] );
		if ( self::TYPE === $type || in_array( self::TYPE, $in, true ) ) {
			return;
		}
		$not_in                            = (array) ( $query->query_vars['type__not_in'] ?? [] );
		$not_in[]                          = self::TYPE;
		$query->query_vars['type__not_in'] = array_values( array_unique( $not_in ) );
	}

	public static function exclude_from_feed( string $where ): string {
		global $wpdb;
		return $where . $wpdb->prepare( ' AND comment_type != %s', self::TYPE );
	}

	/**
	 * Keep `comment_count` (used by the admin and themes) free of notes.
	 *
	 * @param int|null $new_count Pre-computed count.
	 */
	public static function update_comment_count( ?int $new_count, int $old, int $post_id ): ?int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_type != %s", $post_id, self::TYPE )
		);
	}

	/**
	 * Remove notes from wp_count_comments() totals.
	 *
	 * @param array<string, int>|object $count Counts.
	 */
	public static function count_comments( array|object $count, int $post_id ): array|object {
		if ( ! empty( $count ) ) {
			return $count;
		}
		global $wpdb;
		$where = $post_id > 0 ? $wpdb->prepare( 'AND comment_post_ID = %d', $post_id ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_approved, COUNT(*) AS num FROM {$wpdb->comments} WHERE comment_type != %s {$where} GROUP BY comment_approved", self::TYPE ), ARRAY_A );
		$out  = [
			'approved'       => 0,
			'moderated'      => 0,
			'spam'           => 0,
			'trash'          => 0,
			'post-trashed'   => 0,
			'total_comments' => 0,
			'all'            => 0,
		];
		foreach ( (array) $rows as $row ) {
			$num = (int) $row['num'];
			switch ( $row['comment_approved'] ) {
				case '1':
					$out['approved']       += $num;
					$out['total_comments'] += $num;
					$out['all']            += $num;
					break;
				case '0':
					$out['moderated']      += $num;
					$out['total_comments'] += $num;
					$out['all']            += $num;
					break;
				case 'spam':
					$out['spam'] += $num;
					break;
				case 'trash':
					$out['trash'] += $num;
					break;
				case 'post-trashed':
					$out['post-trashed'] += $num;
					break;
			}
		}
		return (object) $out;
	}

	public static function count( int $post_id ): int {
		return self::counts( [ $post_id ] )[ $post_id ] ?? 0;
	}

	/**
	 * Note counts for many posts in one query.
	 *
	 * @param list<int> $post_ids Post IDs.
	 * @return array<int, int>
	 */
	public static function counts( array $post_ids ): array {
		global $wpdb;
		$post_ids = array_filter( array_map( 'intval', $post_ids ) );
		if ( ! $post_ids ) {
			return [];
		}
		$in = implode( ',', $post_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_post_ID AS id, COUNT(*) AS n FROM {$wpdb->comments} WHERE comment_type = %s AND comment_approved = '1' AND comment_post_ID IN ({$in}) GROUP BY comment_post_ID", self::TYPE ) );
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function for_post( int $post_id ): array {
		$comments = get_comments(
			[
				'post_id' => $post_id,
				'type'    => self::TYPE,
				'status'  => 'approve',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
				'number'  => 200,
			]
		);
		return array_map( [ self::class, 'format' ], is_array( $comments ) ? $comments : [] );
	}

	/**
	 * Add a note and notify the assignee and anyone mentioned.
	 *
	 * @param list<int> $mentions Mentioned user IDs (from the autocomplete).
	 * @return array{note: array<string, mixed>, notified: list<int>}|\WP_Error
	 */
	public static function add( WP_Post $post, string $text, array $mentions = [] ): array|\WP_Error {
		$user = wp_get_current_user();
		$text = trim( wp_kses( $text, [] ) );
		if ( '' === $text ) {
			return new \WP_Error( 'masthead_empty_note', __( 'Write a note first.', 'masthead' ), [ 'status' => 400 ] );
		}

		$mentions = self::resolve_mentions( $text, $mentions, $user->ID );

		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $post->ID,
				'comment_content'      => $text,
				'comment_type'         => self::TYPE,
				'comment_approved'     => 1,
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
			]
		);
		if ( ! $comment_id ) {
			return new \WP_Error( 'masthead_note_failed', __( 'Couldn’t save the note.', 'masthead' ), [ 'status' => 500 ] );
		}
		update_comment_meta( $comment_id, self::MENTIONS, $mentions );

		$event    = Activity::log(
			$post->ID,
			'note',
			[
				'comment_id' => $comment_id,
				'mentions'   => $mentions,
				'mentioned'  => People::firsts( $mentions ),
			]
		);
		$notified = Notifications::recipients( $post, null, $user->ID, [ (int) $post->post_author, ...$mentions ] );
		Notifications::notify( $event, $notified );

		$comment = get_comment( $comment_id );
		return [
			'note'     => $comment instanceof WP_Comment ? self::format( $comment ) : [],
			'notified' => $notified,
			'mentions' => $mentions,
		];
	}

	/**
	 * Mentions are the IDs sent by the autocomplete, kept only when the user can view
	 * Masthead and "@Display Name" appears in the text. Capped, so a note can't fan out.
	 *
	 * @param list<int> $ids IDs sent by the client.
	 * @return list<int>
	 */
	public static function resolve_mentions( string $text, array $ids, int $author_id ): array {
		$out = [];
		foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 20 ) as $uid ) {
			if ( $uid === $author_id || ! user_can( $uid, Capabilities::VIEW ) ) {
				continue;
			}
			$u = People::user( $uid );
			if ( $u && str_contains( $text, '@' . $u['name'] ) ) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function format( WP_Comment $c ): array {
		$mentions = get_comment_meta( (int) $c->comment_ID, self::MENTIONS, true );
		return [
			'id'         => (int) $c->comment_ID,
			'author'     => People::user( (int) $c->user_id ),
			'text'       => $c->comment_content,
			'mentions'   => array_values(
				array_filter(
					array_map(
						static fn( $id ) => People::user( (int) $id ),
						is_array( $mentions ) ? $mentions : []
					)
				)
			),
			'created_at' => mysql_to_rfc3339( $c->comment_date_gmt ) . 'Z',
		];
	}
}
