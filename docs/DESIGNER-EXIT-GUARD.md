# Base Designer Exit Guard v1

Status: **SOURCE candidate. Installed Base + Automations acceptance is still required.**

## Ownership and lifecycle

Base owns all user-initiated fullscreen exits, including the shared close button,
the fullscreen toggle and Escape. The public shell emits a synchronous,
cancelable `cb:design-shell:beforeexit` event before mutating fullscreen state.
The Base Designer launch runtime cancels that first attempt and displays the
canonical Base Modal Foundation confirmation. Only after confirmation does
Base replay the same fullscreen control with a one-use approval.

The forced `immediate: true` internal shell teardown remains exempt; callers
must never use it as an alternative user-close route. Do not navigate directly
or synthesize the fullscreenchange event from a consumer.

## Public consumer API

```js
const unregister = window.cbCore.designerExitGuard.register(shell, {
    isDirty: () => Boolean(consumerHasUnsavedChanges()),
    save: async () => {
        await saveThroughExistingAuthoritativeReceiptPath();
        // A failed/conflicting/uncertain receipt must throw or return false.
        // Base also checks isDirty() after the awaited Save.
        return true;
    },
    labels: {
        cleanTitle: 'Close Designer?',
        cleanDescription: 'Return to Automations?',
        dirtyTitle: 'Unsaved changes',
        dirtyDescription: 'Save or discard your changes before closing.',
        choiceTitle: 'What should happen to your changes?',
        save: 'Save changes',
        discard: 'Discard changes',
        close: 'Close Designer',
        cancel: 'Cancel',
        busy: 'Wait for the current save to finish.',
    },
});
```

Consumers own **only** the dirty predicate, existing Save authority and
translated user-facing labels. Base owns the modal, single-flight guard,
fullscreen replay, toast mapping and native `beforeunload` for registered
unsaved work. The adapter must not create a second exit modal, Escape handler
or browser unload handler. Context-switch confirmation is a separate consumer
operation.

The optional `save()` callback is required for **Save & Close**. It must return
the literal boolean `true` after verified persistence, not merely after a 2xx
response; if unavailable, only an explicitly selected Discard may exit a dirty
Designer. Base never initiates a retry after an ambiguous Save and never treats
an unresolved newer edit as saved.

Base provides a guarded confirmation with a warning about potentially unsaved
work when a consumer has **not yet** registered `isDirty()`; this transitional
fallback cannot prove a clean state. Products must register before their
Designer is declared ready. The same installed Base package must advertise
`CoreBlueprint\Core\Design\Editor\Assets::DESIGNER_EXIT_GUARD_API >= 1`.

## Exit decision matrix

| State | Behavior |
| --- | --- |
| Clean | Confirm / Cancel; recheck dirty state before exit |
| Dirty, Save adapter | Save changes / Discard changes / Cancel; no second modal |
| Dirty, no Save adapter | Explicit Discard / Cancel; never claim saved |
| Busy Save | Block exit, Base warning Toast |
| Uncertain Save, persistence refusal or newer edits | Keep Designer open; inline error and domain recovery |
| Modal already open | Escape is owned by that dialog, not by Designer exit |
| Browser/tab navigation | Native warning only for registered dirty state and unapproved exit |

## Acceptance requirements

1. Base-only direct and manual fullscreen exit tests, including Escape.
2. Automations single-modal clean and dirty acceptance with a compatible same-ZIP Base.
3. Save & Close with verified CAS receipt, refusal, network-loss uncertainty and
   post-submit edits; revision and Undo/Redo stay authoritative.
4. i18n parity for shared Base text and all consumer-provided labels (six locales).
5. Successful Level 1, installed smoke and build verification. No main merge
   without explicit owner GO.
