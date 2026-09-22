# Mail Sender Identity API — Core API v1

Core Blueprint Base owns outbound mail transport, credentials, delivery logging and approved sender configuration. Extensions must not create their own Brevo/SMTP stack merely to send from a product-specific address.

## Ownership

- Base owns the selected transport (`Brevo` or `Generic SMTP`) and its credentials.
- Base owns the default sender used by ordinary WordPress/system mail.
- Extensions declare named sender **roles**; they do not own transport credentials.
- Administrators configure the effective address/name for active registered roles on **Core Blueprint → Mail**.
- Unknown sender IDs fail safely to the configured default sender.

## Registration lifecycle

Attach a callback during extension bootstrap. Base fires `cb_core_register_mail_sender_identities` on `init` priority `5`, after the normal translation lifecycle has started.

```php
use CB\Core\Mail\SenderIdentityRegistry;

add_action( 'cb_core_register_mail_sender_identities', static function (): void {
    SenderIdentityRegistry::register( [
        'id'          => 'core-blueprint-helpdesk',
        'label'       => __( 'Helpdesk', 'core-blueprint-helpdesk' ),
        'description' => __( 'Customer support and ticket replies.', 'core-blueprint-helpdesk' ),
    ] );
} );
```

Identity IDs use the same lower-case namespaced kebab-case discipline as Core Blueprint extension IDs. Duplicate or malformed registrations are rejected rather than overwritten.

`default_email` and `default_name` are optional declaration values. When omitted, or when no administrator override exists, Base falls back to its configured default sender.

## Sending

Use `CB\Core\Mail\Sender::send()` instead of constructing a separate transport:

```php
use CB\Core\Mail\Sender;

$sent = Sender::send(
    'core-blueprint-helpdesk',
    $customer_email,
    $subject,
    $body,
    [ 'Reply-To: support@example.com' ],
    $attachments
);
```

The signature mirrors the normal WordPress `wp_mail()` arguments after the identity ID:

```text
Sender::send(
    identity_id,
    to,
    subject,
    message,
    headers = [],
    attachments = []
): bool
```

Base resolves the registered identity, replaces any caller-supplied `From` header with that approved identity, scopes the sender for the duration of the `wp_mail()` call, and then restores the previous context. Nested sends therefore do not leak sender state across messages.

### Durable sender snapshots

Durable workflows may commit sender values before asynchronous delivery. Use `Sender::send_if_identity_matches()` when the committed sender must not drift:

```php
$result = Sender::send_if_identity_matches(
    'core-blueprint-newsletter',
    $dispatch_sender_email,
    $dispatch_sender_name,
    $recipient_email,
    $subject,
    $html,
    [ 'Content-Type: text/html; charset=UTF-8' ]
);
```

The method resolves the registered identity once, compares its effective email/name with the committed values, and fails closed with a `WP_Error` when the identity is unavailable or has changed. When it matches, that exact resolved identity is held request-locally for the entire `wp_mail()` call so SMTP filters and provider adapters observe the same sender snapshot.

This contract does not permit arbitrary From addresses. The committed values must still match a currently registered Base sender identity.

## Force-default policy

The Mail settings `Force default From Email` and `Force default From Name` continue to protect ordinary WordPress/plugin mail from replacing the site default. A sender requested through this public API is an explicit Base-approved exception and remains intact.

This distinction is deliberate:

- arbitrary `wp_mail()` caller `From` override → subject to Base force-default policy;
- registered `Sender::send()` identity → allowed through the same Base transport;
- unknown identity → safe fallback to the default sender.

## Provider requirements

Registering an identity does not make an address valid at the external provider. Every configured sender address must still be authorized according to the selected provider's rules (for example a verified Brevo sender/domain or an SMTP server that permits the address).

## Incoming mail

This API is outbound-only. Mailbox retrieval, IMAP credentials, inbound parsing and ticket threading belong to the extension that owns that workflow (for example Core Blueprint Helpdesk), not to Base Mail.
