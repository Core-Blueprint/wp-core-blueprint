/**
 * Global Template Library batch selection and actions.
 * UI selection is not authority. Each Delete POST reuses the existing
 * provider-owned, revision-fenced manage route. Bulk JSON export reads only
 * through the canonical provider exporter; never exports a T4 resource ZIP.
 */
import { show as showModal } from '@cb-core/modal';

const MAX = 12;
const ID = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/;

function element( tag, className = '', value = '' ) {
    const node = document.createElement( tag );
    if ( className ) node.className = className;
    if ( value ) node.textContent = value;
    return node;
}

function revisionOf( item ) {
    if ( !item || typeof item.version !== 'string' || !/^[1-9][0-9]*$/.test( item.version ) ) return null;
    const revision = Number( item.version );
    return Number.isSafeInteger( revision ) ? revision : null;
}

function eligible( item ) {
    return !!item && [ 'personal', 'site' ].includes( item.origin )
        && typeof item.id === 'string' && ID.test( item.id )
        && typeof item.source === 'string' && ID.test( item.source )
        && revisionOf( item ) !== null && Array.isArray( item.actions )
        && item.actions.some( action => [ 'delete', 'export-json' ].includes( action ) );
}

function keyOf( item ) { return item.source + ':' + item.id; }

function sameOriginRoot( raw ) {
    try {
        const root = new URL( raw, window.location.href );
        if ( root.origin !== window.location.origin || root.username || root.password || root.search || root.hash
            || !root.pathname.endsWith( '/core-blueprint/v1/template-library/' ) ) return null;
        return root.href;
    } catch { return null; }
}

async function send( root, nonce, action, payload ) {
    const response = await fetch( root + action, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        referrerPolicy: 'no-referrer',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify( payload ),
    } );
    const body = await response.json();
    return response.ok && body && typeof body.status === 'string'
        ? body : { status: typeof body?.status === 'string' ? body.status : 'unknown' };
}

function download( encoded, label ) {
    if ( typeof encoded !== 'string' || encoded.length > 22000000
        || !/^[A-Za-z0-9+/]+={0,2}$/.test( encoded )
        || typeof atob !== 'function' || typeof URL.createObjectURL !== 'function' ) return false;
    try {
        const binary = atob( encoded );
        if ( binary.length < 30 || binary.length > 16000000
            || binary.charCodeAt( 0 ) !== 0x50 || binary.charCodeAt( 1 ) !== 0x4b ) return false;
        const bytes = Uint8Array.from( binary, char => char.charCodeAt( 0 ) );
        const url = URL.createObjectURL( new Blob( [ bytes ], { type: 'application/zip' } ) );
        try {
            const link = element( 'a' );
            link.href = url;
            link.download = 'core-blueprint-templates-bulk-json.zip';
            document.body.append( link );
            link.click();
            link.remove();
            return true;
        } finally {
            setTimeout( () => URL.revokeObjectURL( url ), 1000 );
        }
    } catch { return false; }
}

/**
 * The control belongs to one global Browser instance. Contextual Designer
 * instances must not construct it or receive any batch mutation API.
 */
