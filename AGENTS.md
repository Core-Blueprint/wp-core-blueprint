# Core Blueprint Base | Agent entry point

This is the Core Blueprint Base product repository. Its installed runtime
and public Core API remain the normative technical contract.

Before editing, testing, staging or recommending workstation commands:

1. Inspect this exact repository, feature branch, Git SHA and local status.
2. Read the private first-party
   [Engineering Handbook](https://github.com/christiaanbruinsma/wp-core-blueprint-engineering-handbook)
   `AGENTS.md`, applicable Golden Standards, and
   `docs/release-qc/base-local-validation-runbook.md`.
3. Apply the Handbook's `local-build-docker-safety-runbook.md` **before**
   proposing Docker, database, WordPress fixture, Git worktree or build changes.
4. Inspect the actual executable source and `tools/README.md`;
   do not infer flags, interpreters or path defaults from prior chats.
5. Use the product's canonical Level 1 `./tools/check`, guarded Level 2
   `./tools/check-integration`, and Python Level 3
   `python3 tools/build-release`. A source-only PASS is not Golden.
6. Verify exact-head source/runtime parity and report separately:
   Level 1, Level 2, Level 3, CI, field acceptance and merge approval.

The Base Level 2 entrypoint intentionally requires read-only `--preflight`
and `--dry-run` before separately approved `--execute` for a *new*
isolated workspace/database. Do not start/reset/replace Docker, delete
test resources, adopt `wordpress_test` or bypass a collision. Existing
workspaces, fixtures and volumes are protected until the operator proves
their ownership and approves a specific mutation.

The Engineering DX candidate introduces proof-backed **same-HEAD**
Level 2-to-Level 3 environment selection. It does not yet implement
cross-HEAD database reuse or generic suite-wide tooling. Check the
actual source branch before claiming availability.

Do not run CI/Actions, merge, tag, deploy, send mail or publish without
the operator's separate explicit GO. Keep handover commands concise,
canonical and based on actual current source rather than guessed
recovery steps.

If the Handbook connection is unavailable, report the missing authority;
do not invent a replacement policy.
