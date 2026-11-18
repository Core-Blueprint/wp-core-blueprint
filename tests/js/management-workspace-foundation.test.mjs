import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

test('MW-02: public semantic asset boundaries and no consumer business logic', () => {
	const registry = read('src/Admin/PageRegistry.php');
	const catalog = read('src/Admin/AdminAssetCatalog.php');
	const assets = read('src/UI/Assets.php');
	const renderer = read('src/UI/RowActionMenu.php');
	for (const id of ['management-workspace', 'management-table', 'toolbars', 'row-action-menu']) {
		assert.ok(registry.includes("'" + id + "'"), id + ' must be a semantic requirement');
	}
	for (const method of ['enqueue_management_toolbar', 'enqueue_management_table', 'enqueue_management_workspace', 'enqueue_row_action_menu']) {
		assert.ok(assets.includes('function ' + method + '('), method + ' public API');
	}
	assert.match(catalog, /UiAssets::enqueue_management_toolbar\(\)/);
	assert.match(catalog, /UiAssets::enqueue_management_table\(\)/);
	assert.match(catalog, /UiAssets::enqueue_management_workspace\(\)/);
	assert.match(renderer, /esc_url/);
	assert.match(renderer, /esc_html/);
	assert.match(renderer, /type="submit"/);
	assert.doesNotMatch(renderer, /wp_remote_post|wpdb|check_ajax_referer|current_user_can/);
});

test('MW-02: token-optional management primitives keep scoped markup and accessible mobile handling', () => {
	const css = [
		read('assets/css/components/management-table.css'),
		read('assets/css/components/management-workspace.css'),
		read('assets/css/components/management-toolbar-native.css'),
		read('assets/css/components/row-action-menu.css'),
	];
	for (const source of css) {
		assert.doesNotMatch(source, /@import|@use|booking|contracts|jQuery/i);
		assert.match(source, /\.cb-core-/);
	}
	assert.match(css[0], /@media \(max-width: 782px\)/);
	assert.match(css[0], /__cell-label/);
	assert.match(css[2], /@media \(max-width: 480px\)/);
	assert.match(css[3], /position: fixed/);
	assert.match(css[3], /:focus-visible/);
	assert.match(read('assets/css/components/filter-bar.css'), /\.cb-core-toolbar/);
});

class Node {
	constructor(type) {
		this.type = type;
		this.children = [];
		this.parent = null;
		this.hidden = type === 'trigger' || type === 'panel';
		this.attrs = {};
		this.style = {left: '', top: '', removeProperty: key => { this.style[key] = ''; }};
		this.classes = new Set();
		this.classList = {add: x => this.classes.add(x), remove: x => this.classes.delete(x)};
		this.offsetWidth = 200;
		this.offsetHeight = 120;
		this.isConnected = true;
	}
	appendChild(node) {
		if (node.parent) node.parent.children = node.parent.children.filter(x => x !== node);
		this.children.push(node); node.parent = this; return node;
	}
	querySelector(selector) {
		const kind = selector.includes('trigger') ? 'trigger' : selector.includes('panel') ? 'panel' : '';
		return this.children.find(node => node.type === kind) ?? null;
	}
	querySelectorAll(selector) { return selector === '[role="menuitem"]' ? this.children.filter(x => x.type === 'item') : []; }
	setAttribute(name, value) { this.attrs[name] = value; }
	getAttribute(name) { return this.attrs[name] ?? null; }
	focus() { this.owner.activeElement = this; }
	contains(node) { return this === node || this.children.some(x => x.contains(node)); }
	closest(selector) {
		const kind = selector.includes('trigger') ? 'trigger' :
			selector.includes('[data-cb-core-row-menu]') ? 'root' :
			selector.includes('menuitem') ? 'item' : '';
		let node = this;
		while (node) { if (node.type === kind) return node; node = node.parent; }
		return null;
	}
	getBoundingClientRect() { return this.rect ?? {left: 620, right: 654, top: 100, bottom: 134}; }
}

function harness() {
	const handlers = new Map();
	const roots = [];
	const body = new Node('body');
	const document = {
		body, readyState: 'complete', activeElement: null,
		querySelectorAll: selector => selector === '[data-cb-core-row-menu]' ? roots : [],
		addEventListener: (name, cb) => { handlers.set(name, cb); },
	};
	const window = {
		innerWidth: 800, innerHeight: 600,
		addEventListener: (name, cb) => handlers.set('window:' + name, cb),
	};
	function row(y) {
		const root = new Node('root'), trigger = new Node('trigger'), panel = new Node('panel');
		trigger.rect = {left: 620, top: y, right: 654, bottom: y + 34};
		trigger.setAttribute('aria-expanded', 'false');
		const items = [new Node('item'), new Node('item')];
		for (const node of [root, trigger, panel, ...items]) node.owner = document;
		for (const item of items) panel.appendChild(item);
		root.appendChild(trigger); root.appendChild(panel); body.appendChild(root);
		roots.push(root);
		return {root, trigger, panel, items};
	}
	const first = row(520), second = row(150);
	runInNewContext(read('assets/js/core/row-action-menu.js'), {document, window}, {timeout: 1500});
	function event(name, target, key = '') {
		const ev = {target, key, prevented: false, preventDefault() { this.prevented = true; }};
		handlers.get(name)?.(ev);
		return ev;
	}
	return {document, body, first, second, event, handlers};
}

test('MW-02: portal is collision aware; Escape restores focus; only one menu is open', () => {
	const h = harness();
	const {first, second} = h;
	assert.equal(first.trigger.hidden, false);
	assert.equal(first.panel.hidden, true);
	h.event('click', first.trigger);
	assert.equal(first.panel.parent, h.body);
	assert.equal(first.trigger.attrs['aria-expanded'], 'true');
	assert.ok(parseInt(first.panel.style.top, 10) < 520, 'bottom-row menu opens above trigger');
	h.event('keydown', first.trigger, 'ArrowDown');
	assert.equal(h.document.activeElement, first.items[0]);
	h.event('keydown', first.items[0], 'ArrowDown');
	assert.equal(h.document.activeElement, first.items[1]);
	h.event('keydown', first.items[1], 'Escape');
	assert.equal(first.panel.hidden, true);
	assert.equal(first.panel.parent, first.root);
	assert.equal(h.document.activeElement, first.trigger);
	h.event('click', first.trigger);
	h.event('click', second.trigger);
	assert.equal(first.panel.hidden, true);
	assert.equal(second.panel.hidden, false);
	h.event('click', h.body);
	assert.equal(second.panel.hidden, true);
});

test('MW-02: no menu is available before enhancement and no requests are dispatched by Base', () => {
	const js = read('assets/js/core/row-action-menu.js');
	assert.match(js, /trigger\.hidden = false/);
	assert.match(js, /document\.body\.appendChild\(panel\)/);
	assert.match(js, /document\.addEventListener\('scroll'/);
	assert.doesNotMatch(js, /\bfetch\s*\(|XMLHttpRequest|FormData|\.submit\s*\(/);
});
