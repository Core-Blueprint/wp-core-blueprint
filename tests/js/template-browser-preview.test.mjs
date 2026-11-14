import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../assets/js/core/template-browser.js', import.meta.url), 'utf8'
);
const styles = readFileSync(
    new URL('../../assets/css/components/template-library.css', import.meta.url), 'utf8'
);
const assets = readFileSync(
    new URL('../../src/TemplateLibrary/Assets.php', import.meta.url), 'utf8'
);

class Element {
    constructor( tag ) {
        this.tagName = tag.toUpperCase();
        this.className = '';
        this.textContent = '';
        this.dataset = {};
        this.attributes = {};
        this.events = new Map();
        this.children = [];
        this.parentElement = null;
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.value = '';
        this.srcdoc = '';
        this.isConnected = true;
        this.classList = { add: name => { this.className += ' ' + name; } };
    }
    append( ...nodes ) {
        nodes.forEach( child => { child.parentElement = this; this.children.push( child ); } );
    }
    prepend( ...nodes ) {
        nodes.forEach( child => { child.parentElement = this; } );
        this.children.unshift( ...nodes );
    }
    replaceChildren( ...nodes ) {
        this.children = [];
        this.append( ...nodes );
    }
    setAttribute( name, value ) { this.attributes[name] = String( value ); }
    addEventListener( name, fn ) { this.events.set( name, fn ); }
    focus() { this.focused = true; }
}

const nextTurn = () => new Promise( resolve => setImmediate( resolve ) );
function fixture( { preview = true, refuse = false, deferred = null, contextual = false,
    modalDriver = null } = {} ) {
    const calls = [];
    const item = {
        id: 'wordpress.new-user', source: 'core-blueprint-mail',
        title: 'Welcome example', version: '1', origin: 'built-in',
        category: 'mail', designer: 'mail', description: 'Welcome message',
        compatible: true,
    };
    const document = {
        createElement: tag => new Element( tag ),
        getElementById: () => ({ textContent: JSON.stringify( {
            restRoot: '/wp-json/core-blueprint/v1/template-library/', nonce: 'fixture-nonce',
        } ) }),
        querySelector: () => null,
    };
    const window = { location: { origin: 'https://example.invalid' } };
    const sandbox = {
        document, window, HTMLElement: Element, URL, URLSearchParams,
        AbortController, setTimeout, clearTimeout, __showModal: modalDriver,
        fetch: async ( url, options = {} ) => {
            calls.push( { url, options } );
            if ( url.includes( '/sources?' ) ) return {
                ok: true, json: async () => ({ sources: [{
                    id: item.source, extension: 'core-blueprint', designer: 'mail',
                    categories: [{ id: 'mail', label: 'Mail' }],
                    previews: preview ? [1] : [],
                }] }),
            };
            if ( url.includes( '/templates?' ) ) return {
                ok: true, json: async () => ({ items: [item], next_cursor: null }),
            };
            if ( url.endsWith( '/preview' ) ) {
                if ( deferred ) return deferred.promise;
                if ( refuse ) return { ok: false, status: 403 };
                const request = JSON.parse( options.body );
                return { ok: true, json: async () => ({
                    status: 'ready', apiVersion: 1,
                    source: request.source, templateId: request.templateId,
                    version: request.version, contextEpoch: request.contextEpoch,
                    html: '<div style="color:#123456">Synthetic example</div>',
                }) };
            }
            throw new Error( 'Unexpected route: ' + url );
        },
    };
    const code = source
        .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = globalThis.__showModal || (async () => false);')
        .replace("import { createActionMenu } from '@cb-core/template-actions';", 'const createActionMenu = () => null;')
        .replace("import { createImportControl } from '@cb-core/template-resource-import';", 'const createImportControl = () => null;')
        .replace('export { createBrowser, open, loadForApply };', '')
        .replace('export default api;', '');
    runInNewContext( code, sandbox, { timeout: 1000 } );
    const root = new Element( 'div' );
    const browser = window.cbCore.templateBrowser.createBrowser( root, contextual
        ? { mode: 'designer', contextToken: 'server-issued-scope' } : {} );
    const shell = root.children[0];
    const content = shell.children[contextual ? 1 : 1];
    const grid = contextual ? content.children[2].children[0] : content.children[2];
    return { browser, root, content, grid, calls, item, api: window.cbCore.templateBrowser };
}

