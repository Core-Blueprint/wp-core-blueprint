# Core Blueprint Base G1a | Revised executable acceptance test matrix v2.1 (CR-01..07)

**Date:** 2026-10-16  
**Status:** REVISED PROPOSAL, **0 tests executed for this proposal**, **CONTRACT GO + implementation GO HOLD**. The original 72 test IDs are preserved and extended to **100**, pending P0 consumer-owner replay sign-off. Companion: `core-blueprint-base-g1a-contract-v2-20261016.md`.  
**Pins:** Base `main@00975654517f657ca95be7a0dc0d901e95cb764c`; Automations Folders `f4f7e23c61ca2b9448cfd03615b5dc66b8b1106d`; Automations categories `ccc76ea0a82666f1da585465d5215e779f4aa689`; Evaluator `63af69d0746cce7ad10bcbf7c09fb7cad954a4f1`. Replace pins with exact **candidate** SHAs on implementation; never test floating refs.

**Evidence levels:** S = isolated source executable unit; B = actual named JS module resolution from packaged Base and PHP autoload/interface; W = isolated WordPress integration (approved site/DB slot only); F = installed browser/keyboard/a11y/Light-Dark/operator acceptance; X = cross-repo Base↔consumer. A passing S/B never implies W/F/Golden.

## A. Deterministic history and lifecycle (P0)

| ID | Tier | Scenario / fault injection | Required assertion |
| --- | --- | --- | --- |
| H01 | S | Fresh `createDomainSession`, initial `historyStatus`, register `subscribeHistory` | Frozen `{false,false,0}`; **no initial notification**; unsubscribe is function |
| H02 | S | `execute` valid command, listeners project/history, inspect status **inside** `onChange` | Final new project and final `{canUndo:true,canRedo:false,size:1}` already visible; project before history; event frozen, correct two revisions |
| H03 | S | Execute 2 commands → undo → redo | Final status and project coherent at every callback, exactly one history event per mutation, stack order and bounded size correct |
| H04 | S | Undo at origin, redo at tip | Returns `false`, no events, no revision increments |
| H05 | S | Undo followed by new command | Prior redo branch discarded only for successful changed command; new command has exactly one history notification |
| H06 | S | Command returns identical normalized project with new object/key order | No history entry/event, preserve existing redo, no revision increment |
| H07 | S | `allowCommand` false/throws | Denied command rejected before apply, zero observable changes or events |
| H08 | S | `apply` throws, returns wrong result or returns Promise | Throws/rejects synchronously, no project/history mutation or events |
| H09 | S | Domain validator rejects candidate and undo/redo target (injected) | Original project/selection/undo/redo/revisions intact; no partial stack movement or rollback event |
| H10 | S | Profile validator or selection staging throws | No committed project/selection/history change, no phantom event |
| H11 | S | `replace` after two edits, source `context-switch` | Exactly one history reset notice with source; no cross-context undo, redo empty, coherent selection |
| H12 | S | `replace` with invalid project | Preserve old project, history, selection, revisions, dirty; zero notifications |
| H13 | S | `replace` same project with nonempty undo/redo vs same project empty history | Clears with one event in first case; second is true no-op |
| H14 | S | `clearHistory` undo nonempty, redo-only, already empty | One `clear` event for either nonempty case; zero for empty; project unchanged |
| H15 | S | `captureSave` then `rebase` without ongoing edits | Baseline acknowledged, latest current unchanged; **pre-Save undo history retained**, one history notification when baseline changes; dirty becomes false |
| H16 | S | `captureSave` A; edit B1 then B2; `rebase` same-value baseline A | No temporary baseline event, latest draft preserved; **two distinct B steps** remain separately undoable/redoable along with earlier history; dirty true |
| H17 | S | `rebase` invalid baseline/checkpoint or forged token | Throw safe code; exact baseline/project/history/selection/revisions unchanged; zero events, never accept caller-supplied `currentProject` |
| H18 | S | Consumer project observer throws; another observer and history listener attached | Commit stays, next observers/history run, operation reports success; sanitized diagnostic; no leaked document/PII |
| H19 | S | Consumer history observer throws, another exists | Other observer still receives event; committed stacks unchanged |
| H20 | S | Listener attempts nested execute/replace during dispatch | Deterministic reentrancy rejection; outer commit remains valid; no interleaved event |
| H21 | S | Unsubscribe during dispatch, repeated unsubscribe, dispose | No stale callbacks; dispose idempotent, releases refs; subsequent methods throw |
| H22 | S | historyLimit 1, 1000, 0, 1001, noninteger | Correct caps and validation; no mutable stack API publicly available |
| H23 | S | Sequential profile (`mail`, `document-flow`) vs Domain Session history actions | Same post-commit event shape/order and no-op/rollback semantics, independent domain validation |
| H24 | S | Selection subscribe observes command insert/reorder while project subscriber reads selection | One coherent reconciled selection; project → selection → history ordering; no duplicate/partial paths |
| H25 | S | History event metadata with hostile workflow values | Event carries action/source/revisions/status only, with no workflow data/credentials/actor IDs |

