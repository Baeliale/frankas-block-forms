<?php
/**
 * Per-form settings, stored as one object in the form's
 * _frankas_block_forms_settings meta and edited in the block editor
 * (src/shared/form-settings.js).
 *
 * In subjects and messages, {field_name} is replaced with the submitted
 * value and {form_title} with the form's title, {all_fields} with every answer.
 */

defined( 'ABSPATH' ) || exit;

const FRANKAS_BLOCK_FORMS_SETTINGS_META = '_frankas_block_forms_settings';
const FRANKAS_BLOCK_FORMS_TEMPLATE_META = '_frankas_block_forms_template';

function frankas_block_forms_default_settings(): array {
	return [
		// Button and what happens after sending.
		'submitText'       => __( 'Send', 'frankas-block-forms' ),
		'successMessage'   => __( 'Thank you, your message has been sent.', 'frankas-block-forms' ),
		'redirectUrl'      => '',

		// Saved submissions.
		'storeSubmissions' => true,

		// E-mail to the site owner.
		'notifyEnabled'    => true,
		'notifyTo'         => '',
		'notifySubject'    => __( 'New submission: {form_title}', 'frankas-block-forms' ),
		'replyToField'     => '',

		// Confirmation e-mail to the person who filled in the form.
		'confirmEnabled'   => false,
		'confirmField'     => '',
		'confirmSubject'   => __( 'We have received your message', 'frankas-block-forms' ),
		'confirmMessage'   => __( 'Thank you for your message. We will get back to you as soon as possible.', 'frankas-block-forms' )
			. "\n\n" . __( 'Your answers:', 'frankas-block-forms' ) . "\n{all_fields}",
	];
}

/**
 * REST schema of the settings object. Types follow the defaults above.
 */
function frankas_block_forms_settings_schema(): array {
	$properties = [];
	foreach ( frankas_block_forms_default_settings() as $key => $value ) {
		$properties[ $key ] = [ 'type' => is_bool( $value ) ? 'boolean' : 'string' ];
	}
	return [
		'type'                 => 'object',
		'properties'           => $properties,
		'additionalProperties' => false,
	];
}

/**
 * A form's settings, with defaults filled in.
 */
function frankas_block_forms_get_settings( int $form_id ): array {
	$saved = get_post_meta( $form_id, FRANKAS_BLOCK_FORMS_SETTINGS_META, true );
	return array_merge( frankas_block_forms_default_settings(), is_array( $saved ) ? $saved : [] );
}
