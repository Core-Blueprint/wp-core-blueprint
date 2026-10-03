# Icon Control Foundation

Status: **public v1 freeze candidate**.

Icon Control is the canonical compact square control presentation for organizer
rows, sortable lists and compact disclosures. Base owns geometry, icon sizing,
hover, focus, open/active and disabled presentation. Consumers own placement,
accessible labels, domain meaning and behavior.

## Public enqueue

```php
\CoreBlueprint\Core\UI\Assets::enqueue_icon_controls();
```

Auto mode uses Core presentation below the Core Blueprint parent menu and the
WordPress-native adapter on standalone admin screens.

## Canonical control

```html
<button
    type="button"
    class="button-link cb-core-icon-control"
    aria-label="Open options"
>
    <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
</button>
```

Lucide output from `CoreBlueprint\Core\UI\Icon::render()` is also supported.

## Reorder handle

```html
<button
    type="button"
    class="button-link cb-core-icon-control cb-core-reorder-handle"
    data-cb-core-reorder-handle
    aria-label="Reorder item"
>
    <span class="dashicons dashicons-move" aria-hidden="true"></span>
</button>
```

`cb-core-reorder-handle` selects the canonical 20px move-icon geometry.
Reorder Foundation still owns pointer/keyboard movement, grab/grabbing cursor,
pending state and drag state.

## Compact disclosure toggle

```html
<button
    type="button"
    class="button-link cb-core-icon-control cb-core-disclosure-toggle"
    aria-expanded="false"
    aria-controls="example-panel"
    aria-label="Toggle details"
>
    <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
</button>
```

The disclosure modifier rotates a right-facing chevron when
`aria-expanded="true"`. The consumer owns updating `aria-expanded`, the
controlled region and disclosure behavior.

This primitive does not replace the full-width `cb-core-disclosure` component
used by settings panels.
