<?php
/**
 * Updates from GitHub releases.
 *
 * The plugin's Update URI header points at github.com, so WordPress asks the
 * update_plugins_github.com filter, not WordPress.org, whether there is a new
 * version. The answer is the latest GitHub release and its slim-snippets.zip.
 * WordPress decides how often to check (every 12 hours, or sooner on the
 * Plugins and Updates screens), so nothing is cached here.
 *
 * Loaded in the admin, in WP-Cron and in WP-CLI, where update checks run.
 *
 * @package Slim_Snippets
 */

defined( 'ABSPATH' ) || exit;

const SLIM_SNIPPETS_REPO = 'chrishajer/slim-snippets';

add_filter( 'update_plugins_github.com', 'slim_snippets_update_check', 10, 3 );
add_filter( 'plugins_api', 'slim_snippets_update_details', 10, 3 );

/**
 * The latest release on GitHub. Drafts and pre-releases are never "latest".
 *
 * @return array|null Version, package (zip URL), notes (HTML) and compat
 *                    (tested, requires, requires_php from the release's
 *                    readme.txt), or null when GitHub cannot be reached or
 *                    the release has no zip.
 */
function slim_snippets_latest_release() {
	// GitHub requires a user agent. WordPress's default one includes the site URL.
	$agent    = 'Slim-Snippets/' . SLIM_SNIPPETS_VERSION;
	$response = wp_remote_get(
		'https://api.github.com/repos/' . SLIM_SNIPPETS_REPO . '/releases/latest',
		array(
			'headers'    => array( 'Accept' => 'application/vnd.github.html+json' ),
			'user-agent' => $agent,
		)
	);
	$data     = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['tag_name'] ) || empty( $data['assets'] ) ) {
		return null;
	}

	// Only the zip built for the release, downloaded from this repository.
	// GitHub's source zip unpacks into a folder with the wrong name.
	$prefix  = 'https://github.com/' . SLIM_SNIPPETS_REPO . '/releases/download/';
	$package = '';
	foreach ( (array) $data['assets'] as $asset ) {
		$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
		if ( isset( $asset['name'] ) && 'slim-snippets.zip' === $asset['name'] && 0 === strpos( $url, $prefix ) ) {
			$package = $url;
		}
	}
	if ( ! $package ) {
		return null;
	}

	// WordPress shows "Compatibility with WordPress" and blocks updates that
	// need a newer WordPress or PHP, using these readme.txt headers.
	$readme  = wp_remote_retrieve_body(
		wp_remote_get(
			'https://raw.githubusercontent.com/' . SLIM_SNIPPETS_REPO . '/' . rawurlencode( $data['tag_name'] ) . '/readme.txt',
			array( 'user-agent' => $agent )
		)
	);
	$compat  = array();
	$headers = array(
		'tested'       => 'Tested up to',
		'requires'     => 'Requires at least',
		'requires_php' => 'Requires PHP',
	);
	foreach ( $headers as $key => $label ) {
		if ( preg_match( '/^' . $label . ':\s*([0-9.]+)\s*$/mi', $readme, $match ) ) {
			$compat[ $key ] = $match[1];
		}
	}
	// As on WordPress.org, "Tested up to: 7.1" covers every 7.1.x release.
	$wp = get_bloginfo( 'version' );
	if ( isset( $compat['tested'] ) && 0 === strpos( $wp, $compat['tested'] . '.' ) ) {
		$compat['tested'] = $wp;
	}

	return array(
		'version' => ltrim( (string) $data['tag_name'], 'vV' ),
		'package' => $package,
		'notes'   => isset( $data['body_html'] ) ? (string) $data['body_html'] : '',
		'compat'  => $compat,
	);
}

/**
 * Tell WordPress about the latest release. It is returned even when it is not
 * newer: WordPress then lists the plugin as one that supports updates, which
 * shows the auto-updates toggle and the View details link.
 *
 * @param array|false $update      Update data from an earlier filter, or false.
 * @param array       $plugin_data Plugin headers.
 * @param string      $plugin_file Plugin path relative to the plugins folder.
 * @return array|false
 */
function slim_snippets_update_check( $update, $plugin_data, $plugin_file ) {
	// Other plugins can have a github.com Update URI too.
	if ( plugin_basename( SLIM_SNIPPETS_FILE ) !== $plugin_file ) {
		return $update;
	}
	$release = slim_snippets_latest_release();
	if ( ! $release ) {
		return $update;
	}
	return array(
		'slug'    => 'slim-snippets',
		'version' => $release['version'],
		'package' => $release['package'],
		'url'     => 'https://github.com/' . SLIM_SNIPPETS_REPO,
	) + $release['compat'];
}

/**
 * Fill the View details window from the latest release.
 *
 * @param false|object|array $result Result from an earlier filter.
 * @param string             $action Plugins API action.
 * @param object             $args   Plugins API arguments.
 * @return false|object|array|WP_Error
 */
function slim_snippets_update_details( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'slim-snippets' !== $args->slug ) {
		return $result;
	}
	$release = slim_snippets_latest_release();
	if ( ! $release ) {
		// Not false: WordPress would then show a WordPress.org plugin with the same slug.
		return new WP_Error( 'slim_snippets_github', __( 'Could not get the latest release from GitHub.', 'slim-snippets' ) );
	}
	return (object) ( array(
		'name'          => 'Slim Snippets',
		'slug'          => 'slim-snippets',
		'version'       => $release['version'],
		'author'        => '<a href="https://github.com/chrishajer">Chris Hajer</a>',
		'homepage'      => 'https://github.com/' . SLIM_SNIPPETS_REPO,
		'download_link' => $release['package'],
		// WordPress filters this HTML with wp_kses() before showing it.
		'sections'      => array( 'changelog' => $release['notes'] ),
	) + $release['compat'] );
}
