<?php
/**
 * Admin menu, screens and asset loading.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

use Masthead\Model\Stage;
use Masthead\Model\WpStatus;
use Masthead\Rest\BoardsController;

final class Admin {

	public const array PAGES = [
		'masthead'           => 'board',
		'masthead-calendar'  => 'calendar',
		'masthead-workflows' => 'workflows',
		'masthead-setup'     => 'setup',
	];

	public static function menu(): void {
		$render = [ self::class, 'render' ];
		add_menu_page( __( 'Masthead', 'masthead' ), __( 'Masthead', 'masthead' ), Capabilities::VIEW, 'masthead', $render, 'dashicons-columns', 4 );
		add_submenu_page( 'masthead', __( 'Boards', 'masthead' ), __( 'Boards', 'masthead' ), Capabilities::VIEW, 'masthead', $render );
		add_submenu_page( 'masthead', __( 'Calendar', 'masthead' ), __( 'Calendar', 'masthead' ), Capabilities::VIEW, 'masthead-calendar', $render );
		add_submenu_page( 'masthead', __( 'Workflows', 'masthead' ), __( 'Workflows', 'masthead' ), Capabilities::VIEW, 'masthead-workflows', $render );
		add_submenu_page( 'masthead', __( 'Setup', 'masthead' ), __( 'Setup', 'masthead' ), Capabilities::MANAGE_WORKFLOWS, 'masthead-setup', $render );
	}

	public static function is_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return is_admin() && isset( self::PAGES[ $page ] );
	}

	public static function render(): void {
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'masthead'; // phpcs:ignore WordPress.Security.NonceVerification
		$route = self::PAGES[ $page ] ?? 'board';
		printf(
			'<div class="wrap masthead-wrap"><h1 class="screen-reader-text">%s</h1><div id="masthead-root" data-route="%s"><p class="masthead-loading">%s</p></div></div>',
			esc_html__( 'Masthead', 'masthead' ),
			esc_attr( $route ),
			esc_html__( 'Loading…', 'masthead' )
		);
	}

	public static function enqueue(): void {
		if ( ! self::is_screen() ) {
			return;
		}
		Assets::enqueue( 'admin-app', [ 'wp-components' ] );
		wp_add_inline_script( 'masthead-admin-app', 'window.mastheadBootstrap = ' . wp_json_encode( self::bootstrap() ) . ';', 'before' );
	}

	public static function admin_body_class( string $classes ): string {
		return self::is_screen() ? $classes . ' masthead-screen' : $classes;
	}

	/**
	 * Initial data for the admin app and editor plugin.
	 *
	 * @return array<string, mixed>
	 */
	public static function bootstrap(): array {
		$user = People::user( get_current_user_id() );

		$templates = [];
		foreach ( Workflows::templates() as $id => $tpl ) {
			$templates[ $id ] = [
				'id'          => $id,
				'label'       => $tpl['label'],
				'desc'        => $tpl['desc'] ?? '',
				'recommended' => ! empty( $tpl['recommended'] ),
				'stages'      => array_map(
					static fn( array $s ): array => [
						'name'      => $s['name'],
						'color'     => $s['color'],
						'wp_status' => $s['wp_status'],
					],
					$tpl['stages']
				),
			];
		}

		// Only the setup screen shows post counts; skip the query everywhere else.
		$counts = [];
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$setup  = 'masthead-setup' === $page || ! Options::settings()['setup_complete'];
		foreach ( $setup ? (array) wp_count_posts( 'post' ) : [] as $status => $n ) {
			if ( in_array( $status, [ 'draft', 'pending', 'future', 'publish' ], true ) && $n ) {
				$counts[ $status ] = (int) $n;
			}
		}

		$types = [];
		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $pto ) {
			$types[ $pto->name ] = [
				'singular' => (string) $pto->labels->singular_name,
				'plural'   => (string) $pto->labels->name,
			];
		}

		return [
			'user'          => [
				...( $user ?? [] ),
				'caps' => [
					'view'             => current_user_can( Capabilities::VIEW ),
					'approve'          => current_user_can( Capabilities::APPROVE ),
					'manage_boards'    => current_user_can( Capabilities::MANAGE_BOARDS ),
					'manage_workflows' => current_user_can( Capabilities::MANAGE_WORKFLOWS ),
					'edit_others'      => current_user_can( 'edit_others_posts' ),
				],
			],
			'workflows'     => array_values( array_map( static fn( $wf ) => $wf->to_rest(), Workflows::all() ) ),
			'boards'        => array_map( static fn( $b ) => BoardsController::format( $b, false ), Boards::all() ),
			'default_board' => Boards::default_id(),
			'rules'         => Options::rules(),
			'settings'      => Options::settings(),
			'team'          => array_values( array_filter( [ People::member( get_current_user_id() ) ] ) ),
			'templates'     => $templates,
			'post_types'    => $types,
			'statuses'      => array_combine(
				array_map( static fn( WpStatus $s ) => $s->value, WpStatus::cases() ),
				array_map( static fn( WpStatus $s ) => $s->label(), WpStatus::cases() )
			),
			'palette'       => Stage::PALETTE,
			'post_counts'   => (object) $counts,
			'today'         => wp_date( 'Y-m-d' ),
			'start_of_week' => (int) get_option( 'start_of_week', 1 ),
			'admin_url'     => admin_url(),
			'debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
		];
	}
}
