import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const runtimeUrl = new URL('../../assets/js/data-exchange/data-mapper.js', import.meta.url);
const styleUrl = new URL('../../assets/css/data-exchange/data-mapper.css', import.meta.url);
const assetsUrl = new URL('../../src/DataExchange/Mapper/Assets.php', import.meta.url);
const rendererUrl = new URL('../../src/DataExchange/Mapper/Renderer.php', import.meta.url);

test('Data Mapper consumes the public shared Designer Shell without owning document profiles or shell geometry', async () => {
	const [runtime, styles, assets, renderer] = await Promise.all([
		readFile(runtimeUrl, 'utf8'),
		readFile(styleUrl, 'utf8'),
		readFile(assetsUrl, 'utf8'),
		readFile(rendererUrl, 'utf8'),
	]);

	assert.match(runtime, /from '@cb-core\/design-editor'/);
	assert.match(runtime, /createDesignerShell\(/);
	assert.match(assets, /DesignerAssets::enqueue_designer_mode/);
	assert.match(assets, /DesignerAssets::MODULE_ID/);
	assert.match(renderer, /data-cb-design-launch-root/);
	assert.match(renderer, /data-cb-design-shell/);
	assert.match(renderer, /data-cb-design-shell-group=\"mapper-details\"/);
	assert.match(renderer, /cb-core-design-shell__palette--composed/);
	assert.match(renderer, /cb-core-design-shell__panel-body/);
	assert.match(renderer, /cb-core-design-shell__panel-section/);
	assert.match(renderer, /cb-core-design-shell__field/);
	assert.match(renderer, /cb-core-design-shell__canvas--composed/);
	assert.match(renderer, /cb-core-design-shell__canvas-header/);
	assert.match(renderer, /cb-core-design-shell__canvas-workarea/);
	assert.match(renderer, /cb-core-design-shell__composition-stack/);

	for (const source of [runtime, styles, assets, renderer]) {
		assert.doesNotMatch(source, /\b(?:document-fixed|document-flow|mailProfile|Bricks|Brevo|Mailchimp)\b/i);
	}
	assert.doesNotMatch(runtime, /\bjQuery\b|\$\s*\(/);
	assert.doesNotMatch(styles, /position\s*:\s*fixed/i);
	assert.doesNotMatch(styles, /\.cb-core-data-mapper__workspace\b/);
	assert.doesNotMatch(styles, /\.cb-core-data-mapper__(?:source|canvas|sidebar)\s*\{/);
	assert.doesNotMatch(styles, /\.cb-core-data-mapper__control\b/);
	assert.doesNotMatch(styles, /\.cb-core-data-mapper__file-control\b/);
	assert.doesNotMatch(renderer, /cb-core-design-shell__workspace\s+cb-core-data-mapper__workspace/);
});

test('Data Mapper browser contract exposes controlled change, submit, intake and validation boundaries', async () => {
	const [runtime, renderer] = await Promise.all([
		readFile(runtimeUrl, 'utf8'),
		readFile(rendererUrl, 'utf8'),
	]);

	assert.match(runtime, /cb:data-mapper:ready/);
	assert.match(runtime, /cb:data-mapper:change/);
	assert.match(runtime, /cb:data-mapper:file-selected/);
	assert.match(runtime, /cb:data-mapper:submit/);
	assert.match(runtime, /file:\s*\(\) => selectedFile/);
	assert.match(runtime, /setSourceFields\(nextFields/);
	assert.match(runtime, /setTargetFields\(nextFields/);
	assert.match(runtime, /setValidation\(result\)/);
	assert.match(runtime, /setBusy\(nextBusy, message = ''\)/);
	assert.match(runtime, /replaceMapping\(nextMapping/);
	assert.match(runtime, /requiredMissing/);
	assert.match(runtime, /duplicate_or_forbidden/);
	assert.match(renderer, /data-cb-data-mapper-file/);
	assert.match(renderer, /Choose a source file to begin mapping/);

	// Busy is persistent session state. Re-rendering may not re-enable semantic
	// mutations while a consumer-owned server preview/apply operation is active.
	assert.match(runtime, /let busy = false/);
	assert.match(runtime, /primary\.disabled = busy \|\| !result\.valid/);
	assert.match(runtime, /auto\.disabled = busy \|\| sourceFields\.length === 0/);
	assert.match(runtime, /fileInput\.disabled = busy/);
	assert.match(runtime, /transform\.disabled = busy/);
	assert.match(runtime, /targetSelect\.disabled = busy \|\| entry\.transform !== 'direct'/);
	assert.match(runtime, /canUndo: \{ get: \(\) => !busy && historyIndex > 0 \}/);
	assert.match(runtime, /if \(busy \|\| historyIndex <= 0\) return false/);
	assert.doesNotMatch(runtime, /\bdestroy\(\)\s*\{/);

	// Base never uploads or applies data from the browser on its own. Consumers
	// own the authorized transport and server-side Data Exchange invocation.
	assert.doesNotMatch(runtime, /fetch\s*\(|XMLHttpRequest|wp\.apiFetch/);
	assert.doesNotMatch(runtime, /sessionStorage|localStorage|indexedDB/i);
});
