<?php
/**
 * Block editor integration: assets for the sidebar and pre-publish panel.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead;

final class Editor {

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $screen->post_type || ! Workflows::for_post_type( $screen->post_type ) || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		if ( ! Assets::enqueue( 'editor-plugin' ) ) {
			return;
		}
		$wf = Workflows::for_post_type( $screen->post_type );
		wp_add_inline_script(
			'masthead-editor-plugin',
			'window.mastheadEditor = ' . wp_json_encode(
				[
					'workflow'  => $wf->to_rest(),
					'board'     => Boards::default_for_workflow( $wf->key ),
					'user_data' => People::member( get_current_user_id() ),
					'rules'     => Options::rules(),
					'user'      => [
						'id'      => get_current_user_id(),
						'approve' => current_user_can( Capabilities::APPROVE ),
					],
					'statuses'  => array_combine(
						array_map( static fn( $s ) => $s->value, Model\WpStatus::cases() ),
						array_map( static fn( $s ) => $s->label(), Model\WpStatus::cases() )
					),
					'boardUrl'  => admin_url( 'admin.php?page=masthead' ),
				]
			) . ';',
			'before'
		);
	}
}
