/**
 * Progressive inline Mail copy editor for the Base-owned preview iframe.
 *
 * One click selects a node; double-click enters text editing. Global styling,
 * sizing, links on buttons and responsive settings stay in the Inspector.
 * Session commands are the only persistence/undo boundary.
 */
import { serializeMailMarkup } from './rich-text-controls.js';
import { createMailInlineToolbar } from './inline-toolbar.js';

const EDITABLE_PROPERTIES = Object.freeze({
	'mail.heading': 'text',
	'mail.text': 'text',
	'mail.rich-text': 'markup',
});
const MAX_MAIL_TEXT = 100000;
// Unknown or malformed tokens are also protected. Preview values cannot be reverse-interpolated.
const HAS_DYNAMIC_BINDING = /\{\{|\}\}/;

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
	const attachedDocuments = new WeakSet();

	const finish = (save = true) => {
		const active = editing;
		if (!active) return false;
		let next = active.original;
		if (save) {
			try {
				next = active.property === 'markup'
					? serializeMailMarkup(active.target, active.target.ownerDocument)
					: String(active.target.innerText ?? active.target.textContent ?? '').replace(/\r\n?/g, '\n');
			} catch {
				onStatus('The edited text could not be read. Your changes are still available for correction.');
				return false;
			}
			if (next.length > MAX_MAIL_TEXT) {
				onStatus('Text exceeds the allowed length. Shorten it before finishing.');
				return false;
			}
			// Keep editing active while Base commits. This postpones preview
			// replacement until after the canonical undoable session command.
			if (next !== active.original) {
				try {
					executeProperty(active.path, active.property, next);
				} catch {
					onStatus('The edited text could not be saved to this session. Your changes are still in the editor.');
					return false;
				}
			}
		}
		editing = null;
		active.toolbar?.destroy();
		active.target.removeAttribute('contenteditable');
		active.target.removeAttribute('role');
		active.target.removeAttribute('aria-multiline');
		delete active.target.dataset.cbMailInlineEditing;
		active.marker.removeAttribute('data-cb-mail-inline-active');
		if (!save) active.target.innerHTML = active.originalHtml;
		onEditingChange(false);
		onStatus(!save ? 'Text changes discarded.' : (
			next !== active.original ? 'Text updated. Save the template to keep your changes.' : 'Text editing finished.'
		));
		return true;
	};

	const start = (marker) => {
		const path = pathFromMarker(marker);
		if (!path) return false;
		if (editing) {
			if (editing.path.length === path.length && editing.path.every((value, i) => value === path[i])) return true;
			// A failed edit must remain available to correct; never silently
			// throw it away when another node is selected.
			if (!finish(true)) return false;
		}
		const node = nodeAtPath(session.project(), path);
		const property = inlineMailProperty(node);
		if (!property) return false;
		if (!inlineMailIsSafe(node)) {
			onStatus('This text contains dynamic values. Edit its source in the Inspector to preserve bindings.');
			return false;
		}
		const target = targetForMarker(marker, node.type);
		if (!target) return false;
		select(path);

		editing = {
			marker,
			target,
			path,
			property,
			original: node.properties[property],
			originalHtml: target.innerHTML,
		};
		target.setAttribute('contenteditable', 'true');
		target.setAttribute('role', 'textbox');
		target.setAttribute('aria-multiline', property === 'text' && node.type !== 'mail.heading' ? 'true' : 'false');
		target.dataset.cbMailInlineEditing = 'true';
		marker.setAttribute('data-cb-mail-inline-active', 'true');
		if (property === 'markup') {
			editing.toolbar = createMailInlineToolbar({
				documentRef: target.ownerDocument,
				target,
				onStatus,
			});
		}
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
		target.addEventListener('blur', (event) => {
			if (editing?.target !== target) return;
			// Moving focus to the inline Link field is still part of authoring.
			if (editing.toolbar?.contains(event.relatedTarget)) return;
			finish(true);
		});
		editing.toolbar?.element.addEventListener('focusout', (event) => {
			if (editing?.target !== target) return;
			if (editing.toolbar?.contains(event.relatedTarget) || event.relatedTarget === target) return;
			finish(true);
		});
		target.focus();
		return true;
	};

	const startSelected = (documentRef) => {
		if (editing || !documentRef) return false;
		const path = session.selection()?.primary;
		if (!Array.isArray(path)) return false;
		const marker = [...documentRef.querySelectorAll('[data-cb-mail-editor-node]')]
			.find((candidate) => {
				const candidatePath = pathFromMarker(candidate);
				return candidatePath?.length === path.length && candidatePath.every((item, i) => item === path[i]);
			});
		return marker ? start(marker) : false;
	};

	const attach = (documentRef) => {
		if (!documentRef || attachedDocuments.has(documentRef)) return;
		// Each preview iframe receives its own document and event handling.
		attachedDocuments.add(documentRef);
		documentRef.addEventListener('dblclick', (event) => {
			const target = event.target?.nodeType === 1 ? event.target : event.target?.parentElement;
			const marker = target?.closest?.('[data-cb-mail-editor-node]');
			if (!marker) return;
			if (start(marker)) {
				event.preventDefault();
				event.stopPropagation();
			}
		}, true);
		documentRef.addEventListener('keydown', (event) => {
			if (event.key !== 'F2' || editing) return;
			if (startSelected(documentRef)) {
				event.preventDefault();
				event.stopPropagation();
			}
		}, true);
	};
	return { attach, startSelected, finish, isEditing: () => Boolean(editing) };
};
