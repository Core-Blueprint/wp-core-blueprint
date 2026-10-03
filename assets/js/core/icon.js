/**
 * Core Blueprint UI Foundation - shared icon registry client.
 *
 * Registry data is provided by CoreBlueprint\Core\UI\Icon so PHP and JavaScript share
 * one canonical icon set. The browser renderer builds SVG nodes directly;
 * no innerHTML or remote assets are involved.
 *
 * @since   1.0.0
 */

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/icon' );
const data = dataEl ? JSON.parse( dataEl.textContent ) : {};
const icons = data.icons || {};
const aliases = data.aliases || {};

const SVG_NS = 'http://www.w3.org/2000/svg';
const ALLOWED_TAGS = new Set( [ 'path', 'circle', 'rect', 'line', 'polyline' ] );
const ALLOWED_SIZES = new Set( [ 'compact', 'default', 'large' ] );

/** Resolve a semantic alias or canonical Lucide icon name. */
export const resolve = ( name ) => {
	const key = String( name || '' ).trim().toLowerCase().replace( /[^a-z0-9_-]/g, '' );
	return aliases[ key ] || key;
};

/** Whether an icon or semantic alias exists in the curated registry. */
export const has = ( name ) => Boolean( icons[ resolve( name ) ] );

/**
 * Create an inline SVGElement.
 *
 * @param {string} name Semantic alias or canonical icon name.
 * @param {Object} options
 * @param {'compact'|'default'|'large'} [options.size='default']
 * @param {string} [options.className=''] Additional CSS classes.
 * @param {string} [options.label=''] Accessible label. Empty = decorative.
 * @returns {SVGElement|null}
 */
export const create = ( name, options = {} ) => {
	const resolved = resolve( name );
	const nodes = icons[ resolved ];
	if ( ! Array.isArray( nodes ) ) return null;

	const size = ALLOWED_SIZES.has( options.size ) ? options.size : 'default';
	const svg = document.createElementNS( SVG_NS, 'svg' );
	const pixelSize = size === 'compact' ? 14 : ( size === 'large' ? 24 : 16 );
	svg.setAttribute( 'width', String( pixelSize ) );
	svg.setAttribute( 'height', String( pixelSize ) );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'fill', 'none' );
	svg.setAttribute( 'stroke', 'currentColor' );
	svg.setAttribute( 'stroke-width', '2' );
	svg.setAttribute( 'stroke-linecap', 'round' );
	svg.setAttribute( 'stroke-linejoin', 'round' );
	svg.classList.add( 'cb-core-icon', `cb-core-icon--${ size }`, `cb-core-icon--${ resolved }` );

	String( options.className || '' ).split( /\s+/ ).filter( Boolean ).forEach( ( className ) => {
		if ( /^[A-Za-z_-][A-Za-z0-9_-]*$/.test( className ) ) svg.classList.add( className );
	} );

	const label = String( options.label || '' ).trim();
	if ( label ) {
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'aria-label', label );
	} else {
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
	}

	for ( const node of nodes ) {
		if ( ! Array.isArray( node ) || node.length !== 2 || ! ALLOWED_TAGS.has( node[0] ) ) continue;
		const child = document.createElementNS( SVG_NS, node[0] );
		const attrs = node[1] && typeof node[1] === 'object' ? node[1] : {};
		for ( const [ key, value ] of Object.entries( attrs ) ) {
			if ( /^[a-z][a-z0-9-]*$/.test( key ) ) child.setAttribute( key, String( value ) );
		}
		svg.appendChild( child );
	}

	return svg;
};

window.cbCore = window.cbCore || {};
window.cbCore.icon = Object.freeze( { resolve, has, create } );
