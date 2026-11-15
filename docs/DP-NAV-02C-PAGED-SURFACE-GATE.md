# DP-NAV-02C — physical page-to-semantic-fragment engineering gate

Status: **GATE 2 USER PASS; OPTIONAL BACKEND PROJECTION SOURCE CANDIDATE / NO PAGED UI**. Base owner: Core Blueprint. Started from
`main@2ac4a9c3561410374ed0e5c48ae291d9a091f0bc`.

Related canonical roadmap: WordPress Overseer #94, with #93 (navigation),
#75 (Designer freeze), #64 (shared UX), and #60 (conformance).

## What is already delivered on Base main

- DP-NAV-01: accessible Base-owned page navigation; disabled until **actual page
  descriptors and page targets** exist.
- DP-NAV-02A: only Main Workspace owns scrolling, no isolated-iframe scroll.
- DP-NAV-02B: Dompdf 3.1.6 returns PDF bytes and authoritative page count from
  the same rendering operation. A page count does not give target boundaries.
- Reports and Contracts Flow preview currently render as **continuous HTML**.

Do not manufacture paper boundaries by dividing the HTML height into 297 mm
segments. HTML pagination, PDF layout and browser font rendering differ, and
split paragraphs/tables would be misrepresented.

## Gate 1 — Can Dompdf expose real semantic frame fragments per page?

Run the standalone probe **only against the pinned bundled Dompdf 3.1.6**:

```bash
php -l tools/probe-flow-pagination.php
php tools/probe-flow-pagination.php
```

It creates local, generated A4 fixtures with semantic IDs and collects
`begin_page_render` and `end_frame` callbacks, using the same Dompdf event API
documented by the bundled source. The physical page number comes from Dompdf's
own Canvas; it is **not inferred** from CSS or screen pixel geometry. Probe
cases: one page, split paragraph, table overflow, nested component over a page,
and explicit page break. Callback count/fragment count, page number, region ID,
box geometry and output are bounded or rejected. Remote access and embedded
PHP/JavaScript are disabled; no site data, external services, WordPress DB,
persisted generated PDFs or vendor edits.

**Interpret results as evidence, not completion.** `PROOF_CANDIDATE` means
these *synthetic* cases preserved region identity on multiple rendered pages.
`HOLD` or any `UNPROVEN` case means the callback mapping is insufficient for
the acceptance contract, and requires another authority strategy. The probe
does not establish exact positional parity, text-line offsets, repeated table
headers, embedded images, browser rendering or interactive hit testing. Its
output must be inspected before committing any production page mapping.

## Gate 1 user-run result — confirmed candidate (2026-11-15)

At `cd805f2f2a2f89e3383a10cca6cfdee0783f71c3`, Chris executed
the standalone probe with the bundled Dompdf 3.1.6: **5/5 PASS**, no
invalid callbacks, `PROOF_CANDIDATE` and exit code 0.
Actual page counts: 1, 4, 4, 4, 2. One split paragraph yielded the same
semantic ID on pages 1–4; the table's rows mapped to physical pages
1–4 and the nested container preserved identity across pages.

**This is page membership only.** It does not imply correct per-page frame
rectangles, PDF visual parity or interactive A4 Designer surfaces. A new
bounded Gate 2 probe now samples PDF-point rectangles clipped to each
physical page. It rejects non-intersecting frames and requires a coherent
sampled region-page map. A positive result is still a projection candidate,
not proof of pixel-perfect hit targets.

**Gate 2 operator step:**

```bash
php -l tools/probe-flow-pagination.php
php tools/probe-flow-pagination.php > /tmp/cb-dpnav02c-geometry.json
```

Summarize `status`, `proof`, `page_dimensions`, `geometry.clipped_fragments`,
`geometry.fully_outside`, `invalid` and
`geometry.samples` for the five cases. Preserve the full JSON locally.
A failing result is a diagnostic gate, not a reason to weaken bounds.

## Gate 2 user-run result — physical geometry proven on synthetic cases

At `f8392c60116eba6632c3f7a38b228e1328b14635`, Chris ran the
extended actual Dompdf proof: **5/5 PASS, PROOF_CANDIDATE, exit 0**.
A4 page dimensions are 595.28 × 841.89 PDF points. The one-page, split
paragraph, table spill, nested split and explicit break probes reported
zero invalid/outside/clipped fragments and consistent per-page coordinates.

