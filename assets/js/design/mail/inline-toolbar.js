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
	const selectedRange = () => {
		const current = selection();
		if (!current?.rangeCount || current.isCollapsed) return null;
		const range = current.getRangeAt(0);
		return target.contains(range.commonAncestorContainer) ? range : null;
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
	const wrapSelection = (tag) => {
		const range = selectedRange();
		if (!range) {
			onStatus('Select some text first.');
			target.focus();
			return;
		}
		const wrapper = documentRef.createElement(tag);
		wrapper.append(range.extractContents());
		range.insertNode(wrapper);
		const current = selection();
		current?.removeAllRanges();
		const caret = documentRef.createRange();
		caret.selectNodeContents(wrapper);
		caret.collapse(false);
		current?.addRange(caret);
		target.focus();
	};
	button('B', 'Bold selected text', () => wrapSelection('strong'));
	button('I', 'Italicize selected text', () => wrapSelection('em'));
	button('U', 'Underline selected text', () => wrapSelection('u'));

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
	button('Link', 'Link selected text', () => {
		linkRange = selectedRange()?.cloneRange() || null;
		if (!linkRange) {
			onStatus('Select some text first.');
			return;
		}
		linkStatus.textContent = '';
		linkPanel.hidden = false;
		linkInput.focus();
	});
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
		destroy: () => toolbar.remove(),
	};
};
