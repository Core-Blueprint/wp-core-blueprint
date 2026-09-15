# Core Blueprint Document Flow Rendering — Public v1 Contract

Status: **public v1 Foundation contract**.

This document defines the supported external-consumer boundary for rendering Core Blueprint Flow documents. A PHP method being `public` does not by itself make an internal renderer a supported integration contract.

## Public facade

External consumers use:

```php
CB\Core\Design\Profile\Document\Flow\Api\FlowRenderApi
```

Supported v1 methods:

```text
FlowRenderApi::preview_html( $layout, $blocks, $locale, $presentation = null )
FlowRenderApi::pdf( $layout, $blocks, $locale, $presentation = null )
FlowRenderApi::is_pdf_available()
```

`preview_html()` and `pdf()` consume the same typed Flow document inputs. They are separate targets with deliberately different presentation semantics.

## Public typed inputs

The public v1 rendering vocabulary is:

- `CB\Core\Design\Profile\Document\Flow\RenderBlock`;
- `CB\Core\Design\Profile\Document\Flow\TableColumn`;
- `CB\Core\Design\Profile\Document\Flow\Presentation`;
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

For browser embedding, consumers should place the returned complete document in an isolated `iframe` using `srcdoc` and a bare `sandbox` attribute. Consumers should not add `allow-scripts`, `allow-same-origin`, remote-resource permissions or other capabilities that the Flow preview does not require.

## Authoritative PDF output

`pdf()` returns the paged PDF binary for the same typed Flow document. PDF pagination, page counters and fixed per-page footer behavior are authoritative only in this target.

`is_pdf_available()` reports availability of the bundled PDF backend. Continuous screen preview rendering does not depend on that backend.

The Dompdf implementation, security options, paper-point conversion and backend lifecycle remain Base internals. Consumers must not instantiate or configure Dompdf directly.

## Internal renderers

The following classes are implementation details and are **not** public consumer APIs even when a method is technically `public`:

```text
CB\Core\Design\Profile\Document\Flow\HtmlRenderer
CB\Core\Design\Profile\Document\Flow\PdfRenderer
CB\Core\PDF\Renderer
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
