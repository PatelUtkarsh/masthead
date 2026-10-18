<?php
/**
 * Post meta keys and accessors.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Meta {

	/**
	 * REST field that exposes the stage (stored as a masthead_stage term).
	 */
	public const string STAGE     = 'masthead_stage';
	public const string CHECKLIST = '_masthead_checklist';
	public const string DUE       = '_masthead_due';
	public const string REV       = '_masthead_rev';

	public static function register(): void {
		foreach ( Workflows::post_types() as $post_type ) {
			self::register_for( $post_type );
		}
	}

	public static function register_for( string $post_type ): void {
		$can_edit  = static fn( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id );
		$read_only = static fn(): bool => false;

		register_rest_field(
			$post_type,
			self::STAGE,
			[
				'get_callback' => static fn( array $post ): string => self::stage( (int) $post['id'] ),
				'schema'       => [
					'description' => __( 'Masthead stage ID.', 'masthead' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			]
		);
		register_post_meta(
			$post_type,
			self::REV,
			[
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => $read_only,
			]
		);
		register_post_meta(
			$post_type,
			self::CHECKLIST,
			[
				'type'              => 'object',
				'single'            => true,
				'default'           => [],
				'show_in_rest'      => [
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => [ 'type' => 'boolean' ],
					],
				],
				'sanitize_callback' => [ self::class, 'sanitize_checklist' ],
				'auth_callback'     => $can_edit,
			]
		);
		register_post_meta(
			$post_type,
			self::DUE,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => [ self::class, 'sanitize_due' ],
				'auth_callback'     => $can_edit,
			]
		);
	}

	/**
	 * @return array<string, true>
	 */
	public static function sanitize_checklist( mixed $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $id => $done ) {
			if ( $done ) {
				$out[ sanitize_key( (string) $id ) ] = true;
			}
		}
		return $out;
	}

	public static function sanitize_due( mixed $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	public static function stage( int $post_id ): string {
		$type = get_post_type( $post_id );
		$wf   = $type ? Workflows::for_post_type( $type ) : null;
		return $wf ? StageTaxonomy::stage_of( $post_id, $wf ) : '';
	}

	/**
	 * @return array<string, true>
	 */
	public static function checklist( int $post_id ): array {
		return self::sanitize_checklist( get_post_meta( $post_id, self::CHECKLIST, true ) );
	}

	public static function due( int $post_id ): string {
		return self::sanitize_due( get_post_meta( $post_id, self::DUE, true ) );
	}

	public static function rev( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::REV, true );
	}

	public static function bump_rev( int $post_id ): int {
		$rev = self::rev( $post_id ) + 1;
		update_post_meta( $post_id, self::REV, $rev );
		return $rev;
	}

	public static function set_stage( int $post_id, string $stage ): void {
		$type = get_post_type( $post_id );
		$wf   = $type ? Workflows::for_post_type( $type ) : null;
		if ( $wf ) {
			StageTaxonomy::set( $post_id, $wf, $stage );
		}
	}

	/**
	 * @param array<string, true> $checklist Ticked items.
	 */
	public static function set_checklist( int $post_id, array $checklist ): void {
		update_post_meta( $post_id, self::CHECKLIST, self::sanitize_checklist( $checklist ) );
	}

	public static function set_due( int $post_id, string $due ): void {
		$due = self::sanitize_due( $due );
		if ( '' === $due ) {
			delete_post_meta( $post_id, self::DUE );
			return;
		}
		update_post_meta( $post_id, self::DUE, $due );
	}

	/**
	 * Checklist progress for a post in a workflow.
	 *
	 * @return array{done: int, total: int}
	 */
	public static function progress( int $post_id, \Masthead\Model\Workflow $wf ): array {
		$ticked = self::checklist( $post_id );
		$done   = 0;
		foreach ( $wf->checklist_ids() as $id ) {
			if ( isset( $ticked[ $id ] ) ) {
				++$done;
			}
		}
		return [
			'done'  => $done,
			'total' => count( $wf->checklist ),
		];
	}
}
