import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';

const source = readFileSync( new URL( '../../assets/js/core/template-bulk.js', import.meta.url ), 'utf8' );
const browser = readFileSync( new URL( '../../assets/js/core/template-browser.js', import.meta.url ), 'utf8' );
const moduleImport = "import { show as showModal } from '@cb-core/modal';";

class Element {
    constructor( tag ) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.listeners = new Map();
        this.className = '';
        this.attributes = {};
        this.checked = false;
        this.disabled = false;
        this.hidden = false;
        this.textContent = '';
        this.parentElement = null;
        this.classList = {
            toggle: ( name, active ) => {
                const names = new Set( this.className.split( /\s+/ ).filter( Boolean ) );
                if ( active ) names.add( name ); else names.delete( name );
                this.className = [ ...names ].join( ' ' );
            },
        };
    }
    append( ...children ) {
        for ( const child of children ) { child.parentElement = this; this.children.push( child ); }
    }
    setAttribute( name, value ) { this.attributes[name] = String( value ); }
    addEventListener( event, callback ) { this.listeners.set( event, callback ); }
    remove() { this.removed = true; }
    click() { return this.listeners.get( 'click' )?.(); }
    change( checked ) { this.checked = checked; return this.listeners.get( 'change' )?.(); }
}
const record = ( id, actions = [ 'export-json', 'delete' ], origin = 'personal' ) => ( {
    id, title: id, source: 'core-blueprint-mail-saved', version: '1',
    actions, origin,
} );

function harness( opts = {} ) {
    const calls = [];
    const notices = [];
    const downloaded = [];
    const dialogs = [];
    let changed = 0;
    let active = true;
    const document = {
        createElement: tag => {
            const node = new Element( tag );
            if ( tag === 'a' ) {
                node.click = () => downloaded.push( { name: node.download, href: node.href } );
            }
            return node;
        },
        body: new Element( 'body' ),
    };
    const classURL = class TestURL extends URL {
        static createObjectURL() { return 'blob:test'; }
        static revokeObjectURL() {}
    };
    const sandbox = {
        document,
        window: { location: { href: 'https://example.test/wp-admin/admin.php',
            origin: 'https://example.test' } },
        URL: classURL, Blob, Uint8Array, atob, setTimeout: () => 1,
        modalHook: async config => {
            dialogs.push( config );
            assert.equal( config.confirmVariant, 'danger' );
            assert.equal( config.confirmEnabled(), true );
            assert.ok( config.confirmCheck?.label );
            return opts.confirm !== false;
        },
        fetch: async ( url, options ) => {
            const action = String( url ).split( '/' ).at( -1 );
            const body = JSON.parse( options.body );
            calls.push( { action, body, options } );
            assert.equal( options.credentials, 'same-origin' );
            assert.equal( options.headers['X-WP-Nonce'], 'nonce' );
            if ( action === 'bulk-export' ) {
                return { ok: true, json: async () => ( {
                    status: 'ready', format: 'core-blueprint-bulk-json-v1',
                    count: body.items.length,
                    data: Buffer.from( 'PKdummy-zip-file-contents'.repeat( 2 ) ).toString( 'base64' ),
                } ) };
            }
            const reply = opts.manage?.( body, calls.filter( x => x.action === 'manage' ).length )
                ?? { status: 'deleted' };
            if ( reply instanceof Error ) throw reply;
            return { ok: reply.status === 'deleted', json: async () => reply };
        },
    };
    const runnable = source.replace( moduleImport, 'const showModal = globalThis.modalHook;' )
        .replace( 'export function createBulkControl', 'function createBulkControl' )
        + '\nglobalThis.bulk = createBulkControl;';
    assert.ok( source.includes( moduleImport ) );
    runInNewContext( runnable, sandbox, { timeout: 2000 } );
    const control = sandbox.bulk( {
        label: ( key, fallback ) => fallback,
        restRoot: opts.restRoot || 'https://example.test/wp-json/core-blueprint/v1/template-library/',
        nonce: 'nonce',
        isCurrent: () => active,
        onNotice: message => notices.push( message ),
        onChanged: async message => { ++changed; notices.push( message ); },
    } );
    const [ toggle, counter, buttons ] = control.node.children;
    const [ exportButton, deleteButton, clearButton ] = buttons.children;
    const check = item => {
        const article = new Element( 'article' );
        const wrapped = control.checkbox( item, article );
        return { article, wrapped, input: wrapped?.children[0] };
    };
    return {
        control, calls, notices, downloaded, dialogs, toggle: toggle.children[0],
        counter, exportButton, deleteButton, clearButton, check,
        get changed() { return changed; },
        revoke() { active = false; },
    };
}

