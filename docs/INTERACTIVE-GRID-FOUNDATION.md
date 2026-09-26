# Interactive Grid Foundation

Status: **public v1 freeze candidate**.

Core Blueprint Interactive Grid Foundation provides one generic presentation
contract for tabular or grid-like admin interfaces where a cell has one primary
action while still allowing nested business controls above that action.

Base owns cell dividers, stretched-action hover/focus presentation and neutral
current/disabled states. Consumers own dates, times, records, URLs, labels,
authorization and persistence.

## Markup

The minimal interactive-cell contract is:

```html
<table class="widefat cb-core-interactive-grid">
    <tbody>
        <tr>
            <td class="cb-core-interactive-grid__cell">
                <a
                    class="cb-core-interactive-grid__action"
                    href="..."
                    aria-label="Open this cell"
                ></a>

                <div class="cb-core-interactive-grid__content">
                    <span>Cell label</span>
                    <a href="...">Existing item</a>
                </div>
            </td>
        </tr>
    </tbody>
</table>
```

The stretched action must be a real focusable control, normally an anchor for
navigation or a button for local behavior. Do not make a non-semantic wrapper
clickable with JavaScript.

The content layer intentionally ignores pointer events except for real nested
interactive controls. This lets clicks on otherwise empty cell content reach the
primary stretched action while nested links/buttons remain independently usable.

The stretched action and nested controls are siblings. Consumers must never nest
interactive controls inside the stretched action.

## States

`cb-core-interactive-grid__cell--disabled` marks a non-interactive/muted cell.
A disabled cell should not contain the primary `__action`.

`is-current` marks a generic current cell. Consumers decide what "current"
means for their domain.

These states are presentation semantics only. They are not authorization or
validation boundaries.

## Presentation adapters

Standalone WordPress admin screens opt in with:

```php
\CB\Core\UI\Assets::enqueue_interactive_grid();
```

Auto mode uses the Core presentation below the Core Blueprint parent menu and
the WordPress-native adapter elsewhere.

Registered Core Admin pages declare:

```php
[
    'foundations' => [ 'interactive-grid' ],
]
```

Consumers must not import `interactive-grid.css` or
`interactive-grid-native.css` directly.

## Accessibility

Each primary cell action needs an accessible name that describes the resulting
action. A visual label elsewhere in the cell does not replace the accessible
name of an otherwise empty stretched link.

Keyboard focus uses the same full-cell geometry as pointer hover. Nested
business controls remain independently focusable.

No ARIA grid roles are imposed. Consumers should keep native table semantics
when the data is tabular and choose other native structures when it is not.

## Ownership boundary

Interactive Grid Foundation never:

- creates domain URLs;
- opens product-specific editors;
- writes or validates business data;
- intercepts nested controls;
- performs drag and drop;
- imposes calendar semantics.

The consumer owns all business behavior. Foundation owns only the reusable
interactive-cell presentation layer.
