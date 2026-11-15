# DP-NAV-02C — physical page-to-semantic-fragment engineering gate

Status: **PROOF ONLY / NO PAGED UI**. Base owner: Core Blueprint. Started from
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

## Gate 3 — actual Foundation paged-source contract (not yet implemented)

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
security/resource limits. This proof-only source must not be represented as
a completed paged A4 editor or an accepted release.

No production consumer patches, merge, CI/CD, release or deployment are
authorized by this proof task.