| H26 | S | Capture Save A then Save B and acknowledge B before A | Only latest issued B can commit; A throws `SAVE_STALE` with baseline/current/history/dirty/events unchanged, even when A HTTP response arrives last |
| H27 | S | Capture A then B; A arrives **before** B finishes | Strict latest-issued-wins rejects A without state change; B can later acknowledge if server-authoritative version is valid |
| H28 | S | Capture A, edit B1/B2, caller tries old `currentProject` | `rebase` signature has no current argument; injected stale current cannot replace latest active project, original B1/B2 remain |
| H29 | S | Capture in document A, `replace` to doc B with identical JSON value and `contextChanged:true`, then acknowledge A | Context-epoch precondition rejects A, keeps B draft/history/dirty and emits no save event |
| H30 | S | Reuse accepted save token, forged token, disposed session, capture old token after newer issuance | Deterministic bounded error codes, zero unintended mutations/notifications; one-shot only |
| H31 | S | Capture A with 2 pre-Save steps, ordinary successful Save (no ongoing edits) | All prior meaningful undo steps still available; undo after Save makes draft dirty, redo back to accepted baseline makes it clean |
| H32 | S | Capture A, edit B1, B2, undo B2, Save ack same-value A | Undo stack and still-redoable B2 remain distinct and in order; no single coalesced post-Save history entry |
| H33 | S | Normalized authoritative A', replay B1/B2 on A' | Deterministic pure staged replay; consumer `verifyRebase` accepts domain-equivalent result; stepwise undo/redo and final project/selection/history fully coherent |
| H34 | S | Normalized A', command replay conflicts, validator fails, unstable step ID or nondeterministic apply | `SAVE_REPLAY_CONFLICT`, zero changes to current/baseline/stacks/revisions/dirty and zero events; safe recovery path |
| H35 | S | Normalized A' requires truncating existing pre-save step or in-flight undo crossed checkpoint | Abort rather than lose granularity or silently reset; old undo/redo and draft intact |
| H36 | S | Observer throws during successful normalized rebase, diagnostics sink also throws | Commit remains successful; all other project/selection/history observers notified; one safe diagnostic attempt; no uncaught error or leaked payload |
| H37 | S | Capture A, successful rebase with baseline-only update (project unchanged) | One `history:rebase` notification; revision unchanged, historyRevision increases; `baselineSnapshot` final **before** all callbacks |
| H38 | S | `verifyRebase` missing/false/throws/returns Promise on normalized baseline with in-flight edits | Fail closed; no partial replay or observer emission, original baseline and draft unchanged |
| H39 | S | Nested mutation attempted by observer and diagnostic listener | Deterministic isolation, no interleaved commits, subsequent essential callbacks still run, error code only |
| H40 | S/F | Shell subscribed to diagnostics; project DOM renderer fails then refresh succeeds | Generic accessible warning with no private data; committed edit intact; manual/controlled repaint succeeds and clears warning |

## B. Import/public API and Shell/DOM (P0)

