/**
 * @cb-core/console - CB Console runner.
 *
 * Three-panel command runner with side-effects-aware execution:
 *
 *   - none        - direct run, no banner, no confirm
 *   - state       - banner-warning above Run button, direct run on click
 *   - destructive - modal-confirm with explicit action list, server-side
 *                   confirm-token check on the run endpoint
 *
 * Special-case: commands returning data.sensitive_output === true (e.g.
 * `cb failsafe rotate-token`) bypass the regular output panel and render
 * through a dedicated secret-token modal with copy-to-clipboard. The
 * regular output panel only shows a "see secret dialog" placeholder.
 *
 * Architecture: pure ES module, no jQuery, native fetch, native DOM API.
 *
 */

import { copy as copyToClipboard } from '../core/clipboard.js';

const MODULE_ID = '@cb-core/console';

function readModuleData() {
	const node = document.getElementById(`wp-script-module-data-${MODULE_ID}`);
	if (!node) return { restRoot: '', nonce: '', i18n: {} };
	try {
		return JSON.parse(node.textContent || '{}');
	} catch (e) {
		console.error('[CB Console] failed to parse script-module data', e);
		return { restRoot: '', nonce: '', i18n: {} };
	}
}

const data = readModuleData();
const i18n = data.i18n || {};

function t(key, fallback) {
	return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : (fallback || key);
}

function format(template, ...values) {
	let i = 0;
	return String(template).replace(/%[sd]/g, () => {
		const v = values[i++];
		return v === undefined || v === null ? '' : String(v);
	});
}

// ─── DOM helpers ─────────────────────────────────────────────────

function el(tag, attrs = {}, ...children) {
	const node = document.createElement(tag);
	for (const [k, v] of Object.entries(attrs)) {
		if (v === false || v === null || v === undefined) continue;
		if (k === 'class') {
			node.className = v;
		} else if (k === 'dataset') {
			for (const [dk, dv] of Object.entries(v)) {
				node.dataset[dk] = dv;
			}
		} else if (k.startsWith('on') && typeof v === 'function') {
			node.addEventListener(k.slice(2).toLowerCase(), v);
		} else if (k === 'text') {
			node.textContent = v;
		} else if (k === 'html') {
			node.innerHTML = v;
		} else {
			node.setAttribute(k, v);
		}
	}
	for (const child of children) {
		if (child === null || child === undefined || child === false) continue;
		node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
	}
	return node;
}

// ─── REST helpers ────────────────────────────────────────────────

async function fetchCommands() {
	const res = await fetch(data.restRoot + 'commands', {
		method: 'GET',
		headers: { 'Accept': 'application/json', 'X-WP-Nonce': data.nonce },
		credentials: 'same-origin',
	});
	if (!res.ok) throw new Error(`HTTP ${res.status}`);
	return res.json();
}

async function fetchConfirmToken(commandId) {
	const res = await fetch(data.restRoot + 'confirm-token', {
		method: 'POST',
		headers: {
			'Accept': 'application/json',
			'Content-Type': 'application/json',
			'X-WP-Nonce': data.nonce,
		},
		credentials: 'same-origin',
		body: JSON.stringify({ id: commandId }),
	});
	const body = await res.json().catch(() => ({}));
	if (!res.ok) throw new Error((body && body.message) || `HTTP ${res.status}`);
	return body.token;
}

async function runCommand(commandId, args, confirmToken) {
	const payload = { id: commandId, args };
	if (confirmToken) payload.confirm_token = confirmToken;

	const res = await fetch(data.restRoot + 'run', {
		method: 'POST',
		headers: {
			'Accept': 'application/json',
			'Content-Type': 'application/json',
			'X-WP-Nonce': data.nonce,
		},
		credentials: 'same-origin',
		body: JSON.stringify(payload),
	});
	const body = await res.json().catch(() => ({}));
	if (!res.ok) {
		const msg = (body && body.message) || `HTTP ${res.status}`;
		const err = new Error(msg);
		err.payload = body;
		err.status = res.status;
		throw err;
	}
	return body;
}

/**
 * Poll the job-progress endpoint for an async command (currently only
 * `cb scan run`). Returns the parsed response shape:
 *
 *   { status, phase, started_at, completed_at, error, final_result }
 *
 * Network errors throw - the caller's polling loop handles retry/abort.
 */
async function fetchJobProgress(jobId) {
	const url = data.restRoot + 'job-progress?job_id=' + encodeURIComponent(jobId);
	const res = await fetch(url, {
		method: 'GET',
		headers: { 'Accept': 'application/json', 'X-WP-Nonce': data.nonce },
		credentials: 'same-origin',
	});
	if (!res.ok) {
		throw new Error(`HTTP ${res.status}`);
	}
	return res.json();
}

// ─── State ───────────────────────────────────────────────────────

const state = {
	commands: [],
	filtered: [],
	selectedId: null,
	formValues: {},
	running: false,
	// Async polling state - when an async command is running, asyncPoll
	// holds the job_id + abort flag. setting abort = true tells the poll
	// loop to stop on its next tick (the operator clicked "Stop showing
	// progress"). The backend scan keeps running regardless; we only
	// stop the UI tracking.
	asyncPoll: null,
};

