/**
 * Form editor: the form's settings in the sidebar, plus the template switch
 * and "Save as template". Only on the form post type.
 */
import { __ } from '@wordpress/i18n';
import { Button, ExternalLink, ToggleControl } from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel, store as editorStore } from '@wordpress/editor';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import FormSettings, { collectFields, useFormSettings, POST_TYPE, TEMPLATE_META } from '../shared/form-settings';
import { useFormActions } from '../shared/templates';
import '../shared/_settings.scss';

/**
 * The submit button under the fields, as visitors will see it. The canvas
 * has no slot for this, so it goes into a container placed after the block
 * list; it is re-added when the canvas is rebuilt (e.g. device preview).
 */
function SubmitPreview( { text } ) {
	const [ container, setContainer ] = useState( null );

	useEffect( () => {
		const ensure = () => {
			const iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
			const doc = iframe?.contentDocument || document;
			const root = doc.querySelector( '.is-root-container' );
			if ( ! root ) {
				return;
			}
			let el = doc.querySelector( '.fbf-editor-submit' );
			if ( ! el ) {
				el = doc.createElement( 'div' );
				el.className = 'fbf-editor-submit fbf-form__actions';
				root.after( el );
			}
			setContainer( ( current ) => ( current === el ? current : el ) );
		};
		ensure();
		const timer = setInterval( ensure, 1000 );
		return () => clearInterval( timer );
	}, [] );

	return container
		? createPortal(
			<button type="button" className="fbf-submit" disabled>
				{ text }
			</button>,
			container
		)
		: null;
}

function FormPanels() {
	const { postId, postType, title, blocks } = useSelect( ( select ) => ( {
		postId: select( editorStore ).getCurrentPostId(),
		postType: select( editorStore ).getCurrentPostType(),
		title: select( editorStore ).getEditedPostAttribute( 'title' ),
		blocks: select( blockEditorStore ).getBlocks(),
	} ) );
	const [ settings, updateSettings ] = useFormSettings( postId );
	const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta', postId );
	const { saveAsTemplate } = useFormActions();
	const [ isSaving, setIsSaving ] = useState( false );

	if ( postType !== POST_TYPE ) {
		return null;
	}

	const isTemplate = !! meta?.[ TEMPLATE_META ];

	return (
		<>
			<SubmitPreview text={ settings.submitText } />
			<PluginDocumentSettingPanel name="frankas-block-forms-settings" title={ __( 'Form settings', 'frankas-block-forms' ) }>
				<FormSettings settings={ settings } onChange={ updateSettings } fields={ collectFields( blocks ) } formId={ postId } />
			</PluginDocumentSettingPanel>
			<PluginDocumentSettingPanel name="frankas-block-forms-template" title={ __( 'Template', 'frankas-block-forms' ) }>
				<ToggleControl
					label={ __( 'This form is a template', 'frankas-block-forms' ) }
					help={ __( 'Templates are starting points for new forms. They can’t be placed on pages.', 'frankas-block-forms' ) }
					checked={ isTemplate }
					onChange={ ( value ) => setMeta( { ...meta, [ TEMPLATE_META ]: value } ) }
					__nextHasNoMarginBottom
				/>
				{ ! isTemplate && (
					<Button
						variant="secondary"
						isBusy={ isSaving }
						disabled={ isSaving }
						onClick={ async () => {
							setIsSaving( true );
							await saveAsTemplate( { title, blocks, settings } );
							setIsSaving( false );
						} }
					>
						{ __( 'Save a copy as template', 'frankas-block-forms' ) }
					</Button>
				) }
				<p>
					<ExternalLink href={ `${ window.frankasBlockForms?.templatesUrl || '' }` }>{ __( 'All templates', 'frankas-block-forms' ) }</ExternalLink>
				</p>
			</PluginDocumentSettingPanel>
		</>
	);
}

if ( window.frankasBlockForms?.isFormEditor ) {
	registerPlugin( 'frankas-block-forms', { render: FormPanels } );
}
