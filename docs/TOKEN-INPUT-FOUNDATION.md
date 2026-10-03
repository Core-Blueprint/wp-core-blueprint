# Token Input Foundation

**Public contract since:** Core Blueprint Base `1.0.0`

Token Input is a shared progressive-enhancement control for serialized strings containing consumer-defined variables. Base owns generic parsing, interaction, accessibility and presentation only. Consumers own the token allowlist, token meaning, resolution, preview and business validation.

## Enqueue and runtime contract

Start from a real native input; that input remains the only submitted/persistent value:

```html
<input name="reference_format" value="REF-{DATE}-{ID}">
```

```php
\CoreBlueprint\Core\UI\Assets::enqueue_token_inputs();
```

Script-module dependency: `@cb-core/token-input`.

```js
const input = document.querySelector('[name="reference_format"]');
const tokenInput = window.cbCore.tokenInput.create(input, {
    tokens: [
        { value: '{DATE}', label: 'Date' },
        { value: '{ID}', label: 'ID' },
    ],
});
```

No `contenteditable` and no hidden mirror field are used. The serialized value remains ordinary `input.value` state, e.g. `REF-{DATE}-{ID}`.

## Instance API

```js
tokenInput.getValue();
tokenInput.setValue('REF-{DATE}-{ID}');
tokenInput.focus();
tokenInput.destroy();
```

Calling `create()` repeatedly for the same native input returns the existing instance.

## Options

- `tokens`: array of allowed `{TOKEN}` descriptors (`value`, `label`).
- `availableTokensLabel`: optional label for the insertion palette.
- `showAvailableTokens: false`: hide insertion buttons while keeping typed/pasted parsing.
- `ariaLabel`: optional explicit accessible field label; otherwise derived from the native input.

## Interaction contract

- Ordinary text and separators remain freely editable.
- Clicking a token inserts it at the current serialized cursor position.
- Complete balanced tokens typed manually are tokenized immediately.
- Pasted serialized strings are tokenized immediately.
- Balanced values not present in the supplied allowlist remain serialized and are marked `Unknown variable`.
- Backspace directly after a token and Delete directly before it remove the complete token.
- Left/Right arrows cross tokens to the adjacent native text insertion point.
- Each token has a keyboard-accessible remove control with an accessible `Remove variable: …` name.
- Ctrl/Cmd+A selects the complete serialized value; Copy yields the serialized string, not chip markup.
- Paste while the whole value is selected replaces the whole serialized value.
- Native form reset and externally dispatched native input changes re-synchronize the editor.
- The native input emits normal `input`/`change` events for consumer preview/validation logic.

## Validation boundary

Base may distinguish only whether a balanced token is present in the supplied allowlist. Base does not know whether a token is required, how often it may occur, how it resolves, uniqueness rules, preview values or consumer-specific length constraints.

```text
native input
    ↓
Token Input Foundation
    ↓
serialized string
    ↓
consumer validation / preview / resolution
    ↓
save
```

## Presentation boundary

`enqueue_token_inputs()` resolves presentation from the actual wp-admin screen when no adapter is supplied: screens under the Core Blueprint parent menu receive the Core Admin adapter, while standalone WordPress admin screens receive the WP-native adapter. Loaded Core token styles alone never change that decision. Consumers may still explicitly pass `TOKEN_INPUT_PRESENTATION_CORE` or `TOKEN_INPUT_PRESENTATION_WP_NATIVE` when a non-default context is intentional. Extensions must use the public helper rather than importing presentation assets directly.
