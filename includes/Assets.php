<?php
/**
 * Registers built scripts and styles.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Assets {

	/**
	 * Enqueue a build entry point with its generated dependencies.
	 *
	 * @param list<string> $style_deps Style dependencies.
	 */
	public static function enqueue( string $entry, array $style_deps = [] ): bool {
		$asset_file = PLUGIN_DIR . "/build/{$entry}.asset.php";
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}
		$asset  = require $asset_file;
		$handle = "masthead-{$entry}";
		wp_enqueue_script(
			$handle,
			plugins_url( "build/{$entry}.js", PLUGIN_FILE ),
			$asset['dependencies'] ?? [],
			$asset['version'] ?? VERSION,
			[ 'in_footer' => true ]
		);
		wp_set_script_translations( $handle, 'masthead', PLUGIN_DIR . '/languages' );
		if ( is_readable( PLUGIN_DIR . "/build/{$entry}.css" ) ) {
			wp_enqueue_style( $handle, plugins_url( "build/{$entry}.css", PLUGIN_FILE ), $style_deps, $asset['version'] ?? VERSION );
		}
		return true;
	}
}
