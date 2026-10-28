# Core Blueprint — Document Editing Lease v1

**Status: opt-in Base SOURCE CANDIDATE; not installed, field-validated, integrated or released.**
**Scope:** exclusive **editing**, not live collaboration. One editable browser session per persisted document. Source branch `feature/base-document-edit-lease-v1-20261028` from Base `16653229556a7e0a5b0e04c69dd257f1dd153f49`.

## Public PHP contract

`CoreBlueprint\Core\Design\Editor\DocumentEditLease` is PSR-4 loaded by Base; it registers **no** REST/AJAX/Heartbeat hooks and creates **no** table, new frontend runtime or migration. Consumers own all endpoint authentication, CSRF/nonce, document identity, authorization and Save integration.

```php
use CoreBlueprint\Core\Design\Editor\DocumentEditLease as EditLease;

// Example only. The consumer checks a real edit capability and identity here.
$authorize = static function ( string $type, string $id ): bool {
    return 'contracts.template' === $type && contracts_actor_can_edit_template( $id );
};

// After an authenticated, nonce-checked POST, BEFORE mounting Designer:
$result = EditLease::claim( 'contracts.template', $template_id, $authorize );
// 'acquired' => send the opaque token in the private response.
// 'locked'   => deny second editor (including another tab of the same user).
// 'denied'   => access denied; 'unavailable' => fail closed.

// On normal authenticated, nonce-checked heartbeat:
$renewed = EditLease::renew( 'contracts.template', $template_id, $token, $authorize );
// 'renewed' => continue. Anything else => disable editing/Save, retain local draft.

// A preliminary server-side ownership check is available:
$owns_now = EditLease::owns( 'contracts.template', $template_id, $token, $authorize );
// WARNING: a preliminary owns() check is NOT atomic with Save.

// Inside the consumer's EXISTING InnoDB transaction, on the SAME wpdb:
if ( ! EditLease::owns_for_save( 'contracts.template', $template_id, $token, $authorize ) ) {
    throw new RuntimeException( 'EDIT_LEASE_LOST' );
}
// Perform the consumer's existing revision/CAS-guarded Save BEFORE COMMIT.
// The locked option row prevents a competing claim/takeover during this write.

// On normal exit, authenticated request with the exact owner token:
EditLease::release( 'contracts.template', $template_id, $token );
```

## Ownership and storage

- Uniqueness: Base computes one opaque option name from **current site ID + consumer-owned resource type + stable document key**. Consumers must use **the same canonical identity for the same editable resource** across routes; never derive a new key per tab/editor view.
- Existing `wp_options.option_name` **UNIQUE** index is the atomic acquisition boundary. No global lock, no `postmeta` races, no custom migration.
- The lease row uses a bounded server-issued expiry (**180 seconds**), the authenticated WordPress **user ID**, and a SHA-256 digest of a **cryptographically random 32-byte page-session bearer token**. The secret token is **returned to the acquiring consumer** but not stored in the database. The key/value are never exposed in public HTML, URLs or logs.
- Existing `_edit_lock`/`wp_check_post_lock()` may inform UX, but **must not act as the exclusive authority**: WordPress's normal post locks do not block other tabs of the same authenticated user. The transport can reuse **WordPress Heartbeat** for refresh (e.g. every 30 seconds), but the server-side ownership proof remains this Base API.
- Each new editor-page session needs a **new token**; do **not** share/reuse through `localStorage`, `sessionStorage`, cookies, query strings or a persisted document field. Page refresh may briefly wait for expiry if best-effort release did not complete.
- `claim()`: `acquired | locked | denied | unavailable`.
- `renew()`: `renewed | lost | invalid | denied | unavailable`.
- `owns()`, `owns_for_save()`, `release()`: booleans, fail closed. An expired owner **cannot** renew, save as owner or delete a successor's lock.
- The expired row is **reclaimed using an atomic compare-and-swap UPDATE**, not deleted/recreated. A competing claimant can have exactly one winner.
- Direct SQL against the **site's options table** avoids reliance on potentially stale WordPress object-cache values. Keys use `autoload='off'`; each edited document creates at most one row. The expired row is reused when the same document is reopened; no background cleanup job in v1.
- `owns_for_save()` requires a real active database transaction and an **InnoDB `wp_options` table**, then uses `SELECT ... FOR UPDATE` to pin owner status until the consumer commits.
- **Database compatibility:** MariaDB's session-only `@@session.in_transaction` is used only on MariaDB. MySQL 8.x uses the documented `performance_schema.events_transactions_current` + same-connection `threads` mapping and requires an `ACTIVE` event. If Performance Schema, the transaction instrument/consumer, or SQL access is unavailable, the Save gate **fails closed**; no guessed autocommit/transaction state. A real MySQL runtime smoke is a separate FIELD gate before advertising MySQL compatibility. It **refuses** non-transactional Save paths and unavailable/incompatible storage. It must run on the **same connection** as the actual domain write.
- Authorization callback must independently validate the actor, site, and right to edit **this exact resource**; returning anything other than `true` refuses. The library is **not** a new anonymous REST API. All entry/renew/release endpoints need standard WP authentication and request nonce.
- Keep existing domain revision/CAS and any operation receipts. The lease prevents normal concurrent editing; CAS continues to protect stale writes or replay. A network timeout or unverified Save must not be reported as success.

