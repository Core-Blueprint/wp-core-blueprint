/**
 * Native Mail T4 JSON portability UI. Never writes the Designer Host.
 * JSON transfer is offline; only the authenticated same-origin REST boundary
 * performs a provider-authorized Data Exchange export/preflight/create.
 */
const element = (tag, text = '') => {
	const node = document.createElement(tag);
	if (text) node.textContent = text;
	return node;
};

export async function exportMailTemplate({api, item, label, toast}) {
	try {
		const revision = Number(item?.revision);
		if (typeof item?.id !== 'string' || !Number.isSafeInteger(revision) || revision < 1) {
			throw new Error('MAIL_EXPORT_IDENTITY_INVALID');
		}
		const reply = await api('export', {id: item.id, revision});
		if (reply.status === 'unsupported') {
			toast.error(label('mediaNeedsZip', 'This template contains images or embedded media. JSON export supports asset-free templates only.'));
			return false;
		}
		if (reply.status !== 'ready' || typeof reply.data !== 'string'
			|| reply.data.length < 1 || reply.data.length > 1150000) {
			throw new Error('MAIL_EXPORT_UNVERIFIED');
		}
		const blob = new Blob([reply.data], {type: 'application/json'});
		const url = URL.createObjectURL(blob);
		const link = element('a');
		link.href = url;
		link.download = 'core-blueprint-mail-template.json';
		document.body.append(link);
		try {
			link.click();
		} finally {
			link.remove();
			setTimeout(() => URL.revokeObjectURL(url), 30000);
		}
		toast.success(label('exported', 'Mail template JSON is ready to download.'));
		return true;
	} catch (_) {
		toast.error(label('error', 'Template operation could not be verified. Reload before retrying.'));
		return false;
	}
}

function newDestination() {
	if (typeof crypto?.getRandomValues !== 'function') throw new Error('MAIL_IMPORT_RANDOM_UNAVAILABLE');
	const bytes = crypto.getRandomValues(new Uint8Array(16));
	return 'mail.saved.' + Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
}

export function mountMailImport({root, api, label, toast, showModal, reload, onUncertain}) {
	if (!root || typeof api !== 'function') return;
	const controls = element('div');
	controls.className = 'cb-core-mail-saved-import';
	const button = element('button', label('importJson', 'Import JSON'));
	button.type = 'button';
	button.className = 'button';
	const fileInput = element('input');
	fileInput.type = 'file';
	fileInput.accept = '.json,application/json';
	fileInput.hidden = true;
	fileInput.setAttribute('aria-label', label('importJson', 'Import JSON'));
	controls.append(button, fileInput);
	const list = root.querySelector?.('[data-cb-mail-saved-list]');
	if (list && typeof list.before === 'function') list.before(controls);
	else root.append(controls);
	let busy = false;
	let uncertain = false;

	button.addEventListener('click', () => {
		if (!busy && !uncertain) fileInput.click();
	});
	fileInput.addEventListener('change', async () => {
		const file = fileInput.files?.[0];
		if (!file || busy || uncertain) return;
		busy = true;
		button.disabled = true;
		let applyAttempted = false;
		try {
			if (!file.size || file.size > 1150000) throw new Error('MAIL_IMPORT_FILE_SIZE');
			const json = await file.text();
			if (json.length > 1150000) throw new Error('MAIL_IMPORT_FILE_SIZE');
			const destinationId = newDestination();
			const result = await api('preview', {file: json, destinationId});
			if (result.status !== 'ready' || result.preview?.valid !== true
				|| result.preview.record_count !== 1
				|| result.preview.counts?.create !== 1
				|| typeof result.preview.fingerprint !== 'string'
				|| !/^[a-f0-9]{64}$/.test(result.preview.fingerprint)) {
				throw new Error('MAIL_IMPORT_PREVIEW_INVALID');
			}
			const body = element('div');
			body.append(
				element('p', label('importConfirm', 'Create a new personal Mail template? Existing templates and live Mail designs will not be changed.')),
				element('p', file.name)
			);
			// Presentation only; the server-side Data Exchange preview remains
			// the sole authority for validating and importing the JSON.
			try {
				const metadata = JSON.parse(json);
				const title = metadata?.records?.[0]?.title;
				if (typeof title === 'string' && title.length <= 191) {
					body.append(element('p', label('importTemplate', 'Template') + ': ' + title));
				}
			} catch (_) { /* already checked by server preview */ }
			const accepted = await showModal({
				title: label('importTitle', 'Import Mail template'),
				body, confirmLabel: label('importAction', 'Import as new copy'),
			});
			if (accepted !== true) return;
			applyAttempted = true;
			const imported = await api('import', {
				file: json, destinationId, fingerprint: result.preview.fingerprint,
			});
			if (imported.status !== 'created' || imported.id !== destinationId) {
				throw new Error('MAIL_IMPORT_UNVERIFIED');
			}
			toast.success(label('imported', 'New personal Mail template imported.'));
			await reload();
		} catch (_) {
			// An ambiguous POST MUST NOT automatically retry with a new ID.
			if (applyAttempted) {
				uncertain = true;
				if (typeof onUncertain === 'function') onUncertain();
			}
			toast.error(label('error', 'Template operation could not be verified. Reload before retrying.'));
		} finally {
			fileInput.value = '';
			busy = false;
			button.disabled = uncertain;
		}
	});
}
