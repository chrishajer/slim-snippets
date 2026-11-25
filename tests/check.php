<?php
/**
 * Self-check for Slim Snippets. Creates snippets, checks the activation gate,
 * the runner index and import/export, then deletes everything it made (on
 * shutdown, so a fatal error mid-run still cleans up).
 *
 * Run on a test site with the plugin active:
 *   wp eval-file tests/check.php --user=<admin>
 *
 * @package Slim_Snippets
 */

// Dev-only script, not shipped in release zips.
// phpcs:disable WordPress.WP.GlobalVariablesOverride, WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.EscapeOutput, WordPress.Arrays.ArrayDeclarationSpacing, Generic.Formatting.MultipleStatementAlignment, WordPress.Arrays.MultipleStatementAlignment

require_once dirname( SLIM_SNIPPETS_FILE ) . '/admin.php';

$slim_made = array();
$slim_fail = 0;

// Delete everything this script made, even if a check dies with a fatal error.
register_shutdown_function(
	function () use ( &$slim_made ) {
		foreach ( $slim_made as $id ) {
			wp_delete_post( $id, true );
		}
		delete_transient( 'slim_snippets_notices_' . get_current_user_id() );
		slim_snippets_rebuild();
		foreach ( array( 'alpha', 'beta', 'One', 'Two' ) as $name ) {
			$term = get_term_by( 'name', $name, 'slim_snippet_tag' );
			if ( $term && 0 === (int) $term->count ) {
				wp_delete_term( $term->term_id, 'slim_snippet_tag' );
			}
		}
	}
);

$check = function ( $ok, $label ) use ( &$slim_fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$slim_fail += $ok ? 0 : 1;
};

$make = function ( $code, $scope = 'global', $extra = array() ) use ( &$slim_made ) {
	$id          = wp_insert_post(
		wp_slash(
			array_merge(
				array(
					'post_type'    => 'slim_snippet',
					'post_status'  => 'draft',
					'post_title'   => 'slim check',
					'post_content' => $code,
					'meta_input'   => array( '_slim_snippets_scope' => $scope ),
				),
				$extra
			)
		)
	);
	$slim_made[] = $id;
	return $id;
};