## Consumer workflow (Contracts first)

1. The user requests an editor. The Contracts endpoint verifies identity/capability/nonce and calls `claim()` **before enabling any editable Designer controls**. On `locked`, show a clear read-only blocking notice; other documents remain editable.
2. While the editor is open, the consumer renews via WordPress Heartbeat/its existing authenticated transport. A browser can be background-throttled: treat missed renewal or lost ownership as **edit-disabled**, keep the unsaved local draft available, and show a clear recovery path. Never silently reclaim a lease with a new token on Save.
3. **Every persistent Save** must verify the exact token and user identity via `owns_for_save()` **inside the same database transaction as the domain revision/CAS write**. If the consumer's existing Save is not transactional, the consumer must make that **small, localized integration change** before activating the lock; a separate initial `owns()` check is not sufficient. No generic Save engine migration.
4. On normal exit, best-effort authenticated `release()`; browser crash/offline recovers automatically after the 180-second TTL. A failed release does not authorize another editor before expiry. No forced takeover in v1.
5. Different documents have independent rows; same document, two browsers/tabs/users => only one accepted editor. A losing request must not mount an editable document.

## Outside scope

No Google Docs-style collaboration, shared cursors, cross-document locks, administrative takeovers, generic presence system, new table, new background scheduler, `moveNode()` changes, Reports R2-B2 integration or forced adoption by extensions. Reports only participates **when its own persistent document authoring is authorized**.

## Evidence required before any integration or release claim

- Run `php -l` on this class and `tests/public-designer-edit-lease-regression.php`, then `php tests/public-designer-edit-lease-regression.php`. **No executed PASS is currently claimed.**
- WordPress/MariaDB sandbox race with **two independent PHP workers** claiming exactly the same key; same-account tabs must not both win. Verify InnoDB engine, failed DB, expiry, renewal, release and lock-stealing scenarios. **SOURCE fake-DB tests are not concurrency proof.**
- Contracts live UI: second editable open denied, another document allowed; Heartbeat loss disables Save; one transactional `owns_for_save()` + existing CAS path; no stale-owner late Save, no unverified success; 409 stale-revision remains a separate defensive contract.
- Maintain Base's accepted Golden baseline and normal Level 1/2/3 gates. No merging or deploying this isolated candidate without separate acceptance.

**Ownership:** Base PHP lease mechanism. Contracts/other consumers: document identity, permissions, nonce/transport, private in-memory token, denied-entry copy, Heartbeat, server Save integration. No duplication of the lease algorithm in extensions.
