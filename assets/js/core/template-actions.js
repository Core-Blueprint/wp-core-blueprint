/**
 * TL-M2: one Base-owned, provider-authorized card actions disclosure for
 * Template Library and the contextual Designer Browser.
 * No template persistence, Apply, or ownership authority lives here.
 */
import { show as showModal } from '@cb-core/modal';
import { create as createIcon } from '@cb-core/icon';

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
    // Never navigate away from an active Designer; its Exit Guard owns transitions.
    if ( !globalMode || !item.navigation || typeof item.navigation !== 'object' ) return [];
    const entries = [];
    for ( const kind of [ 'preview', 'manage' ] ) {
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

// ZIP delivery is server-generated via one authenticated POST; no public
// download URL, local filename, provider callback or packaged bytes in JS.
// A download request is NOT proof that a browser accepted the file.
function requestResourceZip( item, options ) {
    if ( !options.globalMode || !options.isCurrent() ||
        item.resourcePackageExport !== true || typeof options.nonce !== 'string' ||
        !options.nonce || typeof options.downloadRoot !== 'string' ) return false;
    let target;
    try { target = new URL( options.downloadRoot, window.location.href ); }
    catch { return false; }
    if ( target.origin !== window.location.origin || target.username || target.password ||
        target.search || target.hash || !target.pathname.endsWith( '/admin-post.php' ) ||
        !/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/.test( item.source ) ||
        !/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/.test( item.id ) ||
        !/^[1-9][0-9]*(?:\.[0-9]+){0,2}$/.test( item.version ) ) return false;
    const form = el( 'form' );
    form.method = 'POST';
    form.action = target.href;
    form.target = '_blank';
    form.rel = 'noopener noreferrer';
    form.hidden = true;
    for ( const [ name, value ] of Object.entries( {
        action: 'cb_core_template_resource_export',
        _wpnonce: options.nonce,
        source: item.source, templateId: item.id, version: item.version,
    } ) ) {
        const field = el( 'input' );
        field.type = 'hidden';
        field.name = name;
        field.value = value;
        form.append( field );
    }
    document.body.append( form );
    try {
        form.submit();
        return true;
    } catch {
        return false;
    } finally {
        form.remove();
    }
}

export function createActionMenu( item, options ) {
    const revision = validRevision( item.version );
    const declared = Array.isArray( item.actions ) ? item.actions : [];
    const resourceZip = options.globalMode && item.resourcePackageExport === true
        && typeof item.version === 'string'
        && /^[1-9][0-9]*(?:\.[0-9]+){0,2}$/.test( item.version );
    const operations = [
        ...( revision === null ? [] : OPERATIONS.filter( id => declared.includes( id ) ) ),
        ...( resourceZip ? [ 'export-zip' ] : [] ),
    ];
    const navigation = allowedNavigation( item, options.globalMode );
    if ( !operations.length && !navigation.length ) return null;

    const label = options.label;
    const wrapper = el( 'div', 'cb-template-browser__action-menu' );
    const trigger = el( 'button', 'cb-template-browser__action-trigger' );
    trigger.type = 'button';
    trigger.title = label( 'actions', 'Actions' );
    trigger.setAttribute( 'aria-label', label( 'actions', 'Actions' ) + ': ' + item.title );
    trigger.setAttribute( 'aria-haspopup', 'menu' );
    trigger.setAttribute( 'aria-expanded', 'false' );
    const icon = createIcon( 'ellipsis', { size: 'default' } );
    if ( icon ) trigger.append( icon );
    const panel = el( 'div', 'cb-template-browser__action-panel' );
    panel.id = 'cb-template-actions-' + ++sequence;
    panel.hidden = true;
    panel.setAttribute( 'role', 'menu' );
    panel.setAttribute( 'aria-label', label( 'actions', 'Actions' ) + ': ' + item.title );
    trigger.setAttribute( 'aria-controls', panel.id );
    wrapper.append( trigger, panel );
    let busy = false;
    let expanded = false;
    let disposed = false;
    const hasNativePopover = typeof panel.showPopover === 'function'
        && typeof panel.hidePopover === 'function';
    if ( hasNativePopover ) panel.setAttribute( 'popover', 'manual' );
    const originalHost = wrapper;
    const menuItems = () => Array.from( panel.querySelectorAll( '[role="menuitem"]' ) );

    // The shared native Popover API escapes the clipped Designer card/viewport.
    // On older engines, portal the fixed panel to the dialog (or document body)
    // instead of ever expanding the card. The provider action API is untouched.
    const viewport = () => {
        const visual = window.visualViewport;
        const left = visual?.offsetLeft || 0;
        const top = visual?.offsetTop || 0;
        const width = visual?.width || window.innerWidth;
        const height = visual?.height || window.innerHeight;
        const dialog = wrapper.closest?.( 'dialog' );
        const rect = dialog?.getBoundingClientRect?.();
        return {
            left: Math.max( left + 8, ( rect?.left ?? left ) + 8 ),
            top: Math.max( top + 8, ( rect?.top ?? top ) + 8 ),
            right: Math.min( left + width - 8, ( rect?.right ?? left + width ) - 8 ),
            bottom: Math.min( top + height - 8, ( rect?.bottom ?? top + height ) - 8 ),
        };
    };
    const locate = () => {
        if ( !expanded || !trigger.isConnected || !options.isCurrent() ) {
            close();
            return;
        }
        if ( typeof trigger.getBoundingClientRect !== 'function' ) return;
        const anchor = trigger.getBoundingClientRect();
        const edges = viewport();
        const scroller = wrapper.closest?.( '.cb-template-browser__results-viewport' );
        const frame = scroller?.getBoundingClientRect?.();
        const visibleTop = Math.max( edges.top, frame?.top ?? edges.top );
        const visibleBottom = Math.min( edges.bottom, frame?.bottom ?? edges.bottom );
        if ( anchor.bottom <= visibleTop || anchor.top >= visibleBottom
            || anchor.right <= edges.left || anchor.left >= edges.right
            || edges.right - edges.left < 56 || edges.bottom - edges.top < 56 ) {
            close();
            return;
        }
        const width = Math.min( 220, edges.right - edges.left );
        panel.style.width = width + 'px';
        const height = panel.scrollHeight || panel.getBoundingClientRect().height;
        const below = Math.max( 0, edges.bottom - anchor.bottom - 6 );
        const above = Math.max( 0, anchor.top - edges.top - 6 );
        const placeAbove = above > below && below < Math.min( height, 160 );
        const available = placeAbove ? above : below;
        panel.style.maxHeight = Math.max( 40, available ) + 'px';
        const shownHeight = Math.min( height, Math.max( 40, available ) );
        const top = placeAbove ? anchor.top - 6 - shownHeight : anchor.bottom + 6;
        const left = wrapper.closest?.( '[dir="rtl"]' )
            ? anchor.left : anchor.right - width;
        panel.style.left = Math.max( edges.left, Math.min( left, edges.right - width ) ) + 'px';
        panel.style.top = Math.max( edges.top, Math.min( top, edges.bottom - shownHeight ) ) + 'px';
    };
    const outside = event => {
        if ( !wrapper.contains( event.target ) && !panel.contains( event.target ) ) close();
    };
    const onViewportChange = () => locate();
    const onKeydown = event => {
        if ( event.key === 'Escape' && expanded ) {
            event.preventDefault();
            event.stopPropagation();
            close( true );
        }
    };
    const close = ( restoreFocus = false ) => {
        if ( !expanded ) return;
        expanded = false;
        trigger.setAttribute( 'aria-expanded', 'false' );
        if ( hasNativePopover && panel.matches?.( ':popover-open' ) ) {
            panel.hidePopover();
        }
        panel.hidden = true;
        if ( !hasNativePopover ) originalHost.append( panel );
        document.removeEventListener( 'pointerdown', outside, true );
        document.removeEventListener( 'focusin', outside, true );
        document.removeEventListener( 'keydown', onKeydown, true );
        window.removeEventListener?.( 'resize', onViewportChange );
        window.removeEventListener?.( 'scroll', onViewportChange, true );
        window.visualViewport?.removeEventListener?.( 'resize', onViewportChange );
        window.visualViewport?.removeEventListener?.( 'scroll', onViewportChange );
        if ( restoreFocus && trigger.isConnected ) trigger.focus();
    };
    const open = ( focusFirst = false ) => {
        if ( expanded || busy || disposed || !options.isCurrent() ) return;
        options.onOpening( close );
        if ( !hasNativePopover ) {
            const host = wrapper.closest?.( 'dialog' ) || document.body;
            host.append( panel );
        }
        panel.hidden = false;
        try {
            if ( hasNativePopover ) panel.showPopover();
        } catch {
            panel.hidden = true;
            if ( !hasNativePopover ) originalHost.append( panel );
            return; // No in-flow fallback or accidental card expansion.
        }
        expanded = true;
        trigger.setAttribute( 'aria-expanded', 'true' );
        locate();
        if ( !expanded ) return; // A hidden/offscreen card must not register stale listeners.
        document.addEventListener( 'pointerdown', outside, true );
        document.addEventListener( 'focusin', outside, true );
        document.addEventListener( 'keydown', onKeydown, true );
        window.addEventListener?.( 'resize', onViewportChange, { passive: true } );
        window.addEventListener?.( 'scroll', onViewportChange, { capture: true, passive: true } );
        window.visualViewport?.addEventListener?.( 'resize', onViewportChange );
        window.visualViewport?.addEventListener?.( 'scroll', onViewportChange );
        if ( focusFirst ) menuItems()[ 0 ]?.focus();
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
        close( true );
        if ( id === 'export-zip' ) {
            // The request opens a separate download response, not a mutation
            // in the active Designer and not a confirmed browser download.
            if ( !requestResourceZip( item, options ) ) {
                options.onNotice( label( 'actionFailed', 'The action could not be confirmed. Check the template before retrying.' ) );
            }
            return;
        }
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
        link.setAttribute( 'role', 'menuitem' );
        link.addEventListener( 'click', () => close() );
        panel.append( link );
    }
    for ( const id of operations ) {
        const titles = {
            rename: [ 'rename', 'Rename' ], duplicate: [ 'duplicate', 'Duplicate' ],
            'export-json': [ 'exportJson', 'Export JSON' ],
            'export-zip': [ 'exportZip', 'Export ZIP' ], delete: [ 'delete', 'Delete' ],
        };
        const button = el( 'button', 'cb-template-browser__action-item' +
            ( id === 'delete' ? ' cb-template-browser__action-item--danger' : '' ),
            label( ...titles[id] ) );
        button.type = 'button';
        button.setAttribute( 'role', 'menuitem' );
        button.addEventListener( 'click', () => { if ( !busy ) void action( id ); } );
        panel.append( button );
    }
    trigger.addEventListener( 'click', () => expanded ? close() : open() );
    trigger.addEventListener( 'keydown', event => {
        if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
            event.preventDefault();
            if ( !expanded ) {
                open();
                if ( expanded ) menuItems()[ event.key === 'ArrowUp' ? menuItems().length - 1 : 0 ]?.focus();
            } else {
                menuItems()[ event.key === 'ArrowUp' ? menuItems().length - 1 : 0 ]?.focus();
            }
        }
    } );
    panel.addEventListener( 'keydown', event => {
        const items = menuItems();
        const current = items.indexOf( document.activeElement );
        let next = null;
        if ( event.key === 'ArrowDown' ) next = ( current + 1 ) % items.length;
        else if ( event.key === 'ArrowUp' ) next = ( current + items.length - 1 ) % items.length;
        else if ( event.key === 'Home' ) next = 0;
        else if ( event.key === 'End' ) next = items.length - 1;
        if ( next === null || !items.length ) return;
        event.preventDefault();
        items[ next ]?.focus();
    } );
    return {
        node: wrapper,
        dispose: () => { disposed = true; close(); },
    };
}
