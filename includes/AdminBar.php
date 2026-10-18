<?php
/**
 * Admin bar: notifications bell and (dev builds only) the "View as" switcher.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use WP_Admin_Bar;

final class AdminBar {

	public const string VIEW_AS_META = 'masthead_view_as';

	public static function nodes( WP_Admin_Bar $bar ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( current_user_can( Capabilities::VIEW ) ) {
			$unread = Notifications::unread_count( get_current_user_id() );
			$bar->add_node(
				[
					'id'     => 'masthead-notifications',
					'parent' => 'top-secondary',
					'title'  => sprintf(
						'<span class="ab-icon dashicons dashicons-bell" aria-hidden="true"></span><span class="masthead-ab-count%1$s" data-count="%2$d">%3$s</span><span class="screen-reader-text">%4$s</span>',
						$unread ? '' : ' is-empty',
						$unread,
						$unread ? esc_html( (string) $unread ) : '',
						/* translators: %d: unread notifications. */
						esc_html( sprintf( _n( '%d unread notification', '%d unread notifications', $unread, 'masthead' ), $unread ) )
					),
					'href'   => admin_url( 'admin.php?page=masthead' ),
					'meta'   => [
						'class' => 'masthead-ab-bell',
						'title' => __( 'Notifications', 'masthead' ),
						'html'  => '<div id="masthead-notifications-root"></div>',
					],
				]
			);
		}

		if ( ViewAs::available() ) {
			$current = ViewAs::current_role();
			$bar->add_node(
				[
					'id'     => 'masthead-view-as',
					'parent' => 'top-secondary',
					/* translators: %s: role name. */
					'title'  => esc_html( sprintf( __( 'View as: %s', 'masthead' ), $current ? translate_user_role( wp_roles()->role_names[ $current ] ?? $current ) : __( 'Yourself', 'masthead' ) ) ),
				]
			);
			foreach ( [
				''            => __( 'Yourself', 'masthead' ),
				'editor'      => 'Editor',
				'author'      => 'Author',
				'contributor' => 'Contributor',
			] as $role => $label ) {
				$bar->add_node(
					[
						'id'     => 'masthead-view-as-' . ( '' === $role ? 'self' : $role ),
						'parent' => 'masthead-view-as',
						'title'  => esc_html( '' === $role ? $label : translate_user_role( $label ) ) . ( $current === $role ? ' ✓' : '' ),
						'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=masthead_view_as&role=' . $role ), 'masthead_view_as' ),
					]
				);
			}
		}
	}

	public static function enqueue(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		if ( Assets::enqueue( 'admin-bar' ) ) {
			wp_enqueue_script( 'heartbeat' );
			wp_add_inline_script(
				'masthead-admin-bar',
				'window.mastheadAdminBar = ' . wp_json_encode(
					[
						'boardUrl' => admin_url( 'admin.php?page=masthead' ),
						'user'     => People::user( get_current_user_id() ),
					]
				) . ';',
				'before'
			);
		}
	}
}
