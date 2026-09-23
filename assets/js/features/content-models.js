const dataEl = document.getElementById('wp-script-module-data-@cb-core/content-models');
let data = {};
try {
	data = dataEl ? JSON.parse(dataEl.textContent) : {};
} catch {
	data = {};
}
const i18n = data.i18n || {};
const ajaxUrl = data.ajaxUrl || window.ajaxurl || '';

const typeGuards = new WeakMap();

const setupTypeGuard = (fieldTypeSelect) => {
	const fieldForm = fieldTypeSelect.closest('form');
	const typeChangeConfirmation = fieldForm?.querySelector('[data-cb-cm-confirm-type-change]');
	const originalFieldId = fieldForm?.querySelector('input[name="original_field_id"]')?.value || '';
	if (!fieldForm || !typeChangeConfirmation || !originalFieldId) {
		return null;
	}

	let originalType = fieldTypeSelect.dataset.originalType || fieldTypeSelect.value;
	let acceptedType = originalType;
	let promptPending = false;

	const confirmTypeChange = async (nextType) => {
		if (nextType === acceptedType) {
			return true;
		}
		const modal = window.cbCore?.modal;
		if (!modal?.show) {
			console.warn('[cb-core/content-models] Modal Foundation unavailable; field type change cancelled.');
			return false;
		}
		return modal.show({
			title: i18n.typeChangeTitle || 'Change field type?',
			body: i18n.typeChangeBody || 'Changing a field type can change how existing WordPress metadata is interpreted. Existing values are preserved and are not automatically migrated.',
			confirmLabel: i18n.typeChangeConfirm || 'Change field type',
			cancelLabel: i18n.typeChangeCancel || 'Keep current type',
			confirmVariant: 'remediation',
		});
	};

	const ensureConfirmed = async () => {
		const nextType = fieldTypeSelect.value;
		if (nextType === originalType) {
			acceptedType = originalType;
			typeChangeConfirmation.value = '0';
			return true;
		}
		if (typeChangeConfirmation.value === '1' && nextType === acceptedType) {
			return true;
		}
		if (promptPending) {
			return false;
		}
		promptPending = true;
		fieldTypeSelect.disabled = true;
		const confirmed = await confirmTypeChange(nextType);
		fieldTypeSelect.disabled = false;
		promptPending = false;
		if (!confirmed) {
			fieldTypeSelect.value = acceptedType;
			fieldTypeSelect.dispatchEvent(new CustomEvent('cb:field-type-sync'));
			fieldTypeSelect.dispatchEvent(new CustomEvent('cb:select-picker-sync'));
			return false;
		}
		acceptedType = nextType;
		typeChangeConfirmation.value = '1';
		return true;
	};

	const commit = () => {
		originalType = fieldTypeSelect.value;
		acceptedType = originalType;
		fieldTypeSelect.dataset.originalType = originalType;
		typeChangeConfirmation.value = '0';
	};

	fieldTypeSelect.addEventListener('change', async () => {
		const nextType = fieldTypeSelect.value;
		if (nextType === originalType) {
			acceptedType = originalType;
			typeChangeConfirmation.value = '0';
			return;
		}
		if (nextType === acceptedType) {
			typeChangeConfirmation.value = acceptedType === originalType ? '0' : '1';
			return;
		}
		await ensureConfirmed();
	});

	const guard = { ensureConfirmed, commit };
	typeGuards.set(fieldForm, guard);
	return guard;
};

document.querySelectorAll('[data-cb-cm-field-type]').forEach(setupTypeGuard);

