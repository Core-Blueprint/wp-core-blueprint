# Gutenberg Native SelectControl Theme Polish

**Status:** SOURCE candidate, installed visual acceptance pending.
**Scope:** Base Admin Theme Foundation, `assets/css/admin-theme/gutenberg.css`.
**Base baseline:** `main` at `d7b251fa0fe8df3e5b53dc1e9f1b866df508905e`.

## What this changes

The Base adapter now styles WordPress Gutenberg `SelectControl` inputs
in editor **application chrome**, including the post sidebar, block inspector,
post taxonomies when rendered as native selects, and component popovers.

Only stable public WordPress component markers and editor chrome selectors are
used. The closed select, its input-control container, native `option` and
`optgroup` descendants use existing Base semantic tokens. The enclosing
`html[data-cb-theme]` selector applies `color-scheme: dark` or `light`
**to each select**, not globally. Switching the Core Blueprint HUD theme thus
recomputes the native select color preference without refreshing the editor.

This deliberately does **not** style post/block content, iframe content,
frontend UI, arbitrary select elements, bespoke third-party popovers, or
Gutenberg combobox/tag-token fields that are not native `SelectControl`.

## Browser and OS limitations

A native `select` menu may be rendered in a browser/OS-managed popup that
does not fully honor author CSS on `option` or `optgroup`, including
background, selected-row accent, hover appearance, and scrolling geometry.
`color-scheme` is an appropriate hint to the platform but does **not**
guarantee pixel-identical popups across Chrome, Firefox, Safari, or operating
systems. We intentionally do not simulate the popup with a custom library or
override native keyboard handling, focus outlines, browser selection cues, or
disabled semantics.

Categories and Tags may use checkboxes, search/token fields or comboboxes
rather than native SelectControl markup. This patch must not be claimed as a
comprehensive restyling of those different component types.

## Acceptance matrix

- WordPress 7.0: Dark/Light, select closed/opened, available native options,
  option groups, selected item, disabled, focus, keyboard, and hover.
- WordPress 7.1.3: repeat the same matrix using the current Gutenberg chrome.
- HUD theme switch while the same select stays mounted: both modes update
  without a browser reload.
- Confirm post sidebar taxonomies when native SelectControl is present,
  and block Inspector native selects. Do not force Category/Tags markup into
  a select or style a token field as a native select.
- Inspect one browser/OS that paints native popup options via system chrome
  and record any divergence separately from the CSS source PASS.

## Build and release boundary

`node --test tests/js/gutenberg-select-theme.test.mjs` is the focused
source regression. `./tools/build-dev` produces an **internal** Base ZIP;
it does not establish Level 2 installed proof or public release acceptance.
Do not merge to `main`, trigger Actions, or deploy without separate GO.

The Base Designer Exit Guard work is on an unrelated featurebranch and must
be reconciled only after both independent feature acceptance results exist.
