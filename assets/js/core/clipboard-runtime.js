/**
 * Core Blueprint UI Foundation - shared Clipboard runtime.
 *
 * Clipboard copies explicitly supplied text only. Consumers own the meaning
 * and source of that text; this primitive never infers a value from visible
 * DOM content.
 *
 * Public API:
 *   await window.cbCore.clipboard.copy( text, options )
 *   const instance = window.cbCore.clipboard.enhance( button, options )
 *
 * @package CB\Core
 * @since   1.0.0
 */

import toast from './toast.js';
import { create as createIcon } from './icon.js';

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/clipboard' );
let data = {};
try {
	data = dataEl ? JSON.parse( dataEl.textContent ) : {};
} catch {
	data = {};
}

const i18n = data.i18n || {};
const instances = new WeakMap();

function normalizeOptions( options ) {
	return options && typeof options === 'object' ? options : {};
}

function fallbackCopy( text ) {
	const activeElement = document.activeElement;
	const textarea = document.createElement( 'textarea' );
	textarea.value = text;
	textarea.setAttribute( 'readonly', '' );
	textarea.setAttribute( 'aria-hidden', 'true' );
	textarea.style.position = 'fixed';
	textarea.style.left = '-9999px';
	textarea.style.top = '0';
	document.body.appendChild( textarea );
	textarea.select();
	textarea.setSelectionRange?.( 0, textarea.value.length );

	let copied = false;
	try {
		copied = document.execCommand?.( 'copy' ) === true;
	} finally {
		textarea.remove();
		if ( activeElement && typeof activeElement.focus === 'function' ) {
			try {
				activeElement.focus( { preventScroll: true } );
			} catch {
				activeElement.focus();
			}
		}
	}

	if ( ! copied ) throw new Error( 'clipboard_copy_failed' );
}

/**
 * Copy an explicit string to the system clipboard.
 *
 * @param {string} text Text to copy.
 * @param {{successMessage?:string,errorMessage?:string,notify?:boolean}} [options={}]
 * @returns {Promise<boolean>}
 */
async function copy( text, options = {} ) {
	const opts = normalizeOptions( options );
	const value = String( text ?? '' );
	const notify = opts.notify !== false;

	try {
		if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			try {
				await navigator.clipboard.writeText( value );
			} catch {
				fallbackCopy( value );
			}
		} else {
			fallbackCopy( value );
		}

		if ( notify ) toast.success( opts.successMessage || i18n.copied || 'Copied to clipboard.' );
		return true;
	} catch {
		if ( notify ) toast.error( opts.errorMessage || i18n.copyFailed || 'Could not copy to clipboard.' );
		return false;
	}
}

function resolveText( source, button ) {
	if ( typeof source === 'function' ) return String( source( button ) ?? '' );
	return String( source ?? '' );
}

function replaceManagedIcon( button, iconName ) {
	button.querySelector( '[data-cb-core-clipboard-icon]' )?.remove();
	const icon = createIcon( iconName, { size: 'compact' } );
	if ( ! icon ) return;
	icon.dataset.cbCoreClipboardIcon = '1';
	button.prepend( icon );
}

/**
 * Enhance an existing button with shared clipboard behavior.
 *
 * `options.text` is mandatory and may be a string or callback. The callback
 * receives the button and is evaluated at copy time, which supports dynamic
 * values without coupling the Foundation to consumer DOM structures.
 *
 * @param {HTMLButtonElement} button Existing button element.
 * @param {{text:string|Function,label?:string,icon?:boolean,successMessage?:string,errorMessage?:string,notify?:boolean,feedbackDuration?:number}} options
 * @returns {{copy:Function,destroy:Function}}
 */
function enhance( button, options = {} ) {
	if ( ! button || String( button.tagName ).toUpperCase() !== 'BUTTON' ) {
		throw new TypeError( 'Clipboard enhance() requires a button element.' );
	}

	const previous = instances.get( button );
	if ( previous ) previous.destroy();

	const opts = normalizeOptions( options );
	if ( typeof opts.text !== 'string' && typeof opts.text !== 'function' ) {
		throw new TypeError( 'Clipboard enhance() requires an explicit text string or callback.' );
	}

	const originalAriaLabel = button.getAttribute( 'aria-label' );
	const originalType = button.getAttribute( 'type' );
	const useIcon = opts.icon !== false;
	const requestedDuration = Number( opts.feedbackDuration );
	const feedbackDuration = Number.isFinite( requestedDuration ) && requestedDuration >= 0 ? requestedDuration : 1600;
	let destroyed = false;
	let busy = false;
	let resetTimer = null;

	if ( originalType === null ) button.setAttribute( 'type', 'button' );
	button.dataset.cbCoreClipboard = '1';
	button.classList.add( 'cb-core-clipboard-control' );

	if ( opts.label ) {
		button.setAttribute( 'aria-label', String( opts.label ) );
	} else if ( ! originalAriaLabel && ! String( button.textContent || '' ).trim() ) {
		button.setAttribute( 'aria-label', i18n.copyLabel || 'Copy to clipboard' );
	}

	if ( useIcon ) replaceManagedIcon( button, 'clipboard-copy' );

	const runCopy = async () => {
		if ( destroyed || busy ) return false;
		busy = true;
		const disabledBeforeRun = button.disabled;
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		try {
			const success = await copy( resolveText( opts.text, button ), opts );
			if ( success && useIcon ) {
				replaceManagedIcon( button, 'clipboard-success' );
				if ( feedbackDuration > 0 ) {
					if ( resetTimer ) window.clearTimeout( resetTimer );
					resetTimer = window.setTimeout( () => {
						if ( ! destroyed ) replaceManagedIcon( button, 'clipboard-copy' );
					}, feedbackDuration );
				}
			}
			return success;
		} finally {
			busy = false;
			button.disabled = disabledBeforeRun;
			button.removeAttribute( 'aria-busy' );
		}
	};

	const onClick = ( event ) => {
		event.preventDefault();
		void runCopy();
	};
	button.addEventListener( 'click', onClick );

	const instance = Object.freeze( {
		copy: runCopy,
		destroy() {
			if ( destroyed ) return;
			destroyed = true;
			if ( resetTimer ) window.clearTimeout( resetTimer );
			button.removeEventListener( 'click', onClick );
			button.removeAttribute( 'data-cb-core-clipboard' );
			button.removeAttribute( 'aria-busy' );
			button.classList.remove( 'cb-core-clipboard-control' );
			button.querySelector( '[data-cb-core-clipboard-icon]' )?.remove();
			if ( originalType === null ) button.removeAttribute( 'type' );
			if ( originalAriaLabel === null ) button.removeAttribute( 'aria-label' );
			else button.setAttribute( 'aria-label', originalAriaLabel );
			instances.delete( button );
		},
	} );

	instances.set( button, instance );
	return instance;
}

const clipboard = Object.freeze( { copy, enhance } );
window.cbCore = window.cbCore || {};
window.cbCore.clipboard = clipboard;

export { copy, enhance };
export default clipboard;
