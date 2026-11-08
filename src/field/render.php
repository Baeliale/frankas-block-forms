<?php
/**
 * Server render for frankas-block-forms/field.
 * Entered values and errors come from the form being rendered (includes/forms.php),
 * after a submission without JavaScript.
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$field   = frankas_block_forms_field( $attributes );
$current = frankas_block_forms_current() ?? [ 'form_id' => 0, 'errors' => [], 'values' => [] ];
$name    = $field['name'];
$id      = 'fbf-' . $current['form_id'] . '-' . $name;
$value   = (string) ( $current['values'][ $name ] ?? '' );
$error   = (string) ( $current['errors'][ $name ] ?? '' );
$type    = $field['type'];

// The label may contain simple formatting and links (e.g. to a privacy statement).
$label = wp_kses( $attributes['label'] ?? '', [ 'strong' => [], 'em' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] );

$attrs = sprintf( ' name="fbf[%s]"', esc_attr( $name ) );
if ( $field['required'] ) {
	$attrs .= ' required aria-required="true"';
}
if ( $error ) {
	$attrs .= sprintf( ' aria-invalid="true" aria-describedby="%s-error"', esc_attr( $id ) );
}
// Let browsers fill in the visitor's own details.
$autocomplete = [ 'email' => 'email', 'tel' => 'tel' ][ $type ] ?? ( in_array( $name, [ 'name', 'naam' ], true ) ? 'name' : '' );
if ( $autocomplete ) {
	$attrs .= sprintf( ' autocomplete="%s"', $autocomplete );
}
$placeholder = $field['placeholder'] ? sprintf( ' placeholder="%s"', esc_attr( $field['placeholder'] ) ) : '';
$required    = $field['required'] ? '<span class="fbf-required" aria-hidden="true">*</span>' : '';

$wrapper = get_block_wrapper_attributes( [
	'class'     => 'fbf-field fbf-field--' . $type . ( $error ? ' has-error' : '' ),
	'data-name' => $name,
] );
$tag = 'radio' === $type ? 'fieldset' : 'div';
?>
<<?php echo $tag; ?> <?php echo $wrapper; ?>>
	<?php if ( 'checkbox' === $type ) : ?>
		<label class="fbf-option" for="<?php echo esc_attr( $id ); ?>">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" value="1"<?php echo $attrs; ?><?php checked( '' !== $value ); ?>>
			<span><?php echo $label; ?><?php echo $required; ?></span>
		</label>
	<?php elseif ( 'radio' === $type ) : ?>
		<legend class="fbf-label"><?php echo $label; ?><?php echo $required; ?></legend>
		<?php foreach ( $field['options'] as $i => $option ) : ?>
			<label class="fbf-option" for="<?php echo esc_attr( $id . '-' . $i ); ?>">
				<input type="radio" id="<?php echo esc_attr( $id . '-' . $i ); ?>" value="<?php echo esc_attr( $option ); ?>"<?php echo $attrs; ?><?php checked( $value, $option ); ?>>
				<span><?php echo esc_html( $option ); ?></span>
			</label>
		<?php endforeach; ?>
	<?php else : ?>
		<label class="fbf-label" for="<?php echo esc_attr( $id ); ?>"><?php echo $label; ?><?php echo $required; ?></label>
		<?php if ( 'textarea' === $type ) : ?>
			<textarea class="fbf-textarea" id="<?php echo esc_attr( $id ); ?>"<?php echo $attrs . $placeholder; ?>><?php echo esc_textarea( $value ); ?></textarea>
		<?php elseif ( 'select' === $type ) : ?>
			<select class="fbf-select" id="<?php echo esc_attr( $id ); ?>"<?php echo $attrs; ?>>
				<option value=""><?php esc_html_e( 'Choose…', 'frankas-block-forms' ); ?></option>
				<?php foreach ( $field['options'] as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>"<?php selected( $value, $option ); ?>><?php echo esc_html( $option ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input class="fbf-input" type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php echo $attrs . $placeholder; ?>>
		<?php endif; ?>
	<?php endif; ?>
	<span class="fbf-error" id="<?php echo esc_attr( $id ); ?>-error"<?php echo $error ? '' : ' hidden'; ?>><?php echo esc_html( $error ); ?></span>
</<?php echo $tag; ?>>
