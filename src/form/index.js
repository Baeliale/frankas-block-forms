/**
 * Form block: shows a form post. The form's fields are edited in place, like a
 * synced pattern: they belong to the form post, so changes are saved to the
 * form (WordPress asks to save it along with the page) and show up everywhere
 * the form is used.
 */
import { __, sprintf } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	BlockControls,
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
	Warning,
} from '@wordpress/block-editor';
import {
	Button,
	ExternalLink,
	Modal,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
	TextControl,
	ToolbarButton,
	ToolbarGroup,
	Flex,
} from '@wordpress/components';
import { useEntityBlockEditor, useEntityProp, useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import FormSettings, { collectFields, useFormSettings, POST_TYPE, SETTINGS_META, TEMPLATE_META } from '../shared/form-settings';
import { blankFormContent, useFormActions } from '../shared/templates';
import metadata from './block.json';
import './editor.scss';
import './style.scss';

// From frankas_block_forms_allowed_blocks() in PHP; the list below is a fallback.
const ALLOWED_BLOCKS = window.frankasBlockForms?.allowedBlocks || [
	'frankas-block-forms/field',
	'core/paragraph',
	'core/heading',
	'core/list',
	'core/columns',
	'core/column',
	'core/group',
	'core/separator',
	'core/spacer',
];

const editUrl = ( id ) => `${ window.frankasBlockForms?.editUrl || '' }${ id }`;

// A form can't contain a form: hide this block in the form editor.
addFilter( 'blocks.registerBlockType', 'frankas-block-forms/form-inserter', ( settings, name ) => {
	if ( name === metadata.name && window.frankasBlockForms?.isFormEditor ) {
		return { ...settings, supports: { ...settings.supports, inserter: false } };
	}
	return settings;
} );

function FormPicker( { onSelect } ) {
	const { records, hasResolved } = useEntityRecords( 'postType', POST_TYPE, { per_page: -1, status: 'publish', orderby: 'title', order: 'asc' } );
	const { createForm } = useFormActions();
	const [ formId, setFormId ] = useState( '' );
	const [ title, setTitle ] = useState( '' );
	const [ templateId, setTemplateId ] = useState( '' );
	const [ isCreating, setIsCreating ] = useState( false );

	const forms = ( records || [] ).filter( ( r ) => ! r.meta?.[ TEMPLATE_META ] );
	const templates = ( records || [] ).filter( ( r ) => r.meta?.[ TEMPLATE_META ] );
	const titleOf = ( r ) => r.title?.raw || r.title?.rendered || sprintf( /* translators: %d: form ID */ __( 'Form %d', 'frankas-block-forms' ), r.id );

	const create = async () => {
		setIsCreating( true );
		const template = templates.find( ( t ) => String( t.id ) === templateId );
		const form = await createForm( {
			title: title || ( template ? titleOf( template ).replace( / \(.*\)$/, '' ) : __( 'New form', 'frankas-block-forms' ) ),
			content: template ? template.content.raw : blankFormContent(),
			settings: template?.meta?.[ SETTINGS_META ],
		} );
		setIsCreating( false );
		if ( form ) {
			onSelect( form.id );
		}
	};

	return (
		<Placeholder icon="feedback" label={ __( 'Form', 'frankas-block-forms' ) } instructions={ __( 'Choose one of your forms, or make a new one.', 'frankas-block-forms' ) }>
			{ ! hasResolved ? (
				<Spinner />
			) : (
				<div className="fbf-picker">
					{ forms.length > 0 && (
						<Flex align="flex-end" className="fbf-picker__row">
							<SelectControl
								label={ __( 'Existing form', 'frankas-block-forms' ) }
								value={ formId }
								options={ [ { label: __( 'Choose a form…', 'frankas-block-forms' ), value: '' }, ...forms.map( ( r ) => ( { label: titleOf( r ), value: String( r.id ) } ) ) ] }
								onChange={ setFormId }
								__nextHasNoMarginBottom
							/>
							<Button variant="primary" disabled={ ! formId } onClick={ () => onSelect( Number( formId ) ) }>
								{ __( 'Use this form', 'frankas-block-forms' ) }
							</Button>
						</Flex>
					) }
					<Flex align="flex-end" className="fbf-picker__row">
						<TextControl label={ __( 'New form', 'frankas-block-forms' ) } placeholder={ __( 'Name, e.g. Contact', 'frankas-block-forms' ) } value={ title } onChange={ setTitle } __nextHasNoMarginBottom />
						{ templates.length > 0 && (
							<SelectControl
								label={ __( 'Start from', 'frankas-block-forms' ) }
								value={ templateId }
								options={ [ { label: __( 'Name, email and message', 'frankas-block-forms' ), value: '' }, ...templates.map( ( t ) => ( { label: titleOf( t ), value: String( t.id ) } ) ) ] }
								onChange={ setTemplateId }
								__nextHasNoMarginBottom
							/>
						) }
						<Button variant="secondary" isBusy={ isCreating } disabled={ isCreating } onClick={ create }>
							{ __( 'Create form', 'frankas-block-forms' ) }
						</Button>
					</Flex>
				</div>
			) }
		</Placeholder>
	);
}

function FormFields( { formId, submitText } ) {
	const [ blocks, onInput, onChange ] = useEntityBlockEditor( 'postType', POST_TYPE, { id: formId } );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'fbf-form__fields' },
		{
			value: blocks,
			onInput,
			onChange,
			allowedBlocks: ALLOWED_BLOCKS,
			renderAppender: blocks?.length ? undefined : InnerBlocks.ButtonBlockAppender,
		}
	);
	return (
		<div className="fbf-form">
			<div { ...innerBlocksProps } />
			<div className="fbf-form__actions">
				<button type="button" className="fbf-submit" disabled>
					{ submitText }
				</button>
			</div>
		</div>
	);
}

