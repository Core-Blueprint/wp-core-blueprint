# DP-NAV-02C Gate 3B — shared paged PDF surface

**Status: source candidate, NOT a connected WordPress Designer / NOT FIELD accepted.**
Owner: Core Blueprint Base. Overseer: WordPress #94. Main remains MC-02C accepted.

## Confirmed foundation evidence

- At \`b13e783518095c4909dd9838c8b6f103ceaf8335\` Base Level 1
  **PASS** and disposable WordPress Level 2 full matrix **PASS**.
- Exact Level 2 receipt:
  \`dist/qualification/base-disposable/d415f52c544b2\`.
- The owner also executed the Dompdf semantic/page probe and physical-geometry
  probe on prior exact heads: 5/5 cases PASS for 1, 2 and 4 actual A4 pages.
- The optional \`FlowRenderApi::pdf_with_page_regions()\` returns PDF + its
  authoritative page count and page-local semantic rectangles in one render.
- None of these proves that the browser paged viewer is integrated or accepted.

## Gate 3B source candidates (after Level 2)

\`assets/js/design/document/flow/paged-surface.js\`:
- Accepts a **trusted PDF page source**, the corresponding Dompdf page
  projection, optional native DP-NAV-01 controller and a semantic activation
  handler (no document/Save permission).
- Rejects invalid physical page counts (1–500), PDF-point dimensions, region
  IDs, fragment counts, non-finite/out-of-paper rectangles and page-source
  mismatches **before mounting any DOM**.
- Creates one real bounded paper element per physical PDF page and independently
  positioned semantic overlay buttons, suitable for native keyboard operation.
- Renders canvases on demand, caps active raster memory to 3 page canvases and
  8 million pixels per page, and disposes cancelled / stale async renders.
- Page navigation calls actual page \`scrollIntoView\` and acknowledges active
  page only through the native Foundation navigation controller.
- Keeps the host's Main Workspace as the only scroll owner; no inner iframe
  scroll, no CSS height-based page splitting, no generated page counts.

\`assets/js/design/document/flow/pdfjs-page-source.js\`:
- Accepts only an injected pinned PDF.js 6.5.438 display layer with a
  same-origin, known-name local worker URL and a copied PDF byte snapshot.
- Rejects untrusted worker origins, version mismatches and invalid or oversized
  PDF bytes. Disables PDF JavaScript eval and XFA during page loading.
- This module does NOT download PDF.js or import a CDN. There is no bundled
  \`pdf.mjs\`/\`pdf.worker.min.mjs\` in the current branch; a real browser PDF
  cannot render until the dependency is pinned, license-audited, vendored and
  included by the WordPress asset/build graph.

\`assets/css/design/document-paged-surface.css\`:
- Opt-in, scoped responsive paper and accessible semantic overlays; not yet
  enqueued by a consumer.

\`tests/js/design-flow-paged-surface.test.mjs\`:
- Covers paper construction, authoritative page descriptors, lazy draw,
  activation, selected region, page navigation, teardown/stale render,
  projection refusal, PDF.js worker/version and PDF-data refusal.

## Unfinished P0 connection

1. **Dependency admission:** vendor only the necessary pinned PDF.js display
   module + matching worker and their full license/NOTICE, make the dependency
   build-time deterministic (no runtime network). Audit CSP \`worker-src 'self'\`,
   module support, no code execution from PDFs, memory limits, and packaging.
   Apache-2.0 is GPLv3-compatible but not GPLv2-only-compatible; verify Core
   Blueprint's GPL-2.0-or-later distribution obligations and retain notices.
2. **Authenticated unsaved preview transport:** producer compiles authorized
   typed Flow state, emits the PDF bytes and page-region JSON from the SAME
   render. Use short-lived no-store, bounded, authenticated transport. Never
   offer user-controlled arbitrary PDF/HTML entrypoints, remote URLs or site
   storage by default.
3. **Controlled integration:** actual Base ESM/WordPress asset registration and
   opt-in first-party Reports reference UI; native selection/Inspector/Layers
   mapping and the one DND controller, never parallel save engines. Reconcile
   PDF rectangle transforms with CSS zoom and scroll, including split text,
   tables, nested regions and explicit page breaks.
4. **Consumer boundaries:** no Reports-only renderer or per-consumer reflow.
   Contracts/other Flow consumers opt in after Foundation acceptance; Fixed
   documents remain governed by their own profile capabilities. Mail remains
   unpaged.
5. **Proof:** exact-head JS and PHP tests, Level 1, disposable Level 2, fresh
   ZIP, integrity checks, installed WordPress FIELD with 1,2,many A4 pages,
   long text, table rows/headings, page-nav a11y, selection/Inspector/DND,
   zoom/mobile, PDF visual parity, cancellation and sensitive data privacy.

## Source-only smoke evidence

The new ES modules were parsed successfully in an isolated JS runtime.
A synthetic 2-page PDF adapter rendered page 1 on demand, navigated and
rendered page 2, dispatched the correct semantic region activation, updated
selection, acknowledged native page navigation, and destroyed its nodes and
source exactly once. This does **not** replace \`node --test\` or browser FIELD.

**No new browser ZIP, site install, merge, release, CI/CD or deployment.**
