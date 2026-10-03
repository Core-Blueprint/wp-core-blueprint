/**
 * core/toast.js - Core Blueprint Base
 *
 * Shared toast notification runtime for Core Blueprint Base and extensions.
 * Presentation is supplied by CSS adapters:
 *   - components/toasts.css        → Core Admin Theme
 *   - components/toasts-native.css → standalone WordPress admin screens
 *
 * Public API:
 *   window.cbCore.toast( message, variant = 'success', options = {} )
 *   window.cbCore.toast.success( message, options = {} )
 *   window.cbCore.toast.error( message, options = {} )
 *   window.cbCore.toast.warning( message, options = {} )
 *   window.cbCore.toast.info( message, options = {} )
 *
 * Supported options:
 *   - duration   Number of milliseconds before auto-dismiss. Values <= 0 use
 *                the semantic default unless `persistent` is true.
 *   - persistent Keep the toast visible until manually dismissed.
 *   - dedupe     Suppress an identical visible message+variant. Default true.
 *
 * Native DOM API only. No jQuery. No bundler.
 *
 * @package CoreBlueprint\Core
 * @since   1.0.0
 */

const CONTAINER_ID = 'cb-core-toast-container';
const MAX_VISIBLE  = 5;

const VARIANTS = {
	success: { duration: 4000, dotClass: 'cb-core-toast__dot--success' },
	error:   { duration: 7000, dotClass: 'cb-core-toast__dot--error' },
	warning: { duration: 5000, dotClass: 'cb-core-toast__dot--warning' },
	info:    { duration: 5000, dotClass: 'cb-core-toast__dot--info' },
};

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/toast' );
let data = {};
try {
	data = dataEl ? JSON.parse( dataEl.textContent ) : {};
} catch {
	data = {};
}
const i18n = data.i18n || {};
const presentation = data.presentation === 'core' ? 'core' : 'wp-native';

let container = null;

function ensureContainer() {
	if ( container && document.body.contains( container ) ) {
		return container;
	}

	container = document.getElementById( CONTAINER_ID );
	if ( ! container ) {
		container = document.createElement( 'div' );
		container.id        = CONTAINER_ID;
		container.className = `cb-core-toast-container cb-core-toast-container--${ presentation }`;
		document.body.appendChild( container );
	}
	return container;
}

function normalizeVariant( variant ) {
	return Object.prototype.hasOwnProperty.call( VARIANTS, variant ) ? variant : 'success';
}

function normalizeOptions( options ) {
	return options && typeof options === 'object' ? options : {};
}

/**
 * Show a toast.
 *
 * @param {string} message
 * @param {'success'|'error'|'warning'|'info'} [variant='success']
 * @param {{duration?:number,persistent?:boolean,dedupe?:boolean}} [options={}]
 */
function toast( message, variant = 'success', options = {} ) {
	const resolvedVariant = normalizeVariant( variant );
	const config          = VARIANTS[ resolvedVariant ];
	const opts            = normalizeOptions( options );
	const root            = ensureContainer();
	const text            = String( message ?? '' );
	const dedupe          = opts.dedupe !== false;
	const dedupeKey       = `${ resolvedVariant }\u0000${ text }`;

	if ( dedupe ) {
		const duplicate = Array.from( root.children ).find( ( child ) => child.dataset.cbCoreToastKey === dedupeKey );
		if ( duplicate ) {
			return;
		}
	}

	// Cap visible toasts - remove the oldest when over the limit so a
	// runaway event loop cannot flood the admin screen.
	while ( root.children.length >= MAX_VISIBLE ) {
		root.firstElementChild?.remove();
	}

	const el = document.createElement( 'div' );
	el.className = `cb-core-toast cb-core-toast--${ resolvedVariant }`;
	el.dataset.cbCoreToastKey = dedupeKey;

	const dot = document.createElement( 'span' );
	dot.className = `cb-core-toast__dot ${ config.dotClass }`;
	dot.setAttribute( 'aria-hidden', 'true' );

	const body = document.createElement( 'span' );
	body.className   = 'cb-core-toast__body';
	body.textContent = text;
	body.setAttribute( 'role', resolvedVariant === 'error' ? 'alert' : 'status' );
	body.setAttribute( 'aria-atomic', 'true' );

	const dismissButton = document.createElement( 'button' );
	dismissButton.type = 'button';
	dismissButton.className = 'cb-core-toast__dismiss';
	dismissButton.setAttribute( 'aria-label', i18n.dismiss || 'Dismiss notification' );
	dismissButton.textContent = '×';

	el.appendChild( dot );
	el.appendChild( body );
	el.appendChild( dismissButton );
	root.appendChild( el );

	requestAnimationFrame( () => el.classList.add( 'is-visible' ) );

	const persistent = opts.persistent === true;
	const requestedDuration = Number( opts.duration );
	const duration = Number.isFinite( requestedDuration ) && requestedDuration > 0
		? requestedDuration
		: config.duration;

	let dismissTimer = null;
	let startedAt    = 0;
	let remaining    = duration;
	let dismissed    = false;

	function removeElement() {
		el.remove();
	}

	function dismiss() {
		if ( dismissed ) return;
		dismissed = true;
		if ( dismissTimer ) {
			clearTimeout( dismissTimer );
			dismissTimer = null;
		}
		el.classList.remove( 'is-visible' );

		if ( window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ) {
			removeElement();
			return;
		}
		setTimeout( removeElement, 250 );
	}

	function scheduleDismiss() {
		if ( persistent || dismissed || remaining <= 0 ) return;
		startedAt = Date.now();
		dismissTimer = setTimeout( dismiss, remaining );
	}

	function pauseDismiss() {
		if ( ! dismissTimer || persistent || dismissed ) return;
		clearTimeout( dismissTimer );
		dismissTimer = null;
		remaining = Math.max( 0, remaining - ( Date.now() - startedAt ) );
	}

	function resumeDismiss() {
		if ( persistent || dismissed || dismissTimer ) return;
		if ( remaining <= 0 ) {
			dismiss();
			return;
		}
		scheduleDismiss();
	}

	dismissButton.addEventListener( 'click', dismiss );
	el.addEventListener( 'mouseenter', pauseDismiss );
	el.addEventListener( 'mouseleave', resumeDismiss );
	el.addEventListener( 'focusin', pauseDismiss );
	el.addEventListener( 'focusout', ( event ) => {
		if ( ! el.contains( event.relatedTarget ) ) {
			resumeDismiss();
		}
	} );

	scheduleDismiss();
}

toast.success = ( msg, options = {} ) => toast( msg, 'success', options );
toast.error   = ( msg, options = {} ) => toast( msg, 'error', options );
toast.warning = ( msg, options = {} ) => toast( msg, 'warning', options );
toast.info    = ( msg, options = {} ) => toast( msg, 'info', options );

window.cbCore = window.cbCore || {};
window.cbCore.toast = toast;

export { toast };
export default toast;