function SettingsModal( { formId, onClose } ) {
	const [ title, setTitle ] = useEntityProp( 'postType', POST_TYPE, 'title', formId );
	const [ blocks ] = useEntityBlockEditor( 'postType', POST_TYPE, { id: formId } );
	const [ settings, updateSettings ] = useFormSettings( formId );
	const { saveAsTemplate } = useFormActions();
	const [ isSaving, setIsSaving ] = useState( false );

	return (
		<Modal title={ __( 'Form settings', 'frankas-block-forms' ) } onRequestClose={ onClose } size="medium" className="fbf-settings-modal">
			<TextControl label={ __( 'Name of the form', 'frankas-block-forms' ) } help={ __( 'Only shown in WordPress, not to visitors.', 'frankas-block-forms' ) } value={ title } onChange={ setTitle } __nextHasNoMarginBottom />
			<FormSettings settings={ settings } onChange={ updateSettings } fields={ collectFields( blocks ) } formId={ formId } />
			<p className="fbf-settings-modal__note">{ __( 'These settings belong to the form and are saved with it when you save the page.', 'frankas-block-forms' ) }</p>
			<Flex justify="space-between">
				<Button
					variant="tertiary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ async () => {
						setIsSaving( true );
						await saveAsTemplate( { title, blocks, settings } );
						setIsSaving( false );
					} }
				>
					{ __( 'Save as template', 'frankas-block-forms' ) }
				</Button>
				<Button variant="primary" onClick={ onClose }>
					{ __( 'Done', 'frankas-block-forms' ) }
				</Button>
			</Flex>
		</Modal>
	);
}

function FormEdit( { formId, setAttributes } ) {
	const { record, hasResolved } = useEntityRecord( 'postType', POST_TYPE, formId );
	const [ settings ] = useFormSettings( formId );
	const [ isSettingsOpen, setIsSettingsOpen ] = useState( false );
	const choose = () => setAttributes( { formId: 0 } );

	if ( ! hasResolved ) {
		return <Spinner />;
	}
	if ( ! record || record.status === 'trash' ) {
		return (
			<Warning actions={ [ <Button key="choose" variant="secondary" onClick={ choose }>{ __( 'Choose another form', 'frankas-block-forms' ) }</Button> ] }>
				{ __( 'This form no longer exists.', 'frankas-block-forms' ) }
			</Warning>
		);
	}

	const title = record.title?.raw || __( '(no name)', 'frankas-block-forms' );
	const isTemplate = !! record.meta?.[ TEMPLATE_META ];

	return (
		<>
			<BlockControls group="other">
				<ToolbarGroup>
					<ToolbarButton icon="admin-generic" onClick={ () => setIsSettingsOpen( true ) }>
						{ __( 'Form settings', 'frankas-block-forms' ) }
					</ToolbarButton>
					<ToolbarButton onClick={ choose }>{ __( 'Replace', 'frankas-block-forms' ) }</ToolbarButton>
				</ToolbarGroup>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'frankas-block-forms' ) }>
					<p>
						<strong>{ title }</strong>
					</p>
					<p>{ __( 'Changes to the fields and settings apply everywhere this form is used.', 'frankas-block-forms' ) }</p>
					<Flex direction="column" align="flex-start" gap={ 3 }>
						<Button variant="secondary" icon="admin-generic" onClick={ () => setIsSettingsOpen( true ) }>
							{ __( 'Form settings', 'frankas-block-forms' ) }
						</Button>
						<ExternalLink href={ editUrl( formId ) }>{ __( 'Open in the form editor', 'frankas-block-forms' ) }</ExternalLink>
						{ settings.storeSubmissions && <ExternalLink href={ window.frankasBlockForms?.submissionsUrl + formId }>{ __( 'View submissions', 'frankas-block-forms' ) }</ExternalLink> }
					</Flex>
				</PanelBody>
			</InspectorControls>
			{ isTemplate && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'This is a template. Templates are not shown on the site; choose a form instead.', 'frankas-block-forms' ) }
				</Notice>
			) }
			<FormFields formId={ formId } submitText={ settings.submitText } />
			{ isSettingsOpen && <SettingsModal formId={ formId } onClose={ () => setIsSettingsOpen( false ) } /> }
		</>
	);
}

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps( { className: 'fbf-form-block' } );
		return (
			<div { ...blockProps }>
				{ attributes.formId ? (
					<FormEdit formId={ attributes.formId } setAttributes={ setAttributes } />
				) : (
					<FormPicker onSelect={ ( formId ) => setAttributes( { formId } ) } />
				) }
			</div>
		);
	},
	save: () => null,
} );
