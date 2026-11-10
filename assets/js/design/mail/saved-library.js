/**
 * T3-02 Mail-owned reusable templates.
 * Operates on separate saved assets, never on live Designer persistence.
 * Only trusted Host editor facade may Apply a server-issued native document.
 */
import { open as openTemplateBrowser, loadForApply } from '@cb-core/template-browser';
import { show as showModal } from '@cb-core/modal';
import { toast } from '@cb-core/toast';

const config = typeof window !== 'undefined' ? window.cbCoreMailSaved : null;
const label = (key, fallback) =>
	typeof config?.labels?.[key] === 'string' ? config.labels[key] : fallback;
const errorMessage = () => label('error', 'The template operation could not be verified. Reload before retrying.');

function authorizedUrl(action) {
	if (typeof config?.nonce !== 'string' || !config.nonce || typeof config.restRoot !== 'string') {
		throw new Error('MAIL_SAVED_AUTHORITY_UNAVAILABLE');
	}
	const base = new URL(config.restRoot, location.href);
	if (base.origin !== location.origin
		|| !base.pathname.endsWith('/core-blueprint/v1/mail-templates/')) {
		throw new Error('MAIL_SAVED_AUTHORITY_UNAVAILABLE');
	}
	return new URL(action, base);
}

async function api(action, data = null, {scope = '', signal = null} = {}) {
	const url = authorizedUrl(action);
	const headers = {'X-WP-Nonce': config.nonce, 'Accept': 'application/json'};
	if (scope) headers['X-CB-Designer-Scope'] = scope;
	let method = 'GET';
	if (data !== null && action !== 'list') {
		method = 'POST';
		headers['Content-Type'] = 'application/json';
	} else if (data) {
		for (const [key, value] of Object.entries(data)) if (value !== '') url.searchParams.set(key, String(value));
	}
	const response = await fetch(url.href, {
		method, credentials: 'same-origin', headers, signal,
		...(method === 'POST' ? {body: JSON.stringify(data)} : {}),
	});
	const result = await response.json();
	if (!result || typeof result !== 'object' || Array.isArray(result)
		|| typeof result.status !== 'string'
		|| (!response.ok && ['created', 'renamed', 'deleted', 'ready'].includes(result.status))) {
		throw new Error('MAIL_SAVED_UNVERIFIED');
	}
	// A structured refusal is not a successful mutation. The caller must
	// check an exact result status before rendering any success feedback.
	return result;
}

function element(tag, className = '', value = '') {
	const node = document.createElement(tag);
	if (className) node.className = className;
	if (value) node.textContent = value;
	return node;
}

function nameDialogBody(value = '', visibility = false) {
	const body = element('div', 'cb-core-mail-saved-dialog');
	const textLabel = element('label', 'cb-core-field__label', label('templateName', 'Template name'));
	const input = element('input', 'regular-text');
	input.type = 'text';
	input.required = true;
	input.maxLength = 191;
	input.value = value;
	input.autocomplete = 'off';
	textLabel.append(input);
	body.append(textLabel);
	let select = null;
	if (visibility) {
		const originLabel = element('label', 'cb-core-field__label', label('availability', 'Availability'));
		select = element('select');
		for (const [key, name] of [
			['personal', label('personal', 'My templates')],
			['site', label('site', 'Site templates')],
		]) {
			const option = element('option', '', name);
			option.value = key;
			select.append(option);
		}
		originLabel.append(select);
		body.append(originLabel);
	}
	const notice = element('p');
	notice.setAttribute('role', 'status');
	body.append(notice);
	return {body, input, select, notice};
}

function canCapture(host) {
	if (host?.state !== 'READY' || host.profileId !== 'base/mail' || !host.context || !host.editor) return false;
	try {
		const status = host.editor.saveStatus();
		return !status?.blocked && ['READY_CLEAN', 'READY_DIRTY'].includes(status?.state);
	} catch (_) {
		// An uncertain or switching Host can never authorize an asset snapshot.
		return false;
	}
}

function targetOf(host) {
	const identity = host.context;
	return Object.freeze({
		profileId: host.profileId,
		resourceType: identity.resourceType,
		resourceKey: identity.resourceId,
		contextEpoch: identity.contextEpoch,
	});
}

/**
 * Bind native Mail actions to the existing Host toolbar, not a parallel editor
 * or a second document Save. Caller owns the root lifecycle.
 */
/**
 * One Base-owned Browser icon control opens a unified native workspace.
 * Active design navigation and reusable Template Apply remain separate Host actions.
 */
