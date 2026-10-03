# Modal Foundation — Public Extension Contract

Core Blueprint Base owns the shared modal runtime. Extensions must not ship a
second modal engine for normal admin confirmation, input, typed-confirm, or
reference/help dialogs.

## Public runtime API

```js
window.cbCore.modal.show( options )
```

The official script-module dependency identifier is:

```text
@cb-core/modal
```

The implementation file path is private and may change. Extensions should depend
on the module identifier and public `window.cbCore.modal` API only.

## Standalone WordPress admin screens

On a standalone extension-owned wp-admin screen, enqueue the narrow Foundation
primitive instead of the full Core Admin Theme:

```php
\CoreBlueprint\Core\UI\Assets::enqueue_modals();
```

The default presentation is `wp-native`. It loads the modal runtime, shared
Lucide icon primitive, and `modals-native.css`. It does **not** load Core Admin
tokens, dark mode, cards, layouts, or the Core Admin Theme.

Pages under the Core Blueprint admin menu already receive the `core`
presentation from Base and normally should not call the helper themselves.

## Modes

### Confirmation

```js
const confirmed = await window.cbCore.modal.show( {
    title: 'Delete certificate?',
    body: 'This action cannot be undone.',
    confirmLabel: 'Delete',
    confirmVariant: 'danger',
} );
```

Resolves to `true` when confirmed and `false` when dismissed.

### Required confirmation checkbox

Use `confirmCheck` when a confirmation must remain unavailable until the user
explicitly acknowledges caller-owned copy:

```js
const confirmed = await window.cbCore.modal.show( {
    title: 'Delete license product?',
    body: 'This permanently removes this License Product.',
    confirmLabel: 'Delete product',
    confirmVariant: 'danger',
    confirmCheck: {
        label: 'I understand that this action cannot be undone.',
    },
} );
```

`confirmCheck` is an orthogonal gate, not a fourth modal mode. Presence means the
acknowledgement is required. Base renders a native checkbox and associated
`<label>`; the checkbox always starts unchecked. Confirm remains disabled until
all active gates are valid, so `confirmCheck` may safely compose with
`typedConfirm` or a required `input`.

The option is intentionally strict: there is no `required`, initial checked
state, return value, or checkbox value passed to `onConfirm`. The consumer owns
only the user-facing label and the domain/business decision that requires the
acknowledgement. A missing, non-string, or whitespace-only label rejects the
returned Promise and no modal is rendered, so a requested acknowledgement never
fails open.

`confirmCheck` does not change resolution semantics: confirm/typed-confirm still
resolve to `boolean`; input still resolves to `string|null`.

### Dismiss-only / informational

Use `dismissOnly: true` for help and reference content. It renders one quiet
Close action; it does not render a redundant Cancel button.

```js
const body = document.createElement( 'div' );
body.textContent = 'Reference content';

await window.cbCore.modal.show( {
    title: 'Keyboard shortcuts',
    body,
    dismissOnly: true,
    confirmLabel: 'Close',
} );
```

### Typed confirmation

```js
const confirmed = await window.cbCore.modal.show( {
    title: 'Delete all records',
    body: 'This cannot be undone.',
    confirmLabel: 'Delete all',
    confirmVariant: 'danger',
    typedConfirm: 'DELETE ALL',
} );
```

### Input

```js
const value = await window.cbCore.modal.show( {
    title: 'Reason',
    confirmLabel: 'Continue',
    input: {
        type: 'text',
        label: 'Reason',
        required: true,
    },
} );
```

Resolves to the entered string when confirmed and `null` when dismissed.

## Supported options

- `title` — modal heading; required by the public contract.
- `body` — string or `HTMLElement`.
- `confirmLabel` / `cancelLabel` — action labels.
- `confirmVariant` — `primary`, `secondary`, `remediation`, or `danger`.
- `confirmIcon` — semantic/canonical shared Lucide icon name.
- `dismissOnly` — informational/reference modal with one Close action.
- `typedConfirm` — exact phrase gate.
- `typedConfirmHint` / `typedConfirmMismatch` — optional copy overrides.
- `input` — `{ type, label, placeholder, maxLength, required }`.
- `confirmCheck` — `{ label }`; required acknowledgement gate, always initially unchecked.

`danger` remains destructive intent. It must not be used merely because a modal
contains an error message.

## Presentation boundary

```text
Shared Modal runtime / semantics
│
├── Core Admin presentation
│   └── Core Blueprint tokens + dark/light mode
│
└── WP Native presentation
    └── normal WordPress wp-admin geometry and controls
```

An extension must not enqueue `modals.css`, `tokens.css`, or private modal files
directly. Use `UI\Assets::enqueue_modals()`.

## Certificates example

Certificates can replace the long inline keyboard-shortcut sentence with a
normal WordPress button. Its screen enqueue callback calls:

```php
\CoreBlueprint\Core\UI\Assets::enqueue_modals();
```

Its Designer module declares `@cb-core/modal` as a dependency and then opens:

```js
await window.cbCore.modal.show( {
    title: 'Keyboard shortcuts',
    body: shortcutsTableElement,
    dismissOnly: true,
    confirmLabel: 'Close',
} );
```

The resulting dialog automatically uses the WP Native adapter on the standalone
Certificates admin screen.
