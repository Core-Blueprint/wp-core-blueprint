import '../editor.js';
import { sameValue } from './value.js';
import { createManagedContextBinding, createManagedContextCoordinator, revokeManagedContextBinding } from './context-managed.js';
import { createDesignerShell } from '../shell/index.js';
import { createDesignerSelectionController } from '../shell/selection-controller.js';

const SLOT_NAMES = Object.freeze(['canvas','layers','inspector','controls','preview']);
const unchanged = (before, slots) => SLOT_NAMES.every((key) => {
	const actual = Array.from(slots[key].childNodes);
	return actual.length === before[key].length
		&& actual.every((node, index) => node === before[key][index]);
});

/**
 * Internal Base-only adapter for Exit Guard API v4. This is deliberately NOT
 * exported by the supported Designer entry or attached to the global facade.
 * A consumer supplies declarative context specs and detached presentations.
 */
export const createManagedV4Context = ({
	shell, context, isRegistered, isSettled, isDirty, decide,
} = {}) => {
	if (!shell || shell.isConnected === false || context?.initial?.kind !== 'domain'
		|| typeof context.prepare !== 'function' || !context.mountSlot
		|| !SLOT_NAMES.every((key) => context.mountSlot[key]?.replaceChildren
			&& context.mountSlot[key]?.childNodes)
		|| typeof isRegistered !== 'function' || typeof isSettled !== 'function'
		|| typeof isDirty !== 'function' || typeof decide !== 'function'
		|| !isRegistered()) {
		throw new Error('DESIGNER_V4_REGISTRATION_INVALID');
	}
	const slots = Object.freeze(Object.fromEntries(
		SLOT_NAMES.map((key) => [key, context.mountSlot[key]])
	));
	const binding = createManagedContextBinding({initial:context.initial});
	// Reuse one Base Shell, History and Selection. Never create an alternate
	// controller when an incompatible existing Shell already owns this root.
	const shellController = createDesignerShell(shell,{session:binding.current.session});
	const selectionController = createDesignerSelectionController({
		session:binding.current.session, shell:shellController, typed:'domain',
		renderLayers:context.renderLayers ?? null,
		renderInspector:context.renderInspector ?? null,
		syncCanvas:context.syncCanvas ?? null,
	});
	const live = () => isRegistered() && isSettled();
	const consistent = (handle) => {
		if (!live() || handle !== binding.current) return false;
		const observed = isDirty();
		const actual = !sameValue(handle.session.project(),handle.session.baselineSnapshot());
		return typeof observed === 'boolean' && observed === actual;
	};
	const coordinator = createManagedContextCoordinator({
		binding,shell:shellController,selection:selectionController,slots,
		authorize:async () => consistent(binding.current),
		load:async ({targetId,sourceId,sourceGeneration,signal,isCurrent}) => {
			if (!consistent(binding.current) || !isCurrent()) {
				throw new Error('DESIGNER_CONTEXT_STALE');
			}
			const before = Object.fromEntries(
				SLOT_NAMES.map((key) => [key,Array.from(slots[key].childNodes)])
			);
			const result = await context.prepare(Object.freeze({
				targetId,sourceId,sourceEpoch:sourceGeneration,signal,
			}));
			if (!isCurrent() || !live() || !unchanged(before,slots)) {
				throw new Error('DESIGNER_SOURCE_CHANGED');
			}
			return result;
		},
		decide:async () => live() ? decide() : 'cancel',
		save:typeof context.save === 'function' ? (checkpoint) => context.save(checkpoint) : null,
		saveStatus:() => live() ? 'IDLE' : 'UNKNOWN',
		revalidate:({sourceId,targetId}) => consistent(binding.current)
			&& binding.current.id === sourceId && sourceId !== targetId,
		verify:({targetId,session}) => live() && Boolean(targetId && session),
		cleanup:typeof context.cleanup === 'function' ? (receipt) => context.cleanup(receipt) : null,
	});
	let closed = false;
	let inFlight = 0;
	const finish = () => {
		if (closed && inFlight === 0 && coordinator.status().state === 'READY') {
			if (shellController.destroy() === false) return false;
			selectionController.destroy();
			return revokeManagedContextBinding(binding);
		}
		return false;
	};
	return Object.freeze({
		current:() => closed ? null : binding.current,
		transition:async (targetId) => {
			if (closed) return Object.freeze({status:'refused',code:'DESIGNER_V4_CLOSED'});
			inFlight++;
			try { return await coordinator.transition({targetId}); }
			finally { inFlight--; finish(); }
		},
		cancelPending:() => coordinator.cancelPending(),
		close:() => {
			if (closed) return false;
			if (inFlight !== 0 || coordinator.status().state !== 'READY') return false;
			coordinator.cancelPending();
			try {
				if (shellController.destroy() === false) return false;
				selectionController.destroy();
				if (revokeManagedContextBinding(binding) !== true) return false;
				closed = true;
				return true;
			} catch (_) {
				// A failed teardown must never be reported as a closed lifecycle.
				return false;
			}
		},
		status:() => coordinator.status(),
	});
};
