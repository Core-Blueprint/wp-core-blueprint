# Interoperability Foundation

Core Blueprint Base exposes a generic interoperability registry for versioned cross-extension contracts and their implementations.

This Foundation is neutral infrastructure. Contract owners own contract meaning and PHP interfaces. In the normal public path that owner is an admitted extension; Base may also publish specialized Base-owned platform contracts internally. Base owns registration, validation, discovery, lifecycle and runtime contract enforcement.

> **Base owns interoperability. Extensions own domain semantics.**

The Foundation does not define Forms, builders, storage, mail, payments, AI or any other product/domain contract by itself.

## Public surface

The public v1 surface is:

```php
CoreBlueprint\Core\Interoperability\Registry
```

Registration occurs through two controlled lifecycles:

```text
core_blueprint_register_interoperability_contracts
core_blueprint_register_interoperability_implementations
```

Base-owned platform contracts are loaded privately before the public contract lifecycle. Extension-owned contracts are then collected before implementations. Registration outside the corresponding public collection lifecycle is rejected.

## Extension identity is canonical

Interoperability does not create a second provider identity system.

An implementation provider must always be a valid registered extension in:

```php
CoreBlueprint\Core\ExtensionRegistry
```

A contract registered through the public `Registry::register_contract()` path must likewise use a valid registered extension as its owner. The extension ID used in Interoperability is the same canonical platform identity used elsewhere in Base.

Base itself may publish a specialized platform contract under the reserved owner identity `core-blueprint` through its private read-only contract catalog. Public extension code cannot claim that owner through `register_contract()`, and there is no public Base-owned contract mutation API. Provider implementations targeting a Base-owned contract still require their own normal `ExtensionRegistry` identity.

An unknown public owner or implementation provider is rejected.

## Define a contract

A domain extension defines a versioned contract during `core_blueprint_register_interoperability_contracts`.

```php
use CoreBlueprint\Core\Interoperability\Registry;

add_action( 'core_blueprint_register_interoperability_contracts', static function (): void {
    Registry::register_contract( [
        'owner'       => 'acme-domain',
        'id'          => 'resource-provider',
        'version'     => '1',
        'label'       => __( 'Resource provider', 'acme-domain' ),
        'description' => __( 'Provides access to Acme resources.', 'acme-domain' ),
        'interface'   => Acme\Domain\Contracts\ResourceProvider::class,
    ] );
} );
```

Accepted contract fields are exactly:

- `owner` — canonical `ExtensionRegistry` ID of the extension that owns the contract;
- `id` — contract ID;
- `version` — exact positive integer version represented as a string;
- `label` — required display label, maximum 120 bytes after normalization;
- `description` — optional display description, maximum 500 bytes after normalization;
- `interface` — existing PHP interface that every resolved implementation must satisfy.

Unknown fields are rejected.

The contract ID must match:

```text
[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*
```

Version `0`, semantic ranges and Composer-style version expressions are not part of v1. Consumers request an exact contract version.

The declared PHP interface must exist when the contract is collected.

Duplicate contract identity is rejected. A contract identity is the combination of:

```text
owner + contract id + exact version
```

Specialized Base-owned contracts are documented by their own Foundation documents and are loaded from Base's private read-only contract catalog before extension-owned contract registration begins. Extensions cannot add to or mutate that catalog.

## Register an implementation

An extension implements a previously collected contract during `core_blueprint_register_interoperability_implementations`.

```php
use CoreBlueprint\Core\Interoperability\Registry;

add_action( 'core_blueprint_register_interoperability_implementations', static function (): void {
    Registry::register_implementation( [
        'provider'         => 'acme-adapter',
        'id'               => 'default',
        'label'            => __( 'Acme adapter', 'acme-adapter' ),
        'description'      => __( 'Provides the Acme resource contract.', 'acme-adapter' ),
        'contract_owner'   => 'acme-domain',
        'contract'         => 'resource-provider',
        'contract_version' => '1',
        'supports'         => [
            'resource.discovery',
            'resource.read',
        ],
        'factory'          => static fn (): Acme\Domain\Contracts\ResourceProvider => new Acme\Adapter\ResourceProvider(),
    ] );
} );
```

Accepted implementation fields are exactly:

- `provider` — canonical `ExtensionRegistry` ID of the implementing extension;
- `id` — implementation ID;
- `label` — required display label, maximum 120 bytes after normalization;
- `description` — optional display description, maximum 500 bytes after normalization;
- `contract_owner` — canonical owner of the target contract;
- `contract` — target contract ID;
- `contract_version` — exact target contract version;
- `supports` — list of supported functional feature tokens;
- `factory` — callable that resolves the runtime implementation object.

Unknown fields are rejected.

The referenced contract must already have been collected. The provider must already be registered through `ExtensionRegistry`. The factory must be callable.

Duplicate implementation identity is rejected. An implementation identity is the combination of:

```text
contract owner + contract id + exact version + provider + implementation id
```

Multiple different providers and/or implementation IDs may implement the same exact contract version.

## `supports` is feature discovery

`supports` describes optional functional support within a contract implementation. It is not a WordPress capability/permission system.

Support tokens use the same lower-case identifier grammar as contract/implementation IDs:

```text
[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*
```

The list must be a PHP list and may contain at most 64 entries. Duplicate values are normalized away and the public descriptor is sorted.

