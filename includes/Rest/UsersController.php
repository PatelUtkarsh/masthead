<?php
/**
 * /users route: paged user search for pickers, filters and @mentions.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Rest;

use Masthead\Capabilities;
use Masthead\People;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class UsersController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/users',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'index' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'search'    => [
						'type'    => 'string',
						'default' => '',
					],
					'include'   => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
					'approvers' => [
						'type'    => 'boolean',
						'default' => false,
					],
					'editors'   => [
						'type'    => 'boolean',
						'default' => false,
					],
					'any_role'  => [
						'type'    => 'boolean',
						'default' => false,
					],
					'page'      => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page'  => [
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					],
				],
			]
		);
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$any_role = (bool) $request['any_role'] && current_user_can( Capabilities::MANAGE_WORKFLOWS );
		$result   = People::search(
			[
				'search'    => (string) $request['search'],
				'include'   => array_map( 'intval', (array) ( $request['include'] ?? [] ) ),
				'approvers' => (bool) $request['approvers'],
				'editors'   => (bool) $request['editors'],
				'any_role'  => $any_role,
				'page'      => (int) $request['page'],
				'per_page'  => (int) $request['per_page'],
			]
		);
		if ( $any_role ) {
			foreach ( $result['users'] as &$u ) {
				$u['included'] = user_can( $u['id'], Capabilities::VIEW );
			}
			unset( $u );
		}
		return new WP_REST_Response(
			[
				'users'    => $result['users'],
				'has_more' => $result['has_more'],
			]
		);
	}
}
