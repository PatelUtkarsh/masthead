<?php
/**
 * PHPUnit bootstrap.
 *
 * @package Masthead
 */

declare(strict_types=1);

$masthead_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $masthead_tests_dir ) {
	$masthead_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$masthead_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$masthead_tests_dir}/includes/functions.php. Run the tests inside wp-env: npm run test:php" . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once "{$masthead_tests_dir}/includes/functions.php";

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/masthead.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		Masthead\Install::activate();
	}
);

require "{$masthead_tests_dir}/includes/bootstrap.php";
require_once __DIR__ . '/TestCase.php';
