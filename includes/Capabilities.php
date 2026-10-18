<?php
/**
 * Masthead capabilities.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Capabilities {

	public const string MANAGE_WORKFLOWS = 'masthead_manage_workflows';
	public const string MANAGE_BOARDS    = 'masthead_manage_boards';
	public const string APPROVE          = 'masthead_approve';
	public const string VIEW             = 'masthead_view';

	/**
	 * Role → capabilities.
	 */
	public const array MAP = [
		'administrator' => [ self::MANAGE_WORKFLOWS, self::MANAGE_BOARDS, self::APPROVE, self::VIEW ],
		'editor'        => [ self::MANAGE_BOARDS, self::APPROVE, self::VIEW ],
		'author'        => [ self::VIEW ],
		'contributor'   => [ self::VIEW ],
	];

	public static function add(): void {
		foreach ( self::MAP as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function remove(): void {
		$all = [ self::MANAGE_WORKFLOWS, self::MANAGE_BOARDS, self::APPROVE, self::VIEW ];
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $all as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * IDs of every user who holds a capability. IDs only, so this stays cheap on large sites.
	 *
	 * @return list<int>
	 */
	public static function users_with( string $cap ): array {
		$key = 'users_with_' . $cap;
		$ids = wp_cache_get( $key, 'masthead' );
		if ( false === $ids ) {
			$ids = array_map(
				'intval',
				get_users(
					[
						'capability'  => $cap,
						'fields'      => 'ID',
						'number'      => -1,
						'count_total' => false,
					]
				)
			);
			wp_cache_set( $key, $ids, 'masthead', MINUTE_IN_SECONDS );
		}
		return $ids;
	}
}
