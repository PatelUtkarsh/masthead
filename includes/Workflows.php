<?php
/**
 * Workflow storage, templates and post type registration.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\Stage;
use Masthead\Model\Workflow;

final class Workflows {

	public const string OPTION = 'masthead_workflows';

	/**
	 * Request cache.
	 *
	 * @var array<string, Workflow>|null
	 */
	private static ?array $cache = null;

	/**
	 * @return array<string, Workflow>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $key => $data ) {
			if ( is_array( $data ) ) {
				$wf              = Workflow::from_array( (string) $key, $data );
				$out[ $wf->key ] = $wf;
			}
		}
		self::$cache = $out;
		return $out;
	}

	public static function get( string $key ): ?Workflow {
		return self::all()[ $key ] ?? null;
	}

	public static function for_post_type( string $post_type ): ?Workflow {
		foreach ( self::all() as $wf ) {
			if ( $wf->post_type === $post_type ) {
				return $wf;
			}
		}
		return null;
	}

	public static function for_post( \WP_Post $post ): ?Workflow {
		return self::for_post_type( $post->post_type );
	}

	/**
	 * @return list<string>
	 */
	public static function post_types(): array {
		return array_values( array_unique( array_map( static fn( Workflow $w ): string => $w->post_type, self::all() ) ) );
	}

	public static function save( Workflow $workflow ): void {
		$raw                   = self::raw();
		$raw[ $workflow->key ] = $workflow->to_array();
		update_option( self::OPTION, $raw, false );
		self::flush();
	}

	public static function delete( string $key ): void {
		$raw = self::raw();
		unset( $raw[ $key ] );
		update_option( self::OPTION, $raw, false );
		self::flush();
	}

	/**
	 * Replace every workflow.
	 *
	 * @param array<string, Workflow> $workflows Workflows keyed by key.
	 */
	public static function replace_all( array $workflows ): void {
		$raw = [];
		foreach ( $workflows as $wf ) {
			$raw[ $wf->key ] = $wf->to_array();
		}
		update_option( self::OPTION, $raw, false );
		self::flush();
	}

	public static function flush(): void {
		self::$cache = null;
		StageTaxonomy::forget();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function raw(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Register post types for workflows that own one. Runs on `init`.
	 */
	public static function register_post_types(): void {
		foreach ( self::all() as $wf ) {
			if ( ! $wf->registers || post_type_exists( $wf->post_type ) ) {
				continue;
			}
			$singular = '' !== $wf->singular ? $wf->singular : $wf->name;
			$plural   = '' !== $wf->plural ? $wf->plural : $wf->name;
			register_post_type(
				$wf->post_type,
				[
					'labels'          => [
						'name'          => $plural,
						'singular_name' => $singular,
						/* translators: %s: singular post type label. */
						'add_new_item'  => sprintf( __( 'Add new %s', 'masthead' ), $singular ),
						/* translators: %s: singular post type label. */
						'edit_item'     => sprintf( __( 'Edit %s', 'masthead' ), $singular ),
					],
					'public'          => true,
					'show_in_rest'    => true,
					'has_archive'     => true,
					'menu_icon'       => 'dashicons-media-document',
					'rewrite'         => [ 'slug' => $wf->post_type ],
					'supports'        => [ 'title', 'editor', 'author', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ],
					'taxonomies'      => [ 'category' ],
					'capability_type' => 'post',
				]
			);
		}
	}

	/**
	 * Unique post type slug for a new workflow.
	 */
	public static function post_type_slug( string $singular ): string {
		$base = substr( str_replace( '-', '_', sanitize_key( sanitize_title( $singular ) ) ), 0, 20 );
		if ( '' === $base ) {
			$base = 'masthead_type';
		}
		$slug = $base;
		$n    = 2;
		while ( post_type_exists( $slug ) || null !== self::for_post_type( $slug ) ) {
			$suffix = '_' . ( $n++ );
			$slug   = substr( $base, 0, 20 - strlen( $suffix ) ) . $suffix;
		}
		return $slug;
	}

	/**
	 * Unique workflow key.
	 */
	public static function unique_key( string $name ): string {
		$base = sanitize_key( sanitize_title( $name ) );
		$base = '' !== $base ? $base : 'workflow';
		$key  = $base;
		$n    = 2;
		while ( null !== self::get( $key ) ) {
			$key = $base . '-' . ( $n++ );
		}
		return $key;
	}

	/**
	 * Stage chains for presets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function templates(): array {
		$s = static fn( string $id, string $name, string $color, string $wp, array $o = [] ): array => [
			'id'        => $id,
			'name'      => $name,
			'color'     => $color,
			'wp_status' => $wp,
			...$o,
		];

		return [
			'simple'     => [
				'label'     => __( 'Simple', 'masthead' ),
				'desc'      => __( 'For a solo writer or a small blog. No approval step.', 'masthead' ),
				'checklist' => [ __( 'Featured image set', 'masthead' ), __( 'Links checked', 'masthead' ), __( 'Copy edited', 'masthead' ) ],
				'stages'    => [
					$s( 'draft', __( 'Draft', 'masthead' ), '#d97706', 'draft' ),
					$s( 'ready', __( 'Ready', 'masthead' ), '#0d9488', 'draft' ),
					$s( 'published', __( 'Published', 'masthead' ), '#16a34a', 'publish' ),
				],
			],
			'team'       => [
				'label'       => __( 'Editorial team', 'masthead' ),
				'desc'        => __( 'Writers pitch and draft, editors review and schedule.', 'masthead' ),
				'recommended' => true,
				'checklist'   => [ __( 'Outline approved', 'masthead' ), __( 'Featured image set', 'masthead' ), __( 'SEO title & meta', 'masthead' ), __( 'Links checked', 'masthead' ), __( 'Copy edited', 'masthead' ) ],
				'stages'      => [
					$s( 'idea', __( 'Ideas', 'masthead' ), '#8c8f94', 'draft' ),
					$s( 'draft', __( 'Drafting', 'masthead' ), '#d97706', 'draft' ),
					$s(
						'review',
						__( 'In review', 'masthead' ),
						'#3858e9',
						'pending',
						[
							'limit'      => 3,
							'limit_mode' => 'block',
							'notify'     => 'editors',
							'approval'   => true,
							'required'   => true,
						]
					),
					$s( 'ready', __( 'Ready', 'masthead' ), '#0d9488', 'draft', [ 'notify' => 'assignee' ] ),
					$s( 'scheduled', __( 'Scheduled', 'masthead' ), '#7c3aed', 'future' ),
					$s( 'published', __( 'Published', 'masthead' ), '#16a34a', 'publish', [ 'notify' => 'everyone' ] ),
				],
			],
			'news'       => [
				'label'     => __( 'Newsroom', 'masthead' ),
				'desc'      => __( 'Pitches, assignments, a copy desk and legal review.', 'masthead' ),
				'checklist' => [ __( 'Sources verified', 'masthead' ), __( 'Featured image set', 'masthead' ), __( 'Headline approved', 'masthead' ), __( 'Copy edited', 'masthead' ) ],
				'stages'    => [
					$s( 'pitch', __( 'Pitch', 'masthead' ), '#8c8f94', 'draft' ),
					$s( 'assigned', __( 'Assigned', 'masthead' ), '#0284c7', 'draft', [ 'notify' => 'assignee' ] ),
					$s( 'drafting', __( 'Drafting', 'masthead' ), '#d97706', 'draft' ),
					$s(
						'edit',
						__( 'Edit', 'masthead' ),
						'#3858e9',
						'pending',
						[
							'notify'   => 'editors',
							'approval' => true,
							'required' => true,
						]
					),
					$s(
						'legal',
						__( 'Legal', 'masthead' ),
						'#db2777',
						'pending',
						[
							'notify'   => 'editors',
							'approval' => true,
							'required' => true,
						]
					),
					$s( 'published', __( 'Published', 'masthead' ), '#16a34a', 'publish', [ 'notify' => 'everyone' ] ),
				],
			],
			'review'     => [
				'label'     => __( 'Simple', 'masthead' ),
				'desc'      => __( 'Draft → In review → Published', 'masthead' ),
				'checklist' => [ __( 'Links checked', 'masthead' ), __( 'Copy edited', 'masthead' ) ],
				'stages'    => [
					$s( 'draft', __( 'Draft', 'masthead' ), '#d97706', 'draft' ),
					$s(
						'review',
						__( 'In review', 'masthead' ),
						'#3858e9',
						'pending',
						[
							'notify'   => 'editors',
							'approval' => true,
							'required' => true,
						]
					),
					$s( 'published', __( 'Published', 'masthead' ), '#16a34a', 'publish' ),
				],
			],
			'pages'      => [
				'label'     => __( 'Pages', 'masthead' ),
				'checklist' => [ __( 'Legal sign-off', 'masthead' ), __( 'Links checked', 'masthead' ), __( 'Copy edited', 'masthead' ) ],
				'stages'    => [
					$s( 'draft', __( 'Draft', 'masthead' ), '#d97706', 'draft' ),
					$s(
						'legal',
						__( 'Legal review', 'masthead' ),
						'#db2777',
						'pending',
						[
							'notify'   => 'editors',
							'approval' => true,
							'required' => true,
						]
					),
					$s( 'published', __( 'Published', 'masthead' ), '#16a34a', 'publish' ),
				],
			],
			'newsletter' => [
				'label'     => __( 'Newsletter', 'masthead' ),
				'checklist' => [ __( 'Subject line written', 'masthead' ), __( 'Preview text set', 'masthead' ), __( 'Links checked', 'masthead' ), __( 'Test send', 'masthead' ) ],
				'stages'    => [
					$s( 'draft', __( 'Draft', 'masthead' ), '#d97706', 'draft' ),
					$s(
						'edit',
						__( 'Edit', 'masthead' ),
						'#3858e9',
						'pending',
						[
							'notify'   => 'editors',
							'approval' => true,
							'required' => true,
						]
					),
					$s( 'scheduled', __( 'Scheduled', 'masthead' ), '#7c3aed', 'future' ),
					$s( 'sent', __( 'Sent', 'masthead' ), '#16a34a', 'publish', [ 'notify' => 'everyone' ] ),
				],
			],
		];
	}

	/**
	 * Build a workflow from a template.
	 *
	 * @param array<string, mixed> $props Workflow properties (name, post_type, color, singular, plural, registers).
	 */
	public static function from_template( string $template, string $key, array $props ): Workflow {
		$tpl = self::templates()[ $template ] ?? self::templates()['simple'];
		return Workflow::from_array(
			$key,
			[
				'gate'      => true,
				'checklist' => array_map(
					static fn( string $label ): array => [
						'id'    => 'c_' . sanitize_key( sanitize_title( $label ) ),
						'label' => $label,
					],
					$tpl['checklist']
				),
				'stages'    => $tpl['stages'],
				...$props,
			]
		);
	}

	/**
	 * Default workflows installed on first activation.
	 *
	 * @return array<string, Workflow>
	 */
	public static function defaults(): array {
		return [
			'articles' => self::from_template(
				'team',
				'articles',
				[
					'name'      => __( 'Articles', 'masthead' ),
					'post_type' => 'post',
					'color'     => '#3858e9',
					'singular'  => __( 'Post', 'masthead' ),
					'plural'    => __( 'Posts', 'masthead' ),
				]
			),
		];
	}

	/**
	 * Colour for a new workflow.
	 */
	public static function next_color(): string {
		return Stage::PALETTE[ ( count( self::all() ) + 3 ) % count( Stage::PALETTE ) ];
	}
}
