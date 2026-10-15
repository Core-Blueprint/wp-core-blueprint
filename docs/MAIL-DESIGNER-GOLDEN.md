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
- Optional `mail.rich-text` node with **bounded allowlisted HTML fragment**, no scripts/styles/images, escaped binding substitution and an initial **source textarea inspector**. Real visual WYSIWYG interaction, robust text selection and keyboard editing are still open. Existing `mail.text` behavior is not changed.
- Optional `mail.columns` containing exactly two `mail.column` containers. Section-level placement enforced by Validator, bounded ratio/gap, table-based Outlook structure, responsive stacked `td` style, editor selection markers and insertion into the selected column. Multi-column live browser rendering still requires confirmation.
- `tests/integration/MailDesignerGoldenM1M2ContractTest.php` proposes PHP9.6/WP tests (allowlist/XSS, old text, valid/invalid columns).
- `tests/js/mail-designer-golden-m1-m2.test.mjs` proposes JavaScript profile/palette/render-contract checks.
- **GitHub source readback:** 9 focused guards verified; **NOT a PHP syntax, full test suite, browser or release-build PASS**.

This is a compatibility-oriented first code batch, not completion of the entire professional Designer. Do not release or merge until the full M1/M2 Golden and Base Luna branch reconciliation are complete.

## Reconciliation handshake

1. Freeze new branch SHA, file list, tests and scope.
2. After Base Luna finishes their Base branch, compare merge-base/files/API/schema and conflicts with that branch and latest main.
3. Run PHP syntax, JS syntax, PHP Unit 9.6 integration on WP7.0/7.1, JS profile/browser tests, i18n, full Base check, package ZIP/checksum. Operator runs local commands on clean reconciled branch.
4. Accept visual editor UX, exact client rendering and backwards compatibility on sandbox.
5. Merge **only after explicit GO**. No direct main changes from this branch; no CI without approval.

**Evidence disclaimer:** source implemented here is not locally tested/Golden until user performs above gates.
