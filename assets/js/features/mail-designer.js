import { createDesignerShell, createSession, commands, profiles, decorateDesignerControl } from '@cb-core/design-editor';

const root = document.querySelector('[data-cb-mail-designer]');

if (root) {
	const form = root.querySelector('[data-cb-mail-designer-form]');
	const shellRoot = root.querySelector('[data-cb-design-shell]');
	const projectField = root.querySelector('[data-cb-mail-project]');
	const componentField = root.querySelector('[data-cb-mail-components]');
	const subjectField = root.querySelector('[data-cb-mail-subject]');
	const emailInspector = root.querySelector('[data-cb-mail-email-inspector]');
	const structure = root.querySelector('[data-cb-mail-structure]');
	const inspector = root.querySelector('[data-cb-mail-inspector]');
	const preview = root.querySelector('[data-cb-mail-preview]');
	const previewFrame = root.querySelector('[data-cb-mail-preview-frame]');
	const previewStatus = root.querySelector('[data-cb-mail-preview-status]');
	const ajaxUrl = root.dataset.ajaxUrl || '';
	const nonce = root.dataset.previewNonce || '';
	let templateId = root.dataset.templateId || '';
	const mailProfile = profiles.mail;

	const parseJson = (field, fallback) => {
		try {
			return JSON.parse(field?.value || '');
		} catch (error) {
			return fallback;
		}
	};

	const project = parseJson(projectField, null);
	const components = parseJson(componentField, {});
	const componentDefinitions = Object.values(components).filter((item) => item && typeof item === 'object');
	const sectionDefinition = Object.freeze({
		label: 'Section',
		node_type: 'mail.section',
		provider: 'core',
		defaults: Object.freeze({ background: '#ffffff', padding: 28 }),
		inspector: Object.freeze([
			Object.freeze({ key: 'background', label: 'Background', type: 'color' }),
			Object.freeze({ key: 'padding', label: 'Padding', type: 'number', min: 0, max: 80, step: 1 }),
		]),
	});

	if (!form || !shellRoot || !projectField || !subjectField || !emailInspector || !structure || !inspector || !preview || !project) {
		throw new Error('Mail Designer could not initialize because its editor payload is incomplete.');
	}
	if (!mailProfile || typeof mailProfile.normalizeMailLayout !== 'function' || !Array.isArray(mailProfile.MAIL_FONT_FAMILIES)) {
		throw new Error('Mail Designer could not initialize because the Mail profile styling contract is unavailable.');
	}

	let previewTimer = 0;
	let previewController = null;
	let dragPath = null;
	let shell = null;
	let contextHydrating = false;

	const pathKey = (path) => JSON.stringify(Array.isArray(path) ? path : []);
	const samePath = (left, right) => pathKey(left) === pathKey(right);
	const parentPath = (path) => Array.isArray(path) ? path.slice(0, -1) : [];

	const definitionForNode = (node) => {
		if (node?.type === sectionDefinition.node_type && node?.provider === sectionDefinition.provider) {
			return sectionDefinition;
		}
		return componentDefinitions.find(
			(definition) => definition.node_type === node?.type && definition.provider === node?.provider
		) || null;
	};

	const nodeAt = (projectValue, path) => {
		let node = projectValue?.root;
		for (const index of path || []) {
			node = node?.children?.[index];
			if (!node) return null;
		}
		return node || null;
	};

	const primarySection = () => {
		const children = session.project()?.root?.children;
		if (!Array.isArray(children)) return null;
		const index = children.findIndex((node) => node?.type === 'mail.section');
		return index < 0 ? null : { index, node: children[index] };
	};

	const makeNode = (definition) => ({
		type: String(definition.node_type || ''),
		provider: String(definition.provider || ''),
		properties: structuredClone(definition.defaults || {}),
		children: [],
	});

	const updateSerializedProject = () => {
		projectField.value = JSON.stringify(session.snapshot());
	};

	const setPreviewStatus = (message, isError = false) => {
		if (!previewStatus) return;
		previewStatus.textContent = message;
		previewStatus.classList.toggle('is-error', Boolean(isError));
	};

	const markerPath = (marker) => {
		try {
			const path = JSON.parse(marker?.dataset?.cbMailPath || 'null');
			return Array.isArray(path) && path.every((index) => Number.isInteger(index) && index >= 0) ? path : null;
		} catch (error) {
			return null;
		}
	};

	const syncPreviewHeight = () => {
		const doc = preview.contentDocument;
		if (!doc?.documentElement) return;
		const height = Math.min(1600, Math.max(680, doc.documentElement.scrollHeight + 2));
		preview.style.height = `${height}px`;
	};

	const syncPreviewSelection = () => {
		const doc = preview.contentDocument;
		if (!doc) return;
		const selected = session.editorState.selection.primary();
		doc.querySelectorAll('[data-cb-mail-editor-node]').forEach((marker) => {
			const path = markerPath(marker);
			if (selected && path && samePath(path, selected)) marker.dataset.cbMailSelected = 'true';
			else delete marker.dataset.cbMailSelected;
		});
	};

	const renderSelectionViews = ({ openInspector = false } = {}) => {
		renderStructure();
		renderInspector();
		syncPreviewSelection();
		if (openInspector) shell?.activatePanel('inspector');
	};

	const selectPath = (path, { openInspector = true } = {}) => {
		if (!Array.isArray(path) || !nodeAt(session.project(), path)) return false;
		session.editorState.selection.select(path);
		renderSelectionViews({ openInspector });
		return true;
	};

	const bindPreviewInteractions = () => {
		const doc = preview.contentDocument;
		if (!doc) return;

		doc.addEventListener('click', (event) => {
			const target = event.target && typeof event.target.closest === 'function' ? event.target : null;
			const marker = target?.closest('[data-cb-mail-editor-node]');
			if (!marker) return;
			const path = markerPath(marker);
			if (!path) return;
			event.preventDefault();
			event.stopPropagation();
			selectPath(path);
		}, true);

		doc.addEventListener('pointermove', (event) => {
			const target = event.target && typeof event.target.closest === 'function' ? event.target : null;
			const marker = target?.closest('[data-cb-mail-editor-node]');
			doc.querySelectorAll('[data-cb-mail-hovered]').forEach((node) => { delete node.dataset.cbMailHovered; });
			if (marker) marker.dataset.cbMailHovered = 'true';
		}, { passive: true });

		doc.addEventListener('pointerleave', () => {
			doc.querySelectorAll('[data-cb-mail-hovered]').forEach((node) => { delete node.dataset.cbMailHovered; });
		});

		syncPreviewSelection();
		syncPreviewHeight();
	};

	const refreshPreview = async () => {
		if (!ajaxUrl || !nonce || !templateId) return;
		previewController?.abort();
		previewController = new AbortController();
		setPreviewStatus('Updating preview…');

		const body = new URLSearchParams({
			action: 'cb_core_mail_designer_preview',
			nonce,
			template_id: templateId,
			subject: subjectField.value,
			project_json: projectField.value,
		});

		try {
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
				signal: previewController.signal,
			});
			const payload = await response.json();
			if (!response.ok || !payload?.success || typeof payload?.data?.html !== 'string') {
				throw new Error(payload?.data?.message || 'Preview failed.');
			}
			preview.srcdoc = payload.data.html;
			setPreviewStatus('Preview updated');
		} catch (error) {
			if (error?.name === 'AbortError') return;
			setPreviewStatus(error?.message || 'Preview failed.', true);
		}
	};

	const schedulePreview = () => {
		window.clearTimeout(previewTimer);
		previewTimer = window.setTimeout(refreshPreview, 250);
	};

	const fieldValue = (input, type) => {
		if (type === 'number') {
			const value = Number(input.value);
			return Number.isFinite(value) ? value : 0;
		}
		return input.value;
	};

	const appendSelectOptions = (input, options) => {
		Object.entries(options || {}).forEach(([optionValue, optionLabel]) => {
			const option = document.createElement('option');
			option.value = optionValue;
			option.textContent = optionLabel;
			input.append(option);
		});
	};

	const createInspectorField = (field, value, path) => {
		const wrapper = document.createElement('div');
		wrapper.className = 'cb-core-design-shell__field';
		const id = `cb-mail-inspector-${field.key}-${path.join('-') || 'root'}`;
		const label = document.createElement('label');
		label.className = 'cb-core-design-shell__field-label';
		label.htmlFor = id;
		label.textContent = field.label || field.key;
		wrapper.append(label);

		let input;
		if (field.type === 'textarea') {
			input = document.createElement('textarea');
			input.rows = 5;
		} else if (field.type === 'select') {
			input = document.createElement('select');
			appendSelectOptions(input, field.options);
		} else {
			input = document.createElement('input');
			input.type = ['number', 'color', 'url'].includes(field.type) ? field.type : 'text';
			if (field.type === 'number') {
				if (Number.isFinite(Number(field.min))) input.min = String(field.min);
				if (Number.isFinite(Number(field.max))) input.max = String(field.max);
				if (Number.isFinite(Number(field.step))) input.step = String(field.step);
			}
		}
		input.id = id;
		input.value = value ?? '';
		input.addEventListener('change', () => {
			session.execute(commands.setProperty(path, [field.key], fieldValue(input, field.type)));
		});
		wrapper.append(input);
		return wrapper;
	};

	const createRootField = (field) => {
		const wrapper = document.createElement('div');
		wrapper.className = 'cb-core-design-shell__field';
		const id = `cb-mail-root-${field.key}`;
		const label = document.createElement('label');
		label.className = 'cb-core-design-shell__field-label';
		label.htmlFor = id;
		label.textContent = field.label;
		let input;
		if (field.type === 'select') {
			input = document.createElement('select');
			appendSelectOptions(input, field.options);
		} else {
			input = document.createElement('input');
			input.type = ['number', 'color', 'url'].includes(field.type) ? field.type : 'text';
			if (field.type === 'number') {
				input.min = String(field.min);
				input.max = String(field.max);
				input.step = String(field.step);
			}
		}
		input.id = id;
		input.value = field.value;
		input.addEventListener('change', () => {
			session.execute(commands.setProperty([], field.propertyPath, fieldValue(input, field.type)));
		});
		wrapper.append(label, input);
		return wrapper;
	};

	const renderEmailInspector = () => {
		emailInspector.replaceChildren();
		const heading = document.createElement('h3');
		heading.className = 'cb-core-design-shell__panel-section-title';
		heading.textContent = 'Email style';
		const description = document.createElement('p');
		description.className = 'cb-core-design-shell__panel-section-description';
		description.textContent = 'Global presentation settings for this mail template.';
		emailInspector.append(heading, description);

		const rootProperties = session.project()?.root?.properties || {};
		const layout = mailProfile.normalizeMailLayout(rootProperties.layout || {});
		const fontOptions = Object.fromEntries(mailProfile.MAIL_FONT_FAMILIES.map((fontFamily) => [fontFamily, fontFamily.split(',')[0].trim()]));
		const fields = [
			{ key: 'preheader', label: 'Preheader', type: 'text', propertyPath: ['preheader'], value: rootProperties.preheader || '' },
			{ key: 'width', label: 'Content width', type: 'number', min: 320, max: 800, step: 1, propertyPath: ['layout', 'width'], value: layout.width },
			{ key: 'fontFamily', label: 'Font family', type: 'select', options: fontOptions, propertyPath: ['layout', 'fontFamily'], value: layout.fontFamily },
			{ key: 'background', label: 'Page background', type: 'color', propertyPath: ['layout', 'background'], value: layout.background },
			{ key: 'contentBackground', label: 'Content background', type: 'color', propertyPath: ['layout', 'contentBackground'], value: layout.contentBackground },
			{ key: 'textColor', label: 'Default text color', type: 'color', propertyPath: ['layout', 'textColor'], value: layout.textColor },
			{ key: 'accentColor', label: 'Accent color', type: 'color', propertyPath: ['layout', 'accentColor'], value: layout.accentColor },
		];
		fields.forEach((field) => emailInspector.append(createRootField(field)));
	};

	const renderInspector = () => {
		inspector.replaceChildren();
		const selectedPath = session.editorState.selection.primary();
		if (!selectedPath) {
			const heading = document.createElement('h3');
			heading.className = 'cb-core-design-shell__panel-section-title';
			heading.textContent = 'Element inspector';
			const description = document.createElement('p');
			description.className = 'cb-core-design-shell__panel-section-description';
			description.textContent = 'Select an element on the canvas or in Structure to edit it.';
			inspector.append(heading, description);
			return;
		}

		const node = nodeAt(session.project(), selectedPath);
		if (!node) return;
		const definition = definitionForNode(node);
		const heading = document.createElement('h3');
		heading.className = 'cb-core-design-shell__panel-section-title';
		heading.textContent = definition?.label || node.type || 'Selected element';
		inspector.append(heading);

		if (definition && Array.isArray(definition.inspector) && definition.inspector.length) {
			definition.inspector.forEach((field) => {
				const value = node.properties?.[field.key] ?? definition.defaults?.[field.key];
				inspector.append(createInspectorField(field, value, selectedPath));
			});
		} else {
			const description = document.createElement('p');
			description.className = 'cb-core-design-shell__panel-section-description';
			description.textContent = 'This element does not expose editable properties.';
			inspector.append(description);
		}

		if (node.type !== 'mail.section') {
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'button cb-core-button';
			remove.textContent = 'Remove element';
			remove.addEventListener('click', () => {
				session.execute(commands.removeNode(selectedPath));
				session.editorState.selection.clear();
				renderSelectionViews();
			});
			const actions = document.createElement('div');
			actions.className = 'cb-core-design-shell__panel-actions';
			actions.append(remove);
			inspector.append(actions);
		}
	};

	const nodeSummary = (node, definition) => {
		const properties = node?.properties || {};
		const source = properties.text || properties.label || properties.alt || properties.url || '';
		const text = String(source).replace(/\s+/g, ' ').trim();
		return text ? text.slice(0, 70) : (definition?.label || node?.type || 'Element');
	};

	const moveNode = (path, toIndex) => {
		if (!Array.isArray(path) || !path.length) return false;
		const parent = parentPath(path);
		const fromIndex = path.at(-1);
		if (!Number.isInteger(fromIndex) || !Number.isInteger(toIndex) || fromIndex === toIndex) return false;
		session.execute(commands.reorderNode(parent, fromIndex, toIndex));
		return true;
	};

	const createStructureRow = (node, path, depth, siblingCount) => {
		const row = document.createElement('div');
		row.className = 'cb-core-design-shell__layer-row';
		row.style.setProperty('--cb-design-layer-depth', String(depth));
		row.draggable = path.length > 0;
		if (samePath(path, session.editorState.selection.primary())) row.classList.add('is-selected');

		const select = document.createElement('button');
		select.type = 'button';
		select.className = 'cb-core-design-shell__layer-select';
		const definition = definitionForNode(node);
		const label = document.createElement('span');
		label.className = 'cb-core-design-shell__layer-label';
		label.textContent = definition?.label || (node.type === 'mail.section' ? 'Section' : node.type || 'Element');
		const meta = document.createElement('span');
		meta.className = 'cb-core-design-shell__layer-meta';
		meta.textContent = nodeSummary(node, definition);
		select.append(label, meta);
		select.addEventListener('click', () => selectPath(path));

		const actions = document.createElement('div');
		actions.className = 'cb-core-design-shell__layer-actions';
		const index = path.at(-1);
		const addMoveButton = (icon, targetIndex, disabled, ariaLabel) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'button cb-core-button cb-core-design-shell__layer-action';
			button.textContent = ariaLabel;
			button.disabled = disabled;
			decorateDesignerControl(button, icon, { iconOnly: true, label: ariaLabel });
			button.addEventListener('click', () => moveNode(path, targetIndex));
			actions.append(button);
		};
		if (path.length > 0 && Number.isInteger(index)) {
			addMoveButton('arrow-up', Math.max(0, index - 1), index === 0, 'Move element up');
			addMoveButton('arrow-down', Math.min(siblingCount - 1, index + 1), index === siblingCount - 1, 'Move element down');
		}

		row.addEventListener('dragstart', (event) => {
			dragPath = [...path];
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData('text/plain', pathKey(path));
			}
		});
		row.addEventListener('dragover', (event) => {
			if (!Array.isArray(dragPath) || !samePath(parentPath(dragPath), parentPath(path))) return;
			event.preventDefault();
			if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
		});
		row.addEventListener('drop', (event) => {
			event.preventDefault();
			if (!Array.isArray(dragPath) || !samePath(parentPath(dragPath), parentPath(path))) return;
			const fromIndex = dragPath.at(-1);
			const toIndex = path.at(-1);
			if (Number.isInteger(fromIndex) && Number.isInteger(toIndex) && fromIndex !== toIndex) {
				session.execute(commands.reorderNode(parentPath(path), fromIndex, toIndex));
			}
			dragPath = null;
		});
		row.addEventListener('dragend', () => { dragPath = null; });

		row.append(select, actions);
		return row;
	};

	const renderStructure = () => {
		structure.replaceChildren();
		const tree = document.createElement('div');
		tree.className = 'cb-core-design-shell__layer-list';
		const projectRoot = session.project()?.root;
		const renderChildren = (children, parent, depth) => {
			if (!Array.isArray(children)) return;
			children.forEach((node, index) => {
				if (!node || typeof node !== 'object') return;
				const path = [...parent, index];
				tree.append(createStructureRow(node, path, depth, children.length));
				renderChildren(node.children, path, depth + 1);
			});
		};
		renderChildren(projectRoot?.children, [], 0);
		if (!tree.children.length) {
			const empty = document.createElement('p');
			empty.className = 'cb-core-design-shell__empty-state';
			empty.textContent = 'This mail template does not contain editable elements.';
			structure.append(empty);
			return;
		}
		structure.append(tree);
	};

	const render = () => {
		updateSerializedProject();
		renderEmailInspector();
		renderStructure();
		renderInspector();
		shell?.syncHistory();
		if (!contextHydrating) schedulePreview();
	};

	const session = createSession({
		project,
		profile: 'mail',
		onChange: render,
	});

	shell = createDesignerShell(shellRoot, {
		session,
		defaultPanel: 'email',
	});

	const templateIdFields = Array.from(root.querySelectorAll('input[name="template_id"]'));
	const resolveTemplateSelection = (value) => {
		const raw = String(value || '').trim();
		if (!raw) return { templateId: '', url: '' };
		try {
			const url = new URL(raw, window.location.href);
			if (url.origin !== window.location.origin) throw new Error('Cross-origin template URLs are not allowed.');
			return {
				templateId: String(url.searchParams.get('template') || '').trim(),
				url: url.href,
			};
		} catch (error) {
			return { templateId: raw, url: '' };
		}
	};

	const loadTemplateContext = async (selectionValue) => {
		const selection = resolveTemplateSelection(selectionValue);
		if (!selection.templateId) throw new Error('The selected mail template is invalid.');
		if (!ajaxUrl || !nonce) throw new Error('Mail Designer context switching is unavailable.');

		previewController?.abort();
		window.clearTimeout(previewTimer);
		const body = new URLSearchParams({
			action: 'cb_core_mail_designer_context',
			nonce,
			template_id: selection.templateId,
		});
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		});
		const payload = await response.json();
		const data = payload?.data;
		if (
			!response.ok
			|| !payload?.success
			|| !data
			|| typeof data.template_id !== 'string'
			|| typeof data.subject !== 'string'
			|| typeof data.html !== 'string'
			|| !data.project
			|| typeof data.project !== 'object'
		) {
			throw new Error(data?.message || 'The selected mail template could not be loaded.');
		}

		templateId = data.template_id;
		root.dataset.templateId = templateId;
		templateIdFields.forEach((field) => { field.value = templateId; });
		subjectField.value = data.subject;
		projectField.value = JSON.stringify(data.project);
		session.editorState.selection.clear();

		contextHydrating = true;
		try {
			session.replace(data.project, { source: 'context-switch' });
		} finally {
			contextHydrating = false;
		}

		preview.srcdoc = data.html;
		setPreviewStatus('Preview updated');
		shell.syncHistory();
		if (selection.url) window.history.replaceState(window.history.state, '', selection.url);
		return { templateId, customized: Boolean(data.customized) };
	};

	root.addEventListener('cb:design-shell:contextrequest', (event) => {
		const control = event.detail?.control;
		if (!(control instanceof HTMLSelectElement) || !control.matches('[data-cb-mail-template-select]')) return;
		if (typeof event.detail?.respondWith !== 'function') return;
		event.detail.respondWith(loadTemplateContext(event.detail.value));
	});

	root.querySelectorAll('[data-cb-mail-add]').forEach((button) => {
		button.addEventListener('click', () => {
			const definition = components[button.dataset.cbMailAdd || ''];
			const section = primarySection();
			if (!definition || !section) return;
			const newIndex = section.node.children.length;
			session.execute(commands.insertNode([section.index], newIndex, makeNode(definition)));
			session.editorState.selection.select([section.index, newIndex]);
			renderSelectionViews({ openInspector: true });
		});
	});

	root.querySelectorAll('[data-cb-mail-binding]').forEach((button) => {
		button.addEventListener('click', async () => {
			const token = button.dataset.cbMailBinding || '';
			if (!token) return;
			try {
				await navigator.clipboard.writeText(token);
				const previous = button.textContent;
				button.textContent = 'Copied';
				window.setTimeout(() => { button.textContent = previous; }, 900);
			} catch (error) {
				button.focus();
			}
		});
	});

	root.querySelectorAll('[data-cb-mail-viewport]').forEach((button) => {
		button.addEventListener('click', () => {
			const value = String(button.dataset.cbMailViewport || 'desktop');
			previewFrame?.classList.toggle('is-mobile', value === 'mobile');
			previewFrame?.classList.toggle('is-tablet', value === 'tablet');
			root.querySelectorAll('[data-cb-mail-viewport]').forEach((candidate) => {
				const active = candidate === button;
				candidate.classList.toggle('is-active', active);
				candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		});
	});

	preview.addEventListener('load', bindPreviewInteractions);
	subjectField.addEventListener('input', schedulePreview);
	form.addEventListener('submit', updateSerializedProject);

	window.addEventListener('beforeunload', () => {
		previewController?.abort();
		session.dispose();
	}, { once: true });

	renderEmailInspector();
	renderStructure();
	renderInspector();
	updateSerializedProject();
	shell.syncHistory();
	if (preview.contentDocument?.readyState === 'complete') bindPreviewInteractions();
	else schedulePreview();
}