const groupOrder = ['observe', 'mutate', 'destructive', 'other'];
const groupLabels = {
	observe:     t('groupObserve', 'Read-only'),
	mutate:      t('groupMutate',  'State-change'),
	destructive: t('groupDestructive', 'Destructive'),
	other:       t('groupOther',   'Other'),
};

// ─── Picker rendering ────────────────────────────────────────────

function renderPicker(pickerEl) {
	pickerEl.replaceChildren();

	if (state.filtered.length === 0) {
		pickerEl.appendChild(el('div', {
			class: 'cb-console__picker-empty',
			text: t('noCommands', 'No commands match your filter.'),
		}));
		return;
	}

	const grouped = new Map();
	for (const g of groupOrder) grouped.set(g, []);
	for (const cmd of state.filtered) {
		const g = grouped.has(cmd.group) ? cmd.group : 'other';
		grouped.get(g).push(cmd);
	}

	for (const g of groupOrder) {
		const items = grouped.get(g);
		if (!items || items.length === 0) continue;

		pickerEl.appendChild(el('h3', {
			class: 'cb-console__group-heading',
			text: groupLabels[g] || g,
		}));

		for (const cmd of items) {
			const isActive   = cmd.id === state.selectedId;
			const isDisabled = !cmd.runnable;
			const tile = el('button', {
				type: 'button',
				class: 'cb-console__cmd' + (isActive ? ' is-active' : '') + (isDisabled ? ' is-disabled' : ''),
				role: 'option',
				'aria-selected': isActive ? 'true' : 'false',
				dataset: { commandId: cmd.id },
				onclick: () => selectCommand(cmd.id),
			},
				el('span', { class: 'cb-console__cmd-name', text: cmd.name }),
				cmd.description ? el('span', { class: 'cb-console__cmd-desc', text: cmd.description }) : null,
			);
			pickerEl.appendChild(tile);
		}
	}
}

// ─── Form rendering ──────────────────────────────────────────────

function renderForm(formBodyEl, formFooterEl, titleEl, descEl, sideEffectsEl, runBtn) {
	const cmd = state.commands.find((c) => c.id === state.selectedId);

	if (!cmd) {
		titleEl.textContent = t('selectCommand', 'Select a command');
		descEl.textContent  = t('selectCommandHelp', 'Pick a command from the list to view its arguments.');
		formBodyEl.replaceChildren(el('div', {
			class: 'cb-console__form-empty',
			text: t('selectCommandHelp', 'Pick a command from the list to view its arguments.'),
		}));
		formFooterEl.hidden = true;
		return;
	}

	titleEl.textContent = cmd.name;
	descEl.textContent  = cmd.description || '';

	formBodyEl.replaceChildren();
	const schema = cmd.args_schema || {};
	const keys   = Object.keys(schema);

	if (keys.length === 0) {
		formBodyEl.appendChild(el('div', {
			class: 'cb-console__form-no-args',
			text: t('noArgs', 'This command takes no arguments.'),
		}));
	} else {
		formBodyEl.appendChild(el('h3', {
			class: 'screen-reader-text',
			text: t('argsHeading', 'Arguments'),
		}));
		for (const key of keys) {
			formBodyEl.appendChild(renderField(key, schema[key]));
		}
	}

	// Side-effects badge + warning banner
	sideEffectsEl.replaceChildren();
	const badgeText = cmd.side_effects === 'none'
		? t('readOnlyLabel', 'Read-only')
		: cmd.side_effects === 'state'
			? t('stateChangeLabel', 'State-change')
			: cmd.side_effects === 'destructive'
				? t('destructiveLabel', 'Destructive')
				: cmd.side_effects;

	sideEffectsEl.appendChild(el('span', {
		class: `cb-console__side-effect-badge cb-console__side-effect-badge--${cmd.side_effects}`,
		text: badgeText,
	}));

	if (cmd.runnable) {
		// State-change: persistent banner above Run button.
		// Destructive: same banner, plus a red Run button + modal-confirm.
		if (cmd.side_effects === 'state') {
			sideEffectsEl.appendChild(el('span', {
				class: 'cb-console__warning-banner cb-console__warning-banner--state',
				text: t('sideEffectsNote', 'This command modifies site state.'),
			}));
		} else if (cmd.side_effects === 'destructive') {
			sideEffectsEl.appendChild(el('span', {
				class: 'cb-console__warning-banner cb-console__warning-banner--destructive',
				text: t('destructiveBannerNote', 'Destructive action - confirmation required before running.'),
			}));
		}

		runBtn.disabled = state.running;
		runBtn.textContent = state.running ? t('running', 'Running…') : t('runCommand', 'Run command');
		runBtn.classList.toggle('button-danger', cmd.side_effects === 'destructive');
		runBtn.removeAttribute('title');
	} else {
		runBtn.disabled = true;
		runBtn.textContent = t('runCommand', 'Run command');
		runBtn.classList.remove('button-danger');
		const note = t('capabilityDenied', 'You do not have permission to use the Console.');
		runBtn.setAttribute('title', note);

		sideEffectsEl.appendChild(el('span', {
			class: 'cb-console__pending-note',
			text: note,
		}));
	}

	formFooterEl.hidden = false;
}

