/**
 * Core Blueprint - Core Scanner feature module.
 *
 * Renders the Core Scanner panel inside the Safeguards page. Wires:
 *
 *   - Run scan / Approve baseline / Approve component baseline /
 *     Clear results (action buttons → REST POST/DELETE → re-render)
 *   - Save settings (schedule, scan toggles, max visible findings)
 *
 * Modal confirmations and toast notifications go through the shared
 * cbCore APIs (modal.show, toast.success/error/info) - no module-local
 * implementations. Plain/Technical state is governed by the central
 * UI mode (set on the page wrap by Safeguards::render_core_scanner_tab),
 * not by a local toggle.
 *
 * REST contract: admin endpoints under core-blueprint/v1/integrity/admin/*
 * with the cb_manage_integrity capability check. Hub-bound mirror routes
 * (core-blueprint/v1/integrity/{summary,findings,scan}) are not used by
 * this module - those exist for the Hub plugin.
 *
 * @since   1.0.0
 */

import { qs, qsa } from '../core/dom.js';

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/core-scanner' );
const data   = dataEl ? JSON.parse( dataEl.textContent ) : {};
const restUrl = data.restUrl || '';
const nonce   = data.nonce   || '';
const i18n    = data.i18n    || {};

let scanInProgress = false;

// ─── REST helper ─────────────────────────────────────────────────────────────

async function request( path, options = {} ) {
	const response = await fetch( `${ restUrl }${ path }`, {
		...options,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce':   nonce,
			...( options.headers || {} ),
		},
	} );

	const payload = await response.json().catch( () => ( {} ) );

	if ( ! response.ok ) {
		const message = payload && payload.message
			? payload.message
			: `Request failed with status ${ response.status }`;
		const error = new Error( message );
		// Attach status + parsed payload so callers (notably the
		// scan() flow handling 409 Conflict) can resume on existing
		// jobs without re-parsing the response.
		error.status  = response.status;
		error.payload = payload;
		throw error;
	}

	return payload;
}

// ─── DOM helpers (module-local) ──────────────────────────────────────────────

function setBusy( button, isBusy, label, icon = null ) {
	const api = window.cbCore && window.cbCore.busy;
	if ( ! api ) {
		return;
	}
	api.button( button, isBusy, {
		label:   label || '',
		spinner: Boolean( icon ),
	} );
}

function setStatus( el, text, kind = 'pending' ) {
	if ( ! el ) {
		return;
	}
	el.textContent = text;
	el.dataset.kind = kind;
}


// ─── Toast / modal - delegate to shared cbCore APIs ─────────────────────────

function toast( message, type = 'info' ) {
	const api = window.cbCore && window.cbCore.toast;
	if ( ! api ) {
		// Fallback for the absurd case cbCore didn't load - at least log.
		// Should never happen: this module imports from core/dom.js which
		// initialises cbCore before any feature module evaluates.
		// eslint-disable-next-line no-console
		console.warn( '[cb-core/core-scanner] cbCore.toast unavailable:', message );
		return;
	}
	const fn = api[ type ] || api.info;
	fn( message );
}

async function confirmModal( options = {} ) {
	const api = window.cbCore && window.cbCore.modal;
	if ( ! api || typeof api.show !== 'function' ) {
		// eslint-disable-next-line no-console
		console.warn( '[cb-core/core-scanner] cbCore.modal unavailable' );
		return false;
	}
	return api.show( {
		title:        options.title        || ( i18n.confirm || 'Confirm' ),
		body:         options.body         || options.message || '',
		confirmLabel: options.confirmLabel || ( i18n.confirm || 'Confirm' ),
		cancelLabel:  options.cancelLabel  || ( i18n.cancel  || 'Cancel'  ),
		typedConfirm: options.typedConfirm || undefined,
		typedConfirmHint: options.typedConfirmHint || undefined,
		input:        options.input || undefined,
		onConfirm:    options.onConfirm || undefined,
		confirmVariant: options.variant || 'primary',
		confirmIcon: options.icon || undefined,
	} );
}

// ─── Async scan flow (1.3.13-dev) ─────────────────────────────────────
//
// Persistent state holder for an active scan. Survives page-reloads
// via localStorage so an operator who closes the tab and comes back
// can resume polling against the still-running server-side scan.
const ACTIVE_JOB_STORAGE_KEY = 'cb-core-integrity-active-job';

let activeJob = null;
let pollHandle = null;
let timerHandle = null;

function saveActiveJob( job ) {
	activeJob = job;
	try {
		window.localStorage.setItem( ACTIVE_JOB_STORAGE_KEY, JSON.stringify( job ) );
	} catch ( error ) {
		// localStorage can be disabled (privacy mode); resume across
		// reloads is then unavailable but in-memory state still works.
	}
}

function clearActiveJob() {
	activeJob = null;
	try {
		window.localStorage.removeItem( ACTIVE_JOB_STORAGE_KEY );
	} catch ( error ) {}
}

/**
 * Attach the UI to a resumable scan job returned by another Scanner action.
 * Baseline mutations use this so their verification scan is visible and
 * cancellable by the same lifecycle as a normal manual scan.
 */
