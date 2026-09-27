const root = document.querySelector('[data-cb-admin-navigation-editor]');

if (root) {
	const form = root.querySelector('[data-cb-admin-navigation-form]');
	const payloadInput = root.querySelector('[data-cb-admin-navigation-payload]');
	const orderEnabled = root.querySelector('[data-cb-admin-navigation-order-enabled]');
	const menuList = root.querySelector('[data-cb-admin-navigation-menu-list]');
	const reorderRoot = root.querySelector('[data-cb-admin-navigation-menu-reorder]');

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

	const audience = (row, prefix) => ({
		roles: uniqueReferences(row.querySelector(`[data-cb-admin-navigation-${prefix}-roles]`)?.value),
		capabilities: uniqueReferences(row.querySelector(`[data-cb-admin-navigation-${prefix}-capabilities]`)?.value),
	});

	const menuRows = () => Array.from(menuList?.children || [])
		.filter((item) => item.matches('[data-cb-admin-navigation-menu-row]'));

	const toolbarRows = () => Array.from(root.querySelectorAll('[data-cb-admin-navigation-toolbar-row]'));

	const buildPolicy = () => {
		const menuHidden = [];
		for (const row of menuRows()) {
			if (!row.querySelector('[data-cb-admin-navigation-hide]')?.checked) continue;
			menuHidden.push({
				id: row.dataset.navigationId || '',
				audience: audience(row, 'hide'),
			});
		}

		const toolbarHidden = [];
		const toolbarRenamed = [];
		for (const row of toolbarRows()) {
			const id = row.dataset.navigationId || '';
			if (row.querySelector('[data-cb-admin-navigation-hide]')?.checked) {
				toolbarHidden.push({ id, audience: audience(row, 'hide') });
			}

			const label = row.querySelector('[data-cb-admin-navigation-rename-label]')?.value.trim() || '';
			if (label) {
				toolbarRenamed.push({
					id,
					label,
					audience: audience(row, 'rename'),
				});
			}
		}

		return {
			version: Number.parseInt(root.dataset.policyVersion || '1', 10),
			menu: {
				order: orderEnabled?.checked ? menuRows().map((row) => row.dataset.navigationId || '').filter(Boolean) : [],
				hidden: menuHidden,
			},
			toolbar: {
				hidden: toolbarHidden,
				renamed: toolbarRenamed,
			},
		};
	};

	const syncPayload = () => {
		if (payloadInput) payloadInput.value = JSON.stringify(buildPolicy());
	};

	root.addEventListener('input', syncPayload);
	root.addEventListener('change', syncPayload);
	form?.addEventListener('submit', syncPayload);

	const reorderFoundation = window.cbCore?.reorder;
	if (reorderRoot && reorderFoundation?.enhance) {
		reorderFoundation.enhance(reorderRoot, {
			crossList: false,
			onMove() {
				if (orderEnabled) orderEnabled.checked = true;
				syncPayload();
			},
		});
	} else if (reorderRoot) {
		console.warn('[cb-core/admin-navigation] Reorder Foundation unavailable; menu ordering controls are inactive.');
	}

	syncPayload();
}
