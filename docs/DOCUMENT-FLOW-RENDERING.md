# Core Blueprint Document Flow Rendering — Public v1 Contract

Status: **public v1 Foundation contract**.

This document defines the supported external-consumer boundary for rendering Core Blueprint Flow documents. A PHP method being `public` does not by itself make an internal renderer a supported integration contract.

## Public facade

External consumers use:

```php
CoreBlueprint\Core\Design\Profile\Document\Flow\Api\FlowRenderApi
```

Supported v1 methods:

```text
FlowRenderApi::preview_html( $layout, $blocks, $locale, $presentation = null, $preview_regions = [] )
FlowRenderApi::pdf( $layout, $blocks, $locale, $presentation = null )
FlowRenderApi::is_pdf_available()
```

`preview_html()` and `pdf()` consume the same typed Flow document inputs. They are separate targets with deliberately different presentation semantics.

## Public typed inputs

The public v1 rendering vocabulary is:

- `CoreBlueprint\Core\Design\Profile\Document\Flow\RenderBlock`;
- `CoreBlueprint\Core\Design\Profile\Document\Flow\TableColumn`;
- `CoreBlueprint\Core\Design\Profile\Document\Flow\Presentation`;
- the exact root-owned Flow layout shape below.

The layout shape is:

```php
[
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
]
```

Unknown layout keys, invalid page geometry, invalid locales and non-`RenderBlock` entries fail closed at the Base render boundary.

Consumers provide domain meaning and data. Base owns the bounded rendering grammar, escaping, validated image handling and target-specific document presentation.

## Continuous screen preview

`preview_html()` returns a complete standalone HTML document intended for an isolated Designer/document-canvas preview.

The screen target:

- preserves the Flow document typography, headings, tables, columns, images and bounded `Presentation` accent;
- maps the root Flow margins to screen document padding;
- renders the declared page size as the maximum document width and minimum page-like height;
- keeps page footer content in normal document flow;
- deliberately does **not** simulate PDF pagination or page counters;
- neutralizes paged-media break hints for the continuous screen target;
- contains no executable JavaScript or forms;
- includes a restrictive Content Security Policy that permits only inline styles and validated data-URI images.

The preview is a design representation, not paged-output authority. Consumers must not infer exact PDF page breaks, page counts or fixed-footer placement from `preview_html()`.

The optional `$preview_regions` map is preview-only Designer metadata. Each semantic region maps to one or more Flow render paths. Existing integer entries remain top-level shorthand, while nested paths are ordered integer lists. A container consumes one child index; a columns block consumes a column index followed by a child index. For example:

```php
[
    'title' => [ 0 ],
    'nested-paragraph' => [ [ 1, 0 ] ],
    'right-column-heading' => [ [ 2, 1, 0 ] ],
]
```

Every referenced path must resolve to an existing typed `RenderBlock`, and one render path may belong to only one semantic region. Invalid or duplicate paths fail closed. When a region is selected, the isolated preview may apply editor-only outline and minimum-height affordances so even empty blocks remain visible and selectable; those styles are removed when selection clears. Region metadata and editor affordances are never emitted by paged HTML/PDF rendering.

For browser embedding, consumers should place the returned complete document in an isolated `iframe` using `srcdoc` and a bare `sandbox` attribute. Consumers should not add `allow-scripts`, `allow-same-origin`, remote-resource permissions or other capabilities that the Flow preview does not require.

## DP-NAV-02A/B — Scroll ownership and authoritative page counts (source candidate)

The shared Flow Preview Host owns the sandboxed HTML frame and measures its
entire continuous document. The **surrounding Designer workarea is the only
vertical scrolling surface**: inner iframe scrolling is disabled and the parent
frame receives enough height for its complete inner content, including trusted
border-box decorations. The existing region/selection, iframe generation,
strict CSP, security checks, undo/redo, save and drag/drop contracts remain
unchanged. This single-scroll change applies to any consumer using the public
`createFlowPreviewHost` boundary, not only Maintenance Reports.

`FlowRenderApi::pdf_with_page_count($layout, $blocks, $locale, $presentation)`
is an additive opt-in method returning `['pdf' => <binary>, 'page_count' => <int>]`.
The matching installed Base capability is
`CoreBlueprint\\Core\\Design\\Editor\\Assets::FLOW_PAGED_PAGE_COUNT_API >= 1`.
It uses the **same hardened Dompdf render pass** for both outputs; the count
is read directly from the actual Dompdf Canvas after rendering. The bound is
1–500 pages, matching the public Designer Page Navigation component. A larger
output is refused by this *optional metadata method only*; the existing
`FlowRenderApi::pdf()` remains compatible and does not acquire this UI bound.
The existing `PdfApi::render()` and all PDF security settings remain unchanged.

**Important limitation:** A correct PDF page count does not provide page
boundaries, editable HTML page fragments or rendered navigation targets.
The currently installed continuous `preview_html()` does **not** become
multiple A4 pages merely because the PDF count is available. Feeding the count
to the existing Page Navigation UI without an authoritative page surface would
mislead users and is not permitted. A genuine paged visual renderer with
interactivity mapping is a separate DP-NAV-02C gate; Reports/Contracts opt-in
then follows under DP-NAV-03. Never split by CSS height heuristics.

The newly added source tests and this documentation are not a runtime or
WordPress FIELD acceptance receipt. Merge, deployment and public release
remain independently authorized.

## Authoritative PDF output

`pdf()` returns the paged PDF binary for the same typed Flow document. PDF pagination, page counters and fixed per-page footer behavior are authoritative only in this target.

`is_pdf_available()` reports availability of the bundled PDF backend. Continuous screen preview rendering does not depend on that backend.

The Dompdf implementation, security options, paper-point conversion and backend lifecycle remain Base internals. Consumers must not instantiate or configure Dompdf directly.

## Internal renderers

The following classes are implementation details and are **not** public consumer APIs even when a method is technically `public`:

```text
CoreBlueprint\Core\Design\Profile\Document\Flow\HtmlRenderer
CoreBlueprint\Core\Design\Profile\Document\Flow\PdfRenderer
CoreBlueprint\Core\PDF\Renderer
```

External consumers must use `FlowRenderApi`. Base may refactor the internal renderers while preserving this documented facade and typed-input behavior.

## Security boundary

Flow rendering accepts typed blocks rather than arbitrary consumer HTML/CSS. Text/table content is escaped, image blocks require validated local data URIs, and presentation options remain bounded by the typed `Presentation`/`TableColumn` contracts.

The screen preview CSP is defense in depth, not a replacement for the typed-input contract. Consumers remain responsible for capability/nonce checks on any HTTP endpoint that accepts preview state or returns a rendered preview.

## Designer ownership

Designer Mode owns the generic canvas/shell presentation. A product-specific consumer owns the domain document data and decides when to request a Flow preview. The consumer must not rebuild a separate document rendering grammar or page-layout engine when the Flow profile already models the required document.

For financial, contractual or other regulated output, mutable design state must remain separate from immutable domain truth. The Flow render contract does not grant permission to make domain values editable.

## Compatibility

This facade and its typed-input meaning are additive public v1 contracts. Base may evolve internal HTML/CSS implementation details during 1.x provided that:

- the same valid typed inputs remain accepted;
- unsafe/invalid inputs continue to fail closed;
- `preview_html()` remains a safe continuous screen target;
- `pdf()` remains authoritative paged output;
- consumers are not required to depend on internal render classes or the PDF backend for screen previews.
