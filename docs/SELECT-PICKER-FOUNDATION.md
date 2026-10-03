# Select Picker Foundation

Select Picker is the shared Base primitive for progressively enhancing a real native single `<select>` when a larger or grouped option set benefits from stronger navigation and search.

The native `<select>` remains:

- the submitted form value;
- the source of option and `<optgroup>` data;
- the consumer-owned validation contract;
- the no-JavaScript fallback.

Foundation owns only presentation and interaction. It must not hardcode product-domain options.

## Use

Enqueue the Foundation:

```php
CoreBlueprint\Core\UI\Assets::enqueue_select_picker();
```

Opt a native select into enhancement:

```html
<select name="type" data-cb-core-select-picker data-cb-core-select-picker-search="true">
    <optgroup label="Basic">
        <option value="text">Text</option>
    </optgroup>
    <optgroup label="Relations">
        <option value="post_relation">Post / Object Relation</option>
    </optgroup>
</select>
```

Search modes:

- `data-cb-core-select-picker-search="true"` — always show search;
- `data-cb-core-select-picker-search="false"` — never show search;
- omitted or `auto` — show search when the selectable option count reaches the default threshold (8);
- `data-cb-core-select-picker-search-threshold="12"` — override the auto threshold.

## Runtime

Script module: `@cb-core/select-picker`

Runtime API:

```js
window.cbCore.selectPicker.init(scope);
window.cbCore.selectPicker.enhance(select, options);
```

When a picker option is selected, Foundation updates the real select and dispatches a normal bubbling `change` event. Consumers therefore keep their existing validation and state logic.

Programmatic changes to the native select remain supported. Consumers that change the native value programmatically without dispatching `change` may dispatch the generic `cb:select-picker-sync` event to refresh the enhanced presentation.

## Presentation boundary

Auto mode uses the Core presentation below the Core Blueprint parent menu and the WordPress-native adapter on standalone wp-admin screens. Explicit presentation constants remain available through `CoreBlueprint\Core\UI\Assets`.

## Scope

Select Picker is intended for larger, searchable or grouped option sets. Small controls such as a two- or three-option priority selector should normally remain a native `<select>`.