function renderField(key, def) {
	const id = `cb-console-field-${key}`;
	const labelEl = el('label', {
		class: 'cb-console__field-label',
		for: id,
	}, def.label || key);

	if (def.required) {
		labelEl.appendChild(el('span', {
			class: 'cb-console__field-required',
			text: `(${t('required', 'required')})`,
		}));
	}

	const fieldWrap = el('div', { class: 'cb-console__field' }, labelEl);

	const stored = Object.prototype.hasOwnProperty.call(state.formValues, key)
		? state.formValues[key]
		: (def.default !== undefined && def.default !== null ? def.default : '');

	let input;
	switch (def.type) {
		case 'boolean': {
			const checkbox = el('input', {
				type: 'checkbox',
				id,
				dataset: { fieldKey: key, fieldType: 'boolean' },
			});
			checkbox.checked = !!stored;
			checkbox.addEventListener('change', () => {
				state.formValues[key] = checkbox.checked;
			});
			fieldWrap.replaceChildren(
				el('div', { class: 'cb-console__field-checkbox-row' },
					checkbox,
					el('label', { for: id, text: def.label || key }),
				),
			);
			state.formValues[key] = checkbox.checked;
			break;
		}
		case 'select': {
			input = el('select', {
				id,
				class: 'cb-console__field-select',
				dataset: { fieldKey: key, fieldType: 'select' },
			});
			const opts = def.options || {};
			for (const [val, lab] of Object.entries(opts)) {
				const opt = el('option', { value: val, text: String(lab) });
				if (String(val) === String(stored)) opt.selected = true;
				input.appendChild(opt);
			}
			input.addEventListener('change', () => {
				state.formValues[key] = input.value;
			});
			fieldWrap.appendChild(input);
			state.formValues[key] = input.value;
			break;
		}
		case 'int': {
			input = el('input', {
				type: 'number',
				id,
				class: 'cb-console__field-input cb-console__field-input--int',
				value: stored === null || stored === undefined ? '' : String(stored),
				dataset: { fieldKey: key, fieldType: 'int' },
			});
			input.addEventListener('input', () => {
				state.formValues[key] = input.value === '' ? null : parseInt(input.value, 10);
			});
			fieldWrap.appendChild(input);
			break;
		}
		case 'date': {
			input = el('input', {
				type: 'date',
				id,
				class: 'cb-console__field-input cb-console__field-input--date',
				value: stored === null || stored === undefined ? '' : String(stored),
				dataset: { fieldKey: key, fieldType: 'date' },
			});
			input.addEventListener('input', () => {
				state.formValues[key] = input.value;
			});
			fieldWrap.appendChild(input);
			break;
		}
		case 'user': {
			fieldWrap.appendChild(buildUserPicker(id, key, def, stored));
			break;
		}
		case 'text':
		default: {
			input = el('input', {
				type: 'text',
				id,
				class: 'cb-console__field-input',
				value: stored === null || stored === undefined ? '' : String(stored),
				autocomplete: 'off',
				spellcheck: 'false',
				dataset: { fieldKey: key, fieldType: def.type || 'text' },
			});
			input.addEventListener('input', () => {
				state.formValues[key] = input.value;
			});
			fieldWrap.appendChild(input);
			break;
		}
	}

	if (def.help) {
		fieldWrap.appendChild(el('p', {
			class: 'cb-console__field-help',
			text: def.help,
		}));
	}

	return fieldWrap;
}

// ─── User-picker (autocomplete) ─────────────────────────────────

const userSearchCache = new Map();
let userSearchTimer = null;

async function fetchUserSearch(query) {
	const key = String(query || '').trim().toLowerCase();
	if (userSearchCache.has(key)) return userSearchCache.get(key);

	const url = data.restRoot + 'user-search?q=' + encodeURIComponent(query || '') + '&limit=8';
	const res = await fetch(url, {
		method: 'GET',
		headers: { 'Accept': 'application/json', 'X-WP-Nonce': data.nonce },
		credentials: 'same-origin',
	});
	if (!res.ok) throw new Error(`HTTP ${res.status}`);
	const body = await res.json();
	const results = Array.isArray(body.results) ? body.results : [];
	userSearchCache.set(key, results);
	return results;
}

/**
 * Build a user-picker field - a text input + suggestions dropdown.
 *
 * The form value stored in state.formValues[key] is the user ID as a
 * string (so it round-trips cleanly through the REST normaliser, which
 * treats 'user' as a text type and the resolve_user PHP helper accepts
 * numeric IDs first). The displayed input value is the human-readable
 * "login (display name)" so the operator sees what they picked, but
 * the submitted value is the ID.
 */