function attachQueuedScan( payload ) {
	if ( ! payload || payload.async !== true || ! payload.job_id ) {
		return false;
	}

	saveActiveJob( {
		jobId:     payload.job_id,
		startedAt: payload.started_at || ( Date.now() / 1000 ),
	} );
	injectProgressMarkup();
	startPolling();
	return true;
}

function readStoredActiveJob() {
	try {
		const raw = window.localStorage.getItem( ACTIVE_JOB_STORAGE_KEY );
		return raw ? JSON.parse( raw ) : null;
	} catch ( error ) {
		return null;
	}
}

/**
 * Format seconds as M:SS.s for the live timer.
 */
function formatElapsed( seconds ) {
	const m = Math.floor( seconds / 60 );
	const s = Math.floor( seconds % 60 );
	const t = Math.floor( ( seconds * 10 ) % 10 );
	return `${ m }:${ String( s ).padStart( 2, '0' ) }.${ t }`;
}

/**
 * Update the live-timer DOM element. Runs every 100ms while a scan
 * is active; reads from `activeJob.startedAt` so it survives the
 * polling loop independently.
 */
function updateLiveTimer() {
	if ( ! activeJob ) {
		return;
	}
	const el = document.getElementById( 'cb-core-integrity-progress-timer' );
	if ( ! el ) {
		return;
	}
	const elapsed = ( Date.now() / 1000 ) - activeJob.startedAt;
	el.textContent = formatElapsed( Math.max( 0, elapsed ) );
}

/**
 * Compute weighted progress percentage across phases.
 *
 * Each phase contributes proportionally to its item count. Skipped
 * phases (items_total === 0) don't count toward the total. A phase
 * with status 'done' is fully counted; a phase 'running' counts its
 * items_done; 'pending' counts as 0.
 */
function computePercentage( state ) {
	const phases = state && state.phases ? state.phases : {};
	let totalItems = 0;
	let doneItems  = 0;

	for ( const name of [ 'core', 'plugins', 'themes', 'uploads' ] ) {
		const p = phases[ name ];
		if ( ! p ) {
			continue;
		}
		const items = Number( p.items_total ) || 0;
		if ( items <= 0 ) {
			continue;
		}
		totalItems += items;
		if ( p.status === 'done' ) {
			doneItems += items;
		} else if ( p.status === 'running' ) {
			doneItems += Math.min( Number( p.items_done ) || 0, items );
		}
	}

	if ( totalItems === 0 ) {
		// No item counts yet - show indeterminate progress (never 100%
		// while still running). Equal-weight fallback at phase level:
		// each completed phase = 25%.
		let phaseDone = 0;
		for ( const name of [ 'core', 'plugins', 'themes', 'uploads' ] ) {
			if ( phases[ name ] && phases[ name ].status === 'done' ) {
				phaseDone++;
			}
		}
		return Math.min( 95, phaseDone * 25 );
	}

	return Math.min( 100, Math.round( ( doneItems / totalItems ) * 100 ) );
}

/**
 * Render the phase-label string for the current phase.
 */
function phaseLabel( state ) {
	const labels = {
		core:    i18n.phaseCore    || 'Verifying core files',
		plugins: i18n.phasePlugins || 'Verifying plugins',
		themes:  i18n.phaseThemes  || 'Verifying themes',
		uploads: i18n.phaseUploads || 'Scanning uploads',
	};
	const phase = state.current_phase || '';
	if ( ! phase || ! labels[ phase ] ) {
		return i18n.phaseStarting || 'Starting…';
	}
	const p = state.phases && state.phases[ phase ];
	if ( ! p ) {
		return labels[ phase ];
	}
	const total = Number( p.items_total ) || 0;
	const done  = Number( p.items_done )  || 0;
	if ( total > 0 ) {
		return `${ labels[ phase ] } (${ done } / ${ total })`;
	}
	return labels[ phase ];
}

/**
 * Update progress UI from the polling response.
 */
function renderProgress( state ) {
	const fill = document.getElementById( 'cb-core-integrity-progress-fill' );
	const bar  = fill && fill.parentElement;
	const lbl  = document.getElementById( 'cb-core-integrity-progress-phase' );

	const pct = computePercentage( state );

	if ( fill ) {
		fill.style.width = `${ pct }%`;
	}
	if ( bar ) {
		bar.setAttribute( 'aria-valuenow', String( pct ) );
	}
	if ( lbl ) {
		lbl.textContent = phaseLabel( state );
	}
}

/**
 * Polling loop. Returns a cleanup handle.
 */
async function pollProgress() {
	if ( ! activeJob ) {
		return;
	}

	try {
		const state = await request( `/scan-progress?job_id=${ encodeURIComponent( activeJob.jobId ) }` );

		// Done - render result, clean up, reload to enter result-state UI
		if ( state.status === 'done' ) {
			stopPolling();
			clearActiveJob();
			toast( ( i18n.complete || 'Core scan completed.' ), 'success' );
			// Brief delay so the operator sees the success toast,
			// then reload to render the server-side result-state UI.
			window.setTimeout( () => window.location.reload(), 800 );
			return;
		}

		// Error - surface to the operator and stop polling
		if ( state.status === 'error' ) {
			stopPolling();
			clearActiveJob();
			const msg = state.error || ( i18n.failed || 'Core scan failed.' );
			toast( msg, 'error' );
			window.setTimeout( () => window.location.reload(), 1500 );
			return;
		}

		// Still running - update UI
		renderProgress( state );
	} catch ( error ) {
		// 404: transient gone. Could mean (a) TTL expired, (b) the
		// scan finished and the latest result is in `cb_core_integrity_latest`.
		// Either way: stop polling, clear active job, reload - the
		// page-init flow will detect (b) and render result-state.
		stopPolling();
		clearActiveJob();
		toast( ( i18n.scanLost || 'Scan progress lost. Reloading.' ), 'warning' );
		window.setTimeout( () => window.location.reload(), 1500 );
	}
}

