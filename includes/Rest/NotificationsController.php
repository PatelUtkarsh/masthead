<?php
/**
 * /notifications routes.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Rest;

use Masthead\Notifications;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class NotificationsController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/notifications',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'index' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'unread' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/notifications/read',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'read' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'ids' => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
				],
			]
		);
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$user = get_current_user_id();
		return new WP_REST_Response(
			[
				'items'  => Notifications::for_user( $user, (bool) $request['unread'] ),
				'unread' => Notifications::unread_count( $user ),
			]
		);
	}

	public function read( WP_REST_Request $request ): WP_REST_Response {
		$user = get_current_user_id();
		$ids  = $request->has_param( 'ids' ) ? array_map( 'intval', (array) $request['ids'] ) : null;
		Notifications::mark_read( $user, $ids );
		return new WP_REST_Response( [ 'unread' => Notifications::unread_count( $user ) ] );
	}
}
