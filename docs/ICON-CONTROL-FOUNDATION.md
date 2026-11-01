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


## Optional outlined icon control

Add `cb-core-icon-control--outlined` when the control must look clickable
before hover (for example calendar, picker or toolbar buttons). This opt-in
modifier leaves the ordinary transparent icon-control contract unchanged.

```html
<button type="button"
        class="button-link cb-core-icon-control cb-core-icon-control--outlined"
        aria-label="Choose date">
    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
</button>
```

The existing `Assets::enqueue_icon_controls()` helper loads this modifier
in both Core Admin and the WordPress-native presentation. The button stays
30 × 30 pixels, with centered icon, native focus, active and disabled states.

## Native details disclosure

Use the same `Assets::enqueue_icon_controls()` helper for the optional
CSS-only `cb-core-details` disclosure presentation:

```html
<details class="cb-core-details">
    <summary>
        <span class="cb-core-details__chevron" aria-hidden="true"></span>
        Date exceptions
    </summary>
    <div>Exception editor content</div>
</details>
```

A closed details element shows a downward chevron. When the native `open`
attribute is present the same chevron rotates 180°. Motion is removed for
`prefers-reduced-motion`. The chevron is presentational: `aria-hidden="true"`
is required, and no extra button belongs in `summary`. The `summary`
element is the single keyboard and screen-reader-accessible toggle.
This does not change `cb-core-disclosure-toggle`, which remains a separate
button plus `aria-expanded` contract. No disclosure JavaScript is enqueued.
