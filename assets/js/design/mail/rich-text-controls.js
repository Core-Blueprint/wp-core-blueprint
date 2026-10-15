/**
 * Beginner-first formatting surface for the Base Mail profile.
 * A controlled HTML subset is shared by Visual and Advanced source views.
 * Server-side Mail RichText remains authoritative on save and rendering.
 */
import { convertMailSelectionToList } from './list-format.js';

export const MAIL_INLINE_TAGS = Object.freeze(['STRONG', 'EM', 'U']);
const ALLOWED_TAGS = new Set([
	'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'A',
]);
const BLOCKED_TAGS = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM']);
const MAX_MARKUP_LENGTH = 100000;

export const safeMailLink = (raw) => {
	const url = String(raw || '').trim();
	if (!url || /[\u0000-\u001f\u007f]/.test(url)) return '';
	try {
		const parsed = new URL(url);
		if (parsed.protocol === 'https:' || parsed.protocol === 'http:') {
			return parsed.hostname ? url : '';
		}
		if (parsed.protocol === 'mailto:') {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(parsed.pathname) ? url : '';
		}
	} catch {
		return '';
	}
	return '';
};

export const sanitizeMailMarkup = (source, documentRef) => {
	const markup = String(source ?? '');
	if (markup.length > MAX_MARKUP_LENGTH) throw new RangeError('Mail content is too long.');
	// HTMLTemplateElement is inert: parsing source must not trigger remote
	// image/iframe resource loads or execute scripts before sanitization.
	const template = documentRef.createElement('template');
	template.innerHTML = markup;
	const fragment = documentRef.createDocumentFragment();

	const appendSafe = (target, original) => {
		if (original.nodeType === 3) {
			target.append(documentRef.createTextNode(original.nodeValue || ''));
			return;
		}
		if (original.nodeType !== 1) return;
		const tag = original.tagName.toUpperCase();
		if (BLOCKED_TAGS.has(tag)) return;
		if (!ALLOWED_TAGS.has(tag)) {
			for (const child of [...original.childNodes]) appendSafe(target, child);
			return;
		}
		const node = documentRef.createElement(tag.toLowerCase());
		if (tag === 'A') {
			const href = safeMailLink(original.getAttribute('href'));
			if (href) node.setAttribute('href', href);
			const title = original.getAttribute('title');
			if (title) node.setAttribute('title', title.slice(0, 255));
		}
		for (const child of [...original.childNodes]) appendSafe(node, child);
		target.append(node);
	};
	for (const child of [...template.content.childNodes]) appendSafe(fragment, child);
	return fragment;
};

export const serializeMailMarkup = (element, documentRef) => {
	const container = documentRef.createElement('div');
	// Sanitize a clone, not the live editable surface or its user selection.
	const fragment = sanitizeMailMarkup(element.innerHTML, documentRef);
	container.append(fragment);
	return container.innerHTML;
};

const addButton = (documentRef, parent, text, title, onPress) => {
	const button = documentRef.createElement('button');
	button.type = 'button';
	button.className = 'button cb-core-button';
	button.textContent = text;
	button.title = title;
	button.setAttribute('aria-label', title);
	// Preserve the text selection while the toolbar is clicked.
	button.addEventListener('pointerdown', (event) => event.preventDefault());
	button.addEventListener('click', onPress);
	parent.append(button);
	return button;
};