function buildUserPicker(id, key, def, stored) {
	const wrap = el('div', { class: 'cb-console__user-picker' });

	const input = el('input', {
		type: 'text',
		id,
		class: 'cb-console__field-input cb-console__field-input--user',
		placeholder: t('userSearchPlaceholder', 'Search by login, email, or name…'),
		autocomplete: 'off',
		spellcheck: 'false',
		dataset: { fieldKey: key, fieldType: 'user' },
	});

	const dropdown = el('div', {
		class: 'cb-console__user-picker-dropdown',
		role: 'listbox',
		hidden: true,
	});

	let activeIndex = -1;
	let currentResults = [];

	const renderDropdown = (results, loading = false) => {
		dropdown.replaceChildren();

		if (loading) {
			dropdown.appendChild(el('div', {
				class: 'cb-console__user-picker-loading',
				text: t('searching', 'Searching…'),
			}));
			dropdown.hidden = false;
			return;
		}

		if (!results || results.length === 0) {
			dropdown.appendChild(el('div', {
				class: 'cb-console__user-picker-empty',
				text: t('noUsersFound', 'No users found.'),
			}));
			dropdown.hidden = false;
			return;
		}

		results.forEach((user, idx) => {
			const opt = el('button', {
				type: 'button',
				class: 'cb-console__user-picker-opt' + (idx === activeIndex ? ' is-active' : ''),
				role: 'option',
				dataset: { userId: String(user.id) },
				onclick: () => selectUser(user),
			},
				el('span', { class: 'cb-console__user-picker-opt-login', text: user.login }),
				el('span', { class: 'cb-console__user-picker-opt-name', text: user.display_name || '' }),
				el('span', { class: 'cb-console__user-picker-opt-email', text: user.email || '' }),
			);
			dropdown.appendChild(opt);
		});
		dropdown.hidden = false;
	};

	const selectUser = (user) => {
		input.value = `${user.login}` + (user.display_name && user.display_name !== user.login ? ` (${user.display_name})` : '');
		state.formValues[key] = String(user.id);
		dropdown.hidden = true;
		activeIndex = -1;
		input.dataset.selectedId = String(user.id);
	};

	const triggerSearch = (query) => {
		clearTimeout(userSearchTimer);
		userSearchTimer = setTimeout(async () => {
			renderDropdown([], true);
			try {
				currentResults = await fetchUserSearch(query);
				renderDropdown(currentResults);
			} catch (err) {
				console.error('[CB Console] user search failed', err);
				dropdown.replaceChildren(el('div', {
					class: 'cb-console__user-picker-error',
					text: format(t('searchFailed', 'Search failed: %s'), err.message || 'unknown'),
				}));
				dropdown.hidden = false;
			}
		}, 200);
	};

	input.addEventListener('focus', () => {
		// Show recent users when input is focused with no query.
		if (input.value === '') triggerSearch('');
	});

	input.addEventListener('input', () => {
		// While the operator types, the form value is in flux. Clear the
		// stored ID so a stale selection doesn't survive a fresh search;
		// the value reasserts when they pick from the dropdown.
		delete input.dataset.selectedId;
		state.formValues[key] = '';
		triggerSearch(input.value);
	});

	input.addEventListener('keydown', (e) => {
		if (dropdown.hidden) return;
		const opts = dropdown.querySelectorAll('.cb-console__user-picker-opt');
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			activeIndex = Math.min(activeIndex + 1, opts.length - 1);
			renderDropdown(currentResults);
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			activeIndex = Math.max(activeIndex - 1, 0);
			renderDropdown(currentResults);
		} else if (e.key === 'Enter' && activeIndex >= 0) {
			e.preventDefault();
			selectUser(currentResults[activeIndex]);
		} else if (e.key === 'Escape') {
			dropdown.hidden = true;
			activeIndex = -1;
		}
	});

	// Close dropdown on outside click.
	document.addEventListener('click', (e) => {
		if (!wrap.contains(e.target)) {
			dropdown.hidden = true;
			activeIndex = -1;
		}
	});

	wrap.appendChild(input);
	wrap.appendChild(dropdown);

	// Restore previously-stored value if any (for re-selecting a command
	// that was already filled in).
	if (stored && typeof stored === 'string' && /^\d+$/.test(stored)) {
		// Stored ID - fetch the user once to populate the display.
		fetchUserSearch(stored).then((results) => {
			const found = results.find((u) => String(u.id) === stored);
			if (found) selectUser(found);
		}).catch(() => {});
	}

	return wrap;
}

// ─── Modal: destructive confirm ─────────────────────────────────

function buildConfirmModal(cmd, onConfirm, onCancel) {
	const overlay = el('div', { class: 'cb-console__modal-overlay', role: 'dialog', 'aria-modal': 'true' });
	const dialog  = el('div', { class: 'cb-console__modal cb-console__modal--destructive' });

	const closeAndCancel = () => {
		overlay.remove();
		document.body.classList.remove('cb-console__no-scroll');
		if (onCancel) onCancel();
	};

	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) closeAndCancel();
	});

	dialog.appendChild(el('h2', {
		class: 'cb-console__modal-title',
		text: t('confirmDestructiveTitle', 'Destructive action'),
	}));

	dialog.appendChild(el('p', {
		class: 'cb-console__modal-cmd',
		text: cmd.name,
	}));

	dialog.appendChild(el('p', {
		class: 'cb-console__modal-desc',
		text: cmd.description || '',
	}));

	const actionsList = el('ul', { class: 'cb-console__modal-actions-list' });
	const lines = destructiveActionLines(cmd.id);
	for (const line of lines) {
		actionsList.appendChild(el('li', { text: line }));
	}
	dialog.appendChild(actionsList);

	dialog.appendChild(el('p', {
		class: 'cb-console__modal-irreversible',
		text: t('irreversibleNote', 'This action is irreversible.'),
	}));

	const footer = el('div', { class: 'cb-console__modal-footer' });
	const cancelBtn = el('button', {
		type: 'button',
		class: 'button cb-console__modal-cancel',
		text: t('cancel', 'Cancel'),
		onclick: closeAndCancel,
	});
	const confirmBtn = el('button', {
		type: 'button',
		class: 'button button-danger cb-console__modal-confirm',
		text: t('confirmRun', 'Confirm and run'),
		onclick: () => {
			overlay.remove();
			document.body.classList.remove('cb-console__no-scroll');
			onConfirm();
		},
	});
	footer.appendChild(cancelBtn);
	footer.appendChild(confirmBtn);
	dialog.appendChild(footer);

	overlay.appendChild(dialog);
	document.body.appendChild(overlay);
	document.body.classList.add('cb-console__no-scroll');
	confirmBtn.focus();

	// Esc closes
	const escHandler = (e) => {
		if (e.key === 'Escape') {
			document.removeEventListener('keydown', escHandler);
			closeAndCancel();
		}
	};
	document.addEventListener('keydown', escHandler);
}

