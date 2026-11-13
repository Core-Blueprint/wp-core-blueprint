# TL-1C-C — shared Browser sandboxed preview source candidate

This phase connects the **existing TL-1C-A POST preview** and **TL-1C-B provider-owned synthetic renderers** to the public Base Template Browser. No listing ever contains document HTML and no preview is fetched automatically.

## Presentation

A "Preview" button is added only for source descriptors explicitly advertising the preview API v1. A valid source capability is UI-discovery metadata **not authorization**: each POST still requires server nonce, current actor, DesignerScope where applicable, record ACL and exact version. Unsupported/expired/removed records fail closed. The action is a sibling of any selectable label and of the provider-owned Actions menu, never nested inside a radio label.

A same-Browser overlay panel is used both on the global Library and the Designer's already-open fullscreen Browser modal. This avoids nested dialogs, duplicate focus traps and an extension-owned iframe renderer. It has an explicit Close control, accessible heading, live loading/error state, Escape interception within its own panel, and focus return to the triggering Preview control. The original selection/Apply workflow is untouched. Refresh, filters, switched designs and destroy cancel in-flight previews and clear iframe `srcdoc`.

## Security boundary

The response body must exactly match the active template's `source`, `templateId`, `version` and client-only `contextEpoch`. Fetch is `POST`, `same-origin`, WP nonce, optional server-issued Designer scope, `no-store`, and `redirect:error`. No synthetic input, actor, profile, URL or markup is taken from caller-controlled metadata to grant capabilities.

The iframe receives **sandbox=""** (no allow-scripts, allow-forms, allow-same-origin or top navigation), `referrerpolicy=no-referrer` and embedded CSP: deny all default/script/network/media/frame/object/font/form/base loads. Only inline styles are enabled to present sanitized provider fragments and a static Base viewport stylesheet. All preview HTML enters *only* `iframe.srcdoc`, never the parent DOM; strict server `PreviewHtmlProjection` + `TemplatePreview` remains the authoritative content sanitizer. Note: browser CSP support varies; opaque sandbox restrictions are an independent protective boundary. No live external assets, credentials, live network, cache, clipboard or thumbnail persistence.

## Qualification

- New `tests/js/template-browser-preview.test.mjs` covers opt-in, both Browser modes, POST identity/nonce/scope, sandbox+CSP, focus restore, explicit denial, async stale response invalidation and no remote rendering on listing.
- Existing JS/WordPress provider tests remain unchanged. No merge, release, CI/CD or deployment.
- **SOURCE CANDIDATE**, not owner FIELD: run exact-head `node --check`, `node --test` targeted + `bash tools/check`, then `python3 tools/build-dev` + ZIP SHA verification and rootless disposable Level 2 before owner browser acceptance.
- FIELD: verify native Mail, Saved Mail and Reports previews in global and scoped browsers on desktop/tablet/mobile, keyboard, Escape, scroll, focus, RTL/light/dark, no external requests in Network tab, and Save/Apply/Actions unchanged. Reports CSS fidelity remains intentionally limited by TL-1C v1's inert fragment contract, and must be evaluated explicitly rather than misrepresented as a pixel-perfect output.

Do not freeze Foundation or start consumer migration until this and TL-1C-D FIELD acceptance complete.
