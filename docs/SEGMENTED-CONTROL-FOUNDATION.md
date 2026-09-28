# Segmented Control Foundation

Status: **public v1 freeze candidate**.

Segmented Control is a domain-neutral presentation primitive for a small
single-choice or view-mode selector. Base owns group geometry and
active/hover/focus/disabled presentation. Consumers own labels, values,
selection state, keyboard behavior and persistence.

The active segment is deliberately less prominent than a primary CTA.

## Public enqueue

```php
\CB\Core\UI\Assets::enqueue_segmented_control();
```

Auto mode uses Core presentation below the Core Blueprint parent menu and the
WordPress-native adapter on standalone admin screens.

## Markup

A radio-style selector may use:

```html
<div class="cb-core-segmented-control" role="radiogroup" aria-label="View">
    <button
        type="button"
        class="cb-core-segmented-control__option is-active"
        role="radio"
        aria-checked="true"
    >Day</button>
    <button
        type="button"
        class="cb-core-segmented-control__option"
        role="radio"
        aria-checked="false"
    >Week</button>
    <button
        type="button"
        class="cb-core-segmented-control__option"
        role="radio"
        aria-checked="false"
    >Month</button>
</div>
```

For button-state semantics consumers may instead use `aria-pressed`. Base
does not ship JavaScript for this primitive and does not infer domain values.

Consumers must provide the keyboard behavior appropriate to the semantics they
choose. A native radio implementation is also valid when it fits the screen.
