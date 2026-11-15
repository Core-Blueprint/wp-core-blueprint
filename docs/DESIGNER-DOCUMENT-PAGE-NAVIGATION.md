# DP-NAV-01 — Base Designer Document Page Navigation

**Contract:** opt-in, additive Base Designer Foundation capability (API v1).  
**Overseer:** https://github.com/christiaanbruinsma/wp-core-blueprint-overseer/issues/93.  
**Scope:** page-navigation presenter and capability gate only. Authoritative Flow pagination, consumer adoption, FIELD and release are later gates.

## Purpose and authority

The native document icon in the Designer toolbar denotes **page navigation**, not “Fit document to canvas”. Clicking it must never switch between `fit` and `fixed` widths or change the saved document.

Base owns its toolbar control, menu, focus and keyboard behavior. A consumer owns its document, page layout, rendered page identifiers, current page, page scrolling/loading and domain-specific Save/History.

**Do not infer page count from the height of `FlowRenderApi::preview_html()`.** That preview is continuous HTML and explicitly has no authoritative PDF page breaks or page numbers. PDF is the authoritative paged output. Consumers must opt in only when their page projection is genuinely known and corresponds to their rendered paged document.

## Capability and imports

PHP: `CoreBlueprint\Core\Design\Editor\Assets::DESIGNER_PAGE_NAVIGATION_API >= 1` in the **installed matching Base ZIP**. Do not treat source presence in another checkout as proof.

JS named public export: `configureDesignerPageNavigation` from `@cb-core/design-editor`. The public facade also exposes `shell.configurePageNavigation`. The internal `./shell/page-navigation.js` path is Base-private.

```js
import { configureDesignerPageNavigation } from '@cb-core/design-editor';

const navigation = configureDesignerPageNavigation(shellRoot, {
  pages: [
    { id: 'page-1', label: 'Page 1' },
    { id: 'page-2', label: 'Page 2' },
  ],
  activePageId: 'page-1',
  onNavigate({ pageId, pageIndex, totalPages }) {
    // Consumer validates its current page projection, then navigates.
    // No Save/History/document mutations originate in Base.
    return navigateToActualRenderedPage(pageId);
  },
  signal: hostAbortSignal,
});

// After the consumer confirms that the active page has changed:
navigation.setActivePage('page-2');

// On actual page layout, authoritative page IDs or callback changes:
navigation.update({ pages: verifiedPages, activePageId, onNavigate });
```

The example assumes `verifiedPages` originate from an authorized, accurate paged renderer, **not** the existing continuous Flow preview. `navigateToActualRenderedPage` is illustrative, not a Base API.

## Strict descriptor contract

- `pages: null` means **unknown / unavailable**; `pages: []` means known empty. Both are inert.
- Every nonempty page list must have a valid `activePageId` matching one exact page ID. IDs are case-sensitive, 1–64 characters, ASCII alphanumeric followed optionally by alphanumeric / `.`, `_`, `:`, `-` (no whitespace). All IDs must be distinct.
- A page descriptor is `{id: string, label: string}`; labels are plain text, nonempty, maximum 120 characters, no control characters. DOM uses `textContent`, never `innerHTML`. Maximum 500 pages per projection.
- More than one page **and** a trusted `onNavigate` callback are required to enable the button. Exactly one known page is labeled `Page 1 of 1`, but has no dropdown to open. Missing callback disables navigation even if pages are known.
- `onNavigate` receives a frozen `{pageId, pageIndex, totalPages}` intent. Returning `false` refuses the intent. Otherwise the menu closes, **without claiming a different active page**: only the consumer's `setActivePage`/`update` can confirm selection.
- Invalid and duplicate input fails before replacing existing descriptors. Re-rendered menus discard stale item callbacks via a generation guard.
- `signal.abort()` or `destroy()` removes the menu, listeners and ARIA linkage. Never reuse a controller after its Host context has ended; obtain a fresh controller for a new root.

## Interaction and accessibility

- Base exposes one toolbar button with `aria-haspopup="menu"`, `aria-controls`, `aria-expanded`, and a descriptive accessible label. The popover uses a `menu` with `menuitemradio` items and `aria-checked` for the current page.
- Click/Enter/Space and ArrowUp/ArrowDown open the menu where supported. ArrowUp/Down, Home/End navigate options; Escape closes and returns focus to the button; outside pointer/focus or scroll dismisses. The fullscreen Exit Guard cannot interpret a page-menu Escape as “close Designer”.
- The menu is viewport-constrained, scrollable for many pages and uses Foundation CSS tokens. It is rendered **inside the scoped Shell** so the fullscreen focus boundary remains intact. It never resizes the preview or authoring canvas.
- Base does not silently create a page set when the preview reports only HTML, and does not infer current page from a scroll position.

## Compatibility

The existing public `configureDesignerDocumentView` and its `fit`/`fixed` event remain available to legacy/independent view controls. **The native Reports document icon no longer calls that controller.** No public v1 API is removed, and no new schema, persistence, edit command, History, Save, PDF or iframe-message contract is introduced. Native Mail continues using its separate responsive viewport controls.

The shared left-rail `createDesignerNavigation` serves workflow/task stages and is not this page navigation API.

## Required test and release gates

| Gate | Evidence required | Status after DP-NAV-01 source patch |
|---|---|---|
| Source | 0/1/2+/500 page data; duplicate/unsafe/stale IDs; keyboard, Escape/focus, callback veto, teardown; native Reports width unchanged | Targeted regression added; execution required |
| Base Level 1 | `./tools/check` against exact feature SHA | NOT RUN by remote connector |
| Base Level 2 | Disposable WordPress preflight + isolated qualification against same SHA | NOT RUN |
| Build | Fresh `./tools/build-dev` ZIP; `unzip -t`; checksum | NOT BUILT |
| FIELD | Chris tests installed matching ZIP, report toolbar width unchanged and actual verified paged consumer | PENDING; multi-page experience depends on DP-NAV-02/03 |
| Merge/release | Independent source review, owner GO | HOLD |

## Follow-ups (separate authorizations)

**DP-NAV-02 — Authoritative page projection.** Evaluate bounded paged-preview/preview metadata from Base's real PDF/Flow pagination without extracting pages from continuous HTML height. Security, performance, CSS/reflow, rendered-page targets and alignment to final paged PDF must be proven. No fake page counts.

**DP-NAV-03 — Consumer adoption.** Reports, then Contracts, provide verified page metadata and scoped navigation callbacks through the public Base API; never duplicate the toolbar/dropdown.

**DP-NAV-04 — Cross-consumer acceptance.** One/two/many pages, re-render changing count, viewport/reflow, scroll positioning, keyboard, touch, Save/History invariance and PDF parity.
