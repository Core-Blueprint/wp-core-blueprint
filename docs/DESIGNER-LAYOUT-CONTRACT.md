# Core Blueprint Designer Layout Contract

Status: **canonical Base Designer Sidebar Standard v1 (SOURCE contract); installed ZIP/browser acceptance pending**.

This is the authoritative first-party **layout** contract together with `DESIGNER-FOUNDATION-API.md`. Base Mail is the source reference. A source contract does not certify an installed Base ZIP, a consumer ZIP or browser functionality. Where an older paragraph in another document disagrees with this sidebar placement, this contract's canonical layout governs new first-party source integrations; release acceptance still requires exact artifact/runtime proof.

The Designer structure is not product UI. Base owns the shell composition so Mail, Commerce, Reports and future Designer consumers cannot drift into different editor layouts.

## Canonical structure

Base owns this order and presentation:

\`\`\`text
Header
├── Core Blueprint mark
├── active file/template selector, when applicable
├── viewport controls, when supported
├── Undo / Redo, when a session supports history
├── Close
└── primary Save action, only with authorized persistence

Workspace
├── Left rail
│   ├── Navigation, only when supported (task/stage outline)
│   ├── Elements, when supported
│   │   └── Dynamic Data section, only when tokens exist
│   └── Layers, only when an object hierarchy exists
├── Canvas
└── Right rail
    ├── Inspector
    └── Settings, only when supported
\`\`\`

A consumer supplies the domain content inside these Base-owned slots. It must not invent or reposition structural rails, show inactive capability tabs, or present a fake Save action. Base Mail uses a **headerless white editing canvas**; that is Mail-specific, not a mandate to remove a document-oriented Reports canvas header. Domain document/paper rendering remains consumer-owned within Base's public surface primitives.

**Source gate:** \`node tools/validate-designer-layout.mjs templates/mail-designer.php --mail\` validates the Base Mail consumer layout. The same script can validate a separate first-party PHP/HTML consumer source using an explicit path; it cannot replace installed browser/keyboard and ZIP-identity acceptance.
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

The canonical, optional palette roles, in order, are:

- \`navigation\`: task/stage navigation when appropriate. It is **not** the document Layers tree.
- \`elements\`: element catalogue. When bindings exist, **Dynamic Data is a titled section inside Elements**, not a separate tab or panel.
- \`layers\`: object hierarchy, only when real selectable objects exist. Layers is **left of the canvas**, never a right-side Inspector tab.

Tabbed consumers declare \`data-cb-design-shell-group="palette"\` on corresponding tabs and panels, \`data-cb-design-shell-palette-role="elements|layers"\` (or \`navigation\` when supported) on the tabs, and matching \`data-cb-design-shell-tab\` / \`data-cb-design-shell-panel\` IDs. IDs may be domain-specific; semantic roles must not change. Base owns iconography, ordering, keyboard behavior, vertical-only rail scrolling, truncation and selected state.

A workflow-only Designer may use Base's public Navigation component without exposing inactive Elements/Layers tabs. Consumers provide element definitions and optional token values/copy policies, not alternative rail geometry. See [Designer Navigation](DESIGNER-NAVIGATION.md).

## Right rail

The canonical sidebar roles, in order, are:

- \`inspector\`: selected-item fields and validation.
- \`settings\`: optional global settings, only where the domain supports them.

Tabbed consumers map these through \`data-cb-design-shell-sidebar-role="inspector|settings"\`. **Layers and Dynamic Data are not sidebar roles** in this Standard. Consumer-provided panel IDs may differ from the role name; Base still owns ordering, labels, focus and shared composition. A product may omit unsupported optional roles, never show nonfunctional placeholders.

Selecting a Layer in the left rail keeps its Layers tab active and synchronizes the Inspector on the right and the canvas. Canvas selection and insertion may open Inspector as appropriate, without moving Layers to the sidebar.

This canonical source layout supersedes historical first-party markup that placed Layers on the right or exposed Dynamic Data as a separate tab. Migrating an active consumer requires its own reviewed source change and new exact-SHA acceptance; merely changing these documents never proves consumer conformance.
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

Profile-bound Designer consumers with the public selection API bind their domain view adapters through `createDesignerSelectionController()`. Base invokes them in order: Layers, Inspector, canvas. The interaction source determines panel activation; Layers stays active when a Layer is selected. `createDomainSession()` does not expose profile-bound selection/Inspector methods: domain-neutral consumers such as Reports must supply stable domain selection mapping without pretending that this profile-specific controller can attach to their session. They still consume Base's public Layers/Inspector presentation and keep Layers left, Inspector right.

The session public boundary provides selection snapshots, validated `select()`, `clearSelection()` and `subscribeSelection()`. Structural project changes are published only after their canonical selection has been remapped, so project and selection consumers observe one coherent editor state. Project replacement reconciles selection against the new tree by default; a context switch may request `resetSelection: true` when selection must not carry across documents.
