<?php
/**
 * Core post statuses a stage can map to.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Model;

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts -- enums have $this.
enum WpStatus: string {
	case Draft   = 'draft';
	case Pending = 'pending';
	case Future  = 'future';
	case Publish = 'publish';

	public function label(): string {
		return match ( $this ) {
			self::Draft   => __( 'Draft', 'masthead' ),
			self::Pending => __( 'Pending review', 'masthead' ),
			self::Future  => __( 'Scheduled', 'masthead' ),
			self::Publish => __( 'Published', 'masthead' ),
		};
	}

	/**
	 * Scheduled or published: the statuses gated by publish capability and checklist.
	 */
	public function is_live(): bool {
		return self::Future === $this || self::Publish === $this;
	}

	/**
	 * Map any core status to a stage status. Private posts count as published.
	 */
	public static function from_post_status( string $status ): ?self {
		return match ( $status ) {
			'private' => self::Publish,
			'auto-draft', 'inherit' => self::Draft,
			default   => self::tryFrom( $status ),
		};
	}
}