$latest = function () {
	return (int) get_posts( array( 'post_type' => 'slim_snippet', 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids' ) )[0];
};

$in_index = function ( $id ) {
	return in_array( $id, wp_list_pluck( (array) get_option( 'slim_snippets_active' ), 0 ), true );
};

// 1. Syntax error: not activated, error names the snippet line.
$id = $make( "add_filter( 'slim_check', '__return_true' );\nadd_filter( 'x' " );
list( $state, $text ) = slim_snippets_activate( $id );
$check( 'error' === $state && 'draft' === get_post_status( $id ) && false !== strpos( $text, 'snippet line' ), "syntax error blocks activation ($text)" );

// 2. Top-level throw: not activated.
$id = $make( "throw new RuntimeException( 'boom' );" );
list( $state, $text ) = slim_snippets_activate( $id );
$check( 'error' === $state && false !== strpos( $text, 'boom' ) && get_post_meta( $id, '_slim_snippets_error', true ), "throw blocks activation ($text)" );

// 3. Good snippet: active and indexed; deactivate removes it and stamps the time.
$good = $make( "<?php\nadd_filter( 'slim_check', '__return_true' );\n?>\n", 'global', array( 'post_excerpt' => 'Check <b>desc</b>', 'menu_order' => 7 ) );
wp_set_post_terms( $good, array( 'alpha', 'beta' ), 'slim_snippet_tag' );
$check( 0 === strpos( get_post_field( 'post_content', $good ), 'add_filter' ) && false === strpos( get_post_field( 'post_content', $good ), '?>' ), 'php open and close tags stripped' );
list( $state ) = slim_snippets_activate( $good );
$check( 'ok' === $state && 'publish' === get_post_status( $good ) && $in_index( $good ), 'good snippet activates and is indexed' );
slim_snippets_set_status( $good, 'draft' );
$check( ! $in_index( $good ) && get_post_meta( $good, '_slim_snippets_deactivated', true ), 'deactivate unindexes and stamps time' );
slim_snippets_activate( $good );

// 4. Publishing bad PHP directly (as WP-CLI would) is forced to draft.
$id = $make( 'this is not php', 'global', array( 'post_status' => 'publish' ) );
$check( 'draft' === get_post_status( $id ), 'direct publish of bad code forced to draft' );
$check( 10 === (int) get_post_field( 'menu_order', $id ), 'new snippet without a priority defaults to 10' );

// 5. Code survives kses-style filters byte for byte.
$code = "\$a = '<script>alert(1)</script>' . \"\\n\" . '<a href=\"x\" target=\"_blank\">';";
$id   = $make( $code );
$check( get_post_field( 'post_content', $id ) === $code, 'code saved raw' );

// 6. Single-use runs once and stays inactive.
$id = $make( "echo 'ran-once';", 'single-use' );
list( $state, $text ) = slim_snippets_activate( $id );
$check( 'ran' === $state && 'ran-once' === $text && 'draft' === get_post_status( $id ), 'single-use ran and stayed inactive' );

// 7. CSS is not linted as PHP and is indexed with its scope; priority orders the index.
$css   = $make( 'body { outline: 0; }', 'site-css', array( 'menu_order' => 3 ) );
$late  = $make( '// late', 'global', array( 'menu_order' => 20 ) );
list( $state ) = slim_snippets_activate( $css );
slim_snippets_activate( $late );
$ids   = wp_list_pluck( (array) get_option( 'slim_snippets_active' ), 0 );
$check( 'ok' === $state && array_search( $css, $ids, true ) < array_search( $good, $ids, true ) && array_search( $good, $ids, true ) < array_search( $late, $ids, true ), 'CSS activates; index sorted by priority' );

// 8. Runner keeps a snippet that throws at load time active and records the
// error, so a visitor cannot switch it off. Only this snippet
// runs: the site's own active snippets already ran when WordPress loaded.
$bad      = $make( "throw new LogicException( 'late boom' );" );
$only_bad = function () use ( $bad ) {
	return array( array( $bad, 'global', "throw new LogicException( 'late boom' );" ) );
};
slim_snippets_set_status( $bad, 'publish' );
add_filter( 'pre_option_slim_snippets_active', $only_bad );
slim_snippets_run();
remove_filter( 'pre_option_slim_snippets_active', $only_bad );
$check( 'publish' === get_post_status( $bad ) && false !== strpos( get_post_meta( $bad, '_slim_snippets_error', true ), 'late boom' ), 'runner keeps throwing snippet active, records error' );
slim_snippets_set_status( $bad, 'draft' );

// 9. Export then import round-trips every field; an active snippet comes back inactive.
$export = slim_snippets_export_data( array( $good ) );
list( $state ) = slim_snippets_import_snippet( $export['snippets'][0] );
$copy   = $latest();
$slim_made[] = $copy;
$again  = slim_snippets_export_data( array( $copy ) )['snippets'][0];
$fields = array( 'name', 'desc', 'code', 'tags', 'scope', 'priority' );
$check(
	'imported' === $state && true === $export['snippets'][0]['active'] && false === $again['active'] && ! $in_index( $copy )
	&& array_intersect_key( $again, array_flip( $fields ) ) === array_intersect_key( $export['snippets'][0], array_flip( $fields ) ),
	'export/import round trip; import is inactive'
);

// 10. Code Snippets export: default fields omitted, unsupported and trashed skipped.
$cs = json_decode(
	'{"generator":"Code Snippets v3.9.6","date_created":"2026-07-16 01:37","snippets":[
		{"id":109,"name":"CS sample","desc":"<p>From Code Snippets.</p>","code":"add_filter( \'slim_check_cs\', \'__return_true\' );","tags":["One","Two"],"active":true,"modified":"2026-07-16 02:05:00","revision":"3"},
		{"id":110,"name":"Shortcode","code":"<p>hi</p>","scope":"content"},
		{"id":111,"name":"Old","code":"// old","trashed":true}
	]}',
	true
);
$states = array();
foreach ( $cs['snippets'] as $snippet ) {
	$states[] = slim_snippets_import_snippet( $snippet, true )[0];
}
$cs_id       = $latest();
$slim_made[] = $cs_id;
$got         = slim_snippets_export_data( array( $cs_id ) )['snippets'][0];
$check(
	array( 'imported', 'skipped', 'skipped' ) === $states
	&& 'CS sample' === $got['name'] && 'From Code Snippets.' === $got['desc']
	&& array( 'One', 'Two' ) === $got['tags'] && 'global' === $got['scope'] && 10 === $got['priority']
	&& false === $got['active'] && ! $in_index( $cs_id ) && ! has_filter( 'slim_check_cs' ),
	'Code Snippets import maps fields and defaults, stays inactive, does not run'
);

