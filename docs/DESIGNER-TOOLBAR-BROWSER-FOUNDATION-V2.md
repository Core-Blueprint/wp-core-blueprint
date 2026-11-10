# Designer Toolbar + Browser Foundation v2 (source candidate)

**Scope:** Core Blueprint Base; source branch `feature/designer-toolbar-browser-foundation-20261110` on T3-02. **No merge or customer-release authority.**

## Canonical toolbar placement

- **Start:** Base lockup/Designer identity, with canonical adaptive drawer launchers when necessary
- **Center:** profile-owned view controls, such as Mail responsive viewports or Reports document view, not resource selection
- **End — resource group:** Browser icon-only action; providers supply trusted resource metadata, Base controls geometry
- **End — operations group:** Undo/Redo, existing native Save, **Close last**; no parallel Save/History/Exit Guard

The shared compact toolbar policy, existing keyboard navigation and responsive rules remain authoritative. Both **native Platform** (Mail/Reports) and **classic extension Designer shells** now compose the same zone geometry. Classic consumers retain their authorized context selector inside the end/resource group until their consumer-specific Browser adapter is implemented and verified; never remove working context navigation in a forced visual conversion. Consumers must not hardcode their own workaround for the previous left-side selector.

## Workspace information architecture

The existing shared `@cb-core/template-browser` remains the foundation: Base **fullscreen modal by default** (`size: 'fullscreen'`), native accessible header Close (no Expand control), a **category pane**, a **collection pane** and a **searchable grid**. The Bricks references inform the information architecture, not branding, UI code or assets.

On the first Mail consumer:
- **Designs** lists the real current-site registered Mail resources from the server-authored select; their provider groups become collections. **Open design** is navigation (via canonical Exit Guard `request(..., {intent:'switch'})`) only after the Browser closes, never Template Apply. A clean switch is immediate after all canonical fences; a dirty switch requires Save/Discard/Cancel with the existing localized `transitionConfirm` and `transitionDirtyDescription` labels
- **Templates** lists built-in/personal/site/pack assets via the signed Template Browser scope and existing authorized read-only metadata requests
- **Use template** uses existing source-specific Apply endpoint and the Host's `captureApplyIntent()` / `applyTemplate()` authority. Never replace the active resource or save automatically
- **Save as template** invokes Mail's existing validated create on a separate reusable asset; not the operational Save path
- The current active design has an explicit **Current** marker distinct from the radio-based **Selected** state. Long resource labels and provider metadata are absent from the toolbar

The toolbar icon is Base-owned and disabled until the Mail binder registers against a READY Host. Browser callbacks are consumer capabilities; the Base Browser does not authorize a new write.

## Gate plan

1. Verify all Base Level 1, static ESM import graph, six canonical PHP translations and deterministic internal dev-package gates
2. Operator-installed ZIP on existing WordPress sandbox: Mail Browser icon/tooltip/focus, three-pane layout, switching a clean resource and dirty Save/Discard/Cancel via Exit Guard, template Apply with Undo/Redo, Save as personal/site with readback, manager empty/error/loading, light/dark and compact responsive behavior, focus and keyboard Escape
3. Confirm Reports native toolbar layout and that the Browser is not displayed without a consumer capability; audit external consumer integration separately against published Base shell contracts
4. Formal WordPress Level 2, Level 3, CI and public release remain HOLD until their own evidence; no inference from an internal build

**Known limit:** The landing Saved Templates screen may have failed to execute `@cb-core/mail-saved-library` when `@cb-core/toast` was not enqueued independently of fullscreen Platform. This source patch supplies the missing static toast dependency on the landing route and replaces the indefinite server-side loading fallback with a visible error state. Browser FIELD proof is still required; do not claim the symptom root cause verified until acceptance.

## FIELD-04: Base Modal fullscreen presentation (2026-11-10)

- The shared `@cb-core/modal` supports an explicit `size: 'fullscreen'` in both Core and WordPress-native presentations. It opens in the same **inset maximized geometry** as an expanded workspace, preserving a visible modal border, corner radius and backdrop without inherited workspace max-height. Native `<dialog>` focus trapping and Escape cancellation remain unchanged.
- Fullscreen creates a top-right icon-only Close control with the shared `x` icon and the existing translated Close label. Clicking it follows the same **cancel** route as Escape; while a confirm/apply callback is pending, Close is disabled and cannot dismiss the modal or authorize a navigation.
- Ordinary/wide/workspace dialogs remain unaffected; existing optionally expandable workspace functionality is still available for legitimate other consumers. There is **no legacy modal shim or migration**.
- Only the Designer Template Browser explicitly requests fullscreen. This does not imply Reports already has its own Browser source integration.
- **Patch boundary:** modal presentation only. Internal Browser navigation, collections, cards and actions remain for the separately accepted Browser UX phase. FIELD-01/02/03 remain open.

