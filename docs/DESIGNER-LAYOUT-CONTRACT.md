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
│   ├── Elements
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

Consumers must not position or align this selector with product CSS.

## Left rail

The canonical left roles are:

- `elements`
- `dynamic-data`

Consumers provide their own element catalog and data tokens. Mail elements, financial-document elements, certificate elements and other domain elements are intentionally different; the rail structure is not.

Declare semantic panels with `data-cb-design-shell-panel` and, where useful, the explicit `data-cb-design-shell-palette-role` marker. Base owns canonical role order and labels.

A consumer may omit `dynamic-data` when the domain truly has no dynamic-data capability. It must not replace the canonical rail with a product-specific Structure panel.

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
- toolbar control geometry;
- left/canvas/right workspace order;
- left palette role order and shared labels;
- right sidebar role order and shared labels;
- desktop and responsive rail behavior;
- shared rail/tab/panel presentation.

Consumers own:

- element definitions;
- dynamic-data definitions;
- Inspector fields and validation;
- Layers data and bounded reorder policy;
- Settings fields;
- canvas/render semantics;
- persistence and permissions.

This contract deliberately separates a stable Designer structure from flexible product composition.
