# Core Admin List View Foundation, A1/A2 candidate

**State:** Implementation candidate, not a released or accepted public API.
**Source:** new isolated Base feature branch from main, PHP 8.4+.
**First consumer:** Newsletter Lists (A3 after Base acceptance).
**Ownership:** Base renders presentation only. The caller owns SQL, row visibility,
capabilities, nonce-checked mutations, sorting, query state, links, data formatting
and persistence. Native WordPress list tables must not be replaced.

## Public opt-in

An extension-registered Core Admin page declares the semantic requirement
**foundations: [list-view]** in PageRegistry::register(). This is the only asset
request used by an ordinary extension; do not import private Base styles.

A consumer with a separately owned admin screen may invoke the public PHP
CoreBlueprint\Core\UI\ListView::enqueue() on the authorized admin_enqueue_scripts
hook, scoped to its own screen.

The sole presentation entrypoint is
CoreBlueprint\Core\UI\ListView::render(array $args): string.

### Example: Newsletter Lists index

    use CoreBlueprint\Core\UI\ListView;

    echo ListView::render([
      'title' => __('Lists', 'core-blueprint-newsletter'),
      'description' => __('Manage newsletter audiences', 'core-blueprint-newsletter'),
      'primary_action' => [
        'url' => $authorized_add_url,
        'label' => __('Add list', 'core-blueprint-newsletter'),
      ],
      'filters' => [
        'action' => admin_url('admin.php'),
        'hidden' => ['page' => 'core-blueprint-newsletter-lists'],
        'search' => [
          'name' => 's',
          'label' => __('Search lists', 'core-blueprint-newsletter'),
          'value' => $validated_search,
        ],
        'selects' => [[
          'name' => 'type',
          'label' => __('Type', 'core-blueprint-newsletter'),
          'value' => $validated_type,
          'options' => [
            '' => __('All types', 'core-blueprint-newsletter'),
            'static' => __('Static', 'core-blueprint-newsletter'),
            'dynamic' => __('Dynamic', 'core-blueprint-newsletter'),
          ],
        ]],
        'submit_label' => __('Filter', 'core-blueprint-newsletter'),
        'reset' => [
          'url' => $validated_reset_url,
          'label' => __('Reset', 'core-blueprint-newsletter'),
        ],
      ],
      'columns' => [
        ['id' => 'name', 'label' => __('Name', 'core-blueprint-newsletter'),
          'sort' => ['url' => $allowlisted_sort_url, 'direction' => 'ascending']],
        ['id' => 'type', 'label' => __('Type', 'core-blueprint-newsletter')],
        ['id' => 'actions', 'label' => __('Actions', 'core-blueprint-newsletter')],
      ],
      'rows' => [[
        'cells' => [
          'name' => [
            'text' => 'General Newsletter',
            'url' => $authorized_edit_url,
            'strong' => true,
          ],
          'type' => ['text' => __('Static', 'core-blueprint-newsletter'), 'tone' => 'info'],
          'actions' => [
            'text' => '',
            'actions' => [['url' => $authorized_edit_url,
              'label' => __('Edit', 'core-blueprint-newsletter')]],
          ],
        ],
      ]],
      'labels' => [
        'scroll' => __('Lists table, scroll horizontally if needed', 'core-blueprint-newsletter'),
        'pagination' => __('Lists pages', 'core-blueprint-newsletter'),
        'previous' => __('Previous', 'core-blueprint-newsletter'),
        'next' => __('Next', 'core-blueprint-newsletter'),
      ],
      'pagination' => [
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'summary' => $localized_summary,
        'previous_url' => $allowlisted_previous_url,
        'next_url' => $allowlisted_next_url,
      ],
      'empty_message' => __('No matching lists.', 'core-blueprint-newsletter'),
      'error' => false,
      'error_message' => __('Lists could not be loaded.', 'core-blueprint-newsletter'),
    ]);

This example is **illustrative**. It is not an implemented Newsletter consumer,
and the above URLs/query fields must be provided from existing authorized
Newsletter services rather than assumed safe. Do not copy it verbatim without
adapting to its actual screen.

## Arguments and failure contract

Required: translated nonblank title, 1..20 columns with distinct lowercase
kebab/snake identifiers and translated labels, and 0..500 pre-authorized rows.
An invalid descriptor throws InvalidArgumentException. No arbitrary HTML,
unbounded callback execution, domain SQL, update endpoint or browser data store.

Optional heading description and primary action link are displayed only when
the caller supplies them. They **do not** grant permission. Each row maps
column IDs to scalar text or a descriptor with text, optional URL, strong flag,
tone (neutral/info/success/warning/danger) and optional link actions
(maximum eight per cell). Strings use WordPress escaping; URLs use esc_url().
Mutation links/actions are consumer-owned and must be validated server-side.

Optional filters are one native GET form with scalar hidden route values,
a labeled search input, up to sixteen labeled select controls (up to 100 options
each), a translated submit label and optional reset link. The consumer validates
filter names/types/allowed values and constructs the action/reset URL, while Base
also rejects structurally malformed descriptors. Base never infers query
or sorting behavior.

Optional sort descriptors supply a pre-authorized URL and exact aria-sort
direction ascending/descending, or neither. Only valid active directions
receive aria-sort. Pagination accepts integer page, pages, total and
caller-prepared previous/next URLs and translated summary/navigation labels.
The consumer preserves its own filters and page parameter in those URLs.

Empty and error states use caller-supplied translated messages and no dummy
table. No pretend loading state in server-rendered MVP. The presentation uses
a focusable scroll region, semantic table headers, responsive layouts, visible
focus, logical RTL-friendly CSS and the existing Base toolbar. There is no
new JavaScript runtime, network call or data store.

All user-facing words are supplied through translated consumer descriptors.
Therefore Base adds **zero new translatable strings** and its six PHP locale
catalogs remain aligned without artificial translations. Consumer locales
must match EN source + NL/DE/FR/ES/IT/PT.

## Exclusions and acceptance

This is a custom Core Admin table composition, not a WP_List_Table replacement.
CRM native Contacts remain on WordPress's existing list table. Work's Quick Edit,
CAS/authorization, per-user column reorder/density, bulk edits, asynchronous
refresh and multi-view state stay with Work; corresponding generic opt-ins are
A5. Notes list/cards and Automations cards must not be converted just to comply.

Newsletter A3 migrates ONLY ListsPage::render_index(). Preserve Lists::query()
at 25/page, CRM contact permissions, static/dynamic rule evaluation, create/edit
forms, Members/Usage, sending permission, nonces, and database schema. Humanize
Static/Dynamic and display dates with correct site-timezone semantics inside
Newsletter, not Base. Independent Newsletter B2.2 functional E2E must proceed.

Acceptance: exact-head Base Level 1, real WordPress Level 2 source+runtime,
mobile and RTL visual QA, keyboard/no-JS checks, canonical reproducible ZIP
after explicit appropriate build approval, and explicit merge GO. No existing
test database reset or new test-resource provisioning from this document.
Other Base feature branches (e.g. Template Library T2) remain independent:
reconcile PageRegistry changes before future merges.
