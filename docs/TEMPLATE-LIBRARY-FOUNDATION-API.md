# Template Library Foundation: T0/T1 public PHP contract

Status: **additive T0+T1 + T2 browser implementation candidate**, subject to WordPress/runtime acceptance; pack format and consumer-owned personal/site operations remain **not implemented**. T3A delegated safe management navigation is now an additional source candidate; it does not persist or mutate templates in Base. PHP 8.4+; single-site Base v1 only. See docs/TEMPLATE-BROWSER-CONSUMER.md for the browser and first-consumer contract.

## Ownership and presentation

Base owns one Template Source Registry for two future presentations:

- **Global Template Library** in WordPress Admin discovers metadata from Base and all authorized registered extensions. It can filter by extension, Designer type, origin, category and locale.
- **Browse templates** inside an existing Designer is *locked* to its exact registered extension **and Designer type**. Even two Designers in the same extension cannot see each other's results. The future browser must not render extension/type switchers inside a Designer modal. Both views use the same Base code; modal presentation uses public `@cb-core/modal`, `size: 'workspace'`, `expandable: true`.

An extension owns its schema, storage, per-item ACL, previews, instantiation and save/import/export/duplicate/delete operations. None of these operations is supplied by the T1 Registry. Global Library editing must open the canonical owning Designer, not implement a second editor. There is no template-related external HTTP request or vendor connection in Base.

## Registration

Consumers attach to `core_blueprint_register_template_sources` during plugin load. Base collects **once** at `init` priority 6, after `ExtensionRegistry`'s priority-5 collection. Only register a `CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface` implementation inside that hook. The source supplies:

- `source_id(): string`: unique stable lower-case identity, may contain hyphens/dots/underscores;
- `extension_id(): string`: `core-blueprint` or an actually registered `ExtensionRegistry` ID;
- `designer_type(): string`: stable semantic document/Designer type, **not** a generic extension slug;
- `can_browse(int $user_id): bool`: owning consumer's read authority;
- `can_view(string $template_id, int $user_id): bool`: per-template read authority for personal/site distinctions;
- `query(TemplateQuery $query, ?string $cursor): TemplatePage`: bounded metadata-only, deterministic ordering, all predicates applied **before** paging, no full document bodies.

Provider implementations must be local-only, no outbound calls. Return `TemplateMetadata` records with source/extension/designer matching the registered source. No secrets, workflow principals, body HTML, credentials, customer content or serialized domain documents are permitted in metadata.

### Optional category facet contract

Sources may optionally implement `TemplateCategorySourceInterface::categories(int $user_id): array`
with at most 48 visible `slug => display label` pairs, without modifying the
frozen `TemplateSourceInterface`. Base validates category IDs, strips markup
and returns authorized, bounded `categories: [{id, label}]` alongside each
source in the existing read-only `sources` REST endpoint. No template counts,
bodies or credentials are exposed. Global UI unions only categories from
currently selected sources/plugins/designers; contextual UI uses the verified
Designer scope. Unadapted sources offer no selectable categories until adapted.

### Request-scoped PHP API

- `TemplateSourceRegistry::sources()`: current-user authorized source identities and types.
- `TemplateSourceRegistry::source_categories()`: metadata-only, current-user authorized optional category labels per registered source.
- `TemplateSourceRegistry::browse_global(TemplateQuery $query, ?string $cursor = null): TemplatePage`.
- `TemplateSourceRegistry::browse_designer(string $extension, string $designer, TemplateQuery $query, ?string $cursor = null): TemplatePage`.

The registry is **not a browser endpoint**, and accepting a `$designer` argument in trusted PHP is **not** proof that a browser user may choose that Designer. **T2 must bind the current Designer's identity to a server-validated launch/session/route authority**; a raw client-provided extension/designer selector cannot be used as trusted scope. Any preview/select/use transport must recheck the same context and consumer authorization, independently of list results.

`TemplateQuery` accepts `search`, `source`, `extension`, `designer`, `origin`, `category`, `locale`, `limit` (named arguments, in that order). Default limit 24, maximum 48, search maximum 120 bytes. Origin is `built-in`, `personal`, `site` or `installed-pack`. The minimum supported locale is `en`. Invalid parameters raise `InvalidArgumentException`; context conflicts yield no results. Items are immutable metadata value objects. `TemplatePage::next_cursor` is null at the end.

Registry cursor is opaque, HMAC authenticated with the local WordPress auth salt, expires after 15 minutes and is bound to user ID, query, scope and current eligible-source identities. Tampering, replay across queries/users/scopes or malformed values returns an empty result. Each source supplies its own bounded cursor (maximum 256 bytes), never exposes it directly to the browser. Source failures fail closed; other sources remain available. Global traversal is stable **source-id order with source-local deterministic ordering**, not globally alphabetized across providers. A future global sort contract requires a separate reviewed change, not a client-side fetch-all shortcut.

## Security and release gates

1. Registration outside collection, unknown owner, malformed IDs and collisions fail closed.
2. Denied users and denied per-item metadata never appear; counts must not reveal a hidden personal template. Browser UI must not mistake `can_browse` for mutation authority.
3. Server-side global-versus-Designer isolation including two Designers of one extension, query/filter mismatch, cursor tampering and cross-user replay.
4. Tested 0/1/1,000+ templates, bounded pages (24 default, 48 maximum), provider failure/empty pages, stable continuation and no network calls.
5. No premature translation during plugin load, no private Designer or Modal asset imports, unchanged existing public APIs.
6. Source and WordPress integration tests, PHP/JS static checks, 6 locales when new UI strings arrive, reproducible release ZIP + checksum, installed WordPress role/functional acceptance.

**T2 implementation:** The central Admin page, shared @cb-core/template-browser module, bounded read-only REST routes, and workspace modal are now included in this feature candidate. They require actual WordPress, browser and source/package acceptance before being called released. T3A adds guarded links to owning Designer management screens; T3B consumer-owned Save/Duplicate/Export/Delete still remains to be implemented. T4 defines bounded declarative packs and offline import. T5 is Automations' first consumer after browser acceptance. T7 (optional Updates distribution) is separately audited and opt-in per vendor; entirely outside this Foundation.
