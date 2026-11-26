<?php
/**
 * Admin screens: editor, list table, row and bulk actions, import and export.
 *
 * @package Slim_Snippets
 */

defined( 'ABSPATH' ) || exit;

// A "Snippets" link before Deactivate on the Plugins screen.
add_filter(
	'plugin_action_links_' . plugin_basename( SLIM_SNIPPETS_FILE ),
	function ( $links ) {
		if ( current_user_can( slim_snippets_cap() ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=slim_snippet' ) ), esc_html__( 'Snippets', 'slim-snippets' ) ) );
		}
		return $links;
	}
);

/**
 * Scope names and labels. The names match Code Snippets, so imports map 1:1.
 *
 * @return array<string, string>
 */
function slim_snippets_scopes() {
	return array(
		'global'         => __( 'PHP: everywhere', 'slim-snippets' ),
		'admin'          => __( 'PHP: admin only', 'slim-snippets' ),
		'front-end'      => __( 'PHP: front-end only', 'slim-snippets' ),
		'single-use'     => __( 'PHP: run once', 'slim-snippets' ),
		'site-css'       => __( 'CSS: front-end', 'slim-snippets' ),
		'admin-css'      => __( 'CSS: admin', 'slim-snippets' ),
		'site-head-js'   => __( 'JS: front-end head', 'slim-snippets' ),
		'site-footer-js' => __( 'JS: front-end footer', 'slim-snippets' ),
	);
}

/**
 * Whether the current admin screen belongs to this plugin.
 *
 * @return bool
 */
function slim_snippets_is_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && 'slim_snippet' === $screen->post_type;
}

/*
 * Notices.
 */

/**
 * Queue a notice for the current user's next snippets screen.
 *
 * @param string          $type    success, error, warning or info.
 * @param string          $message Message text.
 * @param string|string[] $detail  Optional detail after the items: a string is
 *                                 shown preformatted (a snippet's output), a
 *                                 list of lines as a bulleted list (import notes).
 * @param string[]        $items   Optional lines shown as a bulleted list after the message.
 */
function slim_snippets_notice( $type, $message, $detail = '', $items = array() ) {
	$key       = 'slim_snippets_notices_' . get_current_user_id();
	$notices   = get_transient( $key );
	$notices   = is_array( $notices ) ? $notices : array();
	$notices[] = array( $type, $message, $detail, $items );
	set_transient( $key, $notices, HOUR_IN_SECONDS );
}

/**
 * Turn an activation result into notices.
 *
 * @param int   $id     Snippet ID.
 * @param array $result Result of slim_snippets_activate().
 * @return bool Whether the snippet is now active.
 */
function slim_snippets_report( $id, $result ) {
	list( $state, $text ) = $result;
	$name                 = get_post_field( 'post_title', $id );
	$name                 = '' === $name ? '#' . $id : $name;

	if ( 'error' === $state ) {
		/* translators: 1: snippet name, 2: error message */
		slim_snippets_notice( 'error', sprintf( __( '"%1$s" was not activated: %2$s', 'slim-snippets' ), $name, $text ) );
	} elseif ( 'ran' === $state ) {
		/* translators: %s: snippet name */
		slim_snippets_notice( 'success', sprintf( __( '"%s" ran once and stays inactive.', 'slim-snippets' ), $name ), $text );
	}
	return 'ok' === $state;
}

add_action( 'admin_notices', 'slim_snippets_admin_notices' );

/**
 * Show safe mode on every screen; show queued notices on snippets screens.
 */
function slim_snippets_admin_notices() {
	if ( slim_snippets_safe_mode() && current_user_can( slim_snippets_cap() ) ) {
		$off = defined( 'SLIM_SNIPPETS_SAFE_MODE' ) && SLIM_SNIPPETS_SAFE_MODE
			? esc_html__( 'Remove SLIM_SNIPPETS_SAFE_MODE from wp-config.php to turn it off.', 'slim-snippets' )
			: sprintf( '<a href="%s">%s</a>', esc_url( remove_query_arg( 'slim-snippets-safe-mode' ) ), esc_html__( 'Turn off safe mode', 'slim-snippets' ) );
		printf(
			'<div class="notice notice-warning"><p>%s %s</p></div>',
			esc_html__( 'Slim Snippets safe mode is on: snippets are not running.', 'slim-snippets' ),
			$off // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		);
	}

	// While Code Snippets is active, point to the migration (not on the page that has it).
	$screen = get_current_screen();
	if ( defined( 'CODE_SNIPPETS_FILE' ) && $screen && current_user_can( slim_snippets_cap() ) && current_user_can( 'activate_plugins' ) && ( 'plugins' === $screen->id || ( slim_snippets_is_screen() && false === strpos( $screen->id, 'slim-snippets-import' ) ) ) ) {
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'The Code Snippets plugin is active. Slim Snippets can copy its snippets and deactivate Code Snippets in one step.', 'slim-snippets' ),
			esc_url( admin_url( 'edit.php?post_type=slim_snippet&page=slim-snippets-import#migrate' ) ),
			esc_html__( 'Review and migrate', 'slim-snippets' )
		);
	}

	if ( ! slim_snippets_is_screen() ) {
		return;
	}

	// A test run that died with a fatal error leaves this option behind.
	$id = (int) get_option( 'slim_snippets_testing' );
	if ( $id ) {
		delete_option( 'slim_snippets_testing' );
		if ( ! get_post_meta( $id, '_slim_snippets_error', true ) ) {
			update_post_meta( $id, '_slim_snippets_error', __( 'Fatal error while activating; left inactive. See the PHP error log.', 'slim-snippets' ) );
		}
		/* translators: %s: snippet name */
		slim_snippets_notice( 'error', sprintf( __( '"%s" caused a fatal error while activating and was left inactive.', 'slim-snippets' ), get_post_field( 'post_title', $id ) ), get_post_meta( $id, '_slim_snippets_error', true ) );
	}

	$key     = 'slim_snippets_notices_' . get_current_user_id();
	$notices = get_transient( $key );
	if ( ! is_array( $notices ) ) {
		return;
	}
	delete_transient( $key );

	foreach ( $notices as $notice ) {
		list( $type, $message, $detail, $items ) = array_pad( $notice, 4, array() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $type ), esc_html( $message ) );
		// The items, then a list-type detail (import and migration notes), as
		// bulleted lists. A string detail, such as a snippet's output, stays
		// preformatted.
		foreach ( array( $items, is_array( $detail ) ? $detail : array() ) as $list ) {
			if ( $list ) {
				echo '<ul class="ul-disc">';
				foreach ( $list as $line ) {
					printf( '<li>%s</li>', esc_html( $line ) );
				}
				echo '</ul>';
			}
		}
		if ( is_string( $detail ) && '' !== $detail ) {
			printf( '<pre style="white-space:pre-wrap">%s</pre>', esc_html( $detail ) );
		}
		echo '</div>';
	}
}

/*
 * Edit screen.
 */

add_action(
	'add_meta_boxes_slim_snippet',
	function () {
		// Reuse the Publish box's ID: Screen Options never lists it, so the
		// Save buttons cannot be hidden. A new priority replaces the core box
		// (the same 'core' priority would keep it). Snippets have no URL, so no
		// Slug box.
		add_meta_box( 'submitdiv', __( 'Snippet', 'slim-snippets' ), 'slim_snippets_box', 'slim_snippet', 'side', 'high' );
		remove_meta_box( 'slugdiv', 'slim_snippet', 'normal' );
	}
);

add_action(
	'edit_form_after_title',
	function ( $post ) {
		if ( 'slim_snippet' !== $post->post_type ) {
			return;
		}
		// On post.php, $post is already escaped for editing (always the excerpt,
		// and the content when the visual editor is off), so take a raw copy and
		// escape each value once. On post-new.php, $post is raw already and holds
		// the default content and excerpt, so filter() returns it unchanged.
		$raw  = $post->filter( 'raw' );
		$code = $raw->post_content;
		$desc = $raw->post_excerpt;
		?>
		<div id="slim-snippets-editor">
			<p><label for="slim-snippets-code"><strong><?php esc_html_e( 'Code', 'slim-snippets' ); ?></strong></label></p>
			<textarea id="slim-snippets-code" name="content" class="widefat code" rows="20" spellcheck="false"><?php echo esc_textarea( $code ); ?></textarea>
			<p><label for="slim-snippets-desc"><strong><?php esc_html_e( 'Description', 'slim-snippets' ); ?></strong></label></p>
			<textarea id="slim-snippets-desc" name="excerpt" class="widefat" rows="7"><?php echo esc_textarea( $desc ); ?></textarea>
		</div>
		<?php
	}
);

/**
 * Side box: scope, priority, status and the save buttons. Replaces the
 * Publish box, whose Publish/Draft wording does not fit snippets.
 *
 * @param WP_Post $post Snippet.
 */
