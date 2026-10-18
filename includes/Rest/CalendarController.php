<?php
/**
 * /calendar route.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Rest;

use Masthead\Cards;
use Masthead\Meta;
use Masthead\StageTaxonomy;
use Masthead\Workflows;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CalendarController extends Controller {

	public const int MONTH_LIMIT = 300;

	public const int UNSCHEDULED_PAGE = 20;

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/calendar',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'index' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'month'     => [
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}$',
					],
					'workflows' => [ 'type' => 'string' ],
					'search'    => [ 'type' => 'string' ],
					'author'    => [ 'type' => 'integer' ],
					'page'      => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'only'      => [
						'type' => 'string',
						'enum' => [ 'unscheduled' ],
					],
				],
			]
		);
	}

	/**
	 * Posts with a due date in the month, plus one page of unscheduled posts.
	 * No COUNT queries: one extra row tells whether more exist.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$month = (string) ( $request['month'] ?: wp_date( 'Y-m' ) );
		$start = $month . '-01';
		$end   = gmdate( 'Y-m-t', (int) strtotime( $start ) );
		$page  = max( 1, (int) $request['page'] );

		$keys = array_filter( array_map( 'sanitize_key', explode( ',', (string) $request['workflows'] ) ) );
		$wfs  = array_filter( Workflows::all(), static fn( $wf ) => ! $keys || in_array( $wf->key, $keys, true ) );
		$out  = [
			'month'            => $month,
			'page'             => $page,
			'posts'            => [],
			'posts_more'       => false,
			'unscheduled'      => [],
			'unscheduled_more' => false,
		];
		if ( ! $wfs ) {
			return new WP_REST_Response( $out );
		}
		$types     = array_values( array_unique( array_map( static fn( $wf ) => $wf->post_type, $wfs ) ) );
		$stage_tts = array_merge( ...array_values( array_map( static fn( $wf ) => StageTaxonomy::ids( $wf ), $wfs ) ) );
		$base      = [
			'post_type'           => $types,
			'post_status'         => Cards::STATUSES,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		];
		if ( $request['author'] ) {
			$base['author'] = (int) $request['author'];
		}
		if ( $request['search'] ) {
			$base['s'] = (string) $request['search'];
		}

		if ( 'unscheduled' !== $request['only'] ) {
			$dated             = new WP_Query(
				[
					...$base,
					'posts_per_page' => self::MONTH_LIMIT + 1,
					'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
						[
							'key'     => Meta::DUE,
							'value'   => [ $start, $end ],
							'compare' => 'BETWEEN',
							'type'    => 'DATE',
						],
					],
				]
			);
			$posts             = array_values( array_filter( $dated->posts, static fn( $p ) => $p instanceof WP_Post ) );
			$out['posts_more'] = count( $posts ) > self::MONTH_LIMIT;
			$out['posts']      = Cards::many( array_slice( $posts, 0, self::MONTH_LIMIT ) );
		}

		$undated                 = new WP_Query(
			[
				...$base,
				'post_status'    => [ 'draft', 'pending' ],
				'posts_per_page' => self::UNSCHEDULED_PAGE + 1,
				'offset'         => ( $page - 1 ) * self::UNSCHEDULED_PAGE,
				'orderby'        => 'modified',
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[
						'taxonomy'         => StageTaxonomy::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => $stage_tts ? $stage_tts : [ 0 ],
						'include_children' => false,
					],
				],
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					[
						'key'     => Meta::DUE,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'   => Meta::DUE,
						'value' => '',
					],
				],
			]
		);
		$undated                 = array_values( array_filter( $undated->posts, static fn( $p ) => $p instanceof WP_Post ) );
		$out['unscheduled_more'] = count( $undated ) > self::UNSCHEDULED_PAGE;
		$out['unscheduled']      = Cards::many( array_slice( $undated, 0, self::UNSCHEDULED_PAGE ) );

		return new WP_REST_Response( $out );
	}
}
