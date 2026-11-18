# Management Workspace Foundation — v1 candidate

**Status:** Base SOURCE candidate; Level 1, WordPress integration, browser acceptance and Golden are not implied.

## Scope and ownership

Management Workspace is an **opt-in presentation and interaction contract**, not a CRUD/query framework. It unifies the recurring overview/search/filter/table/action navigation experience while leaving the consumer's data, routes, lifecycle, permissions, nonces, SQL and confirmations untouched.

The first adoption target is Contracts → Clause Library; Bookings is an established visual reference. Existing Base \`overview-framework\` is an unrelated dashboard/card pattern.

## Public semantic requirements

Pages registered through \`CoreBlueprint\Core\Admin\PageRegistry\` or compatible standalone screens registered through \`CoreBlueprint\Core\UI\AdminTheme::register_screen()\` may declare:

\`\`\`php
[
    'components' => [ 'management-workspace', 'toolbars', 'management-table' ],
    'foundations' => [ 'row-action-menu' ],
]
\`\`\`

Base owns asset selection. Consumers must **not** enqueue Base's private CSS handles/filenames. Direct, opt-in \`CoreBlueprint\Core\UI\Assets\` methods are available for standalone custom menu screens:

- \`enqueue_management_workspace(?string $presentation = null)\`
- \`enqueue_management_toolbar(?string $presentation = null)\`
- \`enqueue_management_table(?string $presentation = null)\`
- \`enqueue_row_action_menu(?string $presentation = null)\`

For presentation, \`null\` detects a Core Admin screen versus a standalone WordPress screen; explicit \`core\` and \`wp-native\` remain available. Core Admin reuses existing \`cb-core-toolbar\` CSS. The native toolbar adapter is scoped and does not load the entire Core Admin theme. All other new component styles use token-optional fallbacks for both contexts.

## Overview / Add / Edit

\`\`\`html
<div class="wrap cb-core-management-workspace">
  <header class="cb-core-management-header">
    <div>
      <h1 class="cb-core-management-header__title">Clause Library</h1>
      <p class="cb-core-management-header__description">Reusable clauses and sections.</p>
    </div>
    <div class="cb-core-management-header__actions">
      <a class="button button-primary cb-core-button cb-core-button--primary" href="CONSUMER_ADD_URL">Add item</a>
    </div>
  </header>
  <!-- Toolbar and table follow -->
</div>
\`\`\`

Use a **separate, directly addressable Add route** and a separate Edit/View route; never embed the Add form above the overview table. Both require consumer-owned capability checks. Separate Add/Edit screens can use \`cb-core-management-form\` and \`cb-core-management-form__actions\` to align field groups, Save and Cancel. Base does not create routes or change existing immutable Draft/Publish transitions.

## Search and Filter Toolbar

Use the existing \`cb-core-toolbar\` structure with native, labeled GET controls:

\`\`\`html
<form method="get" action="WORDPRESS_ADMIN_URL" class="cb-core-toolbar cb-core-toolbar--compact" role="search">
  <input type="hidden" name="page" value="CONSUMER_PAGE_SLUG">
  <label class="cb-core-toolbar__field cb-core-toolbar__field--grow">
    <span class="cb-core-toolbar__label">Search</span>
    <input type="search" name="s" value="" />
  </label>
  <label class="cb-core-toolbar__field">
    <span class="cb-core-toolbar__label">Status</span>
    <select name="status"><option value="">All statuses</option></select>
  </label>
  <div class="cb-core-toolbar__actions cb-core-toolbar__actions--inline">
    <div class="cb-core-toolbar__actions-row">
      <button type="submit" class="button">Apply</button>
      <a href="CONSUMER_CANONICAL_LIST_URL">Clear filters</a>
    </div>
  </div>
</form>
\`\`\`

Each consumer must validate query parameters against explicit allowlists and perform **server-side** search/filter on the full authorized dataset, not just the visible rows. Persist filters through pagination/sorting/back links; reset to the canonical overview URL; distinguish no-records from no-matches. No JavaScript is required for this toolbar.

## Management Table

\`\`\`html
<section class="cb-core-panel">
  <div class="cb-core-management-table-wrap">
    <table class="widefat cb-core-management-table">
      <thead><tr><th scope="col">Title</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
      <tbody>
        <tr>
          <td><span class="cb-core-management-table__cell-label" aria-hidden="true">Title</span>
            <a class="cb-core-management-table__primary" href="CONSUMER_VIEW_URL">Item title</a></td>
          <td><span class="cb-core-management-table__cell-label" aria-hidden="true">Status</span>Draft</td>
          <td><span class="cb-core-management-table__cell-label" aria-hidden="true">Actions</span>
            <div class="cb-core-management-table__actions">
              <a class="button" href="CONSUMER_VIEW_URL">View</a>
              <!-- RowActionMenu::render(...) -->
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</section>
\`\`\`

The mobile cell labels are visual duplicates (\`aria-hidden\`) of semantic table headers. Preserve real table heading associations; do not use clickable \`<tr>\` hacks. CSS rearranges into stacked rows at narrow viewports. Consumers own all column labels, sort parameters, pagination, status and empty result semantics.

## Row Action Menu

\`CoreBlueprint\Core\UI\RowActionMenu::render()\` emits a generic, accessible, declarative three-dot menu; **never** use StatusMenu for arbitrary row actions. Example:

\`\`\`php
echo \CoreBlueprint\Core\UI\RowActionMenu::render( [
    'id'      => 'clauses-actions-42',
    'label'   => 'More actions for clause 42',
    'actions' => [
        [ 'type' => 'link', 'label' => 'Edit', 'url' => $safe_edit_url ],
        [ 'type' => 'submit', 'label' => 'Publish', 'form_id' => 'publish-clause-42' ],
        [ 'type' => 'button', 'label' => 'Archive', 'variant' => 'danger',
          'attrs' => [ 'data-consumer-confirm-archive' => '42' ] ],
    ],
] );
\`\`\`

A \`submit\` action binds by \`form_id\` to a **consumer-rendered POST form elsewhere in the document**. The consumer provides CSRF nonce, action identity, capability checks, state/revision preconditions and server-side confirmation requirements. Consumer JavaScript may handle \`button\` actions; Base never authorizes, fetches, invokes or logs domain mutations. Unsafe attributes (such as inline onclick) are refused. Missing/disabled actions are omitted.

The trigger stays hidden until the Base JS module is active; without JS the consumer's always-visible **View/Edit primary link** is the safe fallback. Do not rely on the overflow for the only way to reach a record. Opening a menu portals its panel into \`document.body\` and positions it with viewport collision handling; rows never expand. It supports one-open-at-a-time, click, keyboard ArrowUp/ArrowDown/Home/End, Escape focus restoration, outside click, scroll/resize repositioning and a \`cb:management-workspace:rendered\` event for host-driven partial updates.

## Acceptance / constraints

- **Core Admin and standalone native:** only requested styles/scripts, with no forced global Core skin; test both mode variants.
- **Security:** real links/forms, no client-side permission authority, POST for mutations, nonce/capability/lifecycle checks in every consumer, no unsafe HTML or action execution inside Base.
- **Accessibility:** visible focus, accessible item-specific trigger, no nested forms, focus restored on Escape; test keyboard and screen readers on an actual page.
- **Responsive:** verify 1600/1280/800/600/360 widths, 200%/400% zoom, long translated strings and mobile row composition; menu portal must remain within viewport.
- **Release qualification:** source regression, PHP/JS syntax, WordPress Level 1/Level 2 where required, browser UAT, consumer adoption and owner acceptance. This document is not a claim of completion.

No merge, CI/CD, public release or deployment without explicit operator authorization.