/**
 * Generate per-command "what will happen" lines for the destructive
 * modal. Hard-coded so the modal can be specific without round-tripping
 * to the server. Anything not listed gets a generic fallback.
 */
function destructiveActionLines(id) {
	switch (id) {
		case 'cb-failsafe-disable':
			return [
				t('actFailsafeDisable1', 'Activates the emergency bypass.'),
				t('actFailsafeDisable2', 'All restrictive Core Blueprint features are disabled site-wide.'),
				t('actFailsafeDisable3', 'Site is exposed to threats CB was configured to block until you re-enable.'),
			];
		case 'cb-failsafe-rotate-token':
			return [
				t('actFailsafeRotateToken1', 'Generates a new secret bypass URL token.'),
				t('actFailsafeRotateToken2', 'The new URL is shown ONCE in a separate dialog and cannot be recovered if not saved.'),
				t('actFailsafeRotateToken3', 'Any previously-saved bypass URL stops working immediately.'),
			];
		case 'cb-operator-remove':
			return [
				t('actOperatorRemove1', 'Demotes the user from the cb_operator role.'),
				t('actOperatorRemove2', 'They lose access to operator-only Core Blueprint surfaces.'),
				t('actOperatorRemove3', 'If --force is set, this proceeds even when they would be the last operator (lockout risk).'),
			];
		default:
			return [t('actGenericIrreversible', 'This command is destructive and cannot be undone.')];
	}
}

// ─── Modal: secret-token (sensitive output handling) ────────────

function buildSecretTokenModal(payload) {
	const url = payload.data && payload.data.bypass_url ? payload.data.bypass_url : '';
	const adminEmail = payload.data && payload.data.admin_email ? payload.data.admin_email : '';

	const overlay = el('div', { class: 'cb-console__modal-overlay', role: 'dialog', 'aria-modal': 'true' });
	const dialog  = el('div', { class: 'cb-console__modal cb-console__modal--secret' });

	const close = () => {
		overlay.remove();
		document.body.classList.remove('cb-console__no-scroll');
	};

	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	dialog.appendChild(el('h2', {
		class: 'cb-console__modal-title',
		text: t('secretTokenTitle', 'Secret bypass URL - shown once'),
	}));

	dialog.appendChild(el('p', {
		class: 'cb-console__modal-warning',
		text: t('secretTokenWarning', 'Save this URL now. It will not be shown again. If you close this dialog without saving, run rotate-token again to generate a new one.'),
	}));

	const urlBox = el('input', {
		type: 'text',
		readonly: 'readonly',
		class: 'cb-console__secret-url',
		value: url,
	});
	urlBox.addEventListener('focus', () => urlBox.select());
	dialog.appendChild(urlBox);

	const copyBtn = el('button', {
		type: 'button',
		class: 'button button-primary cb-console__secret-copy',
		text: t('copyToClipboard', 'Copy URL'),
		onclick: async () => {
			await copyToClipboard( url, {
				successMessage: t('copied', 'Copied!'),
				errorMessage: t('copyFailed', 'Could not copy URL.'),
			} );
		},
	});

	dialog.appendChild(copyBtn);

	dialog.appendChild(el('p', {
		class: 'cb-console__modal-info',
		text: t('secretTokenInfo1', 'Using this URL will:'),
	}));
	const ul = el('ul', { class: 'cb-console__modal-actions-list' });
	ul.appendChild(el('li', { text: t('secretTokenAction1', 'Disable restrictive features for 60 minutes.') }));
	ul.appendChild(el('li', { text: t('secretTokenAction2', 'Rotate the token (single-use).') }));
	if (adminEmail) {
		ul.appendChild(el('li', {
			text: format(t('secretTokenAction3', 'Send an email notification to %s.'), adminEmail),
		}));
	}
	dialog.appendChild(ul);

	const footer = el('div', { class: 'cb-console__modal-footer' });
	const closeBtn = el('button', {
		type: 'button',
		class: 'button cb-console__modal-close',
		text: t('iSavedIt', 'I saved it - close'),
		onclick: close,
	});
	footer.appendChild(closeBtn);
	dialog.appendChild(footer);

	overlay.appendChild(dialog);
	document.body.appendChild(overlay);
	document.body.classList.add('cb-console__no-scroll');
	urlBox.focus();
	urlBox.select();
}

// ─── Output rendering ────────────────────────────────────────────

