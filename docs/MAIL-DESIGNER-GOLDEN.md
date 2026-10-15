# Base Mail Designer Golden | Independent development branch

**Decision (2026-10-15):** Build one professional, reusable email-design system in Base. Newsletter, transactional Base Mail, Automations and later consumers extend it through public contracts. Bricksforge provided a feature/architecture reference only; NO Bricksforge source, proprietary UI or assets are copied.

**Branch:** `feature/base-mail-designer-golden-m0-m2-v1` based on Base `main@7bb3f17ae3c6045838a414949a834ae5f9254286`. Base Luna is patching Base concurrently. Do not merge, rewrite their branch, run shared CI, or claim local WordPress integration PASS until the operator can reconcile and test this branch.

## Source reconciliation: existing components

- Base already owns `Design/Profile/Mail/{Contract,Validator,HtmlRenderer}`, safe 600px-table rendering, basic sections, headings, text, buttons, images, dividers, spacers, scalar bindings, preview markers and responsive CSS.
- Base `Mail/Designer/{ComponentRegistry,BindingRegistry,TemplateRegistry,TemplateRepository}` supplies declarative extension points.
- Shared editor `createSession`, commands/history, selection, shell, layers and Inspector already exist; do not fork these into Mail Designer.
- Base Mail Designer currently relies on a feature-level inspector with plain textarea for body text and one-section palette insertion. It lacks proper rich-text WYSIWYG, true layout columns, client parity certificates and polished validation/preflight.
- Template Library is being developed by Base Luna on separate feature branches. Its integration is a later reconciliation/acceptance item, not permission to create a second library.

## UX product contract: approachable for beginners, precise for professionals

One canonical Mail Designer, **not separate Basic vs Pro editing engines**.
The editor reveals complexity progressively rather than forcing users to choose a permanent mode.

**Beginner flow**:
- Choose a good starter template when the approved Base Template Library is ready. Begin immediately with a readable layout, not a blank maze.
- Select a Heading, Text, Image, Button or Column block using clear names and previews.
- Edit text visually with bold, italic, underline, numbered/bulleted lists and a link field. HTML syntax should never be required.
- Use preset spacing, alignment, mobile stacking and coordinated colors; preview the real outgoing HTML with confidence.
- See helpful, actionable validation messages (such as missing ALT, unsafe links or required consumer content) without technical jargon. Save and undo must be predictable.

**Professional flow**:
- Adjust typography, widths, spacing, backgrounds, column ratios and responsive behavior through the same Inspector.
- Inspect the allowlisted HTML source within individual rich-text blocks via an **Advanced** disclosure. No arbitrary CSS or executable HTML is exposed.
- Use structured bindings, reusable templates and registered extension components; adjust source only within the renderer-safe contract.
- Preview/inspect HTML and client compatibility; recognize mail-client limitations rather than implying pixel-identical output.

**Accessibility and quality gates**:
- Keyboard/selection, focus retention, toolbar naming, screen-reader feedback, paste sanitization and no-loss editing must be tested in a real browser.
- All controls share Base Design System components and fit both light and dark WP Admin themes.
- Changing Advanced visibility cannot lose unsaved content; undo should group an individual authoring operation, not each keystroke.
- No telemetry, tracking, remote fonts or external editor runtimes.
- Mail Designer should meet WCAG-oriented keyboard and contrast expectations before Golden acceptance.

**Source implementation milestone:** a first Visual/Advanced rich-text control has been added to the Base Mail inspector. It currently commits edits on leaving the control; full live WYSIWYG canvas editing, keyboard traversal, focus regression and WordPress integration are still OPEN. This is not acceptance of the complete beginner/pro UX.

## Target milestones and acceptance

- **M0** Exact source/API audit and freeze the independent feature branch. Completed as document-only source reconciliation; visual browser audit and conflict analysis with current Base Luna branches OPEN.
- **M1** Professional composer: safe rich text, selection/clipboard/keyboard, undo/redo, dirty-state and persistence. Structured/allowlisted rich-text rendering first; inspector WYSIWYG and extension consumers subsequently. Old `mail.text` documents must remain unchanged.
- **M2** Responsive layout and design: columns, child insertion, semantics validation, width/gap constraints, stacked mobile view and Outlook-safe table rendering. More typography/style controls without arbitrary CSS.
- **M3** Preview and mail-health diagnostics: desktop/mobile modes, preheader, missing image alt and unsafe/missing links, HTML/client parity, realistic Outlook/Gmail/Apple Mail fixture tests and plain-text decisions. No false claims of universal client support.
- **M4** Integrate Base Template Library only after its T0–T2 Level 2/3 gate and public API reconciliation; no duplicate persistence or import format.
- **M5** Consumers: Newsletter first, Base transactional mail and Automations. Newsletter owns campaign metadata, permissions, unsubscribe rules, snapshot, dispatch and provider governance; Base stays mail-content/renderer only.

