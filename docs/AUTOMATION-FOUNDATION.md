# Automation Foundation — Core API v1

Core Blueprint Base exposes a small interoperability layer for automation-capable plugins. It lets first-party Core Blueprint extensions and third-party WordPress plugins declare stable **triggers**, **actions** and **state capabilities** without depending on the optional Core Blueprint Automations product.

Automation Foundation is intentionally not a workflow engine.

## Ownership

- Domain plugins own business semantics and decide when a trigger is true.
- Domain plugins own action implementations and their mutation rules.
- Domain plugins own read-only live-state resolution for their own records and services.
- Base owns registration, validation, provider identity, discovery, the thin trigger-delivery boundary and governed state/action invocation.
- Core Blueprint Automations, when installed, owns workflows, conditions/operators, persistence, scheduling, retries, concurrency and run history.
- Base does not persist emitted automation events or invocation runs and does not guarantee retry delivery.

The architectural rule is:

> Extensions own business semantics. Base owns the interoperability contract. Automations owns orchestration.

## Provider identity

Third-party capabilities must belong to a plugin already registered through `CoreBlueprint\Core\ExtensionRegistry`. This prevents anonymous or accidental capability ownership.

Attach the extension registration callback during normal plugin loading:

```php
use CoreBlueprint\Core\ExtensionRegistry;

add_action( 'cb_core_register_extensions', static function (): void {
    ExtensionRegistry::register( [
        'id'            => 'acme-reservations',
        'plugin_file'   => plugin_basename( __FILE__ ),
        'requires_api'  => '1.0',
        'requires_base' => '1.0.0-rc1',
        'menu_url'      => '',
        'status_id'     => '',
    ] );
} );
```

`core-blueprint` is a reserved provider identity for Base-owned capabilities. Public extension registration cannot claim it. Base subsystems use internal `register_base()` paths instead.

## Capability registration lifecycle

Attach one callback during plugin loading. Automation Foundation invokes it once, lazily, after WordPress `init` has completed and after Base's canonical ExtensionRegistry collection.

```php
use CoreBlueprint\Core\Automation\ActionRegistry;
use CoreBlueprint\Core\Automation\InvocationContext;
use CoreBlueprint\Core\Automation\StateRegistry;
use CoreBlueprint\Core\Automation\TriggerRegistry;

add_action( 'cb_core_register_automation_capabilities', static function (): void {
    TriggerRegistry::register( [
        'provider'       => 'acme-reservations',
        'id'             => 'reservation.confirmed',
        'label'          => __( 'Reservation confirmed', 'acme-reservations' ),
        'description'    => __( 'A reservation reached its confirmed state.', 'acme-reservations' ),
        'schema_version' => '1',
        'payload_schema' => [
            'reservation_id' => [
                'type'     => 'integer',
                'required' => true,
            ],
            'customer_id' => [
                'type' => 'integer',
            ],
        ],
    ] );

    ActionRegistry::register( [
        'provider'            => 'acme-reservations',
        'id'                  => 'reservation.add_note',
        'label'               => __( 'Add reservation note', 'acme-reservations' ),
        'description'         => __( 'Adds a note through the reservations domain service.', 'acme-reservations' ),
        'schema_version'      => '1',
        'input_schema'        => [
            'reservation_id' => [
                'type'     => 'integer',
                'required' => true,
            ],
            'note' => [
                'type'      => 'string',
                'required'  => true,
                'sensitive' => true,
            ],
        ],
        'output_schema'       => [],
        'required_capability' => 'edit_posts',
        'executor'            => static function ( array $input, InvocationContext $context ): array {
            // Call the plugin's own public/domain service here.
            // Do not bypass its domain authorization or persistence rules.
            return [];
        },
    ] );

    StateRegistry::register( [
        'provider'            => 'acme-reservations',
        'id'                  => 'reservation.current',
        'label'               => __( 'Current reservation state', 'acme-reservations' ),
        'description'         => __( 'Reads current reservation facts.', 'acme-reservations' ),
        'schema_version'      => '1',
        'input_schema'        => [
            'reservation_id' => [
                'type'     => 'integer',
                'required' => true,
            ],
        ],
        'output_schema'       => [
            'status' => [
                'type'     => 'string',
                'required' => true,
            ],
            'customer_email' => [
                'type'      => 'string',
                'sensitive' => true,
            ],
        ],
        'required_capability' => 'read',
        'resolver'            => static function ( array $input, InvocationContext $context ): array {
            // Query the plugin's own read model/service. Do not mutate state.
            return [
                'status'         => 'confirmed',
                'customer_email' => 'customer@example.test',
            ];
        },
    ] );
} );
```