function renderOutput(outputBodyEl, metaEl, clearBtn, payload) {
	outputBodyEl.replaceChildren();

	const cmd = state.commands.find((c) => c.id === payload.command_id);
	const cmdName = cmd ? cmd.name : payload.command_id;

	outputBodyEl.appendChild(el('span', {
		class: 'cb-console__cmd-echo',
		text: `wp ${cmdName}`,
	}));

	if (payload.message) {
		const banner = el('div', {
			class: `cb-console__banner cb-console__banner--${payload.status}`,
		},
			el('div', {
				class: 'cb-console__banner-title',
				text: payload.status === 'error'
					? t('errorPrefix', 'Error')
					: payload.status === 'warning'
						? t('warningPrefix', 'Warning')
						: 'OK',
			}),
			el('div', {
				class: 'cb-console__banner-msg',
				text: payload.message,
			}),
		);
		outputBodyEl.appendChild(banner);
	}

	const lines = Array.isArray(payload.lines) ? payload.lines : [];
	if (lines.length > 0) {
		outputBodyEl.appendChild(el('pre', {
			class: 'cb-console__lines',
			text: lines.join('\n'),
		}));
	} else if (!payload.message) {
		outputBodyEl.appendChild(el('div', {
			class: 'cb-console__output-empty',
			text: t('noOutput', '(no output)'),
		}));
	}

	// Sensitive output? Don't render data section in panel; the secret
	// modal already showed it. Add a placeholder note instead.
	const sensitive = payload.data && payload.data.sensitive_output === true;
	if (sensitive) {
		outputBodyEl.appendChild(el('div', {
			class: 'cb-console__sensitive-note',
			text: t('sensitiveOutputNote', 'Sensitive output rendered in a separate dialog and not stored here.'),
		}));
	} else if (payload.data && typeof payload.data === 'object') {
		const wrap = el('div', { class: 'cb-console__data' });
		const pre  = el('pre', {
			class: 'cb-console__data-pre',
			text: JSON.stringify(payload.data, null, 2),
			hidden: true,
		});
		const toggle = el('button', {
			type: 'button',
			class: 'cb-console__data-toggle',
			text: t('showData', 'Show structured data'),
			'aria-expanded': 'false',
			onclick: () => {
				const expanded = toggle.getAttribute('aria-expanded') === 'true';
				toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
				toggle.textContent = expanded
					? t('showData', 'Show structured data')
					: t('hideData', 'Hide structured data');
				pre.hidden = expanded;
			},
		});
		wrap.appendChild(toggle);
		wrap.appendChild(pre);
		outputBodyEl.appendChild(wrap);
	}

	const ms = Number.isFinite(payload.duration_ms) ? payload.duration_ms : 0;
	const lastRunLabel = `${t('lastRunLabel', 'Last run')}: ${cmdName} · ${format(t('durationMs', '%d ms'), ms)}`;
	metaEl.textContent = lastRunLabel;
	clearBtn.hidden = false;
}

function renderTransportError(outputBodyEl, metaEl, clearBtn, message) {
	outputBodyEl.replaceChildren(
		el('div', {
			class: 'cb-console__banner cb-console__banner--error',
		},
			el('div', { class: 'cb-console__banner-title', text: t('errorPrefix', 'Error') }),
			el('div', { class: 'cb-console__banner-msg', text: format(t('transportError', 'Network or server error: %s'), message) }),
		),
	);
	metaEl.textContent = '';
	clearBtn.hidden = false;
}

/**
 * Render the async-running state in the output panel. Shows the
 * scheduled message, current phase (live-updating), elapsed seconds,
 * and a "Stop showing progress" button that aborts the poll loop
 * without affecting the backend scan.
 *
 * Called once when async starts (with initialPayload from runCommand)
 * and re-called on each poll tick to refresh the phase + elapsed.
 */
function renderAsyncRunning(outputBodyEl, metaEl, clearBtn, payload, progress, startedAt) {
	outputBodyEl.replaceChildren();

	const cmd = state.commands.find((c) => c.id === payload.command_id);
	const cmdName = cmd ? cmd.name : payload.command_id;

	outputBodyEl.appendChild(el('span', {
		class: 'cb-console__cmd-echo',
		text: `wp ${cmdName}`,
	}));

	// Banner - info-style during the run, swapped for success/error on
	// completion via renderOutput().
	outputBodyEl.appendChild(el('div', {
		class: 'cb-console__banner cb-console__banner--running',
	},
		el('div', { class: 'cb-console__banner-title', text: t('runningTitle', 'Running…') }),
		el('div', { class: 'cb-console__banner-msg',   text: payload.message || t('asyncScheduled', 'Async job scheduled.') }),
	));

	// Live progress section
	const phase = (progress && progress.phase) ? progress.phase : t('asyncPending', 'Waiting for cron to fire…');
	const elapsedSec = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));

	outputBodyEl.appendChild(el('div', { class: 'cb-console__async-progress' },
		el('div', { class: 'cb-console__async-spinner' }),
		el('div', { class: 'cb-console__async-progress-text' },
			el('div', { class: 'cb-console__async-phase', text: phase }),
			el('div', { class: 'cb-console__async-elapsed', text: format(t('elapsedSeconds', 'Elapsed: %ds'), elapsedSec) }),
		),
	));

	// Initial output lines from the schedule response
	const lines = Array.isArray(payload.lines) ? payload.lines : [];
	if (lines.length > 0) {
		outputBodyEl.appendChild(el('pre', {
			class: 'cb-console__lines',
			text: lines.join('\n'),
		}));
	}

	// Stop button - abort the poll loop. Backend keeps running.
	outputBodyEl.appendChild(el('button', {
		type: 'button',
		class: 'button cb-console__async-stop',
		text: t('stopProgress', 'Stop showing progress'),
		onclick: () => {
			if (state.asyncPoll) state.asyncPoll.abort = true;
		},
	}));

	const meta = `${t('lastRunLabel', 'Last run')}: ${cmdName} · ${t('asyncRunning', 'live polling')}`;
	metaEl.textContent = meta;
	clearBtn.hidden = false;
}

