<?php
/**
 * The rule engine. Every stage change goes through here.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\LimitMode;
use Masthead\Model\Stage;
use Masthead\Model\Workflow;
use Masthead\Model\WpStatus;
use WP_Error;
use WP_Post;
use WP_User;

final class Rules {

	public const int DEFAULT_PUBLISH_HOUR = 9;

	/**
	 * Check if a post may move to a stage. Runs the checks in spec order and stops at the first failure.
	 *
	 * @param WP_Post             $post         Post.
	 * @param string              $target_stage Target stage ID.
	 * @param WP_User|int|null    $actor        Acting user; defaults to the current user.
	 * @param array<string, mixed> $context     Optional overrides: `checklist` (array<string,bool>), `from` (stage ID).
	 */
	public static function validate( WP_Post $post, string $target_stage, WP_User|int|null $actor = null, array $context = [] ): true|WP_Error {
		$actor = self::actor( $actor );
		$wf    = Workflows::for_post( $post );
		if ( ! $wf ) {
			return new WP_Error( 'masthead_no_workflow', __( 'This post type has no workflow.', 'masthead' ), [ 'status' => 400 ] );
		}
		$target = $wf->stage( $target_stage );
		if ( ! $target ) {
			return new WP_Error( 'masthead_invalid_stage', __( 'That stage does not exist.', 'masthead' ), [ 'status' => 400 ] );
		}
		$current = isset( $context['from'] ) ? ( $wf->stage( (string) $context['from'] ) ?? Sync::current_stage( $post, $wf ) ) : Sync::current_stage( $post, $wf );

		// 1. No-op.
		if ( $current && $current->id === $target->id ) {
			return true;
		}

		if ( $post->ID > 0 && ! user_can( $actor, 'edit_post', $post->ID ) ) {
			return new WP_Error( 'masthead_cannot_edit', __( 'You can’t edit this post.', 'masthead' ), [ 'status' => 403 ] );
		}

		$checks = [
			static fn() => self::check_approval( $wf, $current, $actor ),
			static fn() => self::check_required( $wf, $current, $target ),
			static fn() => self::check_limit( $wf, $target ),
			static fn() => self::check_publish( $post, $target, $actor ),
			static fn() => self::check_checklist( $post, $wf, $target, $context['checklist'] ?? null ),
		];
		foreach ( $checks as $check ) {
			$error = $check();
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		/**
		 * Filters the result of the rule engine.
		 *
		 * @param true|WP_Error $result Result.
		 * @param WP_Post       $post   Post.
		 * @param Stage         $target Target stage.
		 * @param WP_User       $actor  Acting user.
		 */
		return apply_filters( 'masthead_validate_move', true, $post, $target, $actor );
	}

	/**
	 * 2. Approval lock.
	 */
	private static function check_approval( Workflow $wf, ?Stage $current, WP_User $actor ): ?WP_Error {
		if ( ! $current || ! $current->approval || ! Options::rules()['enforce_approvals'] ) {
			return null;
		}
		if ( user_can( $actor, Capabilities::APPROVE ) ) {
			return null;
		}
		return new WP_Error(
			'masthead_needs_approval',
			/* translators: %s: stage name. */
			sprintf( __( 'Needs approval: only Editors can move posts out of %s', 'masthead' ), $current->name ),
			[ 'status' => 422 ]
		);
	}

	/**
	 * 3. Required stage. Moving left is always allowed.
	 */
	private static function check_required( Workflow $wf, ?Stage $current, Stage $target ): ?WP_Error {
		$i = $current ? $wf->index_of( $current->id ) : -1;
		$j = $wf->index_of( $target->id );
		if ( $j <= $i ) {
			return null;
		}
		for ( $k = max( 0, $i + 1 ); $k < $j; $k++ ) {
			if ( $wf->stages[ $k ]->required ) {
				return new WP_Error(
					'masthead_skip_required',
					/* translators: %s: stage name. */
					sprintf( __( 'Can’t skip %s. Move it there first', 'masthead' ), $wf->stages[ $k ]->name ),
					[
						'status' => 422,
						'stage'  => $wf->stages[ $k ]->id,
					]
				);
			}
		}
		return null;
	}

	/**
	 * 4. WIP limit (block mode only).
	 */
	private static function check_limit( Workflow $wf, Stage $target ): ?WP_Error {
		if ( $target->limit <= 0 || LimitMode::Block !== $target->limit_mode ) {
			return null;
		}
		$n = Cards::count_in_stage( $wf, $target->id, false );
		if ( $n < $target->limit ) {
			return null;
		}
		return new WP_Error(
			'masthead_stage_full',
			/* translators: 1: stage name, 2: posts in stage, 3: limit. */
			sprintf( __( '%1$s is full (%2$d/%3$d). Move something on first', 'masthead' ), $target->name, $n, $target->limit ),
			[ 'status' => 422 ]
		);
	}

	/**
	 * 5. Publish capability.
	 */
	private static function check_publish( WP_Post $post, Stage $target, WP_User $actor ): ?WP_Error {
		if ( ! $target->wp_status->is_live() ) {
			return null;
		}
		$pto = get_post_type_object( $post->post_type );
		$cap = $pto ? $pto->cap->publish_posts : 'publish_posts';
		if ( user_can( $actor, $cap ) ) {
			return null;
		}
		return new WP_Error( 'masthead_cannot_publish', __( 'Contributors can’t schedule or publish', 'masthead' ), [ 'status' => 422 ] );
	}

	/**
	 * 6. Checklist gate.
	 *
	 * @param array<string, mixed>|null $checklist Override of the stored checklist.
	 */
	private static function check_checklist( WP_Post $post, Workflow $wf, Stage $target, ?array $checklist ): ?WP_Error {
		if ( ! $wf->gate || ! $target->wp_status->is_live() ) {
			return null;
		}
		$open = self::open_items( $post, $wf, $checklist );
		if ( 0 === $open ) {
			return null;
		}
		return new WP_Error(
			'masthead_checklist_open',
			sprintf(
				/* translators: 1: number of open items, 2: post title. */
				_n( 'Blocked: %1$d checklist item open on “%2$s”', 'Blocked: %1$d checklist items open on “%2$s”', $open, 'masthead' ),
				$open,
				self::short_title( $post )
			),
			[
				'status' => 422,
				'open'   => $open,
			]
		);
	}

	/**
	 * @param array<string, mixed>|null $checklist Override of the stored checklist.
	 */
	public static function open_items( WP_Post $post, Workflow $wf, ?array $checklist = null ): int {
		$ticked = null !== $checklist ? Meta::sanitize_checklist( $checklist ) : Meta::checklist( $post->ID );
		$open   = 0;
		foreach ( $wf->checklist_ids() as $id ) {
			if ( ! isset( $ticked[ $id ] ) ) {
				++$open;
			}
		}
		return $open;
	}

	/**
	 * Pre-publish checks for the editor panel. Unlike validate(), runs every check.
	 *
	 * @param array<string, mixed>|null $checklist Override of the stored checklist.
	 * @return array{target: ?array<string, mixed>, ok: bool, checks: list<array{key: string, label: string, ok: bool, message: string}>}
	 */
	public static function checks( WP_Post $post, ?string $target_stage = null, WP_User|int|null $actor = null, ?array $checklist = null ): array {
		$actor = self::actor( $actor );
		$wf    = Workflows::for_post( $post );
		if ( ! $wf ) {
			return [
				'target' => null,
				'ok'     => true,
				'checks' => [],
			];
		}
		$target   = $target_stage ? $wf->stage( $target_stage ) : null;
		$target ??= $wf->first_stage_for( WpStatus::Publish ) ?? $wf->stages[ count( $wf->stages ) - 1 ];
		$current = Sync::current_stage( $post, $wf );
		$same    = $current && $current->id === $target->id;

		$open     = self::open_items( $post, $wf, $checklist );
		$gate_err = $same ? null : self::check_checklist( $post, $wf, $target, $checklist );
		$req_err  = $same ? null : self::check_required( $wf, $current, $target );
		$appr_err = $same ? null : self::check_approval( $wf, $current, $actor );
		$pub_err  = $same ? null : self::check_publish( $post, $target, $actor );
		$total    = count( $wf->checklist );

		$checks = [
			[
				'key'     => 'checklist',
				'label'   => __( 'Checklist', 'masthead' ),
				'ok'      => null === $gate_err,
				'message' => null === $gate_err
					/* translators: 1: done items, 2: total items. */
					? sprintf( __( '%1$d of %2$d done', 'masthead' ), $total - $open, $total )
					: $gate_err->get_error_message(),
			],
			[
				'key'     => 'required',
				'label'   => __( 'Required stages', 'masthead' ),
				'ok'      => null === $req_err,
				'message' => null === $req_err ? __( 'No required stage skipped', 'masthead' ) : $req_err->get_error_message(),
			],
			[
				'key'     => 'approval',
				'label'   => __( 'Approval', 'masthead' ),
				'ok'      => null === $appr_err,
				'message' => null === $appr_err ? __( 'Approved to move on', 'masthead' ) : $appr_err->get_error_message(),
			],
			[
				'key'     => 'permission',
				'label'   => __( 'Permission', 'masthead' ),
				'ok'      => null === $pub_err,
				'message' => null === $pub_err ? __( 'You can publish', 'masthead' ) : $pub_err->get_error_message(),
			],
		];

		return [
			'target' => [
				'id'   => $target->id,
				'name' => $target->name,
			],
			'ok'     => ! in_array( false, array_column( $checks, 'ok' ), true ),
			'checks' => $checks,
		];
	}

	/**
	 * Validate and apply a move.
	 *
	 * @param WP_Post          $post         Post.
	 * @param string           $target_stage Target stage ID.
	 * @param WP_User|int|null $actor        Acting user.
	 * @param int|null         $rev          Expected `_masthead_rev`, for conflict checks.
	 * @param array<string, mixed> $context  Extra: `event_verb`, `event_meta`.
	 * @return array{post: WP_Post, event: ?int, notified: list<int>, noop: bool}|WP_Error
	 */
	public static function move( WP_Post $post, string $target_stage, WP_User|int|null $actor = null, ?int $rev = null, array $context = [] ): array|WP_Error {
		$actor = self::actor( $actor );
		if ( null !== $rev && Meta::rev( $post->ID ) !== $rev ) {
			return new WP_Error(
				'masthead_conflict',
				__( 'This post changed while you were looking at it', 'masthead' ),
				[
					'status' => 409,
					'rev'    => Meta::rev( $post->ID ),
				]
			);
		}

		$valid = self::validate( $post, $target_stage, $actor );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$wf      = Workflows::for_post( $post );
		$target  = $wf?->stage( $target_stage );
		$current = $wf ? Sync::current_stage( $post, $wf ) : null;
		if ( ! $wf || ! $target ) {
			return new WP_Error( 'masthead_invalid_stage', __( 'That stage does not exist.', 'masthead' ), [ 'status' => 400 ] );
		}
		if ( $current && $current->id === $target->id ) {
			return [
				'post'     => $post,
				'event'    => null,
				'notified' => [],
				'noop'     => true,
			];
		}

		$postarr = self::status_change( $post, $target );
		if ( is_wp_error( $postarr ) ) {
			return $postarr;
		}

		global $wpdb;
		/**
		 * Filters whether moves run inside a database transaction.
		 *
		 * @param bool $use Use a transaction.
		 */
		$tx = (bool) apply_filters( 'masthead_use_transactions', true );
		if ( $tx ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		$result = Sync::applying(
			static function () use ( $post, $postarr, $target ) {
				if ( $postarr ) {
					$updated = wp_update_post(
						[
							'ID' => $post->ID,
							...$postarr,
						],
						true,
						false
					);
					if ( is_wp_error( $updated ) ) {
						return $updated;
					}
				}
				Meta::set_stage( $post->ID, $target->id );
				Meta::bump_rev( $post->ID );
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			if ( $tx ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return $result;
		}

		$verb     = (string) ( $context['event_verb'] ?? 'moved_to' );
		$event_id = Activity::log(
			$post->ID,
			$verb,
			[
				'from'      => $current?->id,
				'from_name' => $current?->name,
				'to'        => $target->id,
				'to_name'   => $target->name,
				...(array) ( $context['event_meta'] ?? [] ),
			],
			$actor->ID
		);
		if ( $tx ) {
			$wpdb->query( 'COMMIT' );
		}

		clean_post_cache( $post->ID );
		Cards::flush_counts();
		$fresh    = get_post( $post->ID );
		$fresh    = $fresh instanceof WP_Post ? $fresh : $post;
		$notified = Notifications::recipients( $fresh, $target, $actor->ID );
		Notifications::notify( $event_id, $notified );

		/**
		 * Fires after Masthead moves a post to a stage.
		 *
		 * @param WP_Post    $post    Post after the move.
		 * @param Stage      $target  Stage entered.
		 * @param Stage|null $current Stage left.
		 * @param WP_User    $actor   Acting user.
		 */
		do_action( 'masthead_moved', $fresh, $target, $current, $actor );

		return [
			'post'     => $fresh,
			'event'    => $event_id,
			'notified' => $notified,
			'noop'     => false,
		];
	}

	/**
	 * Post fields to write so that the post status matches a stage.
	 *
	 * @return array<string, mixed>|WP_Error Empty array when nothing changes.
	 */
	public static function status_change( WP_Post $post, Stage $target ): array|WP_Error {
		$status  = $target->wp_status;
		$current = WpStatus::from_post_status( $post->post_status );
		$now     = time();
		$gmt     = '0000-00-00 00:00:00' === $post->post_date_gmt ? null : (int) strtotime( $post->post_date_gmt . ' UTC' );

		if ( WpStatus::Future === $status ) {
			if ( null !== $gmt && $gmt > $now && 'future' === $post->post_status ) {
				return [];
			}
			if ( null !== $gmt && $gmt > $now ) {
				return [
					'post_status'   => 'future',
					'post_date'     => $post->post_date,
					'post_date_gmt' => $post->post_date_gmt,
					'edit_date'     => true,
				];
			}
			$when = self::due_datetime( $post->ID );
			if ( ! $when || $when->getTimestamp() <= $now ) {
				return new WP_Error(
					'masthead_needs_date',
					__( 'Pick a publish date first', 'masthead' ),
					[ 'status' => 422 ]
				);
			}
			return [
				'post_status'   => 'future',
				'post_date'     => $when->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => get_gmt_from_date( $when->format( 'Y-m-d H:i:s' ) ),
				'edit_date'     => true,
			];
		}

		if ( WpStatus::Publish === $status ) {
			if ( 'publish' === $post->post_status || 'private' === $post->post_status ) {
				return [];
			}
			if ( null === $gmt || $gmt > $now ) {
				$local = current_time( 'mysql' );
				return [
					'post_status'   => 'publish',
					'post_date'     => $local,
					'post_date_gmt' => get_gmt_from_date( $local ),
					'edit_date'     => true,
				];
			}
			return [ 'post_status' => 'publish' ];
		}

		if ( $current === $status ) {
			return [];
		}
		return [ 'post_status' => $status->value ];
	}

	/**
	 * The due date at the default publish time in the site timezone.
	 */
	public static function due_datetime( int $post_id ): ?\DateTimeImmutable {
		$due = Meta::due( $post_id );
		if ( '' === $due ) {
			return null;
		}
		$hour = (int) apply_filters( 'masthead_default_publish_hour', self::DEFAULT_PUBLISH_HOUR );
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', sprintf( '%s %02d:00:00', $due, $hour ), wp_timezone() );
		return $date ? $date : null;
	}

	public static function short_title( WP_Post $post ): string {
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES );
		if ( '' === $title ) {
			$title = __( '(no title)', 'masthead' );
		}
		return mb_strlen( $title ) > 34 ? mb_substr( $title, 0, 32 ) . '…' : $title;
	}

	private static function actor( WP_User|int|null $actor ): WP_User {
		if ( $actor instanceof WP_User ) {
			return $actor;
		}
		$user = get_userdata( null === $actor ? get_current_user_id() : $actor );
		return $user instanceof WP_User ? $user : new WP_User( 0 );
	}
}
