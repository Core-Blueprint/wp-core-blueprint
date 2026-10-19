import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (file) => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const stylesheet = read('assets/css/admin-theme/gutenberg.css');
const tokens = read('assets/css/tokens.css');
const marker = 'Native Gutenberg SelectControl in application chrome';
const start = stylesheet.indexOf(marker);
assert.notEqual(start, -1, 'SelectControl adapter must exist in canonical Gutenberg stylesheet');
const addition = stylesheet.slice(stylesheet.lastIndexOf('/*', start)).replace(/\/\*[\s\S]*?\*\//g, '');
const rules = [...addition.matchAll(/([^{}]+)\{([^{}]+)\}/g)].map((match) => ({
  selector: match[1].trim(),
  declarations: match[2].trim(),
}));

test('GB-SELECT-01: only public WP SelectControl inside Gutenberg application chrome is themed', () => {
  assert.ok(rules.length >= 6, 'closed control, options, disabled and both modes need scoped rules');
  for (const { selector } of rules) {
    assert.match(selector, /body\.cb-admin-theme/);
    assert.match(selector, /interface-interface-skeleton__sidebar/);
    assert.match(selector, /interface-complementary-area/);
    assert.match(selector, /editor-sidebar/);
    assert.match(selector, /editor-post-taxonomies/);
    assert.match(selector, /components-select-control|data-wp-component="SelectControl"/);
    assert.doesNotMatch(selector, /iframe|editor-styles-wrapper|block-editor-block-list__layout/);
    assert.doesNotMatch(selector, /^(?:select|option|optgroup)(?:\s|,|:|$)/, 'no top-level unscoped native element selector');
  }
  assert.doesNotMatch(addition, /!important|-[a-z]*-[0-9a-f]{6}|#[0-9a-f]{3,8}\b/i);
});

test('GB-SELECT-02: native popup, options, optgroups and disabled contrast inherit Base tokens', () => {
  assert.match(addition, /select\.components-select-control__input/);
  assert.match(addition, /\[data-wp-component="SelectControl"\]\s+select/);
  assert.match(addition, /:is\(option, optgroup\)/);
  assert.match(addition, /:disabled/);
  assert.match(addition, /:is\(option, optgroup\):disabled/);
  assert.match(addition, /background-color:\s*var\(--cb-surface-1\)/);
  assert.match(addition, /color:\s*var\(--cb-text\)/);
  assert.match(addition, /color:\s*var\(--cb-text-muted\)/);
  assert.match(addition, /border-color:\s*var\(--cb-border\)/);
  assert.match(addition, /accent-color:\s*var\(--cb-accent\)/);
  for (const [, token] of addition.matchAll(/var\((--cb-[a-z0-9-]+)\)/g)) {
    assert.ok(tokens.includes(token + ':'), `Base token ${token} must exist`);
  }
});

test('GB-SELECT-03: active HUD theme changes native color-scheme without global override', () => {
  const dark = rules.find((rule) => rule.selector.includes('html[data-cb-theme="core_blueprint_dark"]'));
  const light = rules.find((rule) => rule.selector.includes('html[data-cb-theme="core_blueprint_light"]'));
  assert.ok(dark && light);
  assert.match(dark.declarations, /color-scheme:\s*dark\s*;/);
  assert.match(light.declarations, /color-scheme:\s*light\s*;/);
  assert.match(dark.selector, /body\.cb-admin-theme/);
  assert.match(light.selector, /body\.cb-admin-theme/);
  assert.doesNotMatch(addition, /prefers-color-scheme|color-scheme:\s*(?:normal|only)/);
});

test('GB-SELECT-04: native focus, keyboard and hover behavior remain WordPress-owned', () => {
  assert.doesNotMatch(addition, /:focus|:hover|outline:|box-shadow:|appearance:|pointer-events:|display:|position:|z-index:/);
  assert.doesNotMatch(addition, /\.components-combobox-control|\.components-form-token-field|\.block-editor-block-list__/);
});
