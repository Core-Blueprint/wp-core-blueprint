import { normalizeFlowLayout } from './layout.js';

export const validateProject = (project) => {
	const layout = project?.root?.properties?.layout;
	normalizeFlowLayout(layout);
	return project;
};

export { createFlowPreviewHost } from './preview-host.js';
export { setFlowHintsCommand } from './commands.js';
export { buildFlowInspectorContext } from './inspector.js';
export { normalizeFlowHints, normalizeFlowLayout } from './layout.js';
