/**
 * Compact contextual formatting for inline Mail rich text.
 *
 * The toolbar only edits text content. Global styles remain in the Inspector.
 * This code executes within the same-origin authoring preview, never in sent mail.
 */
import { safeMailLink } from './rich-text-controls.js';

export const createMailInlineToolbar = ({ documentRef, target, onStatus = () => {} }) => {
	if (!documentRef?.createElement || !target) throw new TypeError('Inline toolbar requires the active preview document and text target.');
	const selection = () => documentRef.getSelection?.() || null;
	let retainedRange = null;
	const isInTarget = (range) => Boolean(range
		&& target.contains(range.commonAncestorContainer)
		&& String(range.toString?.() ?? '').trim());
	// Focus can move to a toolbar button before its click handler fires.
	// Retain only ranges proven to belong to the current editable block.
	const rememberSelection = () => {
		const current = selection();
		if (current?.rangeCount && !current.isCollapsed) {
			const range = current.getRangeAt(0);
			if (isInTarget(range)) retainedRange = range.cloneRange();
		} else if (documentRef.activeElement === target) {
			retainedRange = null;
		}
	};
	documentRef.addEventListener('selectionchange', rememberSelection);
	const selectedRange = () => {
		const current = selection();
		const live = current?.rangeCount && !current.isCollapsed ? current.getRangeAt(0) : null;
		if (isInTarget(live)) return live;
		return isInTarget(retainedRange) ? retainedRange.cloneRange() : null;
	};
	const toolbar = documentRef.createElement('div');
	toolbar.className = 'cb-mail-inline-format';
	toolbar.setAttribute('role', 'toolbar');
	toolbar.setAttribute('aria-label', 'Text formatting');
	const button = (text, label, callback) => {
		const control = documentRef.createElement('button');
		control.type = 'button';
		control.textContent = text;
		control.setAttribute('aria-label', label);
		// Keep the selected text active on toolbar interactions.
		control.addEventListener('pointerdown', (event) => event.preventDefault());
		control.addEventListener('click', callback);
		toolbar.append(control);
		return control;
	};
	const restoreSelection = (node) => {
		const current = selection();
		if (!current) return;
		current.removeAllRanges();
		const caret = documentRef.createRange();
		caret.selectNodeContents(node);
		caret.collapse(false);
		current.addRange(caret);
		retainedRange = null;
		target.focus();
	};
	const wrapSelection = (tag) => {
		const range = selectedRange();
		if (!range) {
			onStatus('Select some text first.');
			target.focus();
			return false;
		}
		const wrapper = documentRef.createElement(tag);
		wrapper.append(range.extractContents());
		range.insertNode(wrapper);
		restoreSelection(wrapper);
		return true;
	};
	// Only convert complete, consecutive top-level paragraphs. Partial
	// multi-paragraph selections are left intact to prevent invalid mail HTML.
	const paragraphsForRange = (range) => {
		const elementOf = (node) => node?.nodeType === 1 ? node : node?.parentElement;
		const first = elementOf(range.startContainer)?.closest?.('p');
		const last = elementOf(range.endContainer)?.closest?.('p');
		if (!first || !last || first.parentElement !== target || last.parentElement !== target) return null;
		const children = [...(target.children || [])];
		const from = children.indexOf(first);
		const to = children.indexOf(last);
		if (from < 0 || to < from) return null;
		const selected = children.slice(from, to + 1);
		if (selected.some((node) => node.tagName?.toLowerCase() !== 'p')) return null;
		const normalize = (value) => String(value || '').replace(/\\s+/g, '');
		if (!selected.length || normalize(range.toString()) !== normalize(selected.map((node) => node.textContent).join(''))) return null;
		return selected;
	};
	// Creating a list modifies only the selected content; no layout/typography
	// properties are ever changed by the contextual formatting toolbar.
	const listSelection = (type) => {
		const range = selectedRange();
		if (!range) {
			onStatus('Select the text to place in a list.');
			target.focus();
			return false;
		}
		const startNode = range.startContainer?.nodeType === 1
			? range.startContainer : range.startContainer?.parentElement;
		const paragraph = startNode?.closest?.('p');
		if (paragraph && target.contains(paragraph)) {
			const selected = paragraphsForRange(range);
			if (!selected) {
				onStatus('Select complete paragraphs to make a list.');
				return false;
			}
			const list = documentRef.createElement(type);
			const items = [];
			for (const entry of selected) {
				const item = documentRef.createElement('li');
				while (entry.firstChild) item.append(entry.firstChild);
				list.append(item);
				items.push(item);
			}
			selected[0].replaceWith(list);
			for (const entry of selected.slice(1)) entry.remove();
			restoreSelection(items[items.length - 1]);
			return true;
		}
		const startListItem = startNode?.closest?.('li');
		if (startListItem && target.contains(startListItem)) {
			onStatus('Finish this list in the Inspector before changing its type.');
			return false;
		}
		const list = documentRef.createElement(type);
		const item = documentRef.createElement('li');
		item.append(range.extractContents());
		list.append(item);
		range.insertNode(list);
		restoreSelection(item);
		return true;
	};
	button('B', 'Bold selected text', () => wrapSelection('strong'));
	button('I', 'Italicize selected text', () => wrapSelection('em'));
	button('U', 'Underline selected text', () => wrapSelection('u'));
	button('• List', 'Create bulleted list from selected text', () => listSelection('ul'));
	button('1. List', 'Create numbered list from selected text', () => listSelection('ol'));

	const linkPanel = documentRef.createElement('div');
	linkPanel.className = 'cb-mail-inline-format__link';
	linkPanel.hidden = true;
	const linkLabel = documentRef.createElement('label');
	linkLabel.textContent = 'Link address';
	const linkInput = documentRef.createElement('input');
	linkInput.type = 'url';
	linkInput.placeholder = 'https://example.org';
	linkInput.setAttribute('aria-label', 'Link address');
	linkLabel.append(linkInput);
	linkPanel.append(linkLabel);
	const linkStatus = documentRef.createElement('span');
	linkStatus.setAttribute('role', 'status');
	let linkRange = null;
	const openLinkEditor = () => {
		linkRange = selectedRange()?.cloneRange() || null;
		if (!linkRange) {
			onStatus('Select some text first.');
			return false;
		}
		linkStatus.textContent = '';
		linkPanel.hidden = false;
		linkInput.focus();
		return true;
	};
	button('Link', 'Link selected text', openLinkEditor);
	const apply = documentRef.createElement('button');
	apply.type = 'button';
	apply.textContent = 'Apply';
	apply.addEventListener('pointerdown', (event) => event.preventDefault());
	const cancel = documentRef.createElement('button');
	cancel.type = 'button';
	cancel.textContent = 'Cancel';
	cancel.addEventListener('pointerdown', (event) => event.preventDefault());
	const closeLink = () => {
		linkRange = null;
		linkInput.value = '';
		linkPanel.hidden = true;
		target.focus();
	};
	apply.addEventListener('click', () => {
		const url = safeMailLink(linkInput.value);
		if (!url) {
			linkStatus.textContent = 'Use a valid HTTPS, HTTP or email address.';
			return;
		}
		if (!linkRange || !target.contains(linkRange.commonAncestorContainer)) {
			closeLink();
			return;
		}
		const anchor = documentRef.createElement('a');
		anchor.setAttribute('href', url);
		anchor.append(linkRange.extractContents());
		linkRange.insertNode(anchor);
		closeLink();
		restoreSelection(anchor);
	});
	cancel.addEventListener('click', closeLink);
	linkInput.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			closeLink();
		} else if (event.key === 'Enter') {
			event.preventDefault();
			apply.click();
		}
	});
	linkPanel.append(apply, cancel, linkStatus);
	toolbar.append(linkPanel);

	const styleId = 'cb-mail-inline-format-styles';
	if (!documentRef.getElementById(styleId)) {
		const styles = documentRef.createElement('style');
		styles.id = styleId;
		styles.textContent = [
			'.cb-mail-inline-format{position:fixed;z-index:2000;display:flex;align-items:center;flex-wrap:wrap;gap:4px;max-width:calc(100vw - 16px);padding:6px;background:#1f2937;color:#fff;border:1px solid #334155;border-radius:6px;box-shadow:0 4px 14px #0003;font:13px/1.4 Arial,sans-serif}',
			'.cb-mail-inline-format button{border:1px solid #475569;background:#334155;color:#fff;border-radius:4px;cursor:pointer;min-height:30px;padding:4px 9px;font:inherit}',
			'.cb-mail-inline-format button:focus-visible{outline:2px solid #fff;outline-offset:2px}',
			'.cb-mail-inline-format__link{display:flex;align-items:center;gap:5px;flex-wrap:wrap;width:100%}',
			'.cb-mail-inline-format__link[hidden]{display:none!important}',
			'.cb-mail-inline-format__link label{display:flex;gap:4px;align-items:center}',
			'.cb-mail-inline-format__link input{max-width:180px;min-height:30px;border-radius:4px}',
		].join('');
		documentRef.head.append(styles);
	}
	documentRef.body.append(toolbar);
	const reposition = () => {
		const box = target.getBoundingClientRect();
		const height = toolbar.getBoundingClientRect().height || 44;
		const viewport = documentRef.documentElement.clientWidth || 600;
		toolbar.style.left = Math.max(8, Math.min(Math.round(box.left), viewport - toolbar.offsetWidth - 8)) + 'px';
		toolbar.style.top = Math.max(8, box.top > height + 12 ? box.top - height - 8 : box.bottom + 8) + 'px';
	};
	reposition();
	return {
		element: toolbar,
		contains: (node) => Boolean(node && toolbar.contains(node)),
		reposition,
		applyShortcut: (event) => {
			if (!(event?.ctrlKey || event?.metaKey) || event.altKey) return false;
			const key = String(event.key || '').toLowerCase();
			if (key === 'b') return wrapSelection('strong');
			if (key === 'i') return wrapSelection('em');
			if (key === 'u') return wrapSelection('u');
			if (key === 'k') return openLinkEditor();
			return false;
		},
		destroy: () => {
			documentRef.removeEventListener('selectionchange', rememberSelection);
			retainedRange = null;
			toolbar.remove();
		},
	};
};