## FIELD-04B: Inset maximized Browser and full-height results (2026-11-10)

- Core and WordPress-native adapters share the same inset geometry for fullscreen Browser and manually maximized workspace dialogs. The Browser retains a header Close without a size toggle.
- The modal body, Browser mount and shell fill available height between title and actions. Contextual results scroll in a keyboard-focusable region; the search toolbar and result footer remain visible while cards keep intrinsic row heights.
- Other modals and the standalone Template Library page retain existing layouts. Save, selection, Apply and navigation authority are unchanged; no migration or legacy layer.
- New Level 1, build, WordPress field and visual acceptance remain required. Browser Workspace visual refinement is the next independent phase.

## FIELD-05 — Browser Workspace Golden UX (source candidate, 2026-11-10)

- **Hierarchy:** existing three-column Designer Browser remains canonical. The primary navigation and scoped collection buttons have calmer states and design-provider counts. The result toolbar exposes the active collection heading with a contextual count and the existing search/category filters; the irrelevant category field is actually hidden for Designs.
- **Cards:** only the contextual Browser receives more compact visual rhythm, two-line-ready titles, subdued metadata and a neutral placeholder *without claiming a real design preview*. The `Current` badge marks the active resource and must never be confused with a checked selection. Checked radio inputs alone carry selection emphasis.
- **Actions:** `Save as template` moves from a full-width strip below the browser to the bottom of the navigation pane, remaining an existing consumer callback. The bottom Base modal actions `Close` and `Open / use selected` are unchanged. The Browser's internal pagination footer appears for contextual results only while a next-page cursor exists.
- **Responsive and accessibility:** keyboard-focusable result scroll region, radio controls, `aria-pressed` navigation, readable focus rings and responsive pane stacking remain authoritative; the global Template Library page is not redesigned.
- **Out of scope:** loading document content for visual previews, new template categories, taxonomy persistence, Save/History/Apply authorization, extension retrofits, and migration/legacy support. Bricks is an information-architecture reference only; no copied assets/code.
- **Acceptance:** source-level tests and clean build are necessary but do not replace an operator-installed dark/light and compact-width UX assessment. After FIELD approval, review preview-data contracts as a distinct product decision.

## FIELD-05B — Adaptive Browser view composition (source candidate, 2026-11-10)

**Owner decision:** resource **Designs** and reusable **Templates** should not share the same visual card treatment. The Bricks reference informs hierarchy only; there is no copied interface, code or imagery.

- **Designs:** two-column-capable, compact selection rows with a native radio and resource title, provider metadata and a separate `Current` badge. No preview placeholder: a registered live design is not itself a previewable template asset. `Current` remains informational; `Selected` derives solely from the checked radio. Responsive layouts use one column when space is limited.
- **Templates:** continue as a visual grid with a dedicated preview area and metadata. Previews remain neutral placeholders until actual preview data can be verified and authorized; no generated or fabricated previews are injected.
- **Switching and filters:** `data-browser-view` is maintained by the existing shared Browser view state, not by a second DOM implementation. Category is hidden for Designs and when the scoped Templates view has no available category facet. Existing signed `sources` and `templates` endpoints, pagination, selection reset, keyboard-native controls, resource switch after close and consumer Apply/Save/Exit Guard contracts remain unchanged.
- **Toolbar:** search/category stay native labelled controls; their labels become visually hidden but screen-reader-accessible for a compact one-line presentation. The active collection heading, count and search remain visible; wrapping handles narrower viewports.
- **Scope:** Base's contextual `@cb-core/template-browser` only. The global Template Library page is unchanged. No new folders, Sort/Preview APIs, Mail-specific overrides, legacy compatibility or data migrations. No modal geometry changes.

**Qualification gates:** targeted adaptive source/behavior regressions + canonical Level 1 and dev build, followed by installed WordPress Dark/Light and compact viewport inspections, radio/keyboard focus, category/filter behavior, Templates preview region, Save as template, Open design/Exit Guard and Apply. User acceptance is required; this source checkpoint is not a Golden-accepted release.
