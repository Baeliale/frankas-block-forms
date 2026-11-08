<?php
/**
 * Handling a submitted form. Forms post to admin-post.php so they work
 * without JavaScript; src/form/view.js sends the same fields to the REST
 * route instead and shows the result without a page reload.
 *
 * Spam protection, without any outside service: a hidden field people
 * don't fill in, and a signed timestamp that must be at least a few seconds
 * old (bots submit instantly). No nonce, so cached pages keep working.
 */

defined( 'ABSPATH' ) || exit;

const FRANKAS_BLOCK_FORMS_MIN_SECONDS = 3;
const FRANKAS_BLOCK_FORMS_MAX_LENGTH  = 5000;

/**
 * Signed time the form was shown, checked on submit.
 */
function frankas_block_forms_timestamp( int $form_id ): string {
	$time = (string) time();
	return $time . '.' . hash_hmac( 'sha256', $time . '|' . $form_id, wp_salt( 'nonce' ) );
}

function frankas_block_forms_timestamp_ok( string $stamp, int $form_id ): bool {
	[ $time, $sig ] = array_pad( explode( '.', $stamp, 2 ), 2, '' );
	return ctype_digit( $time )
		&& hash_equals( hash_hmac( 'sha256', $time . '|' . $form_id, wp_salt( 'nonce' ) ), $sig )
		&& time() - (int) $time >= FRANKAS_BLOCK_FORMS_MIN_SECONDS;
}

/**
 * Validate, save and e-mail one submission.
 *
 * @param array $post The posted data ($_POST or the REST body), unslashed.
 * @return array{ok: bool, message: string, errors: array<string,string>, values: array<string,string>, redirect: string}
 */
