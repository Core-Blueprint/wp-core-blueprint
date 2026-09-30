/**
 * Core Blueprint - DOM helpers
 *
 * Shared, jQuery-free helpers that every CB Base admin module can pull from,
 * AND the **public API surface** for downstream Core Blueprint plugins
 * (Hub, Access Control, Protected Content, Invoice, …) that lean on Base.
 *
 * The contract - see `apiVersion` below - is intentionally small:
 *
 *   window.cbCore.qs( selector, root? )           // querySelector wrapper
 *   window.cbCore.qsa( selector, root? )          // querySelectorAll → Array
 *   window.cbCore.apiPost( action, nonce, data? ) // fetch → Promise<JSON>
 *   window.cbCore.modal.show( opts )              // generic Promise-based modal
 *   window.cbCore.icon.create( name, opts? )      // shared Lucide icon element
 *                                                 //   (see core/modal.js)
 *   window.cbCore.toast.success/error/warning/info( msg )
 *                                                 // (see core/toast.js)
 *   window.cbCore.apiVersion                      // semver - bump on breaking
 *
 * Anything not on this list is internal to a module and may change without
 * notice. Downstream plugins that need new surface should request it; we add
 * it deliberately rather than letting the API grow by accident.
 *
 * Loaded as a native ES module via wp_enqueue_script_module() (WP 6.7+).
 * Server-side data (ajaxUrl, i18n) is delivered via the official
 * `script_module_data_@cb-core/dom` filter - WP prints a JSON `<script>` tag
 * which this module reads at evaluation time. The cross-plugin runtime
 * surface (qs/qsa/apiPost/apiVersion) is then attached to
 * window.cbCore for downstream plugins to consume.
 *
 * @since   1.0.0
 */

const CB_CORE_API_VERSION = '1.0';

// ─── Server-side data ────────────────────────────────────────────────────────
//
// Read from the per-module data tag printed by the WP 6.7+
// `script_module_data_@cb-core/dom` filter. `dataEl` will be absent on
// pages where this module isn't enqueued - guard accordingly.

const dataEl  = document.getElementById( 'wp-script-module-data-@cb-core/dom' );
const data    = dataEl ? JSON.parse( dataEl.textContent ) : {};
const ajaxUrl = data.ajaxUrl || '';

// ─── DOM helpers ─────────────────────────────────────────────────────────────

/**
 * querySelector wrapper. Returns the first matching element or null.
 *
 * @param {string} selector
 * @param {ParentNode} [root=document]
 * @returns {Element|null}
 */
/**
 * querySelector wrapper. Returns the first matching element or null.
 * Defensive: if `root` is null/undefined, returns null instead of throwing —
 * keeps a misconfigured caller from breaking the whole bundle.
 *
 * @param {string} selector
 * @param {ParentNode} [root=document]
 * @returns {Element|null}
 */
export const qs = ( selector, root = document ) => {
	if ( ! root ) return null;
	return root.querySelector( selector );
};

/**
 * querySelectorAll wrapper. Returns an Array (not a NodeList) so .map / .filter
 * work without spread gymnastics at every call site.
 * Defensive: if `root` is null/undefined, returns an empty array instead of
 * throwing — keeps a misconfigured caller from breaking the whole bundle.
 *
 * @param {string} selector
 * @param {ParentNode} [root=document]
 * @returns {Element[]}
 */
export const qsa = ( selector, root = document ) => {
	if ( ! root ) return [];
	return Array.from( root.querySelectorAll( selector ) );
};

// ─── AJAX helper ─────────────────────────────────────────────────────────────

/**
 * POST to admin-ajax.php with the standard CB envelope (action + nonce +
 * _wpnonce). Returns a native Promise that resolves to the parsed JSON
 * response - caller is responsible for inspecting `response.success`.
 *
 * Network errors and non-OK HTTP statuses both reject the promise.
 *
 * @param {string} action  WP AJAX action name, e.g. 'cb_core_set_alert_recipient'
 * @param {string} nonce   Nonce value from the calling module's data tag
 * @param {Object} [data]  Extra form fields, plain object → URL-encoded
 * @returns {Promise<Object>}
 */
export const apiPost = async ( action, nonce, data = {} ) => {
	const body = new URLSearchParams();
	body.append( 'action',   action );
	body.append( 'nonce',    nonce );
	body.append( '_wpnonce', nonce );
	for ( const [ key, value ] of Object.entries( data ) ) {
		body.append( key, value );
	}

	const response = await fetch( ajaxUrl, {
		method:      'POST',
		credentials: 'same-origin',
		body,
	} );

	if ( ! response.ok ) {
		// Try to extract a server-provided message before falling back.
		let message = `HTTP ${ response.status }`;
		try {
			const json = await response.json();
			if ( json?.data?.message ) {
				message = json.data.message;
			}
		} catch {
			// Body wasn't JSON - keep the HTTP status fallback.
		}
		throw new Error( message );
	}

	return response.json();
};

// ─── Public API exposure ─────────────────────────────────────────────────────
//
// Each core module is responsible for ensuring window.cbCore exists before
// attaching to it - multiple core modules run in undefined order and any
// of them may be the first to touch the global. Idempotent.
//
// Downstream CB plugins (Hub, Access Control, Protected Content, Invoice…)
// read these helpers off window.cbCore at runtime, so they don't need
// cross-plugin filesystem imports.

window.cbCore = window.cbCore || {};
window.cbCore.apiVersion      = CB_CORE_API_VERSION;
window.cbCore.qs              = qs;
window.cbCore.qsa             = qsa;
window.cbCore.apiPost         = apiPost;