export const createMailRichTextControls = ({
	documentRef,
	value = '',
	onCommit,
}) => {
	if (!documentRef?.createElement || typeof onCommit !== 'function') {
		throw new TypeError('Mail rich-text controls require a document and an onCommit callback.');
	}
	const container = documentRef.createElement('div');
	container.className = 'cb-mail-rich-text-controls';
	const toolbar = documentRef.createElement('div');
	toolbar.className = 'cb-mail-rich-text-controls__toolbar';
	toolbar.setAttribute('role', 'toolbar');
	toolbar.setAttribute('aria-label', 'Text formatting');

	const editor = documentRef.createElement('div');
	editor.className = 'cb-mail-rich-text-controls__editor';
	editor.contentEditable = 'true';
	editor.setAttribute('role', 'textbox');
	editor.setAttribute('aria-label', 'Formatted email content');
	editor.setAttribute('aria-multiline', 'true');
	editor.setAttribute('spellcheck', 'true');

	const advanced = documentRef.createElement('details');
	advanced.className = 'cb-mail-rich-text-controls__advanced';
	const summary = documentRef.createElement('summary');
	summary.textContent = 'Advanced: HTML source';
	const source = documentRef.createElement('textarea');
	source.rows = 6;
	source.className = 'large-text code';
	source.setAttribute('aria-label', 'Allowed HTML source');
	const note = documentRef.createElement('p');
	note.className = 'description';
	note.textContent = 'Only paragraphs, basic formatting, lists and safe links are supported. Scripts, styles and embedded content are removed.';

	const setVisual = (markup) => {
		editor.replaceChildren(sanitizeMailMarkup(markup, documentRef));
		source.value = serializeMailMarkup(editor, documentRef);
	};
	setVisual(value);

	const selectedRange = () => {
		const selection = documentRef.getSelection?.();
		if (!selection?.rangeCount || selection.isCollapsed) return null;
		const range = selection.getRangeAt(0);
		return editor.contains(range.commonAncestorContainer) ? range : null;
	};
	const focusEditor = () => editor.focus();
	const wrapSelection = (tag) => {
		const range = selectedRange();
		if (!range) return;
		const wrap = documentRef.createElement(tag);
		wrap.append(range.extractContents());
		range.insertNode(wrap);
		const selection = documentRef.getSelection();
		selection?.removeAllRanges();
		const after = documentRef.createRange();
		after.selectNodeContents(wrap);
		after.collapse(false);
		selection?.addRange(after);
		focusEditor();
	};
	const changeList = (tag) => {
		const range = selectedRange();
		if (!range) return;
		const item = convertMailSelectionToList({
			documentRef,
			target: editor,
			range,
			type: tag,
			onStatus: (message) => {
				note.textContent = message;
				note.setAttribute('role', 'status');
			},
		});
		if (item) focusEditor();
	};

	addButton(documentRef, toolbar, 'B', 'Bold selected text', () => wrapSelection('strong'));
	addButton(documentRef, toolbar, 'I', 'Italicize selected text', () => wrapSelection('em'));
	addButton(documentRef, toolbar, 'U', 'Underline selected text', () => wrapSelection('u'));
	addButton(documentRef, toolbar, '• List', 'Create a bulleted list', () => changeList('ul'));
	addButton(documentRef, toolbar, '1. List', 'Create a numbered list', () => changeList('ol'));

	const linkRow = documentRef.createElement('div');
	linkRow.className = 'cb-mail-rich-text-controls__link';
	linkRow.hidden = true;
	const linkLabel = documentRef.createElement('label');
	linkLabel.textContent = 'Link address';
	const linkField = documentRef.createElement('input');
	linkField.type = 'url';
	linkField.placeholder = 'https://example.org';
	linkField.setAttribute('aria-label', 'Link address');
	const linkError = documentRef.createElement('span');
	linkError.setAttribute('role', 'status');
	linkLabel.append(linkField);
	linkRow.append(linkLabel);
	let savedLinkRange = null;
	addButton(documentRef, toolbar, 'Link', 'Link selected text', () => {
		savedLinkRange = selectedRange()?.cloneRange() || null;
		if (!savedLinkRange) return;
		linkError.textContent = '';
		linkRow.hidden = false;
		linkField.focus();
	});
	addButton(documentRef, linkRow, 'Apply link', 'Apply the entered link', () => {
		const href = safeMailLink(linkField.value);
		if (!href) {
			linkError.textContent = 'Enter a valid https, http or mailto address.';
			return;
		}
		if (!savedLinkRange || !editor.contains(savedLinkRange.commonAncestorContainer)) return;
		const anchor = documentRef.createElement('a');
		anchor.href = href;
		anchor.append(savedLinkRange.extractContents());
		savedLinkRange.insertNode(anchor);
		savedLinkRange = null;
		linkRow.hidden = true;
		focusEditor();
	});
	addButton(documentRef, linkRow, 'Cancel', 'Cancel adding a link', () => {
		savedLinkRange = null;
		linkRow.hidden = true;
		focusEditor();
	});
	linkRow.append(linkError);

	// Word, web pages and other mail editors can contain unsafe pasted markup.
	// Accept text only and let the author deliberately add supported formatting.
	editor.addEventListener('paste', (event) => {
		event.preventDefault();
		const text = event.clipboardData?.getData('text/plain') || '';
		const selection = documentRef.getSelection?.();
		if (!selection?.rangeCount || !editor.contains(selection.anchorNode)) return;
		const range = selection.getRangeAt(0);
		range.deleteContents();
		range.insertNode(documentRef.createTextNode(text));
		range.collapse(false);
	});
	editor.addEventListener('drop', (event) => event.preventDefault());

	const commit = () => {
		let clean;
		try {
			clean = serializeMailMarkup(editor, documentRef);
			if (clean !== source.value) source.value = clean;
			if (clean === String(value ?? '')) return true;
			// Keep both visual and Advanced source data until the canonical Base
			// session accepts the change. Never mark failed Inspector commits done.
			if (onCommit(clean) === false) throw new Error('The current edit could not be applied.');
			value = clean;
			return true;
		} catch {
			note.textContent = 'Unable to apply your changes. Your text is still in this field; correct it and try again.';
			note.setAttribute('role', 'alert');
			return false;
		}
	};
	editor.addEventListener('focusout', (event) => {
		if (!container.contains(event.relatedTarget)) commit();
	});
	advanced.append(summary, source, note);
	advanced.addEventListener('toggle', () => {
		if (advanced.open) {
			source.value = serializeMailMarkup(editor, documentRef);
		} else {
			setVisual(source.value);
			commit();
		}
	});
	source.addEventListener('change', () => {
		setVisual(source.value);
		commit();
	});

	container.append(toolbar, linkRow, editor, advanced);
	return { element: container, commit, editor };
};