| ID | Tier | Scenario | Required assertion |
| --- | --- | --- | --- |
| A01 | B | Import `@cb-core/design-editor` from **packaged** asset via real WP import map/browser ESM | `createDomainSession`, `createSession`, `commands`, shell/Inspector/Nav/Layers actual **functions**, not source token assertions |
| A02 | B | Version+exports parity | PHP `DOMAIN_SESSION_API===2`; `captureSave`, `baselineSnapshot`, `rebase`, `subscribeHistory`, `subscribeDiagnostics` on Domain and all public profile sessions in **same** built ZIP |
| A03 | B | Future negative export probe **only after impact-ledger migration gate** | Inventory actual consumer imports first; assert explicitly scheduled private named exports removed after migration, never assume they were removed in G1b |
| A04 | B | Global facade parity | `window.cbCore.designEditor` documented nested `shell`/`profiles` namespaces match implementation; ready event dispatched once |
| A05 | S/W | `createDesignerShell` with null, valid v2 session, invalid shape | Null presentation-only works; valid history wired; invalid fails **visibly**, no fallback into `.history`/`.projectState` |
| A06 | S/W | Shell mounted/destroyed/remounted | One history subscription/root; `destroy` unsubscribe; session disposed only by consumer |
| A07 | S/W | Shared toolbar undo/redo and native input/contenteditable/IME interactions | Buttons update sync; one edit per shortcut; native text undo untouched; no duplicate Automations listener |
| A08 | F | Manual mode, direct mode, Save/Close, failed launch | Correct first-paint/direct/exit, pinned actions, visible failure and focus return |
| A09 | S/W | Context switch request/respondWith success | Valid response exactly once; project/history committed **before** `contextchanged`; no page reload |
| A10 | S/W | Missing/duplicate respondWith, fetch error, invalid target, pending save | Visible error or explicit denial; selector/old model unchanged; no false `contextchanged` |
| A11 | S/W | Slow context request/save races, switching, teardown | Stale generation ignored; disposed controller not mutated; dirty history not lost |
| A12 | S/F | Navigation with stable IDs, invalid duplicate/off-shell targets, keyboard | Fail closed on bad ID; aria-current, focus, section scoping, update/destroy semantics correct |
| A13 | S/F | Layers nested selection, reorder, disclosure, keyboard | Real hierarchy, bounded same-parent policy, ARIA tree, source-specific panel intent |
| A14 | S/F | Inspector Identity/Section/Toggle/Autosize | Public exports exist, escaping/text nodes, semantic title consistency, 1 control/row, dispose-safe autosize |
| A15 | W/F | Responsive 1281px vs 1280px, zoom 200/400%, reduced motion | 3 rails vs canvas+drawers, exclusive drawers, no layout overflow, predictable focus |
| A16 | W/F | Light/Dark and all six locales, RTL when supported | Legible states, text expansion, translated labels, no hidden controls or icon-only ambiguity |
| A17 | B/W | PHP `enqueue`, standalone `enqueue_designer_mode`, Mail Rich Text | Full Designer presentation works with **no direct composition-handle consumer dependency**; registrations are idempotent, no private wrapper required; Evaluator gate no longer inspects composition handle |
| A18 | B/W | Import map and path boundary in final ZIP | No consumer import from `assets/js/design/*` required; real dependency resolution succeeds |

| A19 | B/W | `enqueue_designer_mode()` standalone in Evaluator and Base Mail/Reports | All public styles present, no direct `DESIGNER_COMPOSITION_STYLE` requirement or invented legacy style contract; no duplicate style handles |
| A20 | B | Scan every first-party Base import including Certificates/Commerce/Newsletter | Exact export/style/DOM impact ledger before private export cleanup; one non-target Designer real module import executable from candidate ZIP |
| A21 | S/W | Observer failure → `subscribeDiagnostics` → Shell `cb:design-shell:diagnostic` | Single frozen code/phase/recoverable payload, no Error/stack/answers/credentials, keyboard- and screen-reader-accessible rerender recovery |

## C. Template Library category / ACL / scope (P0)

