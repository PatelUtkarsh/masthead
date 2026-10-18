<?php
/**
 * Boards: saved views over a workflow.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Boards {

	public const string OPTION = 'masthead_boards';

	/**
	 * @return list<array{id: string, name: string, workflow: string, filters: array{category?: int, author?: int}}>
	 */
	public static function all(): array {
		$raw    = self::raw();
		$boards = [];
		foreach ( $raw['boards'] as $b ) {
			if ( is_array( $b ) && ! empty( $b['id'] ) ) {
				$boards[] = self::sanitize( $b );
			}
		}
		return $boards;
	}

	/**
	 * @return array{id: string, name: string, workflow: string, filters: array{category?: int, author?: int}}|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $b ) {
			if ( $b['id'] === $id ) {
				return $b;
			}
		}
		return null;
	}

	public static function default_id(): string {
		$raw = self::raw();
		$id  = (string) $raw['default_board'];
		if ( null !== self::get( $id ) ) {
			return $id;
		}
		$all = self::all();
		return $all[0]['id'] ?? '';
	}

	/**
	 * Default board of a workflow: the site default if it uses the workflow, else the first board for it.
	 */
	public static function default_for_workflow( string $workflow ): ?string {
		$default = self::get( self::default_id() );
		if ( $default && $default['workflow'] === $workflow ) {
			return $default['id'];
		}
		foreach ( self::all() as $b ) {
			if ( $b['workflow'] === $workflow && empty( $b['filters'] ) ) {
				return $b['id'];
			}
		}
		foreach ( self::all() as $b ) {
			if ( $b['workflow'] === $workflow ) {
				return $b['id'];
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data Board data.
	 * @return array{id: string, name: string, workflow: string, filters: array{category?: int, author?: int}}
	 */
	public static function create( array $data, bool $make_default = false ): array {
		$board           = self::sanitize(
			[
				...$data,
				'id' => 'b_' . strtolower( wp_generate_password( 8, false ) ),
			]
		);
		$raw             = self::raw();
		$raw['boards'][] = $board;
		if ( $make_default || '' === $raw['default_board'] ) {
			$raw['default_board'] = $board['id'];
		}
		self::write( $raw );
		return $board;
	}

	/**
	 * @param array<string, mixed> $data Board data.
	 * @return array{id: string, name: string, workflow: string, filters: array{category?: int, author?: int}}|null
	 */
	public static function update( string $id, array $data, ?bool $make_default = null ): ?array {
		$raw   = self::raw();
		$found = null;
		foreach ( $raw['boards'] as $i => $b ) {
			if ( is_array( $b ) && ( $b['id'] ?? '' ) === $id ) {
				$found               = self::sanitize(
					[
						...$b,
						...$data,
						'id' => $id,
					]
				);
				$raw['boards'][ $i ] = $found;
			}
		}
		if ( null === $found ) {
			return null;
		}
		if ( true === $make_default ) {
			$raw['default_board'] = $id;
		}
		self::write( $raw );
		return $found;
	}

	public static function delete( string $id ): bool {
		$raw    = self::raw();
		$before = count( $raw['boards'] );
		if ( $before <= 1 ) {
			return false;
		}
		$raw['boards'] = array_values( array_filter( $raw['boards'], static fn( $b ): bool => is_array( $b ) && ( $b['id'] ?? '' ) !== $id ) );
		if ( count( $raw['boards'] ) === $before ) {
			return false;
		}
		if ( $raw['default_board'] === $id ) {
			$raw['default_board'] = $raw['boards'][0]['id'] ?? '';
		}
		self::write( $raw );
		return true;
	}

	public static function delete_for_workflow( string $workflow ): void {
		$raw           = self::raw();
		$raw['boards'] = array_values( array_filter( $raw['boards'], static fn( $b ): bool => is_array( $b ) && ( $b['workflow'] ?? '' ) !== $workflow ) );
		if ( null === self::find_in( $raw['boards'], $raw['default_board'] ) ) {
			$raw['default_board'] = $raw['boards'][0]['id'] ?? '';
		}
		self::write( $raw );
	}

	/**
	 * Does a post match a board's filters?
	 *
	 * @param array{workflow: string, filters: array{category?: int, author?: int}} $board Board.
	 */
	public static function matches( \WP_Post $post, array $board ): bool {
		$wf = Workflows::get( $board['workflow'] );
		if ( ! $wf || $wf->post_type !== $post->post_type ) {
			return false;
		}
		if ( ! empty( $board['filters']['author'] ) && (int) $post->post_author !== $board['filters']['author'] ) {
			return false;
		}
		if ( ! empty( $board['filters']['category'] ) && ! has_term( $board['filters']['category'], 'category', $post ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Boards a post appears on.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	public static function for_post( \WP_Post $post ): array {
		$out = [];
		foreach ( self::all() as $b ) {
			if ( self::matches( $post, $b ) ) {
				$out[] = [
					'id'   => $b['id'],
					'name' => $b['name'],
				];
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $b Raw board.
	 * @return array{id: string, name: string, workflow: string, filters: array{category?: int, author?: int}}
	 */
	private static function sanitize( array $b ): array {
		$filters = [];
		$raw_f   = is_array( $b['filters'] ?? null ) ? $b['filters'] : [];
		if ( ! empty( $raw_f['category'] ) ) {
			$filters['category'] = absint( $raw_f['category'] );
		}
		if ( ! empty( $raw_f['author'] ) ) {
			$filters['author'] = absint( $raw_f['author'] );
		}
		$name = sanitize_text_field( (string) ( $b['name'] ?? '' ) );
		return [
			'id'       => sanitize_key( (string) $b['id'] ),
			'name'     => '' !== $name ? $name : __( 'Untitled board', 'masthead' ),
			'workflow' => sanitize_key( (string) ( $b['workflow'] ?? '' ) ),
			'filters'  => $filters,
		];
	}

	/**
	 * @param list<mixed> $boards Boards.
	 */
	private static function find_in( array $boards, string $id ): ?array {
		foreach ( $boards as $b ) {
			if ( is_array( $b ) && ( $b['id'] ?? '' ) === $id ) {
				return $b;
			}
		}
		return null;
	}

	/**
	 * @return array{boards: list<mixed>, default_board: string}
	 */
	private static function raw(): array {
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'boards'        => array_values( is_array( $raw['boards'] ?? null ) ? $raw['boards'] : [] ),
			'default_board' => (string) ( $raw['default_board'] ?? '' ),
		];
	}

	/**
	 * @param array{boards: list<mixed>, default_board: string} $raw Data.
	 */
	private static function write( array $raw ): void {
		update_option( self::OPTION, $raw, false );
	}
}