export function bindMailTemplates({host, root, contextSlot, trigger, designs = [],
	activeDesignId = '', onOpenDesign = null, scopeToken} = {}) {
	if (!host || !root || !contextSlot || root.isConnected !== true
		|| !trigger || typeof trigger.addEventListener !== 'function') {
		throw new Error('MAIL_SAVED_CONTEXT_REFUSED');
	}
	let uncertain = false;
	let busy = false;
	let saving = false;
	trigger.disabled = typeof scopeToken !== 'string' || !scopeToken;
	if (trigger.disabled) trigger.title = 'Template authorization is unavailable. Reload the Designer.';
	const launchSaveAs = () => {
		if (saving || uncertain || !canCapture(host) || !scopeToken) {
			toast.error(label('saveBusy', 'Finish or verify the current Save before saving a template.'));
			return Promise.resolve(false);
		}
		saving = true;
		const form = nameDialogBody('', true);
		return showModal({
			title: label('saveAsTitle', 'Save as Mail template'), body: form.body,
			confirmLabel: label('saveAction', 'Save template'), initialFocus: form.input,
			onConfirm: async () => {
				if (uncertain) { form.notice.textContent = errorMessage(); return false; }
				const title = form.input.value.trim();
				if (!title || title.length > 191 || !canCapture(host)) {
					form.notice.textContent = label('nameAndState', 'Enter a name and verify the current Designer Save state.');
					return false;
				}
				let captured;
				try {
					if (!canCapture(host)) throw new Error('MAIL_SAVED_CONTEXT_REFUSED');
					const target = targetOf(host);
					captured = {
						title, visibility: form.select.value,
						...target,
						document: {
							project: host.editor.snapshot(),
							fields: host.editor.persistedFields(),
						},
					};
				} catch (_) {
					// No request was attempted; this is a proven local refusal,
					// not an ambiguous asset write.
					form.notice.textContent = label('contextChanged', 'The Designer context changed. No template was saved.');
					return false;
				}
				try {
					const result = await api('create', captured, {scope: scopeToken});
					if (result.status === 'created' && typeof result.id === 'string') {
						toast.success(label('savedConfirmation', 'Reusable Mail template saved.'));
						return true;
					}
					if (result.status === 'unknown' || result.status === 'unavailable') uncertain = true;
					form.notice.textContent = errorMessage();
					return false;
				} catch (_) {
					// A transport error is NOT evidence that the template asset
					// was not committed. Prevent accidental duplicate writes.
					uncertain = true;
					form.notice.textContent = errorMessage();
					return false;
				}
			},
		}).catch(() => toast.error(errorMessage())).finally(() => { saving = false; });
	};
	const openBrowser = () => {
		if (busy || uncertain || !canCapture(host) || !scopeToken) {
			toast.error(label('saveBusy', 'Finish or verify the current Save before continuing.'));
			return;
		}
		busy = true;
		void openTemplateBrowser({
			contextToken: scopeToken,
			title: label('browser', 'Browser'),
			designs,
			activeDesignId,
			onOpenDesign,
			onSaveAs: launchSaveAs,
			saveAsLabel: label('saveAs', 'Save as template'),
			onChoose: async (item) => {
				if (!['core-blueprint-mail', 'core-blueprint-mail-saved'].includes(item?.source)
					|| !canCapture(host)) {
					toast.error(label('notAuthorized', 'The selected template is not authorized for this Mail Designer.'));
					return false;
				}
				try {
					const intent = host.editor.captureApplyIntent();
					const reply = await loadForApply({
						contextToken: scopeToken, source: item.source, templateId: item.id,
						target: targetOf(host),
					});
					const changed = host.editor.applyTemplate(reply, intent);
					if (changed) toast.success(label('applied', 'Template applied. Save the Mail design to keep these changes.'));
					return true;
				} catch (_) {
					toast.error(label('applyRefused', 'Template Apply was refused or the context changed. No new Save was made.'));
					return false;
				}
			},
		}).catch(() => toast.error(errorMessage())).finally(() => { busy = false; });
	};
	trigger.addEventListener('click', openBrowser);
	return () => {
		trigger.removeEventListener('click', openBrowser);
		trigger.disabled = true;
	};
}

