## TL-1C-B provider qualification notes (source candidate)

TL-1C-B1 native built-in Mail: six immutable `wordpress.*` definitions only. Other registered Mail template providers remain in metadata discovery but are not trusted for the default preview implementation. Uses the canonical `ProjectRenderer` once and literal example bindings. The preview may show only example identities; it does not call live Mail sending, site settings, token stores or users.

TL-1C-B2 Saved Mail: per-row `SavedTemplateStore::find` actor and personal/site ACL, exact current revision, synthetic interpolation. The saved document's **authored literal content may itself be sensitive**; it is intentionally visible only to an authorized operator under the active per-row ACL. Never persist/cache/share it in the Browser, and always return a generic refusal when revoked/stale.

TL-1C-B3 Reports: `DesignerPreview::render_library_sample` calls the existing Flow compiler with the **separate fixed sample snapshot**. It never calls `preview_report()` or archived Reports `Storage::find_recent`. Site name/URL, actor ID, version and PHP runtime data are replaced by example values, and attachment/logo branding is forced off. The separate Reports Designer preview retains its existing archive behavior and must not be bound to Library.

Shared `PreviewHtmlProjection` is an inert OUTPUT filter, **not** a generic template/document renderer. It parses an already-rendered, capped HTML document without network access, removes active/resource node subtrees, strips all URL-bearing markup and unapproved attributes, and creates the same strict v1 `TemplatePreview` DTO. No second render engine, no external fetch and no HTML in the listing.

**Important security/UX boundary:** the v1 fragment intentionally contains no provider CSS `<style>` elements, images or links; individual inline styles are retained only when WordPress KSES permits them. Reports' original full document CSS is not reproduced in v1. Visual accuracy and safe CSS model must be evaluated during TL-1C-C FIELD; do not claim pixel-identical previews. The Browser preview UI has not been implemented; the new v1 provider route only returns inert fragments when explicitly requested. Opaque iframe with strict CSP remains mandatory.

Current status: SOURCE CANDIDATE ONLY. No exact-head Level 1, dev ZIP, WordPress Level 2 or UI FIELD result for TL-1C-B. Prior TL-1C-A Level 2 PASS is pinned to its earlier SHA and does **not** transfer to these new commits. No merge, release, CI or Foundation Freeze GO.
