# Button Foundation

Status: **public Base UI contract**.

Buttons use the same semantic markup in Core Admin and standalone WordPress
admin. Base owns presentation, keyboard focus and visual states. Consumers own
action meaning, permissions, validation, layout and side effects.

## Public enqueue

```php
\CoreBlueprint\Core\UI\Assets::enqueue_buttons();
```

Auto presentation selects `core` under the Base parent menu and
`wp-native` on standalone WordPress admin screens. Consumers may explicitly
pass `Assets::BUTTON_PRESENTATION_CORE` or
`Assets::BUTTON_PRESENTATION_WP_NATIVE` where necessary.

Core Admin uses the established `buttons.css` with Base tokens; standalone
WordPress admin uses the narrow `buttons-native.css` adapter with optional
semantic tokens and WordPress fallbacks. The native adapter does not import
Core Admin dark mode, layouts or typography, and never styles generic WP
`.button` elements without `.cb-core-button`.
It deliberately excludes buttons within the existing Base modal dialog; the
Modal Foundation retains ownership of its own action-button presentation. CSS filenames and asset handles
are private; consumers call the public helper instead.

On a registered Core Admin page, the existing `PageRegistry` semantic
`components => [ 'buttons' ]` remains supported.

## Canonical markup

```html
<button type="button" class="button cb-core-button cb-core-button--secondary">
    Cancel
</button>
<button type="button" class="button cb-core-button cb-core-button--danger">
    Delete exception
</button>
<button type="button" class="button cb-core-button cb-core-button--primary cb-core-button--compact">
    Save
</button>
```

Required: `cb-core-button` plus one semantic modifier:
`--primary`, `--secondary`, `--remediation`, or `--danger`.
Optional: `--compact`, `--icon-only`. Danger is outlined until hover.
Use `type="button"` for non-submit actions so a form does not submit
accidentally. Use `type="submit"` only for intentional form submission.

```html
<button type="button"
        class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only"
        aria-label="Open calendar">
    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
</button>
```

An icon-only button **must** have a meaningful accessible name.
The icon itself is decorative. Base owns sizing, alignment, hover, focus-visible,
disabled and busy presentation; consumers must not redraw it locally.
Use native `disabled` for unavailable actions, and `aria-busy="true"`
only when the consumer actually manages a pending operation.
An `aria-disabled` element still needs consumer-side action prevention.

This contract introduces no JavaScript behavior or dependency on Designer
Foundation. The existing Modal Foundation is a separate opt-in API for
confirmation flows.

## Manual FIELD acceptance (staging only)

The optional WordPress plugin at `tests/fixtures/base-ui-p53-field.php`
exercises the public enqueue APIs with normal WP buttons, every semantic
button variant, icon-only geometry, outlined icon controls and native
`<details>`. It is deliberately excluded from the canonical release package
by the runtime allowlist. Install it temporarily on staging, open
**Tools → Base UI P53 Fixture**, and remove it after acceptance. The two
presentation links let testers compare native and Core adapters.
