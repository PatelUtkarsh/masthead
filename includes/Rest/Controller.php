<?php
/**
 * Shared REST helpers.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Rest;

use Masthead\Capabilities;
use Masthead\Workflows;
use WP_Error;
use WP_Post;
use WP_REST_Request;

abstract class Controller {

	public const string NAMESPACE = 'masthead/v1';

	abstract public function register_routes(): void;

	public function can_view(): bool|WP_Error {
		return current_user_can( Capabilities::VIEW ) ? true : $this->forbidden();
	}

	public function can_manage_boards(): bool|WP_Error {
		return current_user_can( Capabilities::MANAGE_BOARDS ) ? true : $this->forbidden();
	}

	public function can_manage_workflows(): bool|WP_Error {
		return current_user_can( Capabilities::MANAGE_WORKFLOWS ) ? true : $this->forbidden();
	}

	public function can_edit_post( WP_REST_Request $request ): bool|WP_Error {
		$post = $this->post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return current_user_can( Capabilities::VIEW ) && current_user_can( 'edit_post', $post->ID ) ? true : $this->forbidden();
	}

	public function can_read_post( WP_REST_Request $request ): bool|WP_Error {
		$post = $this->post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return current_user_can( Capabilities::VIEW ) && current_user_can( 'read_post', $post->ID ) ? true : $this->forbidden();
	}

	protected function post( WP_REST_Request $request ): WP_Post|WP_Error {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'trash' === $post->post_status || ! Workflows::for_post( $post ) ) {
			return new WP_Error( 'masthead_not_found', __( 'Post not found.', 'masthead' ), [ 'status' => 404 ] );
		}
		return $post;
	}

	protected function forbidden(): WP_Error {
		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'masthead' ), [ 'status' => rest_authorization_required_code() ] );
	}
}