function mountManagement(root) {
	const surface = root.querySelector('[data-cb-mail-saved-list]');
	if (!surface) return;
	let items = [];
	let cursor = '';
	let loading = false;
	let uncertain = false;
	const status = element('p');
	status.setAttribute('role', 'status');
	const list = element('ul', 'cb-core-mail-saved-list');
	const more = element('button', 'button', label('loadMore', 'Load more'));
	more.type = 'button';
	more.hidden = true;
	status.textContent = label('loading', 'Loading saved templates…');
	surface.replaceChildren(status, list, more);
	const render = () => {
		list.replaceChildren();
		for (const item of items) {
			const row = element('li', 'cb-core-mail-saved-item');
			const details = element('span', 'cb-core-mail-saved-item__title',
				item.title + ' · ' + label(item.visibility, item.visibility));
			const actions = element('span', 'cb-core-mail-saved-item__actions');
			for (const [action, name] of [
				['rename', label('rename', 'Rename')],
				['duplicate', label('duplicate', 'Duplicate')],
				['delete', label('delete', 'Delete')],
			]) {
				const button = element('button', 'button', name);
				button.type = 'button';
				button.setAttribute('aria-label', name + ': ' + item.title);
				button.disabled = uncertain;
				button.addEventListener('click', () => { void manage(item, action); });
				actions.append(button);
			}
			row.append(details, actions);
			list.append(row);
		}
		more.hidden = !cursor || uncertain;
		more.disabled = loading || uncertain;
		status.textContent = items.length ? '' : label('empty', 'No saved templates yet.');
	};
	const load = async (reset = false) => {
		if (loading || uncertain) return;
		loading = true;
		more.disabled = true;
		try {
			const result = await api('list', {limit: 24, cursor: reset ? '' : cursor});
			if (result.status !== 'ready' || !Array.isArray(result.items)) throw new Error('MAIL_SAVED_UNVERIFIED');
			if (reset) items = [];
			items.push(...result.items.filter(item =>
				typeof item.id === 'string' && typeof item.title === 'string'
				&& ['personal', 'site'].includes(item.visibility)
				&& Number.isSafeInteger(Number(item.revision)) && Number(item.revision) > 0
			));
			cursor = typeof result.next === 'string' ? result.next : '';
			render();
		} catch (_) {
			status.textContent = errorMessage();
			uncertain = true;
			render();
			status.textContent = errorMessage();
		} finally {
			loading = false;
			more.disabled = uncertain || !cursor;
		}
	};
	const manage = async (item, action) => {
		if (loading || uncertain) return;
		const revision = Number(item.revision);
		if (!Number.isSafeInteger(revision) || revision < 1) return;
		let name = '';
		if (action === 'delete') {
			const confirmed = await showModal({
				title: label('deleteTitle', 'Delete Mail template'), body: label('deleteBody', 'Delete this reusable template? The live Mail design will not be changed.'),
				confirmVariant: 'danger', confirmLabel: label('deleteAction', 'Delete template'),
			});
			if (confirmed !== true) return;
		} else {
			const form = nameDialogBody(action === 'rename' ? item.title : item.title + ' copy');
			const accepted = await showModal({
				title: action === 'rename' ? label('renameTitle', 'Rename Mail template') : label('duplicateTitle', 'Duplicate Mail template'),
				body: form.body, confirmLabel: action === 'rename' ? 'Rename' : 'Duplicate',
				initialFocus: form.input,
				onConfirm: () => {
					name = form.input.value.trim();
					if (!name || name.length > 191) {
						form.notice.textContent = label('nameRequired', 'Enter a template name.');
						return false;
					}
					return true;
				},
			});
			if (accepted !== true) return;
		}
		loading = true;
		more.disabled = true;
		try {
			const result = await api(action, {
				id: item.id, revision,
				...(action === 'delete' ? {} : {title: name}),
			});
			const success = action === 'rename' ? 'renamed' : (action === 'duplicate' ? 'created' : 'deleted');
			if (result.status === success) {
				toast.success(label('updated', 'Saved Mail template updated.'));
				loading = false;
				await load(true);
				return;
			}
			if (result.status === 'unknown' || result.status === 'unavailable') uncertain = true;
			toast.error(result.status === 'conflict'
				? label('conflict', 'This template was changed elsewhere. Reload before trying again.') : errorMessage());
			if (result.status === 'conflict') {
				loading = false;
				await load(true);
				return;
			}
		} catch (_) {
			uncertain = true;
			toast.error(errorMessage());
		} finally {
			loading = false;
			render();
		}
	};
	more.addEventListener('click', () => { void load(); });
	void load(true);
}

if (typeof document !== 'undefined') {
	const init = () => {
		const management = document.querySelector('[data-cb-mail-saved-manager]');
		if (management) mountManagement(management);
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
	else init();
}
