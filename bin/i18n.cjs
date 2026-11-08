#!/usr/bin/env node
/**
 * Translation workflow without WP-CLI. Run with `npm run i18n`.
 *
 * 1. Extracts every translatable string (PHP gettext calls, JS __() calls,
 *    block.json titles/descriptions/keywords) into languages/<domain>.pot.
 * 2. Merges that into each languages/<prefix><locale>.po: new strings are
 *    added untranslated, strings no longer in the code are dropped.
 * 3. Compiles each .po into a .mo (PHP, block.json) and, if configured,
 *    one JSON file per editor script handle (JavaScript).
 *
 * Translate by editing the .po (any text editor, or Poedit) and run it again.
 *
 * Config: the "i18n" key in package.json:
 *   domain     text domain
 *   poPrefix   prefix of .po/.mo file names ("<domain>-" for plugins, "" for themes)
 *   php, js    folders to scan (recursive)
 *   blockJson  folder with <block>/block.json files (optional)
 *   exclude    folder names to skip
 *   jsonHandles  script handles that get a JSON file; "<block>" expands to
 *                every block folder in blockJson
 *
 * Same file in the kynotrain-theme and kynotrain-blocks repos; keep in sync.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const root = process.cwd();
const config = require( path.join( root, 'package.json' ) ).i18n;
const langDir = path.join( root, 'languages' );

/* ---------- extraction ---------- */

function walk( dir, ext, out = [] ) {
	if ( ! fs.existsSync( dir ) ) return out;
	if ( fs.statSync( dir ).isFile() ) return ext.some( ( e ) => dir.endsWith( e ) ) ? [ ...out, dir ] : out;
	for ( const name of fs.readdirSync( dir ) ) {
		if ( ( config.exclude || [] ).includes( name ) ) continue;
		const p = path.join( dir, name );
		if ( fs.statSync( p ).isDirectory() ) walk( p, ext, out );
		else if ( ext.some( ( e ) => name.endsWith( e ) ) ) out.push( p );
	}
	return out;
}