The second `InvocationContext` callback argument is optional for existing provider callbacks. Userland callbacks that declare only the original input argument remain valid; context-aware providers may declare the second argument when they need correlation or execution metadata.

Malformed, duplicate and unknown-provider definitions fail closed instead of overwriting an existing capability.

## Capability IDs

Trigger, action and state IDs use dotted lowercase identifiers, for example:

```text
reservation.confirmed
reservation.add_note
reservation.current
```

Within one capability kind, the provider and capability ID form the registry identity:

```text
acme-reservations::reservation.confirmed
```

Capability kinds are separate namespaces. A provider may therefore expose the same dotted ID as a trigger, action and/or state capability when those contracts genuinely share a domain name.

A persisted orchestration reference must consequently include the capability kind as well as provider, ID and expected schema version, for example:

```text
kind:           state
provider:       acme-reservations
id:             reservation.current
schema_version: 1
```

Treat `kind + provider + id` as the persistent semantic identity. Labels and descriptions may change; an existing identity must not be repurposed for different semantics.

## Schema versions

Every trigger, action and state capability declares a positive integer schema version as a string:

```text
1
2
3
```

Increase it when the transport contract changes incompatibly. A label or description change alone does not require a schema-version change.

Automation consumers must persist the expected schema version with the capability reference and must not silently reinterpret a workflow configured for an incompatible contract version. `ActionInvoker` and `StateInvoker` compare that expected version with the currently registered contract before entering provider code.

## Transport schemas

The Foundation deliberately uses a bounded transport vocabulary. Supported field types are:

- `string`
- `integer`
- `number`
- `boolean`
- `array` containing only one declared scalar item type

A field definition may use:

```text
type
required
sensitive
items
semantic_type
```

`items` is required only for `array` fields and may be `string`, `integer`, `number` or `boolean`.

`semantic_type` is optional interoperability metadata. It gives a scalar or flat-list value a stable domain meaning without changing the primitive transport type or runtime payload representation. Use it when primitive type alone is too broad to describe safe binding compatibility.

Semantic types use stable dotted identifiers such as:

```text
wp.user_id
acme-reservations.reservation_id
core-blueprint.source_title
```

The first segment identifies the owning/shared namespace; subsequent segments describe the semantic value. Provider-specific semantics should use the provider's stable namespace. Shared cross-plugin semantics may use a shared namespace only when the meaning is intentionally reusable across providers.

A semantic type is part of the interoperability contract, not a PHP/runtime type. A field declared as:

```php
[
    'type'          => 'integer',
    'semantic_type' => 'wp.user_id',
]
```

still carries a normal integer at runtime. `semantic_type` is used by automation consumers for discovery and binding compatibility; `Schema::validate()` continues to validate the primitive transport value.

Legacy field schemas without `semantic_type` remain valid. Adding a semantic type narrows the intended binding meaning of that field, so providers should treat semantic identity changes with the same care as other contract changes and update `schema_version` when compatibility would be affected.

Nested objects, `WP_User`, `WP_Post`, arbitrary domain objects, resources and nested arrays are rejected. Prefer stable IDs and necessary immutable facts over copied domain records.

