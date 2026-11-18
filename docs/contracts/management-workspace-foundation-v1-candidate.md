# Core Blueprint Management Workspace Foundation — v1 candidate contract

**State:** DESIGN CANDIDATE — NOT A PUBLISHED PUBLIC API.  
**Owner:** Core Blueprint Base.  
**Baseline:** `main` at `2c06d146e6c9f216cd3613201a0d8583939156f5` (18 November 2026).  
**First consuming extension after Base validation:** Contracts — Clause Library.  
**Additional reference consumer:** Bookings management pages.

## Purpose

Provide one accessible, responsive management pattern for recurring WordPress-suite screens, without centralizing any extension's business logic:

`Overview → Add → View/Edit`, with reusable page heading and primary action, shared search/filter composition, consistent tabular data, and one primary row action plus a compact overflow action menu.

This is **not** a replacement for the separate Base dashboard `overview-framework` and **not** a new JavaScript UI framework.

## Verified starting point

- Existing Core Admin `cb-core-toolbar` / `cb-core-toolbar--compact` in `assets/css/components/filter-bar.css` already handles responsive labels, controls, actions and wrapping; historical `cb-core-filter-bar` remains an internal compatibility alias.
- Existing `cb-core-panel cb-core-panel--table` supports full-bleed `widefat` table layout; arbitrary management tables are **not** a public Base primitive under the current `docs/CORE-ADMIN-DESIGN-FOUNDATION.md` contract.
- Base's `StatusMenu` PHP, CSS and JS provide a status-specific menu with keyboard handling. This should inform the generic action menu, not be misrepresented as an existing general row-menu API.
- `PageRegistry::COMPONENT_REQUIREMENTS` currently does not expose `toolbars` or `management-table`. The private asset catalog already knows `component.filter-bar`.
- Bookings provides a `View` action and conditional `<details>` actions; its details panel currently expands within the table row. Bookings also owns feature-specific management-table CSS and GET date/status filters.
- Contracts' AUTH-02 Clause Library currently embeds a create form above a `widefat striped` overview and has dedicated edit screen routing. This candidate does not alter AUTH-02 publication, immutable versioning or permissions.

## Canonical composition

### A. Management workspace / navigation

1. An overview page contains one `h1`, an optional concise description, and at most one prominent Add action aligned to the right on wide screens.
2. Add and Edit are **separate navigable screens**, not permanently expanded forms above the overview.
3. Each secondary screen has a heading, clear Back/Cancel navigation, explicit Save/Publish semantics and preserved unsaved-change safety if its editor needs it.
4. Base owns semantic layout classes, spacing, responsive header, and token-backed presentation. The consumer owns routes, URL generation, capabilities, forms and storage.
5. Do not confuse **Overview** in this pattern with Base's dashboard `overview-framework`.

### B. Search and Filter Toolbar

1. Reuse the **existing** `cb-core-toolbar` markup where applicable; publish it as a genuine semantic component only after consumer-safe asset resolution and testing.
2. A labelled search field grows; optional consumer-defined status/type/date filters share the row; Apply and Clear/Reset sit in one consistent action cluster.
3. Native GET forms must work without JavaScript. Filters and search are represented by explicit, bounded query parameters, preserving current filters in pagination, sorting, detail Back URLs and browser history.
4. Reset links return to a canonical consumer-owned list URL. A distinct 'no rows exist' empty state versus 'no rows match filters' state is required.
5. The consumer owns search authorization, query validation, database filtering and result counts. Base **never** filters only rendered table rows, assumes a backend endpoint, or trusts a client-provided filter key.
6. Desktop shows one coherent toolbar; narrow screens stack fields in document order with visible labels and accessible hit areas.
7. Support both presentations: Core Admin token-based theme and a scoped WordPress-native adapter for standalone extension admin screens. Do not globally enqueue the entire Core Admin visual skin.

### C. Management Table

1. A truly generic, opt-in Base table composition with stable row spacing, sortable-heading affordance, overflow-safe content, consistent status badges, empty/results states and pagination composition.
2. Use semantic HTML table markup on desktop. Mobile treatment must preserve row labels, accessible associations, keyboard order and primary/action access. Do **not** hide the underlying data meaning or force tiny horizontal content into the viewport.
3. Column definitions, data, sort allowlists, counts, links, pagination state and capability gates stay with each consumer.
4. Reuse Base's existing `panels`, `buttons`, `state-badges` / `status`, `empty-state` and `form-controls`. Avoid duplicating their canonical style inside consumers.
5. Rows may display only **one** primary action inline, usually View or Edit. Additional actions belong in the row action menu.

