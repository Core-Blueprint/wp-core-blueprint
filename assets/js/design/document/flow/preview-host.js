(function (global) {
	'use strict';

	var PROTOCOL = 'cb-flow-preview-v1';
	var MAX_HEIGHT = 100000;
	var BRIDGE_HASH = 'sha256-0sZcoribBRWR5qZ/6zmLFnkQ04dRR81sc94w6ib7Abw=';
	var BRIDGE_SCRIPT = [
		"(function () {",
		"\t'use strict';",
		"",
		"\tvar PROTOCOL = 'cb-flow-preview-v1';",
		"\tvar generation = null;",
		"\tvar token = null;",
		"\tvar observer = null;",
		"\tvar scheduled = false;",
		"\tvar lastHeight = 0;",
		"",
		"\tfunction measure() {",
		"\t\tvar root;",
		"\t\tvar body;",
		"\t\tvar page;",
		"\t\tvar height;",
		"",
		"\t\tscheduled = false;",
		"",
		"\t\tif (generation === null || token === null) {",
		"\t\t\treturn;",
		"\t\t}",
		"",
		"\t\troot = document.documentElement;",
		"\t\tbody = document.body;",
		"\t\tpage = document.querySelector('.cb-flow-preview-page');",
		"\t\theight = Math.ceil(Math.max(",
		"\t\t\troot ? root.scrollHeight : 0,",
		"\t\t\troot ? root.offsetHeight : 0,",
		"\t\t\tbody ? body.scrollHeight : 0,",
		"\t\t\tbody ? body.offsetHeight : 0,",
		"\t\t\tpage ? page.scrollHeight : 0,",
		"\t\t\tpage ? page.offsetHeight : 0",
		"\t\t));",
		"",
		"\t\tif (!Number.isFinite(height) || height < 1 || height === lastHeight) {",
		"\t\t\treturn;",
		"\t\t}",
		"",
		"\t\tlastHeight = height;",
		"\t\tparent.postMessage({",
		"\t\t\tprotocol: PROTOCOL,",
		"\t\t\ttype: 'height',",
		"\t\t\tgeneration: generation,",
		"\t\t\ttoken: token,",
		"\t\t\theight: height",
		"\t\t}, '*');",
		"\t}",
		"",
		"\tfunction schedule() {",
		"\t\tif (scheduled) {",
		"\t\t\treturn;",
		"\t\t}",
		"",
		"\t\tscheduled = true;",
		"\t\trequestAnimationFrame(measure);",
		"\t}",
		"",
		"\tfunction observe() {",
		"\t\tvar page;",
		"",
		"\t\tif (!observer && 'ResizeObserver' in window) {",
		"\t\t\tobserver = new ResizeObserver(schedule);",
		"\t\t\tif (document.documentElement) {",
		"\t\t\t\tobserver.observe(document.documentElement);",
		"\t\t\t}",
		"\t\t\tif (document.body) {",
		"\t\t\t\tobserver.observe(document.body);",
		"\t\t\t}",
		"",
		"\t\t\tpage = document.querySelector('.cb-flow-preview-page');",
		"\t\t\tif (page) {",
		"\t\t\t\tobserver.observe(page);",
		"\t\t\t}",
		"\t\t}",
		"",
		"\t\tschedule();",
		"\t}",
		"",
		"\twindow.addEventListener('message', function (event) {",
		"\t\tvar data = event.data;",
		"",
		"\t\tif (",
		"\t\t\tevent.source !== parent",
		"\t\t\t|| !data",
		"\t\t\t|| data.protocol !== PROTOCOL",
		"\t\t\t|| data.type !== 'init'",
		"\t\t\t|| !Number.isInteger(data.generation)",
		"\t\t\t|| data.generation < 1",
		"\t\t\t|| typeof data.token !== 'string'",
		"\t\t\t|| data.token.length < 16",
		"\t\t\t|| data.token.length > 128",
		"\t\t) {",
		"\t\t\treturn;",
		"\t\t}",
		"",
		"\t\tgeneration = data.generation;",
		"\t\ttoken = data.token;",
		"\t\tlastHeight = 0;",
		"\t\tobserve();",
		"\t});",
		"",
		"\twindow.addEventListener('load', schedule);",
		"}());"
	].join('\n');

	function randomToken() {
		var values;
		var token = '';
		var index;

		if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
			values = new Uint32Array(4);
			global.crypto.getRandomValues(values);
			for (index = 0; index < values.length; index += 1) {
				token += values[index].toString(16).padStart(8, '0');
			}
			return token;
		}

		return String(Date.now()) + '-' + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
	}

	function preparePreviewDocument(html) {
		var parser;
		var documentNode;
		var csp;
		var policy;
		var script;

		if (typeof html !== 'string' || html.trim() === '') {
			throw new TypeError('Flow preview HTML must be a non-empty string.');
		}
		if (typeof global.DOMParser !== 'function') {
			throw new Error('Flow preview host requires DOMParser.');
		}

		parser = new global.DOMParser();
		documentNode = parser.parseFromString(html, 'text/html');
		csp = documentNode.querySelector('meta[http-equiv="Content-Security-Policy"]');

		if (!csp) {
			throw new Error('Flow preview document must provide a Content-Security-Policy.');
		}

		policy = (csp.getAttribute('content') || '').trim();
		if (/(^|;)\s*script-src(?:\s|;|$)/i.test(policy)) {
			throw new Error('Flow preview document already defines script-src.');
		}
		if (policy !== '' && policy.charAt(policy.length - 1) !== ';') {
			policy += ';';
		}

		csp.setAttribute('content', (policy + " script-src '" + BRIDGE_HASH + "';").trim());

		script = documentNode.createElement('script');
		script.textContent = BRIDGE_SCRIPT;
		documentNode.body.appendChild(script);

		return '<!doctype html>\n' + documentNode.documentElement.outerHTML;
	}

	function createPreviewHost(frame) {
		var generation = 0;
		var token = null;
		var destroyed = false;

		if (!frame || String(frame.tagName).toUpperCase() !== 'IFRAME') {
			throw new TypeError('Flow preview host requires an iframe element.');
		}

		frame.setAttribute('data-cb-flow-preview-host', '');
		frame.setAttribute('sandbox', 'allow-scripts');
		frame.setAttribute('scrolling', 'no');
		frame.hidden = true;

		function postInit() {
			if (destroyed || token === null || !frame.contentWindow) {
				return;
			}

			frame.contentWindow.postMessage({
				protocol: PROTOCOL,
				type: 'init',
				generation: generation,
				token: token
			}, '*');
		}

		function handleMessage(event) {
			var data = event.data;
			var height;

			if (
				destroyed
				|| token === null
				|| event.source !== frame.contentWindow
				|| !data
				|| data.protocol !== PROTOCOL
				|| data.type !== 'height'
				|| data.generation !== generation
				|| data.token !== token
			) {
				return;
			}

			height = data.height;
			if (typeof height !== 'number' || !Number.isFinite(height) || height < 1 || height > MAX_HEIGHT) {
				return;
			}

			frame.style.height = Math.ceil(height) + 'px';
			frame.style.visibility = '';
			frame.removeAttribute('aria-busy');
		}

		frame.addEventListener('load', postInit);
		global.addEventListener('message', handleMessage);

		return Object.freeze({
			render: function (html) {
				if (destroyed) {
					throw new Error('Flow preview host has been destroyed.');
				}

				generation += 1;
				token = randomToken();
				frame.hidden = false;
				frame.setAttribute('aria-busy', 'true');
				frame.style.visibility = 'hidden';
				frame.style.height = '1px';
				frame.srcdoc = preparePreviewDocument(html);

				return generation;
			},
			clear: function () {
				if (destroyed) {
					return;
				}

				generation += 1;
				token = null;
				frame.hidden = true;
				frame.removeAttribute('aria-busy');
				frame.style.visibility = '';
				frame.style.height = '';
				frame.srcdoc = '';
			},
			destroy: function () {
				if (destroyed) {
					return;
				}

				destroyed = true;
				token = null;
				frame.removeEventListener('load', postInit);
				global.removeEventListener('message', handleMessage);
				frame.hidden = true;
				frame.removeAttribute('aria-busy');
				frame.style.visibility = '';
				frame.style.height = '';
				frame.srcdoc = '';
			}
		});
	}

	global.CBBase = global.CBBase || {};
	global.CBBase.Document = global.CBBase.Document || {};
	global.CBBase.Document.FlowPreview = Object.freeze({
		version: 1,
		createHost: createPreviewHost
	});
}(window));
