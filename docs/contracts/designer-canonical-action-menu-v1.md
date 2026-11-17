# Canonical Designer Action Menu v1

**Owner:** Core Blueprint Base Designer Foundation.
**Capability:** `CoreBlueprint\\Core\\Design\\Editor\\Assets::DESIGNER_CANONICAL_ACTION_MENU_API = 1`.

## Shared presentation

Native Reports/Mail canvas and Layers use the existing Base
`layerTree.openActions()`. External consumers provide that Base-owned
`layerTree` to `createDesignerContextMenu({ actionPresenter: layerTree,
getTargetInfo, ... })`, rather than assembling another visible menu.
The original flat context controller remains for older non-converted
integrations, but is NOT the canonical Designer conformance mode.

Base alone owns standard Move Up/Down, Hide/Show, Duplicate and Delete
menu labels, Lucide icons, order, separator, destructive appearance,
disabled state, nested-delete confirmation, focus/keyboard handling and
popup lifecycle.

Consumers provide only standard action IDs, availability, authorized
commands and optional `getTargetInfo({target})` with `label` and
`children` for accurate subtree confirmation. No consumer menu HTML,
icon CSS or custom labels for standard actions.

The Base Canvas controller continues to revalidate immutable registered
targets, current context, available action and `canInvoke` before
dispatch. Domain-specific edit leases, validation, History and Save/Exit
Guard remain authoritative. An action denied at the moment of invocation
does nothing. Stale contexts close without mutation.

**Qualification:** Base and consumer Level 1, exact Base source pairing,
sandbox ZIP integrity and visual/functional parity in Reports/Contracts;
formal Golden/production remain separate gates.