// 11. Code Snippets description HTML becomes plain text; link URLs are kept.
$html = "<p>Use the <code>gform_x</code> filter &amp; more&nbsp;here.<br />Second line.</p>\n"
	. "<p>See <a href=\"https://example.com/a\">the docs</a> and <a href='https://example.com/b'>https://example.com/b</a>.</p>"
	. '<ul><li>One</li><li><strong>Two</strong></li></ul><p>It&#8217;s &lt;head&gt; time.</p>';
$want = "Use the gform_x filter & more here.\nSecond line.\n\n"
	. "See the docs (https://example.com/a) and https://example.com/b.\n\n"
	. "- One\n- Two\n\nIt\u{2019}s <head> time.";
$text = slim_snippets_html_to_text( $html );
$check( $want === $text, 'description HTML converted to text: ' . wp_json_encode( $text ) );
$check( '' === slim_snippets_html_to_text( '' ) && 'Plain text.' === slim_snippets_html_to_text( 'Plain text.' ), 'empty and plain descriptions unchanged' );
$text = slim_snippets_html_to_text( "Clear the rg_gforms_license_info_<key> cache.\n\nUse <b>bold</b> <pre-wrap> <limit> <li class=\"x\">as-is." );
$check( "Clear the rg_gforms_license_info_<key> cache.\n\nUse bold <pre-wrap> <limit>\n- as-is." === $text, 'typed placeholders kept, only formatting tags stripped: ' . wp_json_encode( $text ) );
$cases = array(
	"<p>Code:</p>\n<pre>if (x) {\n    y();\n}</pre>"                                         => "Code:\n\nif (x) {\n    y();\n}",
	"Steps:<ul>\n<li>a</li>\n<li>b</li>\n</ul>"                                                => "Steps:\n- a\n- b",
	'<ol><li>one<ul><li>sub</li></ul></li><li>two</li></ol>'                                   => "- one\n- sub\n- two",
	'a<hr />b<!-- more --><div>c</div>'                                                        => "a\n\nb\n\nc",
	'<table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr></table><p><small>x</small></p>' => "a b\nc\n\nx",
	'<a href="https://x.test/1" data-href="y">one</a> <a href=https://x.test/2>two</a>'      => 'one (https://x.test/1) two (https://x.test/2)',
	'<a href="https://x.test/b">x.test/b</a> <a href="https://x.test/">opt_<key></a>'         => 'https://x.test/b opt_<key> (https://x.test/)',
	"Intro\n\n<ul>\n<li>a</li>\n</ul>\n\nMore"                                               => "Intro\n\n- a\n\nMore",
	'<ul><li><p>a</p></li><li><p>b</p></li></ul>'                                              => "- a\n\n- b",
);
foreach ( $cases as $in => $want ) {
	$text = slim_snippets_html_to_text( $in );
	$check( $want === $text, 'converts ' . wp_json_encode( $in ) . ': ' . wp_json_encode( $text ) );
}