const relationTypes = new Set(['post_relation', 'user_relation', 'term_relation']);
const setupRelationSettings = (fieldTypeSelect) => {
	const form = fieldTypeSelect.closest('form');
	const settings = form?.querySelector('[data-cb-cm-relation-settings]');
	if (!form || !settings) return;
	const row = settings.closest('[data-cb-cm-relation-settings-row]');
	const targets = Array.from(settings.querySelectorAll('[data-cb-cm-relation-target]'));

	const sync = () => {
		const type = fieldTypeSelect.value;
		const active = relationTypes.has(type);
		settings.hidden = !active;
		if (row) row.hidden = !active;

		settings.querySelectorAll('input, select, textarea, button').forEach((control) => {
			control.disabled = !active;
		});
		if (!active) return;

		targets.forEach((section) => {
			const isTarget = section.dataset.cbCmRelationTarget === type;
			section.hidden = !isTarget;
			section.querySelectorAll('input, select, textarea, button').forEach((control) => {
				control.disabled = !isTarget;
			});
		});
	};

	fieldTypeSelect.addEventListener('change', sync);
	fieldTypeSelect.addEventListener('cb:field-type-sync', sync);
	sync();
};

document.querySelectorAll('[data-cb-cm-field-type]').forEach(setupRelationSettings);

// Full editor: preserve the modal confirmation before the normal POST submit.
document.querySelectorAll('form').forEach((form) => {
	if (form.matches('[data-cb-cm-quick-form]')) {
		return;
	}
	const guard = typeGuards.get(form);
	if (!guard) {
		return;
	}
	let resubmitting = false;
	form.addEventListener('submit', async (event) => {
		if (resubmitting) {
			return;
		}
		event.preventDefault();
		const confirmed = await guard.ensureConfirmed();
		if (!confirmed) {
			return;
		}
		resubmitting = true;
		form.requestSubmit();
	});
});

// Independent expandable Quick Edit rows.
document.querySelectorAll('[data-cb-cm-quick-toggle]').forEach((toggle) => {
	toggle.addEventListener('click', () => {
		const panelId = toggle.getAttribute('aria-controls');
		const panel = panelId ? document.getElementById(panelId) : null;
		if (!panel) {
			return;
		}
		const willOpen = panel.hidden;
		panel.hidden = !willOpen;
		const summary = panel.previousElementSibling;
		summary?.classList.toggle('is-quick-open', willOpen);
		summary?.querySelectorAll(`[data-cb-cm-quick-toggle][aria-controls="${panelId}"]`).forEach((button) => {
			button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			const icon = button.querySelector('.dashicons');
			if (icon) {
				icon.classList.toggle('dashicons-arrow-right-alt2', !willOpen);
				icon.classList.toggle('dashicons-arrow-down-alt2', willOpen);
			}
		});
		if (willOpen) {
			panel.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
		}
	});
});

// Quick Edit saves through the same server-side field boundary, without a page reload.
document.querySelectorAll('[data-cb-cm-quick-form]').forEach((form) => {
	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		if (!ajaxUrl) {
			return;
		}
		const guard = typeGuards.get(form);
		if (guard && !(await guard.ensureConfirmed())) {
			return;
		}

		const submitButton = form.querySelector('button[type="submit"]');
		const status = form.querySelector('[data-cb-cm-quick-status]');
		const panel = form.closest('[data-cb-cm-quick-row]');
		const fieldId = panel?.dataset.fieldId || '';
		const summary = Array.from(document.querySelectorAll('.cb-content-models-field-summary')).find((row) => row.dataset.fieldId === fieldId);
		if (submitButton) {
			submitButton.disabled = true;
		}
		if (status) {
			status.textContent = i18n.quickSaving || 'Saving…';
			status.classList.remove('is-error', 'is-success');
		}

		try {
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: new FormData(form),
			});
			const payload = await response.json();
			if (!payload?.success) {
				throw new Error(payload?.data?.message || i18n.quickError || 'The field could not be saved.');
			}
			const saved = payload.data || {};
			if (summary) {
				const label = summary.querySelector('[data-cb-cm-summary-label]');
				const type = summary.querySelector('[data-cb-cm-summary-type]');
				const rest = summary.querySelector('[data-cb-cm-summary-rest]');
				const required = summary.querySelector('[data-cb-cm-summary-required]');
				if (label) label.textContent = saved.label || '';
				if (type) type.textContent = saved.type_label || saved.type || '';
				if (rest) rest.textContent = saved.show_in_rest ? (i18n.restEnabled || 'Enabled') : (i18n.restDisabled || 'Disabled');
				if (required) {
					required.hidden = !saved.required;
					required.textContent = saved.required ? ` (${i18n.requiredLabel || 'required'})` : '';
				}
			}
			guard?.commit();
			if (status) {
				status.textContent = saved.message || i18n.quickSaved || 'Field saved.';
				status.classList.add('is-success');
			}
		} catch (error) {
			if (status) {
				status.textContent = error instanceof Error ? error.message : (i18n.quickError || 'The field could not be saved.');
				status.classList.add('is-error');
			}
		} finally {
			if (submitButton) {
				submitButton.disabled = false;
			}
		}
	});
});

