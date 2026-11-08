<?php
/**
 * The Forms list in wp-admin: forms and templates as separate views, a
 * submissions column, and row actions to duplicate a form, save it as a
 * template or start a new form from a template.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the Forms list is showing templates (?fbf_view=templates).
 */
function frankas_block_forms_viewing_templates(): bool {
	return 'templates' === sanitize_key( wp_unslash( $_GET['fbf_view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
}

function frankas_block_forms_template_meta_query( bool $templates ): array {
	return $templates
		? [ [ 'key' => FRANKAS_BLOCK_FORMS_TEMPLATE_META, 'value' => '1' ] ]
		: [
			'relation' => 'OR',
			[ 'key' => FRANKAS_BLOCK_FORMS_TEMPLATE_META, 'compare' => 'NOT EXISTS' ],
			[ 'key' => FRANKAS_BLOCK_FORMS_TEMPLATE_META, 'value' => '1', 'compare' => '!=' ],
		];
}

add_action( 'pre_get_posts', 'frankas_block_forms_filter_list' );

function frankas_block_forms_filter_list( WP_Query $query ) {
	global $pagenow;
	if ( 'edit.php' === $pagenow && $query->is_main_query() && FRANKAS_BLOCK_FORMS_POST_TYPE === $query->get( 'post_type' ) ) {
		$query->set( 'meta_query', frankas_block_forms_template_meta_query( frankas_block_forms_viewing_templates() ) );
	}
}

add_filter( 'views_edit-' . FRANKAS_BLOCK_FORMS_POST_TYPE, 'frankas_block_forms_list_views' );

/**
 * "Forms | Templates" above the list, replacing the post status links when
 * looking at templates.
 */
function frankas_block_forms_list_views( array $views ): array {
	$base      = admin_url( 'edit.php?post_type=' . FRANKAS_BLOCK_FORMS_POST_TYPE );
	$templates = frankas_block_forms_viewing_templates();
	$count     = count( get_posts( [
		'post_type'      => FRANKAS_BLOCK_FORMS_POST_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => frankas_block_forms_template_meta_query( true ), // phpcs:ignore WordPress.DB.SlowDBQuery
	] ) );

	$link = static fn( string $url, string $label, bool $current ) => sprintf(
		'<a href="%s"%s>%s</a>',
		esc_url( $url ),
		$current ? ' class="current" aria-current="page"' : '',
		$label
	);

	$custom = [
		'fbf_forms'     => $link( $base, __( 'Forms', 'frankas-block-forms' ), ! $templates ),
		/* translators: %d: number of templates */
		'fbf_templates' => $link( add_query_arg( 'fbf_view', 'templates', $base ), sprintf( __( 'Templates <span class="count">(%d)</span>', 'frankas-block-forms' ), $count ), $templates ),
	];

	return $templates ? $custom : $custom + array_diff_key( $views, [ 'all' => true ] );
}

add_filter( 'manage_' . FRANKAS_BLOCK_FORMS_POST_TYPE . '_posts_columns', 'frankas_block_forms_list_columns' );

function frankas_block_forms_list_columns( array $columns ): array {
	if ( frankas_block_forms_viewing_templates() ) {
		return $columns;
	}
	$date = $columns['date'] ?? null;
	unset( $columns['date'] );
	$columns['fbf_submissions'] = __( 'Submissions', 'frankas-block-forms' );
	$columns['fbf_used_on']     = __( 'Used on', 'frankas-block-forms' );
	if ( $date ) {
		$columns['date'] = $date;
	}
	return $columns;
}

add_action( 'manage_' . FRANKAS_BLOCK_FORMS_POST_TYPE . '_posts_custom_column', 'frankas_block_forms_list_column', 10, 2 );

function frankas_block_forms_list_column( string $column, int $form_id ) {
	static $counts = null;

	if ( 'fbf_submissions' === $column ) {
		if ( null === $counts ) {
			global $wp_query;
			$counts = frankas_block_forms_count_submissions( wp_list_pluck( $wp_query->posts, 'ID' ) );
		}
		$count = $counts[ $form_id ] ?? 0;
		if ( ! frankas_block_forms_get_settings( $form_id )['storeSubmissions'] && ! $count ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not saved', 'frankas-block-forms' ) . '</span>';
			return;
		}
		printf( '<a href="%s">%s</a>', esc_url( frankas_block_forms_submissions_url( $form_id ) ), esc_html( number_format_i18n( $count ) ) );
	}

	if ( 'fbf_used_on' === $column ) {
		$pages = frankas_block_forms_pages_using( $form_id );
		echo $pages
			? implode( ', ', array_map( static fn( $p ) => sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $p ) ), esc_html( get_the_title( $p ) ?: __( '(no title)', 'frankas-block-forms' ) ) ), $pages ) )
			: '<span aria-hidden="true">—</span>';
	}
}