test( 'TL-BULK-01: only editable global personal/site templates have native checkboxes', () => {
    const h = harness();
    assert.equal( h.control.node.hidden, true );
    assert.equal( h.check( record( 'mail.builtin', [ 'export-json' ], 'built-in' ) ).wrapped, null );
    assert.equal( h.check( record( 'mail.pack', [ 'delete' ], 'installed-pack' ) ).wrapped, null );
    assert.equal( h.check( record( 'mail.nomgmt', [], 'personal' ) ).wrapped, null );
    const found = h.check( record( 'mail.owned' ) );
    assert.ok( found.input );
    assert.match( found.input.attributes['aria-label'], /Select template: mail.owned/ );
    assert.equal( h.control.node.hidden, false );
    assert.equal( h.exportButton.disabled, true );
    assert.equal( h.deleteButton.disabled, true );
    assert.match( browser, /!contextual \? createBulkControl/ );
    assert.match( browser, /if \( contextual \) \{/ );
    h.control.destroy();
} );

test( 'TL-BULK-01: select shown, clear and real single bulk ZIP download without management writes', async () => {
    const h = harness();
    const a = h.check( record( 'mail.one' ) );
    const b = h.check( record( 'mail.two' ) );
    h.toggle.change( true );
    assert.equal( a.input.checked, true );
    assert.equal( b.input.checked, true );
    assert.equal( h.counter.textContent, '2 selected' );
    assert.equal( h.deleteButton.disabled, false );
    assert.equal( h.exportButton.disabled, false );
    await h.exportButton.click();
    assert.deepEqual( h.calls.map( c => c.action ), [ 'bulk-export' ] );
    assert.deepEqual( Array.from( h.calls[0].body.items, item => item.templateId ), [ 'mail.one', 'mail.two' ] );
    assert.equal( h.downloaded.length, 1 );
    assert.equal( h.downloaded[0].name, 'core-blueprint-templates-bulk-json.zip' );
    assert.equal( h.changed, 0 );
    h.clearButton.click();
    assert.equal( h.counter.textContent, '' );
    assert.equal( a.input.checked, false );
    assert.equal( b.input.checked, false );
    h.control.destroy();
} );

test( 'TL-BULK-01: mixed permissions never grant delete or export to the whole selection', () => {
    const h = harness();
    h.check( record( 'mail.export', [ 'export-json' ] ) ).input.change( true );
    h.check( record( 'mail.delete', [ 'delete' ] ) ).input.change( true );
    assert.equal( h.deleteButton.disabled, true );
    assert.equal( h.exportButton.disabled, true );
    assert.equal( h.calls.length, 0 );
    h.control.destroy();
} );

test( 'TL-BULK-01: bulk delete requires danger confirmation and reports partial known refusal', async () => {
    const h = harness( { manage: ( _body, n ) => n === 1 ? { status: 'deleted' } : { status: 'conflict' } } );
    h.check( record( 'mail.one' ) ).input.change( true );
    h.check( record( 'mail.two' ) ).input.change( true );
    h.check( record( 'mail.three' ) ).input.change( true );
    await h.deleteButton.click();
    assert.equal( h.dialogs.length, 1 );
    assert.deepEqual( h.calls.map( c => c.action ), [ 'manage', 'manage' ] );
    assert.deepEqual( h.calls[0].body, {
        source: 'core-blueprint-mail-saved', templateId: 'mail.one',
        revision: 1, action: 'delete', input: {},
    } );
    assert.equal( h.changed, 1 );
    assert.ok( h.notices.some( n => n.includes( '1 deleted. 2 not processed.' ) ) );
    h.control.destroy();
} );

test( 'TL-BULK-01: lost delete receipt blocks automatic retries and all further bulk writes', async () => {
    const h = harness( { manage: () => new Error( 'network lost after commit' ) } );
    h.check( record( 'mail.one' ) ).input.change( true );
    h.check( record( 'mail.two' ) ).input.change( true );
    await h.deleteButton.click();
    assert.deepEqual( h.calls.map( c => c.action ), [ 'manage' ] );
    assert.equal( h.deleteButton.disabled, true );
    assert.equal( h.exportButton.disabled, true );
    await h.deleteButton.click();
    assert.equal( h.calls.length, 1 );
    assert.equal( h.changed, 0 );
    assert.ok( h.notices.some( n => n.includes( 'Outcome uncertain' ) ) );
    h.control.destroy();
} );

test( 'TL-BULK-01: cancellation, reset and capped Select shown do not write', async () => {
    const h = harness( { confirm: false } );
    const boxes = Array.from( { length: 13 }, ( _, index ) => h.check( record( 'mail.' + index ) ) );
    h.toggle.change( true );
    assert.equal( boxes.filter( box => box.input.checked ).length, 12 );
    assert.ok( h.notices.some( n => n.includes( 'at most 12' ) ) );
    assert.equal( boxes[12].input.disabled, true );
    await h.deleteButton.click();
    assert.equal( h.calls.length, 0 );
    h.control.reset();
    assert.equal( h.control.node.hidden, true );
    assert.equal( h.counter.textContent, '' );
    h.control.destroy();
} );
