import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const native = read('assets/css/components/status-indicators-native.css');
const core = read('assets/css/components/status-indicators.css');
const renderer = read('src/UI/Status.php');
const assets = read('src/UI/Assets.php');

function declaration(selector, property) {
  const escaped = selector.replace(/[.*+?^$()|[\]{}\\]/g, '\\$&');
  const rule = native.match(new RegExp(escaped + '\\s*\\{([^}]*)\\}'));
  assert.ok(rule, 'missing native rule ' + selector);
  const name = property.replace(/[.*+?^$()|[\]{}\\]/g, '\\$&');
  const match = rule[1].match(new RegExp('(?:^|;)\\s*' + name + '\\s*:\\s*([^;]+);?'));
  assert.ok(match, 'missing declaration ' + property + ' in ' + selector);
  return match[1].trim();
}

// Resolve the limited var() expressions used here, to exercise both a Core
// token palette and the WP-native fallback path without a browser dependency.
function resolve(expression, variables) {
  let result = expression;
  for (let depth = 0; depth < 4 && result.startsWith('var('); depth++) {
    assert.ok(result.endsWith(')'), 'unbalanced CSS variable expression');
    const inner = result.slice(4, -1);
    const comma = inner.indexOf(',');
    assert.ok(comma > 0, 'missing standalone fallback in ' + expression);
    const name = inner.slice(0, comma).trim();
    result = (variables[name] ?? inner.slice(comma + 1)).trim();
  }
  assert.ok(!result.startsWith('var('), 'unresolved token chain ' + expression);
  return result;
}

const dot = variant => declaration('.cb-core-status__dot--' + variant, 'background');
const label = declaration('.cb-core-status__label', 'color');
const variantToDot = {
  idle: 'muted', active: 'success', ready: 'warning',
  warning: 'warning', error: 'danger',
};

test('Native Status uses optional semantic tokens with WP-native fallback for every color', () => {
  assert.equal(label, 'var(--cb-text, #1d2327)');
  assert.equal(declaration('.cb-core-status__dot', 'background'), 'var(--cb-text-muted, #646970)');
  assert.equal(dot('muted'), 'var(--cb-text-muted, #8c8f94)');
  assert.equal(dot('success'), 'var(--cb-success, #00a32a)');
  assert.equal(dot('warning'), 'var(--cb-warning, #dba617)');
  assert.equal(dot('danger'), 'var(--cb-danger, #d63638)');
  assert.equal(dot('info'), 'var(--cb-accent, var(--wp-admin-theme-color, #2271b1))');
});

test('Light, dark and standalone native presentation retain all five status variants', () => {
  const palettes = [
    {
      name: 'light',
      vars: {
        '--cb-text': '#2a3240', '--cb-text-muted': '#5a6472',
        '--cb-success': '#188450', '--cb-warning': '#996800',
        '--cb-danger': '#b32d2e', '--cb-accent': '#0064c8',
      },
      text: '#2a3240',
      dots: { idle: '#5a6472', active: '#188450', ready: '#996800', warning: '#996800', error: '#b32d2e' },
    },
    {
      name: 'dark',
      vars: {
        '--cb-text': '#c5cbd4', '--cb-text-muted': '#8a92a0',
        '--cb-success': '#35e0a1', '--cb-warning': '#ffb547',
        '--cb-danger': '#ff5c7a', '--cb-accent': '#00b4ff',
      },
      text: '#c5cbd4',
      dots: { idle: '#8a92a0', active: '#35e0a1', ready: '#ffb547', warning: '#ffb547', error: '#ff5c7a' },
    },
    {
      name: 'wordpress-native-without-core-tokens',
      vars: {},
      text: '#1d2327',
      dots: { idle: '#8c8f94', active: '#00a32a', ready: '#dba617', warning: '#dba617', error: '#d63638' },
    },
  ];
  for (const p of palettes) {
    assert.equal(resolve(label, p.vars), p.text, p.name + ' label');
    for (const [variant, color] of Object.entries(p.dots)) {
      assert.equal(resolve(dot(variantToDot[variant]), p.vars), color, p.name + ' ' + variant);
    }
  }
  assert.equal(resolve(dot('info'), {}), '#2271b1');
  assert.equal(resolve(dot('info'), { '--wp-admin-theme-color': '#3858e9' }), '#3858e9');
});

test('Public Status render API, semantic variant mapping and accessibility are unchanged', () => {
  assert.match(renderer, /public static function render\( string \$variant, string \$label \): string/);
  for (const [variant, color] of Object.entries(variantToDot)) {
    assert.match(renderer, new RegExp("'" + variant + "'\\s*=>\\s*'cb-core-status__dot--" + color + "'"));
  }
  assert.match(renderer, /aria-hidden="true"/);
  assert.match(renderer, /esc_attr\( \$dot_class \)/);
  assert.match(renderer, /esc_html\( \$label \)/);
  assert.match(core, /background: var\(--cb-success\)/);
  assert.match(core, /color: var\(--cb-text\)/);
});

test('Standalone asset stays opt-in, theme-independent and cache-safe', () => {
  assert.match(assets, /public static function enqueue_status\( \?string \$presentation = null \): void/);
  assert.match(assets, /'cb-core-css-status-indicators-native',\s*CB_CORE_URL \. 'assets\/css\/components\/status-indicators-native\.css',\s*\[\],\s*self::asset_revision\( 'assets\/css\/components\/status-indicators-native\.css' \)/);
  assert.doesNotMatch(native, /@import|@use|booking|@layer\s+core-admin/i);
  assert.doesNotMatch(native, /(?:^|\n)\s*(?:body|html|\.button)\s*[\s.{#:]/);
  assert.match(read('assets/css/components/buttons-native.css'), /var\(--cb-text-strong, #1d2327\)/);
});
