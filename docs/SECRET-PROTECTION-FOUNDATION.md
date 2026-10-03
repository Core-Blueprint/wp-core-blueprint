# Secret Protection Foundation

Status: **public v1 contract**.

Introduced in Core API `1.2`.

## Purpose

`CoreBlueprint\Core\Security\SecretProtection` is the Base-owned authenticated protection boundary for extension-owned credentials and other small secrets that must be stored at rest.

Base owns cryptographic protection only. The consuming extension remains responsible for authorization, credential semantics, persistence, retention, disconnect/reconnect behavior and any external-provider lifecycle.

This Foundation is deliberately **not** a credential database or central secrets vault.

## Public API

```php
use CoreBlueprint\Core\Security\SecretProtection;

$protected = SecretProtection::seal(
    $app_password,
    'core-blueprint-bookings.caldav',
    'connection:42'
);

if ( is_wp_error( $protected ) ) {
    // Fail closed. Do not persist plaintext.
}
```

To use the credential later:

```php
$secret = SecretProtection::open(
    $stored_payload,
    'core-blueprint-bookings.caldav',
    'connection:42'
);

if ( is_wp_error( $secret ) ) {
    // Treat the connection as unavailable/reconnect-required.
}
```

The stable public methods are:

```text
SecretProtection::available(): bool
SecretProtection::seal( string $plaintext, string $purpose, string $subject ): string|WP_Error
SecretProtection::open( string $payload, string $purpose, string $subject ): string|WP_Error
```

The public constants are:

```text
SecretProtection::CONTRACT_VERSION = '1'
SecretProtection::MAX_SECRET_BYTES = 65536
```

## Context binding

Every protected value is cryptographically bound to two exact consumer-owned context values:

- `purpose` identifies the credential domain, for example `core-blueprint-bookings.caldav`;
- `subject` identifies the exact owning object, for example `connection:42`.

A payload opened under another purpose or subject fails authentication. Consumers must not trim, rewrite or derive alternate context values between seal/open operations.

Purpose uses the canonical lower-case namespaced identifier grammar:

```text
[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*
```

Subject is an ASCII opaque identifier beginning with an alphanumeric character and may additionally contain `.`, `_`, `:` and `-`.

## Payload ownership

The protected string is opaque and versioned. Consumers:

- persist it unchanged;
- do not parse its prefix or encoded body;
- do not copy a payload between subjects;
- never fall back to treating an unknown payload as plaintext;
- replace the complete payload after credential rotation.

Base may change internal key derivation or payload encoding only through a compatible migration/versioning path. Consumer code depends on `seal()` and `open()`, not the internal encoding.

## Cryptographic boundary

v1 uses authenticated encryption backed by PHP Sodium. Base derives a purpose/subject-specific encryption key from WordPress secret material and never persists that derived key.

There is no plaintext fallback. Malformed, oversized, unsupported, context-mismatched or unauthenticated payloads fail closed with `WP_Error`.

The Foundation does not log plaintext, return plaintext through diagnostics, write Audit records containing secrets or persist credentials itself.

Secret-bearing public parameters are marked with PHP's `SensitiveParameter` attribute so unexpected stack traces redact their values.

## WordPress salt rotation

The site's WordPress authentication secret material is part of the encryption trust boundary.

If those salts are rotated or replaced, previously protected payloads may no longer be decryptable. Consumers must treat that state as reconnect/re-enter-credential rather than attempting an unsafe fallback.

This is intentional: Base does not maintain a second recoverable master secret beside WordPress' own secret material.

## Consumer responsibilities

A consumer must:

- request a dedicated app password/token where the remote provider supports one;
- never store a remote account master password when a scoped credential is available;
- keep decrypted plaintext in memory only as long as required for the immediate operation;
- redact credential values from logs, audit payloads, notices and support diagnostics;
- authorize every credential mutation through its own domain/capability boundary;
- remove its own protected payload when the owning connection is deleted.

## Non-goals

Secret Protection Foundation is not:

- a password manager;
- a remote KMS/HSM integration;
- a credential-sharing service;
- a database/table owned by Base;
- transport encryption;
- protection from already-privileged code executing inside the same WordPress/PHP process.

Extensions continue to own their records. Base only supplies the shared authenticated protection primitive.
