/**
 * TL-M2: one Base-owned, provider-authorized card actions disclosure for
 * Template Library and the contextual Designer Browser.
 * No template persistence, Apply, or ownership authority lives here.
 */
import { show as showModal } from '@cb-core/modal';

const OPERATIONS = Object.freeze( [ 'rename', 'duplicate', 'export-json', 'delete' ] );
let sequence = 0;

function el( tag, className = '', text = null ) {
    const node = document.createElement( tag );
    if ( className ) node.className = className;
    if ( text !== null ) node.textContent = text;
    return node;
}

function validRevision( value ) {
    if ( typeof value !== 'string' || !/^[1-9][0-9]*$/.test( value ) ) return null;
    const revision = Number( value );
    return Number.isSafeInteger( revision ) && revision > 0 ? revision : null;
}

function allowedNavigation( item, globalMode ) {
    if ( !item.navigation || typeof item.navigation !== 'object' ) return [];
    const entries = [];
    for ( const kind of [ 'preview', 'manage' ] ) {
        if ( kind === 'manage' && !globalMode ) continue;
        const link = item.navigation[kind];
        if ( !link || typeof link.url !== 'string' || typeof link.label !== 'string'
            || !link.label.trim() ) continue;
        try {
            const target = new URL( link.url, window.location.href );
            if ( ![ 'http:', 'https:' ].includes( target.protocol )
                || target.origin !== window.location.origin
                || !target.pathname.includes( '/wp-admin/' )
                || [ 'admin-post.php', 'admin-ajax.php', 'update.php' ]
                    .includes( target.pathname.split( '/' ).pop() ) ) continue;
            entries.push( { kind, href: target.href, title: link.label } );
        } catch {
            // Invalid navigation is not shown.
        }
    }
    return entries;
}

async function perform( item, action, revision, input, options ) {
    if ( !options.restRoot || !options.nonce || !options.isCurrent() ) return { status: 'refused' };
    const headers = {
        'X-WP-Nonce': options.nonce,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };
    if ( options.contextToken ) headers['X-CB-Designer-Scope'] = options.contextToken;
    try {
        const response = await fetch( options.restRoot + 'manage', {
            method: 'POST', credentials: 'same-origin', headers,
            body: JSON.stringify( {
                source: item.source, templateId: item.id, action, revision, input,
            } ),
        } );
        const result = await response.json();
        if ( !result || typeof result !== 'object' || typeof result.status !== 'string' ) {
            return { status: 'unknown' };
        }
        if ( !response.ok ) return { status: result.status };
        return result;
    } catch {
        return { status: 'unknown' };
    }
}

function saveJson( item, json ) {
    if ( typeof json !== 'string' || !json || typeof URL.createObjectURL !== 'function' ) {
        return false;
    }
    const blob = new Blob( [ json ], { type: 'application/json;charset=utf-8' } );
    const url = URL.createObjectURL( blob );
    try {
        const link = el( 'a' );
        link.href = url;
        link.download = 'core-blueprint-template-' + item.id.replace( /[^a-z0-9._-]/gi, '-' ) + '.json';
        document.body.append( link );
        link.click();
        link.remove();
        return true;
    } finally {
        // Revoke after the browser has taken the download URL.
        setTimeout( () => URL.revokeObjectURL( url ), 1000 );
    }
}

