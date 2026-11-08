<?php
/**
 * Server render for frankas-block-forms/form.
 *
 * Posts to admin-post.php so it works without JavaScript; view.js sends it to
 * the REST route instead. See includes/submit.php.
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$form_id = (int) ( $attributes['formId'] ?? 0 );
$form    = $form_id ? get_post( $form_id ) : null;

// Nothing to show for missing, unpublished or template forms, or a form
// inside itself.
$rendering = &$GLOBALS['frankas_block_forms_rendering'];
$rendering ??= [];
if ( ! $form || FRANKAS_BLOCK_FORMS_POST_TYPE !== $form->post_type || 'publish' !== $form->post_status || frankas_block_forms_is_template( $form_id ) || isset( $rendering[ $form_id ] ) ) {
	return;
}

$settings = frankas_block_forms_get_settings( $form_id );
$result   = frankas_block_forms_returned_result( $form_id );
$page_id  = get_queried_object_id();
$return   = $page_id ? get_permalink( $page_id ) : home_url( '/' );

$wrapper = get_block_wrapper_attributes( [
	'class' => 'fbf-form-block',
	'id'    => 'fbf-form-' . $form_id,
] );
?>
<div <?php echo $wrapper; ?>>
	<?php if ( $result && $result['ok'] ) : ?>
		<div class="fbf-message fbf-message--success" role="status" tabindex="-1"><?php echo nl2br( esc_html( $result['message'] ) ); ?></div>
	<?php else : ?>
		<?php
		$rendering[ $form_id ] = true;
		frankas_block_forms_current( [
			'form_id' => $form_id,
			'errors'  => $result['errors'] ?? [],
			'values'  => $result['values'] ?? [],
		] );
		$fields = do_blocks( $form->post_content );
		frankas_block_forms_current( null );
		unset( $rendering[ $form_id ] );
		?>
		<form
			class="fbf-form"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-endpoint="<?php echo esc_url( rest_url( 'frankas-block-forms/v1/submit' ) ); ?>"
		>
			<div class="fbf-message fbf-message--error" role="alert"<?php echo $result ? '' : ' hidden'; ?>><?php echo esc_html( $result['message'] ?? '' ); ?></div>

			<div class="fbf-form__fields">
				<?php echo $fields; ?>
			</div>

			<input type="hidden" name="action" value="frankas_block_forms_submit">
			<input type="hidden" name="_fbf_form" value="<?php echo esc_attr( $form_id ); ?>">
			<input type="hidden" name="_fbf_page" value="<?php echo esc_attr( $page_id ); ?>">
			<input type="hidden" name="_fbf_return" value="<?php echo esc_url( $return ); ?>">
			<input type="hidden" name="_fbf_ts" value="<?php echo esc_attr( frankas_block_forms_timestamp( $form_id ) ); ?>">
			<div class="fbf-hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'frankas-block-forms' ); ?>
					<input type="text" name="_fbf_website" value="" tabindex="-1" autocomplete="off">
				</label>
			</div>

			<div class="fbf-form__actions">
				<button type="submit" class="fbf-submit"><?php echo esc_html( $settings['submitText'] ); ?></button>
			</div>
		</form>
	<?php endif; ?>
</div>