/**
 * Pages and posts that contain a Form block showing this form.
 *
 * @return WP_Post[]
 */
function frankas_block_forms_pages_using( int $form_id ): array {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( '<!-- wp:frankas-block-forms/form {"formId":' . $form_id ) . '%';
	$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s", $like ) );

	// The LIKE also matches e.g. formId 12 when looking for 1; check exactly.
	return array_values( array_filter( array_map( 'get_post', $ids ), static function ( $post ) use ( $form_id ) {
		return $post && preg_match( '/<!-- wp:frankas-block-forms\/form \{[^}]*"formId":' . $form_id . '[,}]/', $post->post_content );
	} ) );
}

function frankas_block_forms_submissions_url( int $form_id ): string {
	return admin_url( 'edit.php?post_type=' . FRANKAS_BLOCK_FORMS_POST_TYPE . '&page=frankas-block-forms-submissions&form=' . $form_id );
}

add_filter( 'post_row_actions', 'frankas_block_forms_row_actions', 10, 2 );

function frankas_block_forms_row_actions( array $actions, WP_Post $post ): array {
	if ( FRANKAS_BLOCK_FORMS_POST_TYPE !== $post->post_type || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}
	unset( $actions['inline hide-if-no-js'] );

	$url = static fn( string $do ) => wp_nonce_url(
		admin_url( 'admin-post.php?action=frankas_block_forms_copy&do=' . $do . '&form=' . $post->ID ),
		'frankas_block_forms_copy_' . $post->ID
	);

	if ( frankas_block_forms_is_template( $post->ID ) ) {
		$actions['fbf_use'] = sprintf( '<a href="%s">%s</a>', esc_url( $url( 'form' ) ), esc_html__( 'New form from this template', 'frankas-block-forms' ) );
	} else {
		$actions['fbf_duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( $url( 'form' ) ), esc_html__( 'Duplicate', 'frankas-block-forms' ) );
		$actions['fbf_template']  = sprintf( '<a href="%s">%s</a>', esc_url( $url( 'template' ) ), esc_html__( 'Save as template', 'frankas-block-forms' ) );
		$actions['fbf_entries']   = sprintf( '<a href="%s">%s</a>', esc_url( frankas_block_forms_submissions_url( $post->ID ) ), esc_html__( 'Submissions', 'frankas-block-forms' ) );
	}
	return $actions;
}

add_action( 'admin_post_frankas_block_forms_copy', 'frankas_block_forms_handle_copy' );

/**
 * Row actions: copy a form as a form or as a template, then edit the copy.
 */
function frankas_block_forms_handle_copy() {
	$form_id = absint( $_GET['form'] ?? 0 );
	check_admin_referer( 'frankas_block_forms_copy_' . $form_id );
	if ( ! current_user_can( 'edit_post', $form_id ) ) {
		wp_die( esc_html__( 'You are not allowed to copy this form.', 'frankas-block-forms' ), 403 );
	}

	$as_template = 'template' === sanitize_key( wp_unslash( $_GET['do'] ?? '' ) );
	$title       = '';
	if ( ! $as_template && frankas_block_forms_is_template( $form_id ) ) {
		// A new form from a template: the template's name without "(template)".
		$title = preg_replace( '/\s*\([^)]*\)$/', '', get_the_title( $form_id ) );
	}

	$copy_id = frankas_block_forms_copy_form( $form_id, $as_template, $title );
	if ( is_wp_error( $copy_id ) ) {
		wp_die( esc_html( $copy_id->get_error_message() ) );
	}

	wp_safe_redirect( get_edit_post_link( $copy_id, 'raw' ) );
	exit;
}

add_filter( 'display_post_states', 'frankas_block_forms_post_states', 10, 2 );

function frankas_block_forms_post_states( array $states, WP_Post $post ): array {
	if ( FRANKAS_BLOCK_FORMS_POST_TYPE === $post->post_type && frankas_block_forms_is_template( $post->ID ) ) {
		$states['fbf_template'] = __( 'Template', 'frankas-block-forms' );
	}
	return $states;
}