Consumers should use `supports` only for features whose meaning is defined by the owning domain contract. Base does not invent or infer support tokens.

## Discovery

Public contract metadata:

```php
$contracts = Registry::contracts();
$contract  = Registry::contract( 'acme-domain', 'resource-provider', '1' );
```

Public implementation metadata:

```php
$implementations = Registry::implementations();
$implementation  = Registry::implementation(
    'acme-domain',
    'resource-provider',
    '1',
    'acme-adapter',
    'default'
);
```

Discover all implementations of an exact contract version:

```php
$matches = Registry::discover(
    'acme-domain',
    'resource-provider',
    '1'
);
```

Require one or more support tokens:

```php
$matches = Registry::discover(
    'acme-domain',
    'resource-provider',
    '1',
    [ 'resource.discovery', 'resource.read' ]
);
```

A match must satisfy every requested support token.

Invalid identities, invalid support filters or unknown contracts fail closed and return no matches.

## Public descriptors do not expose factories

Implementation discovery returns normalized metadata only.

The `factory` callable is stored separately and is deliberately absent from public implementation descriptors. Consumers must not attempt to recover private factories or service objects through reflection or internal Base state.

This keeps discovery separate from runtime resolution.

## Runtime resolution

Resolve one implementation explicitly:

```php
$provider = Registry::resolve(
    'acme-domain',
    'resource-provider',
    '1',
    'acme-adapter',
    'default'
);
```

The factory result must be an object implementing the PHP interface declared by the contract.

Resolution fails closed with `WP_Error` when:

- the implementation is unknown;
- the contract is unavailable;
- the factory throws;
- the factory result does not satisfy the declared interface.

Consumers must handle `WP_Error` before using the returned object.

## Lifecycle and freeze behavior

Interoperability collection is request-local and occurs only after Base's canonical extension-admission lifecycle has completed.

The order is:

```text
ExtensionRegistry collection
    ↓
Base private read-only contract catalog
    ↓
core_blueprint_register_interoperability_contracts
    ↓
core_blueprint_register_interoperability_implementations
    ↓
registry frozen for the remainder of the request
```

Calling discovery before the registry is ready does not pull extension registration forward.

Collection occurs once per request. Recursive discovery during collection does not dispatch either public registration lifecycle twice.

After canonical collection finishes, late contract or implementation registration is rejected.

Attach registration callbacks during normal plugin bootstrap so they are present when Base dispatches the controlled lifecycle.

## Versioning

Interoperability contract versions are independent of:

- the extension/plugin version;
- `CB_CORE_API_VERSION`;
- the optional Base product-version requirement declared through `ExtensionRegistry`.

Contract v1 uses exact positive integer versions such as `1`, `2`, `3`.

Do not silently change established contract semantics behind the same owner/id/version identity. Publish a new contract version for incompatible changes.

No compatibility-range negotiation or automatic fallback between contract versions is part of v1.

## Ownership rules

The contract owner owns:

- the meaning of the contract;
- the PHP interface;
- the meaning of each support token;
- domain-specific authorization and data semantics;
- versioning decisions for incompatible contract changes.

For public extension-defined contracts, that owner is an admitted extension. For a specialized Base-owned platform contract, Base owns those semantics and documents them in the corresponding Foundation contract.

The implementing extension owns:

- translation from its external/internal system into the contract;
- its runtime implementation object;
- truthful declaration of supported features;
- any dependency checks required to construct its implementation.

Base owns:

- admission through canonical extension identity;
- controlled registration lifecycle;
- the private catalog of Base-owned platform contracts;
- definition validation;
- exact-version discovery;
- support-token filtering;
- separation of public descriptors and private factories;
- runtime interface enforcement;
- fail-closed behavior.

## First-party and third-party parity

Base does not provide a private first-party implementation path.

Official Core Blueprint integrations and third-party integrations use the same Interoperability registry and the same contract rules.

A first-party integration that needs additional shared behavior should drive a deliberate public-contract change rather than depend on an undocumented private Base escape hatch.

## Relationship to Automation Foundation

Automation Foundation remains a specialized Base interoperability surface for provider-owned triggers, states and actions.

Generic Interoperability does not replace:

```php
CoreBlueprint\Core\Automation\TriggerRegistry
CoreBlueprint\Core\Automation\StateRegistry
CoreBlueprint\Core\Automation\ActionRegistry
```

Do not duplicate Automation trigger/state/action semantics inside a generic interoperability contract merely to avoid using Automation Foundation.

Use Generic Interoperability when multiple extensions need to discover and resolve implementations of a shared domain contract. Use Automation Foundation when an extension is exposing automation-capable triggers, read-only states or actions.

## Non-goals

Interoperability Foundation is not:

- a workflow engine;
- an event bus;
- a dependency-injection container;
- a service locator for arbitrary plugin internals;
- a builder-specific integration layer;
- a Forms API by itself;
- a capability/permission registry;
- an automatic compatibility negotiator between contract versions.

Domain contracts and integrations are separate consumers of this Foundation.

## Stable v1 boundary

Only the public methods and registration lifecycles documented in this Foundation are intended as the Generic Interoperability v1 contract.

The private Base-owned contract catalog and its ingestion path are implementation details. No public API exists for extensions to publish a contract under the reserved `core-blueprint` owner.

Private registry state, diagnostic behavior, key construction and testing helpers are implementation details unless separately documented as public API.
