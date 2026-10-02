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
require human review or a product discussion. The product decision for Base v1
is to submit the canonical plugin with Managed Snippets included rather than
silently remove or fork the feature.

Submission notes should call this out proactively. Explain that Snippets is one
optional module inside a broader governance, security and administration
plugin, that it is disabled by default, and that executable-code mutations are
restricted by the controls above. If the Plugin Review Team requires a product
change, handle that as an explicit review outcome rather than pre-emptively
shipping a different WordPress.org build.

## Suggested reviewer note

Core Blueprint includes an optional Managed Snippets module for PHP, JavaScript,
CSS and HTML. The module is disabled by default and is not required for the
plugin's primary governance, security or administration functionality.

Snippet mutation is restricted to trusted Core Blueprint operators or identities
with WordPress code-management authority, respects WordPress file-modification
policy, validates PHP before storage, integrity-checks managed code before
runtime, imports snippets disabled by default, audit-logs mutations, and
provides runtime auto-disable and an emergency server-side stop.

We are highlighting this implementation explicitly so the Plugin Review Team can
review the capability and its safeguards in context.

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
