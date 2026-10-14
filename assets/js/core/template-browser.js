/**
 * Shared Template Library browser, metadata-only and offline-first.
 *
 * @cb-core/template-browser is the only supported extension import.
 * Contextual browsing takes a server-issued DesignerScope token, NEVER a
 * caller-editable extension/type string. Applying a selected record remains
 * the consumer's own separately authorized responsibility.
 */
import { show as showModal } from '@cb-core/modal';

const dataElement = typeof document !== 'undefined'
    ? document.getElementById( 'wp-script-module-data-@cb-core/template-browser' ) : null;
let settings = {};
try { settings = dataElement ? JSON.parse( dataElement.textContent ) : {}; } catch { settings = {}; }
const labels = settings.i18n || {};
const label = ( key, fallback ) => labels[key] || fallback;

function el( tag, className = '', text = null ) {
    const node = document.createElement( tag );
    if ( className ) node.className = className;
    if ( text !== null ) node.textContent = text;
    return node;
}
function option( value, title ) {
    const item = el( 'option', '', title );
    item.value = value;
    return item;
}
function safeError( message ) {
    return new Error( message || label( 'error', 'Could not load templates.' ) );
}
function queryString( filters, cursor = '' ) {
    const query = new URLSearchParams();
    Object.entries( filters ).forEach( ( [ key, value ] ) => {
        if ( value !== '' && value !== null && value !== undefined ) query.set( key, String( value ) );
    } );
    if ( cursor ) query.set( 'cursor', cursor );
    return query.toString();
}
async function request( route, filters, contextToken, signal ) {
    if ( !settings.restRoot || !settings.nonce ) throw safeError();
    const headers = { 'X-WP-Nonce': settings.nonce };
    if ( contextToken ) headers['X-CB-Designer-Scope'] = contextToken;
    const response = await fetch( settings.restRoot + route + '?' + queryString( filters ), {
        method: 'GET', credentials: 'same-origin', headers, signal,
    } );
    if ( !response.ok ) throw safeError();
    const result = await response.json();
    if ( !result || typeof result !== 'object' ) throw safeError();
    return result;
}

