# Core Scanner field UI acceptance: 2026-10-15

**Status:** source patch delivered; full local Level 1/2/3 and browser acceptance **OPEN**. No merge, publication, deployment or CI/CD is authorized.

## Source boundaries

- Parent: frozen, locally validated Scanner source `feature/core-scanner-threat-evidence-hardening-v1` at `554963a3b27f5bad59452d03175b8f43896f10e8`.
- UI candidate: `feature/core-scanner-field-ui-polish-v1` in `Core-Blueprint/wp-core-blueprint`.
- This is a new source identity. The Scanner parent's Level 1/2/3 receipts and ZIP SHA-256 `57563285ee4a31da2e190531ace81f1d524757ce78257aa3f97886881eb84861` **do not validate this descendant**. The old ZIP is intentionally untouched.
- Designer P02 is a separate feature branch and **is not integrated here**.

## Root cause and corrections

1. Findings and About use the Base-owned `cb-core-interactive-row` / `cb-core-disclosure` component grammar. The Scanner tab omitted `component.interactive-surfaces` from the screen asset registry, so browser-native details markers and unstyled summaries appeared. Declare the existing shared style in `ScreenAssetRegistry::safeguards_requirements('core-scanner')`; do not fork or duplicate Foundation CSS.
2. The local tab nav combined `overflow-x:auto` with a negative tab-bottom margin. Remove the negative margin and suppress only vertical overflow. Preserve horizontal overflow and keyboard focus/navigation on narrow screens.
3. Coverage reused the globally horizontal component-link layout. Give the Scanner coverage cards their own column layout and a title/status header, preserving the existing localized coverage text and its meaning. Header-inspection information stays below metrics.
4. Findings row borders, padding, full-width summary geometry, one disclosure arrow and keyboard focus come from the shared CSS that is now loaded. No scanner severity, baseline approval, quarantine, scan engine or persistence logic is modified.

## Regression evidence / gates

- New standalone read-only `tests/public-core-scanner-ui-regression.php` guards the Scanner asset selection, shared native-marker overrides, renderer classes, tab overflow and coverage geometry.
- The isolated `tests/python/test_check_entrypoint.py` Level 1 orchestration fixtures now include that required UI source test and assert its invocation/order, absence fail-closed behavior, and failure short-circuiting. This resolves seven reported Python failures caused by a stale mocked source inventory; it does not weaken the gate.
- `bash tools/check` includes this new source gate after the existing Scanner security gate. The security regression stays mandatory.
- New WordPress `tests/integration/IntegrityScannerPresentationContractTest.php` checks tab-scoped asset requirements and canonical stylesheet enqueue.
- The GitHub-authored patch has not been run against the operator's local PHP/Node/WordPress browser environment. Do not classify any check as PASS based solely on static source inspection or the parent ZIP.

## Operator validation

From a **clean** local Base checkout, fetch and switch only after recording the existing state:

```bash
cd ~/Downloads/wp-core-blueprint
test -z "$(git status --porcelain)" || { echo 'STOP: dirty working tree'; exit 1; }
git fetch origin --prune
git switch --track origin/feature/core-scanner-field-ui-polish-v1
# If the branch already exists locally, use git switch feature/core-scanner-field-ui-polish-v1 instead.
git pull --ff-only
git rev-parse HEAD
bash tools/check
```

Do not reuse, reset or delete the occupied `scanner_oct15` evidence workspace or database; a new source HEAD requires separately validated test-copy parity and a fresh isolated slot. Follow `tools/check-integration --preflight` / `--dry-run` and the Engineering Handbook. After explicit environment approval, run canonical Level 2 in the fresh slot, then `python3 tools/build-release` with its proven exact-head WordPress test environment. Always verify new archive and new SHA-256 sidecar. No fake or inherited ZIP checksum.

## Staging acceptance (after all source/build gates pass)

- Overview: *About this scan* chevron and title share one row; no native second marker, good focus indication.
- Nav: Overview/Findings/Quarantine/History/Settings retain horizontal tab navigation without vertical scrollbar, including keyboard access and narrow widths.
- Coverage: component name and state on one header row; checked/unexpected/missing/unreadable and uploads header-inspected text underneath, readable without clipping at 320, 375, 768, 1440 px and 200% zoom.
- Findings: one chevron; full-width card and summary row; left-aligned name/status/count, right-aligned operator action; expanded details, nested technical details, baseline review buttons and actionable quarantine controls remain operative and accessible.
- Both light/dark mode and real browser focus/zoom must be inspected. Ensure `NEEDS BASELINE` and `INCOMPLETE` remain meaningful, never presented as proven compromise or clean completion.
- Scanner threat-evidence field acceptance from the frozen Scanner handover remains independently open until actually performed and recorded.

Return exact new Base HEAD, `bash tools/check`, Level 2/3 receipts, ZIP SHA-256 and browser screenshots/errors. **No merge without separate explicit approval.**
