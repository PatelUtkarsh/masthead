<?php
/**
 * Post edits made from Masthead screens (drawer, calendar, editor sidebar).
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\WpStatus;
use WP_Error;
use WP_Post;

final class Posts {

	/**
	 * Create a draft in a stage.
	 *
	 * @param array{category?: int, author?: int} $filters Board filters to apply to the new post.
	 */
	public static function create( string $workflow, ?string $stage_id, string $title = '', array $filters = [] ): WP_Post|WP_Error {
		$wf = Workflows::get( $workflow );
		if ( ! $wf ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 404 ] );
		}
		$pto = get_post_type_object( $wf->post_type );
		if ( ! $pto || ! current_user_can( $pto->cap->create_posts ) ) {
			return new WP_Error( 'masthead_cannot_create', __( 'You can’t create posts of this type.', 'masthead' ), [ 'status' => 403 ] );
		}
		$stage   = $stage_id ? $wf->stage( $stage_id ) : $wf->first_stage();
		$stage ??= $wf->first_stage();
		if ( ! $stage ) {
			return new WP_Error( 'masthead_invalid_stage', __( 'That stage does not exist.', 'masthead' ), [ 'status' => 400 ] );
		}

		$author = get_current_user_id();
		if ( ! empty( $filters['author'] ) && ( (int) $filters['author'] === $author || current_user_can( $pto->cap->edit_others_posts ) ) ) {
			$author = (int) $filters['author'];
		}

		if ( '' === $title ) {
			/* translators: %s: singular post type label, lowercase. */
			$title = sprintf( __( 'Untitled %s', 'masthead' ), strtolower( (string) $pto->labels->singular_name ) );
		}

		$first = $wf->first_stage();
		$id    = wp_insert_post(
			[
				'post_type'   => $wf->post_type,
				'post_status' => 'draft',
				'post_title'  => sanitize_text_field( $title ),
				'post_author' => $author,
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! empty( $filters['category'] ) && is_object_in_taxonomy( $wf->post_type, 'category' ) ) {
			wp_set_post_categories( $id, [ (int) $filters['category'] ] );
		}

		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'masthead_create_failed', __( 'Couldn’t create the post.', 'masthead' ), [ 'status' => 500 ] );
		}

		if ( $first && $stage->id !== $first->id && Meta::stage( $id ) !== $stage->id ) {
			if ( WpStatus::Draft === $stage->wp_status || WpStatus::Pending === $stage->wp_status ) {
				$change = Rules::status_change( $post, $stage );
				Sync::applying(
					static function () use ( $id, $change, $stage ): void {
						if ( is_array( $change ) && $change ) {
							wp_update_post(
								[
									'ID' => $id,
									...$change,
								]
							);
						}
						Meta::set_stage( $id, $stage->id );
						Meta::bump_rev( $id );
					}
				);
			} else {
				$moved = Rules::move( $post, $stage->id );
				if ( is_wp_error( $moved ) ) {
					return $moved;
				}
			}
		}
		Cards::flush_counts();
		$post = get_post( $id );
		return $post instanceof WP_Post ? $post : new WP_Error( 'masthead_create_failed', __( 'Couldn’t create the post.', 'masthead' ), [ 'status' => 500 ] );
	}

	/**
	 * Apply a partial update.
	 *
	 * @param array<string, mixed> $changes `checklist`, `due`, `author`, `title`, `auto_schedule`.
	 * @return array{post: WP_Post, scheduled: bool, blocked: ?string, notified: list<int>}|WP_Error
	 */
	public static function patch( WP_Post $post, array $changes ): array|WP_Error {
		$wf = Workflows::for_post( $post );
		if ( ! $wf ) {
			return new WP_Error( 'masthead_no_workflow', __( 'This post type has no workflow.', 'masthead' ), [ 'status' => 400 ] );
		}
		$scheduled = false;
		$blocked   = null;
		$notified  = [];
		$touched   = false;

		if ( array_key_exists( 'title', $changes ) ) {
			$title = sanitize_text_field( (string) $changes['title'] );
			if ( $title !== $post->post_title ) {
				Sync::applying(
					static fn() => wp_update_post(
						[
							'ID'         => $post->ID,
							'post_title' => $title,
						]
					)
				);
				$touched = true;
			}
		}

		if ( array_key_exists( 'checklist', $changes ) && is_array( $changes['checklist'] ) ) {
			$allowed = array_flip( $wf->checklist_ids() );
			$next    = Meta::checklist( $post->ID );
			foreach ( $changes['checklist'] as $id => $done ) {
				$id = sanitize_key( (string) $id );
				if ( ! isset( $allowed[ $id ] ) ) {
					continue;
				}
				if ( $done ) {
					$next[ $id ] = true;
				} else {
					unset( $next[ $id ] );
				}
			}
			Meta::set_checklist( $post->ID, $next );
			$touched = true;
		}

		if ( array_key_exists( 'author', $changes ) ) {
			$author = absint( $changes['author'] );
			if ( $author && $author !== (int) $post->post_author ) {
				$pto = get_post_type_object( $post->post_type );
				if ( ! $pto || ! current_user_can( $pto->cap->edit_others_posts ) || ! user_can( $author, $pto->cap->edit_posts ) ) {
					return new WP_Error( 'masthead_cannot_assign', __( 'You can’t assign this post to that person.', 'masthead' ), [ 'status' => 403 ] );
				}
				Sync::applying(
					static fn() => wp_update_post(
						[
							'ID'          => $post->ID,
							'post_author' => $author,
						]
					)
				);
				$user  = People::user( $author );
				$event = Activity::log( $post->ID, 'assigned', [ 'name' => $user['name'] ?? '' ] );
				if ( get_current_user_id() !== $author ) {
					Notifications::notify( $event, [ $author ] );
					$notified[] = $author;
				}
				$touched = true;
			}
		}

		if ( array_key_exists( 'due', $changes ) ) {
			$due = Meta::sanitize_due( $changes['due'] );
			if ( Meta::due( $post->ID ) !== $due ) {
				Meta::set_due( $post->ID, $due );
				$touched = true;

				$fresh = get_post( $post->ID );
				$fresh = $fresh instanceof WP_Post ? $fresh : $post;
				$stage = Sync::current_stage( $fresh, $wf );
				$next  = $stage ? $wf->next_stage( $stage->id ) : null;

				if ( '' !== $due && ! empty( $changes['auto_schedule'] ) && Options::rules()['auto_schedule'] && $next && WpStatus::Future === $next->wp_status && $stage && ! $stage->wp_status->is_live() ) {
					$moved = Rules::move(
						$fresh,
						$next->id,
						null,
						null,
						[
							'event_verb' => 'scheduled',
							'event_meta' => [ 'due' => $due ],
						]
					);
					if ( is_wp_error( $moved ) ) {
						$blocked = $moved->get_error_message();
						Activity::log( $post->ID, 'due_set', [ 'due' => $due ] );
					} else {
						$scheduled = true;
						$notified  = [ ...$notified, ...$moved['notified'] ];
					}
				} else {
					if ( '' !== $due && 'future' === $fresh->post_status ) {
						$when = Rules::due_datetime( $post->ID );
						if ( $when && $when->getTimestamp() > time() ) {
							Sync::applying(
								static fn() => wp_update_post(
									[
										'ID'            => $post->ID,
										'post_date'     => $when->format( 'Y-m-d H:i:s' ),
										'post_date_gmt' => get_gmt_from_date( $when->format( 'Y-m-d H:i:s' ) ),
										'edit_date'     => true,
									]
								)
							);
						}
					}
					Activity::log( $post->ID, 'due_set', [ 'due' => $due ] );
				}
			}
		}

		if ( $touched && ! $scheduled ) {
			Meta::bump_rev( $post->ID );
		}
		clean_post_cache( $post->ID );
		$fresh = get_post( $post->ID );
		return [
			'post'      => $fresh instanceof WP_Post ? $fresh : $post,
			'scheduled' => $scheduled,
			'blocked'   => $blocked,
			'notified'  => array_values( array_unique( $notified ) ),
		];
	}

	/**
	 * Detail payload for the drawer.
	 *
	 * @return array<string, mixed>
	 */
	public static function detail( WP_Post $post ): array {
		$card = Cards::card( $post );
		$wf   = Workflows::for_post( $post );
		$rev  = null;
		if ( $wf && $card['stage'] ) {
			$i = $wf->index_of( (string) $card['stage'] );
			foreach ( array_slice( $wf->stages, max( 0, $i ) ) as $s ) {
				if ( $s->reviewer ) {
					$rev = [
						'user'  => People::user( $s->reviewer ),
						'stage' => $s->name,
						'now'   => $s->id === $card['stage'],
					];
					break;
				}
			}
		}
		return [
			...$card,
			'reviewer' => $rev,
			'boards'   => Boards::for_post( $post ),
			'thread'   => Notes::for_post( $post->ID ),
			'activity' => Activity::for_post( $post->ID ),
		];
	}
}
