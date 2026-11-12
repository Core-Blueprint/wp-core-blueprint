import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = relative => readFileSync(new URL('../../' + relative, import.meta.url), 'utf8');
const buttons = read('assets/css/components/buttons.css');
const adminTheme = read('assets/css/admin-theme.css');
const assets = read('src/UI/Assets.php');
const modal = read('assets/js/core/modal.js');
const actions = read('assets/js/core/template-actions.js');

const danger = 'body.cb-admin-theme.wp-core-ui dialog.cb-core-modal--core ' +
    '.cb-core-modal__actions .button.cb-core-button--danger';

function declarations(selector) {
    const start = buttons.indexOf(selector + ' {');
    assert.notEqual(start, -1, 'Missing scoped Foundation selector: ' + selector);
    const open = buttons.indexOf('{', start);
    const close = buttons.indexOf('}', open);
    assert.ok(close > open, 'Unterminated CSS declaration');
    return buttons.slice(open + 1, close);
}

test('TL-M2 R2 polish: global Admin Theme has a more specific generic button cascade', () => {
    assert.match(adminTheme, /body\.cb-admin-theme\.wp-core-ui \.button\s*\{/);
    assert.match(adminTheme, /body\.cb-admin-theme\.wp-core-ui \.button:focus/);
    assert.match(modal, /confirmBtn\.className = 'button cb-core-button cb-core-button--' \+ confirmVariant/);
    assert.match(actions, /confirmVariant: destructive \? 'danger' : 'primary'/);
});

test('TL-M2 R2 polish: Core danger modal renders destructive color at rest', () => {
    const base = declarations(danger);
    assert.match(base, /background:\s*transparent;/);
    assert.match(base, /border:\s*1px solid var\(--cb-danger\);/);
    assert.match(base, /color:\s*var\(--cb-danger\);/);
    assert.match(base, /box-shadow:\s*none;/);
});

test('TL-M2 R2 polish: hover and keyboard focus cannot inherit cyan Admin Theme focus', () => {
    const interactive = declarations(danger + ':focus');
    assert.match(buttons, new RegExp(danger.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ':hover,'));
    assert.match(interactive, /background:\s*var\(--cb-danger\);/);
    assert.match(interactive, /border-color:\s*var\(--cb-danger\);/);
    assert.match(interactive, /color:\s*var\(--cb-surface-1\);/);
    assert.match(interactive, /box-shadow:\s*none;/);
    const focusVisible = declarations(danger + ':focus-visible');
    assert.match(focusVisible, /outline:\s*2px solid var\(--cb-danger\);/);
    assert.match(focusVisible, /outline-offset:\s*2px;/);
    assert.match(focusVisible, /box-shadow:\s*none;/);
});

test('TL-M2 R2 polish: disabled Delete remains subdued, Cancel remains secondary', () => {
    const disabled = declarations(danger + ':disabled');
    assert.match(disabled, /background:\s*transparent;/);
    assert.match(disabled, /opacity:\s*0\.5;/);
    assert.match(disabled, /cursor:\s*not-allowed;/);
    assert.match(modal, /cancelBtn\.className\s*=\s*'button cb-core-button cb-core-button--secondary'/);
});

test('TL-M2 R2 polish: all Base button registrations fingerprint the actual CSS', () => {
    const registrations = [...assets.matchAll(
        /CB_CORE_URL \. 'assets\/css\/components\/buttons\.css',\s*\[[^\]]+\],\s*([^)]*\([^)]*\)|[^)]*)/g
    )];
    assert.equal(registrations.length, 7, 'All shared button enqueue sites should be covered');
    assert.equal((assets.match(/self::asset_revision\( 'assets\/css\/components\/buttons\.css' \)/g) || []).length, 7);
    assert.doesNotMatch(assets, /CB_CORE_URL \. 'assets\/css\/components\/buttons\.css',\s*\[[^\]]+\],\s*CB_CORE_VERSION/);
});