/**
 * Async-poll loop. Polls /job-progress every interval ms until status
 * is 'done', 'error', or 'gone', or until the operator hits Stop.
 *
 * On 'done' it calls renderOutput with the embedded final_result so the
 * regular Result-rendering path applies. On 'error' it shows the error
 * banner. On 'gone' (transient expired) it tells the operator to check
 * the Logs page.
 */
async function asyncPollLoop(jobId, initialPayload, outputBodyEl, metaEl, clearBtn) {
	const startedAt = Date.now();
	const intervalMs = 1000;
	const abortControl = { abort: false };
	state.asyncPoll = abortControl;

	let lastPhase = null;
	let renderTimer = null;

	// Render initial state immediately
	renderAsyncRunning(outputBodyEl, metaEl, clearBtn, initialPayload, null, startedAt);

	// Tick the elapsed counter every second even between polls
	const tickElapsed = () => {
		const elapsedEl = outputBodyEl.querySelector('.cb-console__async-elapsed');
		if (elapsedEl) {
			const sec = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
			elapsedEl.textContent = format(t('elapsedSeconds', 'Elapsed: %ds'), sec);
		}
	};
	renderTimer = setInterval(tickElapsed, 1000);

	const cleanup = () => {
		if (renderTimer) clearInterval(renderTimer);
		state.asyncPoll = null;
		state.running = false;
		if (renderHooks) renderHooks.form();
	};

	try {
		// Loop until terminal state or abort
		// eslint-disable-next-line no-constant-condition
		while (true) {
			if (abortControl.abort) {
				outputBodyEl.replaceChildren(el('div', {
					class: 'cb-console__banner cb-console__banner--warning',
				},
					el('div', { class: 'cb-console__banner-title', text: t('warningPrefix', 'Warning') }),
					el('div', { class: 'cb-console__banner-msg',   text: t('asyncStopped', 'Stopped tracking - the scan continues in the background. Refresh the page to resume tracking, or check Logs once it completes.') }),
				));
				cleanup();
				return;
			}

			await new Promise((r) => setTimeout(r, intervalMs));

			let progress;
			try {
				progress = await fetchJobProgress(jobId);
			} catch (err) {
				console.error('[CB Console] poll failed', err);
				// Transient network blip - keep polling. Only abort on a
				// run of consecutive failures, but for simplicity we
				// just keep going.
				continue;
			}

			const status = progress.status;

			// Phase changed → re-render the running view to update the
			// phase line. The elapsed counter ticks independently.
			if (progress.phase !== lastPhase) {
				lastPhase = progress.phase;
				renderAsyncRunning(outputBodyEl, metaEl, clearBtn, initialPayload, progress, startedAt);
			}

			if (status === 'done') {
				cleanup();
				if (progress.final_result) {
					// Render via the standard Result path so the structured-data
					// viewer + banner styling are consistent with sync commands.
					renderOutput(outputBodyEl, metaEl, clearBtn, {
						...progress.final_result,
						command_id:  initialPayload.command_id,
						duration_ms: Math.floor((Date.now() - startedAt)),
					});
				} else {
					// No final_result embedded - still show success.
					renderOutput(outputBodyEl, metaEl, clearBtn, {
						status:      'success',
						message:     t('asyncDoneNoResult', 'Scan complete. Run `cb scan latest` to see the result.'),
						lines:       [],
						data:        null,
						command_id:  initialPayload.command_id,
						duration_ms: Math.floor((Date.now() - startedAt)),
					});
				}
				return;
			}

			if (status === 'error') {
				cleanup();
				renderOutput(outputBodyEl, metaEl, clearBtn, {
					status:      'error',
					message:     progress.error || t('asyncFailedGeneric', 'The scan failed.'),
					lines:       progress.error ? [ progress.error ] : [],
					data:        null,
					command_id:  initialPayload.command_id,
					duration_ms: Math.floor((Date.now() - startedAt)),
				});
				return;
			}

			if (status === 'gone') {
				cleanup();
				outputBodyEl.replaceChildren(el('div', {
					class: 'cb-console__banner cb-console__banner--warning',
				},
					el('div', { class: 'cb-console__banner-title', text: t('warningPrefix', 'Warning') }),
					el('div', { class: 'cb-console__banner-msg',   text: t('asyncGone', 'Progress state expired. The scan may have completed - check Logs and run `cb scan latest`.') }),
				));
				return;
			}
		}
	} finally {
		// Defensive - ensure cleanup even on unhandled paths
		if (state.asyncPoll === abortControl) cleanup();
	}
}