// 12. The edit screen escapes code and description exactly once, with the visual editor on or off.
$edit_id = $make( 'echo "a < b && c";', 'global', array( 'post_excerpt' => 'x < y & "z"' ) );
$edit_ok = true;
foreach ( array( '__return_true', '__return_false' ) as $rich ) {
	add_filter( 'user_can_richedit', $rich, 99 );
	clean_post_cache( $edit_id );
	ob_start();
	do_action( 'edit_form_after_title', get_post( $edit_id, OBJECT, 'edit' ) );
	$edit_html = ob_get_clean();
	remove_filter( 'user_can_richedit', $rich, 99 );
	$edit_ok = $edit_ok
		&& false !== strpos( $edit_html, '>' . esc_textarea( 'echo "a < b && c";' ) . '</textarea>' )
		&& false !== strpos( $edit_html, '>' . esc_textarea( 'x < y & "z"' ) . '</textarea>' );
}
$check( $edit_ok, 'editor textareas escaped once (visual editor on and off)' );

// 13. Add Snippet (post-new.php) shows the default content and description.
require_once ABSPATH . 'wp-admin/includes/post.php';
$fill = function () {
	return 'echo "pre & filled";';
};
add_filter( 'default_content', $fill );
add_filter( 'default_excerpt', $fill );
$new_post = get_default_post_to_edit( 'slim_snippet', true );
remove_filter( 'default_content', $fill );
remove_filter( 'default_excerpt', $fill );
$slim_made[] = $new_post->ID;
ob_start();
do_action( 'edit_form_after_title', $new_post );
$new_html = ob_get_clean();
$check( 2 === substr_count( $new_html, '>' . esc_textarea( 'echo "pre & filled";' ) . '</textarea>' ), 'new snippet shows default content and description' );

// 14. Descriptions are saved as typed even when kses filters posts (DISALLOW_UNFILTERED_HTML).
kses_init_filters();
slim_snippets_import_snippet( array( 'name' => 'kses', 'desc' => '<p>It&#8217;s &lt;head&gt; time, clear option_&lt;key&gt;</p>', 'code' => '// kses' ), true );
kses_init();
$kses_id     = $latest();
$slim_made[] = $kses_id;
$check( "It\u{2019}s <head> time, clear option_<key>" === get_post_field( 'post_excerpt', $kses_id ), 'kses does not strip the description: ' . wp_json_encode( get_post_field( 'post_excerpt', $kses_id ) ) );

// 15. Revisions keep the raw code and description too, so restoring one is safe under kses.
$rev_id = $make( "echo '<b>x</b>';", 'global', array( 'post_excerpt' => 'old' ) );
kses_init_filters();
wp_update_post( wp_slash( array( 'ID' => $rev_id, 'post_excerpt' => 'new option_<key>' ) ) );
kses_init();
$rev = current( wp_get_post_revisions( $rev_id ) );
$check( $rev && "echo '<b>x</b>';" === $rev->post_content && 'new option_<key>' === $rev->post_excerpt, 'revisions keep raw code and description: ' . wp_json_encode( $rev ? array( $rev->post_content, $rev->post_excerpt ) : null ) );

// 16. On a utf8mb3 column, emoji in the description become entities, as core does.
$utf8 = function ( $charset, $table, $column ) {
	return 'post_excerpt' === $column ? 'utf8' : $charset;
};
add_filter( 'pre_get_col_charset', $utf8, 10, 3 );
$emoji_id = $make( '// emoji', 'global', array( 'post_excerpt' => "Party \u{1F389}" ) );
remove_filter( 'pre_get_col_charset', $utf8, 10 );
$check( 'Party &#x1f389;' === get_post_field( 'post_excerpt', $emoji_id ), 'utf8mb3 description stores emoji as entities: ' . wp_json_encode( get_post_field( 'post_excerpt', $emoji_id ) ) );

