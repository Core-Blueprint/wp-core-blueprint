import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import {
	configureDesignerDocumentView, DESIGNER_DOCUMENT_VIEW_EVENT,
} from '../../assets/js/design/shell/document-view.js';

const source = path => readFile(new URL(path, import.meta.url), 'utf8');

class FakeButton {
	constructor() { this.attributes = {}; this.events = new Map(); this.classList = { toggle() {} }; }
	setAttribute(key, value) { this.attributes[key] = value; }
	addEventListener(type, listener) { this.events.set(type, listener); }
	removeEventListener(type, listener) { if (this.events.get(type) === listener) this.events.delete(type); }
	click() { this.events.get('click')?.(); }
}
class FakeRoot {
	constructor() {
		this.dataset = {};
		this.button = new FakeButton();
		this.events = [];
	}
	querySelector(selector) { return selector === '[data-cb-design-shell-document-view-toggle]' ? this.button : null; }
	dispatchEvent(event) { this.events.push(event); return true; }
}
test('document view toolbar toggles fit/fixed without write, reports event mode and disposes listeners', () => {
	const root = new FakeRoot();
	const previousElement = globalThis.Element;
	const previousEvent = globalThis.CustomEvent;
	globalThis.Element = FakeRoot;
	globalThis.CustomEvent = class {
		constructor(type, options) { this.type = type; this.detail = options.detail; }
	};
	try {
	const abort = new AbortController();
	const controller = configureDesignerDocumentView(root, {mode: 'fit', signal: abort.signal});
	assert.equal(controller.mode, 'fit');
	assert.equal(root.dataset.cbDesignShellDocumentView, 'fit');
	assert.equal(root.button.attributes['aria-pressed'], 'true');
	assert.equal(root.button.attributes['aria-label'], 'Switch to fixed document width');
	assert.equal(root.events.length, 0);
	root.button.click();
	assert.equal(controller.mode, 'fixed');
	assert.equal(root.button.attributes['aria-pressed'], 'false');
	assert.equal(root.button.attributes.title, 'Fit document to canvas');
	assert.equal(root.events.at(-1).type, DESIGNER_DOCUMENT_VIEW_EVENT);
	assert.equal(root.events.at(-1).detail.mode, 'fixed');
	root.button.click();
	assert.equal(controller.mode, 'fit');
	assert.equal(root.events.length, 2);
	assert.equal(controller.setMode('untrusted'), false);
	abort.abort();
	root.button.click();
	assert.equal(controller.mode, 'fit');
	assert.equal(root.events.length, 2);
	} finally {
		if (previousElement === undefined) delete globalThis.Element;
		else globalThis.Element = previousElement;
		if (previousEvent === undefined) delete globalThis.CustomEvent;
		else globalThis.CustomEvent = previousEvent;
	}
});

test('native toolbar is profile-specific; Mail keeps viewports, Reports has document icon and no fake pages', async () => {
	const editor = await source('../../assets/js/design/editor.js');
	const icons = await source('../../assets/js/design/shell/icons.js');
	const css = await source('../../assets/css/design/platform-fullscreen.css');
	assert.match(editor, /if \(profileId === 'base\/mail'\) \{[\s\S]*?data-cb-design-shell-viewport-group/);
	assert.match(editor, /data-cb-design-shell-document-view-toggle/);
	assert.match(editor, /decorateDesignerControl\(documentButton, 'file-text'/);
	assert.match(editor, /activeRegistry\.profileId === 'base\/mail'[\s\S]*?configureDesignerViewports\(stagedRoot/);
	assert.match(editor, /activeRegistry\.profileId === 'base\/reports'[\s\S]*?configureDesignerDocumentView\(stagedRoot/);
	assert.match(editor, /configureDocumentView: configureDesignerDocumentView/);
	assert.match(icons, /'file-text': Object\.freeze/);
	assert.match(css, /data-cb-design-shell-document-view="fixed"[^\{]*\.cb-reports-platform__preview-frame\s*\{\s*width: 860px;\s*max-width: none;/);
	assert.doesNotMatch(editor, /data-cb-design-shell-page-count|data-cb-design-shell-page-input/);
});
