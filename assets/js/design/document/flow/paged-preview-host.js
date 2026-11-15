/**
 * DP-NAV-02C: Base-owned, authenticated single-pass PDF preview host.
 *
 * Consumers provide only a same-origin endpoint, already-authorized native
 * document envelope, page navigation and semantic mapping. The PDF bytes and
 * frame geometry must arrive together from the same server render. No Save.
 */
import {createFlowPagedSurface, validateFlowPageProjection} from './paged-surface.js';
import {createBundledPdfJsPageSource, loadBundledPdfJs} from './pdfjs-page-source.js';

const MAX_JSON_PDF_BASE64 = 11200000;
const MAX_PDF_BYTES = 8 * 1024 * 1024;

const decodePdf = encoded => {
	if (typeof encoded !== 'string' || encoded.length < 24
		|| encoded.length > MAX_JSON_PDF_BASE64
		|| encoded.length % 4 !== 0
		|| !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded)) {
		throw new TypeError('FLOW_PAGED_PDF_PAYLOAD_INVALID');
	}
	const binary = atob(encoded);
	if (binary.length < 16 || binary.length > MAX_PDF_BYTES) {
		throw new TypeError('FLOW_PAGED_PDF_PAYLOAD_INVALID');
	}
	const bytes = new Uint8Array(binary.length);
	for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
	return bytes;
};

const endpointUrl = (endpoint, baseUrl) => {
	if (typeof endpoint !== 'string' || !endpoint
		|| typeof baseUrl !== 'string') {
		throw new TypeError('FLOW_PAGED_PREVIEW_ENDPOINT_INVALID');
	}
	const base = new URL(baseUrl);
	const target = new URL(endpoint, base);
	if (target.origin !== base.origin || !['http:', 'https:'].includes(target.protocol)
		|| target.username || target.password || target.hash) {
		throw new TypeError('FLOW_PAGED_PREVIEW_ENDPOINT_UNTRUSTED');
	}
	return target.href;
};

export const createFlowPagedPreviewHost = ({
	root, navigation = null, resolveRegion = null, onActivate = null,
	onSource = null, onError = null, signal = null,
	pdfJsLoader = loadBundledPdfJs,
	pageSourceFactory = createBundledPdfJsPageSource,
} = {}) => {
	if (!root?.ownerDocument?.defaultView?.fetch || !root.replaceChildren
		|| (onActivate !== null && typeof onActivate !== 'function')
		|| (onSource !== null && typeof onSource !== 'function')
		|| (onError !== null && typeof onError !== 'function')
		|| (resolveRegion !== null && typeof resolveRegion !== 'function')
		|| typeof pdfJsLoader !== 'function' || typeof pageSourceFactory !== 'function'
		|| signal?.aborted) {
		throw new TypeError('FLOW_PAGED_PREVIEW_HOST_INVALID');
	}
	let current = null;
	let destroyed = false;
	let pending = null;
	let serial = 0;
	let selected = null;
	const doc = root.ownerDocument;
	const win = doc.defaultView;
	const baseUrl = doc.baseURI || win.location.href;

	const destroyCurrent = () => {
		current?.destroy();
		current = null;
	};
	const cancel = () => {
		++serial;
		pending?.abort();
		pending = null;
		destroyCurrent();
		root.replaceChildren();
	};
	const render = async ({endpoint, nonce, documentJSON, workerSrc, requestSignal = null} = {}) => {
		if (destroyed || signal?.aborted) throw new Error('FLOW_PAGED_PREVIEW_DESTROYED');
		if (typeof nonce !== 'string' || !nonce || typeof documentJSON !== 'string'
			|| documentJSON.length > 524288 || typeof workerSrc !== 'string'
			|| requestSignal?.aborted) {
			throw new TypeError('FLOW_PAGED_PREVIEW_REQUEST_INVALID');
		}
		const target = endpointUrl(endpoint, baseUrl);
		cancel();
		const generation = serial;
		const abortController = new AbortController();
		pending = abortController;
		const abort = () => abortController.abort();
		signal?.addEventListener('abort', abort, {once: true});
		requestSignal?.addEventListener('abort', abort, {once: true});
		try {
			// A fresh document revision invalidates prior geometry immediately.
			// Coalesce bursts of Inspector edits before making a costly PHP
			// Dompdf request; never permit an old revision to repaint the canvas.
			await new Promise(resolve => setTimeout(resolve, 220));
			if (destroyed || serial !== generation || abortController.signal.aborted) return false;
			const response = await win.fetch(target, {
				method: 'POST', credentials: 'same-origin', cache: 'no-store',
				headers: {'Content-Type': 'application/json', 'X-WP-Nonce': nonce, Accept: 'application/json'},
				body: documentJSON, signal: abortController.signal,
			});
			if (!response.ok) throw new Error('FLOW_PAGED_PREVIEW_UNAVAILABLE');
			const contentLength = Number(response.headers?.get?.('Content-Length') || 0);
			if (contentLength > 16000000) throw new Error('FLOW_PAGED_PREVIEW_OVERSIZED');
			const envelope = await response.json();
			if (destroyed || serial !== generation || abortController.signal.aborted) return false;
			if (!envelope || envelope.status !== 'ready'
				|| !['sample', 'archived'].includes(envelope.source)
				|| typeof envelope.pdf_base64 !== 'string'
				|| !envelope.projection || typeof envelope.projection !== 'object') {
				throw new TypeError('FLOW_PAGED_PREVIEW_ENVELOPE_INVALID');
			}
			const projection = validateFlowPageProjection(envelope.projection);
			const bytes = decodePdf(envelope.pdf_base64);
			const pdfjs = await pdfJsLoader();
			if (destroyed || serial !== generation || abortController.signal.aborted) return false;
			const pageSource = await pageSourceFactory({
				pdfjs, pdfBytes: bytes, workerSrc, baseUrl,
			});
			if (destroyed || serial !== generation || abortController.signal.aborted) {
				pageSource.destroy();
				return false;
			}
			try {
				const surface = createFlowPagedSurface({
					root, pageSource, projection, navigation,
					resolveRegion, onActivate,
					signal: abortController.signal,
					onPageError: () => {
						if (!destroyed && serial === generation) onError?.();
					},
				});
				current = surface;
				current.setSelection(selected);
				onSource?.(envelope.source);
				return true;
			} catch (error) {
				pageSource.destroy();
				throw error;
			}
		} catch (error) {
			if (!destroyed && serial === generation && !abortController.signal.aborted) {
				onError?.();
			}
			if (!destroyed && serial === generation && !abortController.signal.aborted) throw error;
			return false;
		} finally {
			requestSignal?.removeEventListener('abort', abort);
			signal?.removeEventListener('abort', abort);
			if (pending === abortController) pending = null;
		}
	};
	const setSelection = id => {
		if (destroyed) return false;
		selected = id;
		current?.setSelection(id);
		return true;
	};
	const destroy = () => {
		if (destroyed) return;
		destroyed = true;
		cancel();
	};
	signal?.addEventListener('abort', destroy, {once: true});
	return Object.freeze({render, setSelection, cancel, destroy});
};
