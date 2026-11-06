# FRG-04 — Base Designer + Template Library Foundation Freeze Evidence

**Status:** PRE-FREEZE RECONCILIATION / OWNER FREEZE GO PENDING (6 November 2026).  
**Repository:** `Core-Blueprint/wp-core-blueprint`.  
**Pinned merged-source baseline:** `main@18023119473f27a95eac3215b25fcc30d115641f` (PR #157 merged).  
**Audit branch:** `feature/frg-04-foundation-freeze-reconciliation-20261106`; documentation-only changes, not a new runtime baseline.  
**Authority:** current Base source and public contracts; the approved Engineering Handbook Golden Standard, no-legacy policy and sandbox-first workflow; central Overseer [DF-STAB-01 #75](https://github.com/christiaanbruinsma/wp-core-blueprint-overseer/issues/75). This report **does not itself freeze any API** or authorize consumer modernization.

## Scope of the prospective freeze

Freeze **Base's shared authoring/interaction contract**, Template Library's source discovery/browser and delegated navigation, Data Exchange v1, asset-free template JSON schema, and bounded internal resource-package verification → actor-bound staging → canonical Engine preview/apply binding. Base owns shared mechanisms and metadata-only discovery; **each consumer owns its domain schema, permissions, persistence, UI actions and real media materialization**. The current state does **not** add an external Native Trusted Host admission API or a second import engine.

The only approved destination for historical RC test data is a fresh fixture of the final contract. There are **no existing production/customer records** requiring a migration, legacy shim, or backwards compatibility. This is not permission to destroy a local sandbox or database.

## Public Base capability matrix (verified from pinned SOURCE)

| Boundary | Exact source/API contract | Status / limitation |
| --- | --- | --- |
| Shared Designer browser | `@cb-core/design-editor` plus documented public `window.cbCore` facade | SOURCE implemented; versioned WordPress ESM resolution must match installed Base artifact. |
| Sessions/History | `Design\\Editor\\Assets::DOMAIN_SESSION_API = 3` | Base manages History and Discard baseline; consumer keeps Save/persistence authority. |
| Elements & dynamic data | `DESIGNER_ELEMENTS_PALETTE_API = 1` | Optional palette; elements/commands require domain authorization. |
| Context placement / movement | `DESIGNER_CONTEXT_PLACEMENT_API = 1`; `DESIGNER_NODE_MOVE_API = 1` | Pure shared placement, consumer decides allowed mutations. |
| Drag & Drop | `DESIGNER_DND_UNIFIED_API = 1` | Scoped DF-DND-C1 native acceptance exists; not blanket D2/D2E FIELD. |
| Interaction presentation | `DESIGNER_INTERACTION_API = 1` | Pointer-inert shared state; no mutation authority. |
| Canvas navigation | `CANVAS_NAVIGATION_API = 2` | Public zoom/pan capability, no full independent FIELD established. |
| Context menu | `DESIGNER_CONTEXT_MENU_API = 1` | Shared interaction; no command authorization. |
| Native Save presentation | `SAVE_AUTHORITY_UI_API = 1` | Native Mail/Reports SaveCoordinator retains authoritative receipts, UNKNOWN fails closed. |
| Inspector | `INSPECTOR_COMPOSITION_API = 1` | Shared composition, consumer schema-specific inspector. |
| Flow preview | `FLOW_PREVIEW_NESTED_REGIONS_API = 1` | Sandbox/read-only preview, consumer render/data semantics. |
| Exit / managed context | `DESIGNER_EXIT_GUARD_API = 4`, `window.cbCore.designerExitGuard` | Source v4 present; native accepted Discard scope is documented; external FIELD pending. |
| Header select picker | `CoreBlueprint\\Core\\UI\\Assets::SELECT_PICKER_HEADER_API = 1` | Native Mail accepted; other contexts require their own installed evidence. |
| Template discovery/browser | `TemplateLibrary\\TemplateSourceRegistry`, `TemplateQuery`, read-only T2 browser, T3A delegated navigation | Metadata discovery/browse, not a universal template-storage service. |
| Data Exchange | `DataExchange\\Foundation::CONTRACT_VERSION = '1'`, `Engine::preview_json()/apply_json()` | **Single** canonical JSON plan/fingerprint/apply authority; provider owns import semantics. |
| Asset-free template JSON | Existing `core-blueprint-data-exchange` `format_version=1`; one authorized record, `resources: []` | Base transport contract; actual per-consumer import/export not integrated. |
| Internal package transport | `ResourcePackageVerifier`, `ResourcePackageStage`, `ResourcePackageImport` | Real package-to-test-consumer integration present, optional PHP ZipArchive, *internal only*. |

Values above originate in the pinned `src/Design/Editor/Assets.php`, `src/DataExchange/Foundation.php`, and referenced public contracts. They are **SOURCE facts**, not evidence of a separately installed same-SHA WordPress instance.

## Evidence ledger — keep grades distinct

| Gate | Current evidence | Verdict |
| --- | --- | --- |
| Source composition | PRs #153, #154, #155, #156, #157 merged; frozen candidate contains package verifier, private staging, binding, media lifecycle **contract**, and integration fixture | CONFIRMED at pinned Base `main`. |
| Package integration test | Chris reported **8/8 non-skipped real ZIP tests PASS** on FRG-T4-01E feature HEAD `4a01194ee097cc54310b0a859f1d5d896bb3cac6` | OWNER-REPORTED PASS, no independent stdout copy; the merge changed tests/docs only. |
| Base Level 1 and dev ZIP integrity | Chris reported Level 1, internal dev build, `unzip -tqq` and SHA-256 sidecar PASS at same accepted feature HEAD | OWNER-REPORTED PASS, exact checksum not recorded in this audit. |
| Native Designer source coverage | Existing `tests/js/designer-cx02e-v4-public.test.mjs`, `designer-native-discard-integration.test.mjs`, `designer-native-save-feedback.test.mjs`, `template-browser-boundary.test.mjs` and related contract tests found on pinned main | SOURCE-EXISTS; not re-executed in this docs audit. |
| Native Mail/Maintenance Reports FIELD | `DESIGNER-FOUNDATION-API.md` records **accepted specific native** DF-EXIT-01R, Picker and DND-C1 scope; central Overseer #75 requires complete Save/Discard/Cancel, reopen without reload, context/rollback/focus ledger | LIMITED EARLIER FIELD; **current exact-artifact matrix not independently reconciled**. |
| WP Level 2 | No exact SHA `main@18023119…` Level 2 receipt established by this audit | NOT VERIFIED; no Docker, DB or new test workspace authorized. |
| Official Level 3 / release ZIP, CI / Golden | Internal dev ZIP is not a customer release; formal gates have not been established here | NOT VERIFIED / SEPARATE RELEASE HOLD. |
| Cross-consumer and cross-site FIELD | Reports and Certificates real media import/export, consumer endpoints and site-A→site-B restored template not yet implemented/accepted | OUTSIDE BASE FREEZE; required before each relevant consumer's stable release. |

**Evidence protection:** The FRG-T4-01E owner reports are specific to the feature HEAD; merging without runtime changes is not the same as independently running every gate on the merge SHA. Documentation-only changes on this audit branch do not reclassify absent evidence. The full runtime suite, consumer FIELD and Golden claims are distinct.

## Security and operational limits at the Base/consumer boundary

- The real ZIP verifier uses the optional `ZipArchive` parser, bounded canonical manifest, digest and image-header checks. These are **not** complete pixel decode/re-encode or origin signature proof.
- Private staging is actor/capability/destination bound, time limited and reverified on each adapter invocation; the internal service requires an already-provisioned `0700` path outside public webroots. Production entrypoint nonce, upload provenance and storage provisioning remain consumer/integration work.
- The canonical Engine plan fingerprints verified package/document/resource digests, provided consumers opt in to the trusted plan binding and check record-resource equivalence; consumer `apply_import` must recheck its own authorization, destination uniqueness and binding.
- Source attachment IDs, site paths, preview URLs and remote fetches are never portable resource authority. Only locally created destination-owned asset identities may be persisted.
- Media decode/re-encode, per-consumer quota/licensing, newly-created-only resource journal, recovery after process crash, safe cleanup and two-site FIELD acceptance are explicitly **not Base-implemented**.
- Public or stable user-facing offline resource import must not be advertised from these internal classes and fixture tests.

## FRG-04 pre-freeze decision register

**Already resolved:** Data Exchange v1 is reused; no competing import system, no vendor account/network requirement; Base/consumer ownership and two transport types (asset-free JSON and optional resource ZIP) are reconciled; 01E integration test merged; no legacy/backwards-compatibility obligation.

**Remaining before Chris can authorize FRG-04 Foundation Freeze:**

1. **Designer evidence gate:** assemble pointers to previously accepted *same-artifact* native Mail and Maintenance Reports Save/Discard/Cancel/reopen/History/focus/keyboard/responsive results, Exit Guard v4, context switch and relevant Publisher/Picker behavior. Mark any genuinely missing cases **OPEN**, rather than rerunning already accepted evidence by default.
2. **Source/API gate:** ensure final public ESM/PHP capability parity, Editor facade, Template Library browser permissions and Exit Guard contract are coherent on the candidate exact head. Resolve proven functional/contract blockers with narrow reviewed patches only.
3. **Security gate:** confirm scope-limited ZIP/staging/import boundaries and no material unresolved P0/P1 shared Foundation defect. Consumer-owned media/import prerequisites remain named and deferred, not silently called PASS.
4. **Evidence/approval gate:** record final docs-only audit branch head and changed paths, operator Level 1/build provenance, independent Overseer review and **Chris's explicit Freeze GO**. Freeze != merge, stable release, CI, deployment or consumer FIELD acceptance.
5. **Post-freeze adoption:** modernize first-party Designer consumers in the Overseer #75 order: **Contracts → Reports → Automations → Evaluator → Certificates → Invoice & Quotes**. Each requires its own authorized source/field/merge evidence, using only the final Base contract.

**Current verdict:** **FRG-04 FREEZE HOLD — not a claim of a missing feature or failed test.** The remaining task is an evidence/acceptance reconciliation, not further speculative Foundation architecture. The owner should first review this ledger and the scoped native Designer FIELD evidence; only after closing genuine gaps should the exact-source freeze be signed.