// Field ordering: only the drag handle starts a drag, and the Quick Edit row travels with its summary row.
document.querySelectorAll('[data-cb-cm-sortable]').forEach((tbody) => {
	const orderFormId = tbody.dataset.orderForm || '';
	const orderForm = orderFormId ? document.getElementById(orderFormId) : null;
	const input = orderForm?.querySelector('[data-cb-cm-order-input]');
	const saveButton = document.querySelector(`[data-cb-cm-order-save][form="${orderFormId}"]`);
	if (!orderForm || !input || !saveButton) {
		return;
	}

	let draggingRow = null;
	let draggingQuickRow = null;
	let dropTarget = null;
	let dropBefore = true;

	const summaryRows = () => Array.from(tbody.querySelectorAll(':scope > .cb-content-models-field-summary'));
	const clearDropTargets = () => {
		summaryRows().forEach((row) => row.classList.remove('is-drop-before', 'is-drop-after'));
		dropTarget = null;
	};
	const updateOrder = () => {
		input.value = summaryRows().map((row) => row.dataset.fieldId).filter(Boolean).join(',');
		saveButton.disabled = false;
	};

	tbody.querySelectorAll('[data-cb-cm-drag-handle]').forEach((handle) => {
		handle.addEventListener('dragstart', (event) => {
			draggingRow = handle.closest('.cb-content-models-field-summary');
			draggingQuickRow = draggingRow?.nextElementSibling?.matches('[data-cb-cm-quick-row]') ? draggingRow.nextElementSibling : null;
			draggingRow?.classList.add('is-dragging');
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData('text/plain', draggingRow?.dataset.fieldId || '');
			}
		});
		handle.addEventListener('dragend', () => {
			draggingRow?.classList.remove('is-dragging');
			clearDropTargets();
			draggingRow = null;
			draggingQuickRow = null;
		});
	});

	summaryRows().forEach((row) => {
		row.addEventListener('dragover', (event) => {
			if (!draggingRow || draggingRow === row) {
				return;
			}
			event.preventDefault();
			clearDropTargets();
			const rect = row.getBoundingClientRect();
			dropBefore = event.clientY - rect.top < rect.height / 2;
			dropTarget = row;
			row.classList.add(dropBefore ? 'is-drop-before' : 'is-drop-after');
		});
		row.addEventListener('drop', (event) => {
			event.preventDefault();
			if (!draggingRow || !dropTarget || draggingRow === dropTarget) {
				return;
			}
			const targetQuick = dropTarget.nextElementSibling?.matches('[data-cb-cm-quick-row]') ? dropTarget.nextElementSibling : null;
			const reference = dropBefore ? dropTarget : (targetQuick?.nextSibling || dropTarget.nextSibling);
			if (reference !== draggingRow && reference !== draggingQuickRow) {
				tbody.insertBefore(draggingRow, reference);
				if (draggingQuickRow) {
					tbody.insertBefore(draggingQuickRow, reference);
				}
			}
			updateOrder();
			clearDropTargets();
		});
	});
});

