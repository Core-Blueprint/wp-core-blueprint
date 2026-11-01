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

## Installed Base compatibility

The WordPress plugin header is currently `1.0.0` across multiple internal
development builds. Therefore that version number **does not prove** that a
site has the Button Foundation. The consumer must check the public
capabilities at runtime before calling them:

```php
$assets = \\CoreBlueprint\\Core\\UI\\Assets::class;
$ready = class_exists( $assets )
    && is_callable( [ $assets, 'enqueue_buttons' ] )
    && is_callable( [ $assets, 'enqueue_icon_controls' ] )
    && is_callable( [ $assets, 'enqueue_modals' ] )
    && defined( $assets . '::BUTTON_PRESENTATION_WP_NATIVE' );
```

When these checks fail, extensions should omit dependent controls and show a
capability-gated admin notice asking the site operator to install an accepted
Base build. Never call an unavailable method, silently substitute local
Foundation CSS, or rely on the `1.0.0` header as a compatibility gate.

The canonical Base packager includes `src/UI/Assets.php` and the native
component stylesheets. For an observed `undefined method` error, confirm
the actual installed class file and its declaring path with
`ReflectionMethod` in the active WordPress runtime. Check that the installed
file matches the exact tested ZIP before blaming the autoloader. The duplicate
load guard intentionally retains the **first** Base bootstrap; it cannot
upgrade an already-loaded class from a later plugin copy. Webserver OPcache may
also retain stale PHP definitions across replacement operations.

Do not bump Base's public version or introduce a second autoloader merely
to mask an unverified installation/cache mismatch.
