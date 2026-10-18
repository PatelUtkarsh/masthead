<?php
/**
 * A single workflow stage.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Model;

final readonly class Stage {

	public const array PALETTE = [ '#8c8f94', '#d97706', '#3858e9', '#0d9488', '#7c3aed', '#16a34a', '#db2777', '#0284c7' ];

	public function __construct(
		public string $id,
		public string $name,
		public string $color,
		public WpStatus $wp_status,
		public int $limit = 0,
		public LimitMode $limit_mode = LimitMode::Warn,
		public NotifyMode $notify = NotifyMode::None,
		public ?int $reviewer = null,
		public bool $required = false,
		public bool $approval = false,
	) {}

	/**
	 * Build a stage from stored or user-provided data. Invalid values fall back to safe defaults.
	 *
	 * @param array<string, mixed> $data Raw stage data.
	 */
	public static function from_array( array $data ): self {
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$id   = sanitize_key( (string) ( $data['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = sanitize_key( sanitize_title( $name ) );
		}
		if ( '' === $id ) {
			$id = 's_' . strtolower( wp_generate_password( 8, false ) );
		}
		$color    = sanitize_hex_color( (string) ( $data['color'] ?? '' ) );
		$reviewer = isset( $data['reviewer'] ) && '' !== $data['reviewer'] && null !== $data['reviewer'] ? absint( $data['reviewer'] ) : null;

		return new self(
			id: $id,
			name: '' !== $name ? $name : $id,
			color: $color ? $color : self::PALETTE[0],
			wp_status: WpStatus::tryFrom( (string) ( $data['wp_status'] ?? '' ) ) ?? WpStatus::Draft,
			limit: max( 0, (int) ( $data['limit'] ?? 0 ) ),
			limit_mode: LimitMode::tryFrom( (string) ( $data['limit_mode'] ?? '' ) ) ?? LimitMode::Warn,
			notify: NotifyMode::tryFrom( (string) ( $data['notify'] ?? '' ) ) ?? NotifyMode::None,
			reviewer: $reviewer ? $reviewer : null,
			required: (bool) ( $data['required'] ?? false ),
			approval: (bool) ( $data['approval'] ?? false ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'         => $this->id,
			'name'       => $this->name,
			'color'      => $this->color,
			'wp_status'  => $this->wp_status->value,
			'limit'      => $this->limit,
			'limit_mode' => $this->limit_mode->value,
			'notify'     => $this->notify->value,
			'reviewer'   => $this->reviewer,
			'required'   => $this->required,
			'approval'   => $this->approval,
		];
	}
}
