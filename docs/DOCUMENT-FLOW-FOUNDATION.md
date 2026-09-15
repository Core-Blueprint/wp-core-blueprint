# Core Blueprint Document Flow Foundation

Status: **public v1 candidate — not frozen yet**.

The Document Flow Foundation is the Base-owned server-side composition and rendering contract for flowing, page-oriented documents such as reports, invoices and other structured printable output.

> **Base owns document presentation and rendering. Consumers own document semantics and data.**

The contract is intentionally bounded. A consumer describes content through typed `RenderBlock` objects, bounded layout metadata and bounded presentation tokens. A consumer does **not** provide HTML, CSS classes, arbitrary style declarations or a second rendering engine.

The v1 candidate becomes frozen only after the Designer Golden Standard field proof has been accepted across its reference/consumer set. Until then, changes to this document must remain backward-conscious and evidence-driven.

## Namespace

Public Flow classes live under:

```php
CB\Core\Design\Profile\Document\Flow
```

The principal server-side types are:

- `RenderBlock` — typed document content/composition;
- `Layout` — validation for the root Flow page and margin contract;
- `Hints` — bounded spacing/page-break hints consumed by `RenderBlock` factories;
- `Presentation` — bounded renderer-owned presentation tokens;
- `TableColumn` — bounded table-column alignment/weight/wrapping metadata;
- `HtmlRenderer` — canonical HTML renderer used by browser/document previews;
- `PdfRenderer` — canonical PDF renderer for the same typed document contract.

Consumers should normally construct `RenderBlock` instances and pass them, together with the canonical layout, locale and optional `Presentation`, to the appropriate Base renderer.

## Canonical layout

Flow accepts one explicit root layout shape:

```php
$layout = [
    'mode'    => 'flow',
    'units'   => 'mm',
    'page'    => [
        'width'  => 210.0,
        'height' => 297.0,
    ],
    'margins' => [
        'top'    => 12.0,
        'right'  => 12.0,
        'bottom' => 15.0,
        'left'   => 12.0,
    ],
];
```

`Layout` validates exact keys, finite bounded millimetre values and a positive content area. Extra root/page/margin keys are not an extension mechanism.

## Render blocks

### Text

```php
RenderBlock::text( 'Plain text' );
```

Text is escaped by the renderer. New lines may be represented by the renderer, but arbitrary HTML is not accepted.

### Headings

```php
RenderBlock::heading( 'Maintenance Report', 'title' );
RenderBlock::heading( 'Current State', 'section' );
RenderBlock::heading( 'Plugin updates', 'subsection' );
```

Supported roles are exactly:

- `title`
- `section`
- `subsection`

The role selects Base-owned semantic markup and presentation. It is not a CSS-class escape hatch.

### Semantic callouts

```php
RenderBlock::callout(
    'Site status',
    'Everything is current.',
    'success'
);
```

Supported tones are exactly:

- `neutral`
- `info`
- `success`
- `warning`
- `critical`

Tone is semantic input. Base decides the actual border, background, typography and print/PDF presentation.

Consumers must not translate domain-specific status names into CSS values. They map domain status to one of the supported semantic tones.

### Metrics

```php
RenderBlock::metrics( [
    [ 'label' => 'Updates Performed', 'value' => '12', 'detail' => 'Plugins: 10; Themes: 2' ],
    [ 'label' => 'Updates Pending', 'value' => '0' ],
] );
```

Metrics accept an ordered list of **1–8** items. Every item requires a non-empty string `label` and `value`; `detail` is optional. Unknown item keys fail closed.

Grid geometry, wrapping and card presentation are Base-owned. Consumers must not pass column counts, widths, colours or card CSS.

### Rule

```php
RenderBlock::rule();
```

A rule is a semantic section divider. It accepts only the standard Flow hints; consumers do not select stroke width/style/colour.

### Images

```php
RenderBlock::image( $validated_data_uri );
```

Flow images use the canonical `ImageDataUri` validation boundary. Browser URLs, filesystem paths and arbitrary remote sources are not accepted as document-image payloads.

A consumer that starts from an attachment or product-specific branding source must resolve it to the validated document-image contract before constructing the block.

### Containers

```php
RenderBlock::container( [
    RenderBlock::heading( 'Notes', 'section' ),
    RenderBlock::text( 'Text' ),
] );
```

Containers accept an ordered list of typed `RenderBlock` children only. They are grouping semantics, not free-form layout/style containers.

### Columns

```php
RenderBlock::columns(
    [
        [ RenderBlock::text( 'Left' ) ],
        [ RenderBlock::text( 'Right' ) ],
    ],
    [ 2.0, 1.0 ]
);
```

Columns support **2–4** ordered columns. Widths are positive bounded relative weights. Consumers cannot supply CSS units, breakpoints, absolute coordinates or renderer markup.