| ID | Tier | Scenario | Required assertion |
| --- | --- | --- | --- |
| T01 | B | `TemplateCategorySourceInterface` autoload + registry category capability | PHP interface exists and signature matches; proposed `CATEGORY_API===1` after acceptance |
| T02 | W | Registration before/after hook, collisions, unknown extension ID | Correct `init:6` collection and ExtensionRegistry prerequisite; invalid registration fails closed |
| T03 | W | No login, denied source, provider throws | No facets/provider leakage; other authorized sources work |
| T04 | W | Designer scope `workflow` vs other designer **same extension** | Provider `categories()` is **not called** for out-of-scope sources; response contains only authorized source facets |
| T05 | W | Operator global vs nonprivileged contextual scope | Global manage permission + source ACL; context token cannot become global browser |
| T06 | W | Missing/expired/tampered/cross-user/cross-session scope token and nonce | Deny invalid context, no leak; do **not** reject a second valid same-session read-only GET; no token in URL/log/storage |
| T07 | W | Facets 0, 1, 49+, invalid slugs, control chars, HTML, invalid UTF-8, oversize labels | Max 48 valid facets, safe plain text, no HTML/script, deterministic order |
| T08 | W | Two providers use same category slug with different names | No misleading global taxonomy/label; source-specific filter choices |
| T09 | W | Personal template owned by actor A, requested by B | B receives no private template ID/name/count/**presence-only custom category** |
| T10 | W | Provider returns unowned/out-of-scope metadata or >48 items | Registry filters via `can_view` and source identity, bounded query, stable cursor |
| T11 | W | 0/1/1000+ items, locale/origin/category/search filters | Provider applies predicates before paging, no missing/duplicate across pages, bounded runtime |
| T12 | W | Tampered cursor, replay another user/query/designer, expiration | Empty safe result, no cross-scope disclosure |
| T13 | W | Client forges extension/designer filter while supplying legitimate token | Scope mismatch denied (not silently widened) |
| T14 | W/X | Automations template selected with forged metadata, denied POST nonce or wrong actor | No draft creation, no model write, no action by browsing alone |
| T15 | W/X | Authorized Automations template import/export | New workflow DISABLED, principal `0`, no copied literal credentials/recipient/customer data; no automatic Run or unintended post hooks |
| T16 | F | Template Browser selection keyboard/viewport/abort & source/category choice | Context-scope pinned, screen-reader selection works, no template body in network payload |

| T17 | W | Issue valid scoped token, two consecutive read-only browser/categories GETs in same eligible WP session | Both succeed during TTL and scope, token not consumed; returned metadata remains actor-authorized |
| T18 | W | Repeat token after login/logout session rotation, actor or Designer change, expired TTL | Denied; no source facets/items, no scope widening and no identifying presence side channel |
| T19 | W | Valid scoped GET token presented to template instantiate/activation POST | Token alone grants **zero** write authority; require actual fresh nonce, actor+item ACL and domain policy; no workflow principal inherited |

## D. Cross-consumer model/save/privacy, reference proof (P0)

| ID | Tier | Scenario | Required assertion |
| --- | --- | --- | --- |
| X01 | X/B | Automations Folders candidate uses pinned Base candidate | No missing named export; one canonical session; no `createDesignerStateHistory` path |
| X02 | X/W | Automations create/edit/undo/redo/context/dirty/save/reopen | Stable IDs, fully recovered workflow definition, no history crossing workflow/folder identity |
| X03 | X/W | Automations concurrent edits after Save, rejected Save, stale response | Checkpoint CAS, exact draft separation, no false saved status/lost edits; two pending edits remain individually undoable |
| X04 | X/W | Automations Folders vs Category source reconciled | Unique functionality preserved from both branches, no blind cherry-pick/merge; tests include folder export/import |
| X05 | X/B | Evaluator Golden candidate uses pinned Base candidate | Existing DomainSession stays; required Navigation/Inspector public; no old-Base fallback |
| X06 | X/W | Evaluator Rules, Results, Stack Analyzer, undo/redo, validation, save/reopen | Preserve stable form IDs, result visibility and evaluation settings |
| X07 | X/W | Evaluator frontend event after calculation | `cb:evaluator:result` emits only minimised IDs, never answer/score/personal data |
| X08 | X/W | Evaluator second-meta-write fault injection (isolated authorised DB) | Prior persisted data recovered, no partial publish, original state preserved |
| X09 | S/W | Base Mail `mail` profile, Rich Text and preview | Allowlisted HTML, no unsafe executable markup, save/reopen/source parity |
| X10 | S/W | Base Reports `document-flow` profile/PDF | Immutable snapshots, rendered PDF parity, no template-library private report body |
| X11 | S/B | Certificates, Commerce and other first-party import/style inventory | All actual consumers accounted for before removing public named exports / CSS handles |
| X12 | F | Representative 5 Designers, exact compatible artifact set | Keyboard/a11y/Light-Dark/responsive/six locales and human visual acceptance; no private Base import |
| X13 | X/W | Newsletter later P2 | Public Mail profile only, unsubscribe/consent/dispatch snapshot retained, not a P0 blocker |

| X14 | X/W | Automations sanitized `DefinitionCodec` → `DesignProject` → `DefinitionCodec`, 0/1/many step fixtures | Exact authorable schema roundtrip with trigger/states/conditions/actions and stable step IDs, folder identity stored independently |
| X15 | X/W | Automations workflow codec typed values and boundary fuzzing | Preserve integer/float/string/bool/null/array/plain-map distinctions; reject NaN, over-depth/over-bytes, executable/prototype-polluting or unsupported data |
| X16 | X/W | Automations provider drift, import/export and sensitive literal scrub | No silent defaulting, missing source provider handled safely; credentials/tokens/principal absent from project/template and no automatic workflow execution |
| X17 | X/F | Automations B1/B2 while Save A pending; accepted normalized A' | Owner-verified command replay, per-intent undo/redo, explicit conflict recovery, no loss of workflow identity or intended edits |
| X18 | X/F | Evaluator Rules/Results two edits while Save A pending | Per-intent undo/redo after acknowledgement; savedSnapshot/latest dirty correct, policy and frontend minimized event unchanged |
| X19 | X/W | Two out-of-order saves of different domains / stale WordPress POST response | Base rejects old checkpoint; consumer independently enforces server CAS/auth/nonce/version so older write cannot overwrite newer persisted data |
| X20 | X/W | Base Mail/Reports pre-Save undo and server normalization | Ordinary Save preserves edit steps, normalized conflict does not corrupt HTML or immutable report snapshots, compatible profile-session contract |

## E. Required execution proof and handover receipt

1. **Before implementation**: Overseer signs the exact G1a contract version with explicit Automations/Evaluator approval of **granular history/replay**; no GO means no Base implementation edits. Decide exact `captureSave`+`rebase`+diagnostic signatures and conflict/retry UX before bumping PHP `DOMAIN_SESSION_API=2`.
2. **Before test commands**: in an isolated safe branch/worktree, inspect `tools/check`, `tools/build-release`, existing engineering handbook and DB guards; never invent flags/commands. Respect active WordPress slots, `CB_BASE_TEST_SLOT`, or existing local uncommitted changes.
3. **Source and executable integration**: JS Node tests for event ordering/fault injection; PHP interface and ACL tests; **real packaged ESM** import using the WordPress-generated import map (or a faithful exact-asset ESM resolver) plus named export assertions; no regex-only PASS.
4. **Build**: when separately approved, run canonical project-specific build, check ZIP canonical plugin root, `unzip -tq`, verify sidecar SHA-256 and record exact `dist/` path/actual SHA. A built ZIP does not prove WordPress/browser acceptance.
5. **Installed E2E**: only on specifically authorised disposable/isolated test slot; re-check role/nonce/scope and failure scenarios, no automated real workflow execution, real emails, destructive customer data or shared DB reset.
6. **Operator field review**: Owner provides screenshots/observations for Mail, Reports, Evaluations and Automations, desktop/mobile, keyboard, Light/Dark, locales. Overseer moves contract candidate → consumer integrated → source/build verified → installed field accepted only after corresponding evidence.
7. **Handover format after future patch**: repo, branch, exact full HEAD, Base candidate SHA, files/contracts changed, tests actually run and counts, build artifact+SHA only if produced, copy-ready verified Git commands, install/visual acceptance, risks, next GO. Merge/CI/release require separate owner GO.

**Unresolved sign-offs:** Automations and Evaluator must explicitly validate this **specific granular replay + conflict model** and fixture-based workflow roundtrip. Wider named-export impact ledger remains an implementation prerequisite. All proposed cases are **test designs**, not executed evidence; no cross-consumer installed acceptance is claimed.