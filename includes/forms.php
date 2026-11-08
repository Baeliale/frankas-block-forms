<?php
/**
 * Rendering a form: the Form block (src/form/render.php) sets up the form
 * being rendered here, and each Form Field block reads its ID, entered value
 * and error from it.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The form being rendered, or set it (null to clear).
 *
 * @param array|null|false $state [ 'form_id' => int, 'errors' => [], 'values' => [] ]; false to read.
 */
function frankas_block_forms_current( $state = false ): ?array {
	static $current = null;
	if ( false !== $state ) {
		$current = $state;
	}
	return $current;
}

/**
 * Result of a submission without JavaScript, when the visitor was sent back
 * to the page with ?fbf_sent or ?fbf_token.
 *
 * @return array{ok: bool, message: string, errors: array, values: array}|null
 */
function frankas_block_forms_returned_result( int $form_id ): ?array {
	// phpcs:disable WordPress.Security.NonceVerification -- read-only display state.
	if ( isset( $_GET['fbf_sent'] ) && (int) $_GET['fbf_sent'] === $form_id ) {
		return [
			'ok'      => true,
			'message' => frankas_block_forms_get_settings( $form_id )['successMessage'],
			'errors'  => [],
			'values'  => [],
		];
	}
	if ( isset( $_GET['fbf_token'] ) ) {
		$result = get_transient( 'fbf_' . sanitize_key( wp_unslash( $_GET['fbf_token'] ) ) );
		if ( is_array( $result ) && (int) ( $result['form'] ?? 0 ) === $form_id ) {
			return $result;
		}
	}
	// phpcs:enable
	return null;
}
