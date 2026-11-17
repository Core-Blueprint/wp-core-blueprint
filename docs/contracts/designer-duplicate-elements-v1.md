# Designer Duplicate Elements API v1 (DP-DUP-01)

**Owner:** Core Blueprint Base Designer Foundation  
**Public availability:** `CoreBlueprint\\Core\\Design\\Editor\\Assets::DESIGNER_DUPLICATE_API >= 1`  
**Entry:** `@cb-core/design-editor` → `commands.duplicateNode(path, prepareClone = null)`

## Responsibilities

- **Base** owns the trusted structural duplication command, full subtree deep copy,
  next-sibling insertion, selection-path remapping, a single History commit, Undo/Redo,
  the canonical Layers menu item (Copy icon), and Canvas menu presentation.
- **Registered native profiles** own `<profile>.duplicate` command authorization,
  input validation, domain cardinality, per-parent schema constraints and
  `layerPolicyFor().duplicate` admission. `canApply` must validate the resulting
  document *before* a command reaches History.
- **Extension consumers** using the generation-0 document session call the same
  `commands.duplicateNode(path)` inside a lease-checked, domain-validating
  command. Extensions provide only whether/when a node is repeatable and
  the accessible Canvas action descriptor; no second cloning algorithm,
  context-menu DOM, History store or Save authority is permitted.

## Behavior and invariants

1. Right-click or keyboard context menu in Layers/Canvas on an admitted node
   includes **Duplicate element**. Nonrepeatable nodes have no Duplicate action.
   Temporarily unavailable native actions are disabled; invocation rechecks admission.
2. The copy is inserted *immediately after* the source in the same parent.
   All permitted nested contents, property values, rich text, bindings, table data
   and existing media references are retained; source values are not mutated.
3. One user action produces **one** canonical History entry and selects the
   new sibling. Undo and Redo restore project and selection atomically.
4. A document root is never duplicable. Reports Header, Footer and singleton
   data blocks cannot be copied. Mail's structural columns may be copied **as
   their containing two-column component**, never as an individual fixed column.
5. Limits are checked against the whole candidate document: maximum nesting,
   parent relations, permitted node types, singleton cardinality and node count.
   The command is refused before a commit when any constraint fails.
6. The domain is responsible for any **unique persisted identity** carried by
   a copied subtree. A trusted synchronous `prepareClone` may remap identities
   before insertion. If the domain cannot guarantee uniqueness, it **must
   refuse** duplication for the source or any nonrepeatable descendant.
   The Base helper does not guess identities or silently change bindings.
7. Existing Host admission, Contracts edit lease, Save Coordinator/Exit Guard,
   selection validity and responsive accessibility remain authoritative.

## Qualification

- Base source: `tests/js/design-duplicate-command.test.mjs`,
  `tests/js/design-canvas-context-actions.test.mjs`, full `bash tools/check`.
- First-party preview: duplicate an ordinary Reports Section and Mail compound
  columns; deny Reports Header/Footer and standalone Mail Column.
- External consumer: Contracts requires
  `DESIGNER_DUPLICATE_API >= 1`, an active edit lease, valid repeatable
  descendants and the 500-block/8-level constraints. Test both Layers and
  rendered-workarea right-click, Undo/Redo, Save, restore and Exit Guard.
- This spec is a **feature-branch implementation contract**, not a Golden,
  Level 2, Level 3 or public release certificate.
