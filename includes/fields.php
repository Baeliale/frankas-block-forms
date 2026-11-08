<?php
/**
 * Field definitions, read from the Form Field blocks in a form's content.
 * Rendering (src/field/render.php) and validation (includes/submit.php)
 * both go through here, so they always agree on names and options.
 */

defined( 'ABSPATH' ) || exit;

const FRANKAS_BLOCK_FORMS_FIELD_TYPES = [ 'text', 'email', 'tel', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox' ];

/**
 * A field's normalised settings from its block attributes.
 */
function frankas_block_forms_field( array $attrs ): array {
	$type  = in_array( $attrs['type'] ?? '', FRANKAS_BLOCK_FORMS_FIELD_TYPES, true ) ? $attrs['type'] : 'text';
	$label = wp_strip_all_tags( $attrs['label'] ?? '' );

	return [
		'type'        => $type,
		'label'       => $label,
		'name'        => frankas_block_forms_field_name( $attrs['name'] ?? '', $label ),
		'placeholder' => (string) ( $attrs['placeholder'] ?? '' ),
		'options'     => frankas_block_forms_field_options( (string) ( $attrs['options'] ?? '' ) ),
		'required'    => ! empty( $attrs['required'] ),
	];
}

/**
 * The submitted name: as set on the field, or made from the label.
 * Must match fieldName() in src/field/index.js.
 */
function frankas_block_forms_field_name( string $name, string $label ): string {
	$slug = static fn( string $text ) => trim( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( remove_accents( wp_strip_all_tags( $text ) ) ) ), '_' );
	return $slug( $name ) ?: ( $slug( $label ) ?: 'field' );
}

/**
 * One option per line. Must match parseOptions() in src/field/index.js.
 */
function frankas_block_forms_field_options( string $options ): array {
	return array_values( array_filter( array_map( 'trim', explode( "\n", $options ) ), 'strlen' ) );
}

/**
 * All fields of a form, in order, keyed by name. If two fields share a name,
 * the first wins.
 */
function frankas_block_forms_get_fields( int $form_id ): array {
	$form = get_post( $form_id );
	if ( ! $form ) {
		return [];
	}

	$fields = [];
	$walk   = static function ( array $blocks ) use ( &$walk, &$fields ) {
		foreach ( $blocks as $block ) {
			if ( 'frankas-block-forms/field' === $block['blockName'] ) {
				$field = frankas_block_forms_field( $block['attrs'] );
				$fields[ $field['name'] ] ??= $field;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( $form->post_content ) );

	return $fields;
}
