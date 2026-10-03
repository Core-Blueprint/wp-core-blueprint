# Access Mode integration contract

Core Blueprint Base owns the public-response policy for four mutually exclusive modes:

- `public` — normal WordPress responses.
- `coming_soon` — the configured published page remains HTTP 200; other anonymous front-end URLs receive a temporary HTTP 302 redirect to it.
- `maintenance` — the configured published page is rendered at the originally requested URL with HTTP 503; an optional future return time becomes `Retry-After`.
- `admin_only` — anonymous public front-end requests receive HTTP 403 plus `X-Robots-Tag: noindex, nofollow`.

Logged-in users, WordPress admin/login, REST, AJAX, cron, WP-CLI, XML-RPC, Core Blueprint Failsafe, and standard WooCommerce `wc-api` / `wc-ajax` machine callbacks bypass Access Mode enforcement.

## Extension-owned machine or webhook routes

Extensions that expose a non-REST public callback which must remain reachable during Coming Soon, Maintenance or Admin-Only should register a request predicate during bootstrap:

```php
use CoreBlueprint\Core\Security\AccessMode;

AccessMode::register_bypass(
    'my-extension-webhook',
    static function ( string $mode ): bool {
        return isset( $_GET['my-extension-webhook'] );
    }
);
```

The callback is evaluated for the current request only. Return `true` only when the request really belongs to the extension-owned machine endpoint. Do not return `true` based on a broad path or user-agent match.

For backwards-compatible/advanced policy, Base also exposes:

```php
apply_filters( 'cb_core_access_mode_bypass_request', false, $mode );
```

New integrations should prefer `AccessMode::register_bypass()` because it creates a stable named boundary and avoids anonymous filter coupling.

## Ownership rule

Access Mode owns only public request exposure and response semantics. Extensions remain responsible for authenticating and authorizing their own webhook/machine endpoints. Registering a bypass never grants permissions and never disables an endpoint's own authentication.
