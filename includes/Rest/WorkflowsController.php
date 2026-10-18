<?php
/**
 * /workflows, /rules, /setup and /bootstrap routes.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Rest;

use Masthead\Admin;
use Masthead\Options;
use Masthead\Setup;
use Masthead\WorkflowEditor;
use Masthead\Workflows;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WorkflowsController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/bootstrap',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn() => new WP_REST_Response( Admin::bootstrap() ),
				'permission_callback' => [ $this, 'can_view' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/workflows',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'can_manage_workflows' ],
					'args'                => [
						'singular'  => [ 'type' => 'string' ],
						'plural'    => [ 'type' => 'string' ],
						'from'      => [
							'type'    => 'string',
							'default' => 'simple',
						],
						'source'    => [ 'type' => 'string' ],
						'post_type' => [ 'type' => 'string' ],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/workflows/undo',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'undo' ],
				'permission_callback' => [ $this, 'can_manage_workflows' ],
				'args'                => [
					'token' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/workflows/(?P<key>[a-z0-9_-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				[
					'methods'             => 'PUT, PATCH',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'can_manage_workflows' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'can_manage_workflows' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/workflows/(?P<key>[a-z0-9_-]+)/stages/(?P<stage>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_stage' ],
				'permission_callback' => [ $this, 'can_manage_workflows' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/rules',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static fn() => new WP_REST_Response( Options::rules() ),
					'permission_callback' => [ $this, 'can_view' ],
				],
				[
					'methods'             => 'PUT, PATCH',
					'callback'            => static fn( WP_REST_Request $r ) => new WP_REST_Response( Options::update_rules( (array) $r->get_json_params() ) ),
					'permission_callback' => [ $this, 'can_manage_workflows' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/setup',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'setup' ],
				'permission_callback' => [ $this, 'can_manage_workflows' ],
				'args'                => [
					'template'   => [
						'type' => 'string',
						'enum' => [ 'simple', 'team', 'news' ],
					],
					'pages'      => [ 'type' => 'boolean' ],
					'newsletter' => [ 'type' => 'boolean' ],
					'team'       => [
						'type'                 => [ 'object', 'array' ],
						'additionalProperties' => [ 'type' => 'boolean' ],
					],
					'import'     => [ 'type' => 'boolean' ],
					'skip'       => [ 'type' => 'boolean' ],
				],
			]
		);
	}

	public function index(): WP_REST_Response {
		return new WP_REST_Response( array_values( array_map( static fn( $wf ) => $wf->to_rest(), Workflows::all() ) ) );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$wf = Workflows::get( (string) $request['key'] );
		if ( ! $wf ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $wf->to_rest() );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data   = (array) $request->get_json_params();
		$result = WorkflowEditor::save( (string) $request['key'], $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'workflow' => $result['workflow']->to_rest(),
				'warnings' => $result['warnings'],
				'moved'    => $result['moved'],
			]
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = WorkflowEditor::create( (array) $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'workflow' => $result['workflow']->to_rest(),
				'board'    => BoardsController::format( $result['board'] ),
			],
			201
		);
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = WorkflowEditor::delete( (string) $request['key'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function delete_stage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = WorkflowEditor::delete_stage( (string) $request['key'], (string) $request['stage'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'workflow' => $result['workflow']->to_rest(),
				'token'    => $result['token'],
				'moved'    => $result['moved'],
			]
		);
	}

	public function undo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = WorkflowEditor::undo( (string) $request['token'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'workflow' => $result->to_rest() ] );
	}

	public function setup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Setup::apply( (array) $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Admin::bootstrap() );
	}
}