// 17. Skip notes name the snippet.
$skip = slim_snippets_import_snippet( array( 'name' => 'My shortcode', 'code' => '<p>x</p>', 'scope' => 'content' ) );
$check( 'skipped' === $skip[0] && 0 === strpos( $skip[1], '"My shortcode": unsupported scope "content"' ), 'skip note names the snippet: ' . $skip[1] );

// 18. Import status: inactive, keep the file's status, or active. Run-once
// snippets never run; broken ones stay inactive with a note.
$file = array(
	'generator' => 'Code Snippets v3.9.6',
	'snippets'  => array(
		array( 'name' => 'imp on', 'code' => "add_filter( 'slim_imp_on', '__return_true' );", 'active' => true ),
		array( 'name' => 'imp off', 'code' => '// off' ),
		array( 'name' => 'imp once', 'code' => "echo 'imp-once-ran';", 'scope' => 'single-use', 'active' => true ),
		array( 'name' => 'imp trashed', 'code' => '// trashed', 'trashed' => true ),
		array( 'name' => 'imp broken', 'code' => 'this is not php', 'active' => true ),
	),
);
$want = array(
	'inactive' => array( 0, 4, 0, 1 ),
	'keep'     => array( 1, 3, 1, 0 ),
	'active'   => array( 2, 2, 0, 1 ),
);
foreach ( $want as $mode => $expect ) {
	$before = $latest();
	ob_start();
	list( $counts, $notes ) = slim_snippets_import_files( array( array( 'snippets.json', $file ), array( 'other.json', array( 'nope' => 1 ) ) ), $mode );
	$out = ob_get_clean();
	foreach ( get_posts( array( 'post_type' => 'slim_snippet', 'post_status' => array( 'publish', 'draft', 'trash' ), 'numberposts' => -1, 'fields' => 'ids' ) ) as $new_id ) {
		if ( $new_id > $before ) {
			$slim_made[] = $new_id;
		}
	}
	$got    = array( $counts['active'], $counts['inactive'], $counts['trash'], $counts['skipped'] - 1 );
	$broken = 'inactive' !== $mode && preg_grep( '/^"imp broken" left inactive: /', $notes );
	$check(
		$expect === $got && '' === $out && ( 'inactive' === $mode || $broken ) && in_array( '"other.json" is not a snippets export.', $notes, true ),
		"import status '$mode': " . wp_json_encode( $got ) . ' ' . wp_json_encode( $notes )
	);
}

// 19. A function or class declared twice is caught before the test run, also
// against an active snippet that does not run on this screen.
$fn1 = $make( 'function slim_check_dupe_fn() { return 1; }' );
$fn2 = $make( 'function slim_check_dupe_fn() { return 2; }' );
$grd = $make( "if ( ! function_exists( 'slim_check_dupe_fn' ) ) {\n\tfunction slim_check_dupe_fn() { return 3; }\n}\n\$a = stdClass::class;\n\$b = new class() {};\n\$c = function () {};\nclass Slim_Check_Class_A { function slim_check_dupe_fn() {} }" );
$fe1 = $make( 'function &slim_check_fe_fn() { static $v = 1; return $v; }', 'front-end' );
$fe2 = $make( 'function slim_check_fe_fn() {}' );
$ns1 = $make( "namespace SlimCheck;\nclass Dupe {}" );
$ns2 = $make( "namespace SlimCheck;\nclass Dupe {}" );
slim_snippets_set_status( $fe1, 'publish' ); // Active, but front-end code never ran here.
$r = array_map(
	function ( $id ) {
		return slim_snippets_activate( $id );
	},
	array( $fn1, $fn2, $grd, $fe2, $ns1, $ns2 )
);
$check(
	'ok' === $r[0][0] && 'error' === $r[1][0] && false !== strpos( $r[1][1], 'Cannot redeclare function slim_check_dupe_fn()' )
	&& 'ok' === $r[2][0] && 'error' === $r[3][0] && false !== strpos( $r[3][1], 'function slim_check_fe_fn()' )
	&& 'ok' === $r[4][0] && 'error' === $r[5][0] && false !== strpos( $r[5][1], 'class SlimCheck\\Dupe' ),
	'redeclared functions and classes blocked: ' . wp_json_encode( wp_list_pluck( $r, 1 ) )
);