export function createBulkControl( options ) {
    const label = options.label;
    const root = sameOriginRoot( options.restRoot );
    const bar = element( 'div', 'cb-template-browser__bulk' );
    bar.hidden = true;
    const toggle = element( 'label', 'cb-template-browser__bulk-all' );
    const allCheckbox = element( 'input' );
    allCheckbox.type = 'checkbox';
    const allText = element( 'span', '', label( 'bulkSelectShown', 'Select shown' ) );
    toggle.append( allCheckbox, allText );
    const count = element( 'span', 'cb-template-browser__bulk-count' );
    count.setAttribute( 'role', 'status' );
    count.setAttribute( 'aria-live', 'polite' );
    const buttons = element( 'div', 'cb-template-browser__bulk-actions' );
    const exportButton = element( 'button', 'button cb-template-browser__bulk-export',
        label( 'bulkExport', 'Export selected ZIP' ) );
    const deleteButton = element( 'button', 'button cb-template-browser__bulk-delete',
        label( 'bulkDelete', 'Delete selected' ) );
    const clearButton = element( 'button', 'button cb-template-browser__bulk-clear',
        label( 'bulkClear', 'Clear selection' ) );
    for ( const button of [ exportButton, deleteButton, clearButton ] ) button.type = 'button';
    buttons.append( exportButton, deleteButton, clearButton );
    bar.append( toggle, count, buttons );

    const available = new Map();
    const selected = new Map();
    let busy = false;
    let unknown = false;
    let destroyed = false;
    let generation = 0;
    let idSequence = 0;

    const notice = text => { if ( !destroyed ) options.onNotice( text ); };
    const current = () => !destroyed && !unknown && !!root && !!options.nonce && options.isCurrent();
    const list = () => [...selected.values()].map( entry => entry.item );
    const allAllowed = action => selected.size > 0 && list().every( item => item.actions.includes( action ) );

    function sync() {
        bar.hidden = destroyed || available.size === 0;
        const picked = selected.size;
        count.textContent = picked ? picked + ' ' + label( 'bulkSelected', 'selected' ) : '';
        buttons.hidden = picked === 0;
        allCheckbox.checked = available.size > 0 && picked === available.size;
        allCheckbox.indeterminate = picked > 0 && picked < available.size;
        allCheckbox.disabled = busy || unknown;
        exportButton.disabled = busy || unknown || !current() || !allAllowed( 'export-json' );
        deleteButton.disabled = busy || unknown || !current() || !allAllowed( 'delete' );
        clearButton.disabled = busy;
        for ( const [key, entry] of available ) {
            entry.input.checked = selected.has( key );
            entry.input.disabled = busy || unknown || ( !selected.has( key ) && picked >= MAX );
            entry.article?.classList.toggle( 'is-bulk-selected', selected.has( key ) );
        }
    }

    function reset() {
        ++generation;
        selected.clear();
        available.clear();
        sync();
    }

    function checkbox( item, article ) {
        if ( destroyed || !eligible( item ) || !root ) return null;
        const key = keyOf( item );
        if ( available.has( key ) ) return null; // no ambiguous duplicate identities
        const wrapper = element( 'label', 'cb-template-browser__bulk-select' );
        const input = element( 'input', 'cb-template-browser__bulk-checkbox' );
        input.type = 'checkbox';
        input.setAttribute( 'aria-label', label( 'bulkSelectTemplate', 'Select template' ) + ': ' + item.title );
        const id = 'cb-template-bulk-' + ++idSequence;
        input.id = id;
        wrapper.append( input );
        available.set( key, { input, item, article } );
        input.addEventListener( 'change', () => {
            if ( !current() || busy ) { sync(); return; }
            if ( input.checked ) {
                if ( selected.size >= MAX ) { sync(); return; }
                selected.set( key, { item } );
            } else selected.delete( key );
            sync();
        } );
        sync();
        return wrapper;
    }

    allCheckbox.addEventListener( 'change', () => {
        if ( !current() || busy ) return sync();
        if ( !allCheckbox.checked ) selected.clear();
        else {
            for ( const [key, entry] of available ) {
                if ( selected.size >= MAX ) break;
                selected.set( key, { item: entry.item } );
            }
            if ( available.size > MAX ) notice( label( 'bulkLimit', 'Select at most 12 templates per batch.' ) );
        }
        sync();
    } );
    clearButton.addEventListener( 'click', () => { if ( !busy ) { selected.clear(); sync(); } } );

    async function exportSelected() {
        if ( busy || !current() || !allAllowed( 'export-json' ) ) return;
        const records = list().map( item => ( {
            source: item.source, templateId: item.id, revision: revisionOf( item ),
        } ) );
        busy = true;
        sync();
        try {
            const response = await send( root, options.nonce, 'bulk-export', { items: records } );
            if ( response.status === 'ready' && response.format === 'core-blueprint-bulk-json-v1'
                && response.count === records.length && download( response.data ) ) {
                notice( label( 'bulkExportReady', 'Selected JSON templates downloaded as ZIP.' ) );
            } else {
                notice( label( 'bulkExportRefused', 'Bulk export could not be verified. Review selected templates.' ) );
            }
        } catch {
            notice( label( 'bulkExportRefused', 'Bulk export could not be verified. Review selected templates.' ) );
        } finally { busy = false; sync(); }
    }
    exportButton.addEventListener( 'click', () => { void exportSelected(); } );

    async function deleteSelected() {
        if ( busy || !current() || !allAllowed( 'delete' ) ) return;
        const records = list().map( item => ( {
            source: item.source, templateId: item.id, revision: revisionOf( item ),
        } ) );
        const checkpoint = generation;
        busy = true;
        sync();
        let confirmed = false;
        try {
            confirmed = await showModal( {
                title: label( 'bulkDeleteTitle', 'Delete selected templates' ),
                body: label( 'bulkDeleteWarning', 'Permanently delete the selected saved templates? This cannot be undone.' )
                    + ' (' + records.length + ')',
                confirmLabel: label( 'bulkDelete', 'Delete selected' ),
                cancelLabel: label( 'cancel', 'Cancel' ),
                confirmVariant: 'danger',
                confirmCheck: { label: label( 'bulkDeleteCheck',
                    'I understand that selected templates will be permanently deleted.' ) },
                confirmEnabled: () => current() && generation === checkpoint,
            } );
            if ( !confirmed || !current() || checkpoint !== generation ) return;
            let completed = 0;
            let status = 'deleted';
            for ( const record of records ) {
                if ( !current() || checkpoint !== generation ) { status = 'refused'; break; }
                try {
                    const result = await send( root, options.nonce, 'manage', {
                        ...record, action: 'delete', input: {},
                    } );
                    status = result.status;
                } catch { status = 'unknown'; }
                if ( status !== 'deleted' ) break; // exact-once; do not retry uncertain writes
                ++completed;
            }
            if ( status === 'unknown' || !current() || checkpoint !== generation ) {
                unknown = true;
                notice( completed + ' ' + label( 'bulkDeleted', 'deleted' ) + '. '
                    + label( 'bulkUnknown', 'Outcome uncertain. Inspect the Library before any retry.' ) );
            } else {
                const summary = completed + ' ' + label( 'bulkDeleted', 'deleted' ) + '. '
                    + ( records.length - completed ) + ' ' + label( 'bulkNotProcessed', 'not processed' ) + '.';
                notice( summary );
                await options.onChanged( summary );
            }
        } catch {
            unknown = true;
            notice( label( 'bulkUnknown', 'Outcome uncertain. Inspect the Library before any retry.' ) );
        } finally {
            busy = false;
            sync();
        }
    }
    deleteButton.addEventListener( 'click', () => { void deleteSelected(); } );
    sync();
    return Object.freeze( {
        node: bar, checkbox, reset, sync,
        destroy() {
            destroyed = true;
            ++generation;
            selected.clear();
            available.clear();
            bar.remove();
        },
    } );
}
