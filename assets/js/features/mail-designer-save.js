/**
 * Mail persistence transport. The Mail editor owns the Base save checkpoint;
 * this module never guesses at server truth from HTTP status alone.
 */
const root = document.querySelector('[data-cb-mail-designer]');

if (root) {
	const form = root.querySelector('[data-cb-mail-designer-form]');
	const shell = root.querySelector('[data-cb-design-shell]');
	const ajaxUrl = String(root.dataset.ajaxUrl || '').trim();
	let saveController = null;
	let outcomeUnknown = false;

	const announceSaveState = (state, message = '') => {
		shell?.dispatchEvent(new CustomEvent('cb:design-shell:savechange', {
			bubbles: true,
			detail: Object.freeze({
				state: String(state || ''),
				message: String(message || ''),
			}),
		}));
	};

	const requestCapture = () => {
		let captured = null;
		root.dispatchEvent(new CustomEvent('cb:mail:savecapture', {
			detail: Object.freeze({ accept: (value) => { captured = value; } }),
		}));
		if (!captured || !captured.checkpoint || typeof captured.revision !== 'string') {
			throw new Error('Mail save is not ready. Reload the editor before saving.');
		}
		return captured;
	};

	const saveInPlace = async () => {
		if (!form || !ajaxUrl || saveController || outcomeUnknown) return;
		let captured = null;
		let requestStarted = false;
		let responseParsed = false;
		const controller = new AbortController();
		try {
			captured = requestCapture();
			saveController = controller;
			announceSaveState('saving');

			// Never serialize live form fields after the checkpoint was taken.
			const body = new FormData(form);
			body.set('template_id', captured.templateId);
			body.set('subject', captured.subject);
			body.set('project_json', JSON.stringify(captured.checkpoint.submittedProject));
			body.set('expected_revision', captured.revision);
			requestStarted = true;
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
				signal: controller.signal,
			});
			const payload = await response.json();
			responseParsed = true;
			if (!response.ok || payload?.success !== true) {
				throw new Error(payload?.data?.message || 'The mail template could not be saved.');
			}
			let accepted = false;
			root.dispatchEvent(new CustomEvent('cb:mail:saveack', {
				detail: Object.freeze({
					captured,
					data: payload.data,
					accept: () => { accepted = true; },
				}),
			}));
			if (!accepted) throw new Error('Mail save acknowledgement was rejected. Reload before saving again.');
			announceSaveState('saved', payload.data?.message || '');
		} catch (error) {
			// Aborting transport does not undo a server commit.
			root.dispatchEvent(new CustomEvent('cb:mail:savefailed', {
				detail: Object.freeze({ captured }),
			}));
			if (requestStarted && !responseParsed) outcomeUnknown = true;
			announceSaveState(outcomeUnknown ? 'unknown' : 'error', outcomeUnknown
				? 'Mail save outcome is uncertain. Reload before saving.'
				: error?.message || 'The mail template could not be saved.');
		} finally {
			if (saveController === controller) saveController = null;
		}
	};

	if (form && ajaxUrl && typeof fetch === 'function' && typeof FormData === 'function') {
		form.addEventListener('submit', (event) => {
			if (event.defaultPrevented) return;
			event.preventDefault();
			void saveInPlace();
		});
	}
	window.addEventListener('pagehide', () => {
		saveController?.abort();
	}, { once: true });
}
