# Base development ZIP (internal sandbox only)

**Use this when the operator says “build dev” or asks to install a new Base change on their own WordPress sandbox.** Do not start the full public release ceremony for every small UI or test patch.

## One command

From a clean, committed Base checkout with PHP 8.4+, Node and Git available:

```bash
cd ~/Downloads/wp-core-blueprint
./tools/build-dev
```

The script does **not** download WordPress, open MariaDB, start Docker or consume Level 2 receipts. It never invokes `tools/build-release`'s preflight or release gate. It imports the **same canonical packaging logic** directly from that builder, including runtime allowlist, excluded legacy Dompdf autoload, deterministic ZIP metadata, Design module version stamps and full archive-entry/content verification.

Lightweight gates: exact Git HEAD and clean tracked/untracked worktree, all package files actually tracked by Git, PHP >=8.4, syntax for packaged PHP/JS (parallel), canonical read-only translations check, no symlinks in the runtime payload, exact source stability before/after packaging, ZIP byte integrity and SHA-256. This is still **NOT** WordPress runtime verification, Level 2/3, CI, public release or Golden.

Output is confined to `dist/dev/`:

```text
core-blueprint-<version>-dev-<12-char-head>.zip
core-blueprint-<version>-dev-<12-char-head>.zip.sha256
core-blueprint-<version>-dev-<12-char-head>.zip.dev.json
```

The `.dev.json` evidence sidecar clearly states `INTERNAL TEST ONLY - NOT PUBLIC RELEASE VALIDATED`, exact source SHA and runtime manifest hash, ZIP SHA-256, checked gate counts and omitted runtime/release gates. The archive itself preserves WordPress's canonical plugin directory `core-blueprint/` and plugin version/header to allow installation on the sandbox. The dev label is an **external artifact/evidence identity**, not a fake WordPress plugin-header version.

The command never overwrites `dist/core-blueprint-<version>.zip` or its public checksum. If the same dev SHA ZIP already exists, an identical deterministic rebuild is allowed; divergent bytes/receipts are a hard failure, not silently overwritten.

## Quick install/test loop

1. Make a small reviewed change, commit it to the development branch, run targeted regressions relevant to the modified module.
2. Run `./tools/build-dev`. The output ZIP is suitable for the owner's controlled WordPress **sandbox**, not public distribution.
3. Install/update the ZIP on the sandbox. Check the changed behavior and report any defect.
4. Repeat targeted tests + dev ZIP for follow-up patches. No new Level 2 testslot for small visual polish by default.

Changes to security, permissions, settings CAS, durable state, transactional migrations or significant integration contracts still need corresponding real runtime tests **before functional acceptance**. A package can be used to perform that field test but is not its evidence.

## Public release is a different path

For a release meant for external users, the existing `tools/check`, full isolated `tools/check-integration`, canonical `python3 tools/build-release --output-dir dist`, checksum/package checks and all applicable field, consumer, CI and governance gates remain mandatory. Do not relabel a dev ZIP as public. Public CI, release, distribution, merge and deployment need separate appropriate approval.

Suite-wide authoritative decision: [WP Suite Overseer DEV BUILD vs PUBLIC RELEASE](https://github.com/christiaanbruinsma/wp-core-blueprint-overseer/blob/docs/designer-foundation-golden-no-legacy-20261016/decisions/2026-10-19-suite-dev-build-vs-public-release-contract.md). Normative development workflow: [Engineering Handbook Fast Feedback and Release Checkpoints](https://github.com/christiaanbruinsma/wp-core-blueprint-engineering-handbook/blob/docs/base-validation-one-block-and-slot-lifecycle-20261019/docs/development/fast-feedback-release-workflow-v1.md).

**Other extensions:** adopt the same four-lane *meaning* but use product-owned dev wrappers with their own tested package roots/allowlists. Never assume a Base-only tool can build an extension.
