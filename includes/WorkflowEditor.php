<?php
/**
 * Saving, creating and deleting workflows, with the side effects on posts.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\Stage;
use Masthead\Model\Workflow;
use Masthead\Model\WpStatus;
use WP_Error;

final class WorkflowEditor {

	private const string UNDO_PREFIX = 'masthead_undo_';

	/**
	 * Save a full workflow definition.
	 *
	 * @param array<string, mixed> $data Workflow data.
	 * @return array{workflow: Workflow, warnings: list<string>, moved: int}|WP_Error
	 */
	public static function save( string $key, array $data ): array|WP_Error {
		$old = Workflows::get( $key );
		if ( ! $old ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 404 ] );
		}
		$new   = Workflow::from_array(
			$key,
			[
				...$old->to_array(),
				...$data,
				'post_type' => $old->post_type,
				'registers' => $old->registers,
			]
		);
		$valid = self::validate( $new );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$moves = self::moves_for_removed( $old, $new );
		Workflows::save( $new );
		$moved = $moves ? Sync::reassign( $new, $moves ) : 0;
		StageTaxonomy::prune( $new );

		$removed_items = array_values( array_diff( $old->checklist_ids(), $new->checklist_ids() ) );
		if ( $removed_items ) {
			Cron::queue_checklist_cleanup( $new->post_type, $removed_items );
		}
		Cards::flush_counts();

		return [
			'workflow' => $new,
			'warnings' => self::warnings( $new ),
			'moved'    => $moved,
		];
	}

	/**
	 * Delete one stage now, keeping an undo snapshot.
	 *
	 * @return array{workflow: Workflow, token: string, moved: int}|WP_Error
	 */
	public static function delete_stage( string $key, string $stage_id ): array|WP_Error {
		$wf = Workflows::get( $key );
		if ( ! $wf ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 404 ] );
		}
		$i = $wf->index_of( $stage_id );
		if ( $i < 0 ) {
			return new WP_Error( 'masthead_invalid_stage', __( 'That stage does not exist.', 'masthead' ), [ 'status' => 404 ] );
		}
		if ( count( $wf->stages ) <= 2 ) {
			return new WP_Error( 'masthead_min_stages', __( 'A workflow needs at least two stages.', 'masthead' ), [ 'status' => 422 ] );
		}

		$affected = Sync::ids_in_stage( $wf, $stage_id );

		$stages = $wf->stages;
		array_splice( $stages, $i, 1 );
		$new = $wf->with_stages( array_values( $stages ) );
		$to  = ( $wf->stages[ $i - 1 ] ?? $wf->stages[ $i + 1 ] )->id;
		Workflows::save( $new );
		$moved = Sync::set_stage_bulk( $new, $affected, $to );
		StageTaxonomy::prune( $new );

		$token = strtolower( wp_generate_password( 16, false ) );
		set_transient(
			self::UNDO_PREFIX . $token,
			[
				'user'     => get_current_user_id(),
				'key'      => $key,
				'workflow' => $wf->to_array(),
				'stage'    => $stage_id,
				'posts'    => $affected,
			],
			MINUTE_IN_SECONDS * 5
		);

		return [
			'workflow' => $new,
			'token'    => $token,
			'moved'    => $moved,
		];
	}

	/**
	 * Undo a stage deletion: restore the workflow and every affected post.
	 */
	public static function undo( string $token ): Workflow|WP_Error {
		$key  = self::UNDO_PREFIX . sanitize_key( $token );
		$snap = get_transient( $key );
		if ( ! is_array( $snap ) || get_current_user_id() !== (int) $snap['user'] ) {
			return new WP_Error( 'masthead_undo_expired', __( 'It’s too late to undo that.', 'masthead' ), [ 'status' => 410 ] );
		}
		delete_transient( $key );
		$wf = Workflow::from_array( (string) $snap['key'], (array) $snap['workflow'] );
		Workflows::save( $wf );
		Sync::set_stage_bulk( $wf, array_map( 'intval', (array) $snap['posts'] ), (string) $snap['stage'] );
		Cards::flush_counts();
		return $wf;
	}

	/**
	 * Create a workflow. Registers a post type unless an existing one is given.
	 *
	 * @param array<string, mixed> $args `singular`, `plural`, `from` (simple|copy|template key), `post_type`, `color`, `board_name`.
	 * @return array{workflow: Workflow, board: array<string, mixed>}|WP_Error
	 */
	public static function create( array $args ): array|WP_Error {
		$singular  = sanitize_text_field( (string) ( $args['singular'] ?? '' ) );
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? '' ) );
		$registers = '' === $post_type;

		if ( '' !== $post_type ) {
			$pto = get_post_type_object( $post_type );
			if ( ! $pto ) {
				return new WP_Error( 'masthead_invalid_type', __( 'That post type does not exist.', 'masthead' ), [ 'status' => 400 ] );
			}
			if ( Workflows::for_post_type( $post_type ) ) {
				return new WP_Error( 'masthead_type_taken', __( 'That post type already has a workflow.', 'masthead' ), [ 'status' => 409 ] );
			}
			$singular = '' !== $singular ? $singular : (string) $pto->labels->singular_name;
			$plural   = (string) $pto->labels->name;
		} else {
			if ( '' === $singular ) {
				return new WP_Error( 'masthead_name_required', __( 'Give the content type a name.', 'masthead' ), [ 'status' => 400 ] );
			}
			$post_type = Workflows::post_type_slug( $singular );
			$plural    = sanitize_text_field( (string) ( $args['plural'] ?? '' ) );
			$plural    = '' !== $plural ? $plural : $singular . 's';
		}

		$name  = sanitize_text_field( (string) ( $args['name'] ?? $plural ) );
		$key   = Workflows::unique_key( $name );
		$from  = (string) ( $args['from'] ?? 'review' );
		$color = sanitize_hex_color( (string) ( $args['color'] ?? '' ) );
		$props = [
			'name'      => $name,
			'post_type' => $post_type,
			'color'     => $color ? $color : Workflows::next_color(),
			'singular'  => $singular,
			'plural'    => $plural,
			'registers' => $registers,
		];

		if ( 'copy' === $from ) {
			$source = Workflows::get( sanitize_key( (string) ( $args['source'] ?? 'articles' ) ) ) ?? ( array_values( Workflows::all() )[0] ?? null );
			$wf     = $source
				? Workflow::from_array( $key, [ ...$source->to_array(), ...$props ] )
				: Workflows::from_template( 'review', $key, $props );
		} else {
			$tpl = array_key_exists( $from, Workflows::templates() ) ? $from : 'review';
			if ( 'simple' === $from ) {
				$tpl = 'review';
			}
			$wf = Workflows::from_template( $tpl, $key, $props );
		}

		Workflows::save( $wf );
		if ( $registers ) {
			Workflows::register_post_types();
			update_option( 'masthead_flush_rewrite', 1, true );
		}
		Meta::register_for( $wf->post_type );
		StageTaxonomy::register();
		add_filter( "rest_pre_insert_{$wf->post_type}", [ Guard::class, 'rest_pre_insert' ], 10, 2 );

		$board = Boards::create(
			[
				'name'     => sanitize_text_field( (string) ( $args['board_name'] ?? '' ) ) ?: $plural,
				'workflow' => $wf->key,
			]
		);

		if ( ! $registers ) {
			Sync::import( $wf );
		}

		return [
			'workflow' => $wf,
			'board'    => $board,
		];
	}

	public static function delete( string $key ): true|WP_Error {
		$wf = Workflows::get( $key );
		if ( ! $wf ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 404 ] );
		}
		if ( count( Workflows::all() ) <= 1 ) {
			return new WP_Error( 'masthead_last_workflow', __( 'You need at least one workflow.', 'masthead' ), [ 'status' => 422 ] );
		}
		$count = 0;
		foreach ( (array) wp_count_posts( $wf->post_type ) as $status => $n ) {
			if ( in_array( $status, [ ...Cards::STATUSES, 'trash' ], true ) ) {
				$count += (int) $n;
			}
		}
		if ( $count > 0 ) {
			return new WP_Error( 'masthead_workflow_has_posts', __( 'Move or delete its posts first. A workflow with posts can’t be deleted.', 'masthead' ), [ 'status' => 422 ] );
		}
		StageTaxonomy::prune( $wf->with_stages( [] ) );
		Workflows::delete( $key );
		Boards::delete_for_workflow( $key );
		return true;
	}

	public static function validate( Workflow $wf ): true|WP_Error {
		if ( count( $wf->stages ) < 2 ) {
			return new WP_Error( 'masthead_min_stages', __( 'A workflow needs at least two stages.', 'masthead' ), [ 'status' => 422 ] );
		}
		return true;
	}

	/**
	 * @return list<string>
	 */
	public static function warnings( Workflow $wf ): array {
		$last = $wf->stages[ count( $wf->stages ) - 1 ] ?? null;
		if ( $last && WpStatus::Publish !== $last->wp_status ) {
			return [ __( 'The last stage usually publishes the post. This workflow ends on a stage that doesn’t.', 'masthead' ) ];
		}
		return [];
	}

	/**
	 * For stages removed between two versions, where should their posts go?
	 * The previous stage in the old order that still exists, else the next one.
	 *
	 * @return array<string, string>
	 */
	private static function moves_for_removed( Workflow $old, Workflow $updated ): array {
		$moves = [];
		foreach ( $old->stages as $i => $stage ) {
			if ( $updated->stage( $stage->id ) ) {
				continue;
			}
			$to = null;
			for ( $k = $i - 1; $k >= 0 && ! $to; $k-- ) {
				$to = $updated->stage( $old->stages[ $k ]->id );
			}
			$total = count( $old->stages );
			for ( $k = $i + 1; $k < $total && ! $to; $k++ ) {
				$to = $updated->stage( $old->stages[ $k ]->id );
			}
			$to ??= $updated->first_stage();
			if ( $to instanceof Stage ) {
				$moves[ $stage->id ] = $to->id;
			}
		}
		return $moves;
	}
}
