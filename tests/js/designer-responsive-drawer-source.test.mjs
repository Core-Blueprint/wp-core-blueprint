import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const source = relative => readFile(new URL(relative, import.meta.url), 'utf8');

test('FIELD-06: nested native Host stages own their panel visibility, not an outer shell', async () => {
    const css = await source('../../assets/css/design/designer-mode.css');
    const prefix = '.cb-core-design-shell';
    const workspace = ' > .cb-core-design-shell__workspace--collapsible';
    for (const side of ['left', 'right']) {
        const panel = side === 'left' ? 'palette' : 'sidebar';
        for (const state of [
            prefix + '.is-' + side + '-panel-collapsed' + workspace,
            prefix + ':not(.is-' + side + '-panel-collapsed)' + workspace,
        ]) {
            assert.ok(css.includes(state + ' > .cb-core-design-shell__' + panel),
                'Owner state must target its direct workspace: ' + state);
        }
    }
    assert.doesNotMatch(css,
        /\.cb-core-design-shell(?:(?:\.is-(?:left|right)-panel-collapsed)|(?::not\(\.is-(?:left|right)-panel-collapsed\))) \.cb-core-design-shell__workspace--collapsible/,
        'Descendant selectors let an outer Host override the inner drawer state');
    assert.match(css, /\.cb-core-design-shell__drawer-backdrop\[hidden\]\s*\{\s*display: none;/);
});

test('FIELD-06: live Host really mounts a nested outer and inner Designer shell', async () => {
    const bootstrap = await source('../../assets/js/design/platform/wordpress-save-adapter.js');
    const editor = await source('../../assets/js/design/editor.js');
    assert.match(bootstrap, /root\.className='cb-core-design-shell cb-core-form-scope'/);
    assert.match(bootstrap, /root\.append\(canvas\)/);
    assert.match(editor, /root\.classList\.add\('cb-core-design-shell', 'cb-core-design-shell--native'\)/);
    assert.match(editor, /root\.append\(toolbar, notice, workspace\)/);
});

test('FIELD-06: visual backdrop copy removed; accessibility and dismissal preserved', async () => {
    const editor = await source('../../assets/js/design/editor.js');
    assert.match(editor, /const backdrop = button\('', 'data-cb-design-shell-drawer-backdrop'\)/);
    assert.match(editor, /backdrop\.setAttribute\('aria-label', 'Close sidebar'\)/);
    assert.match(editor, /backdrop\.addEventListener\('click', \(\) => close\(true\), \{signal\}\)/);
    assert.match(editor, /if \(event\.key === 'Escape'\)[\s\S]*?close\(true\)/);
    assert.match(editor, /record\.panel\.setAttribute\('inert', ''\)/);
    assert.match(editor, /record\.panel\.setAttribute\('aria-hidden', isClosed \? 'true' : 'false'\)/);
    assert.match(editor, /record\.launcher\.setAttribute\('aria-expanded', isClosed \? 'false' : 'true'\)/);
});
