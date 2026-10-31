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

## Header presentation (public v1)

The Header Picker uses the same public Select Picker controller and the same native single `<select>` as the Field variant. The select remains the authoritative form value, validation target and non-JavaScript fallback.

Declarative initialization:
```html
<select name="stage" aria-label="Workflow stage"
        data-cb-core-select-picker
        data-cb-core-select-picker-variant="header">
    <option value="questions">Questions</option>
    <option value="rules">Rules</option>
</select>
```

Programmatic initialization, after `CoreBlueprint\Core\UI\Assets::enqueue_select_picker()`:
```js
window.cbCore.selectPicker.enhance(select, { variant: 'header' });
```

Supported variants: `field` (default) and `header`. An unknown variant returns `null` before altering the native select. Field retains its existing grouped options, automatic/explicit search, focus and outside-click behavior. Header is a compact text/chevron selector with **no search input**, even if search is requested. Header is a selection control, not an action menu.

Header uses a manual HTML Popover in the browser top layer, with viewport-bounded fixed positioning, resize/ancestor-scroll repositioning and upward flip when needed. If `showPopover()` or `hidePopover()` is missing, the original native select stays visible and functional. Header-specific styling uses the existing Core or WordPress-native Picker presentation and theme tokens.

Opening Header by mouse or keyboard schedules focus to the selected **enabled rendered** option, or the first available option if there is no selectable match. No option is focused if all options are disabled. A pending focus callback is invalidated when the popover closes and cannot refocus an older or newly reopened popover. Arrow Up/Down, Home and End navigate options. Escape closes Header and restores trigger focus; outside click or focus closes it and removes temporary viewport/focus listeners.

Choosing a different option changes the original select and dispatches exactly one bubbling `change` event. Choosing the currently selected option only closes the popover. Programmatic native changes can trigger `cb:select-picker-sync` as described above. Consumers retain domain state, permissions, Save/History and Exit Guard authority.

Before release, verify Core and WP-native presentation in light/dark themes, keyboard and mouse, narrow viewports and native fallback.

## Shared Designer header integration (BASE-BRA-04B)

The Designer Foundation enhances an existing single native select under the public `data-cb-design-shell-context` contract, after composing the canonical header. It does not enhance unrelated controls, multi-selects or other context components. Consumers continue to own option data, native selection value and all domain actions.

Designer Mode uses `cb:select-picker:ready` plus an immediate API check so either script/module load order works. The readiness event is an availability notification, not an authorization. Native Mail statically imports the WordPress Script Module and enhances its original template select only after Trusted Host READY and final toolbar insertion. Reports receives no artificial context selector.

Both Designer routes request `CoreBlueprint\Core\UI\Assets::SELECT_PICKER_PRESENTATION_CORE` via `enqueue_select_picker()`. Ordinary standalone wp-admin screens may instead request the WP-native presentation.

Designer Mode retains its original capture-phase Exit Guard and async context transition. For immediate rollback, disabled/busy, accepted targets, failed loads and transition completion, it dispatches `cb:select-picker-sync` on the native select to refresh presentation only. No extra `change`, context request, Save or History event is created. Native Mail retains `transitionSafe()` and existing template URL navigation; a blocked change restores the native value and sends a paint-only sync.

If a select becomes disabled while its Header popover is open, the popover closes, stale option clicks are rejected and no focus is placed on a disabled trigger. Re-enabling permits normal use. Popover-unavailable browsers keep the native select.