/** A metadata browser. Client callbacks never grant template execution authority. */
function createBrowser( root, options = {} ) {
    if ( !( root instanceof HTMLElement ) ) throw new TypeError( 'Template browser root is required.' );
    const contextual = options.mode === 'designer';
    if ( contextual && ( typeof options.contextToken !== 'string' || !options.contextToken ) ) {
        throw new TypeError( 'A server-issued Designer scope token is required.' );
    }
    const token = contextual ? options.contextToken : '';
    const state = {
        filters: { limit: 24, search: '', source: '', extension: '', designer: '', origin: '', category: '' },
        cursor: null, entries: [], selected: null, pending: false,
        requestGeneration: 0, controller: null, timer: null, destroyed: false,
    };
    const shell = el( 'section', 'cb-template-browser' );
    const sidebar = el( 'nav', 'cb-template-browser__sidebar' );
    sidebar.setAttribute( 'aria-label', label( 'origin', 'Origin' ) );
    const content = el( 'div', 'cb-template-browser__content' );
    const toolbar = el( 'div', 'cb-template-browser__toolbar' );
    const results = el( 'div', 'cb-template-browser__grid' );
    const message = el( 'p', 'cb-template-browser__message' );
    message.setAttribute( 'role', 'status' );
    message.setAttribute( 'aria-live', 'polite' );
    const footer = el( 'div', 'cb-template-browser__footer' );
    const more = el( 'button', 'button cb-template-browser__more', label( 'loadMore', 'Load more' ) );
    more.type = 'button';
    more.hidden = true;
    const summary = el( 'span', 'cb-template-browser__summary' );
    footer.append( summary, more );

    const origins = [
        [ '', 'all', 'All templates' ],
        [ 'built-in', 'builtin', 'Built-in' ],
        [ 'personal', 'personal', 'My templates' ],
        [ 'site', 'site', 'Site templates' ],
        [ 'installed-pack', 'packs', 'Installed packs' ],
    ];
    const originButtons = origins.map( ( [ value, name, fallback ] ) => {
        const button = el( 'button', 'cb-template-browser__origin', label( name, fallback ) );
        button.type = 'button';
        button.dataset.origin = value;
        button.addEventListener( 'click', () => {
            state.filters.origin = value;
            originButtons.forEach( ( item ) => item.setAttribute( 'aria-current', String( item === button ) ) );
            refresh();
        } );
        sidebar.append( button );
        return button;
    } );
    originButtons[0].setAttribute( 'aria-current', 'true' );

    const search = el( 'input', 'cb-template-browser__search' );
    search.type = 'search';
    search.placeholder = label( 'search', 'Search templates' );
    search.setAttribute( 'aria-label', label( 'search', 'Search templates' ) );
    search.maxLength = 120;

    function addSelect( name, translationKey, fallback, choices = [] ) {
        const wrapper = el( 'label', 'cb-template-browser__field' );
        wrapper.append( el( 'span', '', label( translationKey, fallback ) ) );
        const select = el( 'select' );
        select.dataset.filter = name;
        select.append( option( '', label( 'all', 'All templates' ) ) );
        choices.forEach( ( [ value, title ] ) => select.append( option( value, title ) ) );
        select.addEventListener( 'change', () => {
            state.filters[name] = select.value;
            refresh();
        } );
        wrapper.append( select );
        toolbar.append( wrapper );
        return select;
    }
    const sourceSelect = contextual ? null : addSelect( 'source', 'source', 'Source' );
    const extensionSelect = contextual ? null : addSelect( 'extension', 'source', 'Extension' );
    const designerSelect = contextual ? null : addSelect( 'designer', 'designer', 'Designer type' );
    const categoryInput = el( 'input', 'cb-template-browser__category' );
    categoryInput.type = 'text';
    categoryInput.maxLength = 96;
    categoryInput.placeholder = label( 'category', 'Category' );
    categoryInput.setAttribute( 'aria-label', label( 'category', 'Category' ) );
    const categoryField = el( 'label', 'cb-template-browser__field' );
    categoryField.append( el( 'span', '', label( 'category', 'Category' ) ), categoryInput );

    search.addEventListener( 'input', () => {
        clearTimeout( state.timer );
        state.timer = setTimeout( () => { state.filters.search = search.value.trim(); refresh(); }, 250 );
    } );
    categoryInput.addEventListener( 'change', () => {
        state.filters.category = categoryInput.value.trim();
        refresh();
    } );
    const searchField = el( 'label', 'cb-template-browser__field cb-template-browser__field--search' );
    searchField.append( el( 'span', '', label( 'search', 'Search templates' ) ), search );
    toolbar.prepend( searchField );
    toolbar.append( categoryField );
    content.append( toolbar, message, results, footer );
    shell.append( sidebar, content );
    root.replaceChildren( shell );

    function renderCard( item, index ) {
        const article = el( contextual ? 'label' : 'article', 'cb-template-browser__card' );
        if ( contextual ) {
            const radio = el( 'input', 'cb-template-browser__choice' );
            radio.type = 'radio';
            radio.name = 'cb-template-choice';
            radio.value = String( index );
            radio.checked = state.selected === index;
            radio.disabled = !item.compatible;
            radio.addEventListener( 'change', () => { if ( radio.checked ) state.selected = index; } );
            article.append( radio );
        }
        const body = el( 'div', 'cb-template-browser__card-body' );
        body.append( el( 'h3', 'cb-template-browser__card-title', item.title ) );
        if ( item.description ) body.append( el( 'p', 'cb-template-browser__card-description', item.description ) );
        const meta = el( 'div', 'cb-template-browser__card-meta' );
        meta.append(
            el( 'span', '', item.origin ),
            el( 'span', '', item.category ),
            el( 'span', '', item.designer )
        );
        body.append( meta );
        const stateBadge = el( 'span',
            item.compatible ? 'cb-template-browser__status' : 'cb-template-browser__status cb-template-browser__status--blocked',
            item.compatible ? label( 'compatible', 'Compatible' ) : label( 'incompatible', 'Not compatible' )
        );
        body.append( stateBadge );
        article.append( body );
        return article;
    }

    async function load( reset = false ) {
        if ( state.destroyed ) return;
        if ( reset ) {
            state.controller?.abort();
            state.entries = [];
            state.selected = null;
            state.cursor = null;
            results.replaceChildren();
        }
        if ( state.pending && !reset ) return;
        state.pending = true;
        const generation = ++state.requestGeneration;
        const controller = new AbortController();
        state.controller = controller;
        message.textContent = label( 'loading', 'Loading templates…' );
        more.hidden = true;
        try {
            const payload = await request( 'templates', state.filters, token, controller.signal );
            if ( state.destroyed || generation !== state.requestGeneration ) return;
            if ( !Array.isArray( payload.items ) || ( payload.next_cursor !== null && typeof payload.next_cursor !== 'string' ) ) {
                throw safeError();
            }
            payload.items.forEach( ( item ) => {
                if ( !item || typeof item.title !== 'string' || typeof item.source !== 'string' || typeof item.id !== 'string' ) return;
                const index = state.entries.push( item ) - 1;
                results.append( renderCard( item, index ) );
            } );
            state.cursor = payload.next_cursor;
            message.textContent = state.entries.length ? '' : label( 'noResults', 'No templates found.' );
            summary.textContent = String( state.entries.length );
            more.hidden = !state.cursor;
        } catch ( error ) {
            if ( !controller.signal.aborted && !state.destroyed && generation === state.requestGeneration ) {
                message.textContent = label( 'error', 'Could not load templates.' );
            }
        } finally {
            if ( generation === state.requestGeneration ) state.pending = false;
        }
    }
    function refresh() { return load( true ); }
    more.addEventListener( 'click', () => { if ( state.cursor ) load(); } );

    if ( !contextual ) {
        request( 'sources', {}, '', undefined ).then( ( data ) => {
            if ( state.destroyed || !Array.isArray( data.sources ) ) return;
            const sources = data.sources;
            const fill = ( select, items ) => {
                if ( !select ) return;
                items.forEach( ( item ) => select.append( option( item, item ) ) );
            };
            fill( sourceSelect, [ ...new Set( sources.map( ( item ) => item.id ).filter( Boolean ) ) ] );
            fill( extensionSelect, [ ...new Set( sources.map( ( item ) => item.extension ).filter( Boolean ) ) ] );
            fill( designerSelect, [ ...new Set( sources.map( ( item ) => item.designer ).filter( Boolean ) ) ] );
        } ).catch( () => {} );
    }

    refresh();
    return {
        selected: () => state.selected === null ? null : ( state.entries[state.selected] || null ),
        destroy: () => {
            state.destroyed = true;
            state.controller?.abort();
            clearTimeout( state.timer );
            root.replaceChildren();
        },
    };
}

