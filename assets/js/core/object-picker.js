const hasDocument = typeof document !== 'undefined';
const browserWindow = typeof window !== 'undefined' ? window : null;
const dataEl = hasDocument ? document.getElementById('wp-script-module-data-@cb-core/object-picker') : null;
let data = {};
try {
	data = dataEl ? JSON.parse(dataEl.textContent) : {};
} catch {
	data = {};
}
const ajaxUrl = data.ajaxUrl || browserWindow?.ajaxurl || '';
const i18n = data.i18n || {};
const IDENTIFIER_MAX_BYTES = 191;
const phpTrimPattern = /^[\u0000\t\n\r\v ]+|[\u0000\t\n\r\v ]+$/g;
const textEncoder = new TextEncoder();

const parseJson = (value, fallback) => {
	try {
		return JSON.parse(value || '');
	} catch {
		return fallback;
	}
};

export const normalizeIdentifier = (value) => {
	let raw = '';
	if (typeof value === 'string' || typeof value === 'number') {
		raw = String(value);
	} else if (typeof value === 'boolean') {
		raw = value ? '1' : '';
	} else {
		return '';
	}

	const identifier = raw.replace(phpTrimPattern, '');
	if (!identifier || identifier.includes(',') || textEncoder.encode(identifier).byteLength > IDENTIFIER_MAX_BYTES) {
		return '';
	}
	return identifier;
};

export const normalizePickerItem = (item) => {
	if (!item || typeof item !== 'object' || Array.isArray(item)) return null;
	const id = normalizeIdentifier(item.id);
	if (!id) return null;
	return {
		id,
		label: String(item.label || `#${id}`),
		meta: String(item.meta || ''),
	};
};

export const normalizeSelection = (items, multiple = true) => {
	if (!Array.isArray(items)) return [];
	const selected = [];
	items.forEach((item) => {
		if (!multiple && selected.length) return;
		const normalized = normalizePickerItem(item);
		if (!normalized || selected.some((candidate) => candidate.id === normalized.id)) return;
		selected.push(normalized);
	});
	return selected;
};

export const addSelection = (selected, item, multiple = true) => {
	const current = Array.isArray(selected) ? selected : [];
	const normalized = normalizePickerItem(item);
	if (!normalized || current.some((candidate) => candidate.id === normalized.id)) return current;
	return multiple ? [...current, normalized] : [normalized];
};

export const removeSelection = (selected, identifier) => {
	const current = Array.isArray(selected) ? selected : [];
	const normalized = normalizeIdentifier(identifier);
	if (!normalized) return current;
	return current.filter((candidate) => candidate.id !== normalized);
};

