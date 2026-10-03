# Detail Rows Foundation

Status: **public v1 Foundation contract**.

## Purpose

`CoreBlueprint\Core\UI\DetailRows` is the Base-owned Core Admin presentation primitive for compact object, target or resource rows that belong inside a consumer-owned section or card.

It standardizes the repeated presentation pattern:

**name/detail → optional status → optional action**

Typical consumers include template mappings, backup destinations, license assignments, service endpoints, storage providers, scheduled jobs and connected resources.

`DetailRows` is deliberately not integration-specific. Integration/provider-level readiness remains owned by `CoreBlueprint\Core\UI\IntegrationGrid`.

## Public renderer

```php
CoreBlueprint\Core\UI\DetailRows::render( array $items ): string
```

Each item accepts:

- `name` — required, non-empty scalar, caller-localised;
- `description` — optional scalar detail text, caller-localised;
- `status` — optional existing `Status` semantic: `active`, `ready`, `warning`, `error` or `idle`;
- `status_label` — required, non-empty and caller-localised when a valid status is supplied;
- `action_url` — optional CTA URL;
- `action_label` — optional caller-localised CTA label.

Base escapes all consumer text and URLs. The API does not accept raw consumer HTML.

## Fail-closed normalization

- non-array items are omitted;
- empty or missing `name` omits the row;
- an unknown status is never coerced into another meaning;
- missing/empty `status_label` means no status is rendered;
- invalid/incomplete optional status metadata does not discard an otherwise valid row;
- `action_url + action_label` renders the CTA;
- a partial CTA renders no action;
- zero valid rows returns an empty string.

## Ownership boundary

Base owns:

- wrapper and row markup/anatomy;
- row spacing and separators;
- responsive desktop-to-mobile stacking;
- name/detail presentation;
- Status placement;
- CTA/action alignment;
- hover/focus presentation;
- dark/light behavior through Base tokens;
- internal dependencies on the existing Status and Button primitives.

The consumer owns:

- section/card context;
- which rows exist;
- names and descriptions;
- status/readiness/configuration logic;
- caller-localised status labels;
- action destinations and labels;
- all product/domain semantics;
- any additional domain-specific guidance adjacent to the rows.

Base must not learn concrete Communities, LMS, Bricks, backup, licensing, storage or other provider/domain semantics through this primitive.

## Composition

`DetailRows` does not render an outer card. Consumers compose it inside an existing Base `Card` or another appropriate section context.

Example:

```php
$rows = \CoreBlueprint\Core\UI\DetailRows::render( [
    [
        'name'         => __( 'Course Single template', 'consumer-text-domain' ),
        'description'  => __( 'Assigned template', 'consumer-text-domain' ),
        'status'       => 'active',
        'status_label' => __( 'Ready', 'consumer-text-domain' ),
        'action_url'   => $edit_url,
        'action_label' => __( 'Edit', 'consumer-text-domain' ),
    ],
] );

$card = \CoreBlueprint\Core\UI\Card::render( [
    'title' => __( 'Template setup', 'consumer-text-domain' ),
    'body'  => $rows . $consumer_owned_guidance,
] );
```

The consumer remains free to add domain-specific guidance in the same card. That guidance is not part of the Detail Rows contract.

## Integration Grid boundary

The two primitives solve different levels of presentation:

- `IntegrationGrid` = provider/integration-level readiness;
- `DetailRows` = concrete object/target/resource-level details inside a consumer-owned context.

Consumers must not model nested setup targets as fake `IntegrationGrid` entries and Base must not extend `IntegrationGrid` with nested target/domain semantics.

## Semantic component requirement

Registered Core Admin pages request:

```php
[ 'components' => [ 'detail-rows' ] ]
```

The semantic requirement owns the internal Detail Rows stylesheet plus the existing Status and Button presentation dependencies. Consumers must not depend on private Base asset handles or filenames.

A page may declare additional semantic components when other surfaces on that page genuinely require them.

## JavaScript

Detail Rows is a PHP/CSS presentation primitive. It introduces no JavaScript runtime.
