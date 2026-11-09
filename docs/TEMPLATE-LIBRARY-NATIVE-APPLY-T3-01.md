# T3-01 — Native Apply Template Foundation (WP Base)

Status: **SOURCE IMPLEMENTED / QUALIFICATION PENDING**. FRG-04 Foundation Freeze: HOLD.

This document is scoped to the WordPress Core Blueprint Base repository; it does not govern Webapp v2.

## Contract

- Template metadata discovery remains the public `TemplateSourceRegistry` interface. The browser's `onChoose(metadata)` is a selection callback, **not** Apply authority.
- A provider opts in through `TemplateApplySourceInterface`. The provider remains the owner of its own template catalog, document semantics and target authorization. No Base template document store is added.
- `POST /core-blueprint/v1/template-library/apply` is an authenticated **read**, despite its POST method. It requires a valid WordPress REST nonce and a server-issued, actor/session/site-bound `X-CB-Designer-Scope` token for the **exact** native target resource.
- The request carries only `source`, `templateId`, `profileId`, `resourceType`, `resourceKey`, `contextEpoch`. Untrusted metadata does not authorize a document. A registered source must explicitly allow the template for that target; Base enforces current native target authorization and validates the returned complete `{project, fields}` via the first-party domain validator.
- The response is an independent document copy. The server route does **not** mutate or save the target. Existing `PlatformTransport` + `TransactionalSave` remain the sole persistence route.

## Canonical local Apply transaction

The native Host is the only caller of the private `sessionBridge.applyTemplateDocument` and the Host-owned `PersistedFields.prepareReplace`. Under `CommandHistory.applyWithFields`, project, selection, editor state and the complete fields map are staged and committed synchronously as one step, with rollback before publication if either commit fails. Both Undo and Redo restore project **and** fields in canonical History order. Ordinary field edits are also registered in canonical History so Undo ordering stays consistent.

The Host's public editor facade exposes two intent-bound operations:

1. `captureApplyIntent()`: allowed only for the live READY target while Save is in a known clean/dirty state. It returns an opaque, single-use witness.
2. `applyTemplate(reply, intent)`: refuses a stale/reused witness, changed project or fields, intervening History event, wrong actor/site/profile/target/epoch and Save pending/UNKNOWN. It then delegates to trusted project/fields validation and a synchronous History commit.

The public Template Browser's `loadForApply({contextToken,source,templateId,target,signal})` performs the authenticated read. A first-party native consumer must call the Host's intent operations around this read. Do **not** call History directly, install a second SaveCoordinator, or treat `onChoose` as proof of access.

## Scope and next steps

T3-01 is the shared mechanism, not the complete Mail template-management UI. T3-02 will wire the first-party Mail Use Template interaction plus Save as Template, My/Site, Rename, Duplicate and Delete. T4 owns offline import/export through Data Exchange.

At this source checkpoint, canonical Level 1, fresh internal ZIP, WordPress Level 2 and FIELD acceptance have **not** been demonstrated on this exact feature HEAD. Do not claim FRG-04 Freeze or release readiness.
