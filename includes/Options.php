<?php
/**
 * Site-wide rules and plugin settings.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Options {

	public const string RULES    = 'masthead_rules';
	public const string SETTINGS = 'masthead_settings';

	/**
	 * @return array{enforce_approvals: bool, auto_schedule: bool, digest: bool, digest_time: string}
	 */
	public static function rules(): array {
		$raw = get_option( self::RULES, [] );
		$raw = is_array( $raw ) ? $raw : [];
		return self::sanitize_rules( $raw );
	}

	/**
	 * @param array<string, mixed> $changes Partial rules.
	 * @return array{enforce_approvals: bool, auto_schedule: bool, digest: bool, digest_time: string}
	 */
	public static function update_rules( array $changes ): array {
		$rules = self::sanitize_rules( [ ...self::rules(), ...$changes ] );
		update_option( self::RULES, $rules, false );
		Cron::schedule_digest();
		return $rules;
	}

	/**
	 * @param array<string, mixed> $raw Raw rules.
	 * @return array{enforce_approvals: bool, auto_schedule: bool, digest: bool, digest_time: string}
	 */
	public static function sanitize_rules( array $raw ): array {
		$time = (string) ( $raw['digest_time'] ?? '08:00' );
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = '08:00';
		}
		return [
			'enforce_approvals' => (bool) ( $raw['enforce_approvals'] ?? true ),
			'auto_schedule'     => (bool) ( $raw['auto_schedule'] ?? true ),
			'digest'            => (bool) ( $raw['digest'] ?? false ),
			'digest_time'       => $time,
		];
	}

	/**
	 * @return array{version: string, setup_complete: bool, db_version: int}
	 */
	public static function settings(): array {
		$raw = get_option( self::SETTINGS, [] );
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'version'        => (string) ( $raw['version'] ?? '' ),
			'setup_complete' => (bool) ( $raw['setup_complete'] ?? false ),
			'db_version'     => (int) ( $raw['db_version'] ?? 0 ),
		];
	}

	/**
	 * @param array<string, mixed> $changes Partial settings.
	 */
	public static function update_settings( array $changes ): void {
		update_option( self::SETTINGS, [ ...self::settings(), ...$changes ], true );
	}
}