function slim_snippets_box( $post ) {
	$scope    = slim_snippets_scope( $post->ID );
	$active   = 'publish' === $post->post_status;
	$priority = 'auto-draft' === $post->post_status ? 10 : (int) $post->menu_order;
	$error    = get_post_meta( $post->ID, '_slim_snippets_error', true );

	wp_nonce_field( 'slim_snippets_save', 'slim_snippets_nonce' );
	?>
	<p>
		<label for="slim-snippets-scope"><strong><?php esc_html_e( 'Scope', 'slim-snippets' ); ?></strong></label><br>
		<select id="slim-snippets-scope" name="slim_snippets_scope">
			<?php foreach ( slim_snippets_scopes() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $scope, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="slim-snippets-priority"><strong><?php esc_html_e( 'Priority', 'slim-snippets' ); ?></strong></label><br>
		<input type="number" id="slim-snippets-priority" name="menu_order" value="<?php echo esc_attr( $priority ); ?>" min="0" step="1" class="small-text">
		<span class="description"><?php esc_html_e( 'Lower runs sooner.', 'slim-snippets' ); ?></span>
	</p>
	<p>
		<?php esc_html_e( 'Status:', 'slim-snippets' ); ?>
		<strong><?php echo $active ? esc_html__( 'Active', 'slim-snippets' ) : esc_html__( 'Inactive', 'slim-snippets' ); ?></strong>
	</p>
	<?php if ( $error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>
	<p>
		<button type="submit" name="slim_snippets_action" value="save" class="button"><?php esc_html_e( 'Save', 'slim-snippets' ); ?></button>
		<?php if ( $active ) : ?>
			<button type="submit" name="slim_snippets_action" value="deactivate" class="button"><?php esc_html_e( 'Save & Deactivate', 'slim-snippets' ); ?></button>
		<?php elseif ( 'single-use' === $scope ) : ?>
			<button type="submit" name="slim_snippets_action" value="activate" class="button button-primary"><?php esc_html_e( 'Save & Run Once', 'slim-snippets' ); ?></button>
		<?php else : ?>
			<button type="submit" name="slim_snippets_action" value="activate" class="button button-primary"><?php esc_html_e( 'Save & Activate', 'slim-snippets' ); ?></button>
		<?php endif; ?>
	</p>
	<?php if ( 'auto-draft' !== $post->post_status ) : ?>
		<p><a class="submitdelete" style="color:#b32d2e" href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Move to Trash', 'slim-snippets' ); ?></a></p>
	<?php endif; ?>
	<?php
}

/**
 * Whether this request is a valid edit-screen save of the given snippet.
 *
 * @param int $id Snippet ID.
 * @return bool
 */
function slim_snippets_form_ok( $id ) {
	return isset( $_POST['slim_snippets_nonce'], $_POST['post_ID'] )
		&& (int) $_POST['post_ID'] === (int) $id
		&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['slim_snippets_nonce'] ) ), 'slim_snippets_save' )
		&& current_user_can( slim_snippets_cap() );
}

// Edit-screen saves always write the snippet as inactive first, so the code is
// stored even if testing it kills the request. save_post then activates it.
add_filter(
	'wp_insert_post_data',
	function ( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( 'slim_snippet' !== $data['post_type'] || ! slim_snippets_form_ok( $id ) ) {
			return $data;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in slim_snippets_form_ok().
		$action     = isset( $_POST['slim_snippets_action'] ) ? sanitize_key( wp_unslash( $_POST['slim_snippets_action'] ) ) : 'save';
		$was_active = 'publish' === get_post_status( $id );

		$GLOBALS['slim_snippets_pending'][ $id ] = array(
			'activate'   => 'activate' === $action || ( 'save' === $action && $was_active ),
			'was_active' => $was_active,
		);

		$data['post_status'] = 'draft';
		return $data;
	},
	20,
	2
);

add_action(
	'save_post_slim_snippet',
	function ( $id ) {
		if ( ! slim_snippets_form_ok( $id ) || ! isset( $GLOBALS['slim_snippets_pending'][ $id ] ) ) {
			return;
		}
		$pending = $GLOBALS['slim_snippets_pending'][ $id ];
		unset( $GLOBALS['slim_snippets_pending'][ $id ] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in slim_snippets_form_ok().
		$scope = isset( $_POST['slim_snippets_scope'] ) ? sanitize_key( wp_unslash( $_POST['slim_snippets_scope'] ) ) : 'global';
		update_post_meta( $id, '_slim_snippets_scope', array_key_exists( $scope, slim_snippets_scopes() ) ? $scope : 'global' );
		delete_post_meta( $id, '_slim_snippets_error' );

		if ( $pending['was_active'] ) {
			update_post_meta( $id, '_slim_snippets_deactivated', time() );
		}
		// Only say so when the status changed; re-saving an active snippet just says "Snippet saved".
		if ( $pending['activate'] ) {
			if ( slim_snippets_report( $id, slim_snippets_activate( $id ) ) && ! $pending['was_active'] ) {
				slim_snippets_notice( 'success', __( 'Snippet is active.', 'slim-snippets' ) );
			}
		} elseif ( $pending['was_active'] ) {
			slim_snippets_notice( 'success', __( 'Snippet is inactive.', 'slim-snippets' ) );
		}
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! slim_snippets_is_screen() ) {
			return;
		}

		// Autosave would read an empty #content and could overwrite the code.
		wp_dequeue_script( 'autosave' );

		// Core removes the padding of the Publish box (#submitdiv), whose ID the
		// Snippet box reuses. Give it the padding the other boxes have.
		wp_add_inline_style( 'wp-admin', '#poststuff #submitdiv .inside{margin:6px 0 0;padding:0 12px 12px}' );

		$settings = wp_enqueue_code_editor( array( 'type' => 'text/x-php' ) );
		if ( false === $settings ) {
			return; // The user turned syntax highlighting off: plain textarea.
		}
		wp_add_inline_script(
			'code-editor',
			sprintf(
				'jQuery( function ( $ ) {
					var modes = { "site-css": "css", "admin-css": "css", "site-head-js": "javascript", "site-footer-js": "javascript" };
					var editor = wp.codeEditor.initialize( "slim-snippets-code", %1$s ).codemirror;
					var labels = %2$s;
					var scope = $( "#slim-snippets-scope" );
					function sync() {
						editor.setOption( "mode", modes[ scope.val() ] || "text/x-php" );
						$( "#submitdiv button[value=activate]" ).text( labels[ "single-use" === scope.val() ? 1 : 0 ] );
					}
					scope.on( "change", sync );
					sync();
				} );',
				wp_json_encode( $settings ),
				wp_json_encode( array( __( 'Save & Activate', 'slim-snippets' ), __( 'Save & Run Once', 'slim-snippets' ) ) )
			)
		);
		wp_add_inline_style( 'code-editor', '#slim-snippets-editor{margin-top:1em}#slim-snippets-editor .CodeMirror{height:60vh;min-height:300px;border:1px solid #c3c4c7}' );
	}
);

