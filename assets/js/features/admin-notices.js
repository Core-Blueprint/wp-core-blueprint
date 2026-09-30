const root = document.querySelector('[data-cb-admin-notices-editor]');

if (root) {
	const form = root.querySelector('[data-cb-admin-notices-form]');
	const payloadInput = root.querySelector('[data-cb-admin-notices-payload]');

	const uniqueReferences = (value) => {
		const seen = new Set();
		return String(value || '')
			.split(',')
			.map((item) => item.trim())
			.filter((item) => {
				if (!item || seen.has(item)) return false;
				seen.add(item);
				return true;
			});
	};

	const rows = () => Array.from(root.querySelectorAll('[data-cb-admin-notices-source]'));

	const pickerValue = (row, kind) => (
		row.querySelector('[data-cb-admin-notices-' + kind + '-picker] [data-cb-core-object-picker-input]')?.value
		|| ''
	);

	const selectedAudience = (row) => ({
		roles: uniqueReferences(pickerValue(row, 'roles')),
		capabilities: uniqueReferences(pickerValue(row, 'capabilities')),
	});

	const buildPolicy = () => {
		const rules = [];

		for (const row of rows()) {
			if (row.dataset.manageable !== '1') continue;

			const source = row.dataset.sourceId || '';
			const visibility = row.querySelector('[data-cb-admin-notices-visibility]:checked')?.value || 'everyone';
			if (!source || visibility === 'everyone') continue;

			rules.push({
				source,
				visibility,
				audience: visibility === 'selected'
					? selectedAudience(row)
					: { roles: [], capabilities: [] },
			});
		}

		return {
			version: Number.parseInt(root.dataset.policyVersion || '1', 10),
			rules,
		};
	};

	const syncRowPresentation = (row) => {
		const visibility = row.querySelector('[data-cb-admin-notices-visibility]:checked')?.value || 'everyone';
		const selected = row.querySelector('[data-cb-admin-notices-selected-audience]');
		if (selected) selected.hidden = visibility !== 'selected';
	};

	const syncPayload = () => {
		if (payloadInput) payloadInput.value = JSON.stringify(buildPolicy());
	};

	const syncEditor = () => {
		for (const row of rows()) syncRowPresentation(row);
		syncPayload();
	};

	root.addEventListener('input', syncEditor);
	root.addEventListener('change', syncEditor);
	form?.addEventListener('submit', syncPayload);

	syncEditor();
}
