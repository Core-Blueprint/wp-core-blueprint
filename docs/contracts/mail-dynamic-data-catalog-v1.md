# MC-02B — Native Mail Dynamic Data Catalog v1

**Owner:** Core Blueprint Base. **Source branch:** `feature/mail-center-mc02b-provider-dynamic-data-20261115`.  
**Overseer:** https://github.com/christiaanbruinsma/wp-core-blueprint-overseer/issues/92

## Intent

Keep Base as the only Mail authoring, stored design, Save Authority and rendering owner. Every active provider may register its notification IDs through `TemplateRegistry` and optional scalar Mail bindings through the existing `BindingRegistry`. MC-02B makes admitted, authorized **binding metadata** discoverable in the existing Native Mail Designer Elements → Dynamic Data palette. It does not create a new binding engine, provider-owned frontend, stored schema, REST route or mail-send path.

## Server authority and wire shape

The existing, nonce-checked `GET /core-blueprint/v1/designer-platform/context` returns an additional `mailBindingCatalog` for **`base/mail` only**, and only after the request has passed the existing active native Mail resource identity, `manage_options`, Designer enabled state, Frozen Registry, Save Authority and document validations. Reports responses never contain it. It is not sourced from query strings, Browser selections, saved documents or the logged-in actor's content.

Example of a **fictional** Bookings catalog (shortened):

```json
{
  "version": 1,
  "resourceKey": "bookings.customer-confirmed",
  "provider": "bookings",
  "items": [
    {"id":"site.name","provider":"core","group":"site","label":"Site name","example":"Example Site"},
    {"id":"booking.customer_name","provider":"bookings","group":"bookings","label":"Customer name","example":"Jane Example"}
  ]
}
```

The server permits the eight **exact** built-in Base shared IDs and definitions whose `provider` equals the admitted active native template `provider`. ID namespace is not inferred from the binding key: e.g. Bookings uses template IDs `bookings.*` but binding IDs `booking.*`. Do not expose all registered LMS bindings merely because LMS is installed. A provider registration does not independently grant resource/actor authorization.

Maximum **96** entries per resource snapshot, bounded metadata lengths and duplicate rejection at the client. Unknown/stale/malformed `version`, `resourceKey`, provider or descriptor yields an **empty palette**, never mixed provider data. Context switching must receive a fresh server context; the current Browser-first Mail flow navigates to the authorized resource and reloads the Host rather than reusing stale token content.

## Privacy and synthetic examples

The catalog never includes a callable resolver, resolved value, runtime mail context or a provider's registered `preview` field. Provider previews may contain actual configured values, therefore the catalog reads only **Base-owned fictional fixtures**, with `example.invalid` addresses and safe generic fallbacks for future providers. The separate unsaved `Renderer::preview` now uses the same synthetic examples; real send-time `BindingRegistry::resolve` remains unchanged and server-only.

**Never emit:** actual administrator email/site domain, booking/learner identity, customer notes, personal URLs, manage tokens, account recovery links, genuine recipient data, private context or secrets. Binding keys are presentation metadata, not permission to read or resolve actual values. Saved mail tokens continue resolving only at authorized delivery time.

## Native palette and actions

The existing Base Elements palette displays **label**, **provider · group**, the **fictional example**, and the copyable literal `{{binding.key}}`. The click action is **Copy** through the existing clipboard interface only. No HTML injection or markup execution: every field uses `textContent` and is validated before rendering. The catalogue is read-only and does not alter the document, History, Save, delivery, recipients, fallback, or consent.

Rich-text cursor insertion remains a separate capability **not implemented** by this source patch because the current Palette → Host command path offers copy-only token insertion and must not introduce a second editing or History engine. Review keyboard, rich text and undo behavior before adding insertion.

## Exact-head acceptance requirements

- Built-in WordPress: only eight Base shared bindings, including `action.label`; no Bookings/LMS entries.
- Bookings: all fourteen previously registered `booking.*` bindings plus eight shared IDs, with fictitious samples and no resolver data.
- LMS and other providers: only admitted resource-owning provider + eight shared bindings. No other provider cross-contamination.
- Rejected/disabled/nonexistent resource, invalid/future catalog schema, large/duplicate entries, stale provider context: fail closed without cross-provider leftovers.
- Shared UI: one Mail Browser-first launcher, right-click Layers/canvas, Reports page navigation, Save/Exit Guard unaffected.
- Real delivery: the literal `{{booking.customer_name}}` from Copy is resolved by the registered server binding at post-commit runtime. This must still be demonstrated with the exact qualified Bookings plugin in MC-03; it cannot be inferred from Base-only tests.
- Run targeted JS/PHP + full Level 1 + disposable Level 2 and internal ZIP integrity, then owner sandbox FIELD. No merge or release without separate GO.

**MC-02C (saved template management), MC-03 (Bookings functional pairing), MC-04 (LMS inventory/integration) and MC-05 (release) remain separate gates.**
