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


## Header presentation (additive public v1)

Use the same real single-select and enhance it as a compact header title:

```html
<select aria-label="Evaluation stages"
        data-cb-core-select-picker
        data-cb-core-select-picker-variant="header"
        data-cb-core-select-picker-search="false">
    <option value="questions">Questions</option>
    <option value="rules">Rules</option>
    <option value="results">Results</option>
</select>
```

Or call `window.cbCore.selectPicker.enhance(select, { variant: 'header' })`
after enqueuing `CoreBlueprint\Core\UI\Assets::enqueue_select_picker(
CoreBlueprint\Core\UI\Assets::SELECT_PICKER_PRESENTATION_CORE
)`.

- `field` is the implicit default and remains unchanged for existing consumers.
- `header` uses a borderless text/chevron button and a roomy, tokenized menu.
  The selected option still lives in the native `<select>`; normal `change`
  events preserve consumer stage routing and state authority.
- The menu uses a **manual HTML Popover top layer** and bounded fixed-position
  anchoring, so Base Designer's `overflow: hidden` rails cannot clip it.
  Viewport resize and ancestor scrolling refresh its position; closing releases
  listeners. Responsive widths clamp to the visible viewport and menus flip
  upwards when needed.
- Browsers without `showPopover()` keep the **native select**, rather than
  displaying an inaccessible or clipped imitation.
- Escape closes and restores trigger focus. Arrow keys, Home and End navigate
  options. A selected item is highlighted; consumer translations provide labels.
- A compact header is a **selection control**, not an action menu.
  Keep the existing Status Menu Foundation for commands such as Delete or Export.
- The enhancement does not own saved data, permissions or publication. It must
  not intercept Designer Undo/Redo or the Exit Guard. Call
  `select.dispatchEvent(new Event('cb:select-picker-sync'))` if the native value
  is changed without dispatching `change`.

**Installed acceptance remains mandatory:** keyboard operation in a fullscreen
Designer; body-level modal focus; very narrow screens; six locales; light/dark
schemes; outside click; Esc; disabled choices; and unchanged legacy field picker.

Source regressions: `node --test tests/js/select-picker-header.test.mjs`.
Run Base Level 1, DEV build and the installed sandbox matrix before release.