// 20. The Snippet box takes the Publish box's place (Screen Options cannot hide it), and there is no Slug box.
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/meta-boxes.php';
set_current_screen( 'slim_snippet' );
register_and_do_post_meta_boxes( get_post( $edit_id ) );
$boxes = $GLOBALS['wp_meta_boxes']['slim_snippet'];
$check(
	isset( $boxes['side']['high']['submitdiv']['callback'] ) && 'slim_snippets_box' === $boxes['side']['high']['submitdiv']['callback']
	&& ! isset( $boxes['side']['core']['submitdiv'] ) && empty( $boxes['normal']['core']['slugdiv'] ),
	'Snippet box replaces the Publish box; no Slug box'
);

// 21. Code Snippets migration: an inactive copy already here is activated, not
// skipped; duplicates in Code Snippets are copied once (active if either is);
// copies that redeclare an active snippet or an earlier copy stay inactive.
$cs_row = function ( $name, $code, $active ) {
	return array( 'name' => $name, 'description' => '', 'code' => $code, 'tags' => '', 'scope' => 'global', 'priority' => 10, 'active' => $active, 'condition_id' => 0 );
};
$cs_old  = $make( '// cs old', 'global', array( 'post_title' => 'cs old' ) );
$cs_live = $make( '// cs live', 'global', array( 'post_title' => 'cs live' ) );
$make( "// a\n// b", 'global', array( 'post_title' => 'cs crlf' ) );
slim_snippets_set_status( $cs_live, 'publish' );
$plan = slim_snippets_cs_plan(
	array(
		$cs_row( 'cs old', '// cs old', 1 ),
		$cs_row( 'cs twin', '// twin', 0 ),
		$cs_row( 'cs twin', '// twin', 1 ),
		$cs_row( 'cs live', '// cs live', 1 ),
		$cs_row( 'cs crlf', "// a\r\n// b\r\n", 1 ),
	)
);
$check(
	$cs_old === $plan[0]['existing'] && 'active' === $plan[0]['result']
	&& 'active' === $plan[1]['result'] && 'skip' === $plan[2]['result']
	&& 'skip' === $plan[3]['result'] && 'already in Slim Snippets' === $plan[3]['note']
	&& 0 < $plan[4]['existing'],
	'migration plan: ' . wp_json_encode( array_map( fn( $i ) => array( $i['result'], $i['note'], $i['existing'] ), $plan ) )
);
$taken = $make( 'function slim_check_cs_taken() {}' );
slim_snippets_set_status( $taken, 'publish' );
$cs = array(
	$make( 'function slim_check_cs_taken() {}' ),
	$make( 'function slim_check_cs_new() {}' ),
	$make( 'function slim_check_cs_new() {}' ),
	$make( 'this is not php' ),
	$make( 'body { color: red; }', 'site-css' ),
);
$errors = array_values( slim_snippets_cs_check( $cs ) );
$check(
	false !== strpos( $errors[0], 'Cannot redeclare function slim_check_cs_taken()' ) && '' === $errors[1]
	&& false !== strpos( $errors[2], 'Cannot redeclare function slim_check_cs_new()' ) && '' !== $errors[3] && '' === $errors[4],
	'migration copies checked for redeclaring: ' . wp_json_encode( $errors )
);