## Core design and trust guardrails

- No raw arbitrary HTML/CSS or executable code from user/extension inputs. Controlled rich text is an allowlisted subset with context-safe binding interpolation.
- Nested columns render tables for Outlook; mobile display/fallback tests required.
- Public extension components must be declarative and render-safe. Unsafe node types fail validation.
- No Google/Microsoft-dependent runtime, external SaaS editing, trackers, web fonts or unsanctioned outgoing data.
- Keep WP Admin canonical, Base Designer Shell authoritative, EN source/locales later via project i18n.
- No new transfer of GDPR/privacy responsibility into Base. Consumer supplies personalizations and consent policy.

## Current isolated source batch (M0 / early M1-M2)

Implemented without touching the operator's occupied Base clone:
- Optional `mail.rich-text` node with **bounded allowlisted HTML fragment**, no scripts/styles/images, escaped binding substitution, a **beginner-facing visual contenteditable inspector** (bold, italic, underline, lists, safe links, plain-text paste), and an **Advanced** controlled-source disclosure. Changes commit through the Base session/history on leaving the field. Source editing and visual editing use the same sanitized value. This first visual implementation is **not** full canvas-level WYSIWYG, keyboard/accessibility or no-loss acceptance; those require installed runtime tests. Existing `mail.text` behavior is not changed.
- Optional `mail.columns` containing exactly two `mail.column` containers. Section-level placement enforced by Validator, bounded ratio/gap, table-based Outlook structure, responsive stacked `td` style, editor selection markers and insertion into the selected column. Multi-column live browser rendering still requires confirmation.
- `tests/integration/MailDesignerGoldenM1M2ContractTest.php` proposes PHP9.6/WP tests (allowlist/XSS, old text, valid/invalid columns).
- `tests/js/mail-designer-golden-m1-m2.test.mjs` proposes JavaScript profile/palette/render-contract checks, visual/pro source behavior and link validation. Base Mail page styling is scoped in `assets/css/pages/mail-designer.css`.
- **Independent source checks:** V8-parsed both modified Mail JavaScript modules; the pure safe-link validator correctly rejects script/data/invalid addresses while retaining valid HTTPS and mailto references. This is **NOT PHP syntax, full test suite, WordPress browser or release-build PASS**.

This is a compatibility-oriented first code batch, not completion of the entire professional Designer. Do not release or merge until the full M1/M2 Golden and Base Luna branch reconciliation are complete.

## M1-A: Inline canvas copy editing (source candidate; browser acceptance OPEN)

UX contract is explicit: **single click selects**, **double click edits text**, **Inspector owns all styling and configuration**. Accessibility entry: F2 when a text block is selected. Escape discards current inline changes, Ctrl/Command+Enter applies, blur commits one session/history command. Headers also commit on Enter. The original Inspector controls remain the fallback for touch and non-inline contexts.

Source code: `assets/js/design/mail/inline-text.js` and `assets/js/features/mail-designer.js` integrate via the Base public session `commands.setProperty` contract, not a separate editor store. Mail preview updates are suspended while editing and in-flight preview fetches are aborted to prevent overwriting the user's cursor. The outgoing Mail renderer emits inline selection outlines **only for editor markers**. The template has a brief guidance label.

Initial inline types: Base `mail.heading`, `mail.text`, `mail.rich-text`. Dynamic binding tokens are **intentionally excluded** from inline editing to avoid replacing `{{binding}}` source with resolved preview data. Other content, including image properties, URLs, colors, font settings, spacing and responsive controls, remains Inspector-only. Advanced HTML editing continues through allowlisted Rich Text Inspector.

Regression: `tests/js/mail-designer-inline-ux.test.mjs` covers node eligibility, binding guard and source integration. V8 compile checks of the changed JS modules PASS; pure inline eligibility checks (5 fixtures) PASS. **These are limited independent checks, not Node full suite, WordPress, browser, email client, integration or release PASS.**

**Remaining before Golden:** contextual inline rich-text formatting controls, reliable focus/selection and keyboard/touch testing, project dirty-state and context-switch protection, full save/reopen evidence, accessibility, responsive/client parity, proper mail health checks. Reconcile Base Luna's concurrent Inspector/Designer changes before operator testing and any merge.

## M1-B: Inline rich-text toolbar and save reliability (source candidate)