// ─── Filtering + selection ──────────────────────────────────────

function applyFilter(query) {
	const q = String(query || '').trim().toLowerCase();
	if (q === '') {
		state.filtered = state.commands.slice();
		return;
	}
	state.filtered = state.commands.filter((cmd) =>
		cmd.name.toLowerCase().includes(q) ||
		(cmd.description || '').toLowerCase().includes(q)
	);
}

let renderHooks = null;

function selectCommand(id) {
	state.selectedId = id;
	state.formValues = {};
	if (renderHooks) {
		renderHooks.picker();
		renderHooks.form();
	}
}

// ─── Run flow ────────────────────────────────────────────────────

async function performRun(cmd, outputBodyEl, outputMetaEl, clearBtn) {
	state.running = true;
	renderHooks.form();
	outputBodyEl.replaceChildren(el('div', {
		class: 'cb-console__output-empty',
		text: t('running', 'Running…'),
	}));
	outputMetaEl.textContent = '';

	try {
		let confirmToken = null;
		if (cmd.side_effects === 'destructive') {
			confirmToken = await fetchConfirmToken(cmd.id);
		}
		const payload = await runCommand(cmd.id, state.formValues, confirmToken);

		// Async command? Hand off to the polling loop. The schedule
		// response itself is success-shaped (Result::success); the
		// poll loop renders the live progress and the eventual final
		// result.
		if (payload.data && payload.data.async === true && payload.data.job_id) {
			// Don't reset state.running yet - the poll loop manages it.
			await asyncPollLoop(payload.data.job_id, payload, outputBodyEl, outputMetaEl, clearBtn);
			return;
		}

		// Sensitive output? Show secret modal first, then render output panel.
		if (payload.data && payload.data.sensitive_output === true) {
			buildSecretTokenModal(payload);
		}

		renderOutput(outputBodyEl, outputMetaEl, clearBtn, payload);
	} catch (err) {
		console.error('[CB Console] run failed', err);
		renderTransportError(outputBodyEl, outputMetaEl, clearBtn, err.message || 'unknown');
	} finally {
		// Async path manages its own state.running; only reset for sync.
		if (!state.asyncPoll) {
			state.running = false;
			renderHooks.form();
		}
	}
}

// ─── Boot ────────────────────────────────────────────────────────

async function boot() {
	const root = document.querySelector('[data-cb-console-app]');
	if (!root) return;

	const pickerEl       = root.querySelector('[data-cb-console-picker]');
	const searchEl       = root.querySelector('[data-cb-console-search]');
	const formBodyEl     = root.querySelector('[data-cb-console-form-body]');
	const formFooterEl   = root.querySelector('[data-cb-console-form-footer]');
	const formTitleEl    = root.querySelector('[data-cb-console-form-title]');
	const formDescEl     = root.querySelector('[data-cb-console-form-desc]');
	const sideEffectsEl  = root.querySelector('[data-cb-console-side-effects]');
	const runBtn         = root.querySelector('[data-cb-console-run]');
	const outputBodyEl   = root.querySelector('[data-cb-console-output-body]');
	const outputMetaEl   = root.querySelector('[data-cb-console-output-meta]');
	const clearBtn       = root.querySelector('[data-cb-console-clear]');

	if (!pickerEl || !searchEl || !formBodyEl || !runBtn) {
		console.error('[CB Console] required DOM nodes missing');
		return;
	}

	if (searchEl.placeholder !== undefined && i18n.filterCommands) {
		searchEl.placeholder = i18n.filterCommands;
	}

	renderHooks = {
		picker: () => renderPicker(pickerEl),
		form:   () => renderForm(formBodyEl, formFooterEl, formTitleEl, formDescEl, sideEffectsEl, runBtn),
	};

	searchEl.addEventListener('input', () => {
		applyFilter(searchEl.value);
		renderHooks.picker();
	});

	runBtn.addEventListener('click', async () => {
		if (state.running || !state.selectedId) return;
		const cmd = state.commands.find((c) => c.id === state.selectedId);
		if (!cmd || !cmd.runnable) return;

		// Destructive: open modal-confirm first; only run if confirmed.
		if (cmd.side_effects === 'destructive') {
			buildConfirmModal(cmd, () => performRun(cmd, outputBodyEl, outputMetaEl, clearBtn), null);
			return;
		}

		// State-change and read-only: run directly. The state-change banner
		// in the form already warns the operator visually.
		await performRun(cmd, outputBodyEl, outputMetaEl, clearBtn);
	});

	clearBtn.addEventListener('click', () => {
		outputBodyEl.replaceChildren(el('div', {
			class: 'cb-console__output-empty',
			text: t('noOutputYet', 'Output will appear here after you run a command.'),
		}));
		outputMetaEl.textContent = '';
		clearBtn.hidden = true;
	});

	try {
		const catalog = await fetchCommands();
		state.commands = Array.isArray(catalog.commands) ? catalog.commands : [];
		state.filtered = state.commands.slice();
	} catch (err) {
		pickerEl.replaceChildren(el('div', {
			class: 'cb-console__picker-empty',
			text: format(t('transportError', 'Network or server error: %s'), err.message || 'unknown'),
		}));
		return;
	}

	renderHooks.picker();
	renderHooks.form();
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot);
} else {
	boot();
}