function startPolling() {
	if ( pollHandle ) {
		return;
	}
	pollHandle  = window.setInterval( pollProgress, 1000 );
	timerHandle = window.setInterval( updateLiveTimer, 100 );
	// Immediate first poll so the UI fills in from server state right away.
	pollProgress();
}

function stopPolling() {
	if ( pollHandle ) {
		window.clearInterval( pollHandle );
		pollHandle = null;
	}
	if ( timerHandle ) {
		window.clearInterval( timerHandle );
		timerHandle = null;
	}
}

/**
 * Start (or resume) a scan run.
 *
 * Two possible flows:
 *   1. Fresh scan: POST /scan → 202 + job_id → start polling
 *   2. Already-running: 409 + existing_job_id → resume polling
 *
 * There is deliberately no synchronous full-scan fallback. The scan is always
 * dispatched through the resumable job engine; hosts with DISABLE_WP_CRON can
 * use their normal external/system cron runner.
 */
/**
 * Inject the progress-block markup into the DOM when a scan starts.
 *
 * The progress markup is server-rendered only in the scanning page-state
 * (see Page.php::render_scanning_state). When the operator clicks Run
 * from the result/idle state, the page-state is whatever it was - the
 * progress DOM doesn't exist yet. We can't reload to scanning-state
 * without losing the immediate-feedback window the polling needs.
 *
 * Solution: inject the same markup the server would have rendered, set
 * the wrap's data-cb-integrity-state to 'scanning' so the CSS sibling
 * selector activates dimming on everything below, then proceed with
 * polling. On completion the page reloads and the server takes over
 * the rendering again.
 */
function injectProgressMarkup() {
	const wrap = document.querySelector( '.cb-core-integrity-wrap' );
	if ( ! wrap ) {
		return;
	}

	// Idempotent: don't double-inject if a previous scan left it.
	if ( document.getElementById( 'cb-core-integrity-progress' ) ) {
		wrap.dataset.cbIntegrityState = 'scanning';
		wrap.classList.remove( 'cb-core-integrity-state-result', 'cb-core-integrity-state-idle' );
		wrap.classList.add( 'cb-core-integrity-state-scanning' );
		return;
	}

	const section = document.createElement( 'section' );
	section.className = 'cb-core-integrity-panel cb-core-integrity-progress-panel';
	section.id = 'cb-core-integrity-progress';
	section.setAttribute( 'aria-label', i18n.scanProgressAria || 'Scan progress' );
	section.setAttribute( 'aria-live', 'polite' );

	section.innerHTML = `
		<div class="cb-core-integrity-progress-head">
			<h2 class="cb-core-integrity-progress-title">
				<span class="cb-core-spinner is-active" aria-hidden="true"><span class="cb-core-spinner__ring"></span></span>
				${ escapeHtmlText( i18n.runningScan || 'Running Core Scanner' ) }
			</h2>
			<span class="cb-core-integrity-progress-timer" id="cb-core-integrity-progress-timer">0:00.0</span>
		</div>
		<div class="cb-core-integrity-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<div class="cb-core-integrity-progress-bar-fill" id="cb-core-integrity-progress-fill" style="width: 0%"></div>
		</div>
		<p class="cb-core-integrity-progress-phase" id="cb-core-integrity-progress-phase">
			${ escapeHtmlText( i18n.phaseStarting || 'Starting…' ) }
		</p>
	`;

	// Insert at top of the wrap so the sticky-positioning + sibling
	// selector dim work as designed (everything after the progress
	// block gets dimmed via "~ *" in CSS).
	wrap.insertBefore( section, wrap.firstChild );

	// Flip wrap to scanning-state so CSS dim/non-interactive rules apply.
	wrap.dataset.cbIntegrityState = 'scanning';
	wrap.classList.remove( 'cb-core-integrity-state-result', 'cb-core-integrity-state-idle' );
	wrap.classList.add( 'cb-core-integrity-state-scanning' );
}

/**
 * Tiny HTML-text escaper for innerHTML interpolation. The values come
 * from the i18n payload (admin-only, server-supplied) so this is
 * defence-in-depth rather than required, but the lint is cheap.
 */
function escapeHtmlText( str ) {
	const div = document.createElement( 'div' );
	div.textContent = String( str );
	return div.innerHTML;
}

/**
 * Start (or resume) a scan run.
 *
 * Two possible flows:
 *   1. Fresh scan: POST /scan → 202 + job_id → start polling
 *   2. Already-running: 409 + existing_job_id → resume polling
 */
