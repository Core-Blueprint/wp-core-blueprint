# Designer Toolbar + Browser Foundation v2 (source candidate)

**Scope:** Core Blueprint Base; source branch `feature/designer-toolbar-browser-foundation-20261110` on T3-02. **No merge or customer-release authority.**

## Canonical toolbar placement

- **Start:** Base lockup/Designer identity, with canonical adaptive drawer launchers when necessary
- **Center:** profile-owned view controls, such as Mail responsive viewports or Reports document view, not resource selection
- **End — resource group:** Browser icon-only action; providers supply trusted resource metadata, Base controls geometry
- **End — operations group:** Undo/Redo, existing native Save, **Close last**; no parallel Save/History/Exit Guard

The shared compact toolbar policy, existing keyboard navigation and responsive rules remain authoritative. Both **native Platform** (Mail/Reports) and **classic extension Designer shells** now compose the same zone geometry. Classic consumers retain their authorized context selector inside the end/resource group until their consumer-specific Browser adapter is implemented and verified; never remove working context navigation in a forced visual conversion. Consumers must not hardcode their own workaround for the previous left-side selector.

## Workspace information architecture

The existing shared `@cb-core/template-browser` remains the foundation: Base workspace modal, expandable size, a **category pane**, a **collection pane** and a **searchable grid**. The Bricks references inform the information architecture, not branding, UI code or assets.

On the first Mail consumer:
- **Designs** lists the real current-site registered Mail resources from the server-authored select; their provider groups become collections. **Open design** is navigation (via canonical Exit Guard `request(..., {intent:'switch'})`) only after the Browser closes, never Template Apply. A clean switch is immediate after all canonical fences; a dirty switch requires Save/Discard/Cancel with the existing localized `transitionConfirm` and `transitionDirtyDescription` labels
- **Templates** lists built-in/personal/site/pack assets via the signed Template Browser scope and existing authorized read-only metadata requests
- **Use template** uses existing source-specific Apply endpoint and the Host's `captureApplyIntent()` / `applyTemplate()` authority. Never replace the active resource or save automatically
- **Save as template** invokes Mail's existing validated create on a separate reusable asset; not the operational Save path
- The current active design has an explicit selected-state marker in the Browser. Long resource labels and provider metadata are absent from the toolbar

The toolbar icon is Base-owned and disabled until the Mail binder registers against a READY Host. Browser callbacks are consumer capabilities; the Base Browser does not authorize a new write.

## Gate plan

1. Verify all Base Level 1, static ESM import graph, six canonical PHP translations and deterministic internal dev-package gates
2. Operator-installed ZIP on existing WordPress sandbox: Mail Browser icon/tooltip/focus, three-pane layout, switching a clean resource and dirty Save/Discard/Cancel via Exit Guard, template Apply with Undo/Redo, Save as personal/site with readback, manager empty/error/loading, light/dark and compact responsive behavior, focus and keyboard Escape
3. Confirm Reports native toolbar layout and that the Browser is not displayed without a consumer capability; audit external consumer integration separately against published Base shell contracts
4. Formal WordPress Level 2, Level 3, CI and public release remain HOLD until their own evidence; no inference from an internal build

**Known limit:** The landing Saved Templates screen may have failed to execute `@cb-core/mail-saved-library` when `@cb-core/toast` was not enqueued independently of fullscreen Platform. This source patch supplies the missing static toast dependency on the landing route and replaces the indefinite server-side loading fallback with a visible error state. Browser FIELD proof is still required; do not claim the symptom root cause verified until acceptance.
