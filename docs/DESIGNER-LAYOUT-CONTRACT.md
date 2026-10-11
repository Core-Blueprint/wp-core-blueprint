# Core Blueprint Designer Layout Contract

Status: **Base-owned public Designer structure**.

The Designer structure is not product UI. Base owns the shell composition so Mail, Commerce, Reports and future Designer consumers cannot drift into different editor layouts.

## Canonical structure

Base owns this order and presentation:

```text
Header
├── Core Blueprint mark
├── active file/template selector
├── viewport controls when supported
├── Undo / Redo
├── Close
└── primary Save action

Workspace
├── Left rail
│   ├── Navigation, when supported (task/stage outline)
│   ├── Elements, when supported
│   └── Dynamic data, when supported
├── Canvas
└── Right rail
    ├── Inspector
    ├── Layers
    └── Settings
```

A consumer supplies the domain content that appears in those slots. It does not replace, rename or reposition the structural rails.

## Header context selector

When a Designer has an active file, template, document type or equivalent target, the consumer declares that control with:

```html
data-cb-design-shell-context
```

Base places that control directly to the right of the Core Blueprint mark and owns its sizing and vertical alignment.

The selector remains visible even when there is only one available target. A single option is still useful structural context and keeps every Core Blueprint Designer visually consistent.

Consumers must not position or align this selector with product CSS or product JavaScript.

## Context switching without page reload

Changing the active file/template/document is a Designer session transition, not a page navigation. Base owns the switch lifecycle so every consumer keeps the same Designer shell open while domain data changes behind it.

For a `<select>` inside `data-cb-design-shell-context`, Base intercepts the change and dispatches:

```text
cb:design-shell:contextrequest
```

The event detail exposes the requested `value`, the `previousValue`, the `control`, and a `respondWith(promise)` callback. A consumer handles only its domain loading and applies its new project/context, then resolves that Promise. It must not navigate the browser, close Designer Mode, build its own loader overlay or replace the Base shell.

Example consumer pattern:

```js
root.addEventListener('cb:design-shell:contextrequest', (event) => {
    if (!event.detail?.respondWith) return;
    event.detail.respondWith(loadDomainContext(event.detail.value));
});
```

During the Promise Base owns the canvas/main-area transition:

- the current canvas remains mounted;
- the work area is marked busy;
- Base shows the canonical loading transition;
- the context selector is temporarily disabled;
- success emits `cb:design-shell:contextchanged` and fades the transition away;
- failure restores the previous selector value, keeps the existing design intact and shows the canonical error transition.

The consumer remains responsible for fetching/validating the requested domain context and replacing its editor project/state safely. A context switch should clear history that belongs to the previous document/template; it must not make edits from one target undoable inside another target.

Normal URL state may be updated with the History API after a successful switch, but a full page reload is not part of the Designer context contract.

## Left rail

The canonical, optional left roles are:

- `navigation` — task/stage navigation, when needed (new additive v1 capability)
- `elements` — element catalogue, when needed
- `dynamic-data` — data tokens, when supported

**Existing consumers remain unchanged:** their Elements/Dynamic Data order stays the same and Navigation is not injected unless requested. A workflow-only Designer may use the canonical Base-owned Navigation component directly inside the palette without adding inactive Elements tabs. Mixed-role Designers declare matching canonical palette panels/tabs and Base owns their order, labels and keyboard behavior.

Consumers provide stage items, element catalogs and data tokens. Base owns navigation markup, active-state representation, scoped scrolling/focus and palette presentation. A consumer may set its localized Navigation panel label through `data-cb-design-shell-palette-label`; this does not alter other canonical labels.

See [Designer Navigation](DESIGNER-NAVIGATION.md) for the exact public API, item contract and safe single-/multi-role composition. Navigation represents tasks/sections, **not** the object hierarchy in Layers. Consumers must not replace Base's palette geometry or invent a product-specific left rail.

## Right rail

The canonical right roles are:

- `inspector`
- `layers`
- `settings`

