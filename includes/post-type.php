<?php
/**
 * The form post type. A form's content is its fields (Form Field blocks and
 * any text or layout blocks around them); its settings live in post meta.
 * Forms with the template flag are listed separately and can't be placed on
 * pages; they are starting points for new forms.
 */

defined( 'ABSPATH' ) || exit;

const FRANKAS_BLOCK_FORMS_POST_TYPE = 'frankas_block_form';

add_action( 'init', 'frankas_block_forms_register_post_type' );

function frankas_block_forms_register_post_type() {
	register_post_type( FRANKAS_BLOCK_FORMS_POST_TYPE, [
		'labels'              => [
			'name'               => __( 'Forms', 'frankas-block-forms' ),
			'singular_name'      => __( 'Form', 'frankas-block-forms' ),
			'menu_name'          => __( 'Forms', 'frankas-block-forms' ),
			'add_new'            => __( 'Add form', 'frankas-block-forms' ),
			'add_new_item'       => __( 'Add form', 'frankas-block-forms' ),
			'edit_item'          => __( 'Edit form', 'frankas-block-forms' ),
			'new_item'           => __( 'New form', 'frankas-block-forms' ),
			'search_items'       => __( 'Search forms', 'frankas-block-forms' ),
			'not_found'          => __( 'No forms found.', 'frankas-block-forms' ),
			'not_found_in_trash' => __( 'No forms found in the bin.', 'frankas-block-forms' ),
			'all_items'          => __( 'All forms', 'frankas-block-forms' ),
			'item_published'     => __( 'Form saved.', 'frankas-block-forms' ),
			'item_updated'       => __( 'Form updated.', 'frankas-block-forms' ),
		],
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_rest'        => true,
		'menu_icon'           => 'dashicons-feedback',
		'menu_position'       => 25,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'map_meta_cap'        => true,
		'capability_type'     => 'page',
		'supports'            => [ 'title', 'editor', 'custom-fields', 'revisions' ],
		'template'            => frankas_block_forms_new_form_template(),
	] );

	register_post_meta( FRANKAS_BLOCK_FORMS_POST_TYPE, FRANKAS_BLOCK_FORMS_SETTINGS_META, [
		'type'          => 'object',
		'single'        => true,
		'default'       => frankas_block_forms_default_settings(),
		'show_in_rest'  => [ 'schema' => frankas_block_forms_settings_schema() ],
		'auth_callback' => static fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
	] );

	register_post_meta( FRANKAS_BLOCK_FORMS_POST_TYPE, FRANKAS_BLOCK_FORMS_TEMPLATE_META, [
		'type'          => 'boolean',
		'single'        => true,
		'default'       => false,
		'show_in_rest'  => true,
		'auth_callback' => static fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
	] );
}

/**
 * Fields a brand-new form starts with.
 */
function frankas_block_forms_new_form_template(): array {
	return [
		[ 'frankas-block-forms/field', [ 'type' => 'text', 'label' => __( 'Name', 'frankas-block-forms' ), 'name' => 'name', 'required' => true ] ],
		[ 'frankas-block-forms/field', [ 'type' => 'email', 'label' => __( 'Email address', 'frankas-block-forms' ), 'name' => 'email', 'required' => true ] ],
		[ 'frankas-block-forms/field', [ 'type' => 'textarea', 'label' => __( 'Message', 'frankas-block-forms' ), 'name' => 'message' ] ],
	];
}

function frankas_block_forms_is_template( int $form_id ): bool {
	return (bool) get_post_meta( $form_id, FRANKAS_BLOCK_FORMS_TEMPLATE_META, true );
}

/**
 * Copy a form, its content and settings. Returns the new form's ID.
 *
 * @param bool $as_template Whether the copy is a template (or a normal form).
 */
/**
 * The title, or "Title 2", "Title 3"… if a form already has it, so forms
 * can be told apart in the Form block's list.
 */
function frankas_block_forms_unique_title( string $title ): string {
	global $wpdb;
	$taken = $wpdb->get_col( $wpdb->prepare(
		"SELECT post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND post_title LIKE %s",
		FRANKAS_BLOCK_FORMS_POST_TYPE,
		$wpdb->esc_like( $title ) . '%'
	) );
	$unique = $title;
	for ( $n = 2; in_array( $unique, $taken, true ); $n++ ) {
		$unique = $title . ' ' . $n;
	}
	return $unique;
}

function frankas_block_forms_copy_form( int $form_id, bool $as_template, string $title = '' ) {
	$form = get_post( $form_id );
	if ( ! $form || FRANKAS_BLOCK_FORMS_POST_TYPE !== $form->post_type ) {
		return new WP_Error( 'frankas_block_forms_not_found', __( 'Form not found.', 'frankas-block-forms' ) );
	}

	$title = $title ?: sprintf(
		/* translators: %s: title of the form that was copied */
		$as_template ? __( '%s (template)', 'frankas-block-forms' ) : __( '%s (copy)', 'frankas-block-forms' ),
		$form->post_title
	);

	$copy_id = wp_insert_post( [
		'post_type'    => FRANKAS_BLOCK_FORMS_POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => frankas_block_forms_unique_title( $title ),
		// Slashed because wp_insert_post unslashes; block attributes contain backslashes.
		'post_content' => wp_slash( $form->post_content ),
	], true );

	if ( is_wp_error( $copy_id ) ) {
		return $copy_id;
	}

	update_post_meta( $copy_id, FRANKAS_BLOCK_FORMS_SETTINGS_META, frankas_block_forms_get_settings( $form_id ) );
	update_post_meta( $copy_id, FRANKAS_BLOCK_FORMS_TEMPLATE_META, $as_template );

	return $copy_id;
}
