/**
 * Core Blueprint - Notes feature module.
 *
 * Renders the Notes admin page UI: filter form, results list, modal-based
 * note editor, bulk actions (delete, archive), import/export, layout
 * toggle, AJAX pagination + filtering, soft-state preservation across
 * navigation (back/forward via popstate).
 *
 * Modal confirmations and toast notifications go through the shared
 * cbCore APIs (window.cbCore.modal.show, window.cbCore.toast.*) - no
 * module-local implementations.
 *
 * REST contract: list + action endpoints under core-blueprint/v1/notes/*
 * with the cb_manage_notes capability check.
 *
 * @since   1.0.0
 */

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/notes' );
const config = dataEl ? JSON.parse( dataEl.textContent ) : {};

import { qs, qsa } from '../core/dom.js';
    const results = () => qs('#cb-notes-results');
    const filtersForm = () => qs('#cb-notes-filters');

    function debounce(fn, delay = 300) {
        let timer;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    }

    function collectForm(form) {
        const data = {};
        new FormData(form).forEach((value, key) => {
            data[key] = value;
        });
        return data;
    }

    function collectFilters() {
        const form = filtersForm();
        return form ? collectForm(form) : {};
    }

    function showToast(message, type = 'success') {
        const toast = window.cbCore.toast;

        if (typeof toast[type] === 'function') {
            toast[type](message);
            return;
        }

        toast(message, type);
    }

    async function confirmAction(message, danger = false, confirmLabel = null) {
        return window.cbCore.modal.show({
            title: danger ? 'Confirm delete' : 'Confirm action',
            body: message,
            confirmLabel: confirmLabel || (danger ? 'Delete' : 'Confirm'),
            cancelLabel: 'Cancel',
            danger,
        });
    }

    async function confirmDeleteAllNotes() {
        const phrase = 'DELETE ALL NOTES';

        return window.cbCore.modal.show({
            title: 'Delete all notes?',
            body: 'This permanently removes every Core Blueprint Note on this site. This action cannot be undone.',
            confirmLabel: 'Delete all notes',
            cancelLabel: 'Cancel',
            confirmVariant: 'danger',
            typedConfirm: phrase,
            typedConfirmHint: 'Type the phrase to confirm:',
        });
    }

    async function confirmBulkDeleteNotes(count) {
        const phrase = 'DELETE';

        return window.cbCore.modal.show({
            title: `Delete ${count} selected notes?`,
            body: `This permanently removes ${count} selected notes. This action cannot be undone.`,
            confirmLabel: 'Delete selected notes',
            cancelLabel: 'Cancel',
            confirmVariant: 'danger',
            typedConfirm: phrase,
            typedConfirmHint: 'Type the phrase to confirm:',
        });
    }

    function selectedNoteIds() {
        return qsa('[data-cb-notes-select-note]:checked')
            .map((checkbox) => Number.parseInt(checkbox.value, 10))
            .filter((id) => Number.isInteger(id) && id > 0);
    }

    function updateBulkBar() {
        const bar = qs('[data-cb-notes-bulk-bar]');
        const count = selectedNoteIds().length;

        if (!bar) return;

        bar.hidden = count === 0;

        const summary = qs('[data-cb-notes-selected-summary]', bar);
        if (summary) {
            const template = count === 1
                ? (config.i18n?.noteSelected || '%d note selected.')
                : (config.i18n?.notesSelected || '%d notes selected.');
            summary.textContent = template.replace('%d', String(count));
        }
    }

    function clearSelection() {
        qsa('[data-cb-notes-select-note]:checked').forEach((checkbox) => {
            checkbox.checked = false;
        });

        updateBulkBar();
    }

    function selectVisibleNotes() {
        qsa('[data-cb-notes-select-note]').forEach((checkbox) => {
            checkbox.checked = true;
        });

        updateBulkBar();
    }

    function validLayout(layout) {
        return ['list', 'grid-2', 'grid-3'].includes(layout) ? layout : 'list';
    }

    function preferredNotesLayout() {
        return validLayout(window.localStorage.getItem('cbNotesLayout') || config.settings?.default_layout || 'list');
    }

    function applyNotesLayout(layout = preferredNotesLayout()) {
        const normalized = validLayout(layout);
        const list = qs('[data-cb-notes-list]');
        if (!list) return;

        list.classList.remove('cb-notes-list--list', 'cb-notes-list--grid-2', 'cb-notes-list--grid-3');
        list.classList.add(`cb-notes-list--${normalized}`);
        list.dataset.cbNotesLayoutCurrent = normalized;

        qsa('[data-cb-notes-layout]').forEach((button) => {
            const isActive = button.dataset.cbNotesLayout === normalized;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function setNotesLayout(layout) {
        const normalized = validLayout(layout);
        window.localStorage.setItem('cbNotesLayout', normalized);
        applyNotesLayout(normalized);
    }

    function preferredDetailsState() {
        const mode = config.settings?.details_initial_state || 'remember';

        if (mode === 'open') return true;
        if (mode === 'closed') return false;

        return window.localStorage.getItem('cbNotesDetailsOpen') === 'true';
    }

    function initModalDetails(root) {
        const details = qs('[data-cb-notes-details]', root);
        if (!details) return;

        details.open = preferredDetailsState();
        details.addEventListener('toggle', () => {
            if ((config.settings?.details_initial_state || 'remember') !== 'remember') return;
            window.localStorage.setItem('cbNotesDetailsOpen', details.open ? 'true' : 'false');
        });
    }

    async function openFormModal(templateId, title) {
        const template = document.getElementById(templateId);
        if (!template) return;

        const fragment = template.content.cloneNode(true);
        const body = fragment.firstElementChild;
        if (!(body instanceof HTMLElement)) return;

        initModalDetails(body);
        const action = qs('[name="cb_notes_action"]', body)?.value || 'create';
        const confirmLabel = action === 'update'
            ? (config.i18n?.saveChanges || 'Save changes')
            : (config.i18n?.addNote || 'Add note');

        await window.cbCore.modal.show({
            title: title || (config.i18n?.note || 'Note'),
            body,
            size: 'wide',
            initialFocus: 'input[name="title"]',
            confirmLabel,
            cancelLabel: config.i18n?.cancel || 'Cancel',
            onConfirm: async ({ form }) => {
                if (!form.reportValidity()) return false;
                try {
                    const payload = collectForm(form);
                    const json = await request('action', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: payload.cb_notes_action || action, payload, filters: collectFilters() }),
                    });
                    replaceResults(json.html);
                    showToast(json.message || config.i18n?.saved || 'Saved');
                    return true;
                } catch (error) {
                    showToast(error.message || config.i18n?.requestFailed || 'Request failed', 'error');
                    return false;
                }
            },
        });
    }

    async function request(path, options = {}) {
        const headers = options.headers || {};
        headers['X-WP-Nonce'] = config.nonce || '';
        options.headers = headers;

        const response = await fetch((config.restRoot || '/wp-json/core-blueprint/v1/notes/') + path, options);
        const json = await response.json();

        if (!response.ok || !json.success) {
            throw new Error(json.message || 'Request failed');
        }

        return json;
    }

    function replaceResults(html) {
        const target = results();
        if (target) {
            target.innerHTML = html;
        }

        updateBulkBar();
        applyNotesLayout();
    }

    async function loadList(params, updateUrl = true) {
        const search = new URLSearchParams(params);
        search.set('page', 'core-blueprint-notes');

        const json = await request(`list?${search.toString()}`, { method: 'GET' });
        replaceResults(json.html);

        if (updateUrl) {
            const url = `${window.location.pathname}?${search.toString()}`;
            window.history.pushState({}, '', url);
        }
    }

    async function submitAction(form) {
        const button = qs('button[type="submit"]', form);
        const busyApi = window.cbCore && window.cbCore.busy;
        const iconOnly = Boolean(button && button.classList.contains('cb-core-button--icon-only'));

        if (button && busyApi) {
            busyApi.button(button, true, {
                label: iconOnly ? '' : 'Saving…',
                spinnerOnly: iconOnly,
            });
        } else if (button) {
            button.disabled = true;
        }

        try {
            const payload = collectForm(form);
            const action = payload.cb_notes_action;

            const json = await request('action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action,
                    payload,
                    filters: collectFilters(),
                }),
            });

            replaceResults(json.html);
            showToast(json.message || 'Saved');

            if (action === 'create') {
                form.reset();
            }
        } finally {
            if (button && busyApi) {
                busyApi.button(button, false);
            } else if (button) {
                button.disabled = false;
            }
        }
    }

    document.addEventListener('submit', async (event) => {
        const form = event.target;

        if (form.matches('.cb-notes-action-form')) {
            event.preventDefault();

            if (form.classList.contains('cb-notes-delete-form')) {
                const message = form.dataset.confirm || 'Delete this note permanently?';
                const confirmed = await confirmAction(message, true);
                if (!confirmed) return;
            }

            try {
                await submitAction(form);
            } catch (error) {
                showToast(error.message || 'Error', 'error');
            }
            return;
        }

        if (form.matches('#cb-notes-filters')) {
            event.preventDefault();
            try {
                await loadList(collectForm(form));
            } catch (error) {
                showToast(error.message || 'Error', 'error');
            }
        }
    });

    document.addEventListener('change', async (event) => {
        if (event.target.matches('[data-cb-notes-select-note]')) {
            updateBulkBar();
            return;
        }

        const form = event.target.closest('#cb-notes-filters');
        if (!form) return;

        try {
            await loadList(collectForm(form));
        } catch (error) {
            showToast(error.message || 'Error', 'error');
        }
    });

    function resetFiltersForm() {
        const form = filtersForm();
        if (!form) return;

        const defaults = {
            page: 'core-blueprint-notes',
            search: '',
            status: 'all',
            type: 'all',
            assigned: 'all',
            sort: 'updated',
            per_page: '20',
            paged: '1',
            tag: '',
        };

        Object.entries(defaults).forEach(([name, value]) => {
            const field = qs(`[name="${name}"]`, form);
            if (field) field.value = value;
        });
    }

    function syncFiltersForm(params) {
        const form = filtersForm();
        if (!form) return;

        resetFiltersForm();

        Object.keys(params).forEach((key) => {
            const field = qs(`[name="${key}"]`, form);
            if (field) field.value = params[key];
        });
    }

    const cbNotesSearchHandler = debounce(async (event) => {
        const form = event.target.closest('#cb-notes-filters');
        if (!form) return;

        try {
            await loadList(collectForm(form));
        } catch (error) {
            showToast(error.message || 'Error', 'error');
        }
    }, 300);

    document.addEventListener('input', (event) => {
        if (!event.target.matches('#cb-notes-filters input[name="search"]')) return;
        cbNotesSearchHandler(event);
    });

    function downloadJsonExport(exportData) {
        const stamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
        const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'core-blueprint-notes-' + stamp + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    async function exportJson(ids = []) {
        const json = await request('action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'export_json', payload: { ids }, filters: collectFilters() }),
        });
        downloadJsonExport(json.export);
        showToast(json.message || 'Notes exported.');
    }

    function readJsonFile(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => {
                try {
                    resolve(JSON.parse(String(reader.result || '{}')));
                } catch (error) {
                    reject(new Error('Invalid JSON file.'));
                }
            };
            reader.onerror = () => reject(new Error('Could not read JSON file.'));
            reader.readAsText(file);
        });
    }

    function importedNotesFromPayload(payload) {
        if (Array.isArray(payload)) return payload;
        if (payload && Array.isArray(payload.notes)) return payload.notes;
        throw new Error('This JSON file does not contain a notes export.');
    }

    function importStateBadge(status) {
        const badge = document.createElement('span');
        const variant = status === 'new' ? 'success' : status === 'changed' ? 'warning' : 'neutral';
        badge.className = `cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--${variant}`;
        const labels = {
            new: config.i18n?.importStateNew || 'New',
            changed: config.i18n?.importStateChanged || 'Changed',
            identical: config.i18n?.importStateIdentical || 'Identical',
        };
        badge.textContent = labels[status] || status;
        return badge;
    }

    function importPreviewRow(item) {
        const row = document.createElement('div');
        row.className = 'cb-notes-import-row';
        const content = document.createElement('div');
        content.className = 'cb-notes-import-row__content';
        const heading = document.createElement('div');
        heading.className = 'cb-notes-import-row__heading';
        const title = document.createElement('strong');
        title.textContent = item.title || config.i18n?.untitledNote || 'Untitled note';
        heading.appendChild(title);
        heading.appendChild(importStateBadge(item.status || 'new'));
        const changes = document.createElement('small');
        const changeKeys = item.changes ? Object.keys(item.changes) : [];
        changes.textContent = changeKeys.length ? changeKeys.join(', ') : (config.i18n?.noChanges || 'No changes');
        content.appendChild(heading);
        content.appendChild(changes);
        const field = document.createElement('div');
        field.className = 'cb-core-field cb-notes-import-row__decision';
        const select = document.createElement('select');
        select.dataset.cbNotesImportDecision = String(item.index);
        select.setAttribute('aria-label', config.i18n?.importDecision || 'Import decision');
        const defaultDecision = item.status === 'new' ? 'create' : 'skip';
        const options = [
            ['skip', config.i18n?.skip || 'Skip'],
            ['create', config.i18n?.importAsNew || 'Import as new'],
            ['copy', config.i18n?.importAsCopy || 'Import as copy'],
        ];
        if (item.existing_id) options.push(['overwrite', config.i18n?.overwriteExisting || 'Overwrite existing']);
        options.forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value; option.textContent = label; option.selected = value === defaultDecision; select.appendChild(option);
        });
        field.appendChild(select);
        row.appendChild(content);
        row.appendChild(field);
        return row;
    }

    async function openImportPreviewModal(notes, preview) {
        const body = document.createElement('div');
        body.className = 'cb-notes-import-modal-content';
        const intro = document.createElement('p');
        intro.textContent = config.i18n?.importReview || 'Review the imported notes before saving them. Existing notes are detected by context or title.';
        body.appendChild(intro);
        const list = document.createElement('div');
        list.className = 'cb-notes-import-list cb-scrollbar';
        if (preview.length) preview.forEach((item) => list.appendChild(importPreviewRow(item)));
        else { const empty = document.createElement('p'); empty.textContent = config.i18n?.noImportableNotes || 'No importable notes found.'; list.appendChild(empty); }
        body.appendChild(list);
        const acknowledgementHost = document.createElement('div');
        acknowledgementHost.dataset.cbNotesImportOverwriteAcknowledgement = '1';
        body.appendChild(acknowledgementHost);
        const acknowledgementTemplate = document.getElementById('cb-notes-import-overwrite-acknowledgement-template');
        const syncOverwriteAcknowledgement = () => {
            const hasOverwrite = qsa('[data-cb-notes-import-decision]', body).some((select) => select.value === 'overwrite');
            acknowledgementHost.replaceChildren();
            if (hasOverwrite && acknowledgementTemplate) {
                acknowledgementHost.appendChild(acknowledgementTemplate.content.cloneNode(true));
            }
        };
        list.addEventListener('change', (event) => {
            if (event.target.matches('[data-cb-notes-import-decision]')) syncOverwriteAcknowledgement();
        });
        syncOverwriteAcknowledgement();
        await window.cbCore.modal.show({
            title: config.i18n?.importNotes || 'Import Notes',
            body,
            size: 'wide',
            confirmLabel: config.i18n?.importNotesAction || 'Import notes',
            cancelLabel: config.i18n?.cancel || 'Cancel',
            onConfirm: async () => {
                const decisions = {};
                qsa('[data-cb-notes-import-decision]', body).forEach((select) => { decisions[select.dataset.cbNotesImportDecision] = select.value; });
                const hasOverwrite = Object.values(decisions).includes('overwrite');
                const acknowledgement = body.querySelector('[name="notes_import_overwrite_acknowledgement"]');
                if (hasOverwrite && !acknowledgement?.checked) {
                    acknowledgement?.reportValidity();
                    acknowledgement?.focus();
                    return false;
                }
                try {
                    const json = await request('action', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'import_commit', payload: { notes, decisions, notes_import_overwrite_acknowledgement: hasOverwrite ? '1' : '' }, filters: collectFilters() }),
                    });
                    replaceResults(json.html);
                    showToast(json.message || config.i18n?.notesImported || 'Notes imported.');
                    return true;
                } catch (error) {
                    showToast(error.message || config.i18n?.requestFailed || 'Request failed', 'error');
                    return false;
                }
            },
        });
    }

    async function importJsonFile(file) {
        const payload = await readJsonFile(file);
        const notes = importedNotesFromPayload(payload);
        const json = await request('action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'import_preview', payload: { notes }, filters: collectFilters() }),
        });
        await openImportPreviewModal(notes, json.preview || []);
    }

    /**
     * Wire up the page-header Actions dropdown menu.
     *
     * The trigger toggles aria-expanded + the panel's hidden state. Clicks
     * on menu items pass through to the existing data-cb-notes-* handlers
     * (Import JSON, Export all, Delete all), then the menu closes via the
     * outside-click listener on the next tick. Outside click and Escape
     * also dismiss the menu. Self-contained, no shared component dependency
     * - if a second module ever needs this pattern we'll extract it.
     */
    function initActionsMenu() {
        const trigger = qs('[data-cb-notes-actions-menu-trigger]');
        const panel = qs('[data-cb-notes-actions-menu-panel]');
        if (!trigger || !panel) return;

        const items = () => Array.from(panel.querySelectorAll('[role="menuitem"]'))
            .filter((item) => !item.disabled && item.getAttribute('aria-disabled') !== 'true');

        const focusItem = (index) => {
            const menuItems = items();
            if (!menuItems.length) return;
            const safeIndex = (index + menuItems.length) % menuItems.length;
            menuItems[safeIndex].focus();
        };

        const open = (focus = 'first') => {
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            if (focus === 'last') focusItem(-1);
            else if (focus === 'first') focusItem(0);
        };

        const close = ({ restoreFocus = false } = {}) => {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) trigger.focus();
        };

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (panel.hidden) open('first'); else close();
        });

        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open('first');
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                open('last');
            }
        });

        panel.addEventListener('keydown', (event) => {
            const menuItems = items();
            if (!menuItems.length) return;
            const current = menuItems.indexOf(document.activeElement);

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                focusItem(current + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                focusItem(current - 1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                focusItem(0);
            } else if (event.key === 'End') {
                event.preventDefault();
                focusItem(-1);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                close({ restoreFocus: true });
            } else if (event.key === 'Tab') {
                close();
            }
        });

        document.addEventListener('click', (event) => {
            if (panel.hidden) return;
            if (event.target.closest('[data-cb-notes-actions-menu-trigger]')) return;
            if (event.target.closest('[data-cb-notes-actions-menu]')) {
                window.setTimeout(() => close(), 0);
                return;
            }
            close();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                close({ restoreFocus: true });
            }
        });
    }
    document.addEventListener('click', async (event) => {
        const layoutButton = event.target.closest('[data-cb-notes-layout]');
        if (layoutButton) {
            event.preventDefault();
            setNotesLayout(layoutButton.dataset.cbNotesLayout || 'list');
            return;
        }

        const selectionControl = event.target.closest('[data-cb-notes-select-note], .cb-notes-select-note');
        if (selectionControl) {
            event.stopPropagation();
            return;
        }

        const cardActionControl = event.target.closest('.cb-notes-card__icon-actions');
        if (cardActionControl) {
            event.stopPropagation();
        }


        const exportAllButton = event.target.closest('[data-cb-notes-export-all]');
        if (exportAllButton) {
            event.preventDefault();
            exportAllButton.disabled = true;
            try {
                await exportJson([]);
            } catch (error) {
                showToast(error.message || 'Error', 'error');
            } finally {
                exportAllButton.disabled = false;
            }
            return;
        }

        const exportSelectedButton = event.target.closest('[data-cb-notes-export-selected]');
        if (exportSelectedButton) {
            event.preventDefault();
            const ids = selectedNoteIds();
            if (!ids.length) return;
            exportSelectedButton.disabled = true;
            try {
                await exportJson(ids);
            } catch (error) {
                showToast(error.message || 'Error', 'error');
            } finally {
                exportSelectedButton.disabled = false;
            }
            return;
        }

        const importButton = event.target.closest('[data-cb-notes-import-json]');
        if (importButton) {
            event.preventDefault();
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'application/json,.json';
            input.addEventListener('change', async () => {
                const file = input.files && input.files[0];
                if (!file) return;
                importButton.disabled = true;
                try {
                    await importJsonFile(file);
                } catch (error) {
                    showToast(error.message || 'Error', 'error');
                } finally {
                    importButton.disabled = false;
                }
            });
            input.click();
            return;
        }
        const createButton = event.target.closest('.cb-notes-open-create');
        if (createButton) {
            event.preventDefault();
            openFormModal('cb-notes-create-template', createButton.dataset.cbNotesModalTitle || 'New Note');
            return;
        }

        const editButton = event.target.closest('.cb-notes-open-edit');
        if (editButton) {
            event.preventDefault();
            openFormModal(editButton.dataset.cbNotesTemplate, editButton.dataset.cbNotesModalTitle || 'Edit Note');
            return;
        }

        const deleteIconButton = event.target.closest('.cb-notes-card__icon-actions .cb-notes-delete-form button');
        if (deleteIconButton) {
            event.preventDefault();

            const form = deleteIconButton.closest('form');
            if (!form) return;

            const message = form.dataset.confirm || 'Delete this note permanently?';
            const confirmed = await confirmAction(message, true, 'Delete note');
            if (!confirmed) return;

            try {
                await submitAction(form);
            } catch (error) {
                showToast(error.message || 'Error', 'error');
            }
            return;
        }

        const selectVisibleButton = event.target.closest('[data-cb-notes-select-visible]');
        if (selectVisibleButton) {
            event.preventDefault();
            selectVisibleNotes();
            return;
        }

        const clearSelectionButton = event.target.closest('[data-cb-notes-clear-selection]');
        if (clearSelectionButton) {
            event.preventDefault();
            clearSelection();
            return;
        }

        const bulkDeleteButton = event.target.closest('[data-cb-notes-bulk-delete]');
        if (bulkDeleteButton) {
            event.preventDefault();

            const ids = selectedNoteIds();
            if (!ids.length) return;

            const confirmed = await confirmBulkDeleteNotes(ids.length);
            if (!confirmed) return;

            bulkDeleteButton.disabled = true;

            try {
                const json = await request('action', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bulk_delete',
                        payload: { ids, confirm: 'DELETE' },
                        filters: collectFilters(),
                    }),
                });

                replaceResults(json.html);
                showToast(json.message || 'Selected notes deleted.');
            } catch (error) {
                showToast(error.message || 'Error', 'error');
            } finally {
                bulkDeleteButton.disabled = false;
            }
            return;
        }

        const deleteAllButton = event.target.closest('[data-cb-notes-delete-all-trigger]');
        if (deleteAllButton) {
            event.preventDefault();

            const confirmed = await confirmDeleteAllNotes();
            if (!confirmed) return;

            deleteAllButton.disabled = true;

            try {
                const phrase = 'DELETE ALL NOTES';
                const json = await request('action', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_all',
                        payload: { confirm: phrase },
                        filters: collectFilters(),
                    }),
                });

                replaceResults(json.html);
                showToast(json.message || 'All notes deleted.');
            } catch (error) {
                showToast(error.message || 'Error', 'error');
            } finally {
                deleteAllButton.disabled = false;
            }
            return;
        }

        const link = event.target.closest('.cb-notes-ajax-link');
        if (!link) return;

        const url = new URL(link.href);
        if (url.searchParams.get('page') !== 'core-blueprint-notes') return;

        event.preventDefault();

        const params = {};
        url.searchParams.forEach((value, key) => {
            params[key] = value;
        });

        if (Object.keys(params).length === 1 && params.page === 'core-blueprint-notes') {
            resetFiltersForm();
        } else {
            syncFiltersForm(params);
        }

        try {
            await loadList(params);
        } catch (error) {
            showToast(error.message || 'Error', 'error');
        }
    });

    applyNotesLayout();
    initActionsMenu();

    window.addEventListener('popstate', async () => {
        const params = {};
        new URLSearchParams(window.location.search).forEach((value, key) => {
            params[key] = value;
        });

        syncFiltersForm(params);

        try {
            await loadList(params, false);
        } catch (error) {
            showToast(error.message || 'Error', 'error');
        }
    });