async function runScan( button ) {
	if ( ! button || scanInProgress ) {
		return;
	}
	scanInProgress = true;
	setBusy( button, true, ( i18n.running || 'Running Core Scanner…' ), 'update' );

	try {
		const response = await request( '/scan', {
			method: 'POST',
			body:   JSON.stringify( {} ),
			rawResponseOn: [ 409 ],
		} );

		// Async path - 202 with job_id
		saveActiveJob( {
			jobId:     response.job_id,
			startedAt: response.started_at,
		} );
		injectProgressMarkup();
		startPolling();
	} catch ( error ) {
		// 409 Conflict - resume existing job
		if ( error.status === 409 && error.payload && error.payload.existing_job_id ) {
			saveActiveJob( {
				jobId:     error.payload.existing_job_id,
				startedAt: error.payload.started_at || ( Date.now() / 1000 ),
			} );
			toast( ( i18n.scanAlreadyRunning || 'A scan is already running. Progress has been restored.' ), 'info' );
			injectProgressMarkup();
			startPolling();
			scanInProgress = false;
			setBusy( button, false );
			return;
		}

		toast( `${ i18n.failed || 'Core scan failed.' } ${ error.message }`, 'error' );
		setBusy( button, false );
		scanInProgress = false;
	}
}

/**
 * Resume polling on page-load if a stored active job exists.
 *
 * Called from the module's init phase. Three outcomes:
 *   - localStorage has a job, server confirms running → resume polling
 *   - localStorage has a job, server returns 404 (TTL gone) → clear and continue
 *   - no localStorage → no-op, normal page render
 */
async function resumeActiveJobIfAny() {
	const wrap = document.querySelector( '[data-cb-integrity-state]' );
	if ( ! wrap ) {
		return;
	}

	// The server-side persisted job is authoritative. localStorage only helps
	// when the same browser started the job; scheduled/API scans and recovery
	// flows can legitimately have no matching browser state.
	const serverJob = data.activeJob && data.activeJob.jobId ? data.activeJob : null;
	const stored    = readStoredActiveJob();

	if ( wrap.dataset.cbIntegrityState !== 'scanning' ) {
		clearActiveJob();
		return;
	}

	const job = serverJob || ( stored && stored.jobId ? stored : null );
	if ( ! job ) {
		return;
	}

	if ( ! stored || stored.jobId !== job.jobId ) {
		saveActiveJob( job );
	} else {
		activeJob = stored;
	}
	startPolling();
}

// ─── Components filter ────────────────────────────────────────────
// Component cards are server-side filter links. This keeps filtering scoped to
// the complete stored finding set and preserves pagination without rendering
// every incident in the browser.

async function approveBaseline( button ) {
	if ( ! button ) {
		return;
	}

	const isUpdate  = /update/i.test( button.textContent || '' );
	const confirmed = await confirmModal( {
		title:        isUpdate ? ( i18n.baselineUpdateTitle || 'Update all local baselines'   ) : ( i18n.baselineTitle      || 'Approve all local baselines' ),
		message:      isUpdate ? ( i18n.confirmBaselineUpdate || 'Bulk-update every eligible plugin and theme local baseline from the latest scan. Only continue after reviewing the affected components and confirming their current file states are expected.' )
		                       : ( i18n.confirmBaseline       || 'Bulk-approve every eligible plugin and theme snapshot from the latest scan as a local baseline. Review the listed components first; future scans will treat these exact file states as trusted.' ),
		confirmLabel: isUpdate ? ( i18n.baselineUpdate || 'Update all eligible baselines' ) : ( i18n.baselineConfirm || 'Approve all eligible baselines' ),
		cancelLabel:  ( i18n.cancel || 'Cancel' ),
	} );

	if ( ! confirmed ) {
		return;
	}

	setBusy( button, true, ( i18n.approvingBaseline || 'Approving baseline…' ) );

	try {
		const response = await request( '/baseline', { method: 'POST', body: JSON.stringify( {} ) } );
		toast( ( i18n.baselineApproved || 'Baseline approved.' ), 'success' );
		if ( attachQueuedScan( response ) ) {
			return;
		}
		if ( response && response.rescan_queued === false && response.rescan_error ) {
			toast( response.rescan_error, 'warning' );
		}
		// Reload so the server re-renders the result-state with
		// updated component/finding/verified DOM. In-place
		// updateSummary() would re-render Components with obsolete
		// markup and break the filter buttons. Reloading keeps the
		// result-state and interaction bindings authoritative.
		window.setTimeout( () => window.location.reload(), 800 );
	} catch ( error ) {
		toast( `${ i18n.baselineFailed || 'Baseline approval failed.' } ${ error.message }`, 'error' );
		setBusy( button, false );
	}
}

async function clearBaseline( button ) {
	if ( ! button ) {
		return;
	}

	const confirmed = await confirmModal( {
		title:        ( i18n.baselineClearTitle   || 'Clear approved baseline' ),
		message:      ( i18n.confirmBaselineClear || 'Clear the entire approved baseline. All previously approved components will need to be re-verified - run a new scan afterwards to start fresh.' ),
		confirmLabel: ( i18n.baselineClear        || 'Clear Approved Baseline' ),
		cancelLabel:  ( i18n.cancel               || 'Cancel' ),
		variant:      'danger',
	} );

	if ( ! confirmed ) {
		return;
	}

	setBusy( button, true, ( i18n.clearingBaseline || 'Clearing baseline…' ) );

	try {
		await request( '/baseline', { method: 'DELETE' } );
		toast( ( i18n.baselineCleared || 'Approved baseline cleared.' ), 'success' );
		window.setTimeout( () => window.location.reload(), 800 );
	} catch ( error ) {
		toast( `${ i18n.baselineClearFailed || 'Clear failed.' } ${ error.message }`, 'error' );
		setBusy( button, false );
	}
}

