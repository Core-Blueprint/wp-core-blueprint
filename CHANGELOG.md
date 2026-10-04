# Core Blueprint changelog

> This changelog tracks the public `1.0.0` launch line. Internal pre-v1 development history is retained in `CHANGELOG-HISTORY.md` in the source repository and is not included in production packages.

## 1.0.0 — 2026-10-04

### Secret Protection Foundation v1

- Publish `CoreBlueprint\Core\Security\SecretProtection` as a public Core API `1.2` service for authenticated protection of extension-owned credentials at rest.
- Bind every protected payload to an exact consumer purpose and subject so ciphertext cannot be reused under another declared connection context.
- Keep credential records, authorization, lifecycle and retention extension-owned; Base stores no credentials and provides no plaintext fallback.
- Fail closed for malformed, unsupported, oversized or unauthenticated payloads and document WordPress salt rotation as an explicit reconnect boundary.

### Core API 1.2 - shared admin navigation and tiles

- Add canonical public Level 1 and Level 2 navigation primitives for Core Blueprint admin workspaces while keeping route, capability and domain behavior consumer-owned.
- Add the semantic `admin-navigation` component and public narrow enqueue boundary so extensions do not depend on Base asset handles or filenames.
- Publish shared Tile rendering and compact density as a cross-plugin UI contract without broadening the existing `metric-tiles` semantic requirement beyond generic KPI/value-card presentation.

### Core API 1.2 — native admin screen UI requirements

- Extend `AdminTheme::register_screen()` so WordPress-owned admin screens can request semantic shared UI requirements without depending on Base asset handles or filenames.
- Reuse the canonical PageRegistry requirement vocabulary and add the formal `buttons` component identifier alongside existing fields/form-controls contracts.
- Union repeated declarations for shared WordPress hook suffixes so compatible consumers cannot silently erase one another's requirements.
- Keep WordPress routing and screen ownership native; the contract only supplies Base-owned shared component presentation.

### AI Governance WordPress-native observability

- Observe the WordPress 7.0 AI Client through its official generation lifecycle and retain provider, model, capability, duration and token-count metadata without storing prompts or generated content.
- Compose the official WordPress MCP Adapter default-server observability handler so MCP request, session fingerprints, component and bounded failure evidence is captured without replacing an existing handler.
- Add evidence-based request-local correlation for directly nested governed operations, while refusing heuristic correlation across boundaries that do not expose a shared identifier.
- Extend the dedicated AI Activity schema to v1.1 with indexed correlation, provider and model dimensions, matching filters and export fields while leaving the global Base database marker unchanged.

### Routing & URLs Governance v1

- Add opt-in clean category archive URLs that remove the WordPress category base while preserving WordPress as the routing source of truth.
- Use compact archive pagination such as `/blog/p2/`, with explicit legacy redirects from WordPress category-base and `/page/{n}/` forms.
- Require a collision preflight before activation and reserve category-scoped `p{n}` routes for pagination.
- Detect known Page, public post type, taxonomy and compact-pagination route collisions, including collisions introduced after activation.
- Fail open to WordPress default category routing when runtime safety drifts, while preserving the configured policy for recovery after the collision is resolved.
- Reconcile rewrite rules across enable, disable, category/content changes, plugin/theme routing changes, Base deactivation and reactivation.
- Add Preferences and Core Setup integration, six-locale administration copy and focused regression coverage for the original `/blog/page/2/` misparse.

### Reorder Foundation motion polish

- Publish the Reorder Foundation as a public Core API `1.1` contract, preserving the 1.x compatibility rule where a Base API minor satisfies equal-or-lower requested minors.
- Add Base-owned reduced-motion-aware FLIP settling for pointer, keyboard and programmatic reorder moves, including animated rollback after persistence failure.
- Keep consumer markup and transforms independent by animating positional `translate` only, with an instant fallback when reduced motion is requested or Web Animations are unavailable.

### Migration Recovery Foundation

- Add a Base-owned destination recovery contract for governed cross-site migrations, with short-lived destination-bound HMAC tickets and no global security bypass.
- Establish a new destination trust domain after database migration, reconcile Base-owned Role Policy on a fresh runtime, and require imported privileged identities to pass explicit `site_migration` review before destination approval.
- Allow only an Administrator or CB Operator from the migrated site to complete recovery authentication, while keeping Login Shield and other restrictive features bypassed only for the signed recovery request and recovered admin session.
- Expose whether destination pretty routing must be verified so migration extensions can prove permalink and custom-login routing before declaring completion.
- Add audit labels, six-locale recovery copy and integration coverage for ticket tampering, destination binding, two-phase reconciliation, trust invalidation and one-identity reapproval.

### Core Profiles v1