const STR = `'((?:[^'\\\\]|\\\\.)*)'`;
const CALL = new RegExp( `\\b(__|_e|_x|_ex|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\\(\\s*${ STR }\\s*,\\s*${ STR }(?:\\s*,\\s*${ STR })?\\s*\\)`, 'g' );
const unescape = ( s ) => s.replace( /\\(['\\])/g, '$1' );

const entries = new Map(); // key: ctx\u0004msgid
function add( msgid, ctx, ref, isJs ) {
	const key = ( ctx ? ctx + '\u0004' : '' ) + msgid;
	const e = entries.get( key ) || { msgid, ctx, refs: [], js: false };
	e.refs.push( ref );
	e.js = e.js || isJs;
	entries.set( key, e );
}

function scan( files, isJs ) {
	for ( const file of files ) {
		const src = fs.readFileSync( file, 'utf8' );
		for ( const m of src.matchAll( CALL ) ) {
			const hasCtx = m[ 1 ].endsWith( 'x' );
			const domain = unescape( hasCtx ? m[ 4 ] || '' : m[ 3 ] );
			if ( domain !== config.domain ) continue;
			const line = src.slice( 0, m.index ).split( '\n' ).length;
			add( unescape( m[ 2 ] ), hasCtx ? unescape( m[ 3 ] ) : '', `${ path.relative( root, file ).replace( /\\/g, '/' ) }:${ line }`, isJs );
		}
	}
}

scan( ( config.php || [] ).flatMap( ( d ) => walk( path.join( root, d ), [ '.php' ] ) ), false );
scan( ( config.js || [] ).flatMap( ( d ) => walk( path.join( root, d ), [ '.js' ] ) ), true );

const blockDirs = [];
if ( config.blockJson ) {
	const base = path.join( root, config.blockJson );
	for ( const dir of fs.readdirSync( base ) ) {
		const file = path.join( base, dir, 'block.json' );
		if ( ! fs.existsSync( file ) ) continue;
		blockDirs.push( dir );
		const json = JSON.parse( fs.readFileSync( file, 'utf8' ) );
		const ref = path.relative( root, file ).replace( /\\/g, '/' );
		// block.json strings are translated in PHP and in JS.
		if ( json.title ) add( json.title, 'block title', ref, true );
		if ( json.description ) add( json.description, 'block description', ref, true );
		for ( const k of json.keywords || [] ) add( k, 'block keyword', ref, true );
	}
}

/* ---------- PO read/write ---------- */

const poEscape = ( s ) => s.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\n/g, '\\n' );
const poUnescape = ( s ) => s.replace( /\\(.)/g, ( m, c ) => ( c === 'n' ? '\n' : c === 't' ? '\t' : c ) );

function parsePo( text ) {
	const out = new Map();
	let cur = null;
	let field = null;
	const flush = () => {
		if ( cur && cur.msgid !== undefined ) out.set( ( cur.msgctxt ? cur.msgctxt + '\u0004' : '' ) + cur.msgid, cur );
	};
	for ( const raw of text.split( /\r?\n/ ) ) {
		const line = raw.trim();
		if ( line.startsWith( 'msgctxt ' ) || ( line.startsWith( 'msgid ' ) && ! ( cur && cur.msgctxt !== undefined && cur.msgid === undefined ) ) ) {
			flush();
			cur = {};
		}
		const m = line.match( /^(msgctxt|msgid|msgstr)\s+"(.*)"$/ );
		if ( m ) {
			field = m[ 1 ];
			cur[ field ] = poUnescape( m[ 2 ] );
		} else if ( line.startsWith( '"' ) && cur && field ) {
			cur[ field ] += poUnescape( line.slice( 1, -1 ) );
		}
	}
	flush();
	return out;
}

const sorted = [ ...entries.values() ].sort( ( a, b ) => ( a.refs[ 0 ] < b.refs[ 0 ] ? -1 : a.refs[ 0 ] > b.refs[ 0 ] ? 1 : 0 ) );

function writePo( file, header, translations ) {
	let out = `msgid ""\nmsgstr ""\n${ header.map( ( h ) => `"${ poEscape( h ) }\\n"` ).join( '\n' ) }\n`;
	for ( const e of sorted ) {
		const key = ( e.ctx ? e.ctx + '\u0004' : '' ) + e.msgid;
		out += `\n#: ${ e.refs.join( ' ' ) }\n`;
		if ( e.ctx ) out += `msgctxt "${ poEscape( e.ctx ) }"\n`;
		out += `msgid "${ poEscape( e.msgid ) }"\nmsgstr "${ poEscape( translations ? translations.get( key )?.msgstr || '' : '' ) }"\n`;
	}
	fs.writeFileSync( file, out );
}

/* ---------- MO / JSON ---------- */

function writeMo( file, header, translations ) {
	const pairs = [ [ '', header.join( '\n' ) + '\n' ] ];
	for ( const [ key, e ] of translations ) {
		if ( key && e.msgstr ) pairs.push( [ key, e.msgstr ] );
	}
	pairs.sort( ( a, b ) => Buffer.compare( Buffer.from( a[ 0 ] ), Buffer.from( b[ 0 ] ) ) );

	const n = pairs.length;
	const keys = pairs.map( ( p ) => Buffer.from( p[ 0 ], 'utf8' ) );
	const vals = pairs.map( ( p ) => Buffer.from( p[ 1 ], 'utf8' ) );
	const origTable = 28;
	const transTable = origTable + n * 8;
	let offset = transTable + n * 8;
	const head = Buffer.alloc( offset );
	head.writeUInt32LE( 0x950412de, 0 );
	head.writeUInt32LE( 0, 4 );
	head.writeUInt32LE( n, 8 );
	head.writeUInt32LE( origTable, 12 );
	head.writeUInt32LE( transTable, 16 );
	head.writeUInt32LE( 0, 20 );
	head.writeUInt32LE( offset, 24 );
	const chunks = [];
	keys.forEach( ( k, i ) => {
		head.writeUInt32LE( k.length, origTable + i * 8 );
		head.writeUInt32LE( offset, origTable + i * 8 + 4 );
		chunks.push( k, Buffer.from( [ 0 ] ) );
		offset += k.length + 1;
	} );
	vals.forEach( ( v, i ) => {
		head.writeUInt32LE( v.length, transTable + i * 8 );
		head.writeUInt32LE( offset, transTable + i * 8 + 4 );
		chunks.push( v, Buffer.from( [ 0 ] ) );
		offset += v.length + 1;
	} );
	fs.writeFileSync( file, Buffer.concat( [ head, ...chunks ] ) );
}

function jedJson( locale, pluralForms, translations ) {
	const messages = { '': { domain: 'messages', lang: locale, 'plural-forms': pluralForms } };
	for ( const [ key, e ] of translations ) {
		if ( key && e.msgstr && entries.get( key )?.js ) messages[ key ] = [ e.msgstr ];
	}
	return JSON.stringify( { domain: 'messages', locale_data: { messages } } );
}

/* ---------- run ---------- */

fs.mkdirSync( langDir, { recursive: true } );
const potHeader = [
	`Project-Id-Version: ${ config.domain }`,
	'MIME-Version: 1.0',
	'Content-Type: text/plain; charset=UTF-8',
	'Content-Transfer-Encoding: 8bit',
	`X-Domain: ${ config.domain }`,
];
writePo( path.join( langDir, `${ config.domain }.pot` ), potHeader, null );
console.log( `${ config.domain }.pot: ${ sorted.length } strings` );

const prefix = config.poPrefix || '';
for ( const name of fs.readdirSync( langDir ) ) {
	if ( ! name.endsWith( '.po' ) || ! name.startsWith( prefix ) ) continue;
	const locale = name.slice( prefix.length, -3 );
	const poFile = path.join( langDir, name );
	const existing = parsePo( fs.readFileSync( poFile, 'utf8' ) );
	const oldHeader = ( existing.get( '' )?.msgstr || '' ).split( '\n' ).filter( Boolean );
	const header = oldHeader.length ? oldHeader : [ ...potHeader, `Language: ${ locale }`, 'Plural-Forms: nplurals=2; plural=(n != 1);' ];

	writePo( poFile, header, existing );
	const merged = parsePo( fs.readFileSync( poFile, 'utf8' ) );
	merged.delete( '' );
	const missing = [ ...merged.values() ].filter( ( e ) => ! e.msgstr ).length;

	writeMo( path.join( langDir, `${ prefix }${ locale }.mo` ), header, merged );

	let jsonCount = 0;
	if ( config.jsonHandles ) {
		const pluralForms = ( header.find( ( h ) => h.startsWith( 'Plural-Forms:' ) ) || '' ).replace( 'Plural-Forms:', '' ).trim();
		const json = jedJson( locale, pluralForms, merged );
		for ( const handle of config.jsonHandles.flatMap( ( h ) => ( h.includes( '<block>' ) ? blockDirs.map( ( d ) => h.replace( '<block>', d ) ) : [ h ] ) ) ) {
			fs.writeFileSync( path.join( langDir, `${ config.domain }-${ locale }-${ handle }.json` ), json );
			jsonCount++;
		}
	}
	console.log( `${ name }: ${ merged.size - missing }/${ merged.size } translated${ missing ? ` (${ missing } missing)` : '' }, .mo${ jsonCount ? ` + ${ jsonCount } JSON` : '' }` );
}
