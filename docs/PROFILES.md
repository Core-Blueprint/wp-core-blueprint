# Core Blueprint Profiles v1

Core Blueprint Profiles provide portable, versioned configuration transfer for Core Blueprint Base.

A Profile is not a backup, site clone or raw WordPress options export. It contains only explicitly allowlisted configuration domains that Base can validate and apply through their canonical domain APIs.

## Workflow

1. An approved Core Blueprint Operator selects the portable sections to export.
2. Base writes a bounded `core-blueprint-profile` JSON document with a format version and a schema version per section.
3. On another site the operator uploads the document for preview. Uploading does not apply the configuration.
4. Base validates the complete document, normalizes every included section, runs section preflight checks and calculates a complete diff against one current-state snapshot.
5. The preview is bound to that document and state through a SHA-256 fingerprint and a short-lived per-user token.
6. Apply requires the same approved Operator boundary plus nonce validation and current-password confirmation.
7. Base acquires an exclusive compare-and-swap apply lease, repeats the complete preview and refuses stale plans.
8. Sections are applied in deterministic order. Portable module activation is last so configuration exists before runtimes are enabled.
9. Every touched section is verified after apply. On failure, Base attempts compensating rollback in reverse order and records rollback failures as critical audit events.

WordPress does not provide one ACID transaction spanning options, roles, rewrite state and module side effects. Profiles therefore implement an application-level transaction with complete preflight, snapshots, verification, concurrency guards and compensating restore.

## Portable sections in v1

- Security baseline: Core Shield hardening modules/features and portable Login Shield configuration.
- Privacy & logging: IP policy, audit verbosity and audit-log retention.
- Core Scanner policy: schedule, scan coverage, visible-finding limit and scanner alerts.
- Content Models schema: user-managed post types, taxonomies, Option Pages and field schemas, without content values.
- Media Formats: image-format policy without site content.
- Notes defaults: default type, status and presentation without notes, users or assignees.
- Reports policy: retention, portable branding text/color and maintenance composer configuration without snapshots, recipient addresses or attachment IDs.
- Permissions policy: visibility, Privileged Access Protection mode, administrator delegations and permission alerts without operators, approvals or identities.
- AI Governance: activity-retention policy without AI activity records.
- Module activation: portable Base module states, applied last.

## Deliberately excluded

Profiles v1 do not carry:

- Mail configuration, SMTP credentials, API keys or sender identities;
- Snippets or other executable code;
- Failsafe/bypass tokens;
- Access Mode state or page IDs;
- `site_mode` (`hub`, `production`, `development`);
- users, user IDs, role assignments, CB Operator assignments or privileged approvals;
- Scanner results, baselines, history, quarantine evidence or active jobs;
- audit-log records, AI activity records, Notes records or Reports snapshots;
- site-specific recipient addresses, media attachment IDs or Notes assignee IDs;
- custom post/term/user content values managed by Content Models.

These exclusions are part of the v1 portability and trust boundary. Adding a domain later requires an explicit reviewed schema rather than exporting its WordPress option wholesale.

## Versioning

The document has its own `format_version`. Every section has an independent `schema_version` and section contract methods for supported-version checks and migrations. A future section schema can therefore migrate an older supported Profile without changing unrelated sections or the outer document format.

Unknown sections, unsupported versions, unknown keys and invalid scalar types fail closed. Base v1 intentionally has no public third-party Profile-section registration hook so Base can guarantee the payload boundary for every exported section.

## Concurrency and rollback

The apply lease uses compare-and-swap ownership and cannot be released by a stale or foreign worker. The lease is refreshed before each section.

Before each mutation Base verifies that the section still matches its preview snapshot. During rollback, exact sections are restored only when their state is still composed of the known pre-apply and target values. A third concurrent value is never overwritten blindly.

Content Models use a targeted restore boundary. Only definitions touched by the Profile are restored or removed. Unrelated local definitions remain untouched, and a touched definition changed to a third state during the transaction causes rollback to fail closed rather than overwrite that concurrent change.

Core Scanner activation also coordinates with the Scanner lock so a Profile cannot silently disable and cancel a scan that started after preview.

## Authorization

The browser workflow is restricted to a signed, approved Core Blueprint Operator with `cb_manage_permissions`. Apply additionally requires a valid action nonce and confirmation of the current account password.

Profiles do not re-authorize each module state against its ordinary Dashboard capability. Those capabilities govern the modules' normal UI surfaces. Within a Profile transaction, the approved Operator boundary is the authorization authority and the existing module state classes remain the canonical persistence adapters.

## Limits

- Maximum Profile JSON size: 1 MiB.
- Maximum sections per document: 32.
- Maximum reviewable configuration changes per apply: 1,000.
- Preview token lifetime: 15 minutes.
- Apply-lock stale lease: 30 minutes, refreshed during apply.

These limits are intentional denial-of-service and human-review boundaries rather than tuning defaults.
