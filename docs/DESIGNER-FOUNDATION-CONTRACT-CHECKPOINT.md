# Designer Foundation Contract Checkpoint — DF-D3 / DF-D4

**Status:** SOURCE candidate only; FIELD, Foundation Contract Acceptance and Golden remain **HOLD**.  
**Baseline:** `47ef53bb4e57e99c30e1e47cf62abea253a01ca5`  
**Product:** Core Blueprint Base 1.0.0.

## Public API and source capability versions

Use `@cb-core/design-editor` or documented `window.cbCore.designEditor`, never imports from `assets/js/design/` internals. Source-confirmed PHP `CoreBlueprint\\Core\\Design\\Editor\\Assets` capability values:

| Capability | Source value |
| --- | ---: |
| `DOMAIN_SESSION_API` | 3 |
| `DESIGNER_ELEMENTS_PALETTE_API` | 1 |
| `DESIGNER_CONTEXT_PLACEMENT_API` | 1 |
| `DESIGNER_NODE_MOVE_API` | 1 |
| `DESIGNER_INTERACTION_API` | 1 |

Public ESM bindings include `createDesignerDragDropController`, `createDesignerInteractionPresentation`, `createDesignerSelectionController`, `createDesignerElementsPalette`, `resolveDesignerInsertPlacement` and `resolveDesignerMovePlacement`. Relevant global facade paths: `shell.dragDrop.createController`, `shell.interactions.createPresentation`, `shell.selection.createController`, `shell.elements.createPalette` and `shell.placement.resolveInsert/resolveMove`. Capability checks and the executable JS export must match the **same installed ZIP**. Source availability alone is not FIELD acceptance.

## Ownership

**Base owns HOW:** pointer/keyboard DND, selection/history mechanisms, interaction-state presentation, canonical outlines, drag-dot, drop feedback, accessibility rules, lifecycle/cleanup and dispatch to authorized commands.

**Consumers own WHAT:** document schemas and rendering, permitted elements/parents, domain validation, editing forms, editing leases, authorized registered commands, persistence and server-side permission checks. Adapters may bind bounded click/keyboard node-activation and domain-specific editing events, but must route Selection through the Base session and must not implement a second Selection/History/DND engine or independent interactive outlines.

## DND commit semantics

The existing public `createDesignerDragDropController({root,isReady,getContextKey,resolveIntent,canDrop,commit,onStatus})` signature is unchanged.

| Synchronous `commit({source,intent})` outcome | Status |
| --- | --- |
| `undefined`, `true` | `committed` |
| `false`, thrown exception | `refused` |
| Promise / thenable | `refused` (async invalid) |

Other synchronous values remain legacy-tolerated for DND v1. New consumers MUST use `undefined`/`true` on success and `false`/throw on refusal. A domain callback must validate before mutating; a mutation followed by `false` cannot be rolled back by the DND controller. The gesture is invalidated *before* commit, preventing duplicate command dispatch. Invalid drops never produce committed status.

## Selection, editing and keyboard

Enter/Space selects only the directly focused canvas node. Events from nested nodes, links, inputs or editing controls must not reselect a parent. Mail's F2/double-click editing callbacks remain domain-owned. Base keyboard DND starts on Alt+ArrowDown, uses arrow keys for placement, confirms with Enter/Space and cancels with Escape. During active DND, adapter selection is suppressed regardless of handler ordering. Native adapters inspect both `event.defaultPrevented` and the existing internal Host `data-cb-design-dnd-active` state. **That DOM attribute is not a public consumer API.** Consumers must comply with the behavior contract without depending on private Host implementation details.

## Lifecycle and cleanup

Source/zone registrations must reference current connected DOM or generation-checked trusted parent proxies. Release registrations before destructive renderer replacement. On context change or teardown, cancel stale gestures and destroy DND, presentation, listeners, observers, iframe preview and subscriptions. Validate current context, project, permission and `canApply` before any registered command; geometry/DOM paths are not authority. Selection is transient and never itself a History or Save step.

## Legacy Reports proxy infrastructure (deferred)

Maintenance Reports still renders hidden `[data-cb-design-drop-zone-id]` geometry proxies for compatibility with its historical render/region mapping; they remain inert and unregistered as native or keyboard drop targets. The trusted parent `[data-cb-design-node-path]` interaction regions already serve as canonical registered source/target surfaces. Geometry mapping/reuse/cleanup remains in place pending a separate maintenance decision. **No new consumer may depend on these legacy proxies.** Sandbox/CSP and verified read-only iframe geometry remain unchanged.

## Consumer acceptance

Contracts and independent Reports require separate Overseer SOURCE GO and integration tests against the exact approved Base package. FIELD, Foundation Contract Acceptance, Golden, merge and release are separate gates. Historical SOURCE candidate notes remain in `docs/DESIGNER-FOUNDATION-API.md`; this document does not upgrade any unaccepted source candidate into installed-product acceptance.