/**
 * Opens a strict Designer-only workspace. The consumer must separately verify
 * selected template access, owner permissions and disabled draft semantics.
 */
async function open( { contextToken, onChoose, title } = {} ) {
    if ( typeof contextToken !== 'string' || !contextToken || typeof onChoose !== 'function' ) {
        throw new TypeError( 'Designer token and onChoose callback are required.' );
    }
    const body = el( 'div', 'cb-template-browser__modal-body' );
    const notice = el( 'p', 'cb-template-browser__selection-notice' );
    notice.setAttribute( 'role', 'status' );
    body.append( notice );
    const root = el( 'div' );
    body.append( root );
    const browser = createBrowser( root, { mode: 'designer', contextToken } );
    try {
        return await showModal( {
            title: title || label( 'browse', 'Browse templates' ),
            body,
            size: 'workspace',
            expandable: true,
            confirmLabel: label( 'use', 'Use template' ),
            cancelLabel: label( 'close', 'Close' ),
            onConfirm: async () => {
                const selected = browser.selected();
                if ( !selected || !selected.compatible ) {
                    notice.textContent = label( 'select', 'Select a template.' );
                    return false;
                }
                const accepted = await onChoose( { ...selected } );
                return accepted !== false;
            },
        } );
    } finally {
        browser.destroy();
    }
}

const api = Object.freeze( { createBrowser, open } );
if ( typeof window !== 'undefined' ) {
    window.cbCore = window.cbCore || {};
    window.cbCore.templateBrowser = api;
    const root = document.querySelector( '[data-cb-template-library-global]' );
    if ( root ) createBrowser( root, { mode: 'global' } );
}
export { createBrowser, open };
export default api;
