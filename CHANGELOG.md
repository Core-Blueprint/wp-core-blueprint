# Core Blueprint changelog

> The public launch line was normalized back to `1.0.0-rc1` on 2026-09-06. Older RC entries below are retained as pre-launch development history; the public plugin version remains `1.0.0-rc1` throughout the current Golden Standard closure cycle.

## 1.0.0-rc1 — 2026-09-09

### Data Exchange + Data Mapper Foundations v1

- Add the Base-owned `core-blueprint::data-exchange.entity@1` contract on top of Generic Interoperability so extensions can expose versioned import/export entities without moving domain semantics or persistence into Base.
- Add bounded JSON and self-describing CSV transport with strict envelopes, provider authorization, full preflight, deterministic preview fingerprints, stale-plan protection, duplicate-reference rejection and explicit partial-result semantics.
- Add the provider-neutral Data Mapper field-schema and mapping boundary with deterministic id/label/alias matching, explicit direct/ignore/constant transforms and no fuzzy guessing for ambiguous fields.
- Add the shared Data Mapper workspace as a consumer of the existing public Designer Shell, including request-local file intake, field mapping, Undo/Redo, auto-match, inspection and preview handoff without Base-owned AJAX or browser persistence.
- Keep extension providers authoritative for field meaning, portable identity, canonical validation, mutations, audit meaning and authorized upload/download transport; Base does not write provider records directly.
- Add DX/Mapper regression coverage for canonical identity, stale/tampered plans, CSV formula protection, mapping ambiguity, required fields, malformed initial mappings and the request-local browser boundary while keeping `1.0.0-rc1` and Core API `1.0` unchanged.

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

- Keep the public plugin version at `1.0.0-rc1`, with Core API and database schema versions unchanged at `1.0`.
- Make CSV export explicit about the `fputcsv()` escape argument so Base remains clean on PHP 8.4+ without changing the existing CSV escaping behaviour.
- Align Settings Hub integration fixtures with Privileged Access Protection by explicitly approving administrator identities created for tests rather than weakening the production quarantine boundary.
- Re-pin the release-package update smoke to an earlier canonical `1.0.0-rc1` main baseline (`9786408510d51fa55ccc070ae3dc4aa5a1190856`) instead of the stale pre-normalization `1.0.0-rc2` baseline.

## 1.0.0-rc5 — 2026-09-05

### Modal Foundation confirmation checkbox gate

- Add the public additive `confirmCheck: { label }` Modal Foundation option as a required, initially unchecked native acknowledgement gate without changing existing modal result types.
- Centralize confirm-button eligibility so typed confirmation, required input, `confirmCheck`, and async `onConfirm` busy state cannot independently overwrite each other's disabled state.
- Keep `confirmCheck` orthogonal to existing modal modes, with all active gates required before Confirm becomes available and invalid labels failing closed.
- Present the shared gate through both Core Admin and WordPress-native Modal adapters without introducing consumer-specific modal code or business logic.
- Keep the public Core API version and database schema version at `1.0`; stable `1.0.0` remains a separate explicit approval gate after staging/manual validation.

## 1.0.0-rc4 — 2026-09-05

### Golden Standard release-quality closure

- Promote the next testable Base candidate to `1.0.0-rc4`; stable `1.0.0` remains a separate explicit approval gate after staging/manual validation.
- Align the locale allowlist and endonym labels with all seven shipped languages: EN, NL, DE, FR, ES, IT and PT.
- Continue the release-quality architecture cleanup identified by the Golden Standard QC without changing the public API version (`1.0`) or database schema version (`1.0`).
- Keep Failsafe hardening out of rc4 unless the previously identified abuse concern is reproduced as a production defect.

## 1.0.0-rc3.41 — 2026-09-04

### BASE-V1-H — Final Base release candidate

- Mark rc3.41 as the controlled final-RC candidate for the Base v1 release-closure cycle; stable `1.0.0` remains a separate explicit approval gate after staging/manual validation.
- Add an allowlist-based deterministic production ZIP builder that always packages below the canonical `core-blueprint/` root and emits a SHA-256 checksum.
- Exclude repository/test tooling, Composer development metadata, PHPUnit configuration and GitHub workflow files from public release packages by construction rather than by cleanup after archiving.
- Add CI package-boundary validation for required runtime payload, canonical root, version consistency and packaged PHP syntax.
- Add real WordPress 7.0 / PHP 8.4 release-package smoke coverage for both a fresh rc3.41 ZIP install/activation and an update from pinned canonical rc3.40 to rc3.41 while preserving active state.
- Keep Base production domain/security behavior unchanged in H apart from the rc3.41 version marker.

### v1 hardening completed after rc3.28

The rc3.29-rc3.40 development interval was dominated by the structured BASE-V1 release-hardening roadmap rather than feature expansion. Major closure work included:

- reproducible PHPUnit/WordPress CI foundations across WordPress 7.0/7.1 and PHP 8.4/8.5;
- request-boundary lifecycle and data-ownership scenarios plus isolated destructive uninstall coverage;
- canonical Extension Starter source/consumer conformance and the full optional-module enable/disable/re-enable matrix;
- privileged-request, filesystem-recovery, Role Policy/Failsafe, Scanner/provenance and Media Replace durable-state conformance;
- removal of duplicated activation authority and centralization of extension lifecycle ownership;
- centralization of Core Admin asset-hook ownership and request-aware Content Models bootstrap boundaries;
- measured performance attribution, query-source provenance and targeted HUD update-transient cache priming without adding Base-owned cache state;
- public-API freeze/open-source hygiene, including the canonical root GPL license file.

The complete pre-H development history through `1.0.0-rc3.28` is preserved verbatim in [`CHANGELOG-HISTORY.md`](CHANGELOG-HISTORY.md).