const initPicker = (root) => {
	if (root.dataset.cbCoreObjectPickerReady === '1') return;
	const input = root.querySelector('[data-cb-core-object-picker-input]');
	const enhanced = root.querySelector('[data-cb-core-object-picker-enhanced]');
	const selectedEl = root.querySelector('[data-cb-core-object-picker-selected]');
	const search = root.querySelector('[data-cb-core-object-picker-search]');
	const results = root.querySelector('[data-cb-core-object-picker-results]');
	if (!input || !enhanced || !selectedEl || !search || !results || !ajaxUrl) return;

	const multiple = root.dataset.multiple === '1';
	const action = root.dataset.searchAction || '';
	const nonce = root.dataset.searchNonce || '';
	const context = parseJson(root.dataset.searchContext, {});
	const initial = parseJson(root.dataset.selected, []);
	if (!action || !nonce || !Array.isArray(initial)) return;

	let selected = normalizeSelection(initial, multiple);
	let timer = null;
	let controller = null;

	const syncInput = () => {
		input.value = selected.map((item) => item.id).join(',');
		input.dispatchEvent(new Event('change', { bubbles: true }));
	};

	const renderSelected = () => {
		selectedEl.textContent = '';
		selectedEl.classList.toggle('is-empty', !selected.length);
		if (!selected.length) {
			const empty = document.createElement('span');
			empty.className = 'cb-core-object-picker__selected-empty';
			empty.textContent = i18n.noneSelected || 'Nothing selected.';
			selectedEl.appendChild(empty);
			return;
		}
		selected.forEach((item) => {
			const chip = document.createElement('span');
			chip.className = 'cb-core-object-picker__chip';
			const copy = document.createElement('span');
			copy.className = 'cb-core-object-picker__chip-copy';
			const label = document.createElement('span');
			label.className = 'cb-core-object-picker__chip-label';
			label.textContent = item.label;
			copy.appendChild(label);
			if (item.meta) {
				const meta = document.createElement('span');
				meta.className = 'cb-core-object-picker__chip-meta';
				meta.textContent = item.meta;
				copy.appendChild(meta);
			}
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'cb-core-object-picker__remove';
			const removeLabel = `${i18n.remove || 'Remove'} ${item.label}`;
			remove.setAttribute('aria-label', removeLabel);
			remove.setAttribute('title', removeLabel);
			remove.textContent = '×';
			remove.addEventListener('click', () => {
				selected = removeSelection(selected, item.id);
				syncInput();
				renderSelected();
			});
			chip.append(copy, remove);
			selectedEl.appendChild(chip);
		});
	};

	const closeResults = () => {
		results.hidden = true;
		results.textContent = '';
	};

	const renderResults = (items) => {
		results.textContent = '';
		if (!Array.isArray(items) || !items.length) {
			const empty = document.createElement('div');
			empty.className = 'cb-core-object-picker__empty';
			empty.textContent = root.dataset.emptyMessage || i18n.noResults || 'No matching items found.';
			results.appendChild(empty);
			results.hidden = false;
			return;
		}
		items.forEach((item) => {
			const normalized = normalizePickerItem(item);
			if (!normalized || selected.some((candidate) => candidate.id === normalized.id)) return;
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'button cb-core-object-picker__result';
			const label = document.createElement('span');
			label.className = 'cb-core-object-picker__result-label';
			label.textContent = normalized.label;
			button.appendChild(label);
			if (normalized.meta) {
				const meta = document.createElement('span');
				meta.className = 'cb-core-object-picker__result-meta';
				meta.textContent = normalized.meta;
				button.appendChild(meta);
			}
			button.addEventListener('click', () => {
				selected = addSelection(selected, normalized, multiple);
				syncInput();
				renderSelected();
				search.value = '';
				closeResults();
				search.focus();
			});
			results.appendChild(button);
		});
		if (!results.children.length) {
			closeResults();
			return;
		}
		results.hidden = false;
	};

	const runSearch = async () => {
		const term = search.value.trim();
		if (term.length < 2) {
			closeResults();
			return;
		}
		controller?.abort();
		controller = new AbortController();
		const body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', nonce);
		body.append('search', term);
		Object.entries(context).forEach(([key, value]) => body.append(`context[${key}]`, String(value ?? '')));
		try {
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
				signal: controller.signal,
			});
			const payload = await response.json();
			if (!payload?.success) throw new Error(payload?.data?.message || i18n.searchError || 'Search failed.');
			renderResults(payload.data?.items || []);
		} catch (error) {
			if (error?.name === 'AbortError') return;
			renderResults([]);
		}
	};

	root.dataset.cbCoreObjectPickerReady = '1';
	root.classList.add('is-enhanced');
	input.classList.add('screen-reader-text');
	input.setAttribute('aria-hidden', 'true');
	input.tabIndex = -1;
	enhanced.hidden = false;
	renderSelected();

	search.addEventListener('input', () => {
		globalThis.clearTimeout(timer);
		timer = globalThis.setTimeout(runSearch, 250);
	});
	search.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') closeResults();
	});
	document.addEventListener('click', (event) => {
		if (!root.contains(event.target)) closeResults();
	});
};

const init = (scope = hasDocument ? document : null) => {
	if (!scope || typeof scope.querySelectorAll !== 'function') return;
	scope.querySelectorAll('[data-cb-core-object-picker]').forEach(initPicker);
};

if (hasDocument) {
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => init());
	} else {
		init();
	}
}

if (browserWindow) {
	browserWindow.cbCore = browserWindow.cbCore || {};
	browserWindow.cbCore.objectPicker = Object.freeze({ init });
}