async function approveComponentBaseline( button ) {
	if ( ! button ) {
		return;
	}

	const type = button.dataset.cbIntegrityType || '';
	const slug = button.dataset.cbIntegritySlug || '';

	const hasBaseline = button.dataset.cbIntegrityBaselineExists === '1';
	const confirmed   = await confirmModal( {
		title: hasBaseline
			? ( i18n.baselineUpdateTitle    || 'Update approved baseline'         )
			: ( i18n.componentBaselineTitle || 'Approve component baseline'      ),
		message: hasBaseline
			? ( i18n.confirmComponentUpdate  || 'Update the approved baseline for {slug}. Future scans will compare this component against its current state. Only continue after confirming this change is expected.' ).replace( '{slug}', slug )
			: ( i18n.confirmComponentApprove || 'Approve the current state of {slug} as the local baseline. Future changes for this component will be flagged.' ).replace( '{slug}', slug ),
		confirmLabel: hasBaseline
			? ( i18n.baselineUpdate            || 'Update Approved Baseline' )
			: ( i18n.componentBaselineConfirm || 'Approve component'         ),
		cancelLabel: ( i18n.cancel || 'Cancel' ),
	} );

	if ( ! confirmed ) {
		return;
	}

	setBusy( button, true, ( i18n.approvingBaseline || 'Approving baseline…' ) );

	try {
		const response = await request( '/baseline/component', {
			method: 'POST',
			body:   JSON.stringify( { type, slug } ),
		} );
		toast( ( i18n.baselineApproved || 'Component baseline approved.' ), 'success' );
		if ( attachQueuedScan( response ) ) {
			return;
		}
		if ( response && response.rescan_queued === false && response.rescan_error ) {
			toast( response.rescan_error, 'warning' );
		}
		window.setTimeout( () => window.location.reload(), 800 );
	} catch ( error ) {
		toast( `${ i18n.baselineFailed || 'Baseline approval failed.' } ${ error.message }`, 'error' );
		setBusy( button, false );
	}
}

async function removeComponentBaseline( button ) {
	if ( ! button ) {
		return;
	}

	const type = button.dataset.cbIntegrityType || '';
	const slug = button.dataset.cbIntegritySlug || '';

	const confirmed = await confirmModal( {
		title:   ( i18n.baselineRemoveTitle   || 'Remove from approved baseline' ),
		message: ( i18n.confirmBaselineRemove || 'Remove {slug} from the approved baseline. The scanner will stop tracking this component. Use this only when the component has been intentionally removed from the site.' ).replace( '{slug}', slug ),
		confirmLabel: ( i18n.baselineRemove || 'Remove from baseline' ),
		cancelLabel:  ( i18n.cancel         || 'Cancel' ),
		variant:      'danger',
	} );

	if ( ! confirmed ) {
		return;
	}

	setBusy( button, true, ( i18n.removingBaseline || 'Removing from baseline…' ) );

	try {
		const response = await request( '/baseline/component', {
			method: 'DELETE',
			body:   JSON.stringify( { type, slug } ),
		} );
		toast( ( i18n.baselineRemoved || 'Removed from approved baseline.' ), 'success' );
		if ( attachQueuedScan( response ) ) {
			return;
		}
		if ( response && response.rescan_queued === false && response.rescan_error ) {
			toast( response.rescan_error, 'warning' );
		}
		window.setTimeout( () => window.location.reload(), 800 );
	} catch ( error ) {
		toast( `${ i18n.baselineRemoveFailed || 'Removal failed.' } ${ error.message }`, 'error' );
		setBusy( button, false );
	}
}

async function saveSettings( button ) {
	const status = qs( '#cb-core-integrity-settings-status' );
	if ( ! button ) {
		return;
	}

	setBusy( button, true, ( i18n.saving || 'Saving settings…' ) );
	setStatus( status, ( i18n.saving || 'Saving settings…' ), 'pending' );

	try {
		await request( '/settings', {
			method: 'POST',
			body:   JSON.stringify( {
				schedule:         qs( '#cb-core-integrity-schedule'         )?.value   || 'disabled',
				plugin_checksums: Boolean( qs( '#cb-core-integrity-plugin-checksums' )?.checked ),
				theme_checksums:  Boolean( qs( '#cb-core-integrity-theme-checksums'  )?.checked ),
				uploads_scan:     Boolean( qs( '#cb-core-integrity-uploads-scan'     )?.checked ),
			} ),
		} );
		setStatus( status, ( i18n.saved || 'Settings saved.' ), 'success' );
		toast( ( i18n.saved || 'Settings saved.' ), 'success' );
	} catch ( error ) {
		setStatus( status, error.message, 'error' );
		toast( error.message, 'error' );
	} finally {
		setBusy( button, false );
	}
}

