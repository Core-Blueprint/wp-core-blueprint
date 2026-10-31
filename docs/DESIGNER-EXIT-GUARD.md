# Base Designer Exit Guard v3

Status: **DF-EXIT-01 SOURCE candidate. Level 1, build, FIELD and combined consumer acceptance pending.**

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
    discard: () => {
        // Restore the last confirmed consumer baseline, including the UI.
        return restoreConsumerBaselineSynchronously() === true;
    },
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

Consumers own the dirty predicate, existing Save authority, mandatory synchronous
Discard restoration and optional product-specific translated label overrides. Base owns the canonical
13 translated default labels in six locales (NL/DE/FR/ES/IT/PT) through
`wp_localize_script` on `DESIGNER_EXIT_GUARD_SCRIPT`, before the JS guard
initializes. The two existing source strings `Save changes` and `Cancel`
are reused rather than duplicated. Domain-specific overrides take precedence;
unspecified labels inherit translated Base defaults. Base owns the modal, single-flight guard,
fullscreen replay, toast mapping and native `beforeunload` for registered
unsaved work. The adapter must not create a second exit modal, Escape handler
or browser unload handler. Context-switch confirmation is a separate consumer
operation.

The mandatory `discard()` callback is required for **Discard & Close**. It must
restore all local domain and presentation fields, agree with the existing
accepted baseline and return primitive `true` synchronously. A Promise,
missing implementation, failed projection, active Save or uncertain persistence
never authorizes exit. Base performs final postconditions without an additional
`await` before the one-use exit callback. Consumers must not use `form.reset()`,
a post-fullscreen-change reset or a second exit controller.

Context switching uses the existing authorized target transaction. A dirty
source is not reset before the target is validated and committed. Native Mail
and Maintenance Reports share the same Exit Guard through the Platform Host;
that Host restores both project and persisted-field baselines synchronously,
without creating a second Save Authority or unload listener.

The optional `save()` callback is required for **Save & Close**. It must return
the literal boolean `true` after verified persistence, not merely after a 2xx
response; if unavailable, only an explicitly selected Discard may exit a dirty
Designer. Base never initiates a retry after an ambiguous Save and never treats
an unresolved newer edit as saved.

Base provides a guarded confirmation with a warning about potentially unsaved
work when a consumer has **not yet** registered `isDirty()`; this transitional
fallback cannot prove a clean state. Products must register before their
Designer is declared ready. The same installed Base package must advertise
`CoreBlueprint\Core\Design\Editor\Assets::DESIGNER_EXIT_GUARD_API >= 3`.

## Exit decision matrix

| State | Behavior |
| --- | --- |
| Clean | Confirm / Cancel; recheck dirty state before exit |
| Dirty, Save adapter | Save changes / verified synchronous Discard / Cancel; no second modal |
| Dirty, no Save adapter | Explicit verified synchronous Discard / Cancel; never claim saved |
| Busy Save | Block exit, Base warning Toast |
| Uncertain Save, persistence refusal or newer edits | Keep Designer open; inline error and domain recovery |
| Modal already open | Escape is owned by that dialog, not by Designer exit |
| Browser/tab navigation | Native warning only for registered dirty state and unapproved exit |

## Acceptance requirements

1. Base-only direct and manual fullscreen exit tests, including Escape.
2. Automations single-modal clean and dirty acceptance with a compatible same-ZIP Base.
3. Save & Close with verified CAS receipt, refusal, network-loss uncertainty and
   post-submit edits; revision and Undo/Redo stay authoritative.
4. Canonical Base gettext catalog/source parity for all 13 Exit Guard defaults
   (six locales), runtime default injection and optional consumer override
   precedence. Product-specific translations remain consumer-owned.
5. Successful Level 1, installed smoke and build verification. No main merge
   without explicit owner GO.

## DF-EXIT-01 restoration boundaries

`createSession()` and `createDomainSession()` expose `discardToBaseline()`.
It restores project and selection together, empties Undo/Redo, invalidates
old Save tokens via a new context epoch, and retains the accepted baseline
acknowledgement. The Native Host privately coordinates the same History
transaction with validated persisted-fields restoration before observers
are notified. A failed commit or rollback never produces a successful
Discard; UNKNOWN and PERSISTED_UNSYNCHRONIZED always block exit.

**Development-first:** the v3 Discard contract is required. No legacy
adapter or compatibility shim is supplied. External consumers must migrate
and pass joint FIELD validation before Foundation acceptance.
