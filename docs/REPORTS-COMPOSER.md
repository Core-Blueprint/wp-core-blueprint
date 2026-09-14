# Reports Composer v1

Status: **internal v1 contract for the Base Reports Designer consumer**.

> **Reports Composer is a bounded semantic report builder, not a free-form canvas.**

Base owns Designer Mode chrome, rails, toolbar, responsive behavior, panels, form controls and document surface. Reports owns report blocks, their data bindings, block settings, ordering rules, preview data and persistence.

## Product goal

Reports Composer lets an operator assemble a modern report from governed semantic blocks while keeping the result deterministic for both browser preview and PDF rendering.

The Composer must support useful customization without becoming a page builder or Figma-style editor. Users may reorder supported blocks, enable or disable optional blocks and change bounded settings exposed by each block. Users may not position content with arbitrary coordinates, inject CSS/HTML, create unbounded nested containers or bypass the typed Document Flow renderer.

## Persistence

Composer state is render-time presentation state and remains separate from immutable report snapshots.

Canonical Base settings path:

```text
reports.composer.maintenance
```

A Maintenance Report snapshot remains the immutable source of report data. Branding and Composer configuration are resolved at render time. Existing snapshots can therefore be rendered with the current approved report presentation without copying layout state into every snapshot.

The template document is versioned independently from the snapshot schema:

```php
[
    'schema_version' => 1,
    'blocks' => [
        [
            'id'       => 'header',
            'type'     => 'header',
            'enabled'  => true,
            'settings' => [],
        ],
        // ...
    ],
]
```

## v1 block catalog

The first Composer contract deliberately maps to the existing Maintenance semantics before introducing richer visual variants.

| Type | Data source | v1 rule |
| --- | --- | --- |
| `header` | site, report metadata, branding | required, first |
| `status` | snapshot status | optional, reorderable |
| `kpis` | snapshot KPIs | optional, reorderable |
| `current_state` | snapshot site state | optional, reorderable |
| `activity` | maintenance action sections | optional, reorderable |
| `summary` | existing security/backups summary compiler output | optional, reorderable; split into richer blocks later |
| `notes` | snapshot notes/observations | optional, reorderable |
| `footer` | site/report metadata | required, last |

All v1 blocks are singleton semantic blocks. Optional blocks are hidden by setting `enabled=false`; deleting the machine definition is not part of v1. This keeps persisted state bounded and allows a damaged or partial document to be healed deterministically.

Future versions may add repeatable editorial blocks such as a section heading or bounded text block, but those require an explicit schema change and instance-ID rules.

## Ordering and structural rules

- `header` is always enabled and normalized to the first position.
- `footer` is always enabled and normalized to the last position.
- optional blocks may be reordered between them.
- duplicate singleton block types are ignored after the first valid occurrence.
- unknown block types are ignored during read normalization and are rejected by the UI before persistence.
- missing canonical block definitions are healed from defaults.
- the block document stays bounded; v1 never accepts arbitrary nested block trees.

## Designer UX contract

Designer Mode keeps the Golden three-region ownership model:

- **Left palette / structure:** report block library and current block order.
- **Canvas:** canonical document surface rendered through the real typed Flow pipeline.
- **Right inspector:** settings for the selected Reports block plus report appearance/provider settings.

The consumer may implement block selection, reorder and domain commands. It must not reimplement shell geometry, toolbar overflow, drawer behavior, panel spacing or control presentation.

A later Composer interaction slice may use drag-and-drop when it can be implemented as domain behavior without creating product-owned Designer chrome. Keyboard-accessible move-up/move-down commands remain required even if pointer drag-and-drop is added.

## Data binding

Blocks bind to predefined Reports datasets. v1 does not expose an arbitrary expression language.

Examples:

```text
header         -> report metadata + site + branding
status         -> maintenance.status
kpis           -> maintenance.kpis
current_state  -> maintenance.site_state
activity       -> maintenance.sections
summary        -> maintenance.security + maintenance.backups
notes          -> maintenance.notes
footer         -> report metadata + site
```

The snapshot is data truth. A block may decide not to emit visual Flow blocks when its source data is unavailable, but it may not manufacture persisted report facts.

## Preview data

Composer preview supports two domain-owned sources:

1. a representative sample snapshot for designing all supported blocks;
2. the latest real immutable Maintenance snapshot for reality checking.

The sample may demonstrate supported shapes but must remain clearly preview-only and must pass through the same `MaintenanceFlowCompiler -> Document Flow` path as real data. It must never become persisted report history.

## Compilation

`MaintenanceFlowCompiler` remains the only Maintenance-to-Document-Flow compiler.

Composer configuration controls which semantic compiler sections execute and in which order. It does not create a second HTML template engine. Browser preview and PDF rendering must receive the same normalized template and produce typed `RenderBlock` output through the same compiler boundary.

When a visual requirement cannot be represented by public Document Flow primitives, that is first evaluated as a Base Foundation gap. Reports must not solve it by embedding arbitrary CSS or HTML in a block.

## Save/reset authority

The Reports Designer has one domain save authority. Branding and Composer template changes are normalized before one `reports` settings document is committed. Preview accepts unsaved branding/template state without persisting it.

Reset restores both branding and the canonical Maintenance Composer template. Audit events must describe the Reports Designer mutation rather than silently creating independent layout and branding histories.

## Non-goals for v1

Reports Composer v1 does not provide:

- absolute positioning;
- arbitrary width/height/coordinates;
- arbitrary HTML or CSS;
- nested free-form containers;
- custom JavaScript;
- a general query/expression builder;
- per-snapshot layout copies;
- a second renderer separate from Document Flow;
- consumer-owned Designer shell or responsive presentation.

## Planned delivery slices

1. **Domain contract** — block catalog, versioned template normalizer/defaults and persistence shape.
2. **Compiler ordering** — current Maintenance output routed through normalized block order with no content loss.
3. **Composer interaction** — structure list, selection, enable/disable, reorder, atomic save/reset and live preview.
4. **Modern block presentation** — add or improve generic Document Flow primitives where needed, then split the aggregate `summary` into richer Security and Backups blocks.
5. **Presets/sample data** — curated templates such as Modern, Executive and Detailed built from the same block schema.
