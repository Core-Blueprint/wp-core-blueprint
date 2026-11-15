/**
 * Optional source-owned JSON import for the canonical Template Library.
 * Source descriptors are metadata only; every preview/apply is reauthorized
 * on the server against the active actor, scope and provider Data Exchange.
 * ZIP resource imports remain a separate capability.
 */
import { show as showModal } from '@cb-core/modal';

const ID = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/;
const PREFIX = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\.$/;
const FINGERPRINT = /^[a-f0-9]{64}$/;
// An ambiguous POST remains unsafe to retry even if its Browser is closed
// and reopened in this page session. Only a deliberate page reload clears it.
const uncertainSources = new Set();
const element = (tag, className = '', label = '') => {
    const node = document.createElement(tag);
    node.className = className;
    if (label) node.textContent = label;
    return node;
};

export const jsonImportSources = (sources) => {
    if (!Array.isArray(sources) || sources.length > 24) return Object.freeze([]);
    const ids = new Set();
    const accepted = [];
    for (const source of sources) {
        if (!source || typeof source.id !== 'string' || !ID.test(source.id)
            || !source.jsonImport || source.jsonImport.version !== 1
            || typeof source.jsonImport.destinationPrefix !== 'string'
            || source.jsonImport.destinationPrefix.length > 64
            || !PREFIX.test(source.jsonImport.destinationPrefix)
            || ids.has(source.id)) continue;
        ids.add(source.id);
        accepted.push(Object.freeze({
            id: source.id, prefix: source.jsonImport.destinationPrefix,
        }));
    }
    return Object.freeze(accepted);
};