// Structured field schema (Group / Repeater) in the Full Editor.
const structuredTypes = new Set(['group', 'repeater']);
const setupStructuredSettings = (fieldTypeSelect) => {
	const form = fieldTypeSelect.closest('form');
	const row = form?.querySelector('[data-cb-cm-structured-settings-row]');
	const settings = row?.querySelector('[data-cb-cm-structured-settings]');
	if (!form || !row || !settings) return;
	const repeaterLimits = settings.querySelector('[data-cb-cm-repeater-limits]');
	const sync = () => {
		const type = fieldTypeSelect.value;
		const active = structuredTypes.has(type);
		row.hidden = !active;
		settings.querySelectorAll('input, select, textarea, button').forEach((control) => {
			control.disabled = !active;
		});
		if (repeaterLimits) {
			repeaterLimits.hidden = type !== 'repeater';
			repeaterLimits.querySelectorAll('input, select, textarea, button').forEach((control) => {
				control.disabled = !active || type !== 'repeater';
			});
		}
	};
	fieldTypeSelect.addEventListener('change', sync);
	fieldTypeSelect.addEventListener('cb:field-type-sync', sync);
	sync();
};
document.querySelectorAll('[data-cb-cm-field-type]').forEach(setupStructuredSettings);

const setupSubfieldRow = (row, emptySubfieldLabel, reorderController = null) => {
	if (!row || row.dataset.cbCmSubfieldReady === '1') return;
	row.dataset.cbCmSubfieldReady = '1';
	const type = row.querySelector('[data-cb-cm-subfield-type]');
	const label = row.querySelector('[data-cb-cm-subfield-label]');
	const title = row.querySelector('[data-cb-cm-subfield-title]');
	const handle = row.querySelector('[data-cb-cm-subfield-handle]');
	const choice = row.querySelector('[data-cb-cm-subfield-choice-settings]');
	const number = row.querySelector('[data-cb-cm-subfield-number-settings]');
	const relation = row.querySelector('[data-cb-cm-subfield-relation-settings]');
	const relationTargets = Array.from(row.querySelectorAll('[data-cb-cm-subfield-relation-target]'));
	const sync = () => {
		const value = type?.value || 'text';
		if (choice) choice.hidden = !['select', 'radio', 'checkbox'].includes(value);
		if (number) number.hidden = value !== 'number';
		if (relation) relation.hidden = !relationTypes.has(value);
		relationTargets.forEach((target) => {
			target.hidden = target.dataset.cbCmSubfieldRelationTarget !== value;
		});
	};
	const syncLabel = () => {
		const currentLabel = label?.value.trim() || emptySubfieldLabel;
		if (title) title.textContent = currentLabel;
		row.dataset.cbCoreReorderLabel = currentLabel;
		if (handle) {
			handle.setAttribute('aria-label', `${i18n.reorderSubfield || 'Reorder'} ${currentLabel}`);
		}
	};
	type?.addEventListener('change', sync);
	label?.addEventListener('input', syncLabel);
	row.querySelector('[data-cb-cm-remove-subfield]')?.addEventListener('click', () => {
		row.remove();
		reorderController?.refresh();
	});
	sync();
	syncLabel();
};

const setupSubfieldBuilder = (root) => {
	if (root.dataset.cbCmSubfieldBuilderReady === '1') return;
	root.dataset.cbCmSubfieldBuilderReady = '1';
	const list = root.querySelector('[data-cb-cm-subfields]');
	const emptySubfieldLabel = root.dataset.emptySubfieldLabel || 'New subfield';
	const template = root.querySelector('[data-cb-cm-subfield-template]');
	const add = root.querySelector('[data-cb-cm-add-subfield]');
	if (!list || !template || !add) return;

	const reorderFoundation = window.cbCore?.reorder;
	const reorderController = reorderFoundation?.enhance
		? reorderFoundation.enhance(root, { crossList: false })
		: null;
	if (!reorderController) {
		console.warn('[cb-core/content-models] Reorder Foundation unavailable; subfield ordering controls are inactive.');
	}

	let counter = list.querySelectorAll('[data-cb-cm-subfield]').length;
	list.querySelectorAll('[data-cb-cm-subfield]').forEach((row) => {
		setupSubfieldRow(row, emptySubfieldLabel, reorderController);
	});

	add.addEventListener('click', () => {
		const html = template.innerHTML.replaceAll('__INDEX__', String(counter++));
		const holder = document.createElement('div');
		holder.innerHTML = html.trim();
		const row = holder.firstElementChild;
		if (!row) return;
		list.appendChild(row);
		setupSubfieldRow(row, emptySubfieldLabel, reorderController);
		reorderController?.refresh();
		row.querySelector('input:not([type="hidden"])')?.focus();
	});

	root.closest('form')?.addEventListener('submit', () => {
		list.querySelectorAll('[data-cb-cm-subfield]').forEach((row, index) => {
			row.querySelectorAll('[name^="sub_fields["]').forEach((control) => {
				control.name = control.name.replace(/^sub_fields\[[^\]]+\]/, `sub_fields[${index}]`);
			});
		});
	});
};
document.querySelectorAll('[data-cb-cm-structured-settings]').forEach(setupSubfieldBuilder);

