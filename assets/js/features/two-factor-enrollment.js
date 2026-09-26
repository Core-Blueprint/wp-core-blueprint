/**
 * Two-factor enrollment enhancement.
 *
 * QR generation is entirely local. The provisioning URI and setup secret are
 * supplied by the authenticated request-local setup surface; this module never
 * persists, transmits or logs either value.
 *
 * @internal
 * @package CB\Core
 */

import qrcode from '../vendor/qrcode-generator-2.0.4.js';
import clipboard from '../core/clipboard.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
const QUIET_ZONE = 4;
const MODULE_SIZE = 5;

function renderQr( target, uri, label ) {
	const qr = qrcode( 0, 'M' );
	qr.addData( uri );
	qr.make();

	const modules = qr.getModuleCount();
	const total = ( modules + QUIET_ZONE * 2 ) * MODULE_SIZE;
	const svg = document.createElementNS( SVG_NS, 'svg' );
	svg.setAttribute( 'viewBox', `0 0 ${ total } ${ total }` );
	svg.setAttribute( 'role', 'img' );
	svg.setAttribute( 'aria-label', label );
	svg.setAttribute( 'shape-rendering', 'crispEdges' );
	svg.classList.add( 'cb-core-secure-action__qr-svg' );

	const background = document.createElementNS( SVG_NS, 'rect' );
	background.setAttribute( 'width', String( total ) );
	background.setAttribute( 'height', String( total ) );
	background.setAttribute( 'fill', '#fff' );
	svg.appendChild( background );

	let pathData = '';
	for ( let row = 0; row < modules; row += 1 ) {
		for ( let column = 0; column < modules; column += 1 ) {
			if ( ! qr.isDark( row, column ) ) {
				continue;
			}
			const x = ( column + QUIET_ZONE ) * MODULE_SIZE;
			const y = ( row + QUIET_ZONE ) * MODULE_SIZE;
			pathData += `M${ x },${ y }h${ MODULE_SIZE }v${ MODULE_SIZE }h-${ MODULE_SIZE }z`;
		}
	}

	const path = document.createElementNS( SVG_NS, 'path' );
	path.setAttribute( 'd', pathData );
	path.setAttribute( 'fill', '#000' );
	svg.appendChild( path );

	target.replaceChildren( svg );
	target.hidden = false;
}

function enhanceEnrollment( root ) {
	const qrTarget = root.querySelector( '[data-cb-two-factor-qr]' );
	if ( qrTarget ) {
		const uri = qrTarget.getAttribute( 'data-cb-two-factor-provisioning-uri' ) || '';
		const label = qrTarget.getAttribute( 'data-cb-two-factor-qr-label' ) || 'Authenticator setup QR code';
		if ( uri ) {
			try {
				renderQr( qrTarget, uri, label );
			} catch {
				qrTarget.hidden = true;
			}
		}
	}

	const copyButton = root.querySelector( '[data-cb-two-factor-copy-secret]' );
	if ( copyButton instanceof HTMLButtonElement ) {
		const secret = copyButton.getAttribute( 'data-cb-two-factor-copy-secret' ) || '';
		if ( secret ) {
			clipboard.enhance( copyButton, {
				text: secret,
				label: copyButton.getAttribute( 'aria-label' ) || undefined,
			} );
		}
	}
}

document.querySelectorAll( '[data-cb-two-factor-enrollment]' ).forEach( enhanceEnrollment );
