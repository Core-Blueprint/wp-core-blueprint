# Form Composition Foundation

Status: **public v1 UI Foundation contract**.

Form Composition gives Core Blueprint screens one semantic Field + Stack markup contract across both presentation boundaries:

- Core Admin keeps the token-based Design Foundation presentation.
- Standalone WordPress admin screens keep native WordPress controls, colours, focus behaviour, browser affordances and admin chrome. Base provides only shared field structure and spacing.

## Enqueue boundary

Consumers enqueue the narrow adapter through:

```php
use CoreBlueprint\Core\UI\FormComposition;

FormComposition::enqueue();
```

With no explicit presentation, Base resolves from the actual admin screen: Core Blueprint menu screens receive the Core presentation and standalone WordPress admin screens receive the `wp-native` structural adapter. This resolution is based on screen identity, never on whether Core token CSS happens to be loaded. Consumers may explicitly request `FormComposition::PRESENTATION_CORE` or `FormComposition::PRESENTATION_WP_NATIVE` only when they genuinely own that presentation context.

Normal pages registered under the Core Blueprint menu should still prefer PageRegistry's `fields` semantic requirement; Stack is already part of the minimal Core Admin shell.

Consumers must not depend on internal stylesheet handles or filenames used by this helper.

## WordPress-native controls

On standalone WordPress admin screens, Core Blueprint does **not** restyle normal WordPress form controls. Extensions must render canonical WordPress admin markup and let WordPress own the presentation.

Use explicit HTML input types and WordPress' native sizing/classes where appropriate, for example:

```html
<input type="text" class="regular-text">
<input type="search" class="regular-text">
<input type="number" class="small-text">
<select>...</select>
<textarea class="widefat"></textarea>
<button type="button" class="button">Action</button>
```

Do not add suite-wide or extension-local CSS that redefines normal input/select/textarea padding, height, borders, border radius, focus treatment or select chrome merely to make controls look consistent. Correct markup is the consistency contract; WordPress is the presentation authority.

Feature-specific layout rules such as widths, grids, repeaters or task-specific containers remain extension-owned, provided they do not reskin the underlying native controls.

## Stack contract

```html
<div class="cb-core-stack cb-core-stack--form">
    <div class="cb-core-field">...</div>
    <fieldset class="cb-core-field">...</fieldset>
    <table class="widefat">...</table>
</div>
```

Variants:

- `cb-core-stack` — normal component rhythm.
- `cb-core-stack--compact` — tighter related-control rhythm.
- `cb-core-stack--form` — top-level form/field rhythm. Use this when adjacent direct children are logical fields, fieldsets, summary tables or other peer sections in one form surface.
- `cb-core-stack--loose` — larger workflow/section rhythm.

Stack owns only spacing between its direct children. Child components keep ownership of their own internal geometry. Do not compensate for a missing form rhythm by adding local margins to field labels, descriptions or neighboring tables.

## Form Actions contract

Use `cb-core-form-actions` for one or more peer buttons/links that form the
action row for a field or form section:

```html
<div class="cb-core-stack cb-core-stack--form">
    <div class="cb-core-field">...</div>

    <div class="cb-core-form-actions">
        <button type="submit" class="button button-primary">Save</button>
        <a class="button" href="...">Cancel</a>
    </div>
</div>
```

Form Actions owns only horizontal action composition: flex layout, wrapping,
alignment and the gap between peer actions. It does **not** own vertical
separation from fields/sections, button width, button colours or button
presentation.

When an action row follows a field or section, put both inside Stack and let
Stack own their vertical spacing. Do not add local top margins to the action
row. On standalone WordPress admin screens, buttons remain native WordPress
buttons. On Core Admin screens, the surrounding presentation boundary remains
responsible for the applicable button primitive.

A button should not be placed directly in a vertical Stack when doing so would
make the flex container stretch that button across the available width. Use a
Form Actions wrapper instead.

## Field contract

Use `CoreBlueprint\Core\UI\Field::render()` when one normal wrapper + label/control/hint shape fits the field. Native semantic containers such as `<fieldset>` may use the same classes directly when their HTML semantics require a `<legend>`.

```html
<div class="cb-core-field">
    <label class="cb-core-field__label" for="example">Example</label>
    <input id="example" type="text" class="regular-text">
    <p class="description">Helpful context.</p>
</div>
```

Field owns the compact internal rhythm between label, control and help/error/meta text. In the WP-native adapter, these child relationships use the field gap as the spacing authority; consumers should not add extra top margins to `.description` merely to separate one field from the next.

### Simple choices

Use `cb-core-field__choices` for a small inline/wrapping set of native radio or checkbox labels that belong to one field. WordPress continues to own the controls themselves; Form Composition owns only the spacing between peer choices.

```html
<fieldset class="cb-core-field">
    <legend class="cb-core-field__label">Visibility</legend>
    <div class="cb-core-field__choices">
        <label><input type="radio" name="visibility" value="private"> Private</label>
        <label><input type="radio" name="visibility" value="public"> Public</label>
    </div>
    <p class="description">Controls package access.</p>
</fieldset>
```

Browser fieldset/legend layout is special and does not reliably expose the legend as a normal flex item. Base therefore owns an explicit `legend → control/choices` spacing rule in both Core and WP-native presentations so semantic fieldsets keep the same label-to-control rhythm as ordinary Fields. Consumers must not add local legend margins to compensate.

`cb-core-field__choices` is intentionally not a substitute for Choice Group. Use the dedicated Choice Group Foundation when the options need cards, a managed grid, longer descriptions or richer selection presentation.

Supported structural classes include `cb-core-stack`, `cb-core-stack--compact`, `cb-core-stack--form`, `cb-core-stack--loose`, `cb-core-form-actions`, `cb-core-field`, `cb-core-field--inline`, `cb-core-field--separated`, `cb-core-field--enable`, `cb-core-field__label`, `cb-core-field__control`, `cb-core-field__choices`, `cb-core-field__hint`, `cb-core-field__error` and `cb-core-field__meta`.

## Ownership rules

On standalone WordPress admin screens:

- WordPress owns input/select/textarea/radio/checkbox/button presentation, including colours, padding, height, borders, focus behaviour, browser affordances and admin chrome.
- Extensions own correct semantic HTML, explicit input types and the appropriate WordPress admin classes.
- Form Composition owns grouping, spacing and field-level structural states only.
- `cb-core-stack--form` owns vertical spacing between peer fields/sections/action rows; `cb-core-form-actions` owns horizontal composition between peer actions; `cb-core-field` owns the spacing inside one field; `cb-core-field__choices` owns spacing between simple peer choices.
- Do not load Core Admin tokens/theme merely to obtain form styling or spacing.
- Do not add local `field + field { margin-top: ... }` chains when Stack expresses the composition.
- Do not duplicate or override native input/select/textarea presentation inside sibling extensions.
- Feature-specific grids, previews and task-specific sizing may remain extension-owned.

This adapter intentionally does not make a standalone screen a Core Admin screen and does not add dark mode, Core surfaces, Core buttons or Core form-control skinning.
