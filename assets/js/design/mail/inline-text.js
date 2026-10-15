/**
 * Progressive inline Mail copy editor for the Base-owned preview iframe.
 *
 * One click selects a node; double-click enters text editing. Global styling,
 * sizing, links on buttons and responsive settings stay in the Inspector.
 * Session commands are the only persistence/undo boundary.
 */
import { serializeMailMarkup } from './rich-text-controls.js';

const EDITABLE_PROPERTIES = Object.freeze({
	'mail.heading': 'text',
	'mail.text': 'text',
	'mail.rich-text': 'markup',
});
const MAX_MAIL_TEXT = 100000;
const HAS_DYNAMIC_BINDING = /\{\{\s*[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\s*\}\}/i;

export const inlineMailProperty = (node) => (
	node?.provider === 'core' ? EDITABLE_PROPERTIES[node?.type] || null : null
);

export const inlineMailIsSafe = (node) => {
	const property = inlineMailProperty(node);
	return Boolean(
		property
		&& typeof node.properties?.[property] === 'string'
		&& node.properties[property].length <= MAX_MAIL_TEXT
		// Preview contains substituted tokens, not the stored source.
		&& !HAS_DYNAMIC_BINDING.test(node.properties[property])
	);
};

const nodeAtPath = (project, path) => {
	let node = project?.root;
	for (const index of path) {
		if (!Number.isInteger(index) || index < 0 || !Array.isArray(node?.children)) return null;
		node = node.children[index];
	}
	return node;
};

const pathFromMarker = (marker) => {
	try {
		const path = JSON.parse(marker?.dataset?.cbMailPath || 'null');
		return Array.isArray(path) && path.length > 0 && path.every((item) => Number.isInteger(item) && item >= 0)
			? path : null;
	} catch {
		return null;
	}
};

const targetForMarker = (marker, type) => {
	if (type === 'mail.heading') return marker.querySelector('tr > td > div');
	if (type === 'mail.text' || type === 'mail.rich-text') return marker.querySelector('tr > td');
	return null;
};

export const createMailInlineTextController = ({
	session,
	executeProperty,
	select,
	onStatus = () => {},
	onEditingChange = () => {},
}) => {
	if (!session || typeof executeProperty !== 'function' || typeof select !== 'function') {
		throw new TypeError('Inline Mail editing requires an active Base session and canonical commands.');
	}

	let editing = null;

	const finish = (save = true) => {
		const active = editing;
		if (!active) return false;
		editing = null;
		active.target.removeAttribute('contenteditable');
		active.target.removeAttribute('role');
		active.target.removeAttribute('aria-multiline');
		delete active.target.dataset.cbMailInlineEditing;
		active.marker.removeAttribute('data-cb-mail-inline-active');

		if (!save) {
			// Restore only server-rendered original preview HTML (no user input).
			active.target.innerHTML = active.originalHtml;
			onEditingChange(false);
			onStatus('Text changes discarded.');
			return true;
		}

		let next;
		try {
			next = active.property === 'markup'
				? serializeMailMarkup(active.target, active.target.ownerDocument)
				: String(active.target.innerText ?? active.target.textContent ?? '').replace(/\r\n?/g, '\n');
		} catch {
			active.target.innerHTML = active.originalHtml;
			onEditingChange(false);
			onStatus('Unable to read edited text. Changes were discarded.');
			return false;
		}
		if (next.length > MAX_MAIL_TEXT) {
			active.target.innerHTML = active.originalHtml;
			onEditingChange(false);
			onStatus('Text exceeds the allowed length. Changes were discarded.');
			return false;
		}
		// Leave edit mode before dispatching the session change, because Base
		// can regenerate the Inspector and preview as a consequence.
		onEditingChange(false);
		if (next !== active.original) {
			executeProperty(active.path, active.property, next);
			onStatus('Text updated. Save the template to keep your changes.');
		}
		return true;
	};

	const start = (marker) => {
		const path = pathFromMarker(marker);
		if (!path) return false;
		const node = nodeAtPath(session.project(), path);
		const property = inlineMailProperty(node);
		if (!property) return false;
		if (!inlineMailIsSafe(node)) {
			onStatus('This text contains dynamic values. Edit its source in the Inspector to preserve bindings.');
			return false;
		}

		const target = targetForMarker(marker, node.type);
		if (!target) return false;
		if (editing) finish(true);
		select(path);

		editing = {
			marker,
			target,
			path,
			property,
			original: node.properties[property],
			originalHtml: target.innerHTML,
		};
		target.setAttribute('contenteditable', property === 'markup' ? 'true' : 'plaintext-only');
		target.setAttribute('role', 'textbox');
		target.setAttribute('aria-multiline', property === 'text' && node.type !== 'mail.heading' ? 'true' : 'false');
		target.dataset.cbMailInlineEditing = 'true';
		marker.setAttribute('data-cb-mail-inline-active', 'true');
		onEditingChange(true);
		onStatus('Editing text. Press Escape to discard, Ctrl+Enter to finish, or click outside to apply.');

		target.addEventListener('keydown', (event) => {
			if (!editing || editing.target !== target) return;
			if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				finish(false);
				return;
			}
			if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
				event.preventDefault();
				event.stopPropagation();
				finish(true);
				return;
			}
			if (event.key === 'Enter' && node.type === 'mail.heading') {
				event.preventDefault();
				event.stopPropagation();
				finish(true);
			}
		});
		target.addEventListener('paste', (event) => {
			if (!editing || editing.target !== target) return;
			event.preventDefault();
			const text = event.clipboardData?.getData('text/plain') || '';
			const selection = target.ownerDocument.getSelection?.();
			if (!selection?.rangeCount || !target.contains(selection.anchorNode)) return;
			const range = selection.getRangeAt(0);
			range.deleteContents();
			range.insertNode(target.ownerDocument.createTextNode(text));
			range.collapse(false);
		});
		target.addEventListener('drop', (event) => event.preventDefault());
		target.addEventListener('blur', () => {
			if (editing?.target === target) finish(true);
		});
		target.focus();
		return true;
	};

	const attach = (documentRef) => {
		if (!documentRef || documentRef.__cbMailInlineAttached) return;
		// The iframe is replaced on every preview refresh; attach to that
		// document only, not to the globally shared Designer Shell.
		Object.defineProperty(documentRef, '__cbMailInlineAttached', { value: true });
		documentRef.addEventListener('dblclick', (event) => {
			const target = event.target?.nodeType === 1 ? event.target : event.target?.parentElement;
			const marker = target?.closest?.('[data-cb-mail-editor-node]');
			if (!marker) return;
			if (start(marker)) {
				event.preventDefault();
				event.stopPropagation();
			}
		}, true);
	};
	return { attach, finish, isEditing: () => Boolean(editing) };
};
