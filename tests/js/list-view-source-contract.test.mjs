import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../../', import.meta.url);
const source = readFileSync(new URL('src/UI/ListView.php', root), 'utf8');
const registry = readFileSync(new URL('src/Admin/PageRegistry.php', root), 'utf8');
const styles = readFileSync(new URL('assets/css/components/list-view.css', root), 'utf8');

test('List View is opt-in through the public PageRegistry foundation and PHP renderer', () => {
  assert.match(registry, /'list-view'/);
  assert.match(registry, /\\CoreBlueprint\\Core\\UI\\ListView::enqueue\(\)/);
  assert.match(source, /public static function render\( array \$args \): string/);
  assert.match(source, /public static function enqueue\(\): void/);
  assert.match(source, /assets\/css\/components\/filter-bar\.css/);
  assert.match(source, /assets\/css\/components\/list-view\.css/);
});

test('List View renders escaped bounded scalar data without a query or mutation engine', () => {
  assert.match(source, /MAX_COLUMNS = 20/);
  assert.match(source, /MAX_ROWS = 500/);
  assert.match(source, /MAX_FILTERS = 16/);
  assert.match(source, /MAX_ACTIONS = 8/);
  assert.match(source, /esc_html\(/);
  assert.match(source, /esc_attr\(/);
  assert.match(source, /esc_url\(/);
  assert.match(source, /throw new \\InvalidArgumentException/);
  assert.match(source, /method="get"/);
  assert.match(source, /aria-sort=/);
  for (const forbidden of [
    /register_rest_route\(/, /update_option\(/, /wp_insert_post\(/,
    /\$wpdb->/, /wp_remote_/, /innerHTML/, /<script/
  ]) {
    assert.doesNotMatch(source, forbidden);
  }
});

test('List View adds an accessible server-rendered table, without replacing native WP tables', () => {
  assert.match(source, /<table class="cb-core-list-view__table"/);
  assert.match(source, /<th scope="row">/);
  assert.match(source, /scope="col"/);
  assert.match(source, /role="region" tabindex="0"/);
  assert.match(source, /cb-core-list-view__pagination/);
  assert.match(source, /cb-core-list-view__state--error/);
  assert.doesNotMatch(source, /WP_List_Table/);
});

test('List View styling is contained, responsive, RTL-friendly and reduced-motion aware', () => {
  assert.match(styles, /\.cb-core-list-view__table/);
  assert.match(styles, /\.cb-core-list-view__scroll:focus-visible/);
  assert.match(styles, /overflow-x: auto/);
  assert.match(styles, /@media \(max-width: 480px\)/);
  assert.match(styles, /prefers-reduced-motion: reduce/);
  assert.match(styles, /padding-block|border-block|margin-block/);
  assert.doesNotMatch(styles, /(?:^|\n)\s*#wpadminbar\b/);
  assert.doesNotMatch(styles, /(?:^|\n)\s*\.wp-list-table\b/);
});
