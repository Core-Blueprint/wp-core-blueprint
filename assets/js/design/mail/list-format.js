/**
 * Canonical Mail-only list operation shared by Canvas and Inspector.
 * Never insert lists inside paragraphs, list items or mixed-content blocks.
 * The Mail renderer accepts UL/OL/LI; it does not accept arbitrary HTML.
 */
export const convertMailSelectionToList = ({
	documentRef, target, range, type, onStatus = () => {},
}) => {
	if (!documentRef?.createElement || !target || !range || !['ul', 'ol'].includes(type)) return null;
	const selectedText = String(range.toString?.() || '').trim();
	if (!selectedText || !target.contains(range.commonAncestorContainer)) {
		onStatus('Select some text first.');
		return null;
	}
	const asElement = (node) => node?.nodeType === 1 ? node : node?.parentElement;
	const firstParagraph = asElement(range.startContainer)?.closest?.('p');
	const lastParagraph = asElement(range.endContainer)?.closest?.('p');
	if (firstParagraph || lastParagraph) {
		// Only contiguous, complete top-level paragraphs may be converted.
		if (!firstParagraph || !lastParagraph
			|| firstParagraph.parentElement !== target || lastParagraph.parentElement !== target) {
			onStatus('Select complete paragraphs to make a list.');
			return null;
		}
		const children = [...(target.children || [])];
		const from = children.indexOf(firstParagraph);
		const to = children.indexOf(lastParagraph);
		const entries = from >= 0 && to >= from ? children.slice(from, to + 1) : [];
		const normalize = (value) => String(value || '').replace(/\s+/g, '');
		if (!entries.length
			|| entries.some((entry) => entry.tagName?.toLowerCase() !== 'p')
			|| normalize(selectedText) !== normalize(entries.map((entry) => entry.textContent).join(''))) {
			onStatus('Select complete paragraphs to make a list.');
			return null;
		}
		const list = documentRef.createElement(type);
		let lastItem = null;
		for (const paragraph of entries) {
			const item = documentRef.createElement('li');
			while (paragraph.firstChild) item.append(paragraph.firstChild);
			list.append(item);
			lastItem = item;
		}
		entries[0].replaceWith(list);
		for (const paragraph of entries.slice(1)) paragraph.remove();
		return lastItem;
	}
	const ancestor = asElement(range.startContainer);
	const parentListItem = ancestor?.closest?.('li');
	if (parentListItem && target.contains(parentListItem)) {
		onStatus('Finish this list in the Inspector before changing its type.');
		return null;
	}
	// Bare inline copy directly in the text cell is supported, but do not
	// extract mixed nested/structural content in a mail-client layout.
	if (range.commonAncestorContainer !== target) {
		onStatus('Select complete paragraphs to make a list.');
		return null;
	}
	const list = documentRef.createElement(type);
	const item = documentRef.createElement('li');
	item.append(range.extractContents());
	list.append(item);
	range.insertNode(list);
	return item;
};