Unknown runtime payload fields also fail validation. Producers therefore cannot silently expand a payload without updating its declared contract.

### Sensitive fields

`sensitive: true` is **classification metadata**. It tells an orchestration consumer that the value requires careful handling if a run is persisted or logged.

It does not redact, hash or mutate an in-request value. Domain execution/resolution must receive and return the exact validated value required by its contract. Consumers are responsible for privacy-safe persistence and logging policies.

## Emitting triggers

Emit only after WordPress `init` has completed:

```php
use CoreBlueprint\Core\Automation\Emitter;

$result = Emitter::emit(
    'acme-reservations',
    'reservation.confirmed',
    [
        'reservation_id' => 481,
        'customer_id'    => 92,
    ],
    'reservation:481:confirmed:1'
);

if ( is_wp_error( $result ) ) {
    // Handle the rejected emission according to the domain workflow.
}
```

The optional event ID should be a stable domain-event identifier when one exists. This gives an orchestration runtime a deterministic deduplication key. When omitted, Base generates a UUID for that emission.

A successful emission dispatches one immutable `CoreBlueprint\Core\Automation\TriggerEvent` on:

```text
cb_core_automation_trigger_emitted
```

The event exposes:

```text
event_id()
provider()
trigger_id()
schema_version()
payload()
occurred_at()
```

This is an in-request delivery boundary only. Base does not store, queue or retry the event.

## Event versus live state

Triggers and state capabilities solve different problems.

A trigger describes something that happened:

```text
invoice.became_overdue
contract.signed
booking.confirmed
```

A state capability reads what is true now:

```text
invoice.current
crm.contact.current
booking.current
```

An orchestration runtime can therefore react to an event and then query current domain state before deciding what to do. It does not need to duplicate the domain record into its own persistence.

For example:

```text
Trigger:  invoice.became_overdue
State:    invoice.current(invoice_id)
Operator: status == overdue AND balance > 0
Action:   mail.send_reminder
```

The provider owns `invoice.current`. The Automations product owns operators such as equals, contains, greater-than and branching logic.

## Governed invocation context

State and action invocation require an explicit immutable `CoreBlueprint\Core\Automation\InvocationContext`. The launch contract carries:

```text
principal_user_id()
source()
correlation_id()
run_id()
step_id()
attempt()
workflow_id()        // optional
workflow_revision()  // optional
```

`principal_user_id` is the WordPress user whose authority the orchestration consumer is deliberately using for this execution. Base does **not** use `current_user_can()` as the authority boundary, because background execution may run without an ambient logged-in user. Each invocation re-checks the explicit principal with WordPress `user_can()`.

Invocation fails closed when the principal is missing, no longer exists, or no longer has the capability declared by the provider. Persisting or selecting the principal is an orchestration concern; Base does not create service accounts or delegated identities in this contract.

`source`, workflow and correlation values are execution metadata, not proof of authority. Providers must not treat a caller-supplied source string as a security credential.

## State capability boundary

State resolvers are **read-only domain queries**. They must not create records, update status, send mail or perform other mutations.

Public discovery continues to expose only:

```text
provider
id
label
description
schema_version
input_schema
output_schema
required_capability
```

The resolver itself remains private. Official orchestration consumers resolve state only through `CoreBlueprint\Core\Automation\StateInvoker`:

```php
use CoreBlueprint\Core\Automation\InvocationContext;
use CoreBlueprint\Core\Automation\StateInvoker;

$context = new InvocationContext(
    $principal_user_id,
    'automations',
    $correlation_id,
    $run_id,
    $step_id,
    $attempt,
    $workflow_id,
    $workflow_revision
);

$result = StateInvoker::resolve(
    'acme-reservations',
    'reservation.current',
    '1',
    [ 'reservation_id' => 481 ],
    $context
);
```

