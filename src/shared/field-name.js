/**
 * The name a field is submitted under: as set on the field, or made from its
 * label. Must match frankas_block_forms_field_name() in includes/fields.php.
 */
const slug = ( text ) =>
	( text || '' )
		.replace( /<[^>]*>/g, '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9_]+/g, '_' )
		.replace( /^_+|_+$/g, '' );

export default function fieldName( name, label ) {
	return slug( name ) || slug( label ) || 'field';
}