### D. Row Action Menu (not the Status Menu)

1. Default row action cluster: one primary visible link/button and an adjacent compact three-dot (`ellipsis`) menu trigger with an item-specific accessible label (e.g. 'More actions for CB Clause #1').
2. Menu opens as a **floating overlay**, never increasing table-row height. Viewport collision, bottom-row placement, scrolling containers, zoom and mobile bounds are required design/test cases.
3. Only one action menu is open at once. Support click/touch, Enter/Space/ArrowDown to open; ArrowUp/ArrowDown/Home/End navigation; Escape closes and returns focus to the trigger; outside interaction and navigation close.
4. Use appropriate accessible native link/button behavior and truthful disabled/unavailable states. Do not advertise unavailable actions.
5. Separate potentially destructive actions visually and require consumer-owned confirmation where warranted. **Only POST + CSRF nonce + capability/lifecycle rechecks** may mutate state. Base never authorizes, runs or stores consumer actions.
6. Prefer reusing proven accessibility patterns from StatusMenu, but avoid overloading the status-specific component or creating a second conflicting global controller.
7. Plain, no-JS fallback must keep primary navigation available and must not silently expose unsafe mutations.

### E. Forms and feedback

1. Separate Add/Edit screen uses Base field, form-control, button and notice primitives. Consumer owns field semantics, required state, validation, errors, persistence and redirect receipts.
2. Empty/loading/error/success states use existing Base semantic components.
3. Neither a shared form renderer nor the workspace is permitted to bypass existing draft/publish workflows, immutable revisions, capability checks, or nonces.

## Public API / implementation decision required

Expose a small **opt-in set of semantic requirements** rather than consumers importing private CSS file paths or WordPress asset handles:

- `toolbars` — reuse and validate the existing `cb-core-toolbar` composition;
- `management-table` — a new generic table presentation, not a Base-owned data query engine;
- `row-action-menu` — generic menu markup/presentation/interaction;
- `management-workspace` — optionally shared heading/add and form-layout composition.

The exact public IDs, PHP renderer(s) and stylesheet/module asset selection must be reconciled with `PageRegistry`, `AdminAssetCatalog`, `UI\Assets` and the existing Core Admin/native presentation boundary **before declaring a stable API**. If several concerns prove to be merely composition, prefer documented markup over unnecessary new PHP classes.

## Execution plan and acceptance gates

**BASE-MW-01 — contract + inventory:** Pin the existing public surfaces, determine owner of native adapters, register a non-mutating reference fixture and map every component to its asset-enqueue boundary. This document is the first deliverable, not production implementation.

**BASE-MW-02 — generic UI source:** Implement only missing Base-owned markup/presentation/JS. Keep search/filter execution, database queries, CRUD and permission rules in consumer repositories. Add JS/WordPress regression coverage for registration, scoped assets and no-JS fallback.

**BASE-MW-03 — browser/quality qualification:** PHP and JS syntax, source tests, runtime pairing, actual desktop/mobile menu placement and keyboard/focus tests, color themes, long text/RTL/zoom and no-jQuery verification. No forced CI or merge. Golden remains HOLD until operator acceptance.

**CONTRACTS-AUTH-02 consumer:** Once Base is qualified, make Clause Library list-only with an Add item route and Edit route. Reuse Base toolbar, table, primary action + dropdown; keep unchanged publish/immutable storage semantics and retest Draft → Save → Publish → Designer Elements.

**Other consumers:** Gradually adopt per feature, beginning with Bookings as visual reference but not copying its row-expanding `<details>` implementation. No bulk suite-wide CSS/DOM migration.

## Non-goals

- No new CRUD framework, frontend framework, database tables, REST endpoints, or consumer-side action executor in Base.
- No wholesale admin redesign or unrelated Designer / Template Library edits.
- No forced entire Core Admin skin for standalone WP admin screens.
- No migration of consumer-specific actions, status lifecycles or filters to Base.
- No merge, release, deploy or CI without a separate explicit approval.