async function clearResults( button ) {
	if ( ! button ) {
		return;
	}

	const confirmed = await confirmModal( {
		title:        ( i18n.clearTitle    || 'Clear scan results' ),
		message:      ( i18n.confirmClear  || 'Clear the stored integrity scan result?' ),
		confirmLabel: ( i18n.clearConfirm  || 'Clear results' ),
		cancelLabel:  ( i18n.cancel        || 'Cancel'        ),
		variant:      'danger',
	} );

	if ( ! confirmed ) {
		return;
	}

	setBusy( button, true, ( i18n.clearing || 'Clearing results…' ) );

	try {
		await request( '/clear', { method: 'DELETE' } );
		toast( ( i18n.cleared || 'Results cleared.' ), 'success' );
		// Reload so the server re-renders to idle-state (no findings,
		// no components, no verified blocks - empty-state CTA only).
		// In-place updateSummary() would only update the summary tiles
		// but leave the result-state DOM blocks visible with stale data.
		window.setTimeout( () => window.location.reload(), 800 );
	} catch ( error ) {
		toast( `${ i18n.clearFailed || 'Clear failed.' } ${ error.message }`, 'error' );
		setBusy( button, false );
	}
}

async function redetectLocale( button ) {
	if ( ! button ) {
		return;
	}

	setBusy( button, true, ( i18n.redetectingLocale || 'Detecting…' ) );

	try {
		const payload = await request( '/locale/redetect', { method: 'POST', body: JSON.stringify( {} ) } );

		if ( payload && payload.detected ) {
			toast(
				( i18n.localeDetected || 'Distribution locale detected: {locale}' ).replace( '{locale}', payload.detected ),
				'success'
			);
		} else {
			toast( ( i18n.localeInconclusive || 'Detection inconclusive - see panel for details.' ), 'warning' );
		}

		// The Distribution Locale panel is server-rendered from current
		// settings state. Reload so the operator sees the fresh status,
		// tried list, last-detected-at, and cross-check outcome
		// without having to manually refresh. Same approach used after
		// the Login Shield slug change in 1.3.4-dev.
		window.setTimeout( () => window.location.reload(), 800 );
	} catch ( error ) {
		toast( `${ i18n.localeDetectFailed || 'Detection failed.' } ${ error.message }`, 'error' );
		setBusy( button, false );
	}
}

async function copyPath( button ) {
	if ( ! button ) {
		return;
	}

	const value = button.dataset.cbIntegrityCopyValue || '';
	if ( ! value ) {
		return;
	}

	const fallbackCopy = () => {
		const textarea = document.createElement( 'textarea' );
		textarea.value = value;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild( textarea );
		textarea.select();
		const copied = document.execCommand( 'copy' );
		textarea.remove();
		if ( ! copied ) {
			throw new Error( i18n.pathCopyFailed || 'Could not copy the filesystem path.' );
		}
	};

	try {
		if ( navigator.clipboard && window.isSecureContext ) {
			await navigator.clipboard.writeText( value );
		} else {
			fallbackCopy();
		}
		toast( i18n.pathCopied || 'Filesystem path copied.', 'success' );
	} catch ( error ) {
		try {
			fallbackCopy();
			toast( i18n.pathCopied || 'Filesystem path copied.', 'success' );
		} catch ( fallbackError ) {
			toast( fallbackError.message || i18n.pathCopyFailed || 'Could not copy the filesystem path.', 'error' );
		}
	}
}


async function quarantineFinding( button ) {
	const findingId = button?.dataset.cbIntegrityFindingId || '';
	const scope = button?.dataset.cbIntegrityScope === 'directory' ? 'directory' : 'file';
	if ( ! findingId ) return;
	const quarantineLabel = scope === 'directory'
		? ( i18n.quarantineDirectoryTitle || 'Quarantine folder' )
		: ( i18n.quarantineFileTitle || 'Quarantine file' );
	const confirmed = await confirmModal( {
		title: quarantineLabel,
		message: scope === 'directory'
			? ( i18n.quarantineDirectoryBody || 'Move this finding\'s top-level uploads directory out of the active site and into the private Quarantine Workspace? Every file is re-verified first; symlinks or changed evidence abort the action.' )
			: ( i18n.quarantineFileBody || 'Move this exact scanned file out of the active site and into the private Quarantine Workspace? Its SHA-256 is re-verified immediately before the move.' ),
		confirmLabel: quarantineLabel,
		cancelLabel: i18n.cancel || 'Cancel',
		variant: 'remediation',
		icon: 'quarantine',
	} );
	if ( ! confirmed ) return;
	setBusy( button, true, i18n.quarantining || 'Quarantining…' );
	try {
		await request( '/quarantine', { method: 'POST', body: JSON.stringify( { finding_id: findingId, scope } ) } );
		toast( i18n.quarantineDone || 'Item moved to Quarantine Workspace.', 'success' );
		window.setTimeout( () => {
			const target = new URL( window.location.href );
			target.searchParams.set( 'cb_integrity_view', 'quarantine' );
			target.searchParams.delete( 'cb_integrity_search' );
			target.searchParams.delete( 'cb_integrity_component' );
			target.searchParams.delete( 'cb_integrity_severity' );
			target.searchParams.delete( 'cb_integrity_status' );
			target.searchParams.delete( 'cb_integrity_actionable' );
			target.searchParams.delete( 'cb_integrity_findings' );
			target.hash = '';
			window.location.href = target.toString();
		}, 500 );
	} catch ( error ) { toast( error.message, 'error' ); setBusy( button, false ); }
}

