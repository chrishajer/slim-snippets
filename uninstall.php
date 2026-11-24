<?php
/**
 * Uninstall: remove the plugin's options. Snippets themselves are kept, so
 * reinstalling and activating the plugin brings them back as they were.
 *
 * @package Slim_Snippets
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'slim_snippets_active' );
delete_option( 'slim_snippets_testing' );
