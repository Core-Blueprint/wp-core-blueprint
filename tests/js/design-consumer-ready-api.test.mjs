import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const root = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

test('v3 Domain Selection and shared Layers/DnD/Toast entrypoints are exported through one Base module', async () => {
  const editor = await root('assets/js/design/editor.js');
  const assets = await root('src/Design/Editor/Assets.php');
  const shell = await root('assets/js/design/shell/layers.js');
  assert.match(assets, /public const MODULE_ID = '@cb-core\/design-editor';/);
  assert.match(assets, /public const DOMAIN_SESSION_API = 3;/);
  assert.match(assets, /public const SAVE_AUTHORITY_UI_API = 1;/);
  assert.match(assets, /public const DESIGNER_EXIT_GUARD_API = 3;/);
  for (const declaration of ['export const createSession =', 'export const createDomainSession =',
    'createDesignerShell,', 'createDesignerLayerTree,', 'createDesignerDragDropController,',
    'createDesignerNavigation,', 'createDesignerSelectionController,',
    'resolveDesignerDropPlacement,']) {
    assert.ok(editor.includes(declaration), declaration);
  }
  assert.match(editor, /selection\(\) \{ assertActive\(\); return editorState\.selection\.typedSnapshot\(\); \}/);
  assert.match(editor, /subscribeSelection\(listener/);
  assert.match(editor, /setDomainRefs\(refs/);
  assert.match(editor, /captureSave\(\)/);
  assert.match(editor, /rebase\(/);
  assert.match(editor, /shell: Object\.freeze\(/);
  assert.match(editor, /layers: Object\.freeze\(/);
  assert.match(shell, /resolveRemoveConfirmation\(action\.confirmation/);
});

test('Base external native Host admission is not required to consume the public v2 Foundation', async () => {
  const bootstrap = await root('src/Design/Bootstrap.php');
  const api = await root('docs/DESIGNER-FOUNDATION-API.md');
  assert.match(bootstrap, /MailPlatform::register/);
  assert.match(bootstrap, /ReportsPlatform::register/);
  assert.match(api, /createDomainSession\(\)/);
  assert.match(api, /Consumer-safe semantic confirmation/);
  assert.match(api, /consumer-owned/);
});