// Definition-time Conditional Logic builder. Rules are AND inside a group; groups are OR.
const setupConditionBuilder = (root) => {
	if (root.dataset.cbCmConditionsReady === '1') return;
	root.dataset.cbCmConditionsReady = '1';
	const groups = root.querySelector('[data-cb-cm-condition-groups]');
	const groupTemplate = root.querySelector('[data-cb-cm-condition-group-template]');
	const ruleTemplate = root.querySelector('[data-cb-cm-condition-rule-template]');
	const addGroup = root.querySelector('[data-cb-cm-add-condition-group]');
	if (!groups || !groupTemplate || !ruleTemplate || !addGroup) return;

	const syncRule = (rule) => {
		const operator = rule.querySelector('[data-cb-cm-condition-operator]');
		const value = rule.querySelector('[data-cb-cm-condition-value]');
		if (!operator || !value) return;
		const sync = () => {
			const noValue = ['empty', 'not_empty'].includes(operator.value);
			value.hidden = noValue;
			value.disabled = noValue;
		};
		operator.addEventListener('change', sync);
		rule.querySelector('[data-cb-cm-remove-condition]')?.addEventListener('click', () => rule.remove());
		sync();
	};
	const bindGroup = (group) => {
		group.querySelector('[data-cb-cm-remove-condition-group]')?.addEventListener('click', () => group.remove());
		group.querySelectorAll('[data-cb-cm-condition-rule]').forEach(syncRule);
		group.querySelector('[data-cb-cm-add-condition]')?.addEventListener('click', () => {
			const list = group.querySelector('[data-cb-cm-condition-rules]');
			if (!list) return;
			const holder = document.createElement('div');
			holder.innerHTML = ruleTemplate.innerHTML.replaceAll('__GROUP__', '0').replaceAll('__RULE__', String(list.children.length)).trim();
			const rule = holder.firstElementChild;
			if (rule) {
				list.appendChild(rule);
				syncRule(rule);
			}
		});
	};
	groups.querySelectorAll('[data-cb-cm-condition-group]').forEach(bindGroup);
	addGroup.addEventListener('click', () => {
		const holder = document.createElement('div');
		holder.innerHTML = groupTemplate.innerHTML.replaceAll('__GROUP__', String(groups.children.length)).replaceAll('__RULE__', '0').trim();
		const group = holder.firstElementChild;
		if (group) {
			groups.appendChild(group);
			bindGroup(group);
		}
	});
	root.closest('form')?.addEventListener('submit', () => {
		groups.querySelectorAll('[data-cb-cm-condition-group]').forEach((group, groupIndex) => {
			group.querySelectorAll('[data-cb-cm-condition-rule]').forEach((rule, ruleIndex) => {
				rule.querySelectorAll('[name^="conditional_logic["]').forEach((control) => {
					const suffix = control.name.replace(/^conditional_logic\[[^\]]+\]\[[^\]]+\]/, '');
					control.name = `conditional_logic[${groupIndex}][${ruleIndex}]${suffix}`;
				});
			});
		});
	});
};
document.querySelectorAll('[data-cb-cm-conditions]').forEach(setupConditionBuilder);
