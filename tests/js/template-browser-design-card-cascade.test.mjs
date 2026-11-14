import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const formControls = read('assets/css/components/form-controls.css');
const uiAssets = read('src/UI/Assets.php');
const browserStyles = read('assets/css/components/template-library.css');
const browserSource = read('assets/js/core/template-browser.js');

function rule(css, selector) {
    const offset = css.indexOf(selector);
    assert.notEqual(offset, -1, 'Missing selector: ' + selector);
    const opener = css.indexOf('{', offset);
    const closer = css.indexOf('}', opener);
    assert.ok(opener !== -1 && closer > opener, 'Malformed rule: ' + selector);
    return css.slice(opener + 1, closer);
}

test('FIELD-05D: generic form label alignment excludes both selectable Browser card types', () => {
    // Prior bug: this high-specificity label:has(>input[type=radio]) rule
    // overrode the Designer Browser display:grid, leaving flex-direction:column.
    const selectors = formControls.slice(
        formControls.indexOf('/* Plain inline labels use centered'),
        formControls.indexOf('display: inline-flex;', formControls.indexOf('/* Plain inline labels use centered'))
    );
    for (const type of ['checkbox', 'radio']) {
        assert.match(
            selectors,
            new RegExp('label:[^\\n]+:not\\(\\.cb-template-browser__card\\):not\\(\\.cb-template-browser__card-select\\):has\\( > input\\[type="' + type + '"\\] \\)'),
            type + ' must exclude Browser cards from convenience inline-flex rule'
        );
    }
    assert.equal((selectors.match(/:not\(\.cb-template-browser__card\)/g) || []).length, 2);
    assert.equal((selectors.match(/:not\(\.cb-template-browser__card-select\)/g) || []).length, 2,
        'actionable contextual selection labels must not inherit inline-flex layout');
    assert.match(formControls, /label:not\(\.cb-core-radio-card\)/, 'ordinary label convenience remains');
    assert.match(formControls, /display: inline-flex;/, 'plain inline input labels remain flex');
    assert.match(formControls, /input\[type="radio"\]:checked/, 'native radio checked token behavior remains');
});

test('FIELD-05D: Design resources own a two-column grid, radio first and text second', () => {
    const card = rule(
        browserStyles,
        '.cb-template-browser--workspace[data-browser-view="designs"] .cb-template-browser__card--design {'
    );
    assert.match(card, /display:\s*grid;/);
    assert.match(card, /grid-template-columns:\s*20px minmax\(0,\s*1fr\);/);
    assert.match(card, /min-height:\s*74px;/);
    const radio = rule(
        browserStyles,
        '.cb-template-browser--workspace[data-browser-view="designs"] .cb-template-browser__card--design .cb-template-browser__choice {'
    );
    assert.match(radio, /position:\s*static;/);
    assert.match(radio, /grid-column:\s*1;/);
    assert.match(radio, /grid-row:\s*1;/);
    const details = rule(
        browserStyles,
        '.cb-template-browser--workspace[data-browser-view="designs"] .cb-template-browser__card--design .cb-template-browser__card-body {'
    );
    assert.match(details, /grid-column:\s*2;/);
    assert.match(details, /grid-row:\s*1;/);
    assert.match(browserSource, /const card = el\( 'label', 'cb-template-browser__card cb-template-browser__card--design' \)/);
    assert.match(browserSource, /card\.append\( radio, body \)/);
    assert.match(browserStyles, /cb-template-browser__current\s*\{[^}]*margin-inline-start:\s*auto;/);
});

