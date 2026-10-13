import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const stylesheet = await readFile(new URL('../../assets/css/design/editor-shell.css', import.meta.url), 'utf8');
const rules = [...stylesheet.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((match) => ({
	selectors: match[1],
	declarations: match[2],
}));

const composedRule = (target) => rules.find(({ selectors }) => (
	selectors.includes('.cb-core-design-shell.is-fullscreen .cb-core-design-shell__sidebar--composed' + target)
	&& selectors.includes('[data-cb-design-launch-mode="direct"]')
	&& selectors.includes('.cb-core-design-shell__sidebar--composed' + target)
));

const modeStylesheet = await readFile(new URL('../../assets/css/design/designer-mode.css', import.meta.url), 'utf8');

test('responsive Designer drawers retain composed flex rails under the generic block override', () => {
	const responsive = modeStylesheet.split('@media (max-width: 1280px) {')[1]?.split('@media (prefers-reduced-motion: reduce)')[0];
	assert.ok(responsive, 'The shared Designer must declare responsive drawer layout.');
	assert.match(responsive, /\.cb-core-design-shell__workspace--collapsible\s*>\s*\.cb-core-design-shell__palette--tabbed\s*,\s*\.cb-core-design-shell__workspace--collapsible\s*>\s*\.cb-core-design-shell__sidebar--composed\s*\{\s*display:\s*flex\s*!important;\s*flex-direction:\s*column;\s*overflow:\s*hidden;/);
});

test('composed Designer sidebar itself never scrolls in direct or fullscreen mode', () => {
	const rule = composedRule('');
	assert.ok(rule, 'Expected a shared direct/fullscreen sidebar composition rule.');
	assert.match(rule.declarations, /display:\s*flex\s*;/);
	assert.match(rule.declarations, /flex-direction:\s*column\s*;/);
	assert.match(rule.declarations, /overflow:\s*hidden\s*;/);
});

test('composed sidebar tabs remain outside the content scroller', () => {
	const rule = composedRule(' > .cb-core-design-shell__tabs');
	assert.ok(rule, 'Expected a fixed-height tabs rule for both launch modes.');
	assert.match(rule.declarations, /flex:\s*0\s+0\s+auto\s*;/);
});

test('the active sidebar panel owns scrolling without expanding the rail', () => {
	const rule = composedRule(' > .cb-core-design-shell__sidebar-panel:not([hidden])');
	assert.ok(rule, 'Expected a dedicated active-panel scroller for both launch modes.');
	assert.match(rule.declarations, /flex:\s*1\s+1\s+auto\s*;/);
	assert.match(rule.declarations, /min-height:\s*0\s*;/);
	assert.match(rule.declarations, /overflow-y:\s*auto\s*;/);
});