add_filter(
	'post_updated_messages',
	function ( $messages ) {
		$messages['slim_snippet'] = array_fill( 0, 11, __( 'Snippet saved.', 'slim-snippets' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$messages['slim_snippet'][5] = isset( $_GET['revision'] )
			/* translators: %s: date and time of the revision */
			? sprintf( __( 'Snippet restored to revision from %s.', 'slim-snippets' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: false;
		return $messages;
	}
);

add_filter(
	'bulk_post_updated_messages',
	function ( $messages, $counts ) {
		$messages['slim_snippet'] = array(
			/* translators: %s: number of snippets */
			'updated'   => _n( '%s snippet updated.', '%s snippets updated.', $counts['updated'], 'slim-snippets' ),
			/* translators: %s: number of snippets */
			'locked'    => _n( '%s snippet not updated, somebody is editing it.', '%s snippets not updated, somebody is editing them.', $counts['locked'], 'slim-snippets' ),
			/* translators: %s: number of snippets */
			'deleted'   => _n( '%s snippet permanently deleted.', '%s snippets permanently deleted.', $counts['deleted'], 'slim-snippets' ),
			/* translators: %s: number of snippets */
			'trashed'   => _n( '%s snippet moved to the Trash.', '%s snippets moved to the Trash.', $counts['trashed'], 'slim-snippets' ),
			/* translators: %s: number of snippets */
			'untrashed' => _n( '%s snippet restored from the Trash (inactive).', '%s snippets restored from the Trash (inactive).', $counts['untrashed'], 'slim-snippets' ),
		);
		return $messages;
	},
	10,
	2
);

/*
 * Tags screens. Tags only group and filter snippets, so the description that
 * WordPress offers for every term has no use: hide its column on the Tags
 * screen and its field there and on the Edit Tag screen.
 */

add_filter(
	'manage_edit-slim_snippet_tag_columns',
	function ( $columns ) {
		unset( $columns['description'] );
		return $columns;
	}
);

/**
 * Hide the Description field of the Add New Tag and Edit Tag forms.
 */
function slim_snippets_hide_tag_description() {
	echo '<style>.term-description-wrap{display:none}</style>';
}
add_action( 'slim_snippet_tag_pre_add_form', 'slim_snippets_hide_tag_description' );
add_action( 'slim_snippet_tag_pre_edit_form', 'slim_snippets_hide_tag_description' );

/*
 * List screen.
 */

add_filter(
	'manage_slim_snippet_posts_columns',
	function ( $columns ) {
		return array(
			'cb'                        => $columns['cb'],
			'title'                     => __( 'Name', 'slim-snippets' ),
			'slim_scope'                => __( 'Scope', 'slim-snippets' ),
			'slim_priority'             => __( 'Priority', 'slim-snippets' ),
			'taxonomy-slim_snippet_tag' => __( 'Tags', 'slim-snippets' ),
			'slim_id'                   => __( 'ID', 'slim-snippets' ),
			'slim_created'              => __( 'Created', 'slim-snippets' ),
			'slim_modified'             => __( 'Modified', 'slim-snippets' ),
		);
	}
);

add_action(
	'manage_slim_snippet_posts_custom_column',
	function ( $column, $id ) {
		$format = __( 'Y/m/d g:i a', 'slim-snippets' );
		switch ( $column ) {
			case 'slim_scope':
				$scopes = slim_snippets_scopes();
				$scope  = slim_snippets_scope( $id );
				echo esc_html( isset( $scopes[ $scope ] ) ? $scopes[ $scope ] : $scope );
				break;
			case 'slim_priority':
				echo (int) get_post_field( 'menu_order', $id );
				break;
			case 'slim_id':
				echo (int) $id;
				break;
			case 'slim_created':
				echo esc_html( get_the_date( $format, $id ) );
				break;
			case 'slim_modified':
				echo esc_html( get_the_modified_date( $format, $id ) );
				break;
		}
	},
	10,
	2
);

add_filter(
	'manage_edit-slim_snippet_sortable_columns',
	function ( $columns ) {
		return array_merge(
			$columns,
			array(
				'slim_priority' => 'menu_order',
				'slim_id'       => 'ID',
				'slim_created'  => 'date',
				'slim_modified' => 'modified',
			)
		);
	}
);

// Published/Draft read as Active/Inactive on the snippets list (this request only).
add_action(
	'load-edit.php',
	function () {
		global $wp_post_statuses;
		if ( ! slim_snippets_is_screen() ) {
			return;
		}
		/* translators: %s: number of snippets */
		$wp_post_statuses['publish']->label_count = _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'slim-snippets' );
		/* translators: %s: number of snippets */
		$wp_post_statuses['draft']->label_count = _n_noop( 'Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>', 'slim-snippets' );

		// Grey "No description" in Extended view (core prints the excerpt as bare text, so style the cell).
		wp_add_inline_style( 'wp-admin', '.table-view-excerpt .slim-snippets-no-desc .column-title{color:#646970;font-style:italic}.table-view-excerpt .slim-snippets-no-desc .column-title strong{color:#50575e}.table-view-excerpt .slim-snippets-no-desc .column-title :is(strong,.row-actions){font-style:normal}' );
	}
);

// Extended view shows the Description under the title. Without one, core would
// build an excerpt from the code (texturized, so quotes turn curly); show a
// placeholder instead, and nothing in Compact view.
add_filter(
	'get_the_excerpt',
	function ( $excerpt, $post ) {
		if ( 'slim_snippet' !== $post->post_type ) {
			return $excerpt;
		}
		if ( '' !== trim( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}
		return isset( $GLOBALS['mode'] ) && 'excerpt' === $GLOBALS['mode'] ? __( 'No description', 'slim-snippets' ) : '';
	},
	20,
	2
);

add_filter(
	'post_class',
	function ( $classes, $css_class, $post_id ) {
		if ( 'slim_snippet' === get_post_type( $post_id ) && '' === trim( get_post_field( 'post_excerpt', $post_id ) ) ) {
			$classes[] = 'slim-snippets-no-desc';
		}
		return $classes;
	},
	10,
	3
);

add_filter(
	'display_post_states',
	function ( $states, $post ) {
		if ( 'slim_snippet' !== $post->post_type ) {
			return $states;
		}
		unset( $states['draft'] );
		if ( 'draft' === $post->post_status ) {
			$states['slim_inactive'] = __( 'Inactive', 'slim-snippets' );
		}
		if ( get_post_meta( $post->ID, '_slim_snippets_error', true ) ) {
			$states['slim_error'] = __( 'Error', 'slim-snippets' );
		}
		return $states;
	},
	10,
	2
);

/**
 * Query args for inactive snippets deactivated in the last 7 days.
 *
 * @return array
 */
function slim_snippets_recent_args() {
	return array(
		'post_status' => 'draft',
		'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- Small table, admin only.
			array(
				'key'     => '_slim_snippets_deactivated',
				'value'   => time() - WEEK_IN_SECONDS,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		),
	);
}

/**
 * Once per version, on the first admin page load after an update.
 */
function slim_snippets_upgrade() {
	if ( SLIM_SNIPPETS_VERSION === get_option( 'slim_snippets_version' ) ) {
		return;
	}
	// Before 1.2.6, tag counts left out inactive snippets and were not updated
	// on activation, so most were 0.
	$tags = get_terms(
		array(
			'taxonomy'   => 'slim_snippet_tag',
			'hide_empty' => false,
			'fields'     => 'tt_ids',
		)
	);
	if ( $tags && ! is_wp_error( $tags ) ) {
		wp_update_term_count_now( $tags, 'slim_snippet_tag' );
	}
	update_option( 'slim_snippets_version', SLIM_SNIPPETS_VERSION, true );
}
add_action( 'admin_init', 'slim_snippets_upgrade' );

// A tag link in the Tags column filters the list by that tag. Show the filter
// as the current view, so the list does not look unchanged; All clears it.
add_filter(
	'views_edit-slim_snippet',
	function ( $views ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- View filter.
		$tag = isset( $_GET['taxonomy'], $_GET['term'] ) && 'slim_snippet_tag' === $_GET['taxonomy']
			? get_term_by( 'slug', sanitize_text_field( wp_unslash( $_GET['term'] ) ), 'slim_snippet_tag' )
			: false;
		// phpcs:enable
		if ( $tag ) {
			$views['slim_tag'] = sprintf(
				'<a href="%s" class="current" aria-current="page">%s <span class="count">(%d)</span></a>',
				esc_url( admin_url( 'edit.php?post_type=slim_snippet&taxonomy=slim_snippet_tag&term=' . rawurlencode( $tag->slug ) ) ),
				/* translators: %s: tag name */
				esc_html( sprintf( __( 'Tag: %s', 'slim-snippets' ), $tag->name ) ),
				$GLOBALS['wp_query']->found_posts
			);
		}
		return $views;
	}
);

add_filter(
	'views_edit-slim_snippet',
	function ( $views ) {
		$args  = array(
			'post_type'   => 'slim_snippet',
			'numberposts' => -1,
			'fields'      => 'ids',
		);
		$count = count( get_posts( array_merge( slim_snippets_recent_args(), $args ) ) );
		if ( ! $count ) {
			return $views;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View filter.
		$current = ! empty( $_GET['slim_recent'] );
		$link    = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( admin_url( 'edit.php?post_type=slim_snippet&post_status=draft&slim_recent=1' ) ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Recently Active', 'slim-snippets' ),
			$count
		);

		$out = array();
		foreach ( $views as $key => $view ) {
			if ( $current ) {
				$view = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $view );
			}
			$out[ $key ] = $view;
			if ( 'draft' === $key ) {
				$out['slim_recent'] = $link;
			}
		}
		if ( ! isset( $out['slim_recent'] ) ) {
			$out['slim_recent'] = $link;
		}
		return $out;
	}
);

add_action(
	'pre_get_posts',
	function ( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View filter.
		if ( ! is_admin() || ! $query->is_main_query() || 'slim_snippet' !== $query->get( 'post_type' ) || empty( $_GET['slim_recent'] ) ) {
			return;
		}
		foreach ( slim_snippets_recent_args() as $key => $value ) {
			$query->set( $key, $value );
		}
	}
);

// Search tag names too. Core already searches the name, code and description.
add_filter(
	'posts_search',
	function ( $search, $query ) {
		global $wpdb;
		if ( '' === $search || ! is_admin() || ! $query->is_main_query() || 'slim_snippet' !== $query->get( 'post_type' ) ) {
			return $search;
		}
		$tags = $wpdb->prepare(
			"{$wpdb->posts}.ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tt.taxonomy = 'slim_snippet_tag' AND t.name LIKE %s)",
			'%' . $wpdb->esc_like( $query->get( 's' ) ) . '%'
		);
		// Concatenate rather than use $tags as a preg_replace() replacement, where
		// "$0" or "\\" in the search term would be read as references.
		return ' AND ( ' . $tags . ' OR (' . preg_replace( '/^\s*AND\s*\(/', '', $search, 1 ) . ')';
	},
	10,
	2
);

/**
 * URL for a row action.
 *
 * @param string     $action activate, deactivate or export.
 * @param int|string $ids    Snippet ID(s), comma separated, or 'all'.
 * @return string
 */
function slim_snippets_action_url( $action, $ids ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=slim_snippets_' . $action . '&ids=' . $ids ), 'slim_snippets_row' );
}

add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( 'slim_snippet' !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] ); // Quick Edit could change the status without the checks.
		if ( 'trash' === $post->post_status ) {
			return $actions;
		}

		if ( 'publish' === $post->post_status ) {
			$toggle = sprintf( '<a href="%s">%s</a>', esc_url( slim_snippets_action_url( 'deactivate', $post->ID ) ), esc_html__( 'Deactivate', 'slim-snippets' ) );
		} else {
			$label  = 'single-use' === slim_snippets_scope( $post->ID ) ? __( 'Run Once', 'slim-snippets' ) : __( 'Activate', 'slim-snippets' );
			$toggle = sprintf( '<a href="%s">%s</a>', esc_url( slim_snippets_action_url( 'activate', $post->ID ) ), esc_html( $label ) );
		}
		$export = sprintf( '<a href="%s">%s</a>', esc_url( slim_snippets_action_url( 'export', $post->ID ) ), esc_html__( 'Export', 'slim-snippets' ) );

		return array(
			'slim_toggle' => $toggle,
			'slim_export' => $export,
		) + $actions;
	},
	10,
	2
);

add_filter(
	'bulk_actions-edit-slim_snippet',
	function ( $actions ) {
		unset( $actions['edit'] ); // Bulk Edit could change the status without the checks.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View check.
		if ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] ) {
			return $actions;
		}
		return array(
			'slim_snippets_activate'   => __( 'Activate', 'slim-snippets' ),
			'slim_snippets_deactivate' => __( 'Deactivate', 'slim-snippets' ),
			'slim_snippets_export'     => __( 'Export', 'slim-snippets' ),
		) + $actions;
	}
);