test('FIELD-05D: shared template grid and global Library retain safe semantics', () => {
    assert.match(browserStyles, /\.cb-template-browser--workspace\[data-browser-view="templates"\] \.cb-template-browser__grid\s*\{/);
    // TL-1C: Browser-projected Preview joins the same Actions menu;
    // the selectable label stays separate from this interactive disclosure.
    assert.match(browserSource, /const previewable = providerSupportsPreview\( item \);/);
    assert.match(browserSource, /const article = el\( contextual && !menu && !previewable \? 'label' : 'article',\s*'cb-template-browser__card' \)/);
    assert.match(browserSource, /const selection = contextual && \( menu \|\| previewable \)\s*\? el\( 'label', 'cb-template-browser__card-select' \) : article;/);
    assert.match(browserSource, /selection\.append\( preview \)/);
    assert.match(browserSource, /if \( selection !== article \) article\.append\( selection \);/);
    assert.match(browserSource, /previewAvailable: previewable/);
    assert.match(browserSource, /onPreview: trigger => \{ void openPreview\( item, index, trigger \); \}/);
    assert.doesNotMatch(browserSource, /cb-template-browser__preview-trigger/);
    assert.match(browserSource, /if \( menu \) \{[\s\S]*?article\.append\( actions \);/);
    assert.match(browserStyles, /\.cb-template-library-page \.cb-template-browser \{/);
    assert.match(formControls, /:is\(\.cb-core-wrap, \.cb-core-form-scope\) label:has\( > input\[type="radio"\]:disabled \)/);
});

test('TL-M3B: template cards are compact, preview-footer-free, and retain source/version', () => {
    const description = rule(browserStyles, '.cb-template-browser__card-description');
    assert.match(description, /-webkit-line-clamp:\s*2;/);
    const metadata = rule(browserStyles,
        '.cb-template-browser--workspace[data-browser-view="templates"] .cb-template-browser__card-meta span');
    assert.match(metadata, /padding:\s*0;/);
    assert.match(metadata, /border:\s*0;/);
    const preview = rule(browserStyles,
        '.cb-template-library-page .cb-template-browser--workspace .cb-template-browser__card-preview');
    assert.match(preview, /height:\s*142px;/);
    assert.match(browserSource, /body\.append\( provenance \)/);
    assert.match(browserSource, /onPreview: trigger => \{ void openPreview\( item, index, trigger \); \}/);
    assert.doesNotMatch(browserSource, /cb-template-browser__preview-trigger/);
    assert.doesNotMatch(browserStyles, /\.cb-template-browser__preview-trigger/);
});

test('FRG-04 P1: selected card and keyboard focus do not paint stacked outlines', () => {
    const focus = browserStyles.slice(
        browserStyles.indexOf('.cb-template-browser__field :is(input, select):focus-visible,'),
        browserStyles.indexOf('.cb-template-browser__message')
    );
    assert.match(focus, /\.cb-template-browser__card:focus-visible/);
    assert.doesNotMatch(focus, /\.cb-template-browser__card:focus-within/);
    for (const selector of [
        '.cb-template-browser__card:has(.cb-template-browser__choice:checked)',
        '.cb-template-browser--workspace .cb-template-browser__card:has(.cb-template-browser__choice:checked)',
    ]) {
        const selected = rule(browserStyles, selector);
        assert.match(selected, /border-color:\s*var\(--cb-accent/);
        assert.match(selected, /box-shadow:\s*none;/);
        assert.doesNotMatch(selected, /inset 0 0 0/);
    }
    assert.match(browserStyles, /\.cb-template-browser__choice:focus-visible/);
    assert.match(browserStyles, /\.cb-template-browser__action-trigger:focus-visible/);
});

test('FRG-04 polish: preview button and navigation link share one neutral menu color', () => {
    const normal = rule(browserStyles,
        '.cb-template-browser__action-panel :is(button, a).cb-template-browser__action-item:not(.cb-template-browser__action-item--danger)');
    assert.match(normal, /color:\s*var\(--cb-text, inherit\);/);
    const interactive = rule(browserStyles,
        '.cb-template-browser__action-panel :is(button, a).cb-template-browser__action-item:not(.cb-template-browser__action-item--danger):is(:hover, :focus-visible)');
    assert.match(interactive, /color:\s*var\(--cb-text-strong,/);
    assert.match(browserStyles, /\.cb-template-browser__action-item--danger\s*\{\s*color:/);
    assert.match(browserSource, /item\.category === 'wordpress' \? 'WordPress'/);
    assert.match(browserSource, /item\.category === 'mail' \? 'Mail' : item\.category/);
    assert.match(browserSource, /item\.designer === 'mail' \? 'Mail' : item\.designer/);
    assert.match(browserSource, /'Source' \) \+ ': ' \+ item\.source/);
});

test('FIELD-05D: all Foundation enqueue paths load revised form-control CSS, not cached 1.0.0 CSS', () => {
    const registrations = [...uiAssets.matchAll(
        /wp_enqueue_style\(\s*'cb-core-css-form-controls'[\s\S]*?\);/g
    )];
    assert.ok(registrations.length > 1, 'multiple canonical consumers enqueue form controls');
    for (const match of registrations) {
        assert.match(match[0], /self::asset_revision\( 'assets\/css\/components\/form-controls\.css' \)/);
        assert.doesNotMatch(match[0], /CB_CORE_VERSION\s*\);/);
    }
});
