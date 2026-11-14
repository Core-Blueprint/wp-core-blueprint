/**
 * Shared Template Library browser. Discovery is metadata-only; previews are
 * explicit, authorized read-only POSTs isolated inside an opaque sandbox.
 *
 * @cb-core/template-browser is the only supported extension import.
 * Contextual browsing takes a server-issued DesignerScope token, NEVER a
 * caller-editable extension/type string. Applying a selected record remains
 * the consumer's own separately authorized responsibility.
 */
import { show as showModal } from '@cb-core/modal';
import { createActionMenu } from '@cb-core/template-actions';
import { createImportControl } from '@cb-core/template-resource-import';
import { createBulkControl } from '@cb-core/template-bulk';

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
// A preview is not a listing, Apply, or renderer. Recheck identity on reply.
async function requestPreview( item, contextToken, signal, contextEpoch ) {
    if ( !settings.restRoot || !settings.nonce ) throw safeError();
    const headers = { 'X-WP-Nonce': settings.nonce, 'Content-Type': 'application/json' };
    if ( contextToken ) headers['X-CB-Designer-Scope'] = contextToken;
    const response = await fetch( settings.restRoot + 'preview', {
        method: 'POST', credentials: 'same-origin', headers, signal,
        cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer',
        body: JSON.stringify( {
            source: item.source, templateId: item.id, version: item.version, contextEpoch,
        } ),
    } );
    if ( !response.ok ) throw safeError();
    const result = await response.json();
    if ( !result || result.status !== 'ready' || result.apiVersion !== 1
        || result.source !== item.source || result.templateId !== item.id
        || result.version !== item.version || result.contextEpoch !== contextEpoch
        || typeof result.html !== 'string' || !result.html || result.html.length > 65536 ) {
        throw safeError();
    }
    return result.html;
}

// The iframe is both scriptless and an opaque origin. WordPress KSES and the
// TemplatePreview DTO enforce the server-side HTML contract independently.
const PREVIEW_CSP = "default-src 'none'; base-uri 'none'; form-action 'none'; " +
    "script-src 'none'; object-src 'none'; frame-src 'none'; connect-src 'none'; " +
    "img-src 'none'; media-src 'none'; font-src 'none'; style-src 'unsafe-inline'";
