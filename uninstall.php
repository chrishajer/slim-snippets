<?php
/**
 * Uninstall: remove the plugin's options. Snippets themselves are kept, so
 * reinstalling and activating the plugin brings them back as they were,
 * unless "Delete all snippets and tags when the plugin is deleted" is on in
 * Snippets > Settings. On multisite each site has its own setting.
 *
 * @package Slim_Snippets
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete the current site's snippets, tags and options if its setting asks
 * for that.
 */
function slim_snippets_uninstall_site() {
	global $wpdb;
	if ( ! get_option( 'slim_snippets_delete_data' ) ) {
		return;
	}
	// The plugin is not loaded here. The taxonomy is needed for get_terms(), and
	// for wp_delete_post() to remove each snippet's tag links.
	taxonomy_exists( 'slim_snippet_tag' ) || register_taxonomy( 'slim_snippet_tag', 'slim_snippet' );
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'slim_snippet'" ) as $id ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall; every status, Trash included.
		wp_delete_post( (int) $id, true ); // Also deletes its meta and revisions.
	}
	$terms = get_terms(
		array(
			'taxonomy'   => 'slim_snippet_tag',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
		wp_delete_term( $term, 'slim_snippet_tag' );
	}
	foreach ( array( 'slim_snippets_active', 'slim_snippets_testing', 'slim_snippets_version', 'slim_snippets_delete_data' ) as $option ) {
		delete_option( $option );
	}
}

// ponytail: one switch_to_blog() per site, which is fine once, at uninstall.
foreach ( is_multisite() ? get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
) : array( 0 ) as $slim_snippets_site ) {
	$slim_snippets_site && switch_to_blog( $slim_snippets_site );
	slim_snippets_uninstall_site();
	$slim_snippets_site && restore_current_blog();
}

// The current site's options always go. Other sites keep their list of active
// snippets: activating the plugin again rebuilds it only on the current site.
delete_option( 'slim_snippets_active' );
delete_option( 'slim_snippets_testing' );
delete_option( 'slim_snippets_version' );
delete_option( 'slim_snippets_delete_data' );
