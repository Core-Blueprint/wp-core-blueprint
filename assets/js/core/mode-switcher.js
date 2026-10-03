/**
 * mode-switcher.js - Core Blueprint Suite
 *
 * Wires every cb-core-mode-switcher instance on the page. Single
 * source of truth for the click-handling, persistence, and broadcast
 * logic - HUD and page-level switchers share the exact same code path.
 *
 * Per-page custom behaviour (e.g. "reload after change" on Logs)
 * hooks in via the `cb-core-mode-changed` custom event:
 *
 *     document.addEventListener('cb-core-mode-changed', (e) => {
 *       if (this-page-needs-reload) {
 *         e.preventDefault();          // tell us not to do the soft swap
 *         window.location.reload();
 *       }
 *     });
 *
 * The dispatched event is cancelable. If a listener calls
 * preventDefault() before the soft DOM-flip would run, the soft flip
 * is skipped and the listener takes over (typically: page reload).
 *
 * Multiple switchers on one page (HUD + page-level) all stay in sync:
 * after a successful write, every switcher gets its is-active state
 * updated. So toggling the HUD's S immediately highlights S in the
 * page-level switcher too.
 *
 * @package CoreBlueprint\Core
 * @since   1.0.0
 */

import { qsa, apiPost } from './dom.js';

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/mode-switcher' );
const data   = dataEl ? JSON.parse( dataEl.textContent ) : {};
const nonce  = data.nonce || '';
const i18n   = data.i18n  || {};

const VALID_MODES = new Set([ 'plain', 'technical', 'sync' ]);

/**
 * Update the is-active / aria-checked state on every switcher
 * instance to match the given mode. Safe to call when no switcher
 * exists; iterates an empty list silently.
 */
function syncAllSwitchers( mode ) {
	const switchers = qsa( '[data-cb-mode-switcher]' );
	for ( const switcher of switchers ) {
		const buttons = qsa( '.cb-core-mode-switcher__btn', switcher );
		for ( const btn of buttons ) {
			const isActive = btn.dataset.cbMode === mode;
			btn.classList.toggle( 'is-active', isActive );
			btn.setAttribute( 'aria-checked', isActive ? 'true' : 'false' );
		}
	}
}

/**
 * Toggle disabled state on every button across every switcher,
 * to prevent double-clicks racing with an in-flight write.
 */
function setAllDisabled( disabled ) {
	const buttons = qsa( '[data-cb-mode-switcher] .cb-core-mode-switcher__btn' );
	for ( const btn of buttons ) {
		btn.disabled = disabled;
	}
}

/**
 * Read the currently-active mode from the first switcher on the
 * page. All instances are kept in sync, so reading any one gives
 * the same answer.
 */
function readCurrentMode() {
	const active = document.querySelector( '[data-cb-mode-switcher] .cb-core-mode-switcher__btn.is-active' );
	return active ? active.dataset.cbMode : null;
}

/**
 * Handle a button click. Persists the new mode via AJAX, syncs
 * every switcher on the page, then dispatches the custom event so
 * page-specific code (Logs reload, .cb-core-dual flip, etc.) can
 * react.
 */
async function handleClick( event ) {
	const btn = event.target.closest( '.cb-core-mode-switcher__btn' );
	if ( ! btn ) return;
	const wrapper = btn.closest( '[data-cb-mode-switcher]' );
	if ( ! wrapper ) return;
	event.preventDefault();

	let mode = btn.dataset.cbMode;
	if ( ! VALID_MODES.has( mode ) ) return;

	// Cycle wrapper: clicking the (visible) active button advances to
	// the next mode in the plain → technical → sync rotation. Non-cycle
	// (segmented) wrappers keep the original behaviour: clicking the
	// already-active segment is a no-op.
	if ( wrapper.hasAttribute( 'data-cb-mode-cycle' ) && btn.classList.contains( 'is-active' ) ) {
		const order = [ 'plain', 'technical', 'sync' ];
		const idx   = order.indexOf( mode );
		mode = order[ ( idx + 1 ) % order.length ];
	} else if ( btn.classList.contains( 'is-active' ) ) {
		return; // already this mode
	}

	const previous = readCurrentMode();
	const toast    = window.cbCore?.toast;

	// Optimistic local update + lock
	syncAllSwitchers( mode );
	setAllDisabled( true );

	try {
		const response = await apiPost(
			'cb_core_set_description_mode',
			nonce,
			{ scope: 'user', mode },
		);

		if ( ! response?.success ) {
			throw new Error( response?.data?.message || i18n.modeChangeFailed || 'Could not change reading mode.' );
		}

		// Dispatch the custom event. Listeners can preventDefault to
		// take over (e.g. page reload on Logs); otherwise we run the
		// default soft-swap below.
		const customEvent = new CustomEvent( 'cb-core-mode-changed', {
			detail:     { mode, previous, response },
			cancelable: true,
			bubbles:    true,
		} );
		const proceed = document.dispatchEvent( customEvent );

		if ( ! proceed ) {
			// Listener handled it (typically a reload). Leave UI locked
			// so the in-flight reload doesn't appear interactive.
			return;
		}

		// Default soft-swap: flip every .cb-core-dual block on the page
		// to the resolved variant (sync resolves to whatever the server
		// considers the site default; the response carries it).
		applySoftSwap( response );
		setAllDisabled( false );
	} catch ( err ) {
		// Revert local state + surface error
		if ( previous ) syncAllSwitchers( previous );
		setAllDisabled( false );
		toast?.error( err?.message || i18n.modeChangeFailed || 'Could not change reading mode.' );
	}
}

/**
 * Default soft-swap: flip every .cb-core-dual block to the
 * resolved variant. The response from the server carries the
 * resolved mode in `data.effective` (plain or technical, never
 * sync) so this works correctly even when the user clicked Sync.
 */
function applySoftSwap( response ) {
	const effective = response?.data?.effective || 'plain';
	const visible   = effective === 'technical' ? 'technical' : 'plain';

	const blocks = document.querySelectorAll( '.cb-core-dual' );
	for ( const block of blocks ) {
		block.setAttribute( 'data-active', visible );
		block.querySelectorAll( '.cb-core-desc-plain' ).forEach( ( el ) => {
			el.hidden = ( visible !== 'plain' );
		} );
		block.querySelectorAll( '.cb-core-desc-technical' ).forEach( ( el ) => {
			el.hidden = ( visible !== 'technical' );
		} );
	}

	document.documentElement.setAttribute( 'data-cb-desc-mode', effective );
}

// Bind to document so we catch clicks on switchers added after init too
// (e.g. HUD panel which hydrates lazily). Matching with closest()
// means we only react to actual switcher buttons.
if ( nonce ) {
	document.addEventListener( 'click', handleClick );
}