function previewButton( grid ) {
    return grid.children[0]?.children.find( child =>
        child.className.includes( 'cb-template-browser__preview-trigger' )
    );
}
function previewPanel( content ) {
    return content.children.find( child =>
        child.className.includes( 'cb-template-browser__preview-panel' )
    );
}

test('TL-1C-C Browser advertises explicit preview without placing HTML in listing cards', async () => {
    for ( const contextual of [false, true] ) {
        const f = fixture( { contextual } );
        await nextTurn();
        const button = previewButton( f.grid );
        assert.ok( button, 'only supported provider receives explicit Preview button' );
        const card = f.grid.children[0];
        assert.equal( card.tagName, 'ARTICLE', 'actionable card is not an interactive label' );
        if ( contextual ) {
            assert.equal( card.children[0].tagName, 'LABEL', 'selection stays a separate label' );
        }
        assert.equal( f.calls.filter( c => c.url.endsWith( '/preview' ) ).length, 0,
            'listing and first render never fetch preview content' );
        button.events.get( 'click' )();
        await nextTurn();
        const previewRequests = f.calls.filter( c => c.url.endsWith( '/preview' ) );
        assert.equal( previewRequests.length, 1 );
        const req = previewRequests[0];
        assert.equal( req.options.method, 'POST' );
        assert.equal( req.options.credentials, 'same-origin' );
        assert.equal( req.options.cache, 'no-store' );
        assert.equal( req.options.redirect, 'error' );
        assert.equal( req.options.referrerPolicy, 'no-referrer' );
        assert.equal( req.options.headers['X-WP-Nonce'], 'fixture-nonce' );
        assert.equal( req.options.headers['X-CB-Designer-Scope'],
            contextual ? 'server-issued-scope' : undefined );
        const payload = JSON.parse( req.options.body );
        assert.deepEqual( Object.keys( payload ).sort(), ['contextEpoch','source','templateId','version'] );
        assert.equal( payload.source, f.item.source );
        assert.equal( payload.templateId, f.item.id );
        assert.equal( payload.version, f.item.version );
        assert.equal( typeof payload.contextEpoch, 'number' );
        const panel = previewPanel( f.content );
        assert.ok( panel );
        assert.equal( panel.hidden, false );
        assert.equal( f.browser.previewOpen(), true );
        const toolbar = f.content.children[0];
        assert.equal( toolbar.inert, true, 'background controls cannot receive focus' );
        assert.equal( ( contextual ? f.content.children[2] : f.grid ).inert, true,
            'underlying template viewport cannot receive focus' );
        const iframe = panel.children[2];
        assert.equal( iframe.tagName, 'IFRAME' );
        assert.equal( iframe.attributes.sandbox, '' );
        assert.equal( iframe.attributes.referrerpolicy, 'no-referrer' );
        assert.equal( iframe.hidden, false );
        assert.match( iframe.srcdoc, /Synthetic example/ );
        assert.match( iframe.srcdoc, /script-src 'none'/ );
        assert.match( iframe.srcdoc, /connect-src 'none'/ );
        assert.match( iframe.srcdoc, /img-src 'none'/ );
        assert.match( iframe.srcdoc, /form-action 'none'/ );
        assert.match( iframe.srcdoc, /frame-src 'none'/ );
        const close = panel.children[0].children[1];
        close.events.get( 'click' )();
        assert.equal( panel.hidden, true );
        assert.equal( f.browser.previewOpen(), false );
        assert.equal( f.content.children[0].inert, false, 'filters regain normal keyboard focus' );
        assert.equal( ( contextual ? f.content.children[2] : f.grid ).inert, false,
            'template viewport regains keyboard focus' );
        assert.equal( iframe.srcdoc, '', 'closing drops previously authorized HTML from iframe' );
        assert.equal( button.focused, true, 'keyboard focus restored to Preview trigger' );
        f.browser.destroy();
    }
} );


