const dataEl = document.getElementById('wp-script-module-data-@cb-core/select-picker');
let data = {};
try {
	data = dataEl ? JSON.parse(dataEl.textContent) : {};
} catch {
	data = {};
}
const i18n = data.i18n || {};

const truthy = (value) => ['1', 'true', 'yes', 'on'].includes(String(value || '').toLowerCase());

const optionCount = (select) => Array.from(select.options).filter((option) => !option.disabled).length;

const findLabelText = (select) => {
	if (!select.id) return '';
	const label = document.querySelector(`label[for="${CSS.escape(select.id)}"]`);
	return label?.textContent?.trim() || '';
};

const enhance = (select, options = {}) => {
	if (!(select instanceof HTMLSelectElement) || select.multiple || select.dataset.cbCoreSelectPickerReady === '1') {
		return null;
	}

	const searchMode = options.search ?? select.dataset.cbCoreSelectPickerSearch ?? 'auto';
	const threshold = Number.parseInt(options.searchThreshold ?? select.dataset.cbCoreSelectPickerSearchThreshold ?? '8', 10) || 8;
	const searchable = searchMode === 'auto' ? optionCount(select) >= threshold : truthy(searchMode);
	const header = (options.variant ?? select.dataset.cbCoreSelectPickerVariant ?? '') === 'header';
	// Header pickers live inside sticky, clipped Designer panels. Without
	// a top-layer Popover API, retain the fully working native select.
	if (header && (typeof HTMLElement.prototype.showPopover !== 'function'
		|| typeof HTMLElement.prototype.hidePopover !== 'function')) return null;
	const originalParent = select.parentNode;
	if (!originalParent) return null;

	const root = document.createElement('div');
	root.className = 'cb-core-picker cb-core-select-picker' + (header ? ' cb-core-select-picker--header' : '');
	select.before(root);
	root.appendChild(select);

	const enhanced = document.createElement('div');
	enhanced.className = 'cb-core-picker__enhanced cb-core-select-picker__enhanced';
	root.appendChild(enhanced);

	const toggle = document.createElement('button');
	toggle.type = 'button';
	toggle.className = 'cb-core-picker__toggle cb-core-select-picker__toggle';
	toggle.setAttribute('aria-expanded', 'false');
	toggle.setAttribute('aria-haspopup', 'dialog');
	const panelId = `${select.id || 'cb-core-select-picker'}-panel-${Math.random().toString(36).slice(2, 8)}`;
	toggle.setAttribute('aria-controls', panelId);

	const main = document.createElement('span');
	main.className = 'cb-core-picker__toggle-main';
	const copy = document.createElement('span');
	copy.className = 'cb-core-picker__toggle-copy';
	const title = document.createElement('span');
	title.className = 'cb-core-picker__toggle-text';
	const meta = document.createElement('span');
	meta.className = 'cb-core-picker__toggle-meta';
	copy.append(title, meta);
	main.appendChild(copy);
	const chevron = document.createElement('span');
	chevron.className = 'cb-core-select-picker__chevron';
	chevron.setAttribute('aria-hidden', 'true');
	toggle.append(main, chevron);
	enhanced.appendChild(toggle);

	// Preserve explicit accessible names before falling back to a paired label.
	const labelledBy = select.getAttribute('aria-labelledby');
	const externalLabel = select.getAttribute('aria-label')?.trim()
		|| (labelledBy ? document.getElementById(labelledBy)?.textContent?.trim() : '')
		|| findLabelText(select)
		|| select.closest('label')?.textContent?.trim()
		|| '';
	if (externalLabel) toggle.setAttribute('aria-label', externalLabel);

	const panel = document.createElement('div');
	panel.id = panelId;
	panel.className = 'cb-core-picker__panel cb-core-select-picker__panel';
	panel.setAttribute('role', 'dialog');
	if (header) panel.setAttribute('popover', 'manual');
	panel.hidden = true;
	if (externalLabel) panel.setAttribute('aria-label', externalLabel);

	let search = null;
	if (searchable) {
		const toolbar = document.createElement('div');
		toolbar.className = 'cb-core-picker__toolbar';
		search = document.createElement('input');
		search.type = 'search';
		search.className = 'cb-core-picker__search cb-core-select-picker__search';
		search.placeholder = options.searchPlaceholder || select.dataset.cbCoreSelectPickerSearchPlaceholder || i18n.searchOptions || 'Search options…';
		search.setAttribute('aria-label', search.placeholder);
		search.setAttribute('autocomplete', 'off');
		toolbar.appendChild(search);
		panel.appendChild(toolbar);
	}

	const results = document.createElement('div');
	results.className = 'cb-core-picker__results cb-core-select-picker__results';
	panel.appendChild(results);
	enhanced.appendChild(panel);

	const optionButtons = () => Array.from(results.querySelectorAll('.cb-core-select-picker__option:not(:disabled)'));
	const focusOption = (index) => {
		const buttons = optionButtons();
		if (!buttons.length) return;
		const safeIndex = Math.max(0, Math.min(index, buttons.length - 1));
		buttons[safeIndex].focus();
	};

	const groups = () => {
		const output = [];
		Array.from(select.children).forEach((child) => {
			if (child instanceof HTMLOptGroupElement) {
				output.push({
					label: child.label,
					options: Array.from(child.children).filter((option) => option instanceof HTMLOptionElement),
				});
			} else if (child instanceof HTMLOptionElement) {
				let standalone = output.find((group) => group.label === '');
				if (!standalone) {
					standalone = { label: '', options: [] };
					output.unshift(standalone);
				}
				standalone.options.push(child);
			}
		});
		return output;
	};

	const selectedGroup = () => {
		const option = select.selectedOptions[0];
		return option?.parentElement instanceof HTMLOptGroupElement ? option.parentElement.label : '';
	};

	const sync = () => {
		const selected = select.selectedOptions[0];
		title.textContent = selected?.textContent?.trim() || '';
		const group = selectedGroup();
		meta.textContent = group;
		meta.hidden = !group;
		toggle.disabled = select.disabled;
		if (header && externalLabel) {
			toggle.setAttribute('aria-label', externalLabel + ': ' + title.textContent);
		}
		results.querySelectorAll('[data-value]').forEach((button) => {
			const active = button.dataset.value === select.value;
			button.classList.toggle('is-selected', active);
			button.setAttribute('aria-current', active ? 'true' : 'false');
		});
	};

	const locateHeaderPanel = () => {
		if (!header || panel.hidden) return;
		const anchor = toggle.getBoundingClientRect();
		const width = Math.min(Math.max(280, anchor.width), Math.max(160, window.innerWidth - 16));
		panel.style.width = width + 'px';
		const height = panel.getBoundingClientRect().height;
		const left = Math.max(8, Math.min(anchor.left, window.innerWidth - width - 8));
		const below = anchor.bottom + 6;
		const above = anchor.top - height - 6;
		const top = below + height > window.innerHeight - 8 && above >= 8
			? above : Math.max(8, Math.min(below, window.innerHeight - height - 8));
		panel.style.left = left + 'px';
		panel.style.top = top + 'px';
	};
	const onHeaderViewportChange = () => locateHeaderPanel();
	const onHeaderFocusOutside = (event) => {
		if (!root.contains(event.target) && !panel.contains(event.target)) close();
	};
	const close = ({ focus = false } = {}) => {
		if (header) {
			window.removeEventListener('resize', onHeaderViewportChange);
			window.removeEventListener('scroll', onHeaderViewportChange, true);
			document.removeEventListener('focusin', onHeaderFocusOutside);
			document.removeEventListener('click', onDocumentClick);
			if (panel.matches(':popover-open')) panel.hidePopover();
		}
		panel.hidden = true;
		toggle.setAttribute('aria-expanded', 'false');
		root.classList.remove('is-open');
		if (search) search.value = '';
		if (focus) toggle.focus();
	};

	const render = (query = '') => {
		const needle = query.trim().toLocaleLowerCase();
		results.textContent = '';
		let matches = 0;
		groups().forEach((group) => {
			const filtered = group.options.filter((option) => {
				if (option.disabled) return false;
				if (!needle) return true;
				return `${option.textContent || ''} ${option.value}`.toLocaleLowerCase().includes(needle);
			});
			if (!filtered.length) return;

			const section = document.createElement('section');
			section.className = 'cb-core-select-picker__group';
			if (group.label) {
				const heading = document.createElement('div');
				heading.className = 'cb-core-select-picker__group-label';
				heading.textContent = group.label;
				section.appendChild(heading);
			}

			const optionsWrap = document.createElement('div');
			optionsWrap.className = 'cb-core-select-picker__group-options';
			filtered.forEach((option) => {
				matches += 1;
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'cb-core-picker__option cb-core-select-picker__option';
				button.dataset.value = option.value;
				button.textContent = option.textContent?.trim() || option.value;
				button.addEventListener('click', () => {
					if (select.value === option.value) {
						close({ focus: true });
						return;
					}
					select.value = option.value;
					select.dispatchEvent(new Event('change', { bubbles: true }));
					sync();
					close({ focus: true });
				});
				optionsWrap.appendChild(button);
			});
			section.appendChild(optionsWrap);
			results.appendChild(section);
		});

		if (!matches) {
			const empty = document.createElement('div');
			empty.className = 'cb-core-picker__empty';
			empty.textContent = i18n.noResults || 'No matching options found.';
			results.appendChild(empty);
		}
		sync();
	};

	const open = () => {
		if (select.disabled) return;
		render(search?.value || '');
		panel.hidden = false;
		if (header) {
			panel.showPopover();
			locateHeaderPanel();
			window.addEventListener('resize', onHeaderViewportChange, { passive: true });
			window.addEventListener('scroll', onHeaderViewportChange, { capture: true, passive: true });
			document.addEventListener('focusin', onHeaderFocusOutside);
			document.addEventListener('click', onDocumentClick);
		}
		toggle.setAttribute('aria-expanded', 'true');
		root.classList.add('is-open');
		if (search) window.requestAnimationFrame(() => search.focus());
	};

	toggle.addEventListener('click', () => {
		panel.hidden ? open() : close();
	});
	toggle.addEventListener('keydown', (event) => {
		if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
		event.preventDefault();
		if (panel.hidden) open();
		window.requestAnimationFrame(() => {
			if (search) {
				search.focus();
				return;
			}
			const buttons = optionButtons();
			const selectedIndex = buttons.findIndex((button) => button.dataset.value === select.value);
			focusOption(selectedIndex >= 0 ? selectedIndex : (event.key === 'ArrowUp' ? buttons.length - 1 : 0));
		});
	});
	search?.addEventListener('input', () => render(search.value));
	search?.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			close({ focus: true });
		} else if (event.key === 'ArrowDown') {
			event.preventDefault();
			focusOption(0);
		}
	});
	panel.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			close({ focus: true });
			return;
		}
		if (!event.target.classList?.contains('cb-core-select-picker__option')) return;
		const buttons = optionButtons();
		const current = buttons.indexOf(event.target);
		if (event.key === 'ArrowDown') { event.preventDefault(); focusOption(current + 1); }
		if (event.key === 'ArrowUp') { event.preventDefault(); focusOption(current - 1); }
		if (event.key === 'Home') { event.preventDefault(); focusOption(0); }
		if (event.key === 'End') { event.preventDefault(); focusOption(buttons.length - 1); }
	});
	const onDocumentClick = (event) => {
		if (!root.contains(event.target) && !panel.contains(event.target)) close();
	};
	if (!header) {
		// Field picker retains its previous outside-click behavior without
		// introducing a scroll-close rule not present in the original engine.
		document.addEventListener('click', onDocumentClick);
	} else {
		panel.addEventListener('toggle', (event) => {
			if (event.newState === 'closed' && !panel.hidden) close();
		});
	}

	select.addEventListener('change', sync);
	select.addEventListener('cb:select-picker-sync', sync);
	select.form?.addEventListener('reset', () => window.setTimeout(sync, 0));
	new MutationObserver(sync).observe(select, { attributes: true, attributeFilter: ['disabled'] });

	if (externalLabel && select.id) {
		document.querySelectorAll(`label[for="${CSS.escape(select.id)}"]`).forEach((label) => {
			label.addEventListener('click', (event) => {
				if (select.hidden) {
					event.preventDefault();
					toggle.focus();
				}
			});
		});
	}

	select.hidden = true;
	select.setAttribute('aria-hidden', 'true');
	select.tabIndex = -1;
	select.dataset.cbCoreSelectPickerReady = '1';
	root.classList.add('is-enhanced');
	sync();

	return Object.freeze({ root, select, toggle, panel, sync, open, close });
};

const init = (scope = document) => {
	scope.querySelectorAll('select[data-cb-core-select-picker]').forEach((select) => enhance(select));
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', () => init());
} else {
	init();
}

window.cbCore = window.cbCore || {};
window.cbCore.selectPicker = Object.freeze({ init, enhance });
