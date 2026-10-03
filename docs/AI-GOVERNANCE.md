# AI Governance

Core Blueprint Base owns the canonical governance evidence boundary for WordPress abilities, agents and machine integrations.

AI Governance is **not** an AI provider, chat interface, agent runtime or policy engine. Its responsibility is WordPress-native evidence, observability, retention and audit export across Abilities, the WordPress AI Client, supported machine integrations and consumer-reported operations.

## Governing principles

### Evidence over inference

Unknown attribution is valid evidence.

Core Blueprint does not infer that:

- an Ability invocation came from AI;
- a REST request came from MCP;
- an MCP request came from a specific provider, model or client;
- an operation was approved unless an integration supplies reliable approval evidence.

The automatic WordPress Abilities observer records the execution facts WordPress exposes. Source identity is populated only when a concrete integration/request boundary supports it.

### Metadata first

Automatic Ability capture does not retain raw Ability inputs or outputs.

It records bounded metadata such as:

- value type;
- string byte length;
- array item count;
- object class;
- `WP_Error` codes;
- operation/Ability identity;
- actor identity where WordPress provides one;
- source/transport evidence where reliably known;
- outcome and capture state;
- duration;
- bounded structured evidence/context.

Raw prompts, responses, request bodies, authorization material, cookies and secret-bearing fields are not part of the automatic evidence model.

Consumer-reported context passes through the AI Governance privacy boundary and Base's canonical governance sanitizer/redactor before storage.

## Canonical datastore

Base owns the dedicated table:

`{$wpdb->prefix}cb_core_ai_activity`

The store has its own schema marker:

`cb_core_ai_activity_db_version`

This schema is intentionally independent from `CB_CORE_DB_VERSION`. The existing global Base DB marker remains the audit-log schema marker and is not bumped merely because AI Activity adds a dedicated governed store.

AI Activity is registered with Base's `RetentionStoreRegistry` and is pruned by the canonical daily retention runner.

Default retention is 365 days. `0` means retain indefinitely.

## Evidence model

Each activity has a stable opaque UUID plus these first-class dimensions:

| Field | Meaning |
| --- | --- |
| `actor_user_id`, `actor_user_login` | Current WordPress actor where available. Base resolves this itself. |
| `correlation_id`, `parent_activity_id` | Opaque request-local trace identifiers created only from directly observed nesting. They are never reconstructed heuristically. |
| `operation_type` | `ability`, `ai-client`, `mcp-request`, or `operation` for consumer-reported activity. |
| `operation` | Stable Ability/operation identifier. |
| `transport` | Observed execution boundary such as `php`, `rest`, `cli`, `mcp-http`, `mcp-stdio`, or `reported`. |
| `source_id`, `source_label` | Integration/source only when reliably attributed; otherwise null. |
| `provider_id`, `provider_label` | WordPress AI Client provider metadata when the AI Client supplies it. |
| `model_id`, `model_label` | WordPress AI Client model metadata when the AI Client supplies it. |
| `outcome` | What is known about the terminal result: `unknown`, `succeeded`, `failed`, `denied`, `invalid`, `short-circuited`. |
| `capture_state` | Strongest lifecycle evidence observed for that record. |
| `target_*` | Optional target metadata supplied by a trusted consumer/integration. |
| `duration_ms` | Measured duration where a start and terminal boundary are both observable. |
| `error_code` | Bounded machine-readable error code when available. |
| `evidence` | Bounded structured capture evidence. |
| `context` | Bounded structured consumer context. |
| `created_at`, `completed_at` | Observation and terminal timestamps. |

`outcome` and `capture_state` are intentionally separate. A record can therefore truthfully say that execution passed authorization on WordPress 7.0 while the final outcome remains unknown because that WordPress version exposes no common callback-result hook for a failed execution.

## WordPress Abilities capture matrix

### WordPress 7.0

The common platform actions available to Base are:

- `wp_before_execute_ability` — after input validation and permission checks pass;
- `wp_after_execute_ability` — after successful execution and output validation.

Base can therefore prove:

- authorized execution start;
- successful completion;
- actor/source/transport evidence available at those boundaries;
- elapsed duration for successful calls.

