# Core Blueprint Toast Foundation

**Public contract since:** Core Blueprint Base `1.0.0`

The Toast Foundation provides one semantic runtime API with two presentation adapters. Extensions consume the runtime API and do not import Base files directly.

## Runtime API

```js
window.cbCore.toast( message, variant = 'success', options = {} );
window.cbCore.toast.success( message, options = {} );
window.cbCore.toast.error( message, options = {} );
window.cbCore.toast.warning( message, options = {} );
window.cbCore.toast.info( message, options = {} );
```

Supported semantic variants:

- `success` — an operation completed successfully.
- `error` — an operation actually failed.
- `warning` — attention is required, but the operation has not necessarily failed.
- `info` — neutral or in-progress information.

`danger` is intentionally **not** a Toast variant. In Core Blueprint semantics, `danger` describes destructive or irreversible intent before an action; `error` describes a failure that already happened.

### Options

```js
window.cbCore.toast.success( 'Template saved.', {
    duration: 5000,
    persistent: false,
    dedupe: true,
} );
```

- `duration` — auto-dismiss timeout in milliseconds. Semantic defaults are used when omitted.
- `persistent` — keep visible until the user dismisses it.
- `dedupe` — suppress an identical visible message + variant. Defaults to `true`.

The Foundation caps the visible stack at five items. Toasts have an explicit keyboard-accessible dismiss button. Auto-dismiss pauses while the toast is hovered or contains keyboard focus. Reduced-motion preferences disable toast transitions.

## Presentation boundary

### Core Blueprint admin-menu screens

Base automatically provides the **Core Admin** presentation on screens under the Core Blueprint parent menu. Consumers on those screens normally do not enqueue Toast assets themselves.

### Standalone WordPress admin screens

Standalone plugins such as Certificates, LMS and Communities must use the **WP Native** adapter. It follows WordPress admin notice geometry and colours and does not enqueue Core Blueprint theme tokens, dark mode, cards or page layout styling.

Use the opt-in asset helper from the extension's own `admin_enqueue_scripts` callback after confirming that the current screen belongs to the extension:

```php
use CoreBlueprint\Core\UI\Assets;

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( ! my_certificates_screen( $hook ) ) {
        return;
    }

    if ( class_exists( Assets::class ) && method_exists( Assets::class, 'enqueue_toasts' ) ) {
        Assets::enqueue_toasts(); // Default: WP Native presentation.
    }

    wp_enqueue_script_module(
        '@cb-certificates/designer',
        plugins_url( 'assets/js/designer.js', __FILE__ ),
        [ '@cb-core/toast' ],
        MY_CERTIFICATES_VERSION
    );
} );
```

Extensions should declare `@cb-core/toast` as a dependency of any script module that calls the runtime API. This guarantees that `window.cbCore.toast` has been initialised before the extension module evaluates.

If a Foundation consumer deliberately renders inside a Core Blueprint-themed admin context, it may request the Core adapter explicitly:

```php
Assets::enqueue_toasts( Assets::TOAST_PRESENTATION_CORE );
```

Do not use that presentation on normal standalone WordPress admin pages.

## Minimal Certificates usage

After enqueueing the WP Native adapter and declaring the module dependency:

```js
window.cbCore.toast.success( 'Certificate template imported.' );
window.cbCore.toast.error( 'A certificate box fill is invalid.' );
```

Certificates should not import `core/toast.js`, `toasts.css`, or `toasts-native.css` by filesystem or URL. Those files are private implementation details behind the Base asset helper and runtime API.

## Accessibility and lifecycle rules

- `error` messages are announced assertively with `role="alert"`.
- `success`, `warning` and `info` messages use polite `role="status"` announcements.
- The dismiss control is a real button with an accessible label.
- Auto-dismiss pauses on hover and keyboard focus.
- Identical visible messages are deduplicated by default to avoid repeated announcements.
- Persistent feedback that must remain part of the page context should normally use the Notice or FormStatus Foundation instead of a toast.
- Toasts should communicate temporary operation feedback, not replace validation text attached to a specific form field.