### Tables

```php
RenderBlock::table(
    [ 'Component', 'Status' ],
    [ [ 'WordPress', 'Current' ] ]
);
```

Tables use bounded string headers/cells and a bounded row/column count. Optional `TableColumn` metadata may define only alignment, positive relative weight and `nowrap` behavior.

Example:

```php
RenderBlock::table(
    [ 'Item', 'Total' ],
    [ [ 'Service', '€ 100.00' ] ],
    [],
    [
        TableColumn::left( 3.0 ),
        TableColumn::right( 1.0, true ),
    ]
);
```

`TableColumn::left()`, `center()` and `right()` are the supported alignment constructors. They do not expose arbitrary styles.

### Page footer

```php
RenderBlock::page_footer( 'Report generated for example.test', 'Page' );
```

The page footer is renderer-owned fixed page furniture. Consumers supply escaped text and may opt out of the page number. They do not control footer geometry or CSS.

## Flow hints

Most content/composition block factories accept an optional hints array:

```php
[
    'space_before'  => 4.0,
    'space_after'   => 2.0,
    'break_before'  => false,
    'break_after'   => false,
    'keep_together' => true,
]
```

Supported keys are limited to:

- `space_before` — non-negative bounded millimetres;
- `space_after` — non-negative bounded millimetres;
- `break_before` — boolean;
- `break_after` — boolean;
- `keep_together` — boolean.

Unknown keys, non-finite values and invalid types fail closed. Hints are pagination/composition intent; they are not generic style metadata.

## Presentation

The v1 candidate presentation surface is deliberately narrow:

```php
$presentation = Presentation::from_accent( '#3455db' );
```

The accent must normalize to a valid hexadecimal colour. `Presentation` is **not** a theme/style object and does not accept font families, CSS declarations, selectors or arbitrary tokens.

Base remains free to evolve internal HTML/CSS/PDF implementation while preserving the public semantic contract.

## Rendering parity

The same typed document should drive preview and final output:

```php
$html = ( new HtmlRenderer() )->render(
    $layout,
    $blocks,
    $locale,
    $presentation
);

$pdf = ( new PdfRenderer() )->render(
    $layout,
    $blocks,
    $locale,
    $presentation
);
```

Consumers must not maintain a separate preview-only document renderer or product-specific PDF template for the same Flow document. Synthetic preview **data** is permitted, but it must pass through the same domain compiler and Flow renderer as real data.

Reports is the first-party proof of this rule: its Designer preview and saved Maintenance PDF share `MaintenanceFlowCompiler`; only the final Base renderer differs (`HtmlRenderer` versus `PdfRenderer`).

## Ownership boundary

Base owns:

- block validation and bounded payload contracts;
- HTML structure and escaping;
- all Flow CSS/presentation;
- PDF rendering behavior;
- semantic callout visual treatment;
- metrics grid/card geometry;
- heading hierarchy presentation;
- column/table geometry derived from bounded metadata;
- page footer positioning;
- pagination interpretation of Flow hints;
- document-image validation boundary.

Consumers own:

- domain data collection;
- domain status/state semantics;
- mapping domain semantics to typed Flow blocks;
- labels and translated copy;
- deciding block order/content based on their bounded domain model;
- persistence and permissions outside the renderer;
- supplying a valid locale and, where appropriate, bounded accent presentation.

## Consumer restrictions

A Flow consumer must not:

- pass arbitrary HTML to emulate a missing block;
- pass CSS, class names, selectors or style declarations through payloads;
- copy `HtmlRenderer` CSS into the consumer;
- depend on `cb-flow-*` generated class names as a consumer API;
- parse generated HTML to implement domain behavior;
- maintain a second product-owned PDF/HTML presentation path for the same Flow document;
- use browser URLs or arbitrary filesystem paths as image payloads;
- add product-specific rendering options when the underlying need is generic;
- widen a bounded Base payload solely for one consumer.

If a first-party consumer cannot express a legitimate reusable document need through this contract, treat that as a **Base Foundation gap**. Add the smallest consumer-neutral typed primitive in Base, prove it independently, then let the consumer adopt it.

## Golden v1 freeze gate

This contract is ready to freeze only when all of the following are true:

1. the existing Mail/reference Designer remains conformant;
2. Contracts proves the external-consumer Designer composition boundary;
3. Reports proves the same Base-owned Designer shell plus this typed Document Flow presentation path in staging;
4. no consumer requires product-owned shell/renderer/presentation workarounds;
5. executable Base regressions remain green;
6. the final field review is accepted and the Golden freeze receives explicit release approval.

Until that gate is closed, this file describes the **candidate public contract**, not permission to introduce parallel local implementations.
