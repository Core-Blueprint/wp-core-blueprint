import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { File } from 'node:buffer';
import { test } from 'node:test';

const SOURCE = readFileSync(
    new URL( '../../assets/js/core/template-resource-import.js', import.meta.url ), 'utf8',
);
const ENTRY = "import { show as showModal } from '@cb-core/modal';";
assert.ok( SOURCE.includes( ENTRY ), 'Production import must use the shared Base modal' );

class FakeElement {
    constructor( tag ) {
        this.tag = tag;
        this.children = [];
        this.listeners = new Map();
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this.textContent = '';
        this.files = [];
        this.attributes = new Map();
    }
    append( ...children ) {
        this.children.push( ...children );
        if ( this.tag === 'select' && this.children.length === children.length ) {
            this.value = children[0]?.value || '';
        }
    }
    addEventListener( name, callback ) { this.listeners.set( name, callback ); }
    setAttribute( name, value ) { this.attributes.set( name, value ); }
    remove() { this.removed = true; }
    async click() { return this.listeners.get( 'click' )?.(); }
    find( tag ) {
        if ( this.tag === tag ) return this;
        for ( const child of this.children ) {
            const match = child.find( tag );
            if ( match ) return match;
        }
        return null;
    }
}

function setup( mode = 'complete', endpoint = 'https://example.test/wp-json/core-blueprint/v1/template-resource-package/' ) {
    const original = {
        document: globalThis.document,
        window: globalThis.window,
        fetch: globalThis.fetch,
        modal: globalThis.__cbTestModal,
    };
    const calls = [];
    let dialogs = 0;
    let changed = 0;
    const notices = [];
    const file = new File( [ new Uint8Array( 180 ) ], 'template.zip', { type: 'application/zip' } );
    globalThis.document = { createElement: tag => new FakeElement( tag ) };
    globalThis.window = { location: { href: 'https://example.test/wp-admin/admin.php', origin: 'https://example.test' } };
    globalThis.__cbTestModal = async opts => {
        ++dialogs;
        if ( dialogs === 1 ) {
            const input = opts.body.find( 'input' );
            input.files = [ file ];
            opts.onReady?.( { updateConfirmAction() {} } );
            assert.equal( opts.confirmEnabled(), true );
            return opts.onConfirm();
        }
        if ( dialogs === 2 ) {
            assert.equal( opts.confirmEnabled(), true );
            return opts.onConfirm();
        }
        throw new Error( 'Unexpected duplicate confirmation dialog' );
    };
    globalThis.fetch = async ( url, options ) => {
        const action = new URL( url ).pathname.split( '/' ).at( -1 );
        calls.push( { action, options } );
        assert.equal( options.method, 'POST' );
        assert.equal( options.credentials, 'same-origin' );
        assert.equal( options.cache, 'no-store' );
        assert.equal( options.redirect, 'error' );
        assert.equal( options.headers['X-WP-Nonce'], 'verified' );
        if ( action === 'apply' && mode === 'unknown' ) throw new Error( 'Lost server receipt' );
        const result = action === 'stage' ? { status: 'ready', ticket: 't'.repeat( 124 ) }
            : action === 'preview' ? { status: 'ready', preview: {
                valid: mode !== 'bad-preview', record_count: 1,
                counts: { create: 1 }, fingerprint: 'f'.repeat( 64 ),
            } }
            : action === 'apply' ? { status: 'complete', reference: 'template:new' }
            : { status: 'discarded' };
        return { ok: true, json: async () => result };
    };
    const restore = () => {
        globalThis.document = original.document;
        globalThis.window = original.window;
        globalThis.fetch = original.fetch;
        globalThis.__cbTestModal = original.modal;
    };
    return {
        async create() {
            const source = SOURCE.replace( ENTRY,
                'const showModal = globalThis.__cbTestModal;' );
            const mod = await import( 'data:text/javascript;charset=utf-8,' + encodeURIComponent( source )
                + '#fixture-' + mode + '-' + Math.random().toString( 36 ).slice( 2 ) );
            return mod.createImportControl( {
                label: ( key, fallback ) => fallback,
                resourceRoot: endpoint,
                nonce: 'verified',
                onNotice: text => notices.push( text ),
                onChanged: async () => { ++changed; },
            } );
        },
        calls, notices,
        get changed() { return changed; },
        get dialogs() { return dialogs; },
        restore,
    };
}

test( 'T4 global Import ZIP stays hidden without an admitted source or on an unsafe origin', async () => {
    const safe = setup();
    try {
        const control = await safe.create();
        assert.equal( control.node.hidden, true );
        control.updateSources( [ { id: 'reports', resourceImportDestination: '../forged' } ] );
        assert.equal( control.node.hidden, true );
        control.updateSources( [ { id: 'reports', resourceImportDestination: 'reports.drafts' } ] );
        assert.equal( control.node.hidden, false );
        control.destroy();
        assert.equal( control.node.removed, true );
    } finally { safe.restore(); }
    const unsafe = setup( 'complete', 'https://attacker.test/wp-json/core-blueprint/v1/template-resource-package/' );
    try {
        const control = await unsafe.create();
        control.updateSources( [ { id: 'reports', resourceImportDestination: 'reports.drafts' } ] );
        assert.equal( control.node.hidden, true );
    } finally { unsafe.restore(); }
} );

test( 'T4 import performs stage, immutable preview, explicit confirm, one apply and discard', async () => {
    const fixture = setup();
    try {
        const control = await fixture.create();
        control.updateSources( [ { id: 'reports', resourceImportDestination: 'reports.drafts' } ] );
        await control.node.find( 'button' ).click();
        assert.deepEqual( fixture.calls.map( c => c.action ), [ 'stage', 'preview', 'apply', 'discard' ] );
        assert.equal( fixture.dialogs, 2 );
        assert.equal( fixture.changed, 1 );
        assert.equal( control.node.find( 'button' ).disabled, false );
        assert.ok( fixture.notices.some( x => x.includes( 'Draft copy imported' ) ) );
    } finally { fixture.restore(); }
} );

test( 'T4 failed preview never applies and discards the signed stage', async () => {
    const fixture = setup( 'bad-preview' );
    try {
        const control = await fixture.create();
        control.updateSources( [ { id: 'reports', resourceImportDestination: 'reports.drafts' } ] );
        await control.node.find( 'button' ).click();
        assert.deepEqual( fixture.calls.map( c => c.action ), [ 'stage', 'preview', 'discard' ] );
        assert.equal( fixture.dialogs, 1 );
        assert.equal( fixture.changed, 0 );
    } finally { fixture.restore(); }
} );

test( 'T4 lost apply response never retries and preserves stage for reconciliation', async () => {
    const fixture = setup( 'unknown' );
    try {
        const control = await fixture.create();
        control.updateSources( [ { id: 'reports', resourceImportDestination: 'reports.drafts' } ] );
        const button = control.node.find( 'button' );
        await button.click();
        assert.deepEqual( fixture.calls.map( c => c.action ), [ 'stage', 'preview', 'apply' ] );
        assert.equal( button.disabled, true );
        await button.click();
        assert.deepEqual( fixture.calls.map( c => c.action ), [ 'stage', 'preview', 'apply' ] );
        assert.equal( fixture.changed, 0 );
        assert.ok( fixture.notices.some( x => x.includes( 'outcome unknown' ) ) );
    } finally { fixture.restore(); }
} );
