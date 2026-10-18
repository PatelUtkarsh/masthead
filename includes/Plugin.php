<?php
/**
 * Wires every hook.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ Install::class, 'maybe_upgrade' ], 1 );
		add_action( 'init', [ self::class, 'init' ], 5 );
		add_action( 'rest_api_init', [ self::class, 'rest' ] );

		// Status sync, enforcement, trash.
		add_action( 'transition_post_status', [ Sync::class, 'on_transition' ], 10, 3 );
		add_filter( 'wp_insert_post_data', [ Guard::class, 'insert_post_data' ], 20, 4 );
		add_filter( 'wp_untrash_post_status', [ Sync::class, 'untrash_status' ], 10, 3 );
		add_filter( 'update_post_term_count_statuses', [ StageTaxonomy::class, 'counted_statuses' ], 10, 2 );
		add_action( 'save_post', [ Cards::class, 'on_post_change' ] );
		add_action( 'deleted_post', [ Cards::class, 'on_post_change' ] );
		add_action( 'deleted_post', [ Activity::class, 'delete_for_post' ] );

		// Notes stay private.
		add_action( 'pre_get_comments', [ Notes::class, 'exclude_from_queries' ] );
		add_filter( 'comment_feed_where', [ Notes::class, 'exclude_from_feed' ] );
		add_filter( 'pre_wp_update_comment_count_now', [ Notes::class, 'update_comment_count' ], 10, 3 );
		add_filter( 'wp_count_comments', [ Notes::class, 'count_comments' ], 10, 2 );

		// Notifications.
		add_filter( 'heartbeat_received', [ Notifications::class, 'heartbeat' ], 10, 2 );
		add_action( 'admin_bar_menu', [ AdminBar::class, 'nodes' ], 70 );
		add_action( 'admin_enqueue_scripts', [ AdminBar::class, 'enqueue' ] );
		add_action( 'wp_enqueue_scripts', [ AdminBar::class, 'enqueue' ] );
		Cron::register();

		// Dev-only role switcher.
		if ( ViewAs::enabled() ) {
			add_filter( 'user_has_cap', [ ViewAs::class, 'filter_caps' ], 999, 4 );
			add_action( 'admin_post_masthead_view_as', [ ViewAs::class, 'handle_switch' ] );
		}

		// Multisite.
		add_action( 'wp_initialize_site', [ Install::class, 'on_initialize_site' ], 200 );
		add_filter( 'wpmu_drop_tables', [ Install::class, 'on_delete_site' ], 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ Admin::class, 'menu' ] );
			add_action( 'admin_enqueue_scripts', [ Admin::class, 'enqueue' ] );
			add_filter( 'admin_body_class', [ Admin::class, 'admin_body_class' ] );
			add_action( 'admin_notices', [ Guard::class, 'admin_notices' ] );
			add_action( 'wp_dashboard_setup', [ Dashboard::class, 'register' ] );
			add_action( 'admin_enqueue_scripts', [ Dashboard::class, 'enqueue' ] );
		}
		add_action( 'enqueue_block_editor_assets', [ Editor::class, 'enqueue' ] );
	}

	public static function init(): void {
		load_plugin_textdomain( 'masthead', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
		Workflows::register_post_types();
		StageTaxonomy::register();
		Meta::register();
		Guard::register();
		if ( is_admin() ) {
			ListTable::register();
		}
		if ( get_option( 'masthead_flush_rewrite' ) ) {
			delete_option( 'masthead_flush_rewrite' );
			add_action( 'wp_loaded', 'flush_rewrite_rules' );
		}
	}

	public static function rest(): void {
		( new Rest\BoardsController() )->register_routes();
		( new Rest\WorkflowsController() )->register_routes();
		( new Rest\PostsController() )->register_routes();
		( new Rest\CalendarController() )->register_routes();
		( new Rest\NotificationsController() )->register_routes();
		( new Rest\UsersController() )->register_routes();
	}
}