function quarantineInspectBody( payload ) {
	const item = payload?.item || {};
	const wrap = document.createElement( 'div' );
	wrap.className = 'cb-core-quarantine-inspect';
	const meta = document.createElement( 'dl' );
	meta.className = 'cb-core-quarantine-inspect__meta';
	for ( const [ label, value ] of [
		[ i18n.quarantineOriginalPath || 'Original location', item.relative_path || '' ],
		[ i18n.quarantineStatus || 'Status', item.status || '' ],
		[ i18n.quarantineFiles || 'Files', String( item.file_count || 0 ) ],
		[ 'SHA-256', item.evidence_sha256 || '' ],
	] ) {
		const dt = document.createElement( 'dt' ); dt.textContent = label;
		const dd = document.createElement( 'dd' ); dd.textContent = value;
		meta.append( dt, dd );
	}
	wrap.appendChild( meta );
	if ( Array.isArray( item.files ) && item.files.length > 1 ) {
		const select = document.createElement( 'select' );
		select.className = 'widefat cb-core-quarantine-inspect__file';
		for ( const file of item.files ) {
			const option = document.createElement( 'option' );
			option.value = file; option.textContent = file; option.selected = file === payload.preview_file;
			select.appendChild( option );
		}
		wrap.appendChild( select );
	}
	const pre = document.createElement( 'pre' );
	pre.className = 'cb-core-quarantine-preview';
	pre.textContent = payload.preview || ( i18n.quarantineNoPreview || 'No safe text preview is available for this file.' );
	wrap.appendChild( pre );
	if ( payload.preview_truncated ) {
		const note = document.createElement( 'p' ); note.className = 'cb-core-integrity-muted';
		note.textContent = i18n.quarantinePreviewTruncated || 'Preview truncated.'; wrap.appendChild( note );
	}
	return wrap;
}

async function inspectQuarantine( button ) {
	const id = button?.dataset.cbQuarantineId || '';
	if ( ! id ) return;
	try {
		let payload = await request( `/quarantine/${ encodeURIComponent( id ) }` );
		const body = quarantineInspectBody( payload );
		const select = body.querySelector( '.cb-core-quarantine-inspect__file' );
		if ( select ) select.addEventListener( 'change', async () => {
			try {
				payload = await request( `/quarantine/${ encodeURIComponent( id ) }?file=${ encodeURIComponent( select.value ) }` );
				const pre = body.querySelector( '.cb-core-quarantine-preview' );
				if ( pre ) pre.textContent = payload.preview || ( i18n.quarantineNoPreview || 'No safe text preview is available for this file.' );
			} catch ( error ) { toast( error.message, 'error' ); }
		} );
		await window.cbCore.modal.show( { title: i18n.quarantineInspectTitle || 'Inspect quarantined item', body, confirmLabel: i18n.close || 'Close', cancelLabel: i18n.close || 'Close' } );
	} catch ( error ) { toast( error.message, 'error' ); }
}

async function restoreQuarantine( button ) {
	const id = button?.dataset.cbQuarantineId || '';
	if ( ! id ) return;
	const body = document.createElement( 'div' );
	const message = document.createElement( 'p' );
	message.textContent = i18n.quarantineRestoreBody || 'Restore this item to its exact original location? Restore is refused if anything now exists at that path or if the quarantine payload changed.';
	body.appendChild( message );
	const acknowledgementTemplate = document.getElementById( 'cb-integrity-quarantine-restore-acknowledgement-template' );
	if ( acknowledgementTemplate ) {
		body.appendChild( acknowledgementTemplate.content.cloneNode( true ) );
	}
	const confirmed = await confirmModal( {
		title: i18n.quarantineRestoreTitle || 'Restore quarantined item',
		body,
		confirmLabel: i18n.quarantineRestore || 'Restore',
		icon: 'restore',
		onConfirm: () => {
			const acknowledgement = body.querySelector( '[name="quarantine_restore_acknowledgement"]' );
			if ( ! acknowledgement?.checked ) {
				acknowledgement?.reportValidity();
				acknowledgement?.focus();
				return false;
			}
			return true;
		},
	} );
	if ( ! confirmed ) return;
	setBusy( button, true, i18n.quarantineRestoring || 'Restoring…' );
	try {
		await request( `/quarantine/${ encodeURIComponent( id ) }/restore`, { method: 'POST', body: JSON.stringify( { quarantine_restore_acknowledgement: '1' } ) } );
		toast( i18n.quarantineRestored || 'Quarantine item restored.', 'success' ); window.setTimeout( () => window.location.reload(), 500 );
	} catch ( error ) { toast( error.message, 'error' ); setBusy( button, false ); }
}

