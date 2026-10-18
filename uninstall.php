<?php
/**
 * Uninstall: remove options, tables, Masthead meta, notes, capabilities and cron. Posts stay.
 *
 * @package Masthead
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up one site.
 */
function masthead_uninstall_site(): void {
	global $wpdb;

	foreach ( [ 'masthead_workflows', 'masthead_boards', 'masthead_rules', 'masthead_settings', 'masthead_flush_rewrite' ] as $option ) {
		delete_option( $option );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}masthead_notifications" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}masthead_events" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_masthead_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s", 'masthead_color' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_masthead_' ) . '%', $wpdb->esc_like( '_transient_timeout_masthead_' ) . '%' ) );
	// Stage terms of the internal masthead_stage taxonomy.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s",
			'masthead_stage'
		)
	);
	$term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'masthead_stage' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'masthead_stage' ) );
	if ( $term_ids ) {
		$masthead_in = implode( ',', array_map( 'intval', $term_ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ({$masthead_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
	}
	$note_ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = %s", 'masthead_note' ) );
	// phpcs:enable
	foreach ( $note_ids as $id ) {
		wp_delete_comment( (int) $id, true );
	}

	$caps = [ 'masthead_manage_workflows', 'masthead_manage_boards', 'masthead_approve', 'masthead_view' ];
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	wp_clear_scheduled_hook( 'masthead_digest' );
	wp_unschedule_hook( 'masthead_checklist_cleanup' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $masthead_site_id ) {
		switch_to_blog( (int) $masthead_site_id );
		masthead_uninstall_site();
		restore_current_blog();
	}
} else {
	masthead_uninstall_site();
}

// User-level data is network-wide.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'masthead_view_as' ) );
foreach ( get_users(
	[
		'fields'  => 'all',
		'number'  => -1,
		'blog_id' => 0,
	]
) as $masthead_user ) {
	if ( isset( $masthead_user->caps['masthead_view'] ) ) {
		$masthead_user->remove_cap( 'masthead_view' );
	}
}