Through the common 7.0 actions alone Base cannot globally prove:

- raw invocation attempts that fail before authorization;
- permission denials;
- input-validation failures;
- callback `WP_Error` outcomes;
- output-validation failures;
- pre-execution short-circuits (the common short-circuit filter is a WordPress 7.1 addition).

A 7.0 Ability that passed authorization but returned before the success action therefore remains `outcome=unknown` with its strongest observed `capture_state` rather than being guessed as failed.

### WordPress 7.1

Base additionally observes the official 7.1 lifecycle surface:

- `wp_ability_invoked`;
- `wp_pre_execute_ability`;
- `wp_ability_normalize_input`;
- `wp_ability_validate_input`;
- `wp_ability_permission_result`;
- `wp_ability_execute_result`;
- `wp_ability_validate_output`;
- plus the existing before/after actions.

This permits broader evidence for:

- every Ability invocation attempt;
- invalid/normalization failures;
- permission denial;
- short-circuit results;
- execute-callback failures including `WP_Error`;
- output-validation failures;
- successful completion.

The observer uses the common argument subset for the 6.9/7.0 before/after actions so its callbacks remain valid on both supported WordPress versions even though WordPress 7.1 adds the Ability instance as an extra action argument.

## WordPress AI Client capture

WordPress 7.0 exposes generation lifecycle actions through the provider-agnostic AI Client. Base observes:

- `wp_ai_client_before_generate_result`;
- `wp_ai_client_after_generate_result`.

For a completed generation Base records:

- the AI Client capability;
- provider ID and label;
- model ID and label;
- message count, never message content;
- candidate count when available;
- prompt, completion, total and thought token counts when available, stored as bounded usage-count metadata rather than under secret-like token keys;
- observed transport, duration and actor.

Base also registers forward-compatible listeners for the AI Client embedding lifecycle names produced by WordPress' generic event dispatcher:

- `wp_ai_client_before_generate_embedding`;
- `wp_ai_client_after_generate_embedding`.

These hooks are inert on supported WordPress builds that do not yet bundle embedding lifecycle events. When the official AI Client emits them, Base records only embedding metadata such as input count, embedding count, vector dimensions and token usage. Embedding input values and vector values are never retained.

The automatic observer does **not** retain prompt text, embedding input values, generated content, candidate payloads, embedding vectors, provider response bodies, credentials or arbitrary `additionalData`.

The current WordPress AI Client lifecycle has no common terminal action for provider exceptions that occur after a before-generation or before-embedding event. Such a record therefore remains `outcome=unknown` with `capture_state=generation-started`; Base does not guess that it failed.

## WordPress MCP Adapter request observability

When the official WordPress MCP Adapter creates its canonical default server, Base composes its observability handler through the official `mcp_adapter_default_server_config` filter.

If another default-server observability handler was already configured, Base delegates to it first and then records its own governance evidence. An AI Governance storage failure therefore does not replace the adapter response or suppress another observability integration.

Base records the adapter's `mcp.request` completion event and its bounded request metadata, including where supplied:

- status and MCP method;
- transport;
- server identifiers, numeric request IDs, and site-bound fingerprints for string request IDs and MCP session identifiers;
- negotiated schema revision;
- tool, Ability, prompt or resource identity;
- machine-readable failure reason, error type and error category; free-text failure output is reduced to metadata shape only;
- request duration;
- the adapter's sanitized parameter summary.

Only the documented `success` and `error` request statuses are mapped to succeeded/failed outcomes. An absent or unrecognized future status remains `outcome=unknown`; Base preserves the bounded status evidence instead of guessing a failure.

Base does not turn generic REST traffic into MCP evidence. It also does not infer the identity of ChatGPT, Claude or another client from transport alone.

Server-start and component-registration telemetry are intentionally not copied into AI Activity because they are runtime diagnostics rather than user or agent operations.

## Correlation

Correlation is request-local and evidence-based.

When Base directly observes one governed operation starting inside another governed operation, the child inherits the parent's opaque `correlation_id` and stores the parent's activity UUID as `parent_activity_id`.