function previewDocument( html ) {
    return '<!doctype html><html><head><meta charset="utf-8">' +
        '<meta http-equiv="Content-Security-Policy" content="' + PREVIEW_CSP + '">' +
        '<meta name="referrer" content="no-referrer">' +
        '<style>html{color-scheme:light;}body{font:14px/1.5 system-ui,sans-serif;' +
        'margin:16px;color:#202329;background:#fff;overflow-wrap:anywhere;}' +
        'table{max-width:100%;}*{box-sizing:border-box;}</style></head>' +
        '<body dir="auto">' + html + '</body></html>';
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
    const notifyState = () => {
        if ( contextual && typeof options.onStateChange === 'function' ) options.onStateChange();
    };
    const state = {
        filters: { limit: 24, search: '', source: '', extension: '', designer: '', origin: '', category: '', sort: '' },
        sources: [], cursor: null, entries: [], selected: null, pending: false,
        requestGeneration: 0, controller: null, timer: null, destroyed: false,
        actionMenus: [], activeActionClose: null, actionFeedback: '',
        previewController: null, previewEpoch: 0, previewPanel: null,
        previewFrame: null, previewFocus: null,
        view: hasDesigns ? 'designs' : 'templates', designProvider: '',
    };
    const shell = el( 'section', [ 'cb-template-browser', 'cb-template-browser--workspace',
        hasDesigns ? 'cb-template-browser--with-sections' : '',
        contextual ? 'cb-template-browser--modal' : '' ].filter( Boolean ).join( ' ' ) );
    const sidebar = el( 'div', 'cb-template-browser__sidebar' );
    sidebar.setAttribute( 'role', 'group' );
    sidebar.setAttribute( 'aria-label', label( 'browser', 'Browser' ) );
    // Both presentations use the same primary sections and secondary facets.
    // Origins are not folders; no folder API or synthetic collections are implied.
    const collections = el( 'div', 'cb-template-browser__collections' );
    collections.setAttribute( 'role', 'group' );
    collections.setAttribute( 'aria-label', label( 'origin', 'Origin' ) );
    const content = el( 'div', 'cb-template-browser__content' );
    const toolbar = el( 'div', 'cb-template-browser__toolbar' );
    const resultHeading = el( 'div', 'cb-template-browser__result-heading' );
    const resultTitle = el( contextual ? 'h3' : 'h2', 'cb-template-browser__result-title' );
    const resultCount = el( 'span', 'cb-template-browser__result-count' );
    resultHeading.append( resultTitle, resultCount );
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
    if ( designButton ) {
        designButton.type = 'button';
        designButton.dataset.browserView = 'designs';
        designButton.addEventListener( 'click', () => {
            state.view = 'designs';
            syncNavigation();
            refresh();
        } );
        sidebar.append( designButton );
    }
    const templateButton = el( 'button', 'cb-template-browser__origin cb-template-browser__origin--templates',
        label( 'templates', 'Templates' ) );
    templateButton.type = 'button';
    templateButton.dataset.browserView = 'templates';
    templateButton.addEventListener( 'click', () => {
        state.view = 'templates';
        syncNavigation();
        refresh();
    } );
    sidebar.append( templateButton );
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
        collections.append( button );
        return button;
    } );
    function updateResultHeading() {
        const chosen = origins.find( ( item ) => item[0] === state.filters.origin );
        resultTitle.textContent = state.view === 'designs'
            ? ( state.designProvider || label( 'allDesigns', 'All designs' ) )
            : ( chosen ? label( chosen[1], chosen[2] ) : label( 'templates', 'Templates' ) );
        resultCount.textContent = String( state.entries.length );
        resultCount.setAttribute( 'aria-label', String( state.entries.length ) + ' ' +
            ( state.view === 'designs' ? label( 'designs', 'Designs' ) : label( 'templates', 'Templates' ) ) );
    }
    function syncNavigation() {
        shell.dataset.browserView = state.view;
        if ( designButton ) designButton.setAttribute( 'aria-pressed', String( state.view === 'designs' ) );
        templateButton.setAttribute( 'aria-pressed', String( state.view === 'templates' ) );
        originButtons.forEach( ( item ) => item.setAttribute( 'aria-pressed',
            String( state.view === 'templates' && item.dataset.origin === state.filters.origin ) ) );
        const groupKey = state.view === 'templates' ? 'origin' : 'source';
        const groupName = label( groupKey, state.view === 'templates' ? 'Origin' : 'Source' );
        collections.setAttribute( 'aria-label', groupName );
        collections.replaceChildren( el( contextual ? 'h3' : 'h2', 'cb-template-browser__side-heading', groupName ) );
        if ( state.view === 'templates' ) {
            originButtons.forEach( ( button ) => collections.append( button ) );
        } else {
            const providers = [ ...new Set( designs.map( ( item ) => item.provider ).filter( Boolean ) ) ];
            const choices = [ [ '', label( 'allDesigns', 'All designs' ), designs.length ],
                ...providers.map( ( name ) =>
                    [ name, name, designs.filter( ( item ) => item.provider === name ).length ] ) ];
            for ( const [ value, name, count ] of choices ) {
                const button = el( 'button', 'cb-template-browser__origin', name );
                button.type = 'button';
                button.append( el( 'span', 'cb-template-browser__collection-count', String( count ) ) );
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
    const searchLabel = contextual && state.view === 'designs'
        ? label( 'searchDesigns', 'Search designs' ) : label( 'search', 'Search templates' );
    search.placeholder = searchLabel;
    search.setAttribute( 'aria-label', searchLabel );
    search.maxLength = 120;

    function addSelect( name, translationKey, fallback, choices = [] ) {
        const wrapper = el( 'label', 'cb-template-browser__field' );
        wrapper.append( el( 'span', '', label( translationKey, fallback ) ) );
        const select = el( 'select' );
        select.dataset.filter = name;
        select.append( option( '', label( 'allOptions', 'All' ) ) );
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
    // Source order is the unchanged default; sorted results are opt-in and
    // only advertised when the entire currently eligible provider set agrees.
    const sortField = el( 'label', 'cb-template-browser__field cb-template-browser__field--sort' );
    const sortLabel = el( 'span', '', label( 'sortBy', 'Sort by' ) );
    const sortSelect = el( 'select' );
    sortSelect.dataset.filter = 'sort';
    sortSelect.append(
        option( '', label( 'sourceOrder', 'Source order' ) ),
        option( 'title-asc', label( 'exactTitleOrder', 'Title (exact, case-sensitive)' ) )
    );
    sortField.append( sortLabel, sortSelect );
    sortField.hidden = true;
    sortSelect.addEventListener( 'change', () => {
        const next = sortSelect.value;
        if ( next && ( next !== 'title-asc' || !canSortAllSources() ) ) {
            sortSelect.value = state.filters.sort;
            return;
        }
        if ( next === state.filters.sort ) return;
        state.filters.sort = next;
        refresh();
    } );
    // This intentionally does NOT use the visible category/origin facets to
    // discard nonparticipating providers: the server still queries all
    // sources selected by source, extension and Designer identity.
    function eligibleSources() {
        return state.sources.filter( source =>
            ( !state.filters.source || source.id === state.filters.source )
            && ( !state.filters.extension || source.extension === state.filters.extension )
            && ( !state.filters.designer || source.designer === state.filters.designer )
        );
    }
    function canSortAllSources() {
        const eligible = eligibleSources();
        return eligible.length > 0 && eligible.length <= 12
            && eligible.every( source => Array.isArray( source.sorts )
                && source.sorts.includes( 'title-asc' ) );
    }
    function syncSortField() {
        const available = canSortAllSources();
        sortField.hidden = state.view !== 'templates' || !available;
        sortSelect.disabled = !available;
        if ( !available ) state.filters.sort = '';
        sortSelect.value = state.filters.sort;
    }
    function syncCategoryField() {
        if ( categorySelect.parentElement ) {
            categorySelect.parentElement.hidden = state.view === 'designs'
                || ( contextual && categorySelect.disabled );
        }
    }

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
        categorySelect.replaceChildren( option( '', label( 'allOptions', 'All' ) ) );
        [ ...available.entries() ].sort( ( a, b ) => a[1].localeCompare( b[1] ) )
            .forEach( ( [ id, title ] ) => categorySelect.append( option( id, title ) ) );
        categorySelect.disabled = available.size === 0;
        if ( state.filters.category && !available.has( state.filters.category ) ) {
            // Callers perform one refresh after changing a filter. Avoid
            // a redundant request when the selected category is no longer valid.
            state.filters.category = '';
        }
        categorySelect.value = state.filters.category;
        syncCategoryField();
        syncSortField();
    }

    search.addEventListener( 'input', () => {
        clearTimeout( state.timer );
        state.timer = setTimeout( () => { state.filters.search = search.value.trim(); refresh(); }, 250 );
    } );
    const searchField = el( 'label', 'cb-template-browser__field cb-template-browser__field--search' );
    searchField.append( el( 'span', '', searchLabel ), search );
    function syncSearchLabel() {
        const value = contextual && state.view === 'designs'
            ? label( 'searchDesigns', 'Search designs' ) : label( 'search', 'Search templates' );
        search.placeholder = value;
        search.setAttribute( 'aria-label', value );
        searchField.children[0].textContent = value;
    }
    // Keep DOM and visual focus order aligned: Search → Sort → source facets.
    toolbar.prepend( resultHeading, searchField, sortField );
    const importControl = !contextual ? createImportControl( {
        label, nonce: settings.nonce, resourceRoot: settings.resourceRoot,
        onNotice: text => {
            if ( state.destroyed ) return;
            state.actionFeedback = text; // Survives the confirmed list refresh.
            message.textContent = text;
        },
        onChanged: () => load( true ),
    } ) : null;
    if ( importControl ) toolbar.append( importControl.node );
    // Global Library alone owns bulk selection. Designer Use stays single-select.
    const bulkControl = !contextual ? createBulkControl( {
        label, nonce: settings.nonce, restRoot: settings.restRoot,
        isCurrent: () => !state.destroyed && state.view === 'templates' && !state.pending,
        onNotice: text => {
            if ( state.destroyed ) return;
            state.actionFeedback = text;
            message.textContent = text;
        },
        onChanged: async text => {
            if ( state.destroyed ) return;
            state.actionFeedback = text;
            await load( true );
        },
    } ) : null;
    content.append( toolbar, message );
    if ( bulkControl ) content.append( bulkControl.node );
    content.append( resultsViewport || results, footer );

    if ( contextual && typeof options.onSaveAs === 'function' ) {
        const saveArea = el( 'div', 'cb-template-browser__sidebar-footer' );
        const saveAction = el( 'button', 'button cb-template-browser__save-as',
            options.saveAsLabel || label( 'saveAs', 'Save as template' ) );
        saveAction.type = 'button';
        saveAction.addEventListener( 'click', async () => {
            if ( saveAction.disabled ) return;
            saveAction.disabled = true;
            try { await options.onSaveAs(); }
            catch {
                if ( options.notice instanceof HTMLElement ) {
                    options.notice.textContent = label( 'error', 'Could not load templates.' );
                }
            } finally { saveAction.disabled = false; }
        } );
        saveArea.append( saveAction );
        // Keep Save-as reachable when the Designer has templates but no Designs section.
        ( hasDesigns ? sidebar : collections ).append( saveArea );
    }
    // Without an actual Designs section, a single-item primary panel wastes
    // space and has no navigational value. The same Browser owns both layouts.
    if ( hasDesigns ) shell.append( sidebar, collections, content );
    else shell.append( collections, content );
    root.replaceChildren( shell );

    function disposeActionMenus() {
        state.actionMenus.forEach( menu => menu.dispose() );
        state.actionMenus = [];
        state.activeActionClose = null;
    }

    function providerSupportsPreview( item ) {
        return typeof item.version === 'string'
            && /^[1-9][0-9]*(?:\\.[0-9]+){0,2}$/.test( item.version )
            && state.sources.some( source => source.id === item.source
                && Array.isArray( source.previews ) && source.previews.includes( 1 ) );
    }
    function setBackgroundInert( active ) {
        // No nested modal: the page/Designer Browser remains the focus owner.
        // Exclude every underlying interactive pane while preview is open.
        [ sidebar, collections, toolbar, bulkControl?.node, resultsViewport || results, footer ]
            .filter( Boolean ).forEach( node => {
            node.inert = active;
        } );
    }
    function closePreview( restoreFocus = false ) {
        const wasOpen = !!state.previewPanel && !state.previewPanel.hidden;
        setBackgroundInert( false );
        ++state.previewEpoch;
        state.previewController?.abort();
        state.previewController = null;
        if ( state.previewFrame ) state.previewFrame.srcdoc = '';
        if ( state.previewPanel ) state.previewPanel.hidden = true;
        const focus = state.previewFocus;
        state.previewFocus = null;
        if ( restoreFocus && focus && typeof focus.focus === 'function' && focus.isConnected ) {
            focus.focus();
        }
        if ( wasOpen ) notifyState();
    }
    function previewSurface() {
        if ( state.previewPanel ) return state.previewPanel;
        const panel = el( 'section', 'cb-template-browser__preview-panel' );
        panel.setAttribute( 'role', 'region' );
        panel.setAttribute( 'aria-label', label( 'preview', 'Preview' ) );
        const header = el( 'div', 'cb-template-browser__preview-header' );
        const heading = el( 'h3', 'cb-template-browser__preview-title' );
        const close = el( 'button', 'button cb-template-browser__preview-close', label( 'close', 'Close' ) );
        close.type = 'button';
        close.addEventListener( 'click', () => closePreview( true ) );
        header.append( heading, close );
        const status = el( 'p', 'cb-template-browser__preview-status' );
        status.setAttribute( 'role', 'status' );
        status.setAttribute( 'aria-live', 'polite' );
        const iframe = el( 'iframe', 'cb-template-browser__preview-frame' );
        iframe.setAttribute( 'sandbox', '' );
        iframe.setAttribute( 'referrerpolicy', 'no-referrer' );
        iframe.setAttribute( 'loading', 'lazy' );
        iframe.hidden = true;
        panel.append( header, status, iframe );
        panel.hidden = true;
        panel.addEventListener( 'keydown', event => {
            if ( event.key === 'Escape' ) {
                event.preventDefault();
                event.stopPropagation();
                closePreview( true );
            }
        } );
        content.append( panel );
        state.previewPanel = panel;
        state.previewFrame = iframe;
        return panel;
    }
    async function openPreview( item, index, trigger ) {
        if ( state.destroyed || state.view !== 'templates'
            || state.entries[index] !== item || !providerSupportsPreview( item ) ) return;
        closePreview();
        state.activeActionClose?.();
        const panel = previewSurface();
        const [ header, status, iframe ] = panel.children;
        header.children[0].textContent = item.title;
        iframe.setAttribute( 'title', label( 'preview', 'Preview' ) + ': ' + item.title );
        status.textContent = label( 'loading', 'Loading templates…' );
        iframe.hidden = true;
        panel.hidden = false;
        setBackgroundInert( true );
        state.previewFocus = trigger;
        notifyState();
        const close = header.children[1];
        if ( typeof close.focus === 'function' ) close.focus();
        const controller = new AbortController();
        state.previewController = controller;
        const currentEpoch = ++state.previewEpoch;
        const contextEpoch = state.requestGeneration;
        try {
            const html = await requestPreview( item, token, controller.signal, contextEpoch );
            if ( controller.signal.aborted || state.destroyed
                || currentEpoch !== state.previewEpoch || contextEpoch !== state.requestGeneration
                || state.entries[index] !== item || state.view !== 'templates' ) return;
            iframe.srcdoc = previewDocument( html );
            iframe.hidden = false;
            status.textContent = '';
        } catch {
            if ( !controller.signal.aborted && !state.destroyed && currentEpoch === state.previewEpoch ) {
                iframe.srcdoc = '';
                iframe.hidden = true;
                status.textContent = label( 'error', 'Could not load templates.' );
            }
        } finally {
            if ( state.previewController === controller ) state.previewController = null;
        }
    }

    function renderDesigns() {
        closePreview();
        disposeActionMenus();
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
            radio.addEventListener( 'change', () => {
                if ( radio.checked ) {
                    state.selected = index;
                    notifyState();
                }
            } );
            // Designs are selectable resources, not visual template previews.
            // Keep their single radio + label contract without fabricated thumbnails.
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
            card.append( radio, body );
            results.append( card );
        }
        message.textContent = state.entries.length ? '' : label( 'noDesigns', 'No designs found.' );
        summary.textContent = String( state.entries.length );
        updateResultHeading();
        more.hidden = true;
        footer.hidden = contextual;
        notifyState();
    }

    function renderCard( item, index ) {
        // The same provider-gated preview capability now participates in
        // the canonical Actions menu. Never introduce a second card control.
        const previewable = providerSupportsPreview( item );
        const menu = createActionMenu( item, {
            restRoot: settings.restRoot, downloadRoot: settings.downloadRoot,
            nonce: settings.nonce, contextToken: token, globalMode: !contextual, label,
            previewAvailable: previewable,
            onPreview: trigger => { void openPreview( item, index, trigger ); },
            isCurrent: () => !state.destroyed && state.view === 'templates'
                && state.entries[index] === item,
            onOpening: close => {
                state.activeActionClose?.();
                state.activeActionClose = close;
            },
            onNotice: notice => {
                state.actionFeedback = notice;
                message.textContent = notice;
            },
            onChanged: async notice => {
                if ( state.destroyed ) return;
                state.actionFeedback = notice;
                await refresh();
            },
        } );
        // Interactive menus must never be descendants of a selection label.
        // An actionable card uses a separate label and sibling disclosure.
        const article = el( contextual && !menu && !previewable ? 'label' : 'article',
            'cb-template-browser__card' );
        const selection = contextual && ( menu || previewable )
            ? el( 'label', 'cb-template-browser__card-select' ) : article;
        if ( contextual ) {
            const radio = el( 'input', 'cb-template-browser__choice' );
            radio.type = 'radio';
            radio.name = 'cb-template-choice';
            radio.value = String( index );
            radio.checked = state.selected === index;
            radio.disabled = !item.compatible;
            radio.addEventListener( 'change', () => {
                if ( radio.checked ) {
                    state.selected = index;
                    notifyState();
                }
            } );
            selection.append( radio );
        }
        // A neutral, non-document preview keeps cards visually consistent across
        // the global Library and scoped Browser without exposing document bodies.
        const preview = el( 'div', 'cb-template-browser__card-preview' );
        preview.setAttribute( 'aria-hidden', 'true' );
        selection.append( preview );
        const body = el( 'div', 'cb-template-browser__card-body' );
        body.append( el( 'h3', 'cb-template-browser__card-title', item.title ) );
        if ( item.description ) body.append( el( 'p', 'cb-template-browser__card-description', item.description ) );
        const meta = el( 'div', 'cb-template-browser__card-meta' );
        // Translate the declared origin, but never turn it into a trust badge.
        // The source ID and version are the server-projected, non-authoritative
        // provenance facts: they do not authenticate an offline template pack.
        const origins = {
            'built-in': [ 'builtin', 'Built-in' ],
            personal: [ 'personal', 'My templates' ],
            site: [ 'site', 'Site templates' ],
            'installed-pack': [ 'packs', 'Installed packs' ],
        };
        const origin = Object.hasOwn( origins, item.origin ) ? origins[item.origin] : null;
        // Human-facing names only; preserve the exact provider IDs for
        // filters, routing, authorization and provenance. Unknown IDs stay as-is.
        const categoryCaption = item.category === 'wordpress' ? 'WordPress'
            : item.category === 'mail' ? 'Mail' : item.category;
        const designerCaption = item.designer === 'mail' ? 'Mail' : item.designer;
        meta.append(
            el( 'span', '', origin ? label( ...origin ) : item.origin ),
            el( 'span', '', categoryCaption ),
            el( 'span', '', designerCaption )
        );
        body.append( meta );
        const provenance = el( 'div', 'cb-template-browser__card-provenance' );
        if ( typeof item.source === 'string' && item.source ) {
            provenance.append( el( 'span', '', label( 'source', 'Source' ) + ': ' + item.source ) );
        }
        if ( typeof item.version === 'string' && item.version ) {
            provenance.append( el( 'span', '', 'v' + item.version ) );
        }
        if ( provenance.children.length ) body.append( provenance );
        const stateBadge = el( 'span',
            item.compatible ? 'cb-template-browser__status' : 'cb-template-browser__status cb-template-browser__status--blocked',
            item.compatible ? label( 'compatible', 'Compatible' ) : label( 'incompatible', 'Not compatible' )
        );
        if ( contextual || !item.compatible ) body.append( stateBadge );
        selection.append( body );
        if ( selection !== article ) article.append( selection );
        // Only provider-managed personal/site templates get native checkboxes.
        // Browser modal radios and the individual Actions menu remain separate.
        const bulkCheckbox = bulkControl?.checkbox( item, article );
        if ( bulkCheckbox ) article.append( bulkCheckbox );
        if ( menu ) {
            const actions = el( 'div', 'cb-template-browser__actions' );
            actions.append( menu.node );
            article.append( actions );
            state.actionMenus.push( menu );
        }
        return article;
    }

    async function load( reset = false ) {
        if ( state.destroyed ) return;
        if ( reset ) closePreview();
        syncCategoryField();
        syncSortField();
        syncSearchLabel();
        if ( state.view === 'designs' ) {
            renderDesigns();
            return;
        }
        if ( reset ) {
            bulkControl?.reset();
            state.controller?.abort();
            disposeActionMenus();
            state.entries = [];
            state.selected = null;
            state.cursor = null;
            results.replaceChildren();
            notifyState();
        }
        updateResultHeading();
        if ( state.pending && !reset ) return;
        state.pending = true;
        const generation = ++state.requestGeneration;
        const controller = new AbortController();
        state.controller = controller;
        message.textContent = label( 'loading', 'Loading templates…' );
        more.hidden = true;
        footer.hidden = contextual;
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
            message.textContent = state.actionFeedback || ( ( state.entries.length || state.cursor )
                ? '' : label( 'noResults', 'No templates found.' ) );
            state.actionFeedback = '';
            summary.textContent = String( state.entries.length );
            updateResultHeading();
            more.hidden = !state.cursor;
            footer.hidden = contextual && !state.cursor;
            notifyState();
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
        importControl?.updateSources( data.sources );
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
        syncSortField();
        // Sources can resolve after the first template page. Add the optional
        // preview controls without issuing a second metadata request.
        if ( state.view === 'templates' && state.entries.length
            && data.sources.some( source => Array.isArray( source.previews )
                && source.previews.includes( 1 ) ) ) {
            disposeActionMenus();
            bulkControl?.reset(); // Recreate native checkbox DOM with the refreshed cards.
            results.replaceChildren( ...state.entries.map( ( item, index ) => renderCard( item, index ) ) );
        }
    } ).catch( () => {} );

    refresh();
    return {
        selected: () => state.selected === null ? null : ( state.entries[state.selected] || null ),
        previewOpen: () => !!state.previewPanel && !state.previewPanel.hidden,
        view: () => state.view,
        destroy: () => {
            closePreview();
            state.destroyed = true;
            state.controller?.abort();
            importControl?.destroy();
            bulkControl?.destroy();
            disposeActionMenus();
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
    let updateConfirmAction = null;
    let browser = null;
    browser = createBrowser( root, {
        mode: 'designer', contextToken,
        designs: typeof onOpenDesign === 'function' ? designs : [],
        activeDesignId, onSaveAs, saveAsLabel, notice,
        onStateChange: () => {
            if ( browser && updateConfirmAction ) updateConfirmAction( browser.view() === 'designs'
                ? label( 'openDesign', 'Open design' ) : label( 'use', 'Use template' ) );
        },
    } );
    let pendingDesign = null;
    try {
        const accepted = await showModal( {
            title: title || label( 'browse', 'Browse templates' ),
            body,
            size: 'fullscreen',
            confirmLabel: browser.view() === 'designs'
                ? label( 'openDesign', 'Open design' ) : label( 'use', 'Use template' ),
            confirmEnabled: () => {
                // The parent modal must not Apply a selected template through
                // an open preview's inert background.
                if ( browser.previewOpen() ) return false;
                const selected = browser.selected();
                return !!selected && ( selected.kind === 'design'
                    ? typeof onOpenDesign === 'function' : !!selected.compatible );
            },
            onReady: ( { updateConfirmAction: updateAction } ) => {
                updateConfirmAction = updateAction;
                updateConfirmAction( browser.view() === 'designs'
                    ? label( 'openDesign', 'Open design' ) : label( 'use', 'Use template' ) );
            },
            cancelLabel: label( 'close', 'Close' ),
            onConfirm: async () => {
                // Recheck at execution, not just when the button was rendered.
                if ( browser.previewOpen() ) return false;
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