add_filter(
	'handle_bulk_actions-edit-slim_snippet',
	function ( $redirect, $action, $ids ) {
		slim_snippets_do_action( $action, $ids );
		return $redirect;
	},
	10,
	3
);

foreach ( array( 'activate', 'deactivate', 'export' ) as $slim_snippets_action ) {
	add_action(
		'admin_post_slim_snippets_' . $slim_snippets_action,
		function () use ( $slim_snippets_action ) {
			check_admin_referer( 'slim_snippets_row' );
			$ids = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
			if ( 'all' === $ids ) {
				$ids = get_posts(
					array(
						'post_type'   => 'slim_snippet',
						'post_status' => array( 'publish', 'draft' ),
						'numberposts' => -1,
						'fields'      => 'ids',
						'orderby'     => 'ID',
						'order'       => 'ASC',
					)
				);
			}
			slim_snippets_do_action( 'slim_snippets_' . $slim_snippets_action, wp_parse_id_list( $ids ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=slim_snippet' ) );
			exit;
		}
	);
}
unset( $slim_snippets_action );

/**
 * Run a row or bulk action. Export sends a file and exits.
 *
 * @param string $action Action name.
 * @param int[]  $ids    Snippet IDs.
 */
function slim_snippets_do_action( $action, $ids ) {
	if ( ! current_user_can( slim_snippets_cap() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage snippets.', 'slim-snippets' ), 403 );
	}
	$ids = array_filter( array_map( 'absint', (array) $ids ) );

	switch ( $action ) {
		case 'slim_snippets_export':
			slim_snippets_download( $ids );
			break;

		case 'slim_snippets_activate':
			$done = 0;
			foreach ( $ids as $id ) {
				$done += (int) slim_snippets_report( $id, slim_snippets_activate( $id ) );
			}
			if ( $done ) {
				/* translators: %d: number of snippets */
				slim_snippets_notice( 'success', sprintf( _n( '%d snippet activated.', '%d snippets activated.', $done, 'slim-snippets' ), $done ) );
			}
			break;

		case 'slim_snippets_deactivate':
			$done = 0;
			foreach ( $ids as $id ) {
				if ( 'publish' === get_post_status( $id ) && 'slim_snippet' === get_post_type( $id ) ) {
					slim_snippets_set_status( $id, 'draft' );
					++$done;
				}
			}
			/* translators: %d: number of snippets */
			slim_snippets_notice( 'success', sprintf( _n( '%d snippet deactivated.', '%d snippets deactivated.', $done, 'slim-snippets' ), $done ) );
			break;
	}
}

/*
 * Export and import. The JSON matches Code Snippets' export, so files move
 * both ways between the two plugins.
 */

/**
 * Build export data.
 *
 * @param int[] $ids Snippet IDs.
 * @return array
 */
function slim_snippets_export_data( $ids ) {
	$snippets = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post || 'slim_snippet' !== $post->post_type ) {
			continue;
		}
		$tags       = wp_get_post_terms( $post->ID, 'slim_snippet_tag', array( 'fields' => 'names' ) );
		$snippets[] = array(
			'id'       => $post->ID,
			'name'     => $post->post_title,
			'desc'     => str_replace( "\r\n", "\n", $post->post_excerpt ),
			'code'     => str_replace( "\r\n", "\n", $post->post_content ),
			'tags'     => is_array( $tags ) ? $tags : array(),
			'scope'    => slim_snippets_scope( $post->ID ),
			'priority' => (int) $post->menu_order,
			'active'   => 'publish' === $post->post_status,
			'modified' => $post->post_modified,
		);
	}
	return array(
		'generator'    => 'Slim Snippets v' . SLIM_SNIPPETS_VERSION,
		'date_created' => gmdate( 'Y-m-d H:i' ),
		'snippets'     => $snippets,
	);
}

/**
 * Send snippets as a JSON download and exit.
 *
 * @param int[] $ids Snippet IDs.
 */
function slim_snippets_download( $ids ) {
	$data  = slim_snippets_export_data( $ids );
	$title = 1 === count( $data['snippets'] ) ? $data['snippets'][0]['name'] : get_bloginfo( 'name' );
	$file  = sanitize_file_name( strtolower( $title ) . '.slim-snippets.json' );

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $file . '"' );
	echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

/**
 * Convert a Code Snippets description (HTML from its visual editor) to the
 * plain text Slim Snippets uses. Paragraphs become blank-line breaks, list
 * items become "- " lines, and links keep their URL in parentheses.
 *
 * @param string $html Description HTML.
 * @return string Plain text.
 */
function slim_snippets_html_to_text( $html ) {
	// One line break next to a block tag is source layout, not text. A blank
	// line is a paragraph break (descriptions saved without <p> tags), so it
	// keeps one newline. Inside <pre>, whitespace is text and is not trimmed.
	$block = 'p|div|ul|ol|li|h[1-6]|blockquote|table|thead|tbody|tfoot|tr|td|th|hr|figure|figcaption';
	$ws    = '[ \t]*\n?[ \t]*';
	$text  = preg_replace(
		array( '#<!--.*?-->#s', '#' . $ws . '(</?(?:' . $block . ')(?=[\s/>])[^>]*>)' . $ws . '#i', '#' . $ws . '(<pre(?=[\s>])[^>]*>)|(</pre>)' . $ws . '#i' ),
		array( '', '$1', '$1$2' ),
		str_replace( "\r\n", "\n", (string) $html )
	);
	$text  = preg_replace_callback(
		'#<a\s(?:[^>]*?\s)?href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)</a>#is',
		function ( $m ) {
			$url   = $m[1] . $m[2] . $m[3];
			$plain = array_map( 'html_entity_decode', array( trim( wp_strip_all_tags( $m[4] ) ), $url ) );
			$same  = in_array( $plain[0], array( '', $plain[1], preg_replace( '#^[a-z]+:(//)?#i', '', $plain[1] ) ), true );
			return $same ? $url : $m[4] . ' (' . $url . ')';
		},
		$text
	);
	// Line breaks for blocks. "</li>" goes first so a nested list's "\n" and
	// the next "<li>" merge into one line break.
	$text = preg_replace(
		array( '#[ \t]*<br\s*/?>\n?#i', '#</li>#i', '#<tr(?=[\s/>])[^>]*>|</(?:ul|ol)>#i', '#\n?<li(?=[\s/>])[^>]*>#i', '#</t[dh]>#i', '#</?(?:p|div|h[1-6]|blockquote|pre|table|hr|figure)(?=[\s/>])[^>]*>#i' ),
		array( "\n", '', "\n", "\n- ", ' ', "\n\n" ),
		$text
	);
	// Strip only the formatting tags an editor produces. Anything else, like
	// the placeholder in "option_<key>", is text the author typed.
	$text = preg_replace( '#</?(?:a|abbr|b|blockquote|cite|code|del|div|em|figcaption|figure|font|h[1-6]|hr|i|img|ins|kbd|li|mark|ol|p|pre|q|s|small|span|strong|sub|sup|table|tbody|td|tfoot|th|thead|tr|u|ul)(?=[\s/>])[^>]*>#i', '', $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = str_replace( "\xC2\xA0", ' ', $text ); // Non-breaking spaces from &nbsp;.
	// "<li><p>a</p>" puts the item text on the line after its "- ": join them.
	$text = preg_replace( array( '/^- \n+/m', '/[ \t]+\n/', "/\n{3,}/" ), array( '- ', "\n", "\n\n" ), $text );
	return trim( $text );
}

/**
 * Import one snippet from Slim Snippets or Code Snippets export data, as an
 * inactive snippet (or into the Trash). Activating is up to the caller.
 *
 * @param array $snippet            One entry of the export's "snippets" array.
 * @param bool  $from_code_snippets Whether the file came from Code Snippets, whose descriptions are HTML.
 * @param bool  $keep_trash         Import snippets that are trashed in the export into the Trash instead of skipping them.
 * @return array{0: string, 1: string, 2: int} State ('imported', 'trash', 'skipped' or 'error'), a note, and the new ID.
 */
function slim_snippets_import_snippet( $snippet, $from_code_snippets = false, $keep_trash = false ) {
	$scope = isset( $snippet['scope'] ) ? (string) $snippet['scope'] : 'global';
	$name  = sanitize_text_field( isset( $snippet['name'] ) ? $snippet['name'] : '' );
	if ( ! empty( $snippet['trashed'] ) && ! $keep_trash ) {
		/* translators: %s: snippet name */
		return array( 'skipped', sprintf( __( '"%s": in the Trash when it was exported', 'slim-snippets' ), $name ), 0 );
	}
	if ( ! array_key_exists( $scope, slim_snippets_scopes() ) ) {
		/* translators: 1: snippet name, 2: scope name from the export file */
		return array( 'skipped', sprintf( __( '"%1$s": unsupported scope "%2$s"', 'slim-snippets' ), $name, $scope ), 0 );
	}

	$desc = isset( $snippet['desc'] ) ? $snippet['desc'] : ( isset( $snippet['description'] ) ? $snippet['description'] : '' );
	$desc = $from_code_snippets ? slim_snippets_html_to_text( $desc ) : $desc;
	$id   = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'slim_snippet',
				'post_status'  => 'draft',
				'post_title'   => $name,
				'post_excerpt' => (string) $desc,
				'post_content' => isset( $snippet['code'] ) ? (string) $snippet['code'] : '',
				'menu_order'   => max( 0, isset( $snippet['priority'] ) ? (int) $snippet['priority'] : 10 ),
				'meta_input'   => array( '_slim_snippets_scope' => $scope ),
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return array( 'error', '"' . $name . '": ' . $id->get_error_message(), 0 );
	}

	$tags = isset( $snippet['tags'] ) ? $snippet['tags'] : array();
	$tags = is_array( $tags ) ? $tags : explode( ',', (string) $tags );
	wp_set_post_terms( $id, array_filter( array_map( 'sanitize_text_field', $tags ) ), 'slim_snippet_tag' );

	if ( ! empty( $snippet['trashed'] ) ) {
		wp_trash_post( $id );
		return array( 'trash', '', $id );
	}
	return array( 'imported', '', $id );
}

/**
 * Import the snippets of decoded export files and set their status.
 *
 * @param array  $files    Uploaded files, each array( file name, decoded JSON ).
 * @param string $status   'inactive' (every snippet inactive), 'keep' (the
 *                         status in the file, including the Trash) or 'active'
 *                         (activate every snippet).
 * @param bool   $activate False imports every snippet inactive whatever the
 *                         status (while Code Snippets is active and running
 *                         the originals); the Trash is still kept.
 * @return array{0: array<string, int>, 1: string[]} Counts and notes.
 */
function slim_snippets_import_files( $files, $status, $activate = true ) {
	$counts = array_fill_keys( array( 'active', 'inactive', 'failed', 'trash', 'skipped' ), 0 );
	$notes  = array();
	$queue  = array();
	foreach ( $files as list( $file, $data ) ) {
		if ( ! isset( $data['snippets'] ) || ! is_array( $data['snippets'] ) ) {
			++$counts['skipped'];
			/* translators: %s: file name */
			$notes[] = sprintf( __( '"%s" is not a snippets export.', 'slim-snippets' ), $file );
			continue;
		}
		$from_cs = isset( $data['generator'] ) && 0 === strpos( (string) $data['generator'], 'Code Snippets' );
		foreach ( $data['snippets'] as $snippet ) {
			if ( ! is_array( $snippet ) ) {
				++$counts['skipped'];
				/* translators: %s: file name */
				$notes[] = sprintf( __( '"%s" has a snippet that is not valid.', 'slim-snippets' ), $file );
				continue;
			}
			list( $state, $note, $id ) = slim_snippets_import_snippet( $snippet, $from_cs, 'keep' === $status );
			if ( 'imported' !== $state ) {
				++$counts[ 'trash' === $state ? 'trash' : 'skipped' ];
				$notes[] = $note;
				continue;
			}
			// An import never runs a run-once snippet.
			$wanted = $activate && ( 'active' === $status || ( 'keep' === $status && ! empty( $snippet['active'] ) ) );
			if ( $wanted && 'single-use' !== slim_snippets_scope( $id ) ) {
				$queue[] = $id;
			} else {
				++$counts['inactive'];
			}
		}
	}

	// Activate only once everything is imported, so a failure cannot cut the import short.
	foreach ( $queue as $id ) {
		list( $state, $text ) = slim_snippets_activate( $id );
		if ( 'ok' === $state ) {
			++$counts['active'];
		} else {
			++$counts['failed']; // Imported, but left inactive.
			/* translators: 1: snippet name, 2: error message */
			$notes[] = sprintf( __( '"%1$s" left inactive: %2$s', 'slim-snippets' ), get_the_title( $id ), $text );
		}
	}
	return array( $counts, array_filter( $notes ) );
}

add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'edit.php?post_type=slim_snippet',
			__( 'Import / Export Snippets', 'slim-snippets' ),
			__( 'Import / Export', 'slim-snippets' ),
			slim_snippets_cap(),
			'slim-snippets-import',
			'slim_snippets_import_page',
			2 // After All Snippets and Add Snippet, before Tags.
		);
		add_submenu_page(
			'edit.php?post_type=slim_snippet',
			__( 'Snippets Settings', 'slim-snippets' ),
			__( 'Settings', 'slim-snippets' ),
			slim_snippets_cap(),
			'slim-snippets-settings',
			'slim_snippets_settings_page'
		);
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'slim-snippets',
			'slim_snippets_delete_data',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
	}
);
// options.php checks manage_options unless told otherwise.
add_filter( 'option_page_capability_slim-snippets', 'slim_snippets_cap' );