The approved UX contract remains unchanged: single click selects, double click edits copy, and **all styling/configuration stays in the Inspector**. The inline toolbar appears only for `mail.rich-text` and exposes **bold, italic, underline, and a validated link action**. For legacy `mail.heading` / `mail.text`, the in-preview editor changes plain copy only. Rich-text lists continue to be available in the Inspector; a polished contextual list toolbar remains an open M1 follow-up.

Source implementation:
- `assets/js/design/mail/inline-toolbar.js`: iframe-preview-only formatting surface, accessible button labels and safe HTTP/HTTPS/email links (via existing `safeMailLink`). Selection is preserved when toolbar actions are activated. Toolbar HTML/CSS is **not included in sent e-mail**.
- `assets/js/design/mail/inline-text.js`: mounts the toolbar for rich-text only, skips committing when focus moves to the toolbar link input, and destroys transient controls on finish/cancel.
- `assets/js/features/mail-designer.js`: tracks a fingerprint of template ID + subject + canonical session project, displays an unobtrusive unsaved indicator, suspends preview while editing, warns on browser navigation and asks before switching templates with unsaved changes. It blocks template switches during an in-flight save and ignores stale switch fetches after edits.
- `assets/js/features/mail-designer-save.js`: respects a Mail consumer's cancelled submit and aborts in-flight AJAX only on actual `pagehide`, not a cancellable `beforeunload`.
- `assets/css/pages/mail-designer.css`: the unsaved indicator uses Base text tokens.
- `tests/js/mail-designer-inline-ux.test.mjs`: source-contract regression for formatting, focus, consent to discard, confirmed AJAX save fingerprint, correct unload and save event ordering.

**Golden acceptance OPEN:** source parses and focused readback cannot verify actual caret position, toolbar/link focus, undo/redo granularity, clipboard behavior, user cancellation, AJAX races, i18n, form save/reopen, mobile/touch, accessibility or WordPress Mail context switching. Run clean isolated browser + WP7.0/7.1 tests only after Base Luna's concurrent work is reconciled. Do not merge this branch or claim a Golden milestone yet.

## M1-C: Interaction hardening (isolated source + simulated runtime)

This batch adds resilience around the initial M1-A/M1-B inline edit candidate:

- **Single-click selection remains in the canonical canvas selection controller; double-click or F2 opens direct editing.** F2 now works from the outer Designer Layers/controls as well as the iframe, but is scoped to the active Designer Shell so it does not capture shortcuts elsewhere in WordPress Admin.
- Source bindings are never reverse-interpolated from preview: **all** `{{` / `}}` token fragments are excluded, even unrecognized or malformed tokens, not just the known registry format. Their original content stays editable in the Inspector.
- While a text edit is active, **preview fetches are aborted and revision-guarded**. Late responses cannot overwrite the current contenteditable element. Aborted/stale errors do not replace a newer status message.
- Switching the inline target commits the old text first; if that commit fails, the new target is not opened. A failed command, unreadable markup or text exceeding the length limit leaves the current draft **in its editable DOM**, rather than discarding it.
- Escape from the inline toolbar cancels editing, and keyboard completion restores focus to the edited element. The inline text session continues to issue **one property command per completed edit**, allowing the existing Base history to record changes.
- Source test additions: `tests/js/mail-designer-inline-runtime.test.mjs` uses a small isolated DOM boundary mock to invoke the actual inline controller across commit, cancel, F2, failed/retried save, oversize, bindings and focus. `tests/js/mail-designer-inline-ux.test.mjs` verifies preview revision guards and selector/Inspector integration.
- Isolated execution evidence: V8 syntax compilation of the four relevant JS modules PASS; **8/8 mocked-controller behavioral cases PASS**; **6/6 focused source/pure assertions PASS**. These were executed outside the Base WordPress runtime and are not substitutes for the complete Node/WordPress/browser/Outlook or build/release suites.

**Open reconciliation acceptance:** rich-text DOM selection and link-focus with a real browser, WebKit/mobile touch, canvas/Inspector editing round trip, consecutive undo/redo, cancellation of invalid edits during actual AJAX submits, save failure/retry, full email-client HTML compatibility and tests with Base Luna's concurrent Designer changes. Never label Golden based only on isolated tests.

## M1-D: Composer usability, contextual lists and selection handling (source candidate)

The Base Mail inline editing toolbar now supports a deliberate small set of **content commands only**: B/I/U, a link field with existing `safeMailLink` validation, single-item bulleted and numbered lists, and Ctrl/Command+B/I/U/K. Global layout, typography, alignment, spacing, image and CTA link properties remain Inspector-owned.

