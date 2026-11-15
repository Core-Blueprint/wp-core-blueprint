/**
 * Optional PDF.js 6.5.438 adapter for the shared Document Flow paged surface.
 *
 * PDF.js is provided by the Base-owned, locally bundled module loader once
 * the dependency is admitted. This file MUST NOT fetch a CDN, arbitrary URL
 * or remote document. It receives only the same-render PDF byte snapshot.
 */
const MAX_PDF_BYTES = 16 * 1024 * 1024;
const PINNED_PDFJS_VERSION = '6.5.438';

const validatedWorkerUrl = (workerSrc, baseUrl) => {
	if (typeof workerSrc !== 'string' || !workerSrc || typeof baseUrl !== 'string') {
		throw new TypeError('FLOW_PAGED_PDFJS_WORKER_INVALID');
	}
	let worker;
	let base;
	try {
		base = new URL(baseUrl);
		worker = new URL(workerSrc, base);
	} catch (_) {
		throw new TypeError('FLOW_PAGED_PDFJS_WORKER_INVALID');
	}
	if (base.protocol !== 'https:' && base.protocol !== 'http:') {
		throw new TypeError('FLOW_PAGED_PDFJS_ORIGIN_INVALID');
	}
	if (worker.origin !== base.origin || !worker.pathname.endsWith('/pdf.worker.min.mjs')
		|| worker.username || worker.password || worker.hash) {
		throw new TypeError('FLOW_PAGED_PDFJS_WORKER_UNTRUSTED');
	}
	return worker.href;
};

export const createBundledPdfJsPageSource = async ({
	pdfjs, pdfBytes, workerSrc, baseUrl,
} = {}) => {
	if (!pdfjs || pdfjs.version !== PINNED_PDFJS_VERSION
		|| typeof pdfjs.getDocument !== 'function'
		|| !pdfjs.GlobalWorkerOptions || !pdfjs.GlobalWorkerOptions.workerSrc) {
		// An unset workerSrc is accepted: it will be assigned below.
		if (!pdfjs || pdfjs.version !== PINNED_PDFJS_VERSION
			|| typeof pdfjs.getDocument !== 'function' || !pdfjs.GlobalWorkerOptions) {
			throw new TypeError('FLOW_PAGED_PDFJS_UNAVAILABLE');
		}
	}
	const workerUrl = validatedWorkerUrl(workerSrc, baseUrl);
	const bytes = pdfBytes instanceof Uint8Array
		? pdfBytes.slice()
		: pdfBytes instanceof ArrayBuffer ? new Uint8Array(pdfBytes.slice(0)) : null;
	if (!bytes || bytes.byteLength < 16 || bytes.byteLength > MAX_PDF_BYTES
		|| bytes[0] !== 37 || bytes[1] !== 80 || bytes[2] !== 68
		|| bytes[3] !== 70 || bytes[4] !== 45) {
		throw new TypeError('FLOW_PAGED_PDF_BYTES_INVALID');
	}
	pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
	const task = pdfjs.getDocument({
		data: bytes,
		isEvalSupported: false,
		enableXfa: false,
		disableAutoFetch: true,
		disableStream: true,
		stopAtErrors: true,
	});
	let destroyed = false;
	let doc;
	try {
		doc = await task.promise;
		if (!Number.isSafeInteger(doc?.numPages) || doc.numPages < 1 || doc.numPages > 500
			|| typeof doc.getPage !== 'function') {
			throw new TypeError('FLOW_PAGED_PDFJS_DOCUMENT_INVALID');
		}
	} catch (error) {
		try { await task.destroy?.(); } catch (_) { /* Reject remains authoritative. */ }
		throw error;
	}
	return Object.freeze({
		numPages: doc.numPages,
		getPage(index) {
			if (destroyed || !Number.isSafeInteger(index) || index < 1 || index > doc.numPages) {
				throw new TypeError('FLOW_PAGED_PDFJS_PAGE_INVALID');
			}
			return doc.getPage(index);
		},
		destroy() {
			if (destroyed) return;
			destroyed = true;
			void task.destroy?.();
		},
	});
};

export const BUNDLED_PDFJS_VERSION = PINNED_PDFJS_VERSION;
