# Designer Consumer Admission v1 — first SOURCE slice

**Status:** CA-P1 SOURCE candidate. No external native Host runtime admission or
consumer Save transport is implemented by this slice. Base Mail and Reports
continue to compile and run with the unmodified Base-only registry.

## Responsibility boundary

- Base owns the Host, immutable Registry, session/history, module verification
  and the public Designer shell.
- A consumer owns its domain model, permission checks, resources, persistence,
  server-side validation and revision compare-and-swap.
- No user-entered URL, browser registration, dynamic import, plugin-presence
  check or `wp-config.php` manifest digest authorizes a Designer.
- The installed WordPress plugin/update channel establishes publisher trust.
  The installed artifact manifest proves that its packaged Designer modules
  and CSS match the installed bytes, not independent publisher authenticity.

## PHP registration (opt-in; one consumer per opened Host context)

An active, normally installed extension can expose one descriptor via the
`cb_core_designer_consumer_profiles` WordPress filter. The callback must
return a *list*, not a keyed registry. Each descriptor contains **exactly**:

- `profileId`: non-Base profile identifier (`vendor/profile`).
- `packageId`: identifier bound to the installed manifest.
- `installedRoot`: the actual plugin root directly under `WP_PLUGIN_DIR`.
- `declare`: callable declared inside the installed consumer root.
- `register`: callable declared inside the installed consumer root.

`declare(ProviderRegistry, DesignTypeRegistry, CapabilityRegistry,
AuthorizationRegistry): void` registers provider/type/capability/grants.

`register(DesignTypeRegistry, ProfileRegistry, ComponentRegistry,
ControlRegistry, ModuleCatalog, array $artifact): void` registers the profile,
typed controls, components and modules. Use
`ApprovedManifestSource::module_stamp($artifact, $relativeJsPath)` for every
consumer-owned module and `consumer/<relativeJsPath>` as its `packagePath`.

Both callbacks are server-executed PHP, sourced from the admitted plugin.
All type declarations must precede the three profile registrations.
The resulting graph contains only the native Mail and Reports profiles plus
the one requested external profile.

Calling `Bootstrap::frozen_registry($profileId)` on an explicitly
authorized server-side consumer request collects that one descriptor and
compiles it with Base. Calling `Bootstrap::frozen_registry()` retains
the original Base-only behavior.

Only the consumer profile module may be located under the attested
`consumer/` package path. All Base modules remain under `base/`.
Registration collisions and missing/modified consumer assets fail closed.

## What CA-P1 does not grant

CA-P1 **does not** register a WordPress transport for external contexts,
create a Save adapter, authorize a resource, provide a consumer runtime module
import map, or allow a consumer to write through Base's Mail/Reports Repository.

CA-P2 must connect the separately authorized consumer resource and its own
Save authority to the existing `attachTrustedDesignerHost()` bridge, with
the same installed Base+consumer artifact evidence and no second History or
Save engine. Automations is the first proposed consumer acceptance case.

A consumer may still use the existing public `@cb-core/design-editor`
Domain Session v2 and shared shell without becoming a native Host profile.
This requires checking the compatible installed Base PHP/ESM contract.
