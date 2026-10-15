# Base Mail Designer Golden | Independent development branch

**Decision (2026-10-15):** Build one professional, reusable email-design system in Base. Newsletter, transactional Base Mail, Automations and later consumers extend it through public contracts. Bricksforge provided a feature/architecture reference only; NO Bricksforge source, proprietary UI or assets are copied.

**Branch:** `feature/base-mail-designer-golden-m0-m2-v1` based on Base `main@7bb3f17ae3c6045838a414949a834ae5f9254286`. Base Luna is patching Base concurrently. Do not merge, rewrite their branch, run shared CI, or claim local WordPress integration PASS until the operator can reconcile and test this branch.

## Source reconciliation: existing components

- Base already owns `Design/Profile/Mail/{Contract,Validator,HtmlRenderer}`, safe 600px-table rendering, basic sections, headings, text, buttons, images, dividers, spacers, scalar bindings, preview markers and responsive CSS.
- Base `Mail/Designer/{ComponentRegistry,BindingRegistry,TemplateRegistry,TemplateRepository}` supplies declarative extension points.
- Shared editor `createSession`, commands/history, selection, shell, layers and Inspector already exist; do not fork these into Mail Designer.
- Base Mail Designer currently relies on a feature-level inspector with plain textarea for body text and one-section palette insertion. It lacks proper rich-text WYSIWYG, true layout columns, client parity certificates and polished validation/preflight.
- Template Library is being developed by Base Luna on separate feature branches. Its integration is a later reconciliation/acceptance item, not permission to create a second library.

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

## Reconciliation handshake

1. Freeze new branch SHA, file list, tests and scope.
2. After Base Luna finishes their Base branch, compare merge-base/files/API/schema and conflicts with that branch and latest main.
3. Run PHP syntax, JS syntax, PHP Unit 9.6 integration on WP7.0/7.1, JS profile/browser tests, i18n, full Base check, package ZIP/checksum. Operator runs local commands on clean reconciled branch.
4. Accept visual editor UX, exact client rendering and backwards compatibility on sandbox.
5. Merge **only after explicit GO**. No direct main changes from this branch; no CI without approval.

**Evidence disclaimer:** source implemented here is not locally tested/Golden until user performs above gates.
