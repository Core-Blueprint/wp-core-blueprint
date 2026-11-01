import { createManagedContextAuthority } from './context-authority.js';
import { createManagedContextTransaction } from './context-transaction.js';

/**
 * Internal-only context authority test/host boundary.
 * Not a supported @cb-core/design-editor export and not on window.cbCore.
 * Canonical factories are installed once by editor.js at module evaluation.
 */
let managedFactories = null;
export const registerManagedContextFactories = (factories) => {
	if (managedFactories !== null || typeof factories?.createProfileSession !== 'function'
		|| typeof factories?.createDomainSession !== 'function') {
		throw new Error('DESIGNER_MANAGED_FACTORY_REGISTRATION_REFUSED');
	}
	managedFactories = Object.freeze({ ...factories });
};

/**
 * CX-02B: canonical Base-only managed session binding. The consumer-facing
 * surface cannot activate, retire, unlock or otherwise mutate context authority.
 * Only the internal module retains mutation authority.
 */
const managedContextInternals = new WeakMap();
export const createManagedContextBinding = ({ initial } = {}) => {
	if (managedFactories === null) throw new Error('DESIGNER_MANAGED_FACTORY_UNAVAILABLE');
	const authority = createManagedContextAuthority({
		createProfileSession: managedFactories.createProfileSession,
		createDomainSession: managedFactories.createDomainSession,
	});
	const initialHandle = authority.stage(initial);
	authority.activateInitial(initialHandle);
	const binding = Object.freeze({
		get current() { return managedContextInternals.get(binding)?.current ?? null; },
		stageContext(spec) { return authority.stage(spec); },
		isCurrent(handle) { return authority.isCurrent(handle); },
	});
	managedContextInternals.set(binding, { authority, current: initialHandle, coordinatorAttached: false });
	return binding;
};

/**
 * Base-only construction of the canonical context coordinator.
 * These constructors are absent from the supported editor.js entry and window facade.
 * No consumer can reach the private authority through its public ContextHandle.
 */
export const createManagedContextCoordinator = ({binding, ...options} = {}) => {
	const internals = managedContextInternals.get(binding);
	if (!internals || internals.coordinatorAttached) {
		throw new Error('DESIGNER_TRANSACTION_AUTHORITY_REFUSED');
	}
	internals.coordinatorAttached = true;
	try {
		return createManagedContextTransaction({
			...options, authority:internals.authority, state:internals,
		});
	} catch (error) {
		internals.coordinatorAttached = false;
		throw error;
	}
};

