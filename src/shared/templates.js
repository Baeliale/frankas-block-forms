/**
 * Creating forms from the editor: new forms (blank or from a template) and
 * saving a form as a template.
 */
import { __, sprintf } from '@wordpress/i18n';
import { createBlock, serialize } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { FIELD_BLOCK, POST_TYPE, SETTINGS_META, TEMPLATE_META } from './form-settings';

// Same fields as frankas_block_forms_new_form_template() in includes/post-type.php.
export const blankFormContent = () =>
	serialize( [
		createBlock( FIELD_BLOCK, { type: 'text', label: __( 'Name', 'frankas-block-forms' ), name: 'name', required: true } ),
		createBlock( FIELD_BLOCK, { type: 'email', label: __( 'Email address', 'frankas-block-forms' ), name: 'email', required: true } ),
		createBlock( FIELD_BLOCK, { type: 'textarea', label: __( 'Message', 'frankas-block-forms' ), name: 'message' } ),
	] );

/**
 * @return {{ createForm: Function, saveAsTemplate: Function }}
 */
export function useFormActions() {
	const { saveEntityRecord } = useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	/**
	 * @param {Object}  args
	 * @param {string}  args.title
	 * @param {string}  args.content    Serialized blocks.
	 * @param {Object}  args.settings   Form settings, or undefined for the defaults.
	 * @param {boolean} args.isTemplate
	 * @return {Promise<Object|undefined>} The new form record.
	 */
	const createForm = async ( { title, content, settings, isTemplate = false } ) => {
		const meta = { [ TEMPLATE_META ]: isTemplate };
		if ( settings ) {
			meta[ SETTINGS_META ] = settings;
		}
		try {
			return await saveEntityRecord( 'postType', POST_TYPE, { title, content, status: 'publish', meta }, { throwOnError: true } );
		} catch ( error ) {
			createErrorNotice( error?.message || __( 'The form could not be created.', 'frankas-block-forms' ), { type: 'snackbar' } );
		}
	};

	const saveAsTemplate = async ( { title, blocks, settings } ) => {
		const template = await createForm( {
			/* translators: %s: form title */
			title: sprintf( __( '%s (template)', 'frankas-block-forms' ), title || __( 'Form', 'frankas-block-forms' ) ),
			content: serialize( blocks ),
			settings,
			isTemplate: true,
		} );
		if ( template ) {
			createSuccessNotice( __( 'Saved as a template. You can start new forms from it.', 'frankas-block-forms' ), { type: 'snackbar' } );
		}
		return template;
	};

	return { createForm, saveAsTemplate };
}
