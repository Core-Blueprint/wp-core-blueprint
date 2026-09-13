// Curated Lucide icon node data for Core Blueprint Designer chrome.
// Source: https://github.com/lucide-icons/lucide
//
// ISC License
// Copyright (c) 2026 Lucide Icons and Contributors
// Permission to use, copy, modify, and/or distribute this software for any
// purpose with or without fee is hereby granted, provided that the above
// copyright notice and this permission notice appear in all copies.
// THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
// WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
// MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY
// SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
// WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
// ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
// OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
//
// Some Lucide glyphs in this curated set are derived from Feather Icons.
// Copyright (c) 2013-present Cole Bemis
// MIT License: permission is hereby granted, free of charge, to any person
// obtaining a copy of this software and associated documentation files to
// deal in the Software without restriction, including without limitation
// the rights to use, copy, modify, merge, publish, distribute, sublicense,
// and/or sell copies of the Software, subject to inclusion of the copyright
// notice and this permission notice in all copies or substantial portions of
// the Software. THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
// EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
// MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.

const ICONS = Object.freeze({
	'undo-2': Object.freeze([
		['path', { d: 'M9 14 4 9l5-5' }],
		['path', { d: 'M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11' }],
	]),
	'redo-2': Object.freeze([
		['path', { d: 'm15 14 5-5-5-5' }],
		['path', { d: 'M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13' }],
	]),
	ellipsis: Object.freeze([
		['circle', { cx: '5', cy: '12', r: '1' }],
		['circle', { cx: '12', cy: '12', r: '1' }],
		['circle', { cx: '19', cy: '12', r: '1' }],
	]),
	smartphone: Object.freeze([
		['rect', { width: '14', height: '20', x: '5', y: '2', rx: '2', ry: '2' }],
		['path', { d: 'M12 18h.01' }],
	]),
	tablet: Object.freeze([
		['rect', { width: '16', height: '20', x: '4', y: '2', rx: '2', ry: '2' }],
		['line', { x1: '12', x2: '12.01', y1: '18', y2: '18' }],
	]),
	monitor: Object.freeze([
		['rect', { width: '20', height: '14', x: '2', y: '3', rx: '2' }],
		['line', { x1: '8', x2: '16', y1: '21', y2: '21' }],
		['line', { x1: '12', x2: '12', y1: '17', y2: '21' }],
	]),
	'maximize-2': Object.freeze([
		['path', { d: 'M15 3h6v6' }],
		['path', { d: 'm21 3-7 7' }],
		['path', { d: 'm3 21 7-7' }],
		['path', { d: 'M9 21H3v-6' }],
	]),
	'minimize-2': Object.freeze([
		['path', { d: 'm14 10 7-7' }],
		['path', { d: 'M20 10h-6V4' }],
		['path', { d: 'm3 21 7-7' }],
		['path', { d: 'M4 14h6v6' }],
	]),
	x: Object.freeze([
		['path', { d: 'M18 6 6 18' }],
		['path', { d: 'm6 6 12 12' }],
	]),
	'chevron-left': Object.freeze([
		['path', { d: 'm15 18-6-6 6-6' }],
	]),
	'chevron-right': Object.freeze([
		['path', { d: 'm9 18 6-6-6-6' }],
	]),
	save: Object.freeze([
		['path', { d: 'M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z' }],
		['path', { d: 'M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7' }],
		['path', { d: 'M7 3v4a1 1 0 0 0 1 1h7' }],
	]),
	'sliders-horizontal': Object.freeze([
		['path', { d: 'M10 5H3' }],
		['path', { d: 'M12 19H3' }],
		['path', { d: 'M14 3v4' }],
		['path', { d: 'M16 17v4' }],
		['path', { d: 'M21 12h-9' }],
		['path', { d: 'M21 19h-5' }],
		['path', { d: 'M21 5h-7' }],
		['path', { d: 'M8 10v4' }],
		['path', { d: 'M8 12H3' }],
	]),
	layers: Object.freeze([
		['path', { d: 'M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z' }],
		['path', { d: 'M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12' }],
		['path', { d: 'M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17' }],
	]),
	'settings-2': Object.freeze([
		['path', { d: 'M14 17H5' }],
		['path', { d: 'M19 7h-9' }],
		['circle', { cx: '17', cy: '17', r: '3' }],
		['circle', { cx: '7', cy: '7', r: '3' }],
	]),
});

export const DESIGNER_ICON_NAMES = Object.freeze(Object.keys(ICONS));

const svgElement = (documentRef, name) => {
	const node = documentRef.createElementNS('http://www.w3.org/2000/svg', name);
	return node;
};

export const createDesignerIcon = (name, documentRef = document) => {
	const definition = ICONS[String(name || '')];
	if (!definition || !documentRef?.createElementNS) return null;

	const wrapper = documentRef.createElement('span');
	wrapper.className = 'cb-core-design-shell__icon';
	wrapper.dataset.cbDesignShellLucideIcon = String(name);
	wrapper.setAttribute('aria-hidden', 'true');

	const svg = svgElement(documentRef, 'svg');
	svg.setAttribute('viewBox', '0 0 24 24');
	svg.setAttribute('fill', 'none');
	svg.setAttribute('stroke', 'currentColor');
	svg.setAttribute('stroke-width', '2');
	svg.setAttribute('stroke-linecap', 'round');
	svg.setAttribute('stroke-linejoin', 'round');
	svg.setAttribute('focusable', 'false');
	svg.classList.add('lucide', `lucide-${String(name)}`);

	definition.forEach(([tagName, attributes]) => {
		const child = svgElement(documentRef, tagName);
		Object.entries(attributes).forEach(([attribute, value]) => child.setAttribute(attribute, value));
		svg.append(child);
	});

	wrapper.append(svg);
	return wrapper;
};

export const decorateDesignerControl = (control, name, {
	iconOnly = false,
	label = null,
} = {}) => {
	if (!control) return false;
	const documentRef = control.ownerDocument || document;
	const icon = createDesignerIcon(name, documentRef);
	if (!icon) return false;

	const accessibleLabel = String(label || control.getAttribute?.('aria-label') || control.textContent || '').trim();
	control.querySelector?.('[data-cb-design-shell-lucide-icon]')?.remove?.();

	if (accessibleLabel) {
		control.setAttribute?.('aria-label', accessibleLabel);
		control.setAttribute?.('title', accessibleLabel);
	}

	if (iconOnly) {
		control.classList?.add('cb-core-design-shell__icon-button');
		control.replaceChildren?.(icon);
	} else {
		control.classList?.add('cb-core-design-shell__icon-label');
		control.prepend?.(icon);
	}
	return true;
};
