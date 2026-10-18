<?php
/**
 * User summaries for REST payloads, team preload and user search.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use WP_User_Query;

final class People {

	private const array COLORS = [ '#3858e9', '#c2410c', '#0f766e', '#7c3aed', '#be185d', '#0369a1', '#15803d', '#b45309' ];

	/**
	 * Request cache.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static array $cache = [];

	/**
	 * @return array{id: int, name: string, first: string, initials: string, color: string, avatar: string, role: string}|null
	 */
	public static function user( int $user_id ): ?array {
		if ( array_key_exists( $user_id, self::$cache ) ) {
			return self::$cache[ $user_id ];
		}
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			self::$cache[ $user_id ] = null;
			return null;
		}
		$name  = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$parts = preg_split( '/\s+/', trim( $name ) );
		$parts = is_array( $parts ) ? array_values( array_filter( $parts ) ) : [ $name ];
		$ini   = strtoupper( mb_substr( $parts[0] ?? '', 0, 1 ) . ( count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '' ) );
		$roles = array_values( $user->roles );
		$role  = $roles[0] ?? '';

		self::$cache[ $user_id ] = [
			'id'       => $user_id,
			'name'     => $name,
			'first'    => (string) ( $parts[0] ?? $name ),
			'initials' => $ini,
			'color'    => self::COLORS[ $user_id % count( self::COLORS ) ],
			'avatar'   => (string) get_avatar_url( $user_id, [ 'size' => 48 ] ),
			'role'     => $role ? translate_user_role( wp_roles()->role_names[ $role ] ?? $role ) : '',
		];
		return self::$cache[ $user_id ];
	}

	/**
	 * Summary plus team flags.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function member( int $user_id ): ?array {
		$u = self::user( $user_id );
		if ( ! $u ) {
			return null;
		}
		$u['can_approve'] = user_can( $user_id, Capabilities::APPROVE );
		$u['can_edit']    = user_can( $user_id, 'edit_posts' );
		return $u;
	}

	/**
	 * Search users who can view Masthead. No COUNT query: fetches one extra row to
	 * know whether another page exists.
	 *
	 * @param array{search?: string, include?: list<int>, approvers?: bool, editors?: bool, page?: int, per_page?: int, any_role?: bool} $args Query.
	 * @return array{users: list<array<string, mixed>>, has_more: bool}
	 */
	public static function search( array $args = [] ): array {
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$q        = [
			'number'      => $per_page + 1,
			'offset'      => ( $page - 1 ) * $per_page,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'fields'      => 'ID',
			'count_total' => false,
		];
		if ( empty( $args['any_role'] ) ) {
			$q['capability'] = ! empty( $args['approvers'] ) ? Capabilities::APPROVE : Capabilities::VIEW;
		} else {
			$q['role__in'] = array_keys( Capabilities::MAP );
		}
		if ( ! empty( $args['editors'] ) ) {
			$q['capability__in'] = [ 'edit_posts' ];
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$q['search']         = '*' . $search . '*';
			$q['search_columns'] = [ 'display_name', 'user_login', 'user_nicename' ];
		}
		if ( ! empty( $args['include'] ) ) {
			$q['include'] = array_slice( array_map( 'intval', $args['include'] ), 0, 100 );
			$q['number']  = count( $q['include'] );
			$q['offset']  = 0;
		}
		$query    = new WP_User_Query( $q );
		$ids      = array_map( 'intval', (array) $query->get_results() );
		$has_more = empty( $args['include'] ) && count( $ids ) > $per_page;
		$ids      = array_slice( $ids, 0, $per_page );
		cache_users( $ids );
		$users = [];
		foreach ( $ids as $id ) {
			$m = self::member( $id );
			if ( $m ) {
				$users[] = $m;
			}
		}
		return [
			'users'    => $users,
			'has_more' => $has_more,
		];
	}

	/**
	 * Comma-separated first names.
	 *
	 * @param list<int> $ids User IDs.
	 */
	public static function firsts( array $ids ): string {
		$names = [];
		foreach ( $ids as $id ) {
			$u = self::user( $id );
			if ( $u ) {
				$names[] = $u['first'];
			}
		}
		return implode( ', ', $names );
	}

	public static function flush(): void {
		self::$cache = [];
	}
}
