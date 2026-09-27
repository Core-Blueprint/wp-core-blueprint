const roots = document.querySelectorAll('[data-cb-admin-columns-governance]');

roots.forEach((root) => {
	const reorder = window.cbCore?.reorder;
	const reorderRoot = root.querySelector('[data-cb-core-reorder]');
	const controller = reorder?.enhance && reorderRoot ? reorder.enhance(reorderRoot, { crossList: false }) : null;
	const list = reorderRoot?.querySelector('[data-cb-core-reorder-list="columns"]');
	const save = root.querySelector('[data-admin-columns-save]');
	const reset = root.querySelector('[data-admin-columns-reset]');
	const spinner = root.querySelector('[data-admin-columns-spinner]');
	const status = root.querySelector('[data-admin-columns-status]');
	if (!list || !save || !reset) return;

	const sourceValues = (kind) => Array.from(root.querySelectorAll(`[data-source-toggle="${kind}"]:checked`))
		.map((input) => input.value)
		.filter(Boolean);

	const sourceEnabled = (item) => {
		const kind = item.dataset.sourceKind || '';
		const key = item.dataset.sourceKey || '';
		if (!kind || !key) return true;
		const source = root.querySelector(`[data-source-toggle="${CSS.escape(kind)}"][value="${CSS.escape(key)}"]`);
		return !source || source.checked;
	};

	const screenPolicy = () => {
		const items = Array.from(list.querySelectorAll(':scope > [data-cb-core-reorder-item]')).filter(sourceEnabled);
		return {
			order: items.map((item) => item.dataset.cbCoreReorderItem || '').filter(Boolean),
			hidden: items
				.filter((item) => {
					const toggle = item.querySelector('[data-column-visible]');
					return toggle && !toggle.checked && !toggle.disabled;
				})
				.map((item) => item.dataset.cbCoreReorderItem || '')
				.filter(Boolean),
			taxonomies: sourceValues('taxonomy'),
			meta: sourceValues('meta'),
		};
	};

	const setBusy = (busy, message = '') => {
		save.disabled = busy;
		reset.disabled = busy;
		if (spinner) spinner.classList.toggle('is-active', busy);
		root.setAttribute('aria-busy', busy ? 'true' : 'false');
		if (status) status.textContent = message;
	};

	const mutate = async (operation) => {
		const body = new FormData();
		body.set('action', root.dataset.action || '');
		body.set('nonce', root.dataset.nonce || '');
		body.set('screen_id', root.dataset.screenId || '');
		body.set('operation', operation);
		if (operation === 'save') body.set('screen_policy', JSON.stringify(screenPolicy()));

		setBusy(true, root.dataset.saving || 'Saving…');
		try {
			const response = await fetch(root.dataset.ajaxUrl || window.ajaxurl || '', {
				method: 'POST',
				credentials: 'same-origin',
				body,
			});
			const payload = await response.json();
			if (!payload?.success) {
				throw new Error(payload?.data?.message || root.dataset.error || 'The site-wide column policy could not be saved.');
			}
			window.location.reload();
		} catch (error) {
			setBusy(false, error instanceof Error ? error.message : (root.dataset.error || 'The site-wide column policy could not be saved.'));
		}
	};

	save.addEventListener('click', () => mutate('save'));
	reset.addEventListener('click', () => mutate('reset'));

	root.querySelectorAll('[data-source-toggle]').forEach((toggle) => {
		toggle.addEventListener('change', () => controller?.refresh());
	});
});
