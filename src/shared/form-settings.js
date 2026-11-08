/**
 * A form's settings, shown in the form editor's sidebar (src/editor) and in
 * the Form block's settings window (src/form). Stored in the form's
 * _frankas_block_forms_settings meta; see includes/settings.php.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Notice, SelectControl, TextControl, TextareaControl, ToggleControl, ExternalLink } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useMemo } from '@wordpress/element';
import fieldName from './field-name';

export const POST_TYPE = window.frankasBlockForms?.postType || 'frankas_block_form';
export const SETTINGS_META = '_frankas_block_forms_settings';
export const TEMPLATE_META = '_frankas_block_forms_template';
export const FIELD_BLOCK = 'frankas-block-forms/field';

/**
 * Fields in a list of blocks, at any depth: [ { name, label, type } ].
 *
 * @param {Array} blocks
 */
export function collectFields( blocks ) {
	const fields = [];
	const walk = ( list ) =>
		( list || [] ).forEach( ( block ) => {
			if ( block.name === FIELD_BLOCK ) {
				const { name, label, type = 'text' } = block.attributes;
				fields.push( { name: fieldName( name, label ), label: ( label || '' ).replace( /<[^>]*>/g, '' ), type } );
			}
			walk( block.innerBlocks );
		} );
	walk( blocks );
	return fields;
}

/**
 * A form's settings with defaults filled in, and a setter for some of them.
 *
 * @param {number} formId
 */
export function useFormSettings( formId ) {
	const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta', formId );
	const settings = useMemo( () => ( { ...( window.frankasBlockForms?.defaults || {} ), ...( meta?.[ SETTINGS_META ] || {} ) } ), [ meta ] );
	const update = ( changes ) => setMeta( { ...meta, [ SETTINGS_META ]: { ...settings, ...changes } } );
	return [ settings, update ];
}

function Group( { title, children } ) {
	return (
		<fieldset className="fbf-settings__group">
			<legend className="fbf-settings__legend">{ title }</legend>
			{ children }
		</fieldset>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.settings Current settings.
 * @param {Function} props.onChange Called with the changed settings.
 * @param {Array}    props.fields   The form's fields, from collectFields().
 * @param {number}   props.formId   For the link to the submissions.
 */
export default function FormSettings( { settings, onChange, fields, formId } ) {
	const set = ( key ) => ( value ) => onChange( { [ key ]: value } );
	const emailOptions = [
		{ label: __( '— None —', 'frankas-block-forms' ), value: '' },
		...fields.filter( ( f ) => f.type === 'email' ).map( ( f ) => ( { label: f.label || f.name, value: f.name } ) ),
	];
	const tagsHelp = __( 'Use {field_name} for an answer, {all_fields} for all answers and {form_title} for the name of the form.', 'frankas-block-forms' );

	return (
		<div className="fbf-settings">
			{ ! settings.storeSubmissions && ! settings.notifyEnabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Submissions are neither saved nor e-mailed, so nobody will see them.', 'frankas-block-forms' ) }
				</Notice>
			) }

			<Group title={ __( 'Button and thank-you message', 'frankas-block-forms' ) }>
				<TextControl label={ __( 'Button text', 'frankas-block-forms' ) } value={ settings.submitText } onChange={ set( 'submitText' ) } __nextHasNoMarginBottom />
				<TextareaControl label={ __( 'Thank-you message', 'frankas-block-forms' ) } value={ settings.successMessage } onChange={ set( 'successMessage' ) } rows={ 3 } __nextHasNoMarginBottom />
				<TextControl
					label={ __( 'Or go to this page', 'frankas-block-forms' ) }
					help={ __( 'An address such as /thank-you. Leave empty to show the thank-you message instead.', 'frankas-block-forms' ) }
					value={ settings.redirectUrl }
					onChange={ set( 'redirectUrl' ) }
					__nextHasNoMarginBottom
				/>
			</Group>

			<Group title={ __( 'Submissions', 'frankas-block-forms' ) }>
				<ToggleControl
					label={ __( 'Save submissions', 'frankas-block-forms' ) }
					help={ __( 'Keep every submission in WordPress, under Forms › Submissions. Turn off if the e-mail is enough.', 'frankas-block-forms' ) }
					checked={ settings.storeSubmissions }
					onChange={ set( 'storeSubmissions' ) }
					__nextHasNoMarginBottom
				/>
				{ formId > 0 && window.frankasBlockForms?.submissionsUrl && (
					<ExternalLink href={ window.frankasBlockForms.submissionsUrl + formId }>{ __( 'View submissions', 'frankas-block-forms' ) }</ExternalLink>
				) }
			</Group>

			<Group title={ __( 'E-mail to you', 'frankas-block-forms' ) }>
				<ToggleControl label={ __( 'Send an e-mail for every submission', 'frankas-block-forms' ) } checked={ settings.notifyEnabled } onChange={ set( 'notifyEnabled' ) } __nextHasNoMarginBottom />
				{ settings.notifyEnabled && (
					<>
						<TextControl
							label={ __( 'Send to', 'frankas-block-forms' ) }
							help={ sprintf(
								/* translators: %s: the site's admin e-mail address */
								__( 'Separate several addresses with commas. Empty: %s (the site’s admin address).', 'frankas-block-forms' ),
								window.frankasBlockForms?.adminEmail || ''
							) }
							value={ settings.notifyTo }
							placeholder={ window.frankasBlockForms?.adminEmail || '' }
							onChange={ set( 'notifyTo' ) }
							__nextHasNoMarginBottom
						/>
						<TextControl label={ __( 'Subject', 'frankas-block-forms' ) } help={ tagsHelp } value={ settings.notifySubject } onChange={ set( 'notifySubject' ) } __nextHasNoMarginBottom />
						<SelectControl
							label={ __( 'Reply to the address in', 'frankas-block-forms' ) }
							help={ __( 'So that clicking Reply in your e-mail program answers the person who filled in the form.', 'frankas-block-forms' ) }
							value={ settings.replyToField }
							options={ emailOptions }
							onChange={ set( 'replyToField' ) }
							__nextHasNoMarginBottom
						/>
					</>
				) }
			</Group>

			<Group title={ __( 'Confirmation e-mail', 'frankas-block-forms' ) }>
				<ToggleControl label={ __( 'Send a confirmation to the person who filled in the form', 'frankas-block-forms' ) } checked={ settings.confirmEnabled } onChange={ set( 'confirmEnabled' ) } __nextHasNoMarginBottom />
				{ settings.confirmEnabled && (
					<>
						<SelectControl
							label={ __( 'Send to the address in', 'frankas-block-forms' ) }
							help={ emailOptions.length < 2 ? __( 'Add a field of the type Email to the form first.', 'frankas-block-forms' ) : undefined }
							value={ settings.confirmField }
							options={ emailOptions }
							onChange={ set( 'confirmField' ) }
							__nextHasNoMarginBottom
						/>
						<TextControl label={ __( 'Subject', 'frankas-block-forms' ) } value={ settings.confirmSubject } onChange={ set( 'confirmSubject' ) } __nextHasNoMarginBottom />
						<TextareaControl label={ __( 'Message', 'frankas-block-forms' ) } help={ tagsHelp } value={ settings.confirmMessage } onChange={ set( 'confirmMessage' ) } rows={ 6 } __nextHasNoMarginBottom />
					</>
				) }
			</Group>
		</div>
	);
}
