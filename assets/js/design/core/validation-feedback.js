const normalizeDiagnostic = (diagnostic) => {
	if (!diagnostic || typeof diagnostic !== 'object' || Array.isArray(diagnostic)) return null;
	const code = typeof diagnostic.code === 'string' ? diagnostic.code.trim() : '';
	const message = typeof diagnostic.message === 'string' ? diagnostic.message.trim() : '';
	const location = typeof diagnostic.location === 'string' ? diagnostic.location.trim() : '';
	return code && message && location ? Object.freeze({ code, message, location }) : null;
};

export class ValidationFeedback {
	#items = [];
	#writeGuard;

	constructor({ writeGuard = null, initialDiagnostics = [] } = {}) {
		if (writeGuard !== null && typeof writeGuard !== 'function') throw new TypeError('ValidationFeedback write guard must be a function.');
		if (!Array.isArray(initialDiagnostics)) throw new TypeError('Initial validation diagnostics must be an array.');
		this.#writeGuard = writeGuard;
		// Constructor-only bootstrap: no public mutation bypass after creation.
		this.#items = initialDiagnostics.map(normalizeDiagnostic).filter(Boolean);
	}

	replace(diagnostics) {
		this.#writeGuard?.();
		if (!Array.isArray(diagnostics)) throw new TypeError('Validation diagnostics must be an array.');
		this.#items = diagnostics.map(normalizeDiagnostic).filter(Boolean);
	}

	append(diagnostic) {
		this.#writeGuard?.();
		const normalized = normalizeDiagnostic(diagnostic);
		if (!normalized) return false;
		this.#items.push(normalized);
		return true;
	}

	clear() {
		this.#writeGuard?.();
		this.#items = [];
	}

	all() {
		return this.#items.map((item) => ({ ...item }));
	}

	count() {
		return this.#items.length;
	}

	hasErrors() {
		return this.#items.length > 0;
	}

	forLocation(location, { includeDescendants = true } = {}) {
		const target = String(location || '').trim();
		if (!target) return [];
		return this.#items
			.filter((item) => item.location === target || (includeDescendants && item.location.startsWith(`${target}.`)))
			.map((item) => ({ ...item }));
	}

	toJSON() {
		throw new TypeError('ValidationFeedback is editor-session state and must not be persisted in DesignProject.');
	}
}