function frankas_block_forms_handle_submission( array $post ): array {
	$form_id = (int) ( $post['_fbf_form'] ?? 0 );
	$page_id = (int) ( $post['_fbf_page'] ?? 0 );
	$input   = is_array( $post['fbf'] ?? null ) ? $post['fbf'] : [];
	$result  = [ 'ok' => false, 'message' => '', 'errors' => [], 'values' => [], 'redirect' => '' ];
	$failed  = __( 'Your message could not be sent. Please try again later.', 'frankas-block-forms' );

	$form = get_post( $form_id );
	if ( ! $form || FRANKAS_BLOCK_FORMS_POST_TYPE !== $form->post_type || 'publish' !== $form->post_status || frankas_block_forms_is_template( $form_id ) ) {
		$result['message'] = $failed;
		return $result;
	}

	// Spam: pretend it worked, so bots learn nothing.
	if ( '' !== ( $post['_fbf_website'] ?? '' ) || ! frankas_block_forms_timestamp_ok( (string) ( $post['_fbf_ts'] ?? '' ), $form_id ) ) {
		$result['ok']      = true;
		$result['message'] = frankas_block_forms_get_settings( $form_id )['successMessage'];
		return $result;
	}

	$fields   = frankas_block_forms_get_fields( $form_id );
	$settings = frankas_block_forms_get_settings( $form_id );
	$data     = [];

	foreach ( $fields as $name => $field ) {
		$raw   = $input[ $name ] ?? '';
		$value = is_string( $raw ) ? trim( mb_substr( $raw, 0, FRANKAS_BLOCK_FORMS_MAX_LENGTH ) ) : '';
		$value = 'textarea' === $field['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

		$result['values'][ $name ] = $value;
		$error                     = frankas_block_forms_validate_field( $field, $value );
		if ( $error ) {
			$result['errors'][ $name ] = $error;
			continue;
		}

		$data[ $name ] = [
			'label' => $field['label'],
			'value' => 'checkbox' === $field['type'] ? ( '' !== $value ? __( 'Yes', 'frankas-block-forms' ) : __( 'No', 'frankas-block-forms' ) ) : $value,
		];
	}

	if ( $result['errors'] ) {
		$result['message'] = __( 'Please check the fields marked below.', 'frankas-block-forms' );
		return $result;
	}

	$stored = $settings['storeSubmissions'] ? frankas_block_forms_insert_submission( $form_id, $page_id, $data ) : 0;
	$mailed = frankas_block_forms_send_emails( $form, $settings, $data, $page_id );

	// Only a failure if the submission was neither saved nor e-mailed.
	if ( ! $stored && ! $mailed ) {
		$result['message'] = $failed;
		return $result;
	}

	/**
	 * Fires after a submission was accepted.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $data    Answers: [ name => [ 'label' => …, 'value' => … ] ].
	 * @param int   $stored  Submission ID, or 0 when submissions aren't saved.
	 */
	do_action( 'frankas_block_forms_submitted', $form_id, $data, $stored );

	$result['ok']       = true;
	$result['message']  = $settings['successMessage'];
	$result['redirect'] = $settings['redirectUrl'] ? esc_url_raw( $settings['redirectUrl'] ) : '';
	$result['values']   = [];
	return $result;
}

/**
 * Error message for a field value, or '' when it's fine.
 */
function frankas_block_forms_validate_field( array $field, string $value ): string {
	if ( '' === $value ) {
		return $field['required'] ? __( 'This field is required.', 'frankas-block-forms' ) : '';
	}

	switch ( $field['type'] ) {
		case 'email':
			return is_email( $value ) ? '' : __( 'Enter a valid email address.', 'frankas-block-forms' );
		case 'number':
			return is_numeric( $value ) ? '' : __( 'Enter a number.', 'frankas-block-forms' );
		case 'date':
			$date = DateTime::createFromFormat( '!Y-m-d', $value );
			return $date && $date->format( 'Y-m-d' ) === $value ? '' : __( 'Enter a valid date.', 'frankas-block-forms' );
		case 'select':
		case 'radio':
			return in_array( $value, $field['options'], true ) ? '' : __( 'Choose one of the options.', 'frankas-block-forms' );
	}
	return '';
}

/**
 * Notification to the site owner and confirmation to the sender, as set in
 * the form settings. Returns whether any e-mail was sent.
 */
function frankas_block_forms_send_emails( WP_Post $form, array $settings, array $data, int $page_id ): bool {
	$sent    = false;
	$replace = frankas_block_forms_tags( $form, $data );

	if ( $settings['notifyEnabled'] ) {
		$to = array_filter( array_map( 'trim', explode( ',', $settings['notifyTo'] ?: get_option( 'admin_email' ) ) ), 'is_email' );

		$body = $replace( '{all_fields}' );
		if ( $page_id ) {
			/* translators: %s: address of the page the form was sent from */
			$body .= "\n\n" . sprintf( __( 'Sent from: %s', 'frankas-block-forms' ), get_permalink( $page_id ) );
		}

		$headers  = [];
		$reply_to = $data[ $settings['replyToField'] ]['value'] ?? '';
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		if ( $to ) {
			$sent = wp_mail( $to, frankas_block_forms_one_line( $replace( $settings['notifySubject'] ) ), $body, $headers ) || $sent;
		}
	}

	if ( $settings['confirmEnabled'] ) {
		$to = $data[ $settings['confirmField'] ]['value'] ?? '';
		if ( $to && is_email( $to ) ) {
			$sent = wp_mail( $to, frankas_block_forms_one_line( $replace( $settings['confirmSubject'] ) ), $replace( $settings['confirmMessage'] ) ) || $sent;
		}
	}

	return $sent;
}

/**
 * Replacer for {form_title}, {all_fields} and {field_name} tags.
 */
function frankas_block_forms_tags( WP_Post $form, array $data ): callable {
	$all = [];
	foreach ( $data as $answer ) {
		$all[] = $answer['label'] . ': ' . ( '' === $answer['value'] ? '–' : $answer['value'] );
	}

	$tags = [
		'{form_title}' => get_the_title( $form ),
		'{all_fields}' => implode( "\n", $all ),
	];
	foreach ( $data as $name => $answer ) {
		$tags[ '{' . $name . '}' ] = $answer['value'];
	}

	return static fn( string $text ) => strtr( $text, $tags );
}

function frankas_block_forms_one_line( string $text ): string {
	return trim( preg_replace( '/\s+/', ' ', $text ) );
}

add_action( 'admin_post_nopriv_frankas_block_forms_submit', 'frankas_block_forms_submit_without_js' );
add_action( 'admin_post_frankas_block_forms_submit', 'frankas_block_forms_submit_without_js' );

/**
 * Plain form post: handle it, then go back to the page. Errors and the
 * entered values travel through a short-lived transient.
 */
function frankas_block_forms_submit_without_js() {
	$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- public form, see the spam checks.
	$result  = frankas_block_forms_handle_submission( $post );
	$form_id = (int) ( $post['_fbf_form'] ?? 0 );
	$back    = wp_validate_redirect( (string) ( $post['_fbf_return'] ?? '' ), home_url( '/' ) );
	$back    = remove_query_arg( [ 'fbf_sent', 'fbf_token' ], $back );

	if ( $result['ok'] ) {
		$url = $result['redirect'] ?: add_query_arg( 'fbf_sent', $form_id, $back ) . '#fbf-form-' . $form_id;
	} else {
		$token = wp_generate_password( 12, false );
		set_transient( 'fbf_' . $token, [ 'form' => $form_id ] + $result, 10 * MINUTE_IN_SECONDS );
		$url = add_query_arg( 'fbf_token', $token, $back ) . '#fbf-form-' . $form_id;
	}

	wp_safe_redirect( $url );
	exit;
}

add_action( 'rest_api_init', 'frankas_block_forms_register_rest_route' );

function frankas_block_forms_register_rest_route() {
	register_rest_route( 'frankas-block-forms/v1', '/submit', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => static function ( WP_REST_Request $request ) {
			$result = frankas_block_forms_handle_submission( $request->get_body_params() );
			unset( $result['values'] );
			return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
		},
	] );
}
