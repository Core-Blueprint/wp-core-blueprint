import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const read = relative => readFile(new URL(relative, import.meta.url), 'utf8');

const iconsSource = await read('../../assets/js/design/shell/icons.js');
const editorSource = await read('../../assets/js/design/editor.js');
const shellStyles = await read('../../assets/css/design/editor-shell.css');
const modeStyles = await read('../../assets/css/design/designer-mode.css');

class FakeNode {
    constructor(tag, doc) {
        this.tagName = tag;
        this.ownerDocument = doc;
        this.children = [];
        this.attributes = new Map();
        this.dataset = {};
        this.classNames = [];
        this.classList = { add: (...names) => this.classNames.push(...names) };
    }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = [...children]; }
    querySelector() { return null; }
}

const fakeDocument = {
    createElement(tag) { return new FakeNode(tag, this); },
    createElementNS(_namespace, tag) { return new FakeNode(tag, this); },
};

// The curated icon module has no imports. Loading its exact source in Node
// verifies the rendered SVG nodes, not merely a string key in the registry.
const icons = await import('data:text/javascript;base64,' +
    Buffer.from(iconsSource, 'utf8').toString('base64'));

test('FIELD-06B: canonical Lucide panel-left and panel-right render recognizable SVG geometry', () => {
    for (const [name, divider] of [['panel-left', 'M9 3v18'], ['panel-right', 'M15 3v18']]) {
        assert.ok(icons.DESIGNER_ICON_NAMES.includes(name), name + ' must be registered');
        const icon = icons.createDesignerIcon(name, fakeDocument);
        assert.ok(icon);
        assert.equal(icon.dataset.cbDesignShellLucideIcon, name);
        assert.equal(icon.getAttribute('aria-hidden'), 'true');

        const svg = icon.children[0];
        assert.equal(svg.tagName, 'svg');
        assert.ok(svg.classNames.includes('lucide-' + name));
        assert.equal(svg.getAttribute('viewBox'), '0 0 24 24');
        const [frame, separator] = svg.children;
        assert.equal(frame.tagName, 'rect');
        assert.equal(frame.getAttribute('x'), '3');
        assert.equal(frame.getAttribute('width'), '18');
        assert.equal(separator.tagName, 'path');
        assert.equal(separator.getAttribute('d'), divider);
    }
});

test('FIELD-06B: each native drawer launcher uses one fixed icon in either state', () => {
    assert.match(editorSource,
        /decorateDesignerControl\(leftLauncher, 'panel-left', \{iconOnly: true, label: 'Open Elements and Layers'\}\)/);
    assert.match(editorSource,
        /decorateDesignerControl\(rightLauncher, 'panel-right', \{iconOnly: true, label: 'Open Inspector and Settings'\}\)/);
    assert.match(editorSource, /record\.launcher\.setAttribute\('aria-expanded', isClosed \? 'false' : 'true'\)/);
    assert.doesNotMatch(editorSource, /decorateDesignerControl\(record\.launcher,/,
        'The launcher icon must not switch during opening/closing');
    assert.match(modeStyles, /@media \(max-width: 1280px\)[\s\S]*\.cb-core-design-shell__drawer-launcher\s*\{\s*display: inline-flex !important;/);
});

test('FIELD-06B: open drawer uses exactly the existing toolbar active treatment', () => {
    const marker = '.cb-core-design-shell__toolbar--designer ' +
        '.cb-core-design-shell__drawer-launcher[aria-expanded="true"]';
    const active = shellStyles.indexOf(marker);
    assert.ok(active !== -1, 'Expanded drawer must join existing active selector group');
    const start = shellStyles.lastIndexOf('.cb-core-design-shell__toolbar-group--viewport', active);
    const end = shellStyles.indexOf('}', active);
    assert.ok(start !== -1 && end > active);
    const rule = shellStyles.slice(start, end);
    assert.match(rule, /\.cb-core-design-shell__icon-button\[aria-pressed="true"\]/);
    assert.match(rule, /border-color: var\(--cb-accent\) !important;/);
    assert.match(rule, /box-shadow: inset 0 0 0 1px var\(--cb-accent\);/);
    assert.doesNotMatch(shellStyles, /drawer-launcher\[aria-pressed="true"\]/,
        'Expandable drawer buttons must keep aria-expanded rather than aria-pressed');
});