- Add approved-Operator-only export, preview and transactional apply of portable Base configuration through versioned Core Blueprint Profile JSON documents.
- Keep secrets, executable Snippets, identities, trust approvals, site mode, access-state IDs, runtime evidence and customer content outside the Profile payload by explicit section allowlists.
- Add deterministic full preflight, human-readable diffs, stale-preview fingerprints, compare-and-swap apply locking, per-section verification and compensating rollback with concurrent-state protection.
- Apply portable module activation last, coordinate Core Scanner state changes with the Scanner lock, and keep Content Models rollback targeted to definitions touched by the failed Profile transaction.
- Add strict schema/type validation, bounded uploads/review size, six-locale Profile catalogs and regression coverage for stale state, partial failure, lock ownership, rollback and portability boundaries.
- Freeze the v1 ownership contract so official first-party extensions can register namespaced portable sections through a controlled, one-shot lifecycle without moving extension settings into Base.
- Add portable severity-based audit notification policy while keeping recipient addresses explicitly site-local.

### Data Exchange + Data Mapper Foundations v1

- Add the Base-owned `core-blueprint::data-exchange.entity@1` contract on top of Generic Interoperability so extensions can expose versioned import/export entities without moving domain semantics or persistence into Base.
- Add bounded JSON and self-describing CSV transport with strict envelopes, provider authorization, full preflight, deterministic preview fingerprints, stale-plan protection, duplicate-reference rejection and explicit partial-result semantics.
- Add the provider-neutral Data Mapper field-schema and mapping boundary with deterministic id/label/alias matching, explicit direct/ignore/constant transforms and no fuzzy guessing for ambiguous fields.
- Add the shared Data Mapper workspace as a consumer of the existing public Designer Shell, including request-local file intake, field mapping, Undo/Redo, auto-match, inspection and preview handoff without Base-owned AJAX or browser persistence.
- Keep extension providers authoritative for field meaning, portable identity, canonical validation, mutations, audit meaning and authorized upload/download transport; Base does not write provider records directly.
- Add DX/Mapper regression coverage for canonical identity, stale/tampered plans, CSV formula protection, mapping ambiguity, required fields, malformed initial mappings and the request-local browser boundary.

### Forms Foundation v1 — normalized form ingress

- Add the Base-owned `core-blueprint::forms.provider@1` platform contract on top of Generic Interoperability while keeping every concrete provider extension-admitted and builder-neutral.
- Load Base-owned interoperability contracts from a private read-only catalog before the public extension contract lifecycle; expose no public Base-owned contract mutation route.
- Add bounded, immutable, request-local form-submission ingress through `SubmissionEmitter` / `SubmissionEvent`, with multiple-provider support and runtime availability checks.
- Treat `null` as the only omitted optional submission/event identifier value; explicit empty identifiers fail closed and the documented field/value transport limits are regression-locked.
- Keep raw submission values out of Base persistence, Audit/Governance and Automation by default; consumers must deliberately own any storage, classification, retention or orchestration they introduce.
- Add contract coverage for reserved Base ownership, provider spoofing, exact support discovery, multiple providers, bounded transport and lifecycle/freeze behavior.

### Golden Standard Gate 5B — localization quality

- Align all six shipped locale catalogs with the current 3,216-string `1.0.0-rc1` runtime source.
- Replace broken mixed-language and low-quality historical translations in DE, FR, ES, IT and PT while adding the missing current-source strings to NL.
- Preserve placeholders, contexts and locale-specific plural rules, including French `n > 1`, and ship WordPress-native `.l10n.php` catalogs as the single compiled runtime format for the WP 7.0+ baseline.
- Remove stale duplicate PO/MO/POT runtime artifacts, add deterministic source-to-catalog freshness checking, and verify actual WordPress singular/plural loading for NL/DE/FR/ES/IT/PT.

### Golden Standard Gates 2–4 — security, architecture and concurrency

- Harden Failsafe rejection auditing against unauthenticated write amplification while preserving complete audit coverage for valid bypass lifecycle events.
- Remove server-side plaintext recovery copies for rotated Failsafe tokens and failed Snippets saves; one-time recovery now remains request/browser scoped.
- Preserve user-authored Snippets source on uninstall while neutralizing Base-owned generated runtime state.
- Split Access Mode persistence/admin transport from runtime enforcement without changing the public AccessMode API.
- Move Base-owned settings defaults into a dedicated schema owner while retaining `Settings::defaults()` as the stable public facade.
- Make Scanner global and slice lock refresh/release operations compare-and-swap guarded so a superseded worker cannot overwrite or release a newer owner lease.
- Add regression coverage for Scanner stale takeover, ownership and atomic mutation contracts.

### Golden Standard Gate 1 — PHP 8.4 and CI baseline

- At this earlier hardening milestone, keep the public plugin version at `1.0.0-rc1`, with Core API and database schema versions then at `1.0`; Core API was subsequently advanced to `1.1` when the Reorder Foundation was published.
- Make CSV export explicit about the `fputcsv()` escape argument so Base remains clean on PHP 8.4+ without changing the existing CSV escaping behaviour.
- Align Settings Hub integration fixtures with Privileged Access Protection by explicitly approving administrator identities created for tests rather than weakening the production quarantine boundary.
- Pin the release-package update smoke to a canonical earlier `1.0.0-rc1` main baseline so install/update validation exercises the supported current-RC lifecycle.
