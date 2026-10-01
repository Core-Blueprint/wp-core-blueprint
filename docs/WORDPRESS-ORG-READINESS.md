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

## Submission review risk

### Managed Snippets

Managed Snippets remains an intentional part of Core Blueprint Base. It is one
optional administration capability inside a broader standalone governance,
security and administration plugin, not the sole purpose of Base.

The module allows authorized operators to manage PHP, JavaScript, CSS and HTML
snippets, so it deserves explicit review during WordPress.org submission.

The runtime is deliberately bounded:

- the module is disabled by default;
- `cb_manage_snippets` remains a privileged Core Blueprint capability;
- executable-code mutations additionally require either a signed, approved
  CB Operator or native WordPress `install_plugins` plus `unfiltered_html`
  authority;
- WordPress file-modification policy remains authoritative;
- imported snippets remain disabled until reviewed;
- PHP is syntax-validated before storage;
- managed code is integrity-fingerprinted;
- runtime errors auto-disable the affected snippet;
- `CB_CORE_DISABLE_SNIPPETS` provides a server-side emergency stop;
- snippet mutations are audit logged.

WordPress.org review policy for arbitrary executable-code features can still
require human review or a product discussion. Treat this as a submission review
risk, not as a reason to silently remove or fork the canonical Base feature.

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