export function createJsonImportControl({
    label, restRoot, nonce, contextToken = '', isCurrent, onNotice, onChanged,
} = {}) {
    const node = element('div', 'cb-template-browser__json-import cb-template-browser__import-control');
    const choose = element('select', 'cb-template-browser__json-import-source');
    choose.setAttribute('aria-label', label('importDestination', 'Import destination'));
    choose.hidden = true;
    const button = element('button', 'button cb-template-browser__import cb-template-browser__json-import-button',
        label('importJson', 'Import JSON'));
    button.type = 'button';
    button.hidden = true;
    const fileInput = element('input', 'cb-template-browser__json-import-file');
    fileInput.type = 'file';
    fileInput.accept = '.json,application/json';
    fileInput.hidden = true;
    fileInput.setAttribute('aria-label', label('importJson', 'Import JSON'));
    node.append(choose, button, fileInput);

    let sources = Object.freeze([]);
    let busy = false;
    let uncertain = false;
    let destroyed = false;
    let available = false;
    const current = () => !destroyed && available
        && typeof isCurrent === 'function' && isCurrent();
    const sync = () => {
        node.hidden = !current() || sources.length === 0;
        button.hidden = node.hidden;
        choose.hidden = node.hidden || sources.length < 2;
        button.disabled = busy || uncertain
            || sources.some(source => uncertainSources.has(source.id));
        choose.disabled = busy || uncertain
            || sources.some(source => uncertainSources.has(source.id));
    };
    const updateSources = incoming => {
        sources = jsonImportSources(incoming);
        choose.replaceChildren(...sources.map(source => {
            const option = element('option', '', source.id);
            option.value = source.id;
            return option;
        }));
        sync();
    };
    const setVisible = value => {
        available = value === true;
        sync();
    };
    const notify = (key, fallback) => {
        if (!destroyed && typeof onNotice === 'function') onNotice(label(key, fallback));
    };
    const post = async (payload) => {
        const headers = {
            'X-WP-Nonce': nonce, 'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        if (contextToken) headers['X-CB-Designer-Scope'] = contextToken;
        const response = await fetch(restRoot + 'import-json', {
            method: 'POST', credentials: 'same-origin', headers,
            body: JSON.stringify(payload),
        });
        const result = await response.json();
        if (!response.ok || !result || typeof result !== 'object') throw new Error('JSON_IMPORT_REFUSED');
        return result;
    };
    const destination = source => {
        if (!source || !current() || typeof crypto?.getRandomValues !== 'function') {
            throw new Error('JSON_IMPORT_CONTEXT_INVALID');
        }
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        return source.prefix + Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    };
    const onButton = () => {
        if (current() && !busy && !uncertain && !button.disabled && sources.length) fileInput.click();
    };
    const onFile = async () => {
        const file = fileInput.files?.[0];
        if (!file || !current() || busy || uncertain || button.disabled) return;
        const source = sources.find(entry => entry.id === choose.value);
        if (!source || uncertainSources.has(source.id)) return;
        busy = true;
        sync();
        let applied = false;
        try {
            if (!file.size || file.size > 1150000) throw new Error('JSON_IMPORT_SIZE');
            const json = await file.text();
            if (!current() || !sources.includes(source) || json.length > 1150000) {
                throw new Error('JSON_IMPORT_STALE');
            }
            const destinationId = destination(source);
            const preview = await post({
                phase: 'preview', source: source.id, destinationId, file: json,
            });
            if (!current() || !sources.includes(source) || preview.status !== 'ready'
                || preview.source !== source.id || preview.destinationId !== destinationId
                || preview.preview?.valid !== true
                || preview.preview?.record_count !== 1
                || preview.preview?.counts?.create !== 1
                || typeof preview.preview.fingerprint !== 'string'
                || !FINGERPRINT.test(preview.preview.fingerprint)) {
                throw new Error('JSON_IMPORT_PREVIEW_REFUSED');
            }
            const confirmation = element('div', 'cb-template-browser__json-import-confirm');
            confirmation.append(
                element('p', '', label('importAsNew', 'Import as new copy')),
                element('p', '', file.name),
                element('p', '', label('source', 'Source') + ': ' + source.id),
            );
            // Presentation only: use the already verified file; untrusted
            // names always enter the DOM as plain text, never markup.
            try {
                const metadata = JSON.parse(json);
                const title = metadata?.records?.[0]?.title;
                if (typeof title === 'string' && title.length > 0 && title.length <= 191) {
                    confirmation.append(element('p', '',
                        label('templateName', 'Template name') + ': ' + title));
                }
            } catch { /* the server preview is authoritative */ }
            const confirmed = await showModal({
                title: label('importJson', 'Import JSON'),
                body: confirmation,
                confirmLabel: label('importAsNew', 'Import as new copy'),
            });
            if (confirmed !== true) return;
            if (!current() || !sources.includes(source)) throw new Error('JSON_IMPORT_STALE');
            applied = true;
            // From here, a transport failure is an unknown write outcome.
            const result = await post({
                phase: 'apply', source: source.id, destinationId, file: json,
                fingerprint: preview.preview.fingerprint,
            });
            if (!current() || result.status !== 'created'
                || result.source !== source.id || result.id !== destinationId
                || result.revision !== 1) throw new Error('JSON_IMPORT_UNCONFIRMED');
            notify('actionDone', 'Template action completed.');
            if (typeof onChanged === 'function') await onChanged();
        } catch {
            if (applied) {
                uncertain = true;
                uncertainSources.add(source.id);
            }
            notify(applied ? 'importUnknown' : 'importInvalid',
                applied ? 'Import outcome unknown. Inspect destination before attempting another import.'
                    : 'Package could not be verified.');
        } finally {
            fileInput.value = '';
            busy = false;
            sync();
        }
    };
    button.addEventListener('click', onButton);
    fileInput.addEventListener('change', onFile);
    return {
        node, updateSources, setVisible,
        destroy() {
            destroyed = true;
            sources = Object.freeze([]);
            button.removeEventListener('click', onButton);
            fileInput.removeEventListener('change', onFile);
            node.replaceChildren();
        },
    };
}
