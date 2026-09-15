import { createDesignerShell } from '@cb-core/design-editor';

const controllers = new WeakMap();

const clone = (value) => JSON.parse(JSON.stringify(value));
const text = (value) => String(value ?? '').trim();
const byId = (fields) => new Map((Array.isArray(fields) ? fields : []).map((field) => [text(field.id), field]));
const readableFields = (fields) => (Array.isArray(fields) ? fields : []).filter((field) => field?.readable === true);
const writableFields = (fields) => (Array.isArray(fields) ? fields : []).filter((field) => field?.writable === true);
const matchKey = (value) => text(value)
	.normalize('NFD')
	.replace(/[\u0300-\u036f]/g, '')
	.toLowerCase()
	.replace(/[^a-z0-9]+/g, '');

const fieldTokens = (field) => Array.from(new Set([
	field?.id,
	field?.label,
	...(Array.isArray(field?.aliases) ? field.aliases : []),
].map(matchKey).filter(Boolean)));

const suggestMapping = (sourceFields, targetFields) => {
	const tokenTargets = new Map();
	writableFields(targetFields).forEach((field) => {
		fieldTokens(field).forEach((token) => {
			if (!tokenTargets.has(token)) tokenTargets.set(token, new Set());
			tokenTargets.get(token).add(field.id);
		});
	});

	const claimed = new Set();
	return readableFields(sourceFields).map((field) => {
		const candidates = new Set();
		fieldTokens(field).forEach((token) => {
			(tokenTargets.get(token) || []).forEach((targetId) => {
				if (!claimed.has(targetId)) candidates.add(targetId);
			});
		});
		if (candidates.size === 1) {
			const target = Array.from(candidates)[0];
			claimed.add(target);
			return { source: field.id, target, transform: 'direct', value: null };
		}
		return { source: field.id, target: null, transform: 'ignore', value: null };
	});
};

const validateMapping = (mapping, sourceFields, targetFields) => {
	const source = byId(sourceFields);
	const target = byId(targetFields);
	const seenSources = new Set();
	const seenTargets = new Set();
	const errors = [];
	let mapped = 0;

	(Array.isArray(mapping) ? mapping : []).forEach((entry, index) => {
		const transform = text(entry?.transform);
		const sourceId = text(entry?.source);
		const targetId = text(entry?.target);

		if (transform === 'ignore') {
			if (!sourceId || !source.has(sourceId) || seenSources.has(sourceId)) errors.push({ index, code: 'invalid_ignore' });
			seenSources.add(sourceId);
			return;
		}

		if (transform === 'constant') {
			if (!targetId || !target.has(targetId) || target.get(targetId)?.writable !== true || seenTargets.has(targetId)) {
				errors.push({ index, code: 'invalid_constant' });
				return;
			}
			seenTargets.add(targetId);
			mapped += 1;
			return;
		}

		if (transform !== 'direct' || !sourceId || !targetId || !source.has(sourceId) || !target.has(targetId)) {
			errors.push({ index, code: 'invalid_direct' });
			return;
		}
		if (source.get(sourceId)?.readable !== true || target.get(targetId)?.writable !== true || seenSources.has(sourceId) || seenTargets.has(targetId)) {
			errors.push({ index, code: 'duplicate_or_forbidden' });
			return;
		}
		seenSources.add(sourceId);
		seenTargets.add(targetId);
		mapped += 1;
	});

	const requiredMissing = writableFields(targetFields)
		.filter((field) => field.required === true && !seenTargets.has(field.id))
		.map((field) => field.id);
	if (requiredMissing.length) errors.push({ index: null, code: 'required_unmapped', fields: requiredMissing });

	return Object.freeze({
		valid: source.size > 0 && errors.length === 0,
		errors,
		mapped,
		ignored: (Array.isArray(mapping) ? mapping : []).filter((entry) => entry?.transform === 'ignore').length,
		requiredMissing,
	});
};

const createElement = (documentRef, name, className = '') => {
	const node = documentRef.createElement(name);
	if (className) node.className = className;
	return node;
};