// 22. Updater: the latest release and its zip are offered (also when not newer,
// so WordPress shows the auto-updates toggle), with the readme.txt versions
// ("Tested up to: 7.1" covers the site's 7.1.x); another plugin's github.com
// update, a zip from anywhere else and a failed request are ignored.
$slim_wp     = get_bloginfo( 'version' );
$slim_readme = "Tested up to: " . implode( '.', array_slice( explode( '.', $slim_wp ), 0, 2 ) ) . "\nRequires at least: 6.0\nRequires PHP: 7.4\n";
$slim_github = null;
$mock        = function ( $pre, $args, $url ) use ( &$slim_github, $slim_readme ) {
	if ( 'https://raw.githubusercontent.com/chrishajer/slim-snippets/v9.0.0/readme.txt' === $url ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $slim_readme, 'headers' => array(), 'cookies' => array() );
	}
	return false === strpos( $url, 'api.github.com/repos/chrishajer/slim-snippets/releases/latest' ) ? $pre : $slim_github;
};
$release     = function ( $zip ) {
	$body = array(
		'tag_name'  => 'v9.0.0',
		'body_html' => '<p>notes</p>',
		'assets'    => array( array( 'name' => 'slim-snippets.zip', 'browser_download_url' => $zip ) ),
	);
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$mine        = plugin_basename( SLIM_SNIPPETS_FILE );
$zip         = 'https://github.com/chrishajer/slim-snippets/releases/download/v9.0.0/slim-snippets.zip';
$slim_github = $release( $zip );
$up          = apply_filters( 'update_plugins_github.com', false, array(), $mine, array() );
$info        = slim_snippets_update_details( false, 'plugin_information', (object) array( 'slug' => 'slim-snippets' ) );
$other       = slim_snippets_update_check( false, array(), 'other/other.php' );
$slim_github = $release( 'https://example.com/slim-snippets.zip' );
$foreign     = slim_snippets_update_check( false, array(), $mine );
$slim_github = new WP_Error( 'http_request_failed', 'down' );
$down        = slim_snippets_update_check( false, array(), $mine );
$down_info   = slim_snippets_update_details( false, 'plugin_information', (object) array( 'slug' => 'slim-snippets' ) );
remove_filter( 'pre_http_request', $mock, 10 );
$check(
	is_array( $up ) && '9.0.0' === $up['version'] && $zip === $up['package']
	&& $slim_wp === $up['tested'] && '6.0' === $up['requires'] && '7.4' === $up['requires_php']
	&& '9.0.0' === $info->version && $slim_wp === $info->tested && '<p>notes</p>' === $info->sections['changelog']
	&& false === $other && false === $foreign && false === $down && is_wp_error( $down_info ),
	'updater: ' . wp_json_encode( array( $up, $other, $foreign, $down ) )
);

// 23. Only the activation checks make a snippet active. Publishing or
// scheduling good code with wp_insert_post() (the WordPress importer, WP-CLI),
// changing an active snippet's code with wp_update_post() (another plugin) and
// restoring a revision all leave the snippet inactive and out of the index.
// Tools > Export leaves snippets out.
$gate_new    = $make( '// gate new', 'global', array( 'post_status' => 'publish' ) );
$gate_future = $make( '// gate future', 'global', array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
$gate_live   = $make( '// gate live' );
slim_snippets_activate( $gate_live );
$gate_was    = get_post_status( $gate_live );
wp_update_post( array( 'ID' => $gate_live, 'post_content' => '// gate changed' ) );
$gate_edited = get_post_status( $gate_live );
slim_snippets_activate( $gate_live );
$gate_revs   = wp_get_post_revisions( $gate_live );
$gate_rev    = $gate_revs ? reset( $gate_revs ) : null;
if ( $gate_rev ) {
	wp_restore_post_revision( $gate_rev->ID );
}
$check(
	'draft' === get_post_status( $gate_new ) && 'draft' === get_post_status( $gate_future )
	&& 'publish' === $gate_was && 'draft' === $gate_edited && $gate_rev && 'draft' === get_post_status( $gate_live )
	&& ! $in_index( $gate_new ) && ! $in_index( $gate_future ) && ! $in_index( $gate_live )
	&& ! get_post_type_object( 'slim_snippet' )->can_export,
	'only activation makes a snippet active: ' . wp_json_encode( array( get_post_status( $gate_new ), get_post_status( $gate_future ), $gate_was, $gate_edited, (bool) $gate_rev, get_post_status( $gate_live ) ) )
);

// 24. A notice's items are shown as an escaped bulleted list, between the
// message and the preformatted detail.
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'edit-slim_snippet' );
delete_transient( 'slim_snippets_notices_' . get_current_user_id() );
slim_snippets_notice( 'warning', 'Migrated 3 snippets from Code Snippets:', 'note <b>', array( '2 active', '1 <inactive>' ) );
ob_start();
slim_snippets_admin_notices();
$notice_html = ob_get_clean();
$check(
	false !== strpos( $notice_html, '<p>Migrated 3 snippets from Code Snippets:</p><ul class="ul-disc"><li>2 active</li><li>1 &lt;inactive&gt;</li></ul><pre style="white-space:pre-wrap">note &lt;b&gt;</pre>' ),
	'notice items render as a bulleted list: ' . trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $notice_html ) ) )
);

