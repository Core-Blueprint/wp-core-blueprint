# WordPress.org readiness

## Purpose

This branch prepares Core Blueprint Base for WordPress.org review quality without promoting the plugin to stable v1.

Canonical release version remains:

`1.0.0-rc1`

A later explicit release decision owns any change to `1.0.0`.

## Readiness baseline

Current readiness work covers:

- WordPress.org-standard `readme.txt` metadata for the current RC version.
- Dynamic version consistency between the plugin header, `CB_CORE_VERSION`, translation metadata and directory readme.
- Deterministic inclusion of `readme.txt` in the production ZIP.
- External-service disclosure for the optional Brevo transport.
- External-service disclosure for official WordPress.org checksum lookups used by Core Scanner.
- Public source/build-tool location in the directory readme.
- Third-party runtime provenance and GPL-compatible license documentation.
- No Base-owned third-party plugin/theme updater injection.
- Direct-access guards on first-party runtime files and composed translation catalogs.
- A non-conflicting name for the Core Blueprint clipboard runtime asset.
- No HUD optimization that directly primes WordPress update-transient storage.

## Open product blocker

### Managed Snippets

Base currently includes a managed Snippets module that allows authorized administrators to create and execute:

- PHP
- JavaScript
- CSS
- HTML

PHP snippets are written to managed files and included at runtime. JavaScript, CSS and HTML snippets are rendered into configured runtime locations.

The current WordPress.org Plugin Developer FAQ states that new plugins allowing arbitrary code insertion or execution, including PHP or JavaScript editors, are generally not accepted.

This is not treated as a small compliance defect. A product decision is required before WordPress.org submission.

Acceptable directions to evaluate include:

1. Extract Managed Snippets from Base into a separate Core Blueprint extension that is not part of the WordPress.org Base package.
2. Redesign the Base feature so it no longer permits arbitrary executable code.
3. Remove the feature from Base before submission.

Do not create a special WordPress.org-only Base build that silently differs in product scope from the canonical Base release. The canonical product boundary should be decided explicitly.

## Final submission gates

These remain intentionally pending until the product blockers and remaining planned Base development are closed:

- full Base integration suite on the final candidate;
- canonical deterministic release build;
- exact ZIP field test;
- official WordPress Plugin Check against the exact candidate;
- official WordPress readme validation;
- final external-service and bundled-license review;
- final version decision;
- WordPress.org submission and SVN release workflow.

GitHub Actions should not be repeatedly run while the product is still changing. Prefer local gates during development and one controlled final directory check when the candidate is ready.
