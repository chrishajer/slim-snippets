<?php
/**
 * Admin screens: editor, list table, row and bulk actions, import and export.
 *
 * @package Slim_Snippets
 */

defined( 'ABSPATH' ) || exit;

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
 * @param string $type    success, error, warning or info.
 * @param string $message Message text.
 * @param string $detail  Optional preformatted text, such as a snippet's output.
 */
function slim_snippets_notice( $type, $message, $detail = '' ) {
	$key       = 'slim_snippets_notices_' . get_current_user_id();
	$notices   = get_transient( $key );
	$notices   = is_array( $notices ) ? $notices : array();
	$notices[] = array( $type, $message, $detail );
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
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Slim Snippets safe mode is on: snippets are not running.', 'slim-snippets' )
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

	foreach ( $notices as list( $type, $message, $detail ) ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $type ), esc_html( $message ) );
		if ( '' !== $detail ) {
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
		remove_meta_box( 'submitdiv', 'slim_snippet', 'side' );
		add_meta_box( 'slim_snippets_box', __( 'Snippet', 'slim-snippets' ), 'slim_snippets_box', 'slim_snippet', 'side', 'high' );
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
			<textarea id="slim-snippets-desc" name="excerpt" class="widefat" rows="3"><?php echo esc_textarea( $desc ); ?></textarea>
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
		<span class="description"><?php esc_html_e( 'Lower runs first.', 'slim-snippets' ); ?></span>
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
		if ( $pending['activate'] && slim_snippets_report( $id, slim_snippets_activate( $id ) ) ) {
			slim_snippets_notice( 'success', __( 'Snippet is active.', 'slim-snippets' ) );
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

		$settings = wp_enqueue_code_editor( array( 'type' => 'text/x-php' ) );
		if ( false === $settings ) {
			return; // The user turned syntax highlighting off: plain textarea.
		}
		wp_add_inline_script(
			'code-editor',
			sprintf(
				'jQuery( function ( $ ) {
					var modes = { "site-css": "css", "admin-css": "css", "site-head-js": "javascript", "site-footer-js": "javascript" };
					var editor = wp.codeEditor.initialize( "slim-snippets-code", %s ).codemirror;
					var scope = $( "#slim-snippets-scope" );
					function sync() { editor.setOption( "mode", modes[ scope.val() ] || "text/x-php" ); }
					scope.on( "change", sync );
					sync();
				} );',
				wp_json_encode( $settings )
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
	}
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
		return preg_replace( '/^\s*AND\s*\(/', ' AND ( ' . $tags . ' OR (', $search, 1 ) . ')';
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
 * Import one snippet from Slim Snippets or Code Snippets export data.
 * Imported snippets are always inactive, whatever the export says, so nothing
 * runs until someone activates it on purpose.
 *
 * @param array $snippet            One entry of the export's "snippets" array.
 * @param bool  $from_code_snippets Whether the file came from Code Snippets, whose descriptions are HTML.
 * @return array{0: string, 1: string} State ('imported', 'skipped' or 'error') and a reason.
 */
function slim_snippets_import_snippet( $snippet, $from_code_snippets = false ) {
	$scope = isset( $snippet['scope'] ) ? (string) $snippet['scope'] : 'global';
	if ( ! empty( $snippet['trashed'] ) ) {
		return array( 'skipped', __( 'trashed snippet', 'slim-snippets' ) );
	}
	if ( ! array_key_exists( $scope, slim_snippets_scopes() ) ) {
		/* translators: %s: scope name from the export file */
		return array( 'skipped', sprintf( __( 'unsupported scope "%s"', 'slim-snippets' ), $scope ) );
	}

	$desc = isset( $snippet['desc'] ) ? $snippet['desc'] : ( isset( $snippet['description'] ) ? $snippet['description'] : '' );
	$desc = $from_code_snippets ? slim_snippets_html_to_text( $desc ) : $desc;
	$id   = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'slim_snippet',
				'post_status'  => 'draft',
				'post_title'   => sanitize_text_field( isset( $snippet['name'] ) ? $snippet['name'] : '' ),
				'post_excerpt' => (string) $desc,
				'post_content' => isset( $snippet['code'] ) ? (string) $snippet['code'] : '',
				'menu_order'   => max( 0, isset( $snippet['priority'] ) ? (int) $snippet['priority'] : 10 ),
				'meta_input'   => array( '_slim_snippets_scope' => $scope ),
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return array( 'error', $id->get_error_message() );
	}

	$tags = isset( $snippet['tags'] ) ? $snippet['tags'] : array();
	$tags = is_array( $tags ) ? $tags : explode( ',', (string) $tags );
	wp_set_post_terms( $id, array_filter( array_map( 'sanitize_text_field', $tags ) ), 'slim_snippet_tag' );

	return array( 'imported', '' );
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
			'slim_snippets_import_page'
		);
	}
);

