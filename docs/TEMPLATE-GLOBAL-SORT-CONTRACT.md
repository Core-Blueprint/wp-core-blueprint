# TL-1B1 — Bounded global template sorting (Base contract)

**Status:** SOURCE CANDIDATE, exact-head native integration and WordPress FIELD **OPEN**.  
**Initial Base parent:** `main@7958f9677ac173a25078fc2329bf402b19dceb87`.  
**Consumer note:** TL-1B2 will introduce the UI control after this backend is accepted. Existing unsorted browsing remains the default, unchanged. **No Foundation Freeze, merge or release is implied.**

## Sort identity and correct global order

`TemplateQuery(sort: 'title-asc')`, or authenticated REST `GET /core-blueprint/v1/template-library/templates?sort=title-asc`, opts into sorted metadata. A missing/empty `sort` continues to page by source and its native cursor.

**Important UX limitation:** the initial comparator is raw UTF-8 byte ordering (`strcmp`), case- and accent-sensitive, **not localized/natural-language A–Z sorting**. The UI must not claim locale-sensitive alphabetical ordering, nor label the control plain "A–Z", until a unified collation and matching DB seek strategy are separately qualified. The global total order is `(raw_title_bytes ASC, source_id ASC, template_id ASC)`; duplicate titles across sources and IDs have deterministic tie-breakers. This gives identical behavior in PHP and MariaDB regardless of the site's default collation. MariaDB Saved Mail uses `BINARY title`/`BINARY id` comparisons and ordering; SQL result equality with the PHP comparator is covered by physical WordPress integration tests.

## Optional provider contract

A registered source explicitly implements `TemplateSortedSourceInterface` alongside the existing `TemplateSourceInterface`. Method:

```php
public function query_sorted(TemplateQuery $query, ?array $after): TemplatePage;
```

`$after` is a decoded, **trusted by Base but never by the provider for authorization**, global position `['title' => string, 'source' => string, 'id' => string]`, or null on the first page.

Providers MUST:
- Enforce source/extension/Designer/actor, origin/category/locale/search predicates and per-item `can_view` before paging.
- Return at most `$query->limit` metadata-only entries in the exact comparator order, all **strictly after** the full global position. Return the first qualifying entries without gaps.
- Return `next_cursor: null` for this method. Base alone owns global continuation; the default `query()` remains unchanged.
- Never supply documents, external HTTP reads, or consumer write authority through sorted metadata.
- Advertise sorted capability only when the provider can satisfy the contract under its storage and access policy.

Native providers initially implementing this contract: Mail built-in, Mail Saved, Maintenance Reports. Mail Saved retains owner/site visibility and uses the same actor/current capability checks as native unsorted paging. Existing extension providers without this interface remain fully supported for **unsorted** browsing.

## Registry, budget and confidentiality

- At most **12 visible providers per sorted query** and **48 items per provider read** (REST limit 1–48). The Registry merges up to 576 candidate metadata records to emit at most 48; a last-page check, when needed, probes at most one item per provider. It does not load domain document bodies.
- The Registry checks each item's source, extension, Designer, filters, current `can_view`, exact order and strict global cursor position. An oversized, out-of-order, unauthorized, unexpected, failing or non-opted-in provider makes sorted browsing **fail closed** with `422 {"code":"sort_unavailable"}`. It never silently produces an incomplete result advertised as globally sorted.
- The global cursor stores only the last comparator tuple, query/sort/provider-set fingerprint, actor, WordPress blog/site ID and expiration (15 minutes). It is **AES-256-GCM authenticated and encrypted** with a key derived from the site's WordPress auth salt, using a fresh random 96-bit IV. This protects personal template titles from REST URL and access-log disclosure. No cleartext template titles or private provider cursors are transmitted. Missing OpenSSL prevents only the sorted mode: `sort_unavailable`.
- Invalid/modified/replayed cursors, wrong actor/site/scope/query/sort, or expired cursors are denied with `400 {"code":"invalid_cursor"}`. Changed provider membership alters the fingerprint and invalidates the cursor. Per-item authorization is always rechecked.
- Empty source scopes legitimately return no items. A provider count over 12 fails explicitly rather than truncating the visible provider set.
- Sorting is an **ordered keyset view**, not a transactional cross-provider snapshot: templates inserted, removed or renamed between separate HTTP requests can alter which future rows qualify. Never claim snapshot stability. A client should refresh from the beginning after an administrative mutation.

## Technical proof and acceptance

- `tests/integration/TemplateLibraryGlobalSortTest.php`: interleaved providers, ties, boundaries, more than 2,000 fixture templates, cursor privacy/tamper/actor/scope/filter/limit, unsupported and untrusted providers, unauthorized role, REST 400/422.
- `tests/integration/MailSavedTemplateLifecycleTest.php`: real WordPress/MariaDB Saved Mail title order, case-sensitive and accented titles, source-aware keyset advancement, no document body returned.
- Native Node/browser action regressions are unchanged; TL-1B1 introduces no browser UI, translation keys or style changes.
- Acceptance requires exact feature SHA, `bash tools/check`, `python3 tools/build-dev` (internal ZIP, manifest, hash, unzip), disposable Base Level 2/full WP matrix and targeted REST/WordPress FIELD before a separate owner merge GO. Do not publish, deploy, dispatch CI/CD or lift FRG-04 during this phase.

## Deliberately deferred

TL-1B2: a sort-select in both Browser modes, only after the backend is qualified, with accurate case-sensitive ordering language, responsive/RTL/keyboard behavior, cursor/selection invalidation and end-to-end FIELD.

True linguistic, case-folded `Title A–Z`, locale-aware comparisons, natural sorting, provider-specific collations or indices require a separately verified cross-DB/JSON comparator and seek contract. Provider-authorized real document previews remain a separate Foundation task.
