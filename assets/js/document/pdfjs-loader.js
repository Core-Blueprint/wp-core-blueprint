/**
 * Base Document Flow — deferred PDF.js display-module resolver.
 *
 * This non-Designer bridge is intentional: the canonical Design module
 * artifact guard requires every assets/js/design/*.js import to be static.
 * The locally vendored PDF.js module is evaluated only when a paginated
 * document actually needs rendering, not when the shared Mail/Reports Host
 * initializes. WordPress owns the '@cb-core/pdfjs-display' import map entry.
 *
 * No remote URL or CDN fallback is permitted.
 */
const PINNED_PDFJS_VERSION = '6.5.438';

export const loadBundledPdfJs = async () => {
	const pdfjs = await import('@cb-core/pdfjs-display');
	if (pdfjs?.version !== PINNED_PDFJS_VERSION ||
		typeof pdfjs.getDocument !== 'function' ||
		!pdfjs.GlobalWorkerOptions) {
		throw new TypeError('FLOW_PAGED_PDFJS_UNAVAILABLE');
	}
	return pdfjs;
};