/**
 * Settings page. uninstall.php reads the setting.
 */
function slim_snippets_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Snippets Settings', 'slim-snippets' ); ?></h1>
		<?php settings_errors(); // Core shows "Settings saved." only on the Settings menu's own screens. ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'slim-snippets' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Clean up', 'slim-snippets' ); ?></th>
					<td>
						<label><input type="checkbox" name="slim_snippets_delete_data" value="1"<?php checked( (bool) get_option( 'slim_snippets_delete_data' ) ); ?>> <?php esc_html_e( 'Delete all snippets and tags when the plugin is deleted', 'slim-snippets' ); ?></label>
						<p class="description">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s: link to the Export section of the Import / Export page */
									__( 'Every snippet, including those in the Trash, is deleted with its revisions and tags. This cannot be undone. To keep a copy, %s first.', 'slim-snippets' ),
									'<a href="' . esc_url( admin_url( 'edit.php?post_type=slim_snippet&page=slim-snippets-import#export' ) ) . '">' . esc_html__( 'export your snippets', 'slim-snippets' ) . '</a>'
								)
							);
							?>
						</p>
						<?php if ( is_multisite() ) : ?>
							<p class="description"><?php esc_html_e( 'This applies to this site only.', 'slim-snippets' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Import / Export page.
 */
function slim_snippets_import_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import / Export Snippets', 'slim-snippets' ); ?></h1>

		<?php
		// Migrating deactivates a plugin, so only users who may do that see it.
		if ( defined( 'CODE_SNIPPETS_FILE' ) && current_user_can( 'activate_plugins' ) ) {
			slim_snippets_cs_section();
		}
		?>

		<h2><?php esc_html_e( 'Import', 'slim-snippets' ); ?></h2>
		<p><?php esc_html_e( 'Upload one or more JSON files exported from Slim Snippets or Code Snippets. Drop them on the box below or choose them.', 'slim-snippets' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="slim_snippets_import">
			<?php wp_nonce_field( 'slim_snippets_import' ); ?>
			<style>
				/* The whole input is the drop zone; the browse button looks like a WP .button. */
				#slim-snippets-files{box-sizing:border-box;width:100%;max-width:600px;padding:48px 24px;border:2px dashed #c3c4c7;border-radius:4px;background:#fff;cursor:pointer}
				#slim-snippets-files:hover,#slim-snippets-files:focus{border-color:#2271b1;outline:0}
				#slim-snippets-files::file-selector-button{margin-right:10px;padding:0 10px;min-height:30px;font-size:13px;line-height:2.15384615;color:#2271b1;background:#f6f7f7;border:1px solid #2271b1;border-radius:3px;cursor:pointer}
				#slim-snippets-files::file-selector-button:hover{color:#0a4b78;background:#f0f0f1;border-color:#0a4b78}
			</style>
			<p><input type="file" id="slim-snippets-files" name="slim_snippets_files[]" accept=".json,application/json" multiple required></p>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Status after import', 'slim-snippets' ); ?></strong></legend>
				<?php
				// While Code Snippets is active it runs the originals, so the import
				// handler activates nothing. Offer only the choice that will happen.
				$cs_active = defined( 'CODE_SNIPPETS_FILE' );
				if ( $cs_active ) :
					?>
					<div class="notice notice-warning inline"><p>
						<?php
						// A network-activated plugin can only be deactivated in the Network
						// Admin, and migrating does not deactivate it.
						if ( is_plugin_active_for_network( plugin_basename( CODE_SNIPPETS_FILE ) ) ) {
							$warning = sprintf(
								/* translators: %s: link to the Network Admin Plugins screen */
								__( 'The Code Snippets plugin is network-activated, so snippets can only be imported inactive. To keep their status or activate them, deactivate Code Snippets on the %s first.', 'slim-snippets' ),
								'<a href="' . esc_url( network_admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Network Admin Plugins screen', 'slim-snippets' ) . '</a>'
							);
						} else {
							$warning = sprintf(
								/* translators: 1: link to the Plugins screen, 2: link to the Migrate from Code Snippets section */
								__( 'The Code Snippets plugin is active, so snippets can only be imported inactive. To keep their status or activate them, deactivate Code Snippets on the %1$s first, or use %2$s at the top of this page.', 'slim-snippets' ),
								'<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Plugins screen', 'slim-snippets' ) . '</a>',
								'<a href="#migrate">' . esc_html__( 'Migrate from Code Snippets', 'slim-snippets' ) . '</a>'
							);
						}
						echo wp_kses_post( $warning );
						?>
					</p></div>
				<?php endif; ?>
				<p><label><input type="radio" name="slim_snippets_status" value="inactive" checked> <?php esc_html_e( 'Inactive: import every snippet inactive, so nothing runs until you activate it', 'slim-snippets' ); ?></label></p>
				<p><label<?php echo $cs_active ? ' style="color:#8c8f94"' : ''; ?>><input type="radio" name="slim_snippets_status" value="keep"<?php disabled( $cs_active ); ?>> <?php esc_html_e( 'Keep the status from the file: activate the active snippets, keep the inactive ones inactive, and put trashed snippets in the Trash', 'slim-snippets' ); ?></label></p>
				<p><label<?php echo $cs_active ? ' style="color:#8c8f94"' : ''; ?>><input type="radio" name="slim_snippets_status" value="active"<?php disabled( $cs_active ); ?>> <?php esc_html_e( 'Active: activate every snippet', 'slim-snippets' ); ?></label></p>
			</fieldset>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Activating runs the usual checks. A snippet with an error, or one that declares a function or class that already exists, stays inactive and is listed in the notice.', 'slim-snippets' ); ?></li>
				<li><?php esc_html_e( 'Run-once snippets are always imported inactive, so an import never runs them.', 'slim-snippets' ); ?></li>
				<li><?php esc_html_e( 'Trashed snippets are not imported unless you keep the status from the file.', 'slim-snippets' ); ?></li>
				<li><strong><?php esc_html_e( 'Migrating from Code Snippets?', 'slim-snippets' ); ?></strong> <?php esc_html_e( 'If the Code Snippets plugin is active on this site, use Migrate from Code Snippets at the top of this page.', 'slim-snippets' ); ?></li>
			</ul>
			<?php submit_button( __( 'Import', 'slim-snippets' ) ); ?>
		</form>

		<h2 id="export"><?php esc_html_e( 'Export', 'slim-snippets' ); ?></h2>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'To export one snippet, hover over it on the All Snippets screen and click Export under its name.', 'slim-snippets' ); ?></li>
			<li><?php esc_html_e( 'To export several snippets, select them on the All Snippets screen, choose Export from the Bulk actions menu, and click Apply.', 'slim-snippets' ); ?></li>
			<li><?php esc_html_e( 'To export every snippet that is not in the Trash, click Export All Snippets below.', 'slim-snippets' ); ?></li>
		</ul>
		<p><a class="button" href="<?php echo esc_url( slim_snippets_action_url( 'export', 'all' ) ); ?>"><?php esc_html_e( 'Export All Snippets', 'slim-snippets' ); ?></a></p>
	</div>
	<?php
}

