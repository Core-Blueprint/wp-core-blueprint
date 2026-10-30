# Base Flow-preview v1 — Contracts nested-region compatibility

**Status:** BOUNDED SOURCE CANDIDATE. Overseer issue #67. No FIELD acceptance, Contracts migration GO, Base Golden, merge or public release implied.

**Baseline:** `12a072d3fc34351d5bdf828f070e69f93993d189`. This extension retains the existing public `createFlowPreviewHost(iframe, options)` signature and protocol version **1**. It does not add a new preview, Designer, Selection, Save or DND engine.

## Exact supported boundary

- **PHP capability:** `CoreBlueprint\Core\Design\Editor\Assets::FLOW_PREVIEW_NESTED_REGIONS_API >= 1` (new compatibility gate). Consumers must check this from the **same installed Base ZIP** that supplies the public `@cb-core/design-editor` / `createFlowPreviewHost` export. Source availability is not installation evidence.
- **Typed Flow region IDs:** `rN`, `rN.M`, ..., up to **eight** non-negative decimal path segments after `r`. Each segment is canonical (no leading zeros except `0`), a safe JavaScript integer, and the full ID is at most **64** bytes. Examples: `r0`, `r0.2`, `r0.1.2.3.4.5.6.7`. Overdepth, malformed, overlong or unsafe numeric IDs fail closed.
- **Geometry count:** 0–500 **unique semantic region IDs** per generation. The existing PHP `HtmlRenderer::render_preview` validates the input mapping; the iframe bridge measures and sends the full bounded set; the parent host validates the entire message atomically. **501+ regions fail**, never return a truncated 500-region prefix.
- Multiple rendered DOM targets **may correspond to the same semantic region** (a region can cover multiple Flow render paths). The bridge unions those rectangles into **one** ID and emits unique IDs. Duplicate IDs *inside a parent-facing region array* are invalid and rejected. PHP still refuses one render path being owned by two different regions.
- Geometry retains the existing trusted frame generation, strict message shape/source checks, bounded finite top/left/width/height and teardown. Parent-region callbacks remain preview-only data: no preview message or geometry becomes authorization or a document mutation.

## Atomic failure behavior

If any iframe target is invalid, unsafe or would create a 501st unique region, the bridge publishes a same-generation **invalid region envelope** (`regions: null`) rather than a partial map. The parent immediately clears the previous trusted `onRegions` map and marks that preview generation failed. Any later message from that failed generation is ignored. A new render generation starts empty and is validated independently.

The iframe stays `sandbox="allow-scripts"`; the existing renderer-generated CSP hash is recomputed against the actual bridge bytes. Cross-window source, message version and generation guards stay unchanged. Legacy iframe selection/hover visuals are **not** globally removed. A separate approved consumer may arrange parent-owned interaction presentation without creating a second active outline; this patch introduces no global visualization toggle.

## Contracts obligations (separate authorization)

Contracts must independently remove its own `ContractsFlowCompiler::MAX_PREVIEW_REGIONS = 100` and early truncation/break, validate up to 500 unique regions without partial output, and verify the new PHP capability plus public executable JS factory from one matching installed Base artifact. Preserve Contracts document model, editing lease, Selection, History, Save, signing/evidence and immutable content. Independent Contracts migration/source acceptance is **HOLD** until Central Overseer authorizes it.

## Source gates

`node --test tests/js/design-flow-preview-contracts-compat.test.mjs` exercises the actual public parent Host and runtime PHP iframe bridge (including 100/101/500/>500, eight-level paths, malformed and duplicate IDs, stale generations, frame source, cleanup and bounded 500-region measurement).

`php tests/public-flow-preview-compat-regression.php` exercises the real PHP renderer and 100/101/500-region output, over-limit and path validation, and the capability boundary. Both regressions are also included in canonical `bash tools/check` (Node via the existing `tests/js/*.test.mjs` glob).

After all source tests pass, run **one** `python3 tools/build-dev`, verify the exact artifact SHA-256 and archive root, then request independent **targeted FIELD GO** from Overseer. Do not install or publish before FIELD authorization.
