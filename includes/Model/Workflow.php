<?php
/**
 * A workflow: an ordered list of stages for one post type.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Model;

final readonly class Workflow {

	/**
	 * @param string                                    $key       Workflow key (option array key).
	 * @param string                                    $name      Display name, e.g. "Articles".
	 * @param string                                    $post_type Post type slug.
	 * @param string                                    $color     Hex colour.
	 * @param bool                                      $gate      Checklist gate for scheduling and publishing.
	 * @param list<array{id: string, label: string}>    $checklist Checklist items.
	 * @param list<Stage>                               $stages    Ordered stages.
	 * @param string                                    $singular  Singular label of the post type.
	 * @param string                                    $plural    Plural label of the post type.
	 * @param bool                                      $registers Masthead registers the post type itself.
	 */
	public function __construct(
		public string $key,
		public string $name,
		public string $post_type,
		public string $color,
		public bool $gate,
		public array $checklist,
		public array $stages,
		public string $singular = '',
		public string $plural = '',
		public bool $registers = false,
	) {}

	/**
	 * @param string               $key  Workflow key.
	 * @param array<string, mixed> $data Raw workflow data.
	 */
	public static function from_array( string $key, array $data ): self {
		$stages = [];
		$seen   = [];
		foreach ( (array) ( $data['stages'] ?? [] ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$stage = Stage::from_array( $raw );
			$id    = $stage->id;
			$n     = 2;
			while ( isset( $seen[ $id ] ) ) {
				$id = $stage->id . '-' . ( $n++ );
			}
			if ( $id !== $stage->id ) {
				$stage = Stage::from_array(
					[
						...$stage->to_array(),
						'id' => $id,
					]
				);
			}
			$seen[ $id ] = true;
			$stages[]    = $stage;
		}

		$checklist = [];
		$seen      = [];
		foreach ( (array) ( $data['checklist'] ?? [] ) as $item ) {
			if ( is_string( $item ) ) {
				$item = [ 'label' => $item ];
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'c_' . strtolower( wp_generate_password( 8, false ) );
			}
			$seen[ $id ] = true;
			$checklist[] = [
				'id'    => $id,
				'label' => $label,
			];
		}

		$name     = sanitize_text_field( (string) ( $data['name'] ?? $key ) );
		$singular = sanitize_text_field( (string) ( $data['singular'] ?? '' ) );
		$plural   = sanitize_text_field( (string) ( $data['plural'] ?? '' ) );
		$color    = sanitize_hex_color( (string) ( $data['color'] ?? '' ) );

		return new self(
			key: sanitize_key( $key ),
			name: '' !== $name ? $name : $key,
			post_type: sanitize_key( (string) ( $data['post_type'] ?? 'post' ) ),
			color: $color ? $color : '#3858e9',
			gate: (bool) ( $data['gate'] ?? false ),
			checklist: $checklist,
			stages: $stages,
			singular: $singular,
			plural: '' !== $plural ? $plural : $name,
			registers: (bool) ( $data['registers'] ?? false ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'name'      => $this->name,
			'post_type' => $this->post_type,
			'color'     => $this->color,
			'gate'      => $this->gate,
			'checklist' => $this->checklist,
			'stages'    => array_map( static fn( Stage $s ): array => $s->to_array(), $this->stages ),
			'singular'  => $this->singular,
			'plural'    => $this->plural,
			'registers' => $this->registers,
		];
	}

	public function stage( string $id ): ?Stage {
		foreach ( $this->stages as $stage ) {
			if ( $stage->id === $id ) {
				return $stage;
			}
		}
		return null;
	}

	/**
	 * Position of a stage, or -1 when it does not exist.
	 */
	public function index_of( string $id ): int {
		foreach ( $this->stages as $i => $stage ) {
			if ( $stage->id === $id ) {
				return $i;
			}
		}
		return -1;
	}

	public function first_stage(): ?Stage {
		return $this->stages[0] ?? null;
	}

	public function first_stage_for( WpStatus $status ): ?Stage {
		foreach ( $this->stages as $stage ) {
			if ( $stage->wp_status === $status ) {
				return $stage;
			}
		}
		return null;
	}

	public function next_stage( string $id ): ?Stage {
		$i = $this->index_of( $id );
		return $i < 0 ? null : ( $this->stages[ $i + 1 ] ?? null );
	}

	/**
	 * @return list<string>
	 */
	public function checklist_ids(): array {
		return array_column( $this->checklist, 'id' );
	}

	/**
	 * Return a copy with other stages.
	 *
	 * @param list<Stage> $stages New stages.
	 */
	public function with_stages( array $stages ): self {
		return new self( $this->key, $this->name, $this->post_type, $this->color, $this->gate, $this->checklist, $stages, $this->singular, $this->plural, $this->registers );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_rest(): array {
		$counts = \Masthead\Cards::stage_counts( $this );
		return [
			'key'    => $this->key,
			...$this->to_array(),
			'stages' => array_map(
				static fn( Stage $s ): array => [
					...$s->to_array(),
					'count' => $counts[ $s->id ] ?? 0,
				],
				$this->stages
			),
		];
	}
}