// 25. Code Snippets snippets that are not migrated because of their scope or
// a condition are flagged for review with their Code Snippets ID, so the
// migration table can link to them. Trashed and duplicate ones are not.
$review_plan = slim_snippets_cs_plan(
	array(
		array_merge( $cs_row( 'cs html', '<p>hi</p>', 1 ), array( 'id' => 41, 'scope' => 'content' ) ),
		array_merge( $cs_row( 'cs cond', '// cond', 1 ), array( 'id' => 42, 'condition_id' => 7 ) ),
		array_merge( $cs_row( 'cs gone', '// gone', -1 ), array( 'id' => 43 ) ),
		array_merge( $cs_row( 'cs fine', '// fine', 1 ), array( 'id' => 44 ) ),
	)
);
$check(
	array( true, true, false, false ) === array_column( $review_plan, 'review' ) && array( 41, 42, 43, 44 ) === array_column( $review_plan, 'id' ),
	'not-migrated snippets flagged for review: ' . wp_json_encode( array_column( $review_plan, 'review' ) )
);

// 26. Active CSS and JavaScript snippets are copied inactive from the free
// Code Snippets plugin, which never ran them, and active from Pro, which did.
$asset_rows = array(
	array_merge( $cs_row( 'cs css', 'body{}', 1 ), array( 'scope' => 'site-css' ) ),
	array_merge( $cs_row( 'cs js', 'void 0;', 1 ), array( 'scope' => 'site-footer-js' ) ),
	array_merge( $cs_row( 'cs css off', 'p{}', 0 ), array( 'scope' => 'admin-css' ) ),
	$cs_row( 'cs php', '// php', 1 ),
);
$free_plan = slim_snippets_cs_plan( $asset_rows, false );
$pro_plan  = slim_snippets_cs_plan( $asset_rows, true );
$check(
	array( 'inactive', 'inactive', 'inactive', 'active' ) === array_column( $free_plan, 'result' )
	&& array( true, true, false, false ) === array_column( $free_plan, 'free_asset' )
	&& array( 'active', 'active', 'inactive', 'active' ) === array_column( $pro_plan, 'result' )
	&& ! array_filter( array_column( $pro_plan, 'free_asset' ) ),
	'free Code Snippets CSS/JS copied inactive: ' . wp_json_encode( array( array_column( $free_plan, 'result' ), array_column( $pro_plan, 'result' ) ) )
);

echo $slim_fail ? "\n$slim_fail FAILED\n" : "\nALL PASSED\n";