add_action(
	'admin_post_slim_snippets_import',
	function () {
		check_admin_referer( 'slim_snippets_import' );
		if ( ! current_user_can( slim_snippets_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage snippets.', 'slim-snippets' ), 403 );
		}

		$status = isset( $_POST['slim_snippets_status'] ) ? sanitize_key( wp_unslash( $_POST['slim_snippets_status'] ) ) : '';
		$status = in_array( $status, array( 'keep', 'active' ), true ) ? $status : 'inactive';
		$files  = array();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- tmp_name is checked with is_uploaded_file(); the name is only shown, escaped, in the notice.
		$names = isset( $_FILES['slim_snippets_files']['name'] ) ? (array) $_FILES['slim_snippets_files']['name'] : array();
		foreach ( isset( $_FILES['slim_snippets_files']['tmp_name'] ) ? (array) $_FILES['slim_snippets_files']['tmp_name'] : array() as $i => $tmp ) {
			// phpcs:enable
			if ( is_uploaded_file( $tmp ) ) {
				$name    = isset( $names[ $i ] ) ? sanitize_text_field( $names[ $i ] ) : '';
				$files[] = array( $name, json_decode( (string) file_get_contents( $tmp ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded file.
			}
		}

		// While Code Snippets is active it still runs the originals: an active copy
		// would run twice, or take the site down if it declares a function or class.
		$cs_active              = defined( 'CODE_SNIPPETS_FILE' );
		list( $counts, $notes ) = slim_snippets_import_files( $files, $status, ! $cs_active );
		$total                  = $counts['active'] + $counts['inactive'] + $counts['failed'] + $counts['trash'];
		$items                  = array(
			/* translators: %d: number of snippets */
			sprintf( _n( '%d active', '%d active', $counts['active'], 'slim-snippets' ), $counts['active'] ),
			/* translators: %d: number of snippets */
			sprintf( _n( '%d inactive', '%d inactive', $counts['inactive'], 'slim-snippets' ), $counts['inactive'] ),
		);
		if ( $counts['failed'] ) {
			/* translators: %d: number of snippets */
			$items[] = sprintf( _n( '%d left inactive because of an error, listed below', '%d left inactive because of an error, listed below', $counts['failed'], 'slim-snippets' ), $counts['failed'] );
		}
		if ( $counts['trash'] ) {
			/* translators: %d: number of snippets */
			$items[] = sprintf( _n( '%d in the Trash', '%d in the Trash', $counts['trash'], 'slim-snippets' ), $counts['trash'] );
		}
		if ( $counts['skipped'] ) {
			/* translators: %d: number of snippets */
			$items[] = sprintf( _n( '%d skipped, listed below', '%d skipped, listed below', $counts['skipped'], 'slim-snippets' ), $counts['skipped'] );
		}
		if ( $cs_active && 'inactive' !== $status ) {
			$items[] = __( 'None were activated, because the Code Snippets plugin is active. Deactivate it, then activate the snippets you want.', 'slim-snippets' );
		}
		slim_snippets_notice(
			$notes || ( $cs_active && 'inactive' !== $status ) ? 'warning' : 'success',
			/* translators: %d: number of snippets */
			sprintf( _n( 'Imported %d snippet:', 'Imported %d snippets:', $total, 'slim-snippets' ), $total ),
			$notes,
			$items
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=slim_snippet' ) );
		exit;
	}
);

/*
 * Migrate from Code Snippets on the same site.
 */

/**
 * What migrating from the active Code Snippets would do with each of its
 * snippets. Reads Code Snippets' own table and changes nothing.
 *
 * @param array[]|null $rows Rows of Code Snippets' table; null reads the table.
 * @return array[] One row per Code Snippets snippet: 'snippet' (export-style
 *                 array), 'status' in Code Snippets ('active', 'inactive' or
 *                 'trash'), 'result' ('active', 'inactive' or 'skip'), 'note'
 *                 (why it is skipped, or ''), 'existing' (ID of the inactive
 *                 Slim Snippets copy to activate instead of copying again, or 0),
 *                 'id' (the snippet's ID in Code Snippets), 'review' (true
 *                 when the snippet is not migrated because it is trashed, its
 *                 scope is not supported or it uses a condition, so its code
 *                 would be lost from view once Code Snippets is deactivated)
 *                 and 'free_asset'
 *                 (true when it is an active CSS or JavaScript snippet that the
 *                 free Code Snippets plugin never ran, so it is copied inactive).
 *
 * @param bool|null    $pro  Whether Code Snippets Pro is the plugin migrated from;
 *                           null reads CODE_SNIPPETS_PRO.
 */
function slim_snippets_cs_plan( $rows = null, $pro = null ) {
	global $wpdb;
	if ( null === $rows ) {
		$table = $wpdb->prefix . 'snippets';
		if ( ! defined( 'CODE_SNIPPETS_FILE' ) || ! slim_snippets_table_exists( $table ) ) {
			return array();
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}snippets ORDER BY priority, id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Code Snippets' own table, no input.
	}

	// Snippets already copied (same name and code) are not copied again. Values
	// are a Slim Snippets post, or the index of an earlier row of this plan.
	$have  = array();
	$posts = get_posts(
		array(
			'post_type'   => 'slim_snippet',
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => -1,
		)
	);
	// Line endings are ignored: saving in the editor turns "\r\n" into "\n".
	$key_of = fn( $name, $code ) => $name . "\0" . trim( str_replace( array( "\r\n", "\r" ), "\n", $code ) );
	foreach ( $posts as $post ) {
		$have[ $key_of( $post->post_title, $post->post_content ) ] = $post;
	}
	// A network-active Code Snippets is not deactivated here, so nothing may be activated.
	$can_activate = ! defined( 'CODE_SNIPPETS_FILE' ) || ! is_plugin_active_for_network( plugin_basename( CODE_SNIPPETS_FILE ) );
	// CSS and JavaScript snippets are Pro features: the free plugin keeps them,
	// but never runs them. Copying them active would start code that never ran.
	$pro = null === $pro ? defined( 'CODE_SNIPPETS_PRO' ) && CODE_SNIPPETS_PRO : $pro;

	$plan = array();
	foreach ( (array) $rows as $row ) {
		$name     = sanitize_text_field( $row['name'] );
		$status   = (int) $row['active'] < 0 ? 'trash' : ( (int) $row['active'] ? 'active' : 'inactive' );
		$free_css = ! $pro && 'active' === $status && isset( SLIM_SNIPPETS_OUTPUT_HOOKS[ $row['scope'] ] );
		$active   = $can_activate && 'active' === $status && 'single-use' !== $row['scope'] && ! $free_css;
		$key      = $key_of( $name, $row['code'] );
		$dup      = isset( $have[ $key ] ) ? $have[ $key ] : null;
		$note     = '';
		$existing = 0;
		$review   = false;
		if ( 'trash' === $status ) {
			$note   = __( 'in the Code Snippets Trash', 'slim-snippets' );
			$review = true;
		} elseif ( ! array_key_exists( $row['scope'], slim_snippets_scopes() ) ) {
			/* translators: %s: Code Snippets scope name */
			$note   = sprintf( __( 'scope "%s" is not supported', 'slim-snippets' ), $row['scope'] );
			$review = true;
		} elseif ( ! empty( $row['condition_id'] ) ) {
			$note   = __( 'uses a Code Snippets condition, which is not supported', 'slim-snippets' );
			$review = true;
		} elseif ( is_int( $dup ) ) {
			$twin = __( 'duplicate of another Code Snippets snippet with the same name and code', 'slim-snippets' );
			if ( $active && 'active' !== $plan[ $dup ]['result'] ) {
				// Copy the active one, so it is the one listed in the Active
				// table with a checkbox, and skip the earlier inactive one.
				$plan[ $dup ]['result']     = 'skip';
				$plan[ $dup ]['note']       = $twin;
				$plan[ $dup ]['free_asset'] = false;
			} else {
				$note = $twin;
			}
		} elseif ( $dup && $active && 'draft' === $dup->post_status ) {
			$existing = $dup->ID; // Inactive copy, from an earlier import: activate it, or this snippet runs nowhere.
		} elseif ( $dup ) {
			$note = __( 'already in Slim Snippets', 'slim-snippets' );
		}
		if ( ! $note ) {
			$have[ $key ] = count( $plan );
		}
		$plan[] = array(
			'snippet'    => array(
				'name'     => $name,
				'desc'     => $row['description'],
				'code'     => $row['code'],
				'tags'     => $row['tags'],
				'scope'    => $row['scope'],
				'priority' => $row['priority'],
			),
			'status'     => $status,
			'result'     => $note ? 'skip' : ( $active ? 'active' : 'inactive' ),
			'note'       => $note,
			'existing'   => $existing,
			'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'review'     => $review,
			'free_asset' => $free_css && ! $note,
		);
	}
	return $plan;
}

/**
 * Why each Code Snippets copy cannot be activated, without running it. The
 * copies skip the test run and function_exists(): Code Snippets already ran
 * the same code in this request, so those would always fail. What is checked
 * is that the code parses and declares nothing that an active Slim Snippets
 * snippet, or an earlier copy in the list, already declares.
 *
 * @param int[] $ids Snippet IDs, in activation order.
 * @return array<int, string> ID => error, '' when it can be activated.
 */
function slim_snippets_cs_check( $ids ) {
	$taken = array();
	foreach ( (array) get_option( 'slim_snippets_active', array() ) as list( , $scope, $code ) ) {
		if ( slim_snippets_is_php( $scope ) ) {
			$taken += slim_snippets_declarations( $code );
		}
	}
	$errors = array();
	foreach ( $ids as $id ) {
		$error = '';
		if ( slim_snippets_is_php( slim_snippets_scope( $id ) ) ) {
			$code  = get_post_field( 'post_content', $id );
			$error = slim_snippets_lint( $code );
			$found = $error ? array() : slim_snippets_declarations( $code );
			$clash = array_intersect_key( $found, $taken );
			if ( $clash ) {
				/* translators: %s: for example "function my_helper()" or "class My_Class" */
				$error = sprintf( __( 'Cannot redeclare %s: it already exists in another snippet, a plugin or the theme', 'slim-snippets' ), reset( $clash ) );
			} else {
				$taken += $found;
			}
		}
		$errors[ $id ] = $error;
	}
	return $errors;
}

/**
 * Migrate section of the Import / Export page, shown while Code Snippets is active.
 * Snippets are listed in three tables by their status in Code Snippets. Every
 * snippet that can be migrated has a checkbox, checked by default; WordPress's
 * common.js gives each table's header checkbox its check-all behavior.
 */
function slim_snippets_cs_section() {
	$plan    = slim_snippets_cs_plan();
	$todo    = count( array_filter( $plan, fn( $item ) => 'skip' !== $item['result'] ) );
	$scopes  = slim_snippets_scopes();
	$labels  = array(
		'active'   => __( 'Active', 'slim-snippets' ),
		'inactive' => __( 'Inactive', 'slim-snippets' ),
		'trash'    => __( 'Trash', 'slim-snippets' ),
	);
	$network = is_plugin_active_for_network( plugin_basename( CODE_SNIPPETS_FILE ) );
	$review  = array_filter( array_column( $plan, 'review' ) );
	$assets  = array_filter( array_column( $plan, 'free_asset' ) );
	?>
	<h2 id="migrate"><?php esc_html_e( 'Migrate from Code Snippets', 'slim-snippets' ); ?></h2>
	<ul class="ul-disc">
		<li><?php esc_html_e( 'The Code Snippets plugin is active on this site. Migrating copies the selected snippets into Slim Snippets and then deactivates Code Snippets, so the same snippets do not run in both plugins.', 'slim-snippets' ); ?></li>
		<li>
			<?php
			esc_html_e( 'Every snippet that can be migrated is selected. A snippet you clear is not copied.', 'slim-snippets' );
			if ( ! $network ) {
				echo ' ' . esc_html__( 'If it is active, it stops running when Code Snippets is deactivated.', 'slim-snippets' );
			}
			?>
		</li>
		<li>
			<?php
			esc_html_e( 'Snippets that are active in Code Snippets are active in Slim Snippets after migrating, except run-once snippets, which are copied inactive.', 'slim-snippets' );
			if ( $assets ) {
				echo ' ' . esc_html__( 'CSS and JavaScript snippets are copied inactive too, because the free Code Snippets plugin does not run them.', 'slim-snippets' );
			}
			?>
		</li>
		<li><?php esc_html_e( 'The Code Snippets plugin and its snippets are not changed or deleted. To switch back, deactivate Slim Snippets first, then reactivate Code Snippets. With both plugins active, the same snippets run twice, and a snippet that declares a function or class causes a fatal error.', 'slim-snippets' ); ?></li>
		<?php if ( $review && ! $network ) : ?>
			<li><?php esc_html_e( 'Snippets with a "view snippet" link below are not migrated. Once the Code Snippets plugin is deactivated, the active ones stop running and none of them can be viewed there. Open each one before you migrate.', 'slim-snippets' ); ?></li>
		<?php endif; ?>
		<?php if ( $network ) : ?>
			<li><strong><?php esc_html_e( 'The Code Snippets plugin is network-activated, so it is not deactivated here and every snippet is copied inactive.', 'slim-snippets' ); ?></strong></li>
		<?php endif; ?>
	</ul>
	<?php if ( ! $plan ) : ?>
		<p><?php esc_html_e( 'Code Snippets has no snippets on this site.', 'slim-snippets' ); ?></p>
		<?php
		return;
	endif;
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="slim_snippets_migrate">
		<?php
		wp_nonce_field( 'slim_snippets_migrate' );
		foreach ( $labels as $status => $heading ) :
			$items = array_filter( $plan, fn( $item ) => $status === $item['status'] );
			if ( ! $items ) {
				continue;
			}
			// Only tables with a snippet that can be migrated get checkboxes.
			$pick = 'trash' !== $status && array_filter( $items, fn( $item ) => 'skip' !== $item['result'] );
			?>
			<h3>
				<?php
				/* translators: 1: Active, Inactive or Trash, 2: number of snippets */
				echo esc_html( sprintf( __( '%1$s (%2$d)', 'slim-snippets' ), $heading, count( $items ) ) );
				?>
			</h3>
			<table class="wp-list-table widefat striped" style="max-width:1000px">
				<thead>
					<tr>
						<?php if ( $pick ) : ?>
							<td class="manage-column column-cb check-column">
								<input type="checkbox" id="slim-snippets-cs-all-<?php echo esc_attr( $status ); ?>" checked>
								<label for="slim-snippets-cs-all-<?php echo esc_attr( $status ); ?>"><span class="screen-reader-text">
									<?php
									echo esc_html( 'active' === $status ? __( 'Select all active snippets', 'slim-snippets' ) : __( 'Select all inactive snippets', 'slim-snippets' ) );
									?>
								</span></label>
							</td>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Snippet', 'slim-snippets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Scope', 'slim-snippets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'After migrating', 'slim-snippets' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $items as $item ) :
						$name = '' === $item['snippet']['name'] ? __( '(no title)', 'slim-snippets' ) : $item['snippet']['name'];
						?>
						<tr>
							<?php if ( $pick ) : ?>
								<th scope="row" class="check-column">
									<?php if ( 'skip' !== $item['result'] ) : ?>
										<input type="checkbox" name="slim_snippets_cs[]" value="<?php echo (int) $item['id']; ?>" id="slim-snippets-cs-<?php echo (int) $item['id']; ?>" checked>
										<label for="slim-snippets-cs-<?php echo (int) $item['id']; ?>"><span class="screen-reader-text">
											<?php
											/* translators: %s: snippet name */
											echo esc_html( sprintf( __( 'Migrate "%s"', 'slim-snippets' ), $name ) );
											?>
										</span></label>
									<?php endif; ?>
								</th>
							<?php endif; ?>
							<td><?php echo esc_html( $name ); ?></td>
							<td><?php echo esc_html( isset( $scopes[ $item['snippet']['scope'] ] ) ? $scopes[ $item['snippet']['scope'] ] : $item['snippet']['scope'] ); ?></td>
							<td>
								<?php
								if ( 'skip' === $item['result'] ) {
									if ( 'trash' === $item['status'] ) {
										esc_html_e( 'Not migrated', 'slim-snippets' ); // The table heading already says Trash.
									} else {
										/* translators: %s: reason, for example "already in Slim Snippets" */
										echo esc_html( sprintf( __( 'Not migrated: %s', 'slim-snippets' ), $item['note'] ) );
									}
									// Its code is out of reach once Code Snippets is deactivated: link to it so the
									// site owner can see what it does and how to replace it.
									if ( $item['review'] && $item['id'] ) {
										$edit = function_exists( 'Code_Snippets\code_snippets' ) ? \Code_Snippets\code_snippets()->get_menu_url( 'edit' ) : admin_url( 'admin.php?page=edit-snippet' );
										printf(
											'. <a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span><span aria-hidden="true" class="dashicons dashicons-external" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;text-decoration:none"></span></a>',
											esc_url( add_query_arg( 'id', $item['id'], $edit ) ),
											esc_html__( 'view snippet', 'slim-snippets' ),
											esc_html__( '(opens in a new tab)', 'slim-snippets' )
										);
									}
								} elseif ( $item['existing'] ) {
									esc_html_e( 'Active (activates the copy already in Slim Snippets)', 'slim-snippets' );
								} elseif ( $item['free_asset'] ) {
									esc_html_e( 'Inactive (CSS and JavaScript snippets do not run in the free Code Snippets plugin)', 'slim-snippets' );
								} else {
									echo esc_html( $labels[ $item['result'] ] );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endforeach;
		$label = $network
			? __( 'Migrate Selected Snippets', 'slim-snippets' )
			: __( 'Migrate Selected Snippets and Deactivate Code Snippets', 'slim-snippets' );
		submit_button( $label, 'primary', 'submit', true, $todo ? array() : array( 'disabled' => 'disabled' ) );
		?>
	</form>
	<?php
}

add_action(
	'admin_post_slim_snippets_migrate',
	function () {
		check_admin_referer( 'slim_snippets_migrate' );
		if ( ! current_user_can( slim_snippets_cap() ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to migrate snippets.', 'slim-snippets' ), 403 );
		}
		$back = admin_url( 'edit.php?post_type=slim_snippet' );
		if ( ! defined( 'CODE_SNIPPETS_FILE' ) ) {
			slim_snippets_notice( 'error', __( 'The Code Snippets plugin is not active, so there is nothing to migrate.', 'slim-snippets' ) );
			wp_safe_redirect( $back );
			exit;
		}
		// Code Snippets IDs of the snippets checked on the migrate form. If none
		// of them can be migrated now (nothing was checked, or the snippets
		// changed since the page loaded), change nothing, and say so.
		$selected = isset( $_POST['slim_snippets_cs'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['slim_snippets_cs'] ) ) : array();
		$plan     = slim_snippets_cs_plan();
		if ( ! array_filter( $plan, fn( $item ) => 'skip' !== $item['result'] && in_array( $item['id'], $selected, true ) ) ) {
			slim_snippets_notice( 'error', __( 'No snippets that can be migrated were selected, so nothing was migrated and the Code Snippets plugin is still active.', 'slim-snippets' ) );
			// No #migrate: the notice is above that heading, at the top of the page.
			wp_safe_redirect( admin_url( 'edit.php?post_type=slim_snippet&page=slim-snippets-import' ) );
			exit;
		}

		$counts = array_fill_keys( array( 'active', 'inactive', 'failed', 'trash', 'skipped', 'unselected' ), 0 );
		$notes  = array();
		$queue  = array();
		foreach ( $plan as $item ) {
			// Trashed snippets are only counted; listing them would bury the notes that matter.
			if ( 'trash' === $item['status'] ) {
				++$counts['trash'];
				continue;
			}
			if ( 'skip' === $item['result'] ) {
				++$counts['skipped'];
				$notes[] = '"' . $item['snippet']['name'] . '": ' . $item['note'];
				continue;
			}
			if ( ! in_array( $item['id'], $selected, true ) ) {
				++$counts['unselected'];
				continue;
			}
			if ( $item['existing'] ) {
				$queue[] = $item['existing'];
				continue;
			}
			list( $state, $note, $id ) = slim_snippets_import_snippet( $item['snippet'], true );
			if ( 'imported' !== $state ) {
				++$counts['skipped'];
				$notes[] = $note;
			} elseif ( 'active' === $item['result'] ) {
				$queue[] = $id;
			} else {
				++$counts['inactive'];
			}
		}

		// Check the copies first, then deactivate Code Snippets and activate them
		// with one rebuild, so the time with neither running is as short as possible.
		$errors = slim_snippets_cs_check( $queue );
		foreach ( array_filter( $errors ) as $id => $error ) {
			update_post_meta( $id, '_slim_snippets_error', $error );
			++$counts['failed']; // Copied, but left inactive.
			/* translators: 1: snippet name, 2: error message */
			$notes[] = sprintf( __( '"%1$s" left inactive: %2$s', 'slim-snippets' ), get_the_title( $id ), $error );
		}
		$file = plugin_basename( CODE_SNIPPETS_FILE );
		if ( ! is_plugin_active_for_network( $file ) ) {
			deactivate_plugins( $file );
		}
		foreach ( array_keys( $errors, '', true ) as $id ) {
			delete_post_meta( $id, '_slim_snippets_error' );
			slim_snippets_set_status( $id, 'publish', '', false );
			++$counts['active'];
		}
		slim_snippets_rebuild();

		$total = $counts['active'] + $counts['inactive'] + $counts['failed'];
		$items = array(
			/* translators: %d: number of snippets */
			sprintf( _n( '%d active', '%d active', $counts['active'], 'slim-snippets' ), $counts['active'] ),
			/* translators: %d: number of snippets */
			sprintf( _n( '%d inactive', '%d inactive', $counts['inactive'], 'slim-snippets' ), $counts['inactive'] ),
		);
		if ( $counts['failed'] ) {
			/* translators: %d: number of snippets */
			$items[] = sprintf( _n( '%d left inactive because of an error, listed below', '%d left inactive because of an error, listed below', $counts['failed'], 'slim-snippets' ), $counts['failed'] );
		}
		if ( $counts['trash'] ) {
			/* translators: %d: number of snippets */
			$items[] = sprintf( _n( '%d not migrated because it is in the Code Snippets Trash', '%d not migrated because they are in the Code Snippets Trash', $counts['trash'], 'slim-snippets' ), $counts['trash'] );
		}
		if ( $counts['unselected'] ) {
			/* translators: %d: number of snippets */
			$items[] = sprintf( _n( '%d not migrated because it was not selected', '%d not migrated because they were not selected', $counts['unselected'], 'slim-snippets' ), $counts['unselected'] );
		}
		if ( $counts['skipped'] ) {
			/* translators: %d: number of snippets */
			$items[] = sprintf( _n( '%d not migrated, listed below', '%d not migrated, listed below', $counts['skipped'], 'slim-snippets' ), $counts['skipped'] );
		}
		if ( ! is_plugin_active( $file ) ) {
			$items[] = __( 'The Code Snippets plugin is deactivated', 'slim-snippets' );
		}
		slim_snippets_notice(
			$notes ? 'warning' : 'success',
			/* translators: %d: number of snippets */
			sprintf( _n( 'Migrated %d snippet from Code Snippets:', 'Migrated %d snippets from Code Snippets:', $total, 'slim-snippets' ), $total ),
			array_values( array_filter( $notes ) ),
			$items
		);
		wp_safe_redirect( $back );
		exit;
	}
);
