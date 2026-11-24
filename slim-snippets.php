<?php
/**
 * Plugin Name:       Slim Snippets
 * Plugin URI:        https://github.com/chrishajer/slim-snippets
 * Description:       Run PHP, CSS and JavaScript snippets without editing functions.php. Imports Code Snippets exports.
 * Version:           1.0.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Chris Hajer
 * Author URI:        https://github.com/chrishajer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slim-snippets
 * Update URI:        https://github.com/chrishajer/slim-snippets
 *
 * Several ideas, the scope names and the import/export format come from
 * Code Snippets by Shea Bunge / Code Snippets Pro (GPL-2.0-or-later),
 * https://github.com/codesnippetspro/code-snippets. See "Credits" in readme.txt.
 *
 * @package Slim_Snippets
 */

defined( 'ABSPATH' ) || exit;

define( 'SLIM_SNIPPETS_VERSION', '1.0.4' );
define( 'SLIM_SNIPPETS_FILE', __FILE__ );

/**
 * Scopes that print their code into the page instead of running it as PHP,
 * mapped to the hook they print on.
 */
const SLIM_SNIPPETS_OUTPUT_HOOKS = array(
	'site-css'       => 'wp_head',
	'admin-css'      => 'admin_head',
	'site-head-js'   => 'wp_head',
	'site-footer-js' => 'wp_footer',
);

/**
 * Capability needed to manage snippets. On multisite only super admins may
 * run PHP, because site admins do not have unfiltered_html.
 *
 * @return string
 */
