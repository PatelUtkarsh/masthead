<?php
/**
 * Plugin Name:       Masthead
 * Description:       Kanban publishing workflows for wp-admin. Every card is a post; every stage maps to a core post status.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.4
 * Author:            Masthead
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       masthead
 * Domain Path:       /languages
 * Network:           false
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '1.0.0';
const DB_VERSION  = 1;
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$file     = PLUGIN_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, [ Install::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Install::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );
