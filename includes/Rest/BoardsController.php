<?php
/**
 * /boards routes.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Rest;

use Masthead\Boards;
use Masthead\Cards;
use Masthead\People;
use Masthead\Workflows;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class BoardsController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/boards',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'counts' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'can_manage_boards' ],
					'args'                => $this->board_args( true ),
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/boards/count',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'count' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => $this->board_args( true ),
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/boards/(?P<id>[a-z0-9_-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'include' => [ 'type' => 'string' ],
						'stage'   => [ 'type' => 'string' ],
						'page'    => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'search'  => [ 'type' => 'string' ],
						'author'  => [ 'type' => 'integer' ],
					],
				],
				[
					'methods'             => 'PUT, PATCH',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'can_manage_boards' ],
					'args'                => $this->board_args( false ),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'can_manage_boards' ],
				],
			]
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function board_args( bool $create ): array {
		return [
			'name'     => [
				'type'     => 'string',
				'required' => false,
			],
			'workflow' => [
				'type'     => 'string',
				'required' => $create,
			],
			'filters'  => [
				'type'       => 'object',
				'properties' => [
					'category' => [ 'type' => [ 'integer', 'null' ] ],
					'author'   => [ 'type' => [ 'integer', 'null' ] ],
				],
			],
			'default'  => [ 'type' => 'boolean' ],
		];
	}

	/**
	 * @param array{id: string, name: string, workflow: string, filters: array{category?: int, author?: int}} $board Board.
	 * @return array<string, mixed>
	 */
	public static function format( array $board, bool $with_count = true ): array {
		$summary = [];
		$labels  = [];
		if ( ! empty( $board['filters']['category'] ) ) {
			$term = get_term( $board['filters']['category'], 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$labels['category'] = [
					'id'   => $term->term_id,
					'name' => html_entity_decode( $term->name, ENT_QUOTES ),
				];
				$summary[]          = $labels['category']['name'];
			}
		}
		if ( ! empty( $board['filters']['author'] ) ) {
			$u = People::user( $board['filters']['author'] );
			if ( $u ) {
				$labels['author'] = $u;
				$summary[]        = $u['name'];
			}
		}
		return [
			...$board,
			'filters'    => (object) $board['filters'],
			'labels'     => (object) $labels,
			'summary'    => $summary ? implode( ' · ', $summary ) : __( 'All posts', 'masthead' ),
			'count'      => $with_count ? Cards::count_for_board( $board ) : null,
			'is_default' => Boards::default_id() === $board['id'],
		];
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$with_count = (bool) $request['counts'];
		return new WP_REST_Response(
			[
				'boards'        => array_map( static fn( $b ) => self::format( $b, $with_count ), Boards::all() ),
				'default_board' => Boards::default_id(),
			]
		);
	}

	public function count( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$wf = Workflows::get( (string) $request['workflow'] );
		if ( ! $wf ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 404 ] );
		}
		$filters = (array) $request['filters'];
		return new WP_REST_Response(
			[
				'count' => Cards::count_for_board(
					[
						'workflow' => $wf->key,
						'filters'  => array_filter(
							[
								'category' => (int) ( $filters['category'] ?? 0 ),
								'author'   => (int) ( $filters['author'] ?? 0 ),
							]
						),
					]
				),
			]
		);
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$board = Boards::get( (string) $request['id'] );
		if ( ! $board ) {
			return new WP_Error( 'masthead_board_not_found', __( 'Board not found.', 'masthead' ), [ 'status' => 404 ] );
		}
		$out = self::format( $board );
		if ( 'cards' === $request['include'] ) {
			$out['columns'] = Cards::for_board(
				$board,
				[
					'stage'  => (string) $request['stage'],
					'page'   => (int) $request['page'],
					'search' => (string) $request['search'],
					'author' => (int) $request['author'],
				]
			);
			$wf             = Workflows::get( $board['workflow'] );
			$out['counts']  = $wf ? (object) Cards::stage_counts( $wf ) : (object) [];
		}
		return new WP_REST_Response( $out );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Workflows::get( (string) $request['workflow'] ) ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 400 ] );
		}
		$board = Boards::create(
			[
				'name'     => (string) $request['name'],
				'workflow' => (string) $request['workflow'],
				'filters'  => (array) $request['filters'],
			],
			(bool) $request['default']
		);
		return new WP_REST_Response( self::format( $board ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = [];
		foreach ( [ 'name', 'workflow', 'filters' ] as $key ) {
			if ( $request->has_param( $key ) ) {
				$data[ $key ] = $request[ $key ];
			}
		}
		if ( isset( $data['workflow'] ) && ! Workflows::get( (string) $data['workflow'] ) ) {
			return new WP_Error( 'masthead_no_workflow', __( 'That workflow does not exist.', 'masthead' ), [ 'status' => 400 ] );
		}
		$board = Boards::update( (string) $request['id'], $data, $request->has_param( 'default' ) ? (bool) $request['default'] : null );
		if ( ! $board ) {
			return new WP_Error( 'masthead_board_not_found', __( 'Board not found.', 'masthead' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( self::format( $board ) );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Boards::get( (string) $request['id'] ) ) {
			return new WP_Error( 'masthead_board_not_found', __( 'Board not found.', 'masthead' ), [ 'status' => 404 ] );
		}
		if ( ! Boards::delete( (string) $request['id'] ) ) {
			return new WP_Error( 'masthead_last_board', __( 'At least one board must remain.', 'masthead' ), [ 'status' => 422 ] );
		}
		return new WP_REST_Response(
			[
				'deleted'       => true,
				'default_board' => Boards::default_id(),
			]
		);
	}
}
