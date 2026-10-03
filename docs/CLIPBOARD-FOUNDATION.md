# Clipboard Foundation

**Public contract since:** Core Blueprint Base `1.0.0`

Clipboard is a shared copy-to-clipboard primitive for Base and standalone Core Blueprint extensions. Base owns clipboard access, fallback behavior, shared icon state, accessibility and Toast feedback. Consumers own the copied value and its meaning.

## Enqueue and runtime contract

```php
\CoreBlueprint\Core\UI\Assets::enqueue_clipboard();
```

Script-module dependency: `@cb-core/clipboard`.

Direct copy:

```js
const copied = await window.cbCore.clipboard.copy('[example_shortcode id="42"]');
```

Custom feedback:

```js
await window.cbCore.clipboard.copy(value, {
    successMessage: 'Shortcode copied.',
    errorMessage: 'Could not copy shortcode.',
});
```

The Promise resolves to `true` on success and `false` on failure. Toast feedback is enabled by default; pass `notify: false` when a consumer deliberately owns another feedback surface.

## Enhance an existing button

Clipboard does not invent a new button system. Supply a real button styled by the current presentation context: WordPress `.button` on standalone admin pages or the shared Core Button Foundation under Core Admin.

```js
const button = document.querySelector('[data-copy-shortcode]');

const control = window.cbCore.clipboard.enhance(button, {
    text: () => shortcodeInput.value,
    label: 'Copy shortcode',
    successMessage: 'Shortcode copied.',
});
```

`text` is required and must be an explicit string or callback. Clipboard never guesses the payload from visible DOM text.

## Instance API

```js
await control.copy();
control.destroy();
```

Re-enhancing the same button destroys its previous Clipboard instance first.

## Options

- `text`: required string or callback evaluated at copy time.
- `label`: optional accessible button label.
- `icon: false`: disable the shared copy/success Lucide icon state.
- `successMessage`: override the success Toast text.
- `errorMessage`: override the error Toast text.
- `notify: false`: suppress automatic Toast feedback.
- `feedbackDuration`: milliseconds before the success icon returns to Copy; default `1600`.

## Interaction and accessibility contract

- Uses `navigator.clipboard.writeText()` when available.
- Falls back locally to an off-screen textarea plus `execCommand('copy')` when browser policy or support requires it.
- Preserves the user's active focus after fallback copy.
- Enhanced controls are real buttons and receive `type="button"` when no type is supplied.
- Copy operations expose `aria-busy="true"` while in progress and temporarily disable the control.
- Icon-only controls receive `Copy to clipboard` as an accessible fallback name unless the consumer supplies `label`.
- Successful enhanced copies temporarily use the shared `clipboard-success` Lucide alias.
- `destroy()` removes Foundation listeners/state/icons and restores Foundation-owned button attributes.

## Presentation boundary

`enqueue_clipboard()` defaults to WordPress-native presentation on standalone extension screens. It loads the WP Native Toast adapter plus shared Icons and only presentation-neutral Clipboard alignment.

When the Core Admin token layer is already active, Clipboard automatically keeps Core presentation and reuses Button Foundation. Extensions must not import Core Admin theme assets to obtain Clipboard behavior.

## Consumer boundary

Clipboard has no shortcode, certificate, LMS, filename, URL or command semantics. The consumer decides what value is copied and may perform any business validation before invoking the Foundation.
