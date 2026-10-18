<?php
/**
 * Activation, upgrades and multisite set-up.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Install {

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	public static function deactivate(): void {
		Cron::clear();
	}

	/**
	 * Install on a site created after network activation.
	 */
	public static function on_initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( PLUGIN_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * @param array<string> $tables Tables to drop.
	 * @return array<string>
	 */
	public static function on_delete_site( array $tables, int $site_id ): array {
		global $wpdb;
		$prefix   = $wpdb->get_blog_prefix( $site_id );
		$tables[] = $prefix . 'masthead_events';
		$tables[] = $prefix . 'masthead_notifications';
		return $tables;
	}

	/**
	 * Run upgrades when the stored DB version is behind.
	 */
	public static function maybe_upgrade(): void {
		$settings = Options::settings();
		if ( $settings['db_version'] >= DB_VERSION && VERSION === $settings['version'] ) {
			return;
		}
		self::install_site();
	}

	public static function install_site(): void {
		self::create_tables();
		Capabilities::add();

		if ( false === get_option( Workflows::OPTION ) ) {
			Workflows::replace_all( Workflows::defaults() );
		}
		Workflows::flush();

		if ( false === get_option( Boards::OPTION ) ) {
			update_option(
				Boards::OPTION,
				[
					'boards'        => [],
					'default_board' => '',
				],
				false
			);
			foreach ( Workflows::all() as $wf ) {
				Boards::create(
					[
						/* translators: %s: workflow name. */
						'name'     => 'articles' === $wf->key ? __( 'Editorial board', 'masthead' ) : sprintf( __( '%s board', 'masthead' ), $wf->name ),
						'workflow' => $wf->key,
					]
				);
			}
		}

		if ( false === get_option( Options::RULES ) ) {
			update_option( Options::RULES, Options::sanitize_rules( [] ), false );
		}

		Options::update_settings(
			[
				'version'    => VERSION,
				'db_version' => DB_VERSION,
			]
		);

		Cron::schedule_digest();
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$events  = $wpdb->prefix . 'masthead_events';
		$notifs  = $wpdb->prefix . 'masthead_notifications';

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				actor_id bigint(20) unsigned DEFAULT NULL,
				verb varchar(40) NOT NULL,
				meta longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY verb_created (verb,created_at)
			) {$charset};
			CREATE TABLE {$notifs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				read_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_read (user_id,read_at),
				KEY event_id (event_id)
			) {$charset};"
		);
	}
}
