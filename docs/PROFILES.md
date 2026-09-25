# Core Profiles v1

Core Profiles is the Core Blueprint Base feature for portable, versioned configuration transfer.

A Profile is not a backup, site clone or raw WordPress options export. It contains only explicitly allowlisted configuration domains that Base can validate and apply through their canonical domain APIs.

## Workflow

1. An approved Core Blueprint Operator selects the portable sections to export.
2. Base writes a bounded `core-blueprint-profile` JSON document with a format version and a schema version per section.
3. On another site the operator uploads the document for preview. Uploading does not apply the configuration.
4. Base validates the complete document, normalizes every included section, runs section preflight checks and calculates a complete diff against one current-state snapshot.
5. The preview is bound to that document and state through a SHA-256 fingerprint and a short-lived per-user token.
6. Apply requires the same approved Operator boundary plus nonce validation, current-password confirmation and an explicit acknowledgement that backup and recovery remain the operator's responsibility.
7. Base acquires an exclusive compare-and-swap apply lease, repeats the complete preview and refuses stale plans.
8. Sections are applied in deterministic order. Portable module activation is last so configuration exists before runtimes are enabled.
9. Every touched section is verified after apply. On failure, Base attempts compensating rollback in reverse order and records rollback failures as critical audit events.

WordPress does not provide one ACID transaction spanning options, roles, rewrite state and module side effects. Profiles therefore implement an application-level transaction with complete preflight, snapshots, verification, concurrency guards and compensating restore.

Core Profiles cannot guarantee compatibility with every WordPress environment. Third-party plugins, themes, custom code and hosting configuration can affect the result of a configuration change. Core Blueprint attempts compensating rollback when an apply step fails, but the operator remains responsible for maintaining a recent backup or another suitable recovery option.

## Portable sections in v1

- Security baseline: Core Shield hardening modules/features and portable Login Shield configuration.
- Privacy & logging: IP policy, audit verbosity and audit-log retention.
- Audit notifications: severity-based notification policy without recipient addresses.
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

Unknown sections, unsupported versions, unknown keys and invalid scalar types fail closed.

## Ownership and extension contract

Core Profiles owns the transport, validation plan, deterministic section ordering, concurrency guard and application transaction. It does not own the canonical settings represented by a section. Every section must read and mutate its domain through that domain's supported state APIs or repositories, and must provide its own normalization, preflight, verification and recovery behavior. Core Setup and other consumers use this same Profile document and Engine contract; they do not write a second copy of module state.

Base sections are registered directly by Base. Official first-party extensions may add portable sections during the controlled `cb_core_register_profile_sections` lifecycle by calling `SectionRegistry::register( $extension_id, $section )`. The extension must already be registered through the canonical Extension Registry and recognized there as first-party Core Blueprint software. Extension section IDs must be namespaced below that extension ID. The extension section registry is collected once, sorted deterministically and then frozen for the request.

The first-party extension boundary is intentionally narrower than a general third-party plugin API. Third-party plugins cannot register arbitrary Profile payloads in v1. This lets Base keep the secrets and portability trust boundary reviewable. A Profile containing a section whose owning extension is unavailable fails closed before mutation. An installed extension may expose portable configuration even while its optional runtime/module is disabled; runtime activation remains a separate concern and Base module activation remains the final built-in apply section.

Portable configuration is policy or reproducible configuration, not runtime identity. Secrets, credentials, recovery material, temporary tokens, user/Operator assignments, approval fingerprints, machine-detected environment state, jobs, evidence/history records, recipient addresses, attachment/assignee IDs and executable code remain outside reusable Profiles.


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
