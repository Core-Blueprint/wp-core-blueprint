import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

test('DF-EXIT-01: Native Mail and Maintenance Reports share one Host and canonical Exit Guard', () => {
  const native = source('assets/js/design/platform/wordpress-save-adapter.js');
  const host = source('assets/js/design/platform/host.js');
  const facade = source('assets/js/design/editor.js');
  const assets = source('src/Design/Editor/Assets.php');
  assert.match(native, /const exitGuard=window\.cbCore\?\.designerExitGuard;/);
  assert.match(native, /discard:\(\)=>host\.discard\(\)/);
  assert.match(native, /void exitGuard\.request\(root,/);
  assert.doesNotMatch(native, /window\.addEventListener\('beforeunload'/);
  assert.match(host, /active\.save\.discard\(\) !== true/);
  // WordPress receives attachTrustedDesignerHost(), not createPlatformHost().
  // Its capability assertion and synchronous delegation must remain present.
  assert.match(facade, /typeof host\.discard !== 'function'/);
  assert.match(facade, /discard\(\) \{\s*assertActive\(\);\s*return host\.discard\(\);\s*\}/);
  assert.match(assets, /DESIGNER_EXIT_GUARD_API = 4/);
  assert.match(assets, /self::enqueue_exit_guard\(\)/);
  assert.match(assets, /enqueue_modals/);
});

test('DF-EXIT-01: Native persisted-fields rollback witness commits, compensates and preserves accepted baseline', () => {
  const text = source('assets/js/design/platform/persisted-fields.js')
    .replace(/^import .*;\s*$/gm, '').replace(/\bexport /g, '');
  const create = new Function('validatePersistedFieldsV1', text + '\nreturn createPersistedFields;')(
    () => ({valid:true, diagnostics:[]})
  );
  const hash = 'sha256:' + 'a'.repeat(64);
  const profileId = 'base/mail', projectSchema = 'v1';
  const schema = Object.freeze({fingerprint:hash});
  const facet = Object.freeze({
    contractVersion:1, profileId, projectSchema, fieldDescriptors:[{saveKey:'subject'}],
    schemaGeneration:1, fingerprint:hash, registryFingerprint:hash,
    projectSchemaFingerprint:hash,
  });
  const registryView = Object.freeze({
    fingerprint:hash, profileId,
    persistedFieldFacet: () => facet,
    projectSchemaSnapshot: () => schema,
  });
  const fields = create({
    registryView, profileId, projectSchema, projectRegistrySnapshot:schema,
    fieldValidationContext:Object.freeze({}), fieldFacet:facet,
    initialFields:{subject:'Test'},
  });
  fields.set('subject', 'Test eee');
  let pending = fields.prepareDiscard();
  pending.commit();
  assert.equal(fields.snapshot().fields.subject, 'Test');
  assert.equal(fields.snapshot().dirty, false);
  pending.rollback();
  assert.equal(fields.snapshot().fields.subject, 'Test eee');
  assert.equal(fields.snapshot().dirty, true);
  pending = fields.prepareDiscard();
  pending.commit();
  assert.equal(fields.snapshot().fields.subject, 'Test');
  assert.equal(fields.snapshot().dirty, false);
  fields.destroy();
});

test('DF-EXIT-01: Native SaveCoordinator enforces UNKNOWN and unsynchronized fences before Discard', () => {
  const coordinator = source('assets/js/design/platform/save-coordinator.js');
  assert.match(coordinator, /blocksContextTransition\(\) \|\| flight \|\| pending/);
  assert.match(coordinator, /prepareDiscard\(\)/);
  assert.match(coordinator, /host\.discardToBaseline\(\{/);
  assert.match(coordinator, /notify\('PERSISTED_UNSYNCHRONIZED'\)/);
  assert.match(coordinator, /notify\('READY_CLEAN'\)/);
});

test('DF-EXIT-01R: Native Platform loads canonical context and toolbar CSS in order', () => {
  const assets = source('src/Design/Editor/Assets.php');
  const start = assets.indexOf('public static function enqueue_platform(): void {');
  const end = assets.indexOf('public static function enqueue_mail_rich_text_controls(): void {', start);
  assert.ok(start >= 0 && end > start, 'Native Platform enqueue boundary exists');
  const platform = assets.slice(start, end);
  assert.match(platform, /self::DESIGNER_CONTEXT_STYLE,\s*CB_CORE_URL \.[^\n]*designer-context\.css'/);
  assert.match(platform, /self::DESIGNER_TOOLBAR_STYLE,\s*CB_CORE_URL \.[^\n]*designer-toolbar\.css'/);
  assert.match(platform, /\[ self::DESIGNER_COMPOSITION_STYLE \]/);
  assert.match(platform, /\[ self::DESIGNER_CONTEXT_STYLE, self::BUTTON_STYLE \]/);
  assert.match(platform, /'cb-core-designer-platform-fullscreen',[\s\S]*?\[ self::DESIGNER_TOOLBAR_STYLE \]/);
  assert.ok(
    platform.indexOf('self::DESIGNER_CONTEXT_STYLE,') < platform.indexOf('self::DESIGNER_TOOLBAR_STYLE,'),
    'Context stylesheet must enqueue before Toolbar stylesheet'
  );
  const toolbar = source('assets/css/design/designer-toolbar.css');
  assert.match(toolbar, /\.cb-core-designer-exit-choices\s*\{[^}]*display:\s*grid/s);
  assert.match(toolbar, /\.cb-core-designer-exit-choice\s*\{[^}]*display:\s*flex/s);
});