Before the resolver runs, Base verifies the registered schema version, explicit principal, required capability and input schema. After it runs, Base validates the output schema. Provider `WP_Error` results and thrown `Throwable` failures are normalized to a safe machine-readable failure without exposing provider/exception details.

External code must not use reflection or internal Foundation classes to obtain a resolver.

## Action execution boundary

The executor itself remains private. Official orchestration consumers invoke actions only through `CoreBlueprint\Core\Automation\ActionInvoker`:

```php
use CoreBlueprint\Core\Automation\ActionInvoker;

$result = ActionInvoker::invoke(
    'acme-reservations',
    'reservation.add_note',
    '1',
    [
        'reservation_id' => 481,
        'note'           => 'Follow up next week.',
    ],
    $context
);
```

Before the executor runs, Base verifies the registered schema version, explicit principal, required capability and input schema. After it runs, Base validates the output schema. Provider `WP_Error` results and thrown `Throwable` failures are normalized; exception messages and provider error data are not returned across the normal invocation boundary.

Base does not retry, queue, schedule, deduplicate or persist the action. Those concerns remain with the orchestration runtime and provider/domain design. External code must not use reflection or internal Foundation classes to obtain an executor.

### Invocation errors

Governed invocation failures use stable `WP_Error` codes so orchestration consumers can persist machine-readable outcomes:

```text
cb_core_automation_not_ready
cb_core_automation_unknown_action
cb_core_automation_unknown_state
cb_core_automation_schema_mismatch
cb_core_automation_principal_missing
cb_core_automation_principal_invalid
cb_core_automation_permission_denied
cb_core_automation_invalid_input
cb_core_automation_execution_failed
cb_core_automation_invalid_output
```

When a provider deliberately returns `WP_Error`, Base returns `cb_core_automation_execution_failed` with only the sanitized provider error code in `provider_error_code`. Provider messages/data and thrown exception details are not propagated.

## Early emission, discovery and invocation

The Foundation intentionally refuses trigger emission and governed invocation before the WordPress `init` lifecycle has completed. It returns:

```text
cb_core_automation_not_ready
```

Capability discovery also waits for the same canonical lifecycle. This preserves Base's existing extension-registration timing and prevents an early-loaded plugin from freezing the provider/capability inventory before later plugins have registered themselves.

A domain event that happens before this boundary must not be worked around by forcing registry collection early.

## Discovery

Consumers can inspect registered metadata through:

```php
use CoreBlueprint\Core\Automation\ActionRegistry;
use CoreBlueprint\Core\Automation\StateRegistry;
use CoreBlueprint\Core\Automation\TriggerRegistry;

$triggers = TriggerRegistry::all();
$actions  = ActionRegistry::all();
$states   = StateRegistry::all();

$trigger = TriggerRegistry::get( 'acme-reservations', 'reservation.confirmed' );
$action  = ActionRegistry::get( 'acme-reservations', 'reservation.add_note' );
$state   = StateRegistry::get( 'acme-reservations', 'reservation.current' );
```

Public action discovery excludes the executor. Public state discovery excludes the resolver. Discovery is metadata, not execution authority. `ActionInvoker` and `StateInvoker` are the governed execution boundaries.

## No hard dependency on Core Blueprint Automations

A plugin may implement Automation Foundation support with only Core Blueprint Base installed. If the optional Automations plugin is absent, trigger/action/state registration remains harmless and ordinary plugin behavior must continue normally.

Do not require, import or call classes from `wp-core-blueprint-automations` merely to expose capabilities. The invocation context is a Base value object and does not depend on an Automations class.

## Non-goals of Base

Automation Foundation does not provide:

- workflow definitions or workflow storage;
- a visual workflow builder;
- condition/operator evaluation;
- queues or workers;
- scheduled or delayed jobs;
- retries;
- branching or loops;
- concurrency control;
- run history;
- direct public access to provider executors/resolvers;
- webhooks or external connectors.

Those concerns belong to the optional orchestration product, not to Base.
