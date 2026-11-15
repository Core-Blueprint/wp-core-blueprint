# DP-NAV-02C Gate 3B — shared paged PDF surface

**Status: connected in Base/Reports source, but not yet qualified, packaged or FIELD accepted.**
Owner: Core Blueprint Base. Overseer: WordPress #94. Main remains MC-02C accepted.

## Confirmed foundation evidence

- At `b13e783518095c4909dd9838c8b6f103ceaf8335` Base Level 1
  **PASS** and disposable WordPress Level 2 full matrix **PASS**.
- Exact Level 2 receipt:
  `dist/qualification/base-disposable/d415f52c544b2`.
- The owner also executed the Dompdf semantic/page probe and physical-geometry
  probe on prior exact heads: 5/5 cases PASS for 1, 2 and 4 actual A4 pages.
- The optional `FlowRenderApi::pdf_with_page_regions()` returns PDF + its
  authoritative page count and page-local semantic rectangles in one render.
- None of these proves that the browser paged viewer is integrated or accepted.

## Gate 3B source candidates (after Level 2)

`assets/js/design/document/flow/paged-surface.js`:
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
- Page navigation calls actual page `scrollIntoView` and acknowledges active
  page only through the native Foundation navigation controller.
- Keeps the host's Main Workspace as the only scroll owner; no inner iframe
  scroll, no CSS height-based page splitting, no generated page counts.

`assets/js/design/document/flow/pdfjs-page-source.js`:
- Accepts only an injected pinned PDF.js 6.5.438 display layer with a
  same-origin, known-name local worker URL and a copied PDF byte snapshot.
- Rejects untrusted worker origins, version mismatches and invalid or oversized
  PDF bytes. Disables PDF JavaScript eval and XFA during page loading.
- This module dynamically loads the standard `@cb-core/pdfjs-display` WordPress
  import-map dependency. It does not download PDF.js or import a CDN.
  **The actual upstream `pdf.min.mjs`, `pdf.worker.min.mjs`, font/CMap/WASM
  runtime and LICENSE are not yet vendored/committed.** The browser cannot
  render until the official pinned distribution has been audited and added.

`assets/css/design/document-paged-surface.css`:
- Opt-in, scoped responsive paper and accessible semantic overlays. Reports
  now enqueues this only for its native document Designer, never Mail.

`tests/js/design-flow-paged-surface.test.mjs`:
- Covers paper construction, authoritative page descriptors, lazy draw,
  activation, selected region, page navigation, teardown/stale render,
  projection refusal, PDF.js worker/version and PDF-data refusal.

## Unfinished P0 connection

1. **Dependency admission:** vendor only the necessary pinned PDF.js display
   module + matching worker and their full license/NOTICE, make the dependency
   build-time deterministic (no runtime network). Audit CSP `worker-src 'self'`,
   module support, no code execution from PDFs, memory limits, and packaging.
   Apache-2.0 is GPLv3-compatible but not GPLv2-only-compatible; verify Core
   Blueprint's GPL-2.0-or-later distribution obligations and retain notices.
2. **Authenticated unsaved preview transport:** SOURCE WIRED. The new
   `/core-blueprint/v1/reports-designer/preview-paged` REST POST duplicates
   existing strict nonce, authenticated capability, Reports-state and native
   document-shape admissions. It returns private no-store PDF base64 bytes and
   associated fragment JSON from the SAME one-pass Dompdf render, without site
   writes. Verify size, MIME, CSP, actual PHP performance and privacy in FIELD.
3. **Controlled integration:** SOURCE WIRED. Base exports the public Flow
   paged host, registers local pinned PDF.js dynamically, and Reports opts into
   the new paged preview and native DP-NAV-01 controller. Semantic IDs resolve
   back to trusted document paths for the one shared Selection, DND, Layers
   and Inspector authority. This must still be proven in live WordPress with
   actual PDF.js bytes, accurate CSS zoom and split text/table fixtures.
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
source exactly once. This does **not** replace `node --test` or browser FIELD.

**No new browser ZIP, site install, merge, release, CI/CD or deployment.**

The vendor admission source is `tools/vendor-pdfjs` (npm-pack SRI verification,
local LICENSE and asset hash manifest). Both `tools/build-release` and `build-dev`
refuse an absent or modified vendor bundle. The source-only additions are not
proof that the pinned npm tarball has been downloaded or a WordPress viewport
has rendered a real PDF. `docs/DP-NAV-02C-DOCUMENT-RELIABILITY-GATE.md`
contains the mandatory all-document-consumer acceptance matrix.
