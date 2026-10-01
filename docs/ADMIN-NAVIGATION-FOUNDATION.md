# Core Blueprint Admin Navigation Foundation v1

## Purpose

Core Blueprint admin workspaces use one canonical navigation hierarchy so Base and extensions do not invent separate tab, sub-tab, view-switch and spacing patterns.

Base owns navigation presentation and vertical rhythm. Consumers keep ownership of routes, capabilities, labels and business behavior.

## Navigation hierarchy

### Level 1 - Workspace navigation

Use primary tabs for the main destinations of an admin workspace.

Canonical primitives:

- `CB\Core\UI\PrimaryNav` for arbitrary workspace destinations;
- `CB\Core\Admin\TabNav` as the same-page `?tab=` convenience wrapper;
- `.cb-core-tab-wrapper`.

Do not render a second full tab row underneath Level 1.

### Level 2 - Section navigation

Use quiet inline links when one Level 1 destination has internal sections.

Canonical primitive:

- `CB\Core\UI\SectionNav`
- `.cb-core-section-nav`

Examples:

- Overview | Setup & Trust | Recovery & Advanced
- Weekly | Date exceptions

Level 2 is navigation. It changes the current section or route.

### Level 3 - View or mode selector

Use Segmented Control for mutually exclusive presentation modes or local view choices.

Canonical primitive:

- `.cb-core-segmented-control`
- `CB\Core\UI\Assets::enqueue_segmented_control()`

Examples:

- Day | Week | Month
- Grid | List
- Visual | Code

A view selector is not a second navigation row.

## Actions

Workflow actions remain buttons.

Examples:

- Add booking
- Save
- Publish
- Retry

Do not place workflow actions inside Level 1, Level 2 or Segmented Control navigation.

## Vertical rhythm

When primary and/or secondary navigation precedes page content, wrap those navigation elements in:

```html
<div class="cb-core-navigation-stack">
    <!-- Level 1, optional -->
    <!-- Level 2, optional -->
</div>
```

The Navigation Stack owns the gap from navigation to content. Consumers must not add page-specific bottom margins, clear elements or duplicate section margins to recreate this spacing.

Canonical content gap: `var(--cb-space-5)` = 24px.

Within the stack:

- Level 1 owns no bottom spacing.
- Level 2 sits `var(--cb-space-3)` = 12px below Level 1.
- The stack owns the final 24px gap to content.

The stack accepts both `.cb-core-tab-wrapper` and WordPress `.nav-tab-wrapper` during progressive migration.

## Constraints

- Maximum two navigation levels.
- Do not introduce a third navigation row.
- Do not use a second full tab row as Level 2.
- Do not use primary buttons for selected view state.
- Do not use `subsubsub` floats or manual separator markup for new Core Blueprint Level 2 navigation.
- Do not add consumer-owned CSS that redraws Base navigation primitives.
- Existing pages may migrate during their normal extension audit. This foundation does not require a suite-wide rewrite in one release.

## Consumer responsibilities

Consumers own:

- route/query construction;
- capability checks;
- active section selection;
- labels;
- business behavior.

Base owns:

- navigation markup contract, including generic Level 1 and Level 2 renderers;
- active-state presentation;
- accessibility attributes;
- separators;
- focus/hover behavior;
- navigation spacing and rhythm.

## Migration rule

When an extension is audited:

1. Identify every navigation row and view selector.
2. Map main destinations to Level 1.
3. Map internal sections to Level 2.
4. Map local display modes to Segmented Control.
5. Map workflow operations to Buttons.
6. Remove local navigation spacing and duplicate tab styling only after the canonical Base primitive is in place.