function slim_snippets_cap() {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Whether a scope holds PHP (as opposed to CSS or JavaScript).
 *
 * @param string $scope Scope name.
 * @return bool
 */
function slim_snippets_is_php( $scope ) {
	return ! array_key_exists( $scope, SLIM_SNIPPETS_OUTPUT_HOOKS );
}

/**
 * Scope of a snippet.
 *
 * @param int $id Snippet ID.
 * @return string
 */
function slim_snippets_scope( $id ) {
	$scope = get_post_meta( $id, '_slim_snippets_scope', true );
	return $scope ? $scope : 'global';
}

add_action( 'init', 'slim_snippets_register' );

/**
 * Register the snippet post type and its tag taxonomy.
 */
function slim_snippets_register() {
	$cap = slim_snippets_cap();

	register_post_type(
		'slim_snippet',
		array(
			'labels'              => array(
				'name'               => __( 'Snippets', 'slim-snippets' ),
				'singular_name'      => __( 'Snippet', 'slim-snippets' ),
				'add_new'            => __( 'Add Snippet', 'slim-snippets' ),
				'add_new_item'       => __( 'Add Snippet', 'slim-snippets' ),
				'edit_item'          => __( 'Edit Snippet', 'slim-snippets' ),
				'new_item'           => __( 'New Snippet', 'slim-snippets' ),
				'search_items'       => __( 'Search Snippets', 'slim-snippets' ),
				'not_found'          => __( 'No snippets found.', 'slim-snippets' ),
				'not_found_in_trash' => __( 'No snippets found in Trash.', 'slim-snippets' ),
				'all_items'          => __( 'All Snippets', 'slim-snippets' ),
				'menu_name'          => __( 'Snippets', 'slim-snippets' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
			'query_var'           => false,
			'menu_icon'           => 'dashicons-editor-code',
			'supports'            => array( 'title', 'revisions' ),
			'map_meta_cap'        => false,
			'capabilities'        => array_fill_keys(
				array(
					'edit_post',
					'read_post',
					'delete_post',
					'edit_posts',
					'edit_others_posts',
					'edit_private_posts',
					'edit_published_posts',
					'delete_posts',
					'delete_others_posts',
					'delete_private_posts',
					'delete_published_posts',
					'publish_posts',
					'read_private_posts',
					'create_posts',
				),
				$cap
			),
		)
	);

	register_taxonomy(
		'slim_snippet_tag',
		'slim_snippet',
		array(
			'labels'            => array(
				'name'          => __( 'Tags', 'slim-snippets' ),
				'singular_name' => __( 'Tag', 'slim-snippets' ),
			),
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => false,
			'rewrite'           => false,
			'query_var'         => false,
			'capabilities'      => array_fill_keys( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ), $cap ),
		)
	);
}

/**
 * Safe mode: the SLIM_SNIPPETS_SAFE_MODE constant, or ?slim-snippets-safe-mode=1
 * for a user who can manage snippets. Idea from Code Snippets.
 *
 * @return bool
 */
function slim_snippets_safe_mode() {
	if ( defined( 'SLIM_SNIPPETS_SAFE_MODE' ) && SLIM_SNIPPETS_SAFE_MODE ) {
		return true;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only switch, capability checked.
	return ! empty( $_REQUEST['slim-snippets-safe-mode'] ) && current_user_can( slim_snippets_cap() );
}

// Keep safe mode on while clicking around wp-admin. Idea from Code Snippets.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only adds the same flag to links.
if ( ! empty( $_REQUEST['slim-snippets-safe-mode'] ) ) {
	add_filter(
		'admin_url',
		function ( $url, $path ) {
			// Leave bare admin_url() alone: callers append paths to it.
			return '' === $path ? $url : add_query_arg( 'slim-snippets-safe-mode', 1, $url );
		},
		10,
		2
	);
}

/**
 * ID of the snippet being saved from the edit screen in this request, if any.
 * The runner skips it, so re-testing it on save cannot hit "Cannot redeclare".
 * Idea from Code Snippets.
 *
 * @return int
 */
function slim_snippets_editing_id() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- The save itself is nonce-checked; this only skips one snippet for this request.
	if (
		is_admin()
		&& isset( $GLOBALS['pagenow'], $_POST['action'], $_POST['post_type'], $_POST['post_ID'] )
		&& 'post.php' === $GLOBALS['pagenow']
		&& 'editpost' === $_POST['action']
		&& 'slim_snippet' === $_POST['post_type']
		&& current_user_can( slim_snippets_cap() )
	) {
		return (int) $_POST['post_ID'];
	}
	// phpcs:enable
	return 0;
}

// Same timing as Code Snippets, so snippets moved over behave the same.
add_action( 'plugins_loaded', 'slim_snippets_run', 1 );

/**
 * Run the active snippets. They come from one autoloaded option, so this
 * costs no database queries.
 */
function slim_snippets_run() {
	if ( slim_snippets_safe_mode() ) {
		return;
	}

	$skip  = slim_snippets_editing_id();
	$admin = is_admin();
	$hooks = SLIM_SNIPPETS_OUTPUT_HOOKS;

	foreach ( (array) get_option( 'slim_snippets_active', array() ) as list( $id, $scope, $code ) ) {
		if ( $id === $skip ) {
			continue;
		}

		if ( isset( $hooks[ $scope ] ) ) {
			add_action(
				$hooks[ $scope ],
				static function () use ( $id, $scope, $code ) {
					slim_snippets_print( $id, $scope, $code );
				}
			);
			continue;
		}

		if ( ( 'admin' === $scope && ! $admin ) || ( 'front-end' === $scope && $admin ) ) {
			continue;
		}

		// A single-use snippet published outside the edit screen (WP-CLI, say):
		// deactivate first so it runs only once.
		if ( 'single-use' === $scope ) {
			slim_snippets_set_status( $id, 'draft' );
		}

		list( $error ) = slim_snippets_eval( $code );
		if ( $error ) {
			slim_snippets_set_status( $id, 'draft', slim_snippets_describe( $error ) );
		}
	}
}

/**
 * Print a CSS or JavaScript snippet.
 *
 * @param int    $id    Snippet ID.
 * @param string $scope Scope name.
 * @param string $code  CSS or JavaScript.
 */
function slim_snippets_print( $id, $scope, $code ) {
	if ( '-css' === substr( $scope, -4 ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS written by an administrator.
		printf( "<style id=\"slim-snippet-%d\">\n%s\n</style>\n", (int) $id, $code );
	} else {
		wp_print_inline_script_tag( $code, array( 'id' => 'slim-snippet-' . (int) $id ) );
	}
}

/**
 * Run PHP code, discarding or returning its output.
 *
 * @param string $code PHP code without an opening tag.
 * @return array{0: ?Throwable, 1: string} Error, if one was thrown, and the output.
 */
function slim_snippets_eval( $code ) {
	$error = null;
	ob_start();
	try {
		eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Running snippets is what this plugin does.
	} catch ( Throwable $e ) {
		$error = $e;
	}
	return array( $error, (string) ob_get_clean() );
}

/**
 * One-line description of an error, pointing at the snippet line when the
 * error happened in the snippet itself.
 *
 * @param Throwable $e Error.
 * @return string
 */
function slim_snippets_describe( Throwable $e ) {
	$where = false !== strpos( $e->getFile(), "eval()'d code" )
		? 'snippet line ' . $e->getLine()
		: basename( $e->getFile() ) . ':' . $e->getLine();
	return rtrim( $e->getMessage(), '.' ) . ' (' . $where . ')';
}

/**
 * Syntax-check PHP without running it.
 *
 * @param string $code PHP code without an opening tag.
 * @return string Error message, or '' when the code parses.
 */
function slim_snippets_lint( $code ) {
	if ( ! function_exists( 'token_get_all' ) ) {
		return '';
	}
	try {
		token_get_all( "<?php\n" . $code, TOKEN_PARSE );
	} catch ( CompileError $e ) {
		return rtrim( $e->getMessage(), '.' ) . ' (snippet line ' . ( $e->getLine() - 1 ) . ')';
	}
	return '';
}

/**
 * Functions and classes the snippet declares at the top level, keyed
 * "function:name" or "class:name" (lowercase, with the namespace).
 * Declarations inside a block, such as an `if ( ! function_exists() )` guard,
 * are left out because they are conditional.
 *
 * @param string $code Snippet code that parses.
 * @return array<string, string> Key => label such as "function foo()".
 */
function slim_snippets_declarations( $code ) {
	// ponytail: braced namespaces ("namespace X { }") and alternative-syntax ifs
	// are not followed; a redeclaration there still ends in the fatal-error marker.
	$skip   = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG );
	$names  = array( T_STRING, T_NS_SEPARATOR, defined( 'T_NAME_QUALIFIED' ) ? T_NAME_QUALIFIED : T_STRING );
	$kinds  = array( T_CLASS, T_INTERFACE, T_TRAIT, defined( 'T_ENUM' ) ? T_ENUM : T_CLASS );
	$tokens = array();
	foreach ( token_get_all( "<?php\n" . $code ) as $t ) {
		if ( ! is_array( $t ) || ! in_array( $t[0], $skip, true ) ) {
			$tokens[] = is_array( $t ) ? $t : array( $t, $t );
		}
	}

	$found = array();
	$depth = 0;
	$ns    = '';
	foreach ( $tokens as $i => list( $id ) ) {
		if ( '{' === $id || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
			++$depth;
			continue;
		}
		if ( '}' === $id ) {
			--$depth;
			continue;
		}
		$prev = $i ? $tokens[ $i - 1 ][0] : null;
		$next = isset( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ] : array( null, '' );
		if ( $depth || T_USE === $prev ) {
			continue;
		}
		if ( T_NAMESPACE === $id && T_NS_SEPARATOR !== $next[0] ) {
			$ns = '';
			$j  = $i + 1;
			while ( isset( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $names, true ) ) {
				$ns .= $tokens[ $j++ ][1];
			}
			$ns = '' === $ns ? '' : $ns . '\\';
		} elseif ( T_FUNCTION === $id ) {
			// Match the text: PHP 8.1+ gives "&" its own token types.
			$name = '&' === $next[1] && isset( $tokens[ $i + 2 ] ) ? $tokens[ $i + 2 ] : $next;
			if ( T_STRING === $name[0] ) {
				$found[ 'function:' . strtolower( $ns . $name[1] ) ] = 'function ' . $ns . $name[1] . '()';
			}
		} elseif ( in_array( $id, $kinds, true ) && T_STRING === $next[0] && T_DOUBLE_COLON !== $prev && T_NEW !== $prev ) {
			$found[ 'class:' . strtolower( $ns . $next[1] ) ] = $tokens[ $i ][1] . ' ' . $ns . $next[1];
		}
	}
	return $found;
}

/**
 * Whether the snippet declares a function or class that already exists, in
 * the code that is loaded or in any active snippet (an active front-end
 * snippet does not run on admin screens, so function_exists() alone would
 * miss it). Declaring it again is a fatal error that try/catch cannot stop.
 *
 * @param string $code Snippet code that parses.
 * @return string Error message, or '' when nothing is declared twice.
 */
function slim_snippets_redeclared( $code ) {
	$taken = array();
	foreach ( (array) get_option( 'slim_snippets_active', array() ) as list( , $scope, $other ) ) {
		if ( slim_snippets_is_php( $scope ) ) {
			$taken += slim_snippets_declarations( $other );
		}
	}
	foreach ( slim_snippets_declarations( $code ) as $key => $label ) {
		list( $kind, $name ) = explode( ':', $key, 2 );
		$exists              = 'function' === $kind ? function_exists( $name ) : ( class_exists( $name ) || interface_exists( $name ) || trait_exists( $name ) );
		if ( $exists || isset( $taken[ $key ] ) ) {
			/* translators: %s: for example "function my_helper()" or "class My_Class" */
			return sprintf( __( 'Cannot redeclare %s: it already exists in another snippet, a plugin or the theme', 'slim-snippets' ), $label );
		}
	}
	return '';
}

/**
 * Activate a snippet, but only if it parses and runs without an error.
 * A single-use snippet runs once here and stays inactive.
 *
 * A function or class declared twice is caught before the test run. If the
 * code still dies with an uncatchable fatal error, the request ends here: the snippet is still inactive, its code
 * is already saved, and the leftover slim_snippets_testing option is reported
 * on the next visit to a snippets screen.
 *
 * @param int $id Snippet ID.
 * @return array{0: string, 1: string} State ('ok', 'ran' or 'error') and a message or the output.
 */
function slim_snippets_activate( $id ) {
	$post = get_post( $id );
	if ( ! $post || 'slim_snippet' !== $post->post_type || 'trash' === $post->post_status ) {
		return array( 'error', 'Snippet not found.' );
	}
	if ( 'publish' === $post->post_status ) {
		return array( 'ok', '' );
	}

	$scope = slim_snippets_scope( $id );
	if ( slim_snippets_is_php( $scope ) ) {
		$message = slim_snippets_lint( $post->post_content );
		$message = $message ? $message : slim_snippets_redeclared( $post->post_content );
		$output  = '';
		if ( ! $message ) {
			static $shutdown = false;
			if ( ! $shutdown ) {
				register_shutdown_function( 'slim_snippets_activation_shutdown' );
				$shutdown = true;
			}
			update_option( 'slim_snippets_testing', $id, false );
			list( $error, $output ) = slim_snippets_eval( $post->post_content );
			delete_option( 'slim_snippets_testing' );
			$message = $error ? slim_snippets_describe( $error ) : '';
		}

		if ( $message ) {
			update_post_meta( $id, '_slim_snippets_error', $message );
			return array( 'error', $message );
		}
		delete_post_meta( $id, '_slim_snippets_error' );

		if ( 'single-use' === $scope ) {
			return array( 'ran', $output );
		}
	}

	slim_snippets_set_status( $id, 'publish' );
	return array( 'ok', '' );
}

/**
 * Record the message of a fatal error that ended a test run. WordPress's own
 * fatal error handler runs first and does not exit, so this still runs.
 */
function slim_snippets_activation_shutdown() {
	$error = error_get_last();
	$id    = (int) get_option( 'slim_snippets_testing' );
	if ( $id && $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		update_post_meta( $id, '_slim_snippets_error', $error['message'] . ' (fatal error; left inactive)' );
	}
}

/**
 * Change a snippet's status directly, without going through wp_update_post()
 * and its hooks, then rebuild the list of active snippets.
 *
 * @param int    $id     Snippet ID.
 * @param string $status 'publish' (active) or 'draft' (inactive).
 * @param string $error  Error to record, if any.
 */
function slim_snippets_set_status( $id, $status, $error = '' ) {
	global $wpdb;

	if ( 'slim_snippet' !== get_post_type( $id ) ) {
		return;
	}

	$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cache cleared below.
	clean_post_cache( $id );

	if ( 'draft' === $status ) {
		update_post_meta( $id, '_slim_snippets_deactivated', time() );
	}
	if ( $error ) {
		update_post_meta( $id, '_slim_snippets_error', $error );
	}
	slim_snippets_rebuild();
}

register_activation_hook( __FILE__, 'slim_snippets_rebuild' );
add_action( 'save_post_slim_snippet', 'slim_snippets_rebuild', 99, 0 );
add_action(
	'deleted_post',
	function ( $id, $post ) {
		if ( $post && 'slim_snippet' === $post->post_type ) {
			slim_snippets_rebuild();
		}
	},
	10,
	2
);

/**
 * Store the active snippets, in run order, in one autoloaded option.
 */
function slim_snippets_rebuild() {
	$active = array();
	$posts  = get_posts(
		array(
			'post_type'   => 'slim_snippet',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => array(
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			),
		)
	);
	foreach ( $posts as $post ) {
		$active[] = array( $post->ID, slim_snippets_scope( $post->ID ), $post->post_content );
	}
	update_option( 'slim_snippets_active', $active, true );
}

add_filter( 'wp_insert_post_data', 'slim_snippets_insert_data', 10, 3 );

/**
 * Applies to every write (edit screen, WP-CLI, import, revisions): keep the
 * code and description exactly as written, pin the created date, and never
 * publish PHP that does not parse.
 *
 * @param array $data        Slashed post data about to be saved.
 * @param array $postarr     Sanitized post array.
 * @param array $unsanitized Post array as passed in, before sanitizing.
 * @return array
 */
function slim_snippets_insert_data( $data, $postarr, $unsanitized ) {
	global $wpdb;

	// Revisions are copies of a snippet, so they keep the raw code and
	// description too. Restoring one must not bring back filtered text.
	$revision = 'revision' === $data['post_type'] && 'slim_snippet' === get_post_type( $data['post_parent'] );
	if ( 'slim_snippet' !== $data['post_type'] && ! $revision ) {
		return $data;
	}

	// Code is not HTML: skip kses and the other content filters.
	if ( isset( $unsanitized['post_content'] ) ) {
		$code = str_replace( "\r\n", "\n", wp_unslash( $unsanitized['post_content'] ) );
		if ( preg_match( '/^\s*<\?php\s/i', $code ) ) {
			$code = preg_replace( array( '/^\s*<\?php\s+/i', '/\?>\s*$/' ), '', $code );
		}
		$data['post_content'] = wp_slash( $code );
	}

	// The description is plain text and is escaped wherever it is shown. Keep
	// it as typed, so kses (DISALLOW_UNFILTERED_HTML, WP-CLI without --user)
	// cannot delete text like "option_<key>".
	// Like core, emoji become entities where the column is utf8mb3.
	if ( isset( $unsanitized['post_excerpt'] ) ) {
		$desc                 = $unsanitized['post_excerpt'];
		$data['post_excerpt'] = 'utf8' === $wpdb->get_col_charset( $wpdb->posts, 'post_excerpt' ) ? wp_encode_emoji( $desc ) : $desc;
	}

	if ( $revision ) {
		return $data;
	}

	// New snippets default to priority 10, like Code Snippets.
	if ( empty( $postarr['ID'] ) && ! isset( $unsanitized['menu_order'] ) ) {
		$data['menu_order'] = 10;
	}

	// Drafts have a "floating" date that moves on every save. Pin it so the
	// Created column means created.
	if ( '0000-00-00 00:00:00' === $data['post_date_gmt'] && 'auto-draft' !== $data['post_status'] ) {
		$data['post_date_gmt'] = get_gmt_from_date( $data['post_date'] );
	}

	if ( 'publish' === $data['post_status'] ) {
		$scope = isset( $postarr['meta_input']['_slim_snippets_scope'] )
			? $postarr['meta_input']['_slim_snippets_scope']
			: ( empty( $postarr['ID'] ) ? 'global' : slim_snippets_scope( $postarr['ID'] ) );
		if ( slim_snippets_is_php( $scope ) && slim_snippets_lint( wp_unslash( $data['post_content'] ) ) ) {
			$data['post_status'] = 'draft';
		}
	}

	return $data;
}

if ( is_admin() ) {
	require_once __DIR__ . '/admin.php';
}