export function createActionMenu( item, options ) {
    const revision = validRevision( item.version );
    const declared = Array.isArray( item.actions ) ? item.actions : [];
    const operations = revision === null ? [] : OPERATIONS.filter( id => declared.includes( id ) );
    const navigation = allowedNavigation( item, options.globalMode );
    if ( !operations.length && !navigation.length ) return null;

    const label = options.label;
    const wrapper = el( 'div', 'cb-template-browser__action-menu' );
    const trigger = el( 'button', 'button cb-template-browser__action-trigger',
        label( 'actions', 'Actions' ) );
    trigger.type = 'button';
    trigger.setAttribute( 'aria-label', label( 'actions', 'Actions' ) + ': ' + item.title );
    trigger.setAttribute( 'aria-expanded', 'false' );
    const panel = el( 'div', 'cb-template-browser__action-panel' );
    panel.id = 'cb-template-actions-' + ++sequence;
    panel.hidden = true;
    panel.setAttribute( 'role', 'group' );
    panel.setAttribute( 'aria-label', label( 'actions', 'Actions' ) + ': ' + item.title );
    trigger.setAttribute( 'aria-controls', panel.id );
    wrapper.append( trigger, panel );
    let busy = false;
    let expanded = false;
    let disposed = false;

    const outside = event => {
        if ( !wrapper.contains( event.target ) ) close();
    };
    const close = ( restoreFocus = false ) => {
        if ( !expanded ) return;
        expanded = false;
        panel.hidden = true;
        trigger.setAttribute( 'aria-expanded', 'false' );
        document.removeEventListener( 'pointerdown', outside, true );
        document.removeEventListener( 'focusin', outside, true );
        if ( restoreFocus && trigger.isConnected ) trigger.focus();
    };
    const open = () => {
        if ( expanded || busy || disposed ) return;
        options.onOpening( close );
        expanded = true;
        panel.hidden = false;
        trigger.setAttribute( 'aria-expanded', 'true' );
        document.addEventListener( 'pointerdown', outside, true );
        document.addEventListener( 'focusin', outside, true );
    };
    const notify = ( key, fallback ) => options.onNotice( label( key, fallback ) );
    const statusMessage = status => {
        if ( status === 'conflict' ) return label( 'actionConflict', 'Template changed elsewhere. Reload and try again.' );
        if ( status === 'refused' || status === 'missing' ) return label( 'actionRefused', 'You can no longer manage this template.' );
        if ( status === 'unsupported' ) return label( 'actionUnsupported', 'This action is not supported for this template.' );
        return label( 'actionFailed', 'The action could not be confirmed. Check the template before retrying.' );
    };

    async function execute( action, input = {} ) {
        if ( busy || disposed || !options.isCurrent() ) return { status: 'refused' };
        busy = true;
        trigger.disabled = true;
        wrapper.setAttribute( 'aria-busy', 'true' );
        try {
            return await perform( item, action, revision, input, options );
        } finally {
            busy = false;
            trigger.disabled = false;
            wrapper.removeAttribute( 'aria-busy' );
        }
    }

    async function action( id ) {
        close();
        if ( id === 'export-json' ) {
            const result = await execute( id );
            if ( result.status === 'ready' && typeof result.data === 'string' ) {
                try {
                    if ( saveJson( item, result.data ) ) {
                        notify( 'exportReady', 'Template JSON downloaded.' );
                        return;
                    }
                } catch {
                    // Download failed after an authorized export: no automatic retry.
                }
                notify( 'actionFailed', 'The action could not be confirmed. Check the template before retrying.' );
            } else {
                options.onNotice( statusMessage( result.status ) );
                if ( [ 'conflict', 'unknown', 'missing', 'refused' ].includes( result.status ) ) {
                    if ( !disposed ) await options.onChanged( statusMessage( result.status ) );
                }
            }
            return;
        }
        const destructive = id === 'delete';
        const body = el( 'div', 'cb-template-browser__action-dialog' );
        const prompt = el( 'p', '', destructive
            ? label( 'deleteWarning', 'Permanently delete this saved template?' ) + ' ' + item.title
            : id === 'rename' ? label( 'renameHelp', 'Choose a new template name.' )
                : label( 'duplicateHelp', 'Name the new copy of this template.' ) );
        body.append( prompt );
        let input = null;
        if ( !destructive ) {
            const field = el( 'label', 'cb-template-browser__action-field',
                label( 'templateName', 'Template name' ) );
            input = el( 'input', 'regular-text cb-template-browser__action-input' );
            input.type = 'text';
            input.value = id === 'rename' ? item.title : item.title + ' (copy)';
            input.maxLength = 191;
            field.append( input );
            body.append( field );
        }
        const feedback = el( 'p', 'cb-template-browser__action-feedback' );
        feedback.setAttribute( 'role', 'status' );
        body.append( feedback );
        let updateAvailability = null;
        const validTitle = () => {
            if ( !input ) return true;
            const title = input.value.trim();
            return title.length > 0 && new TextEncoder().encode( title ).length <= 191;
        };
        input?.addEventListener( 'input', () => updateAvailability?.() );
        let refreshMessage = null;
        await showModal( {
            title: label( id === 'rename' ? 'rename' : id === 'duplicate' ? 'duplicate' : 'delete',
                id === 'rename' ? 'Rename' : id === 'duplicate' ? 'Duplicate' : 'Delete' ) + ': ' + item.title,
            body,
            confirmLabel: label( id === 'delete' ? 'delete' : 'saveAction',
                id === 'delete' ? 'Delete' : 'Save' ),
            cancelLabel: label( 'cancel', 'Cancel' ),
            confirmVariant: destructive ? 'danger' : 'primary',
            confirmCheck: destructive ? { label: label( 'deleteConfirm',
                'I understand this permanently deletes the saved template.' ) } : undefined,
            initialFocus: input || undefined,
            confirmEnabled: () => !disposed && !busy && options.isCurrent() && validTitle(),
            onReady: ( { updateConfirmAction } ) => { updateAvailability = updateConfirmAction; },
            onConfirm: async () => {
                if ( !options.isCurrent() || !validTitle() ) return false;
                const result = await execute( id, destructive ? {} : { title: input.value.trim() } );
                const expected = { rename: 'renamed', duplicate: 'created', delete: 'deleted' }[id];
                if ( result.status === expected ) {
                    refreshMessage = label( 'actionDone', 'Template action completed.' );
                    return true;
                }
                feedback.textContent = statusMessage( result.status );
                if ( [ 'conflict', 'unknown', 'missing', 'refused' ].includes( result.status ) ) {
                    refreshMessage = feedback.textContent;
                    return true; // close stale dialog before refreshing source authority
                }
                return false;
            },
        } );
        if ( refreshMessage ) await options.onChanged( refreshMessage );
    }

    for ( const entry of navigation ) {
        const link = el( 'a', 'cb-template-browser__action-item', entry.title );
        link.href = entry.href;
        link.addEventListener( 'click', () => close() );
        panel.append( link );
    }
    for ( const id of operations ) {
        const titles = {
            rename: [ 'rename', 'Rename' ], duplicate: [ 'duplicate', 'Duplicate' ],
            'export-json': [ 'exportJson', 'Export JSON' ], delete: [ 'delete', 'Delete' ],
        };
        const button = el( 'button', 'cb-template-browser__action-item' +
            ( id === 'delete' ? ' cb-template-browser__action-item--danger' : '' ),
            label( ...titles[id] ) );
        button.type = 'button';
        button.addEventListener( 'click', () => { if ( !busy ) void action( id ); } );
        panel.append( button );
    }
    trigger.addEventListener( 'click', () => expanded ? close() : open() );
    trigger.addEventListener( 'keydown', event => {
        if ( event.key === 'ArrowDown' ) {
            event.preventDefault();
            open();
            panel.querySelector( 'a, button' )?.focus();
        }
    } );
    wrapper.addEventListener( 'keydown', event => {
        if ( event.key === 'Escape' && expanded ) {
            event.preventDefault();
            event.stopPropagation();
            close( true );
        }
    } );
    return {
        node: wrapper,
        dispose: () => { disposed = true; close(); },
    };
}
