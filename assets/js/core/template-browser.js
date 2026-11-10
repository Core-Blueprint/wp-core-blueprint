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
async function request( route, filters, contextToken, signal, cursor = '' ) {
    if ( !settings.restRoot || !settings.nonce ) throw safeError();
    const headers = { 'X-WP-Nonce': settings.nonce };
    if ( contextToken ) headers['X-CB-Designer-Scope'] = contextToken;
    const response = await fetch( settings.restRoot + route + '?' + queryString( filters, cursor ), {
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
    // Active designs come from the consumer's already-authorized picker.
    // Metadata only; these IDs do not authorize a document write or Apply.
    const designs = contextual && Array.isArray( options.designs )
        ? options.designs.filter( ( item ) => item && typeof item.id === 'string'
            && item.id && typeof item.title === 'string' && item.title
            && typeof item.provider === 'string' )
            .map( ( item ) => ( { kind: 'design', id: item.id,
                title: item.title, provider: item.provider } ) ) : [];
    const hasDesigns = designs.length > 0;
    if ( contextual && ( typeof options.contextToken !== 'string' || !options.contextToken ) ) {
        throw new TypeError( 'A server-issued Designer scope token is required.' );
    }
    const token = contextual ? options.contextToken : '';
    const state = {
        filters: { limit: 24, search: '', source: '', extension: '', designer: '', origin: '', category: '' },
        sources: [], cursor: null, entries: [], selected: null, pending: false,
        requestGeneration: 0, controller: null, timer: null, destroyed: false,
        view: hasDesigns ? 'designs' : 'templates', designProvider: '',
    };
    const shell = el( 'section', [ 'cb-template-browser', contextual ? 'cb-template-browser--modal' : '',
        hasDesigns ? 'cb-template-browser--designer-workspace' : '' ].filter( Boolean ).join( ' ' ) );
    const sidebar = el( 'div', 'cb-template-browser__sidebar' );
    sidebar.setAttribute( 'role', 'group' );
    sidebar.setAttribute( 'aria-label', label( 'origin', 'Origin' ) );
    const collections = hasDesigns ? el( 'div', 'cb-template-browser__collections' ) : null;
    if ( collections ) {
        collections.setAttribute( 'role', 'group' );
        collections.setAttribute( 'aria-label', label( 'folders', 'Collections' ) );
    }
    const content = el( 'div', 'cb-template-browser__content' );
    const toolbar = el( 'div', 'cb-template-browser__toolbar' );
    const results = el( 'div', 'cb-template-browser__grid' );
    // Contextual results scroll independently; the card grid keeps intrinsic row sizing.
    const resultsViewport = contextual ? el( 'div', 'cb-template-browser__results-viewport cb-scrollbar' ) : null;
    if ( resultsViewport ) {
        resultsViewport.setAttribute( 'role', 'region' );
        resultsViewport.setAttribute( 'aria-label', label( 'browse', 'Browse templates' ) );
        resultsViewport.setAttribute( 'tabindex', '0' );
        resultsViewport.append( results );
    }
    const message = el( 'p', 'cb-template-browser__message' );
    message.setAttribute( 'role', 'status' );
    message.setAttribute( 'aria-live', 'polite' );
    const footer = el( 'div', 'cb-template-browser__footer' );
    const more = el( 'button', 'button cb-template-browser__more', label( 'loadMore', 'Load more' ) );
    more.type = 'button';
    more.hidden = true;
    const summary = el( 'span', 'cb-template-browser__summary' );
    footer.append( summary, more );

    const designButton = hasDesigns ? el( 'button', 'cb-template-browser__origin cb-template-browser__origin--designs',
        label( 'designs', 'Designs' ) ) : null;
    let templateButton = null;
    if ( designButton ) {
        designButton.type = 'button';
        designButton.dataset.browserView = 'designs';
        designButton.addEventListener( 'click', () => {
            state.view = 'designs';
            syncNavigation();
            refresh();
        } );
        sidebar.append( designButton );
        templateButton = el( 'button', 'cb-template-browser__origin cb-template-browser__origin--templates',
            label( 'templates', 'Templates' ) );
        templateButton.type = 'button';
        templateButton.dataset.browserView = 'templates';
        templateButton.addEventListener( 'click', () => {
            state.view = 'templates';
            syncNavigation();
            refresh();
        } );
        sidebar.append( templateButton );
    }
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
            state.view = 'templates';
            state.filters.origin = value;
            updateCategoryOptions();
            syncNavigation();
            refresh();
        } );
        ( collections || sidebar ).append( button );
        return button;
    } );
    function syncNavigation() {
        if ( designButton ) {
            designButton.setAttribute( 'aria-pressed', String( state.view === 'designs' ) );
            templateButton?.setAttribute( 'aria-pressed', String( state.view === 'templates' ) );
        }
        originButtons.forEach( ( item ) => item.setAttribute( 'aria-pressed',
            String( state.view === 'templates' && item.dataset.origin === state.filters.origin ) ) );
        if ( !collections ) return;
        collections.replaceChildren( el( 'h3', 'cb-template-browser__side-heading',
            label( 'folders', 'Collections' ) ) );
        if ( state.view === 'templates' ) {
            originButtons.forEach( ( button ) => collections.append( button ) );
        } else {
            const providers = [ ...new Set( designs.map( ( item ) => item.provider ).filter( Boolean ) ) ];
            const choices = [ [ '', label( 'allDesigns', 'All designs' ) ],
                ...providers.map( ( name ) => [ name, name ] ) ];
            for ( const [ value, name ] of choices ) {
                const button = el( 'button', 'cb-template-browser__origin', name );
                button.type = 'button';
                button.setAttribute( 'aria-pressed', String( value === state.designProvider ) );
                button.addEventListener( 'click', () => {
                    state.designProvider = value;
                    syncNavigation();
                    refresh();
                } );
                collections.append( button );
            }
        }
    }
    syncNavigation();

    const search = el( 'input', 'cb-template-browser__search' );
    search.type = 'search';
    const searchLabel = hasDesigns ? label( 'searchWorkspace', 'Search designs and templates' )
        : label( 'search', 'Search templates' );
    search.placeholder = searchLabel;
    search.setAttribute( 'aria-label', searchLabel );
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
            if ( name !== 'category' ) updateCategoryOptions();
            refresh();
        } );
        wrapper.append( select );
        toolbar.append( wrapper );
        return select;
    }
    const sourceSelect = contextual ? null : addSelect( 'source', 'source', 'Source' );
    const extensionSelect = contextual ? null : addSelect( 'extension', 'plugin', 'Plugin' );
    const designerSelect = contextual ? null : addSelect( 'designer', 'designer', 'Designer type' );
    const categorySelect = addSelect( 'category', 'category', 'Category' );
    categorySelect.disabled = true;

    // Provider-declared facets avoid missing categories on later result pages.
    function updateCategoryOptions() {
        const available = new Map();
        for ( const source of state.sources ) {
            if ( state.filters.source && source.id !== state.filters.source ) continue;
            if ( state.filters.extension && source.extension !== state.filters.extension ) continue;
            if ( state.filters.designer && source.designer !== state.filters.designer ) continue;
            if ( !Array.isArray( source.categories ) ) continue;
            for ( const category of source.categories ) {
                if ( !category || typeof category.id !== 'string' || typeof category.label !== 'string'
                    || !/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/.test( category.id ) ) continue;
                if ( !available.has( category.id ) ) available.set( category.id, category.label );
            }
        }
        categorySelect.replaceChildren( option( '', label( 'all', 'All templates' ) ) );
        [ ...available.entries() ].sort( ( a, b ) => a[1].localeCompare( b[1] ) )
            .forEach( ( [ id, title ] ) => categorySelect.append( option( id, title ) ) );
        categorySelect.disabled = available.size === 0;
        if ( state.filters.category && !available.has( state.filters.category ) ) {
            // Callers perform one refresh after changing a filter. Avoid
            // a redundant request when the selected category is no longer valid.
            state.filters.category = '';
        }
        categorySelect.value = state.filters.category;
    }

    search.addEventListener( 'input', () => {
        clearTimeout( state.timer );
        state.timer = setTimeout( () => { state.filters.search = search.value.trim(); refresh(); }, 250 );
    } );
    const searchField = el( 'label', 'cb-template-browser__field cb-template-browser__field--search' );
    searchField.append( el( 'span', '', searchLabel ), search );
    toolbar.prepend( searchField );
    content.append( toolbar, message, resultsViewport || results, footer );
    if ( collections ) shell.append( sidebar, collections, content );
    else shell.append( sidebar, content );
    root.replaceChildren( shell );

    function renderDesigns() {
        results.replaceChildren();
        state.entries = [];
        state.selected = null;
        state.cursor = null;
        state.controller?.abort();
        ++state.requestGeneration;
        state.pending = false;
        const needle = state.filters.search.toLocaleLowerCase();
        for ( const item of designs ) {
            if ( state.designProvider && item.provider !== state.designProvider ) continue;
            if ( needle && !`${item.title} ${item.provider}`.toLocaleLowerCase().includes( needle ) ) continue;
            const index = state.entries.push( item ) - 1;
            const card = el( 'label', 'cb-template-browser__card cb-template-browser__card--design' );
            const radio = el( 'input', 'cb-template-browser__choice' );
            radio.type = 'radio';
            radio.name = 'cb-template-choice';
            radio.value = String( index );
            radio.checked = false;
            radio.addEventListener( 'change', () => { if ( radio.checked ) state.selected = index; } );
            const preview = el( 'div', 'cb-template-browser__card-preview' );
            preview.setAttribute( 'aria-hidden', 'true' );
            const body = el( 'div', 'cb-template-browser__card-body' );
            body.append( el( 'h3', 'cb-template-browser__card-title', item.title ) );
            const meta = el( 'div', 'cb-template-browser__card-meta' );
            if ( item.provider ) meta.append( el( 'span', '', item.provider ) );
            if ( item.id === options.activeDesignId ) {
                card.classList.add( 'is-current' );
                meta.append( el( 'span', 'cb-template-browser__current',
                    label( 'current', 'Current' ) ) );
            }
            body.append( meta );
            card.append( radio, preview, body );
            results.append( card );
        }
        message.textContent = state.entries.length ? '' : label( 'noDesigns', 'No designs found.' );
        summary.textContent = String( state.entries.length );
        more.hidden = true;
    }

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
        if ( contextual ) {
            const preview = el( 'div', 'cb-template-browser__card-preview' );
            preview.setAttribute( 'aria-hidden', 'true' );
            article.append( preview );
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
        if ( contextual || !item.compatible ) body.append( stateBadge );
        article.append( body );
        // T3: management is navigation to the owning extension, never a
        // Base-owned write/export/delete request. Contextual Designer selection
        // remains a single radio interaction without nested interactive links.
        if ( !contextual && item.navigation && typeof item.navigation === 'object' ) {
            const actions = el( 'div', 'cb-template-browser__actions' );
            for ( const kind of [ 'preview', 'manage' ] ) {
                const entry = item.navigation[kind];
                if ( !entry || typeof entry.url !== 'string' || typeof entry.label !== 'string'
                    || !entry.label.trim() ) continue;
                try {
                    const destination = new URL( entry.url, window.location.href );
                    if ( ![ 'http:', 'https:' ].includes( destination.protocol )
                        || destination.origin !== window.location.origin
                        || !destination.pathname.includes( '/wp-admin/' ) ) continue;
                    const link = el( 'a', 'button cb-template-browser__action', entry.label );
                    link.href = destination.href;
                    actions.append( link );
                } catch {
                    // Malformed provider navigation fails closed.
                }
            }
            if ( actions.childElementCount ) article.append( actions );
        }
        return article;
    }

    async function load( reset = false ) {
        if ( state.destroyed ) return;
        if ( categorySelect.parentElement ) categorySelect.parentElement.hidden = state.view === 'designs';
        if ( state.view === 'designs' ) {
            renderDesigns();
            return;
        }
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
            const payload = await request( 'templates', state.filters, token, controller.signal, state.cursor || '' );
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
            message.textContent = ( state.entries.length || state.cursor ) ? '' : label( 'noResults', 'No templates found.' );
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

    request( 'sources', {}, token, undefined ).then( ( data ) => {
        if ( state.destroyed || !Array.isArray( data.sources ) ) return;
        state.sources = data.sources;
        if ( !contextual ) {
            const fill = ( select, items ) => {
                if ( !select ) return;
                items.forEach( ( item ) => select.append( option( item, item ) ) );
            };
            fill( sourceSelect, [ ...new Set( state.sources.map( ( item ) => item.id ).filter( Boolean ) ) ] );
            fill( extensionSelect, [ ...new Set( state.sources.map( ( item ) => item.extension ).filter( Boolean ) ) ] );
            fill( designerSelect, [ ...new Set( state.sources.map( ( item ) => item.designer ).filter( Boolean ) ) ] );
        }
        updateCategoryOptions();
    } ).catch( () => {} );

    refresh();
    return {
        selected: () => state.selected === null ? null : ( state.entries[state.selected] || null ),
        view: () => state.view,
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
async function open( { contextToken, onChoose, title, designs = [], activeDesignId = '',
    onOpenDesign = null, onSaveAs = null, saveAsLabel = '' } = {} ) {
    if ( typeof contextToken !== 'string' || !contextToken || typeof onChoose !== 'function' ) {
        throw new TypeError( 'Designer token and onChoose callback are required.' );
    }
    const body = el( 'div', 'cb-template-browser__modal-body' );
    const notice = el( 'p', 'cb-template-browser__selection-notice' );
    notice.setAttribute( 'role', 'status' );
    body.append( notice );
    const root = el( 'div', 'cb-template-browser__mount' );
    body.append( root );
    const browser = createBrowser( root, {
        mode: 'designer', contextToken,
        designs: typeof onOpenDesign === 'function' ? designs : [],
        activeDesignId,
    } );
    let pendingDesign = null;
    if ( typeof onSaveAs === 'function' ) {
        const saveAction = el( 'button', 'button cb-template-browser__save-as',
            saveAsLabel || label( 'saveAs', 'Save as template' ) );
        saveAction.type = 'button';
        saveAction.addEventListener( 'click', async () => {
            if ( saveAction.disabled ) return;
            saveAction.disabled = true;
            try { await onSaveAs(); }
            catch { notice.textContent = label( 'error', 'Could not load templates.' ); }
            finally { saveAction.disabled = false; }
        } );
        body.append( saveAction );
    }
    try {
        const accepted = await showModal( {
            title: title || label( 'browse', 'Browse templates' ),
            body,
            size: 'fullscreen',
            confirmLabel: typeof onOpenDesign === 'function' && Array.isArray( designs ) && designs.length
                ? label( 'selectAction', 'Open / use selected' ) : label( 'use', 'Use template' ),
            cancelLabel: label( 'close', 'Close' ),
            onConfirm: async () => {
                const selected = browser.selected();
                if ( !selected || ( selected.kind !== 'design' && !selected.compatible ) ) {
                    notice.textContent = browser.view() === 'designs'
                        ? label( 'selectDesign', 'Select a design.' ) : label( 'select', 'Select a template.' );
                    return false;
                }
                if ( selected.kind === 'design' ) {
                    pendingDesign = { ...selected };
                    return true;
                }
                const chosen = await onChoose( { ...selected } );
                return chosen !== false;
            },
        } );
        // Only navigate after the Browser dialog has closed. The consumer's
        // existing Exit Guard decides Save/Discard for the current document.
        if ( accepted && pendingDesign && typeof onOpenDesign === 'function' ) {
            return await onOpenDesign( pendingDesign );
        }
        return accepted;
    } finally {
        browser.destroy();
    }
}

/**
 * Read-only, scope-bound materialization. The response must go through the
 * Host's captureApplyIntent()/applyTemplate(); never replace a project here.
 */
async function loadForApply( { contextToken, source, templateId, target, signal } = {} ) {
    if ( !contextToken || typeof contextToken !== 'string'
        || typeof source !== 'string' || typeof templateId !== 'string'
        || !target || typeof target !== 'object'
        || !Number.isSafeInteger( target.contextEpoch ) || target.contextEpoch < 0
        || typeof target.profileId !== 'string'
        || typeof target.resourceType !== 'string'
        || typeof target.resourceKey !== 'string' ) {
        throw safeError();
    }
    const response = await fetch( settings.restRoot + 'apply', {
        method: 'POST', credentials: 'same-origin', signal,
        headers: {
            'X-WP-Nonce': settings.nonce,
            'X-CB-Designer-Scope': contextToken,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        body: JSON.stringify( {
            source, templateId,
            profileId: target.profileId, resourceType: target.resourceType,
            resourceKey: target.resourceKey, contextEpoch: target.contextEpoch,
        } ),
    } );
    if ( !response.ok ) throw safeError();
    const payload = await response.json();
    if ( payload?.status !== 'ready' || payload.source !== source
        || payload.templateId !== templateId
        || payload.profileId !== target.profileId
        || payload.resourceType !== target.resourceType
        || payload.resourceKey !== target.resourceKey
        || payload.contextEpoch !== target.contextEpoch
        || !payload.document || typeof payload.document !== 'object' ) {
        throw safeError();
    }
    return payload;
}

const api = Object.freeze( { createBrowser, open, loadForApply } );
if ( typeof window !== 'undefined' ) {
    window.cbCore = window.cbCore || {};
    window.cbCore.templateBrowser = api;
    const root = document.querySelector( '[data-cb-template-library-global]' );
    if ( root ) createBrowser( root, { mode: 'global' } );
}
export { createBrowser, open, loadForApply };
export default api;
