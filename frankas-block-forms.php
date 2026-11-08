<?php
/**
 * Plugin Name: Frankas Block Forms
 * Description: Forms built from blocks. Each form is a post with its own settings for e-mails, saved submissions and what happens after sending; place it on any page with the Form block.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Kynotrain
 * Text Domain: frankas-block-forms
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FRANKAS_BLOCK_FORMS_VERSION', '1.0.0' );
define( 'FRANKAS_BLOCK_FORMS_FILE', __FILE__ );
define( 'FRANKAS_BLOCK_FORMS_DIR', plugin_dir_path( __FILE__ ) );

require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/settings.php';
require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/post-type.php';
require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/fields.php';
require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/submissions.php';
require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/submit.php';
require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/forms.php';

if ( is_admin() ) {
	require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/admin.php';
	require_once FRANKAS_BLOCK_FORMS_DIR . 'includes/admin-submissions.php';
}

register_activation_hook( __FILE__, 'frankas_block_forms_install' );
add_action( 'plugins_loaded', 'frankas_block_forms_maybe_install' );

add_action( 'init', 'frankas_block_forms_load_textdomain', 1 );

function frankas_block_forms_load_textdomain() {
	load_plugin_textdomain( 'frankas-block-forms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'frankas_block_forms_register_blocks' );

function frankas_block_forms_register_blocks() {
	foreach ( glob( FRANKAS_BLOCK_FORMS_DIR . 'build/*/block.json' ) ?: [] as $file ) {
		$block_type = register_block_type( dirname( $file ) );
		if ( ! $block_type ) {
			continue;
		}
		foreach ( array_merge( $block_type->editor_script_handles, $block_type->view_script_handles ) as $handle ) {
			wp_set_script_translations( $handle, 'frankas-block-forms', FRANKAS_BLOCK_FORMS_DIR . 'languages' );
		}
	}
}

/**
 * Blocks that can go inside a form: fields plus text and layout. Used for the
 * form editor and for the Form block's inner blocks.
 */
function frankas_block_forms_allowed_blocks(): array {
	return apply_filters( 'frankas_block_forms_allowed_blocks', [
		'frankas-block-forms/field',
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/columns',
		'core/column',
		'core/group',
		'core/separator',
		'core/spacer',
	] );
}

// Late, so it wins over a theme's or site plugin's own list.
add_filter( 'allowed_block_types_all', 'frankas_block_forms_form_editor_blocks', 50, 2 );

function frankas_block_forms_form_editor_blocks( $allowed, $context ) {
	if ( isset( $context->post ) && FRANKAS_BLOCK_FORMS_POST_TYPE === $context->post->post_type ) {
		return frankas_block_forms_allowed_blocks();
	}
	return $allowed;
}

add_filter( 'block_categories_all', 'frankas_block_forms_block_category', 10, 2 );

/**
 * A "Form" category for the form blocks; first in the form editor, where the
 * fields are what you add most.
 */
function frankas_block_forms_block_category( array $categories, $context ): array {
	$category = [ 'slug' => 'frankas-block-forms', 'title' => __( 'Form', 'frankas-block-forms' ), 'icon' => null ];
	$in_form  = isset( $context->post ) && FRANKAS_BLOCK_FORMS_POST_TYPE === $context->post->post_type;
	return $in_form ? array_merge( [ $category ], $categories ) : array_merge( $categories, [ $category ] );
}

add_action( 'enqueue_block_editor_assets', 'frankas_block_forms_enqueue_editor_assets' );

/**
 * The settings panel in the form editor (src/editor), plus what the blocks
 * need to know about the screen they are on.
 */
function frankas_block_forms_enqueue_editor_assets() {
	$asset_file = FRANKAS_BLOCK_FORMS_DIR . 'build/editor/index.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}
	$asset = require $asset_file;

	wp_enqueue_script( 'frankas-block-forms-editor', plugins_url( 'build/editor/index.js', __FILE__ ), $asset['dependencies'], $asset['version'], true );
	wp_set_script_translations( 'frankas-block-forms-editor', 'frankas-block-forms', FRANKAS_BLOCK_FORMS_DIR . 'languages' );
	if ( file_exists( FRANKAS_BLOCK_FORMS_DIR . 'build/editor/index.css' ) ) {
		wp_enqueue_style( 'frankas-block-forms-editor', plugins_url( 'build/editor/index.css', __FILE__ ), [], $asset['version'] );
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// Loaded before the blocks register, so the field block can drop its
	// "only inside a Form block" rule in the form editor itself.
	wp_add_inline_script(
		'wp-blocks',
		'window.frankasBlockForms = ' . wp_json_encode( [
			'postType'       => FRANKAS_BLOCK_FORMS_POST_TYPE,
			'isFormEditor'   => $screen && FRANKAS_BLOCK_FORMS_POST_TYPE === $screen->post_type,
			'defaults'       => frankas_block_forms_default_settings(),
			'allowedBlocks'  => frankas_block_forms_allowed_blocks(),
			'adminEmail'     => get_option( 'admin_email' ),
			'submissionsUrl' => admin_url( 'edit.php?post_type=' . FRANKAS_BLOCK_FORMS_POST_TYPE . '&page=frankas-block-forms-submissions&form=' ),
			'editUrl'        => admin_url( 'post.php?action=edit&post=' ),
			'templatesUrl'   => admin_url( 'edit.php?post_type=' . FRANKAS_BLOCK_FORMS_POST_TYPE . '&fbf_view=templates' ),
		] ) . ';',
		'before'
	);
}
