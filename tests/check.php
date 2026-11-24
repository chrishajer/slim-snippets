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

// 8. Runner deactivates a snippet that throws at load time. Only this snippet
// runs: the site's own active snippets already ran when WordPress loaded.
$bad      = $make( "throw new LogicException( 'late boom' );" );
$only_bad = function () use ( $bad ) {
	return array( array( $bad, 'global', "throw new LogicException( 'late boom' );" ) );
};
slim_snippets_set_status( $bad, 'publish' );
add_filter( 'pre_option_slim_snippets_active', $only_bad );
slim_snippets_run();
remove_filter( 'pre_option_slim_snippets_active', $only_bad );
$check( 'draft' === get_post_status( $bad ) && false !== strpos( get_post_meta( $bad, '_slim_snippets_error', true ), 'late boom' ), 'runner deactivates throwing snippet' );

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

// 17. The Snippet box takes the Publish box's place (Screen Options cannot hide it), and there is no Slug box.
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

echo $slim_fail ? "\n$slim_fail FAILED\n" : "\nALL PASSED\n";
