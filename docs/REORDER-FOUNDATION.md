# Reorder Foundation

Status: **public v1 freeze candidate**.

Core Blueprint Reorder Foundation provides one generic interaction contract for
changing the canonical order of consumer-owned items. It supports ordered lists
within one workspace and optional movement between related lists.

Base owns interaction, focus, accessible announcements, pending state and
rollback presentation. Consumers own domain meaning, authorization, validation
and persistence.

## Public runtime

Script-module dependency:

```text
@cb-core/reorder
```

Runtime:

```js
window.cbCore.reorder.enhance(root, options)
window.cbCore.reorder.isEnhanced(root)
```

`enhance()` returns a controller with:

```js
controller.snapshot()
controller.move(itemId, targetListId, targetIndex)
controller.moveUp(itemId)
controller.moveDown(itemId)
controller.refresh()
controller.destroy()
```

All pointer, keyboard and programmatic moves use the same mutation pipeline.

## Markup

Consumers own item markup. The minimal contract is:

```html
<div data-cb-core-reorder>
    <div
        data-cb-core-reorder-list="primary"
        data-cb-core-reorder-list-label="Primary"
    >
        <article
            data-cb-core-reorder-item="item:1"
            data-cb-core-reorder-label="Example item"
        >
            <button
                type="button"
                data-cb-core-reorder-handle
                aria-label="Reorder Example item"
            >
                Move
            </button>
        </article>
    </div>
</div>
```

The root, list and item identifiers are opaque. Base does not infer WordPress
object types, providers or numeric meaning from them. Identifiers must be
non-empty, unique within one root and at most 191 UTF-8 bytes.

The handle must be an actual interactive control. A non-focusable decorative
element must not be the only reorder mechanism.

## Options

```js
const controller = window.cbCore.reorder.enhance(root, {
    crossList: true,

    canMove(move) {
        return true;
    },

    async onMove(move) {
        await persist(move);
    },
});
```

### `crossList`

Defaults to `false`. Movement between lists is accepted only when the consumer
explicitly enables it.

### `canMove(move)`

Optional synchronous consumer policy. Return `true` to allow the requested move.
Any other return value rejects the move before DOM mutation.

This is a user-interface policy only. It is never an authorization boundary.

### `onMove(move)`

Optional persistence callback. It runs after the optimistic DOM move.

The move is committed when the callback resolves without returning `false`.
Returning `false`, throwing or rejecting causes Foundation-owned rollback to
the exact previous list/order snapshot.

Consumers remain responsible for server-side authorization and canonical
persistence.

## Move projection

Consumers receive a domain-neutral projection:

```js
{
    itemId: 'item:1',
    from: {
        listId: 'primary',
        index: 2,
    },
    to: {
        listId: 'secondary',
        index: 0,
    },
    affectedLists: [
        {
            listId: 'primary',
            itemIds: ['item:2'],
        },
        {
            listId: 'secondary',
            itemIds: ['item:1', 'item:3'],
        },
    ],
    input: 'pointer',
}
```

`input` is `pointer`, `keyboard` or `programmatic`.

No menu order, taxonomy, REST, database or product-domain fields are part of the
Foundation contract.

## Pointer interaction

The Foundation uses Pointer Events for mouse, touch and pen/stylus input.

A pointer movement threshold prevents a click from becoming an accidental drag.
During an active reorder, Base owns:

- pointer capture;
- vertical hit testing;
- before/after insertion feedback;
- bounded page-edge autoscroll;
- cancellation cleanup.

v1 supports vertical ordered lists. It is not a free-position canvas or generic
tree editor.

## Keyboard interaction

When focus is on a reorder handle:

- `Alt + ArrowUp` moves the item one position earlier;
- `Alt + ArrowDown` moves the item one position later.

Focus remains associated with the moved item.

Consumers that enable cross-list movement must also provide a non-pointer way to
select another list, such as a Move-to control that calls `controller.move()`.
Pointer dragging must never be the only way to perform a cross-list mutation.

## Accessibility

Base creates one polite live region per reorder root and announces completed
moves and rollbacks. Item and list labels come from
`data-cb-core-reorder-label` and `data-cb-core-reorder-list-label`.

The Foundation intentionally does not impose `role="listbox"`,
`aria-grabbed` or `aria-dropeffect`. Consumers should use native semantic
HTML appropriate to their own content.

During async persistence the root receives `aria-busy="true"` and rejects
additional reorder mutations until the current move settles.

## Rollback

The previous DOM order is captured before mutation.

If consumer persistence fails:

1. the previous list/order snapshot is restored;
2. pending state is removed;
3. focus is restored to the moved item's handle where possible;
4. a polite rollback announcement is emitted;
5. `cb:reorder:error` is dispatched.

Base does not show product-specific error copy or Toasts automatically.

## Events

Successful commit:

```text
cb:reorder:change
```

Rollback:

```text
cb:reorder:error
```

Both bubble from the reorder root. Event detail contains the move projection.
The error event additionally exposes the caught/rejected error value.

## Nested roots

Nested reorder roots are supported. A handle, list or item belongs to its
closest `[data-cb-core-reorder]` root. Parent controllers must not consume
child-root movement.

## Presentation adapters

Standalone WordPress admin screens may opt in with:

```php
\CB\Core\UI\Assets::enqueue_reorder();
```

Auto mode uses the Core presentation below the Core Blueprint parent menu and
the WordPress-native presentation elsewhere.

Consumers must not import `reorder.js`, `reorder.css` or
`reorder-native.css` directly.

Registered Core Admin pages declare:

```php
[
    'foundations' => [ 'reorder' ],
]
```

Base presentation owns only generic reorder states: handle interaction,
dragging, insertion marker, pending state, focus treatment, reduced motion and
live-region utility. Consumers continue to own their row/card/list composition.

## Persistence boundary

Reorder Foundation never:

- writes WordPress posts or terms;
- knows `menu_order`;
- knows taxonomy relationships;
- creates REST/AJAX routes;
- evaluates capabilities;
- logs product-domain audit events.

A consumer may use a normal form submit, REST, AJAX or another canonical
application service. The Foundation sees only the resulting move success or
failure.

## v1 scope

Included:

- same-list vertical reorder;
- optional cross-list movement;
- pointer/touch/stylus interaction;
- keyboard same-list movement;
- programmatic moves;
- focus preservation;
- announcements;
- async pending state;
- rollback;
- nested-root isolation;
- Core and WordPress-native presentation.

Excluded:

- arbitrary tree reparenting;
- Kanban semantics;
- free-position coordinates;
- domain persistence;
- bulk selection;
- generic workflow/page-builder models.
