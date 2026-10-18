<?php
/**
 * Enforces the rule engine on status changes made outside Masthead
 * (block editor, REST, Quick Edit, Bulk Edit, classic editor).
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use WP_Error;
use WP_Post;
use WP_REST_Request;

final class Guard {

	private const string NOTICE_KEY = 'masthead_blocked_';

	/**
	 * Register the REST filter for each workflow post type.
	 */
	public static function register(): void {
		foreach ( Workflows::post_types() as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", [ self::class, 'rest_pre_insert' ], 10, 2 );
		}
	}

	/**
	 * `rest_pre_insert_{post_type}`: block status changes the rule engine rejects.
	 *
	 * @param \stdClass|WP_Error $prepared Prepared post.
	 */
	public static function rest_pre_insert( \stdClass|WP_Error $prepared, WP_REST_Request $request ): \stdClass|WP_Error {
		if ( is_wp_error( $prepared ) || Sync::is_applying() || empty( $prepared->post_status ) ) {
			return $prepared;
		}
		$meta      = $request->get_param( 'meta' );
		$checklist = is_array( $meta ) && isset( $meta[ Meta::CHECKLIST ] ) && is_array( $meta[ Meta::CHECKLIST ] ) ? $meta[ Meta::CHECKLIST ] : null;

		if ( ! empty( $prepared->ID ) ) {
			$post = get_post( (int) $prepared->ID );
			if ( ! $post ) {
				return $prepared;
			}
			$error = self::check( $post, (string) $prepared->post_status, $checklist );
		} else {
			$post  = new WP_Post(
				(object) [
					'ID'          => 0,
					'post_type'   => (string) ( $prepared->post_type ?? $request->get_param( 'type' ) ?? 'post' ),
					'post_status' => 'draft',
					'post_title'  => (string) ( $prepared->post_title ?? '' ),
					'post_author' => (int) ( $prepared->post_author ?? get_current_user_id() ),
				]
			);
			$error = self::check( $post, (string) $prepared->post_status, $checklist, true );
		}
		return $error ?? $prepared;
	}

	/**
	 * `wp_insert_post_data`: revert blocked status changes from non-REST screens.
	 *
	 * @param array<string, mixed> $data    Sanitized post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public static function insert_post_data( array $data, array $postarr, array $unsanitized = [], bool $update = false ): array {
		if ( Sync::is_applying() || ! $update || empty( $postarr['ID'] ) || self::is_unattended() ) {
			return $data;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $data;
		}
		$post = get_post( (int) $postarr['ID'] );
		if ( ! $post ) {
			return $data;
		}
		$error = self::check( $post, (string) ( $data['post_status'] ?? $post->post_status ) );
		if ( ! $error ) {
			return $data;
		}

		if ( wp_doing_ajax() && isset( $_POST['action'] ) && 'inline-save' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification -- core verified the nonce.
			wp_die( esc_html( $error->get_error_message() ) );
		}

		$data['post_status'] = $post->post_status;
		if ( 'future' !== $post->post_status ) {
			$data['post_date']     = $post->post_date;
			$data['post_date_gmt'] = $post->post_date_gmt;
		}
		self::remember( $error->get_error_message() );
		return $data;
	}

	/**
	 * Run the rule engine for a status change.
	 *
	 * @param array<string, mixed>|null $checklist Pending checklist value from the request.
	 */
	public static function check( WP_Post $post, string $new_status, ?array $checklist = null, bool $is_new = false ): ?WP_Error {
		if ( in_array( $new_status, [ 'trash', 'auto-draft', 'inherit' ], true ) || 'trash' === $post->post_status ) {
			return null;
		}
		if ( ! $is_new && $new_status === $post->post_status ) {
			return null;
		}
		$wf = Workflows::for_post( $post );
		if ( ! $wf ) {
			return null;
		}
		$current = $is_new || 'auto-draft' === $post->post_status ? $wf->first_stage() : Sync::current_stage( $post, $wf );
		$target  = Sync::target_for_status( $wf, $current, $new_status );
		if ( ! $target ) {
			return null;
		}
		$context = [ 'from' => $current?->id ];
		if ( null !== $checklist ) {
			$context['checklist'] = $checklist;
		}
		$result = Rules::validate( $post, $target->id, null, $context );
		return is_wp_error( $result ) ? $result : null;
	}

	/**
	 * Cron, WP-CLI and other requests without a user are not blocked; Sync still moves the card.
	 */
	private static function is_unattended(): bool {
		return 0 === get_current_user_id() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	private static function remember( string $message ): void {
		$key        = self::NOTICE_KEY . get_current_user_id();
		$messages   = get_transient( $key );
		$messages   = is_array( $messages ) ? $messages : [];
		$messages[] = $message;
		set_transient( $key, array_values( array_unique( $messages ) ), 120 );
		add_filter(
			'redirect_post_location',
			static fn( string $location ): string => remove_query_arg( 'message', $location )
		);
	}

	/**
	 * Show blocked-change messages from the previous request.
	 */
	public static function admin_notices(): void {
		$key      = self::NOTICE_KEY . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) || ! $messages ) {
			return;
		}
		delete_transient( $key );
		foreach ( $messages as $message ) {
			wp_admin_notice(
				esc_html( (string) $message ),
				[
					'type'        => 'error',
					'dismissible' => true,
				]
			);
		}
	}
}
