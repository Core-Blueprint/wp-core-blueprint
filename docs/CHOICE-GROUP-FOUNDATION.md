# Choice Group Foundation

`CoreBlueprint\Core\UI\ChoiceGroup` is the shared Base primitive for grouped native checkbox or radio options.

The Foundation owns only:

- consistent grouped presentation;
- responsive grid layout;
- optional compact and scrollable variants;
- native checkbox/radio accessibility and Core/WP-native presentation adapters.

Consumers own field names, values, persistence, validation and business meaning.

## Rendering

```php
use CoreBlueprint\Core\UI\ChoiceGroup;

echo ChoiceGroup::render( [
    'aria_label' => __( 'Behaviour', 'core-blueprint' ),
    'options' => [
        [
            'name'    => 'public',
            'label'   => __( 'Publicly queryable', 'core-blueprint' ),
            'checked' => true,
        ],
        [
            'name'    => 'show_in_rest',
            'label'   => __( 'Expose through the WordPress REST API', 'core-blueprint' ),
            'checked' => true,
        ],
    ],
] );
```

Options may provide `name`, `label`, `value`, `checked`, `disabled`, `id`, and `class`.

Group arguments:

- `type`: `checkbox` (default) or `radio`;
- `scrollable`: constrain long collections with internal scrolling;
- `compact`: denser layout for embedded workspaces;
- `aria_label`: accessible group name when no external relationship is available;
- `class`: additional consumer-owned wrapper class.

## Assets

Core Blueprint admin pages receive the Core presentation automatically through Base admin assets.

Standalone extension screens can opt in explicitly:

```php
CoreBlueprint\Core\UI\Assets::enqueue_choice_group();
```

Auto mode selects `core` below the Core Blueprint parent menu and `wp-native` elsewhere. Consumers may force either documented presentation constant when required.

## Boundary

Do not create module-specific clones for ordinary grouped checkbox/radio collections. Page CSS may compose Choice Group with surrounding headings/help text, but should not duplicate its border, surface, spacing, responsive grid or option alignment.
