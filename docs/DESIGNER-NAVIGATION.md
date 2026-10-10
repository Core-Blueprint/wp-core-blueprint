# Core Blueprint Designer Navigation Foundation (optional)

**Contract:** additive public v1 Designer Foundation capability.
**Owner:** Core Blueprint Base through the public design-editor module.
**This patch:** Base only. Automations and Evaluator do not yet consume the API.

## Purpose

Navigation is a task/stage outline in the **left Designer rail**, not the actual object hierarchy in the right-side Layers panel. Automations' WHEN / GET DATA / ONLY IF / THEN and Evaluator's prospective Build / Logic / Results / Preview are examples, not Base-owned business semantics.

The three-rail workspace, editor header, context selector, right Inspector/Layers/Settings roles, fullscreen mode, and responsive drawers remain unchanged.

## Left palette roles

Canonical optional order: **Navigation → Elements → Dynamic Data**. Omitted roles create no placeholders.

- **Navigation only:** append the public Navigation component directly inside the existing left palette without tabs, as Automations can do.
- **Navigation + Elements:** declare the normal Base tablist with a navigation panel and an elements panel. Set data-cb-design-shell-palette-role="navigation" and matching tab/panel identifiers. Base owns panel order, labeling, tab keyboard behavior and responsive geometry.
- **Elements or Dynamic Data only:** unchanged for existing consumers.
- Base's default translated tab name is **Navigation**. An optional translated data-cb-design-shell-palette-label="Workflow" on the navigation panel supplies a domain-appropriate label without renaming other canonical roles.
- Do not build a second left sidebar or product-owned collapse/drawer/tab controller.

## Public API

Import **DESIGNER_PALETTE_ROLES** and **createDesignerNavigation** from the public module identifier @cb-core/design-editor (not a private relative source file).

    const shell = document.querySelector('[data-cb-design-shell]');
    const navigation = createDesignerNavigation({
      root: shell,
      ariaLabel: 'Workflow stages', // Consumer-translated
      activeId: 'when',
      items: [
        {
          id: 'when',
          step: '1',
          label: 'When',
          description: 'Trigger',
          targetId: 'workflow-trigger',
          status: { label: 'Required', tone: 'warning' },
        },
        {
          id: 'then',
          step: '2',
          label: 'Then',
          description: 'Actions',
          targetId: 'workflow-actions',
        },
      ],
    });
    shell.querySelector('.cb-core-design-shell__palette').append(navigation.element);
    navigation.setActive('then');
    navigation.update(updatedItems, { activeId: 'when' });
    navigation.destroy();

Each item requires a stable, unique nonempty **id** and nonempty **label**. All human-visible labels are supplied (and translated) by the consumer. Supported optional properties:

- **step**: short display counter; **description**: descriptive text.
- **targetId**: section ID; Base resolves it only inside the supplied Designer shell.
- **disabled**: disables activation; the consumer explains why.
- **status**: object with translated label and tone neutral, success, warning or danger; consumers own its meaning.
- **onNavigate({item, target, event})**: optional synchronous callback on the component; returning false cancels activation. Without a targetId, a callback can implement a virtual stage, but must implement its domain navigation.
- **activeId**: initial active item. A later unknown setActive ID returns false; it never invents a target.

The component returns **element**, **setActive(id)**, **update(items, {activeId})**, and **destroy()**. Updating is validated before replacing DOM and restores focus to the same control when its stable item ID survives. Destroying removes the DOM and makes subsequent mutations invalid.

The equivalent global facade is window.cbCore.designEditor.shell.navigation.create(options), with the canonical roles at window.cbCore.designEditor.shell.paletteRoles.

## Accessibility and safety

- Markup is a semantic navigation landmark containing an ordered list of links for section targets, or buttons for callback-only items. Native Tab and Enter remain functional.
- The active item uses aria-current="location". Navigation does not mutate the Layers object selection.
- Clicking a valid target scrolls and focuses it. A temporary tabindex="-1" is removed on blur.
- Off-shell targets, even when their ID matches, never receive focus or scroll.
- Navigation items are not ARIA tabs. When Navigation and Elements are tabs in the left palette, Base's existing Left/Right/Home/End tab behavior applies to those tabs.
- Labels/status are inserted as text, not executable HTML.
- Responsive, fullscreen, theme and save/history behaviors remain owned by existing Base contracts.

## Mixed-panel declarative example

The consumer renders a normal Base tablist with two buttons declaring matching tab identifiers **navigation** and **elements**, both in panel group **palette**. The left palette contains matching section panels with data-cb-design-shell-panel values navigation/elements, matching data-cb-design-shell-group="palette", and the canonical data-cb-design-shell-palette-role markers. The navigation panel may set data-cb-design-shell-palette-label to a consumer-localized stage title.

The consumer appends navigation.element to the navigation panel and creates Designer Shell through the existing Base public contract. **Do not move or rename the palette rail itself.**

## Non-goals

No change to createSession profiles or Evaluator's current history bridge. No business validation, data policies, workflow activation, dry-run execution, persisted navigation, alternate right sidebar roles, or Automations/Evaluator migration. These remain separate consumer and architecture decisions.

## Acceptance

Run the exact feature HEAD's PHP/JS source and public contract tests, canonical six-locale i18n gate and full release build in dist. Field-test both Navigation-only and Navigation + Elements at desktop and responsive drawer breakpoints; keyboard-only, screen readers, 200%/400% zoom, focus return and light/dark themes. Ensure existing Mail, Reports and Commerce consumers do not regress. No release or merge without explicit GO.
