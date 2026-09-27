# Object Picker Foundation

`CB\Core\UI\ObjectPicker` is the shared Base primitive for asynchronously searching and selecting WordPress-backed or extension-backed objects without loading a complete object catalog into a `<select>`.

Foundation owns:

- progressive enhancement over one real submitted text input;
- single and multiple selection presentation;
- ordered selected-item state;
- debounced async search transport;
- a full-width selected-object card for single selection and removable chips for multiple selection;
- opaque identifier transport and exact-string deduplication;
- Core Admin and WordPress-native presentation adapters;
- the public runtime `window.cbCore.objectPicker.init()`.

Consumers own:

- the AJAX action;
- nonce scope and authorization;
- search query implementation;
- object semantics and labels;
- persistence and validation.

This boundary deliberately prevents Foundation from knowing about Content Models, posts, users, terms, Certificates, Backups, or any other product domain.

## Identifier contract

`item.id` is an opaque transport identifier. Base does not infer an object type, provider or numeric meaning from it.

A valid identifier:

- is supplied as a scalar value and normalized to a string;
- is trimmed before use;
- is non-empty after trimming;
- is at most 191 bytes;
- does not contain a comma.

The comma restriction exists because the v1 multiple-selection transport remains a comma-separated ordered list. There is no parallel JSON/value/provider transport in this Foundation contract.

Numeric identifiers remain fully supported. An item returned as `13` is transported internally as the string `"13"`, while the submitted input still contains `13`. Existing consumers that normalize submitted values with `absint()` therefore continue to work unchanged.

Opaque identifiers such as these are valid and remain distinct even when they share the same numeric suffix:

```text
vendor:record:42
vendor:collection:42
```

Selection, initial state, deduplication and removal all compare the complete normalized string identifier.

## Rendering

```php
use CB\Core\UI\ObjectPicker;

echo ObjectPicker::render( [
    'id'       => 'related-object',
    'name'     => 'related_object',
    'multiple' => true,
    'action'   => 'my_plugin_search_objects',
    'nonce'    => wp_create_nonce( 'my_plugin_search_objects' ),
    'context'   => [ 'scope' => 'example' ],
    'show_hint'  => true,
    'selected'   => [
        [ 'id' => 42, 'label' => 'Example', 'meta' => 'Post · #42' ],
        [ 'id' => 'vendor:object:42', 'label' => 'Extension object', 'meta' => 'Example provider' ],
    ],
] );
```

The optional `show_hint` argument defaults to `true`. Consumers that already provide domain-specific guidance directly below the picker may set it to `false` to avoid duplicate helper copy.

The submitted fallback value is one normalized opaque identifier for single selection or a comma-separated ordered identifier list for multiple selection. Consumers normalize that transport representation into their own domain-specific storage contract.

## Search response

The consumer AJAX action may return numeric or opaque string identifiers:

```php
wp_send_json_success( [
    'items' => [
        [ 'id' => 42, 'label' => 'Example', 'meta' => 'Post · #42' ],
        [ 'id' => 'vendor:object:42', 'label' => 'Extension object', 'meta' => 'Example provider' ],
    ],
] );
```

Base normalizes each valid result ID to its opaque string representation before comparing or storing browser selection state. Invalid or empty identifiers are ignored safely.

Never treat the browser-provided search context or identifier contents as authorization. Resolve trusted configuration again on the server and apply the consumer's capability boundary before returning objects or persisting a submitted selection.

## Assets

```php
CB\Core\UI\Assets::enqueue_object_picker();
```

Auto mode selects Core presentation below the Core Blueprint parent menu and the WordPress-native adapter elsewhere. Consumers may explicitly use the documented presentation constants where screen ownership is already known.
