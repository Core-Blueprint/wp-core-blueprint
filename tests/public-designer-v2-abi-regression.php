<?php
declare(strict_types=1);
/**
 * G1d-B v2 History/Save with B2 v3 Shared Selection SOURCE preflight, never installed ZIP proof.
 * Run alongside executable JavaScript tests and installed WP module tests.
 */
$root = dirname(__DIR__);
$assets = file_get_contents($root . '/src/Design/Editor/Assets.php');
$editor = file_get_contents($root . '/assets/js/design/editor.js');
$shell = file_get_contents($root . '/assets/js/design/shell/index.js');
$document = file_get_contents($root . '/docs/DESIGNER-FOUNDATION-API.md');

if (!is_string($assets) || !is_string($editor) || !is_string($shell) || !is_string($document)) {
    fwrite(STDERR, "G1d-B: missing public contract sources\n");
    exit(1);
}
$require = static function (bool $condition, string $name): void {
    if (!$condition) {
        fwrite(STDERR, "G1d-B: SOURCE FAIL: {$name}\n");
        exit(1);
    }
};
$require((bool) preg_match('/public const DOMAIN_SESSION_API\s*=\s*3\s*;/', $assets), 'PHP v3 capability (v2 History/Save + B2 Shared Selection)');
$require(str_contains($assets, "public const MODULE_ID = '@cb-core/design-editor'"), 'canonical module identifier');
$renderer = file_get_contents($root . '/assets/js/design/shell/elements-palette.js');
$require(is_string($renderer) && str_contains($renderer, 'export const createDesignerElementsPalette'),
    'DF-A1 canonical public Elements renderer source');
$require((bool) preg_match('/public const DESIGNER_ELEMENTS_PALETTE_API\\s*=\\s*1\\s*;/', $assets),
    'DF-A1 optional same-build PHP capability');
$require(str_contains($editor, 'createDesignerElementsPalette,'),
    'DF-A1 named ESM renderer export');
$require(str_contains($editor, 'elements: Object.freeze({ createPalette: createDesignerElementsPalette })'),
    'DF-A1 global facade renderer parity');

$placement = file_get_contents($root . '/assets/js/design/shell/drop-placement.js');
$require(is_string($placement) && str_contains($placement, 'export const resolveDesignerInsertPlacement'),
    'DF-C canonical public insertion candidate source');
$require((bool) preg_match('/public const DESIGNER_CONTEXT_PLACEMENT_API\\s*=\\s*1\\s*;/', $assets),
    'DF-C optional same-build PHP capability');
$require(str_contains($editor, 'resolveDesignerInsertPlacement,'),
    'DF-C named ESM insertion export');
// DF-C guarantees the existing resolver, not an exact/frozen property count.
// DF-D2 may extend the same public facade with additional supported resolvers.
$require((bool) preg_match('/placement:\\s*Object\\.freeze\\(\\{\\s*resolveInsert:\\s*resolveDesignerInsertPlacement\\s*(?:,|\\})/', $editor),
    'DF-C global facade insertion parity');
$require((bool) preg_match('/public const DESIGNER_NODE_MOVE_API\\s*=\\s*1\\s*;/', $assets),
    'DF-D2 optional same-build PHP capability');
$require(str_contains($editor, 'resolveDesignerMovePlacement,'),
    'DF-D2 named ESM move export');
$require(str_contains($editor, 'resolveMove: resolveDesignerMovePlacement'),
    'DF-D2 global facade move parity');

$require(str_contains($assets, 'wp_enqueue_script_module(') && str_contains($assets, 'self::MODULE_ID'), 'WordPress module registration');
$require(str_contains($assets, 'public static function enqueue_designer_mode('), 'Designer Mode boundary');
foreach (['createSession', 'createDomainSession', 'createDesignerShell', 'createDesignerNavigation',
          'createDesignerInspectorIdentity', 'createDesignerLayerTree',
          'createDesignerSelectionController'] as $symbol) {
    $require(str_contains($editor, $symbol), "public module: {$symbol}");
}
foreach (['captureSave()', 'rebase(options)', 'subscribeHistory(listener)',
          'subscribeDiagnostics(listener)', 'baselineSnapshot()', 'clearHistory()'] as $method) {
    $require(str_contains($editor, $method), "session surface: {$method}");
}
$require(str_contains($editor, 'window.cbCore.designEditor = publicApi'), 'global facade');
$require(str_contains($shell, 'session.subscribeHistory(syncHistory)'), 'Shell postcommit history');
$require(str_contains($document, 'v2 SOURCE candidate'), 'documentation source state');
$require(str_contains($document, 'installed ZIP acceptance pending'), 'documented packaging gate');
echo "Base Designer G1d-B v2 History/Save + B2 v3 Shared Selection SOURCE regression: PASS\n";
