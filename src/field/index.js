import { __, sprintf } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InspectorControls, store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, SelectControl, TextareaControl, Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import fieldName from '../shared/field-name';
import metadata from './block.json';
import './style.scss';

const TYPES = [
	{ value: 'text', label: __( 'Text', 'frankas-block-forms' ) },
	{ value: 'email', label: __( 'Email', 'frankas-block-forms' ) },
	{ value: 'tel', label: __( 'Phone number', 'frankas-block-forms' ) },
	{ value: 'number', label: __( 'Number', 'frankas-block-forms' ) },
	{ value: 'date', label: __( 'Date', 'frankas-block-forms' ) },
	{ value: 'textarea', label: __( 'Long text', 'frankas-block-forms' ) },
	{ value: 'select', label: __( 'Dropdown', 'frankas-block-forms' ) },
	{ value: 'radio', label: __( 'Choice buttons (pick one)', 'frankas-block-forms' ) },
	{ value: 'checkbox', label: __( 'Checkbox (yes/no)', 'frankas-block-forms' ) },
];

const HAS_OPTIONS = [ 'select', 'radio' ];
const HAS_PLACEHOLDER = [ 'text', 'email', 'tel', 'number', 'textarea' ];

// One option per line. Must match frankas_block_forms_field_options() in includes/fields.php.
const parseOptions = ( options ) => options.split( '\n' ).map( ( o ) => o.trim() ).filter( Boolean );

// In the form editor itself the fields sit at the top level, not inside a
// Form block, so the "only inside a Form block" rule doesn't apply there.
addFilter( 'blocks.registerBlockType', 'frankas-block-forms/field-ancestor', ( settings, name ) => {
	if ( name === metadata.name && window.frankasBlockForms?.isFormEditor ) {
		const { ancestor, ...rest } = settings;
		return rest;
	}
	return settings;
} );

function Control( { type, placeholder, options } ) {
	if ( type === 'textarea' ) {
		return <textarea className="fbf-textarea" placeholder={ placeholder } disabled />;
	}
	if ( type === 'select' ) {
		return (
			<select className="fbf-select" disabled>
				<option>{ __( 'Choose…', 'frankas-block-forms' ) }</option>
				{ parseOptions( options ).map( ( o ) => <option key={ o }>{ o }</option> ) }
			</select>
		);
	}
	if ( type === 'radio' ) {
		const list = parseOptions( options );
		return ( list.length ? list : [ __( 'Add options in the sidebar', 'frankas-block-forms' ) ] ).map( ( o ) => (
			<label className="fbf-option" key={ o }>
				<input type="radio" disabled />
				<span>{ o }</span>
			</label>
		) );
	}
	return <input className="fbf-input" type={ type } placeholder={ placeholder } disabled />;
}

registerBlockType( metadata.name, {
	variations: [
		{ name: 'default', title: __( 'Form Field', 'frankas-block-forms' ), attributes: { label: __( 'Name', 'frankas-block-forms' ) }, isDefault: true, scope: [ 'inserter' ] },
		...TYPES.map( ( { value, label } ) => ( {
			name: value,
			title: label,
			attributes: { type: value },
			isActive: [ 'type' ],
			// Only names the block in the sidebar ("Email", "Dropdown"…); the
			// type itself is chosen with the Field type control.
			scope: [ 'block' ],
		} ) ),
	],
	edit( { attributes, setAttributes, clientId } ) {
		const { type, label, name, placeholder, options, required } = attributes;
		const isCheckbox = type === 'checkbox';
		const submittedName = fieldName( name, label );

		// Other fields in the same form with the same name would overwrite this one.
		const isDuplicate = useSelect(
			( select ) => {
				const editor = select( blockEditorStore );
				const formOf = ( id ) => editor.getBlockParentsByBlockName( id, 'frankas-block-forms/form', true )[ 0 ] || '';
				const form = formOf( clientId );
				return editor.getClientIdsWithDescendants().some( ( id ) => {
					if ( id === clientId || editor.getBlockName( id ) !== metadata.name || formOf( id ) !== form ) {
						return false;
					}
					const other = editor.getBlockAttributes( id );
					return fieldName( other.name, other.label ) === submittedName;
				} );
			},
			[ clientId, submittedName ]
		);

		const labelInput = (
			<RichText
				tagName="span"
				className={ isCheckbox ? undefined : 'fbf-label' }
				value={ label }
				onChange={ ( v ) => setAttributes( { label: v } ) }
				placeholder={ __( 'Label', 'frankas-block-forms' ) }
				allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
			/>
		);

		return (
			<div { ...useBlockProps( { className: `fbf-field fbf-field--${ type }` } ) }>
				<InspectorControls>
					<PanelBody title={ __( 'Field', 'frankas-block-forms' ) }>
						<SelectControl
							label={ __( 'Field type', 'frankas-block-forms' ) }
							value={ type }
							options={ TYPES }
							onChange={ ( v ) => setAttributes( { type: v } ) }
							__nextHasNoMarginBottom
						/>
						{ HAS_OPTIONS.includes( type ) && (
							<TextareaControl
								label={ __( 'Options', 'frankas-block-forms' ) }
								help={ __( 'One option per line.', 'frankas-block-forms' ) }
								value={ options }
								onChange={ ( v ) => setAttributes( { options: v } ) }
								rows={ 5 }
								__nextHasNoMarginBottom
							/>
						) }
						{ HAS_PLACEHOLDER.includes( type ) && (
							<TextControl
								label={ __( 'Placeholder', 'frankas-block-forms' ) }
								help={ __( 'Grey example text shown inside the empty field.', 'frankas-block-forms' ) }
								value={ placeholder }
								onChange={ ( v ) => setAttributes( { placeholder: v } ) }
								__nextHasNoMarginBottom
							/>
						) }
						<ToggleControl
							label={ __( 'Required', 'frankas-block-forms' ) }
							checked={ required }
							onChange={ ( v ) => setAttributes( { required: v } ) }
							__nextHasNoMarginBottom
						/>
					</PanelBody>
				</InspectorControls>
				<InspectorControls group="advanced">
					<TextControl
						label={ __( 'Field name', 'frankas-block-forms' ) }
						help={ sprintf(
							/* translators: %s: the field's name in submissions and e-mail tags */
							__( 'Used in submissions and as {%s} in e-mails. Leave empty to make it from the label.', 'frankas-block-forms' ),
							submittedName
						) }
						value={ name }
						placeholder={ submittedName }
						onChange={ ( v ) => setAttributes( { name: v } ) }
						__nextHasNoMarginBottom
					/>
				</InspectorControls>

				{ isDuplicate && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: field name */
							__( 'Another field is also called "%s". Give one of them a different label or field name, or its answer will be lost.', 'frankas-block-forms' ),
							submittedName
						) }
					</Notice>
				) }

				{ isCheckbox ? (
					<label className="fbf-option">
						<input type="checkbox" disabled />
						<span>
							{ labelInput }
							{ required && <span className="fbf-required">*</span> }
						</span>
					</label>
				) : (
					<>
						<div>
							{ labelInput }
							{ required && <span className="fbf-required">*</span> }
						</div>
						<Control type={ type } placeholder={ placeholder } options={ options } />
					</>
				) }
			</div>
		);
	},
	save: () => null,
} );
