<?php
/**
 * Dev-only "View as" role switcher for QA. Only active when WP_DEBUG is on,
 * only for administrators, and it can only remove capabilities.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class ViewAs {

	private const array ROLES = [ 'editor', 'author', 'contributor' ];

	public static function enabled(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && (bool) apply_filters( 'masthead_enable_view_as', true );
	}

	/**
	 * Is the real (unfiltered) user an administrator?
	 */
	public static function available(): bool {
		if ( ! self::enabled() || ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		return in_array( 'administrator', (array) $user->roles, true );
	}

	public static function current_role(): string {
		if ( ! self::available() ) {
			return '';
		}
		$role = (string) get_user_meta( get_current_user_id(), AdminBar::VIEW_AS_META, true );
		return in_array( $role, self::ROLES, true ) ? $role : '';
	}

	/**
	 * `user_has_cap`: intersect the admin's caps with the emulated role's caps.
	 *
	 * @param array<string, bool> $allcaps All caps.
	 * @param array<string>       $caps    Required primitive caps.
	 * @param array<mixed>        $args    Arguments.
	 * @return array<string, bool>
	 */
	public static function filter_caps( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! self::enabled() || get_current_user_id() !== $user->ID || ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return $allcaps;
		}
		$role_name = (string) get_user_meta( $user->ID, AdminBar::VIEW_AS_META, true );
		if ( ! in_array( $role_name, self::ROLES, true ) ) {
			return $allcaps;
		}
		$role = get_role( $role_name );
		if ( ! $role ) {
			return $allcaps;
		}
		$out = [];
		foreach ( $allcaps as $cap => $granted ) {
			$out[ $cap ] = $granted && ! empty( $role->capabilities[ $cap ] );
		}
		return $out;
	}

	public static function handle_switch(): void {
		check_admin_referer( 'masthead_view_as' );
		if ( ! self::available() ) {
			wp_die( esc_html__( 'Not available.', 'masthead' ), 403 );
		}
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '';
		if ( in_array( $role, self::ROLES, true ) ) {
			update_user_meta( get_current_user_id(), AdminBar::VIEW_AS_META, $role );
		} else {
			delete_user_meta( get_current_user_id(), AdminBar::VIEW_AS_META );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=masthead' ) );
		exit;
	}
}