test('TL-1C-D open preview gates the owning Designer modal confirmation', async () => {
    let modalOptions;
    let releaseModal;
    let confirmUpdates = 0;
    let chooseCalls = 0;
    const f = fixture( { contextual: true, modalDriver: options => {
        modalOptions = options;
        options.onReady( { updateConfirmAction: () => { confirmUpdates++; } } );
        return new Promise( resolve => { releaseModal = resolve; } );
    } } );
    f.browser.destroy();
    const opening = f.api.open( {
        contextToken: 'server-issued-scope',
        onChoose: async () => { chooseCalls++; return true; },
    } );
    try {
        await nextTurn();
        await nextTurn();
        const modalRoot = modalOptions.body.children[1];
        const content = modalRoot.children[0].children[1];
        const grid = content.children[2].children[0];
        const radio = grid.children[0].children[0].children[0];
        radio.checked = true;
        radio.events.get( 'change' )();
        assert.equal( modalOptions.confirmEnabled(), true, 'selection enables Use' );
        previewButton( grid ).events.get( 'click' )();
        assert.equal( modalOptions.confirmEnabled(), false, 'preview disables Use' );
        assert.equal( await modalOptions.onConfirm(), false, 'direct submit also refuses' );
        assert.equal( chooseCalls, 0, 'no Apply while preview owns focus' );
        const panel = previewPanel( content );
        panel.children[0].children[1].events.get( 'click' )();
        assert.equal( modalOptions.confirmEnabled(), true, 'Close restores selected action' );
        assert.ok( confirmUpdates >= 3, 'modal refreshed its native confirmation gate' );
    } finally {
        releaseModal( false );
        await opening;
    }
} );

test('TL-1C-C provider opt-out has no preview action and no preview request', async () => {
    const f = fixture( { preview: false } );
    await nextTurn();
    assert.equal( previewButton( f.grid ), undefined );
    assert.equal( f.calls.some( c => c.url.endsWith('/preview') ), false );
    f.browser.destroy();
} );

test('TL-1C-C revoked preview fails without showing or retaining provider content', async () => {
    const f = fixture( { refuse: true } );
    await nextTurn();
    previewButton( f.grid ).events.get( 'click' )();
    await nextTurn();
    const panel = previewPanel( f.content );
    assert.equal( panel.hidden, false, 'failure still offers accessible Close action' );
    assert.equal( panel.children[2].hidden, true );
    assert.equal( panel.children[2].srcdoc, '' );
    assert.match( panel.children[1].textContent, /Could not load templates/ );
    f.browser.destroy();
} );

test('TL-1C-C stale async preview cannot reopen after filters changed', async () => {
    let resolve;
    const deferred = { promise: new Promise( done => { resolve = done; } ) };
    const f = fixture( { deferred } );
    await nextTurn();
    const trigger = previewButton( f.grid );
    trigger.events.get( 'click' )();
    const panel = previewPanel( f.content );
    assert.equal( panel.hidden, false );
    const originFilters = f.root.children[0].children[0];
    originFilters.children[2].events.get( 'click' )();
    assert.equal( panel.hidden, true, 'refresh aborts and hides preview' );
    resolve({ ok: true, json: async () => ({
        status: 'ready', apiVersion: 1, source: f.item.source,
        templateId: f.item.id, version: f.item.version,
        contextEpoch: 1, html: '<div>Stale sample</div>',
    }) });
    await nextTurn();
    assert.equal( panel.children[2].srcdoc, '' );
    assert.equal( panel.hidden, true );
    f.browser.destroy();
} );

test('TL-1C-C security, accessibility and RTL presentation remain Base-owned', () => {
    assert.match( source, /const PREVIEW_CSP/ );
    assert.match( source, /iframe\.setAttribute\( 'sandbox', '' \)/ );
    assert.match( source, /iframe\.srcdoc = previewDocument\( html \)/ );
    assert.doesNotMatch( source, /\.innerHTML\s*=/ );
    assert.match( source, /state\.previewController\?\.abort\(\)/ );
    assert.match( source, /event\.key === 'Escape'/ );
    assert.match( styles, /\.cb-template-browser__preview-panel\[hidden\]/ );
    assert.match( styles, /\.cb-template-browser__preview-trigger:focus-visible/ );
    assert.match( styles, /margin-block-start:/ );
    assert.match( styles, /@media \(max-width: 720px\)/ );
    assert.match( assets, /'preview'\s*=> __\( 'Preview', 'core-blueprint' \)/ );
} );