const createController = (root, config) => {
	const shell = root.querySelector('[data-cb-design-shell]');
	if (!shell) return null;

	let sourceFields = Array.isArray(config.sourceFields) ? clone(config.sourceFields) : [];
	let targetFields = Array.isArray(config.targetFields) ? clone(config.targetFields) : [];
	let mapping = Array.isArray(config.mapping) && config.mapping.length
		? clone(config.mapping)
		: suggestMapping(sourceFields, targetFields);
	let selectedSource = readableFields(sourceFields)[0]?.id || '';
	let selectedFile = null;
	let externalValidation = null;
	let busy = false;

	const history = [clone(mapping)];
	let historyIndex = 0;
	const historyState = {};
	Object.defineProperties(historyState, {
		canUndo: { get: () => !busy && historyIndex > 0 },
		canRedo: { get: () => !busy && historyIndex < history.length - 1 },
	});

	let shellController = null;
	const session = {
		history: historyState,
		undo() {
			if (busy || historyIndex <= 0) return false;
			historyIndex -= 1;
			mapping = clone(history[historyIndex]);
			render();
			return true;
		},
		redo() {
			if (busy || historyIndex >= history.length - 1) return false;
			historyIndex += 1;
			mapping = clone(history[historyIndex]);
			render();
			return true;
		},
	};

	const sourceContainer = shell.querySelector('[data-cb-data-mapper-source-fields]');
	const mappingContainer = shell.querySelector('[data-cb-data-mapper-mappings]');
	const inspector = shell.querySelector('[data-cb-data-mapper-inspector]');
	const preview = shell.querySelector('[data-cb-data-mapper-preview]');
	const summary = shell.querySelector('[data-cb-data-mapper-summary]');
	const search = shell.querySelector('[data-cb-data-mapper-search]');
	const auto = shell.querySelector('[data-cb-data-mapper-auto]');
	const primary = shell.querySelector('[data-cb-data-mapper-primary]');
	const status = shell.querySelector('[data-cb-design-shell-status]');
	const fileInput = shell.querySelector('[data-cb-data-mapper-file]');
	const fileName = shell.querySelector('[data-cb-data-mapper-file-name]');
	const labels = config.labels || {};

	const targetMap = () => byId(targetFields);
	const snapshot = () => clone(mapping);
	const resetHistory = (nextMapping) => {
		mapping = clone(nextMapping);
		history.splice(0, history.length, clone(mapping));
		historyIndex = 0;
		externalValidation = null;
	};
	const commit = (next) => {
		if (busy) return false;
		mapping = clone(next);
		history.splice(historyIndex + 1);
		history.push(clone(mapping));
		historyIndex = history.length - 1;
		externalValidation = null;
		render();
		root.dispatchEvent(new CustomEvent('cb:data-mapper:change', {
			bubbles: true,
			detail: Object.freeze({ direction: config.direction, mapping: snapshot() }),
		}));
		return true;
	};

	const entryForSource = (sourceId) => mapping.find((entry) => text(entry?.source) === sourceId) || null;
	const updateSourceEntry = (sourceId, patch) => {
		if (busy) return false;
		const next = snapshot();
		const index = next.findIndex((entry) => text(entry?.source) === sourceId);
		const current = index >= 0 ? next[index] : { source: sourceId, target: null, transform: 'ignore', value: null };
		const replacement = { ...current, ...patch, source: sourceId };
		if (index >= 0) next[index] = replacement;
		else next.push(replacement);
		return commit(next);
	};

	const renderSources = () => {
		if (!sourceContainer) return;
		const query = matchKey(search?.value || '');
		sourceContainer.replaceChildren();
		readableFields(sourceFields).forEach((field) => {
			if (query && !fieldTokens(field).some((token) => token.includes(query))) return;
			const button = createElement(document, 'button', 'cb-core-data-mapper__source-field');
			button.type = 'button';
			button.dataset.cbDataMapperSource = field.id;
			button.classList.toggle('is-selected', field.id === selectedSource);
			const label = createElement(document, 'span', 'cb-core-data-mapper__field-label');
			label.textContent = field.label || field.id;
			const type = createElement(document, 'span', 'cb-core-data-mapper__field-type');
			type.textContent = field.type || 'string';
			button.append(label, type);
			button.addEventListener('click', () => {
				selectedSource = field.id;
				render();
			});
			sourceContainer.append(button);
		});
	};

	const targetLabel = (entry) => {
		if (!entry || entry.transform === 'ignore' || !entry.target) return labels.ignore || 'Ignore';
		return targetMap().get(entry.target)?.label || entry.target;
	};

	const renderMappings = () => {
		if (!mappingContainer) return;
		mappingContainer.replaceChildren();
		readableFields(sourceFields).forEach((field) => {
			const entry = entryForSource(field.id) || { source: field.id, target: null, transform: 'ignore', value: null };
			const row = createElement(document, 'button', 'cb-core-data-mapper__mapping-row');
			row.type = 'button';
			row.classList.toggle('is-selected', selectedSource === field.id);
			row.classList.toggle('is-ignored', entry.transform === 'ignore');

			const source = createElement(document, 'span', 'cb-core-data-mapper__mapping-endpoint');
			const sourceName = createElement(document, 'strong');
			sourceName.textContent = field.label || field.id;
			const sourceId = createElement(document, 'small');
			sourceId.textContent = field.id;
			source.append(sourceName, sourceId);

			const connector = createElement(document, 'span', 'cb-core-data-mapper__connector');
			connector.textContent = '→';
			connector.setAttribute('aria-hidden', 'true');

			const target = createElement(document, 'span', 'cb-core-data-mapper__mapping-endpoint cb-core-data-mapper__mapping-endpoint--target');
			const targetName = createElement(document, 'strong');
			targetName.textContent = targetLabel(entry);
			const targetId = createElement(document, 'small');
			targetId.textContent = entry.target || '';
			target.append(targetName, targetId);

			row.append(source, connector, target);
			row.addEventListener('click', () => {
				selectedSource = field.id;
				render();
			});
			mappingContainer.append(row);
		});
	};

	const option = (value, labelText, selected = false) => {
		const node = document.createElement('option');
		node.value = value;
		node.textContent = labelText;
		node.selected = selected;
		return node;
	};

	const renderInspector = () => {
		if (!inspector) return;
		inspector.replaceChildren();
		const field = sourceFields.find((candidate) => candidate.id === selectedSource);
		if (!field) {
			const empty = createElement(document, 'p', 'description');
			empty.textContent = sourceFields.length === 0 && config.intake
				? (labels.fileNeeded || 'Choose a source file to begin mapping.')
				: (labels.noSelection || 'Select a field mapping to inspect it.');
			inspector.append(empty);
			return;
		}
		const entry = entryForSource(field.id) || { source: field.id, target: null, transform: 'ignore', value: null };

		const heading = createElement(document, 'div', 'cb-core-data-mapper__inspector-heading');
		const name = createElement(document, 'strong');
		name.textContent = field.label || field.id;
		const id = createElement(document, 'code');
		id.textContent = field.id;
		heading.append(name, id);

		const transformField = createElement(document, 'label', 'cb-core-data-mapper__control');
		const transformLabel = createElement(document, 'span');
		transformLabel.textContent = labels.transform || 'Transform';
		const transform = document.createElement('select');
		transform.disabled = busy;
		transform.append(
			option('direct', labels.direct || 'Direct', entry.transform === 'direct'),
			option('ignore', labels.ignore || 'Ignore', entry.transform === 'ignore'),
		);
		transform.addEventListener('change', () => {
			if (busy) return;
			const nextTransform = transform.value;
			const nextTarget = nextTransform === 'direct' ? (entry.target || '') : null;
			updateSourceEntry(field.id, { transform: nextTransform, target: nextTarget, value: null });
		});
		transformField.append(transformLabel, transform);

		const targetField = createElement(document, 'label', 'cb-core-data-mapper__control');
		const targetTitle = createElement(document, 'span');
		targetTitle.textContent = labels.targetField || 'Target field';
		const targetSelect = document.createElement('select');
		targetSelect.disabled = busy || entry.transform !== 'direct';
		targetSelect.append(option('', '—', !entry.target));
		writableFields(targetFields).forEach((targetDef) => {
			targetSelect.append(option(targetDef.id, targetDef.label || targetDef.id, entry.target === targetDef.id));
		});
		targetSelect.addEventListener('change', () => {
			if (busy) return;
			updateSourceEntry(field.id, {
				transform: targetSelect.value ? 'direct' : 'ignore',
				target: targetSelect.value || null,
				value: null,
			});
		});
		targetField.append(targetTitle, targetSelect);

		if (field.description) {
			const description = createElement(document, 'p', 'description');
			description.textContent = field.description;
			inspector.append(heading, description, transformField, targetField);
		} else {
			inspector.append(heading, transformField, targetField);
		}
	};

	const renderSummary = () => {
		const result = validateMapping(mapping, sourceFields, targetFields);
		if (summary) {
			if (sourceFields.length === 0 && config.intake) {
				summary.textContent = labels.fileNeeded || 'Choose a source file to begin mapping.';
			} else {
				summary.textContent = result.valid
					? `${result.mapped} mapped · ${result.ignored} ignored`
					: `${result.mapped} mapped · ${result.errors.length} need attention`;
			}
			summary.classList.toggle('is-valid', result.valid);
			summary.classList.toggle('is-invalid', sourceFields.length > 0 && !result.valid);
		}
		if (primary) primary.disabled = busy || !result.valid;
		if (search) search.disabled = sourceFields.length === 0;
		if (auto) auto.disabled = busy || sourceFields.length === 0;
		if (fileInput) fileInput.disabled = busy;
		return result;
	};

	const renderPreview = () => {
		if (!preview) return;
		preview.replaceChildren();
		if (!externalValidation) {
			const empty = createElement(document, 'p', 'description');
			empty.textContent = labels.noPreview || 'No validated preview is available yet.';
			preview.append(empty);
			return;
		}
		const state = createElement(document, 'div', 'cb-core-data-mapper__validation');
		state.classList.toggle('is-valid', externalValidation.valid === true);
		state.classList.toggle('is-invalid', externalValidation.valid === false);
		const title = createElement(document, 'strong');
		title.textContent = externalValidation.message || (externalValidation.valid === true
			? (labels.allMapped || 'Mapping is complete.')
			: (labels.needsAttention || 'Mapping needs attention.'));
		state.append(title);

		if (externalValidation.counts && typeof externalValidation.counts === 'object') {
			const list = createElement(document, 'dl', 'cb-core-data-mapper__preview-counts');
			Object.entries(externalValidation.counts).forEach(([key, value]) => {
				const dt = document.createElement('dt');
				dt.textContent = key.replace(/[_-]+/g, ' ');
				const dd = document.createElement('dd');
				dd.textContent = String(value);
				list.append(dt, dd);
			});
			state.append(list);
		}

		if (Array.isArray(externalValidation.errors) && externalValidation.errors.length) {
			const list = document.createElement('ul');
			externalValidation.errors.slice(0, 50).forEach((error) => {
				const item = document.createElement('li');
				item.textContent = text(error?.message || error?.code || error);
				list.append(item);
			});
			state.append(list);
		}
		preview.append(state);
	};

	function render() {
		renderSources();
		renderMappings();
		renderInspector();
		renderSummary();
		renderPreview();
		shellController?.syncHistory?.();
	}

	shellController = createDesignerShell(shell, {
		session,
		defaultPanels: { 'mapper-details': 'mapping' },
	});

	search?.addEventListener('input', renderSources);
	auto?.addEventListener('click', () => {
		if (busy) return;
		commit(suggestMapping(sourceFields, targetFields));
	});
	fileInput?.addEventListener('change', () => {
		if (busy) return;
		selectedFile = fileInput.files?.item(0) || null;
		sourceFields = [];
		selectedSource = '';
		resetHistory([]);
		if (fileName) fileName.textContent = selectedFile?.name || (labels.fileNeeded || 'Choose a source file to begin mapping.');
		if (status) status.textContent = selectedFile ? (labels.inspectingFile || 'Inspecting source file…') : '';
		render();
		root.dispatchEvent(new CustomEvent('cb:data-mapper:file-selected', {
			bubbles: true,
			detail: Object.freeze({
				file: selectedFile,
				name: selectedFile?.name || '',
				size: selectedFile?.size || 0,
				type: selectedFile?.type || '',
			}),
		}));
	});
	primary?.addEventListener('click', () => {
		if (busy) return;
		const localValidation = validateMapping(mapping, sourceFields, targetFields);
		if (!localValidation.valid) {
			render();
			return;
		}
		root.dispatchEvent(new CustomEvent('cb:data-mapper:submit', {
			bubbles: true,
			cancelable: true,
			detail: Object.freeze({
				direction: config.direction,
				file: selectedFile,
				mapping: snapshot(),
				localValidation,
			}),
		}));
	});

	const controller = Object.freeze({
		root,
		shell: shellController,
		file: () => selectedFile,
		sourceFields: () => clone(sourceFields),
		targetFields: () => clone(targetFields),
		mapping: snapshot,
		validation: () => validateMapping(mapping, sourceFields, targetFields),
		setSourceFields(nextFields, { mapping: nextMapping = null, autoMatch = true } = {}) {
			if (!Array.isArray(nextFields) || nextFields.length === 0) return false;
			sourceFields = clone(nextFields);
			selectedSource = readableFields(sourceFields)[0]?.id || '';
			const replacement = Array.isArray(nextMapping)
				? nextMapping
				: (autoMatch ? suggestMapping(sourceFields, targetFields) : []);
			resetHistory(replacement);
			if (status) status.textContent = labels.fileSelected || 'Source file selected.';
			render();
			return true;
		},
		setTargetFields(nextFields, { autoMatch = true } = {}) {
			if (busy || !Array.isArray(nextFields) || nextFields.length === 0) return false;
			targetFields = clone(nextFields);
			resetHistory(autoMatch ? suggestMapping(sourceFields, targetFields) : []);
			render();
			return true;
		},
		replaceMapping(nextMapping, { recordHistory = true } = {}) {
			if (busy || !Array.isArray(nextMapping)) return false;
			if (recordHistory) return commit(nextMapping);
			mapping = clone(nextMapping);
			externalValidation = null;
			render();
			return true;
		},
		autoMatch() {
			if (busy || sourceFields.length === 0) return false;
			return commit(suggestMapping(sourceFields, targetFields));
		},
		setValidation(result) {
			externalValidation = result && typeof result === 'object' ? clone(result) : null;
			renderPreview();
		},
		setBusy(nextBusy, message = '') {
			busy = Boolean(nextBusy);
			if (status) {
				status.textContent = text(message);
				status.setAttribute('aria-busy', busy ? 'true' : 'false');
			}
			render();
		},
	});
	controllers.set(root, controller);
	render();

	root.dispatchEvent(new CustomEvent('cb:data-mapper:ready', {
		bubbles: true,
		detail: Object.freeze({ controller }),
	}));
	return controller;
};

export const createDataMapper = (root, configuration = null) => {
	if (!(root instanceof Element)) throw new TypeError('Data Mapper requires a root Element.');
	if (controllers.has(root)) return controllers.get(root);
	let config = configuration;
	if (!config) {
		const source = root.querySelector('[data-cb-data-mapper-config]')?.textContent || '';
		try {
			config = JSON.parse(source);
		} catch (error) {
			throw new TypeError('Data Mapper configuration is invalid.');
		}
	}
	if (!config || typeof config !== 'object') throw new TypeError('Data Mapper configuration is invalid.');
	return createController(root, config);
};

export const controllerFor = (root) => controllers.get(root) || null;

const boot = () => {
	document.querySelectorAll('[data-cb-data-mapper-root]').forEach((root) => {
		if (controllers.has(root)) return;
		try {
			createDataMapper(root);
		} catch (error) {
			root.dataset.cbDataMapperError = 'true';
		}
	});
};

const publicApi = Object.freeze({ create: createDataMapper, get: controllerFor });
if (typeof window !== 'undefined') {
	window.cbCore = window.cbCore || {};
	window.cbCore.dataMapper = publicApi;
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
else boot();