- Mail toolbar `assets/js/design/mail/inline-toolbar.js` only acts on a **nonempty selection within the current editable block**. It retains an editor-owned Range while focus moves to a button, drops it after an action, and removes its `selectionchange` listener on toolbar teardown. No range outside the active text block is accepted.
- List conversion avoids nesting `<ul>` or `<ol>` inside `<p>`: a whole paragraph can be converted; a partial paragraph selection stays unchanged with explanatory status. Multi-paragraph list UX and actual Outlook/HTML parity remain acceptance work.
- `assets/js/design/mail/inline-text.js` forwards rich-text shortcuts to the toolbar, exposes the active path, and leaves plain Heading/Text nodes in copy-only mode.
- `assets/js/features/mail-designer.js` commits the previous inline edit before selecting another canvas node with a single click. A failed commit leaves editing active and blocks the new selection, rather than silently dropping data.
- `tests/js/mail-designer-inline-format.test.mjs` covers toolbar selection, B/I/U, bullet/number list creation, safe vs invalid links, shortcut scope, retained focus-range teardown and partial-paragraph protection. The existing M1-A/B/C source tests were reconciled to the controller's current return shape and keyboard finish wrapper.

**Evidence:** Isolated V8 compilation of the touched JavaScript modules and test source PASS; **7/7** new toolbar mock-DOM behavioral cases PASS and **15/15** prior inline-controller/source cases PASS. These are not a real browser, Node full suite, PHP/WP integration, Outlook compatibility or a distribution build. Operator's Base workspace remains with Base Luna; no merge, CI or release.

**Next after Base Luna:** reconcile shared Designer, Mail profile, Inspector and Template Library APIs; test actual selection/caret, focused/blurred toolbar link form, multi-paragraph lists, inline/Inspector round trips, undo/redo, persistent save/reopen and mobile/keyboard accessibility in staging. Expand M2/M3 only after these are assessed.

## M1-E: Composer cross-surface completion (isolated source candidate, NOT Golden)

- **One validated list operation:** new `assets/js/design/mail/list-format.js` is shared by canvas contextual toolbar and Base Mail Inspector. A contiguous selection of whole sibling paragraphs becomes one `ul` / `ol` with one `li` per paragraph. Mixed, partial and nested selections fail closed with author-facing guidance. This is a Mail-only formatting primitive, not a new Designer rendering engine.
- **Protected cross-surface editing:** `assets/js/features/mail-designer.js` now uses `finishActiveText()` and `selectAfterTextCommit()` around Layers, Inspector property changes, palette insertion, node deletion/reorder and the shared Designer Shell undo/redo click actions. Invalid/unfinished draft text prevents destructive navigation. Settings continue to belong to the Inspector; one committed inline edit remains one canonical `session.execute(commands.setProperty(...))`.
- **Inspector commit retention:** `assets/js/design/mail/rich-text-controls.js` does not advance its locally acknowledged value until the Base session accepts the change. On failure, the field preserves its content and announces a visible error.
- **Tests:** shared list selection tested for two paragraphs and partial multi-paragraph refusal, with prior inline toolbar/controller tests rechecked. M1-E source contracts confirm that undo/redo, Layers, deletion, palette and Inspector must pass a finish gate and that both surfaces use the same formatter.
- **Isolated evidence:** 9/9 parsed JavaScript files/test modules and 25/25 focused behavioral/source assertions PASS using GitHub-source readback and a restricted V8 mock-DOM harness. These do not amount to a full Node suite, PHP lint, WordPress tests, real browser/editor input, mail-client output or a distribution ZIP.
- **Acceptation OPEN:** Chrome/Firefox/Safari genuine Range/selection, pasted line breaks, multiple paragraphs with inline links, accessibility, pointer/touch behavior, Inspector↔Canvas content fidelity, keyboard/native undo vs canonical Base history, save/failure/reopen, RTL/language, and integration with Base Luna's concurrent Inspector, modal and Template Library work.

No direct changes to Base Luna's active branch, `main`, Newsletter production consumer or the shared Designer Shell; no merges, CI, publication, customer sends or release build.

## Reconciliation handshake

1. Freeze new branch SHA, file list, tests and scope.
2. After Base Luna finishes their Base branch, compare merge-base/files/API/schema and conflicts with that branch and latest main.
3. Run PHP syntax, JS syntax, PHP Unit 9.6 integration on WP7.0/7.1, JS profile/browser tests, i18n, full Base check, package ZIP/checksum. Operator runs local commands on clean reconciled branch.
4. Accept visual editor UX, exact client rendering and backwards compatibility on sandbox.
5. Merge **only after explicit GO**. No direct main changes from this branch; no CI without approval.

**Evidence disclaimer:** source implemented here is not locally tested/Golden until user performs above gates.
