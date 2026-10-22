import { cloneFrozen, cloneValue } from './value.js';
import { locationForPath, nodeAt } from './tree.js';

export class InspectorState {
	#activeSection = null;
	#expanded = new Set();

	activeSection() { return this.#activeSection; }
	setActiveSection(section) { this.#activeSection = section === null ? null : String(section || '').trim() || null; }
	setExpanded(section, expanded) {
		const id = String(section || '').trim();
		if (!id) return false;
		if (expanded) this.#expanded.add(id);
		else this.#expanded.delete(id);
		return true;
	}
	isExpanded(section) { return this.#expanded.has(String(section || '').trim()); }
	snapshot() { return { activeSection: this.#activeSection, expanded: [...this.#expanded] }; }
	restore(snapshot) {
		if (!snapshot || typeof snapshot !== 'object') throw new TypeError('Invalid inspector snapshot.');
		this.#activeSection = typeof snapshot.activeSection === 'string' && snapshot.activeSection ? snapshot.activeSection : null;
		this.#expanded = new Set(Array.isArray(snapshot.expanded) ? snapshot.expanded.filter((item) => typeof item === 'string' && item) : []);
	}
	toJSON() { throw new TypeError('InspectorState is editor-session state and must not be persisted in DesignProject.'); }
}

export const resolveInspectorTarget = (root, selection, { project = null, resolveEntity = null } = {}) => {
	const refs = selection?.typedSnapshot?.().refs
		?? selection?.refs ?? (selection?.paths?.() ?? []).map((path) => ({ kind: 'node', path }));
	const entries = refs.map((ref) => {
		if (ref.kind === 'node') {
			const node = nodeAt(root, ref.path);
			return node ? { ref, path: ref.path, node: cloneValue(node) } : null;
		}
		if (ref.kind !== 'entity' || typeof resolveEntity !== 'function' || !project) return null;
		const entity = resolveEntity(ref, project);
		return entity ? { ref, entity: cloneValue(entity) } : null;
	}).filter(Boolean);
	const kind = !entries.length ? 'none' : entries.length === 1 ? 'single' : 'multiple';
	return cloneFrozen({ kind, entries });
};

export const buildInspectorContext = (root, selection, feedback = null, options = {}) => {
	const target = resolveInspectorTarget(root, selection, options);
	const diagnostics = target.entries.flatMap(({ path }) => (
		path ? feedback?.forLocation?.(locationForPath(path), { includeDescendants: true }) ?? [] : []
	));
	return cloneFrozen({ target, diagnostics });
};

/**
 * Strict declarative, no DOM or mutation. A missing typed target is an error,
 * never an invitation to silently select a similarly named field.
 */
export const projectInspectorControls = ({
	project, selection, controls, resolveEntity = null,
} = {}) => {
	if (!project?.root || !Array.isArray(controls)) throw new TypeError('Inspector projection requires a project and controls.');
	const target = resolveInspectorTarget(project.root, selection, { project, resolveEntity });
	return Object.freeze(controls.map((control) => {
		if (!control || typeof control !== 'object' || typeof control.id !== 'string') {
			throw new TypeError('Inspector control must be registered.');
		}
		const primary = target.entries.find((entry) => JSON.stringify(entry.ref) === JSON.stringify(
			selection?.typedSnapshot?.().primary ?? selection?.primary ?? null,
		)) ?? null;
		return cloneFrozen({
			key: control.id,
			label: String(control.label || ''),
			disabled: !primary || control.readonly === true,
			target: primary?.ref ?? null,
		});
	}));
};