Base does not correlate separate MCP and Ability rows by matching timestamps, names or durations. If the platform does not expose a reliable common request identifier at both boundaries, those records remain separate evidence.

## Source and transport attribution

Direct PHP Ability execution is recorded as `transport=php` and source unknown unless another reliable integration boundary reports source metadata.

Generic REST Ability execution is `transport=rest`; REST alone is not AI or MCP evidence.

Generic WP-CLI execution is `transport=cli`; CLI alone is not AI or MCP evidence.

For Ability records, the official WordPress MCP Adapter is attributed automatically only when both of these are true:

1. the official `WP\MCP\Core\McpAdapter` runtime is loaded; and
2. the request is on its canonical default-server boundary:
   - HTTP endpoint `/wp-json/mcp/mcp-adapter-default-server`, or
   - WP-CLI command `mcp-adapter serve`.

That supports source attribution to the **WordPress MCP Adapter** for Ability execution. Separately, the official Adapter observability handler supplies first-class MCP request records for its default server. Neither boundary identifies a provider/model/client such as ChatGPT, Claude or another agent unless the platform supplies reliable evidence for that identity.

## Public consumer API

The public v1 reporting boundary is:

`CoreBlueprint\Core\AIGovernance\Activity::record( array $activity ): string|false`

Use this only when a Core Blueprint extension or integration has governance evidence that is not already captured adequately by the WordPress Abilities observer.

Required keys:

- `operation` — stable operation identifier;
- `outcome` — one of `unknown`, `succeeded`, `failed`, `denied`, `invalid`, `short-circuited`.

Optional keys:

- `transport` — one of `unknown`, `php`, `rest`, `cli`, `mcp-http`, `mcp-stdio`, `reported`;
- `source_id`;
- `source_label`;
- `provider_id`;
- `provider_label`;
- `model_id`;
- `model_label`;
- `target_type`;
- `target_id`;
- `target_label`;
- `duration_ms`;
- `error_code`;
- `evidence` — bounded associative metadata;
- `context` — bounded associative metadata.

Example:

```php
use CoreBlueprint\Core\AIGovernance\Activity;

$activity_id = Activity::record( [
    'operation'    => 'my-plugin/content-operation',
    'outcome'      => 'succeeded',
    'transport'    => 'reported',
    'source_id'    => 'my-plugin-agent-adapter',
    'source_label' => 'My Plugin Agent Adapter',
    'provider_id'  => 'provider-id-if-known',
    'model_id'     => 'model-id-if-known',
    'target_type'  => 'post',
    'target_id'    => (string) $post_id,
    'evidence'     => [
        'approval_id' => $approval_id,
    ],
] );
```

The return value is the opaque activity UUID, or `false` when the report is rejected or cannot be stored.

### Public API rules

Consumers must not:

- reach into `Repository` or Base table internals;
- supply or spoof a WordPress actor/user ID — Base resolves the current actor;
- send raw prompts/responses/request bodies merely because they are available;
- label an unknown source as AI/provider/client based on heuristics;
- use the reporting API to replace domain authorization.

Consumer business authorization and permission checks remain owned by the consumer/domain operation.

## Admin surface and export

The Base-owned **Core Blueprint → Logs → AI Activity** tab provides:

- an empty state when nothing has been recorded;
- date, actor, source, correlation ID, operation type, transport, provider, model, operation and outcome filters;
- activity list;
- per-record detail view;
- CSV export;
- structured JSON export;
- retention configuration.

The page and export actions require `manage_options` in v1.

Exporting activity and changing retention also emit normal Base Audit Log governance events so those administrator actions remain visible outside the dedicated AI Activity store.

## Failure behavior

AI Governance is an observability layer, not an execution policy engine.

An activity logging/storage failure must not be used to grant, deny or replace an Ability's permission callback. The reporting boundary is fail-safe/non-fatal and automatic observation treats storage as best-effort evidence capture.

## Out of scope for v1

AI Governance does not include provider configuration, prompt/response archives, cost calculation, autonomous agents, approval workflow orchestration, policy enforcement, anomaly detection, Hub aggregation or heuristic AI detection.