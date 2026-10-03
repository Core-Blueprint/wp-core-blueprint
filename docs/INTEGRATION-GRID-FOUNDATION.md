# Integration Grid Foundation

Status: **public v1 Core Admin Design Foundation contract**.

## Purpose

`CoreBlueprint\Core\UI\IntegrationGrid` is the shared presentation boundary for integration/readiness cards on registered Core Blueprint admin pages.

Base owns the generic visual composition. Consumers own all integration meaning and business logic.

This contract prevents first-party extensions from independently redrawing the same integration surface while keeping Base completely unaware of concrete products, providers, builders or extension domains.

## Ownership boundary

Base owns:

- responsive two-column / one-column grid presentation;
- integration-card markup and surface treatment;
- title/status placement;
- description presentation;
- CTA/footer presentation;
- spacing, borders, hover/focus treatment and dark/light presentation;
- mapping Integration Grid states onto the existing `CoreBlueprint\Core\UI\Status` primitive.

Consumers own:

- which integrations exist;
- detection and readiness logic;
- integration name and description;
- status meaning selected from the four public Integration Grid states;
- visible, caller-localised status label;
- optional configure/action URL and label;
- all domain rules and dependencies.

Base must not infer provider, plugin, builder or product semantics from an Integration Grid item.

## Public renderer

```php
use CoreBlueprint\Core\UI\IntegrationGrid;

echo IntegrationGrid::render( [
    [
        'name'         => __( 'Vendor Access', 'vendor-extension' ),
        'description'  => __( 'Controls eligibility for this feature.', 'vendor-extension' ),
        'status'       => IntegrationGrid::READY,
        'status_label' => __( 'Ready', 'vendor-extension' ),
        'action_url'   => admin_url( 'admin.php?page=vendor-access' ),
        'action_label' => __( 'Configure Access', 'vendor-extension' ),
    ],
] );
```

`render()` returns an HTML string safe to echo. Consumer-provided text and URLs are escaped by Base.

## Item contract

Each item accepts:

| Key | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Human-readable integration name. Empty names invalidate the item. |
| `description` | no | Explanatory copy owned by the consumer. |
| `status` | yes | One of `ready`, `needs-setup`, `optional`, `unavailable`. |
| `status_label` | yes | Caller-localised visible status text. Empty labels invalidate the item. |
| `action_url` | no | Configure/action destination. |
| `action_label` | no | Visible CTA label. |

A CTA is rendered only when both `action_url` and `action_label` are non-empty. A partial CTA fails closed to no action.

Items with an empty name, empty status label, unknown status or a non-array shape are omitted. Unknown states are never coerced into a different business meaning. If no valid items remain, `render()` returns an empty string.

## Status semantics

Integration Grid has exactly four public states for v1:

| Integration Grid state | Existing Base `Status` variant | Visual meaning |
| --- | --- | --- |
| `ready` | `active` | green — immediately usable |
| `needs-setup` | `warning` | amber — active/detected but operator setup remains |
| `optional` | `idle` | grey — not required for the current capability |
| `unavailable` | `error` | red — expected integration contract/runtime is unavailable |

This mapping is owned by Integration Grid. Consumers must not translate these states directly into colour classes or depend on the internal Status-to-colour mapping.

The existing `Status::ready` semantic is not changed by this Foundation.

## No-action behavior

When an item has no complete CTA, Base renders no footer. The card remains structurally complete and its status/description communicate the current state without inventing generic business copy.

Consumers should express any reason, next step or "no configuration required" meaning in their own localised description/status text rather than introducing local footer presentation.

## Asset contract

Registered Core Admin pages request the semantic component through `PageRegistry`:

```php
PageRegistry::register(
    new Vendor\Admin\IntegrationsPage(),
    [
        'components' => [ 'integration-grid' ],
    ]
);
```

`integration-grid` is the only public asset requirement consumers need for this surface. Base internally provides the Integration Grid stylesheet plus the existing Status and Button presentation dependencies.

CSS filenames, WordPress handles, enqueue order and bundle boundaries remain private Base implementation details.

## Presentation boundary

Integration Grid is a **Core Admin Design Foundation component**. It is not a standalone WordPress-native adapter and must not be used as a reason to import Core Admin presentation onto unrelated wp-admin screens.

The grid uses two equal columns at normal Core Admin widths and collapses to one column at the WordPress admin responsive breakpoint. Base owns this geometry.

Extensions may position the whole Integration Grid within their page composition, but must not locally redraw card surfaces, internal spacing, status placement, CTA presentation, borders, radii, hover/focus states or responsive card geometry.

## Golden Extension rule

First-party Core Blueprint Integrations pages use the shared Base Integration Grid Foundation when they present integration readiness cards.

Extensions retain ownership of integration semantics; Base retains ownership of presentation.