/**
 * Import / Export page.
 */
function slim_snippets_import_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import / Export Snippets', 'slim-snippets' ); ?></h1>

		<h2><?php esc_html_e( 'Import', 'slim-snippets' ); ?></h2>
		<p><?php esc_html_e( 'Upload JSON files exported from Slim Snippets or Code Snippets. Every snippet is imported inactive, even if it was active in the export, so nothing runs until you activate it.', 'slim-snippets' ); ?></p>
		<p><?php esc_html_e( 'Moving from Code Snippets? Deactivate it before you activate the imported snippets. If it still runs the same snippets, a function declared twice stops the activation with a fatal error.', 'slim-snippets' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="slim_snippets_import">
			<?php wp_nonce_field( 'slim_snippets_import' ); ?>
			<p><input type="file" name="slim_snippets_files[]" accept=".json,application/json" multiple required></p>
			<?php submit_button( __( 'Import', 'slim-snippets' ), 'primary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Export', 'slim-snippets' ); ?></h2>
		<p><?php esc_html_e( 'Download every snippet that is not in the Trash. To export some, select them on the snippets list and use the Export bulk action.', 'slim-snippets' ); ?></p>
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

		$counts  = array(
			'imported' => 0,
			'skipped'  => 0,
			'error'    => 0,
		);
		$reasons = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only tmp_name is used, checked with is_uploaded_file().
		$files = isset( $_FILES['slim_snippets_files']['tmp_name'] ) ? (array) $_FILES['slim_snippets_files']['tmp_name'] : array();

		foreach ( $files as $tmp ) {
			if ( ! is_uploaded_file( $tmp ) ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $tmp ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded file.
			if ( ! isset( $data['snippets'] ) || ! is_array( $data['snippets'] ) ) {
				++$counts['error'];
				$reasons[] = __( 'a file is not a snippets export', 'slim-snippets' );
				continue;
			}
			$from_cs = isset( $data['generator'] ) && 0 === strpos( (string) $data['generator'], 'Code Snippets' );
			foreach ( $data['snippets'] as $snippet ) {
				list( $state, $reason ) = is_array( $snippet ) ? slim_snippets_import_snippet( $snippet, $from_cs ) : array( 'error', 'bad entry' );
				++$counts[ $state ];
				if ( $reason ) {
					$reasons[] = $reason;
				}
			}
		}

		slim_snippets_notice(
			$counts['error'] ? 'warning' : 'success',
			sprintf(
				/* translators: 1: imported count, 2: skipped count */
				_n( 'Imported %1$d snippet (inactive). Skipped %2$d.', 'Imported %1$d snippets (all inactive). Skipped %2$d.', $counts['imported'], 'slim-snippets' ),
				$counts['imported'],
				$counts['skipped'] + $counts['error']
			),
			$reasons ? __( 'Skipped or failed:', 'slim-snippets' ) . ' ' . implode( ', ', array_unique( $reasons ) ) : ''
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=slim_snippet' ) );
		exit;
	}
);