Base already owns their canonical order, labels and Designer-shell presentation through the shared sidebar contract. Consumers own only the content inside the roles.

A product may omit a role only when that capability does not exist. Product-specific alternatives such as `Document design`, `Email` or `Structure` are domain labels/content, not replacement shell roles.

## Ownership boundary

Base owns:

- header composition and active-context placement;
- context-switch interception, busy state, transition and success/error lifecycle;
- toolbar control geometry;
- left/canvas/right workspace order;
- left palette role order and shared labels;
- right sidebar role order and shared labels;
- desktop and responsive rail behavior;
- shared rail/tab/panel presentation;
- Layers tree traversal, parent/child presentation, transient collapse state, row DOM, action order, iconography, selected-state presentation, keyboard navigation and action affordances.

Consumers own:

- loading and validating the requested domain context behind `respondWith()`;
- replacing their domain project/editor state after a successful context fetch;
- element definitions;
- dynamic-data definitions;
- Inspector fields and validation;
- Layers semantic descriptors and bounded reorder/remove policy, supplied through the Base-owned Layers tree;
- Settings fields;
- canvas/render semantics;
- persistence and permissions.

This contract deliberately separates a stable Designer structure and session experience from flexible product composition.
## Canonical Layers interaction

The Layers view must visually mirror the actual document hierarchy. Consumers describe semantic nodes; Base owns how that hierarchy is rendered and operated.

Consumers use the public Designer Layers tree helper and provide only stable keys/paths, labels, optional useful summaries, selected state, child descriptors and bounded callbacks. The tree-level selection adapter owns Layers selection intent: clicking a Layer selects that path with source `layers` while keeping the Layers panel active. A grouping node with children is rendered as a parent with nested descendants. Consumers must not recursively build their own Layer rows or simulate hierarchy with local margins.

Base owns:

- recursive tree traversal and canonical row composition;
- parent/child indentation and hierarchy guides;
- expand/collapse disclosure for nodes with children;
- transient collapse state that never mutates or serializes the project;
- suppression of redundant secondary metadata when it only repeats the primary label;
- compact Layers density and selected/hover/focus presentation;
- ARIA tree/treeitem/group metadata and keyboard traversal;
- sibling move controls and same-parent drag/reorder when the consumer supplies a bounded reorder policy;
- standard action order, iconography and accessible labels.

Keyboard navigation follows tree conventions: Up/Down move through visible Layers, Right opens a collapsed parent or enters its first child, Left closes an expanded parent or returns to its parent, and Home/End move to the first/last visible Layer.

Reorder remains bounded by the consumer's domain policy. Base never decides whether a domain node may be removed, moved across a structural boundary or persisted. Cross-parent drag/drop is not part of the v1 Layers contract.

Standard row actions remain ordered as move up, move down, remove. Move/remove controls are icon-only with accessible labels. The action overlay is visible on pointer hover, keyboard focus within the row and whenever the row is selected; otherwise it remains visually hidden without changing row geometry.


## Canonical selection lifecycle

Selection is Base-owned session state. Every real selection mutation publishes one canonical change event with a revision, source and action. Structural operations may also publish when the final path is unchanged but now identifies a different semantic node. Command-driven remaps are batched so insert, remove and reorder expose only their final coherent selection state; undo and redo restore selection through the same lifecycle.

Consumers must not manually coordinate separate Layers, Inspector and canvas selection paths. They bind domain render adapters through the public Designer selection controller. Base invokes those adapters in deterministic order: Layers, Inspector, canvas. Selection determines which element is active; the interaction source determines panel activation. A Layers click keeps Layers open while synchronizing Inspector data and canvas selection. Canvas selection and insertion may activate Inspector when the interaction intent is editing.

The session public boundary provides selection snapshots, validated `select()`, `clearSelection()` and `subscribeSelection()`. Structural project changes are published only after their canonical selection has been remapped, so project and selection consumers observe one coherent editor state. Project replacement reconciles selection against the new tree by default; a context switch may request `resetSelection: true` when selection must not carry across documents.