Examples: the split paragraph occupies page-local rectangle
`(51.02,42.52,493.23,742.63)` on pages 1–3 and shorter last-page
rectangle `(51.02,42.52,493.23,649.8)` on page 4. Nested fragments
similarly preserve stable semantic identity across pages; the explicit
page break places `r2` only on page 2.

These are synthetic, sampled frame boxes. They are **not** independently
verified pixel-perfect hit targets, do not yet prove repeated table header
mapping or full report PDF parity, and do not establish a browser viewer.

## Gate 3A — optional Base PDF region projection (source candidate)

New opt-in Flow API (not yet qualified/consumer-enabled):

```php
FlowRenderApi::pdf_with_page_regions(
    $layout, $typed_blocks, $locale, $presentation, $preview_regions
);
```

- Emits PDF bytes from **one** hardened bundled Dompdf render, together
  with its actual page count, physical dimensions and bounded region boxes
  in PDF points; it does not render a second time.
- The shared Flow renderer inserts *inert, validated region attributes*
  into the PDF-specific HTML. This preserves normal PDF presentation
  while allowing Dompdf's end-frame callback to retain fragment identity.
- `PageRegionCollector` internally enforces ≤500 distinct region IDs,
  ≤5000 frame fragments, bounded page numbers and canonical box geometry.
  Missing/invalid/out-of-page data fails closed, not converted to fake pages.
- Existing `pdf()`, `pdf_with_page_count()` and `preview_html()` retain
  their existing semantics and public contracts. No consumer or endpoint is
  automatically opted in.
- No HTTP route, generated PDF stored on disk, new database schema,
  third-party dependency or vendor edit is part of Gate 3A.
- Source implementation and targeted integration tests have been committed
  on the DP-NAV-02C feature branch, but **runtime tests, Level 1/2, ZIP and
  browser FIELD remain OPEN**.

## Gate 3B — interactive paged browser viewer (SOURCE CANDIDATE / NOT CONNECTED)

A bounded two-part page-surface and pinned PDF.js adapter have now been added
on this unmerged feature branch, along with optional CSS and Node tests.
See `docs/DP-NAV-02C-GATE3B-PAGE-SURFACE.md` for exact capabilities,
source-only evidence, the unbundled PDF.js dependency, security and next gates.
No WordPress consumer, transport or page visual is yet connected.

A safe local PDF page renderer, authenticated unsaved-preview transport,
trusted fragment overlay, native selection/Inspector/Layers/DND mapping,
and actual DP-NAV-01 DOM targets are required. Gate 3A is not evidence
that the user can see or edit separated A4 pages.

## Gate 3 — actual Foundation paged-source contract (in progress)

Only after Gate 1 evidence:

1. Render a **single approved Flow document** to PDF with authoritative
   page count and validated, clipped **per-page fragment rectangles** from the
   same Dompdf pass. Avoid changing binary PDF semantics and do not expose the
   vendor's internal frame types as a public API.
2. Demonstrate *fragment geometry* and PDF visual agreement using A4 real
   report fixtures: split paragraphs, split tables, repeated headers/footers,
   nested containers and long text. Fail closed on missing or overlapping
   geometry; do not invent mapping or silently truncate.
3. Evaluate a **locally bundled, audited PDF page renderer** for visual page
   surfaces (no network or cloud). A PDF canvas is not editable: map each
   verified semantic fragment through a separate Foundation interaction layer
   preserving native selection, Inspector, Layers, context actions and DND.
   Disallow PDF script execution, remote loads, uncontrolled HTML and stale
   preview generations. Use short-lived, authorized, no-store unsaved-preview
   transport. Audit bundle license, CSP/worker, memory and server load.
4. Give the native Main Workspace sole scroll ownership and hand DP-NAV-01
   real, stable page descriptors/DOM targets. No page chooser until actual
   target elements exist.
5. Preserve fixed/flow profile compatibility and explicit consumer opt-in.
   Reports is the first **test consumer**; Contracts and subsequent document
   consumers share Base pagination. Mail and unpaged designers do not inherit
   artificial paper pages.

## Evidence and acceptance

Require exact-commit Node/PHP tests, Base Level 1, disposable WordPress Level 2,
a fresh internal ZIP, and user FIELD acceptance as separate gates. Acceptance
must verify 0/1/2/many pages, automatic and explicit breaks, long split text,
table spill and headers, A4 margins, per-page navigation, keyboard/focus,
interaction overlays, responsive and zoom behavior, PDF visual parity, and
security/resource limits. This backend source candidate must not be represented as
a completed paged A4 editor or an accepted release.

No production consumer patches, merge, CI/CD, release or deployment are
authorized by this proof task.
