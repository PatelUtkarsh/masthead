<?php
/**
 * /posts routes: create, detail, move, bulk move, patch, checks, notes, activity.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Rest;

use Masthead\Activity;
use Masthead\Boards;
use Masthead\Capabilities;
use Masthead\Cards;
use Masthead\Notes;
use Masthead\People;
use Masthead\Posts;
use Masthead\Rules;
use Masthead\Workflows;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PostsController extends Controller {

	public function register_routes(): void {
		$id = '/posts/(?P<id>\d+)';

		register_rest_route(
			self::NAMESPACE,
			'/posts',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'workflow' => [
						'type'     => 'string',
						'required' => true,
					],
					'stage'    => [ 'type' => 'string' ],
					'title'    => [ 'type' => 'string' ],
					'board'    => [ 'type' => 'string' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/posts/move',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk_move' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'ids'   => [
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
						'required' => true,
					],
					'stage' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			$id,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'can_read_post' ],
				],
				[
					'methods'             => 'PATCH, PUT',
					'callback'            => [ $this, 'patch' ],
					'permission_callback' => [ $this, 'can_edit_post' ],
					'args'                => [
						'checklist'     => [ 'type' => 'object' ],
						'due'           => [ 'type' => [ 'string', 'null' ] ],
						'author'        => [ 'type' => 'integer' ],
						'title'         => [ 'type' => 'string' ],
						'auto_schedule' => [ 'type' => 'boolean' ],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/move',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'move' ],
				'permission_callback' => [ $this, 'can_edit_post' ],
				'args'                => [
					'stage' => [
						'type'     => 'string',
						'required' => true,
					],
					'rev'   => [ 'type' => 'integer' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/checks',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'checks' ],
				'permission_callback' => [ $this, 'can_read_post' ],
				'args'                => [
					'target' => [ 'type' => 'string' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/notes',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'notes' ],
					'permission_callback' => [ $this, 'can_read_post' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add_note' ],
					'permission_callback' => [ $this, 'can_read_post' ],
					'args'                => [
						'text'     => [
							'type'     => 'string',
							'required' => true,
						],
						'mentions' => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/activity',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'activity' ],
				'permission_callback' => [ $this, 'can_read_post' ],
			]
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$filters = [];
		if ( $request['board'] ) {
			$board   = Boards::get( (string) $request['board'] );
			$filters = $board['filters'] ?? [];
		}
		$post = Posts::create( (string) $request['workflow'], $request['stage'] ? (string) $request['stage'] : null, (string) $request['title'], $filters );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return new WP_REST_Response( Posts::detail( $post ), 201 );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		return is_wp_error( $post ) ? $post : new WP_REST_Response( Posts::detail( $post ) );
	}

	public function move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$rev    = $request->has_param( 'rev' ) && null !== $request['rev'] ? (int) $request['rev'] : null;
		$result = Rules::move( $post, (string) $request['stage'], null, $rev );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			if ( 409 === ( $data['status'] ?? 0 ) ) {
				$data['card'] = Cards::card( $post );
				$result->add_data( $data );
			}
			return $result;
		}
		$wf    = Workflows::for_post( $result['post'] );
		$stage = $wf?->stage( (string) $request['stage'] );
		$names = People::firsts( $result['notified'] );
		return new WP_REST_Response(
			[
				'post'     => Cards::card( $result['post'] ),
				'event'    => $result['event'] ? Activity::format( (object) Activity::get( $result['event'] ) ) : null,
				'notified' => array_map( static fn( int $id ) => People::user( $id ), $result['notified'] ),
				'message'  => $stage ? self::moved_message( $stage->name, $names ) : '',
			]
		);
	}

	public static function moved_message( string $stage, string $names ): string {
		return '' !== $names
			/* translators: 1: stage name, 2: first names. */
			? sprintf( __( 'Moved to %1$s · notified %2$s', 'masthead' ), $stage, $names )
			/* translators: %s: stage name. */
			: sprintf( __( 'Moved to %s', 'masthead' ), $stage );
	}

	public function bulk_move( WP_REST_Request $request ): WP_REST_Response {
		$stage   = (string) $request['stage'];
		$results = [];
		$moved   = 0;
		$errors  = [];
		$name    = $stage;
		foreach ( array_unique( array_map( 'intval', (array) $request['ids'] ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! Workflows::for_post( $post ) ) {
				$results[] = [
					'id'    => $id,
					'ok'    => false,
					'error' => __( 'Post not found.', 'masthead' ),
				];
				continue;
			}
			$stage_obj = Workflows::for_post( $post )?->stage( $stage );
			$name      = $stage_obj ? $stage_obj->name : $name;
			$result    = current_user_can( 'edit_post', $id )
				? Rules::move( $post, $stage )
				: new WP_Error( 'masthead_cannot_edit', __( 'You can’t edit this post.', 'masthead' ) );
			if ( is_wp_error( $result ) ) {
				$errors[]  = $result->get_error_message();
				$results[] = [
					'id'    => $id,
					'ok'    => false,
					'code'  => $result->get_error_code(),
					'error' => $result->get_error_message(),
				];
				continue;
			}
			if ( ! $result['noop'] ) {
				++$moved;
			}
			$results[] = [
				'id'   => $id,
				'ok'   => true,
				'card' => Cards::card( $result['post'] ),
			];
		}
		return new WP_REST_Response(
			[
				'results' => $results,
				'moved'   => $moved,
				'blocked' => count( $errors ),
				'message' => self::bulk_message( $moved, $name, $errors ),
			]
		);
	}

	/**
	 * "Moved 3 to Ready · 1 blocked: {reason}".
	 *
	 * @param list<string> $errors Error messages.
	 */
	public static function bulk_message( int $moved, string $stage, array $errors ): string {
		/* translators: 1: number of posts, 2: stage name. */
		$msg = sprintf( __( 'Moved %1$d to %2$s', 'masthead' ), $moved, $stage );
		if ( $errors ) {
			/* translators: 1: number blocked, 2: first reason. */
			$msg .= ' · ' . sprintf( __( '%1$d blocked: %2$s', 'masthead' ), count( $errors ), $errors[0] );
		}
		return $msg;
	}

	public function patch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$changes = [];
		foreach ( [ 'checklist', 'due', 'author', 'title', 'auto_schedule' ] as $key ) {
			if ( $request->has_param( $key ) ) {
				$changes[ $key ] = $request[ $key ] ?? '';
			}
		}
		$result = Posts::patch( $post, $changes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'post'      => Posts::detail( $result['post'] ),
				'scheduled' => $result['scheduled'],
				'blocked'   => $result['blocked'],
				'notified'  => array_map( static fn( int $id ) => People::user( $id ), $result['notified'] ),
			]
		);
	}

	public function checks( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return new WP_REST_Response( Rules::checks( $post, $request['target'] ? (string) $request['target'] : null ) );
	}

	public function notes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		return is_wp_error( $post ) ? $post : new WP_REST_Response( Notes::for_post( $post->ID ) );
	}

	public function add_note( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$result = Notes::add( $post, (string) $request['text'], array_map( 'intval', (array) $request['mentions'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'note'      => $result['note'],
				'mentioned' => array_map( static fn( int $id ) => People::user( $id ), $result['mentions'] ),
				'notified'  => array_map( static fn( int $id ) => People::user( $id ), $result['notified'] ),
			],
			201
		);
	}

	public function activity( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		return is_wp_error( $post ) ? $post : new WP_REST_Response( Activity::for_post( $post->ID ) );
	}
}