async function deleteQuarantine( button ) {
	const id = button?.dataset.cbQuarantineId || '';
	if ( ! id ) return;
	const confirmed = await confirmModal( { title: i18n.quarantineDeleteTitle || 'Permanently delete quarantined item', message: i18n.quarantineDeleteBody || 'This permanently destroys the isolated payload. The workspace record and audit trail remain, but the file cannot be restored.', confirmLabel: i18n.quarantineDelete || 'Permanently delete', cancelLabel: i18n.cancel || 'Cancel', variant: 'danger', icon: 'delete', typedConfirm: 'DELETE', typedConfirmHint: i18n.quarantineDeleteHint || 'Type to confirm:' } );
	if ( ! confirmed ) return;
	setBusy( button, true, i18n.quarantineDeleting || 'Deleting…' );
	try {
		await request( `/quarantine/${ encodeURIComponent( id ) }/delete`, { method: 'DELETE', body: JSON.stringify( { confirm: 'DELETE' } ) } );
		toast( i18n.quarantineDeleted || 'Quarantine payload permanently deleted.', 'success' ); window.setTimeout( () => window.location.reload(), 500 );
	} catch ( error ) { toast( error.message, 'error' ); setBusy( button, false ); }
}

async function addQuarantineNote( button ) {
	const id = button?.dataset.cbQuarantineId || '';
	if ( ! id ) return;
	const note = await confirmModal( { title: i18n.quarantineNoteTitle || 'Add quarantine note', message: i18n.quarantineNoteBody || 'Add investigation context for this item. Notes become part of the workspace history.', confirmLabel: i18n.quarantineNoteSave || 'Save note', input: { type: 'text', required: true, maxLength: 2000, placeholder: i18n.quarantineNotePlaceholder || 'Investigation note…' } } );
	if ( note === null || String( note ).trim() === '' ) return;
	try { await request( `/quarantine/${ encodeURIComponent( id ) }/note`, { method: 'POST', body: JSON.stringify( { note } ) } ); toast( i18n.quarantineNoteSaved || 'Quarantine note saved.', 'success' ); window.setTimeout( () => window.location.reload(), 400 ); } catch ( error ) { toast( error.message, 'error' ); }
}

async function setQuarantineState( button ) {
	const id = button?.dataset.cbQuarantineId || '';
	const state = button?.dataset.cbQuarantineState || '';
	if ( ! id || ! state ) return;
	try { await request( `/quarantine/${ encodeURIComponent( id ) }/state`, { method: 'PUT', body: JSON.stringify( { state } ) } ); toast( i18n.quarantineStateSaved || 'Quarantine review state updated.', 'success' ); window.setTimeout( () => window.location.reload(), 350 ); } catch ( error ) { toast( error.message, 'error' ); }
}

function openBaselineReview( button ) {
	const details = button?.closest( '.cb-core-integrity-component-result' );
	if ( ! details ) return;
	details.open = true;
}

async function markBaselineReviewed( button ) {
	const candidateId = button?.dataset.cbIntegrityCandidateId || '';
	if ( ! candidateId ) return;
	setBusy( button, true, ( i18n.baselineReviewing || 'Saving review…' ) );
	try {
		await request( '/baseline/review', {
			method: 'PUT',
			body: JSON.stringify( { candidate_id: candidateId } ),
		} );
		toast( i18n.baselineReviewed || 'Baseline candidate marked as reviewed.', 'success' );
		window.setTimeout( () => window.location.reload(), 250 );
	} catch ( error ) {
		setBusy( button, false );
		toast( `${ i18n.baselineReviewFailed || 'Could not save baseline review.' } ${ error.message }`, 'error' );
	}
}

// ─── Action dispatcher ───────────────────────────────────────────────────────

function handleAction( event ) {
	const button = event.target.closest( '[data-cb-integrity-action]' );
	if ( ! button ) {
		return;
	}

	event.preventDefault();
	const action = button.dataset.cbIntegrityAction;

	if ( action === 'run-scan'                    ) { return runScan(                  button ); }
	if ( action === 'clear-results'               ) { return clearResults(             button ); }
	if ( action === 'approve-baseline'            ) { return approveBaseline(          button ); }
	if ( action === 'clear-baseline'              ) { return clearBaseline(            button ); }
	if ( action === 'approve-component-baseline'  ) { return approveComponentBaseline( button ); }
	if ( action === 'remove-component-baseline'   ) { return removeComponentBaseline(  button ); }
	if ( action === 'redetect-locale'             ) { return redetectLocale(           button ); }
	if ( action === 'save-settings'               ) { return saveSettings(             button ); }
	if ( action === 'copy-path'                   ) { return copyPath(                  button ); }
	if ( action === 'open-baseline-review'        ) { return openBaselineReview(         button ); }
	if ( action === 'mark-baseline-reviewed'      ) { return markBaselineReviewed(       button ); }
	if ( action === 'quarantine-finding'          ) { return quarantineFinding(         button ); }
	if ( action === 'quarantine-inspect'          ) { return inspectQuarantine(          button ); }
	if ( action === 'quarantine-restore'          ) { return restoreQuarantine(          button ); }
	if ( action === 'quarantine-delete'           ) { return deleteQuarantine(           button ); }
	if ( action === 'quarantine-note'             ) { return addQuarantineNote(          button ); }
	if ( action === 'quarantine-state'            ) { return setQuarantineState(         button ); }
}


// ─── Wire it up ──────────────────────────────────────────────────────────────

if ( qs( '.cb-core-integrity-wrap' ) ) {
	document.addEventListener( 'click', handleAction );
	resumeActiveJobIfAny();
}
