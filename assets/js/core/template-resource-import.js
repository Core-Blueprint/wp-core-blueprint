/**
 * Optional global Template Library ZIP import.
 *
 * Discovery is a per-provider hint, never a permission to import. The signed
 * server stage, canonical Data Exchange preview and one provider-owned create
 * remain the only authority. Never retry an ambiguous apply.
 */
import { show as showModal } from '@cb-core/modal';

const ID = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/;
const FINGERPRINT = /^[a-f0-9]{64}$/;
const MAX_BYTES = 24 * 1024 * 1024;

function element( tag, text = '' ) {
    const node = document.createElement( tag );
    if ( text ) node.textContent = text;
    return node;
}

function validEndpoint( value ) {
    if ( typeof value !== 'string' || !value ) return null;
    try {
        const url = new URL( value, window.location.href );
        return url.origin === window.location.origin && !url.username && !url.password
            && !url.search && !url.hash
            && url.pathname.endsWith( '/core-blueprint/v1/template-resource-package/' )
            ? url.href : null;
    } catch { return null; }
}

/** The only accepted endpoints, owned by Base and always same-origin. */
async function send( root, nonce, action, payload, signal ) {
    if ( ![ 'stage', 'preview', 'apply', 'discard' ].includes( action ) ||
        !root || typeof nonce !== 'string' || !nonce ) throw new Error( 'transport_unavailable' );
    const multi = action === 'stage';
    const response = await fetch( root + action, {
        method: 'POST', credentials: 'same-origin',
        headers: multi ? { 'X-WP-Nonce': nonce } :
            { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: multi ? payload : JSON.stringify( payload ), signal,
        cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer',
    } );
    if ( !response.ok ) throw new Error( 'request_refused' );
    return response.json();
}

function authorizedDestinations( sources ) {
    if ( !Array.isArray( sources ) ) return [];
    const found = new Map();
    for ( const source of sources ) {
        if ( !source || typeof source.id !== 'string' || !ID.test( source.id )
            || typeof source.resourceImportDestination !== 'string'
            || source.resourceImportDestination.length > 96
            || !ID.test( source.resourceImportDestination ) ) continue;
        // A duplicated source ID with conflicting target is not a selectable
        // import route, even if the server accidentally projects it twice.
        if ( found.has( source.id ) && found.get( source.id ) !== source.resourceImportDestination ) {
            found.set( source.id, null );
        } else if ( !found.has( source.id ) ) {
            found.set( source.id, source.resourceImportDestination );
        }
    }
    return [...found].filter( ( [, destination] ) => !!destination )
        .map( ( [ source, destination ] ) => ( { source, destination } ) );
}

/**
 * A global Browser affordance; no source => no visible button.
 * Never available inside the Designer modal (which owns its own Save/Apply).
 */
export function createImportControl( options ) {
    const label = options.label;
    const root = validEndpoint( options.resourceRoot );
    const button = element( 'button', label( 'importZip', 'Import ZIP' ) );
    button.type = 'button';
    button.className = 'button cb-template-browser__import';
    button.hidden = true;
    const status = element( 'span' );
    status.className = 'cb-template-browser__import-status';
    status.setAttribute( 'role', 'status' );
    status.setAttribute( 'aria-live', 'polite' );
    const container = element( 'div' );
    container.className = 'cb-template-browser__import-control';
    container.append( button, status );

    let sources = [];
    let destroyed = false;
    let busy = false;
    let unknown = false;
    let generation = 0;

    const notify = ( message ) => {
        if ( !destroyed ) {
            status.textContent = message;
            options.onNotice?.( message );
        }
    };
    const sync = () => {
        button.hidden = !root || !options.nonce || sources.length === 0 || destroyed;
        button.disabled = busy || unknown;
    };
    const discard = async ( ticket, destination ) => {
        if ( !ticket || !destination ) return;
        try { await send( root, options.nonce, 'discard', { ticket, destinationId: destination } ); }
        catch { /* Signed stages expire and are pruned; never report cleanup as an import write. */ }
    };

    async function start() {
        if ( destroyed || busy || unknown || !root || !sources.length ) return;
        busy = true;
        sync();
        const epoch = ++generation;
        let ticket = null;
        let destination = null;
        let fingerprint = null;
        let applyStarted = false;
        let controller = new AbortController();
        try {
            const body = element( 'div' );
            body.className = 'cb-template-browser__import-body';
            const destinationLabel = element( 'label', label( 'importDestination', 'Import destination' ) );
            const select = element( 'select' );
            select.className = 'cb-template-browser__import-select';
            for ( const item of sources ) {
                const choice = element( 'option', item.source );
                choice.value = item.destination;
                select.append( choice );
            }
            destinationLabel.append( select );
            const fileLabel = element( 'label', label( 'importFile', 'ZIP package' ) );
            const input = element( 'input' );
            input.type = 'file';
            input.accept = '.zip,application/zip';
            fileLabel.append( input );
            const error = element( 'p' );
            error.setAttribute( 'role', 'alert' );
            error.className = 'cb-template-browser__import-error';
            body.append( destinationLabel, fileLabel, error );
            let updateConfirmAction = null;
            const refreshGate = () => updateConfirmAction?.();
            input.addEventListener( 'change', refreshGate );
            select.addEventListener( 'change', refreshGate );

            const prepared = await showModal( {
                title: label( 'importZip', 'Import ZIP' ), body, size: 'md',
                confirmLabel: label( 'importPreview', 'Preview package' ),
                cancelLabel: label( 'cancel', 'Cancel' ),
                confirmEnabled: () => {
                    const file = input.files?.[0];
                    return !destroyed && epoch === generation && !!file
                        && file.size >= 22 && file.size <= MAX_BYTES
                        && sources.some( item => item.destination === select.value );
                },
                onReady: data => { updateConfirmAction = data.updateConfirmAction; },
                onConfirm: async () => {
                    const file = input.files?.[0];
                    destination = select.value;
                    if ( destroyed || epoch !== generation || !file
                        || file.size < 22 || file.size > MAX_BYTES
                        || !sources.some( item => item.destination === destination ) ) return false;
                    error.textContent = '';
                    try {
                        const body = new FormData();
                        body.set( 'destinationId', destination );
                        body.set( 'package', file, 'template-resources.zip' );
                        const staged = await send( root, options.nonce, 'stage', body, controller.signal );
                        if ( staged?.status !== 'ready' || typeof staged.ticket !== 'string'
                            || staged.ticket.length <= 80 || staged.ticket.length > 1024 ) throw new Error();
                        ticket = staged.ticket;
                        const checked = await send( root, options.nonce, 'preview',
                            { ticket, destinationId: destination }, controller.signal );
                        const preview = checked?.preview;
                        if ( checked?.status !== 'ready' || preview?.valid !== true
                            || preview?.record_count !== 1 || preview?.counts?.create !== 1
                            || typeof preview?.fingerprint !== 'string'
                            || !FINGERPRINT.test( preview.fingerprint ) ) throw new Error();
                        fingerprint = preview.fingerprint;
                        return !destroyed && epoch === generation;
                    } catch {
                        await discard( ticket, destination );
                        ticket = null;
                        fingerprint = null;
                        if ( !destroyed ) error.textContent = label( 'importInvalid', 'Package could not be verified.' );
                        return false;
                    }
                },
            } );

            if ( !prepared || !ticket || !fingerprint || destroyed || epoch !== generation ) return;
            const summary = element( 'p',
                label( 'importConfirm', 'Create one new draft copy from this verified package?' ) );
            summary.className = 'cb-template-browser__import-summary';
            const confirmed = await showModal( {
                title: label( 'importZip', 'Import ZIP' ), body: summary,
                confirmLabel: label( 'importCreate', 'Create draft copy' ),
                cancelLabel: label( 'cancel', 'Cancel' ),
                confirmEnabled: () => !unknown && !destroyed && epoch === generation,
                onConfirm: async () => {
                    // The server may commit even when its response is lost.
                    // From this point any error or invalid receipt is UNKNOWN.
                    applyStarted = true;
                    try {
                        const result = await send( root, options.nonce, 'apply',
                            { ticket, destinationId: destination, fingerprint } );
                        if ( result?.status !== 'complete' || typeof result.reference !== 'string'
                            || !result.reference ) throw new Error();
                        notify( label( 'importDone', 'Draft copy imported. Refresh to view it.' ) );
                        return true;
                    } catch {
                        unknown = true;
                        notify( label( 'importUnknown',
                            'Import outcome unknown. Inspect destination before attempting another import.' ) );
                        return false;
                    } finally { sync(); }
                },
            } );
            if ( confirmed && !destroyed ) {
                await discard( ticket, destination );
                ticket = null;
                // Only a confirmed server receipt may refresh listing. Never
                // advertise a browser-selected target as a completed import.
                await options.onChanged?.();
            }
        } catch {
            if ( !applyStarted && !destroyed ) notify( label( 'importInvalid', 'Package could not be verified.' ) );
        } finally {
            controller.abort();
            if ( !applyStarted && ticket ) await discard( ticket, destination );
            // After apply was attempted, preserve the stage for reconciliation
            // on ambiguous results; cleanup is handled by the signed TTL.
            busy = false;
            sync();
        }
    }

    button.addEventListener( 'click', () => { void start(); } );
    sync();
    return Object.freeze( {
        node: container,
        updateSources( data ) {
            if ( destroyed || busy || unknown ) return;
            sources = authorizedDestinations( data );
            sync();
        },
        destroy() {
            destroyed = true;
            ++generation;
            button.disabled = true;
            container.remove();
        },
    } );
}
