> **TL-M3A-R1 — Conditional Workspace & Contained Library (12 November 2026, SOURCE CANDIDATE):** The existing Base-owned `createBrowser()` renders **two panes** when there is no Designs section (global Library or scoped Templates-only Browser), and retains **three panes** when active Designs exist. Global Library now owns one border/radius/surface workspace around Origin navigation and content, with labeled Source/Plugin/Designer type/Category filters (localized neutral `All` defaults), a responsive two-row toolbar and more compact neutral template previews. The modal's native external frame, Designer resource rows, confirmation footer, context scope and Exit Guard are unchanged. Scoped Save-as is preserved inside the Origin pane when Designs are absent; global Library never receives Save-as or Apply. No new REST/folder contract, permission path, persistence, migration, or consumer adapter. This supersedes the earlier TL-M3A assumption that the global Library must display three panes. **R1 requires exact-HEAD native test/WordPress Level 1, disposable Level 2, ZIP/hash and FIELD desktop/mobile/RTL/light-dark review; no merge/release/CI GO is implied.**

---

> **TL-M3A — Shared Browser Presentation (12 November 2026, SOURCE CANDIDATE):** The global Template Library and scoped Designer Browser now use the same `createBrowser()` three-pane DOM hierarchy, `cb-template-browser--workspace` visual rules, heading/search/filter toolbar, neutral non-document template card previews, and provider-owned Actions popover. The primary pane contains the available Browser sections (global: Templates; scoped with active designs: Designs and Templates). The secondary pane presents **Origin** filters for templates and **Source** (design provider) navigation for active designs; it does not claim there is a folder/collections persistence API. Global Library keeps metadata-only browsing and permitted provider management but **never** exposes Designer selection/Apply/Save-as. The contextual Browser retains its verified scope token, isolated result scrolling, selection/apply boundary and Exit Guard. No REST, PHP source-interface, CAS/revision, export payload or consumer data contract changes. Native Node, Base Level 1, disposable Level 2 and responsive/keyboard/RTL WordPress FIELD checks are required before acceptance; no merge/release/CI/consumer GO is implied. This paragraph documents only the M3A presentation candidate. The TL-M2 R2 note below is a historical checkpoint.

---

> **TL-M2 R2 candidate (12 November 2026):** The shared Browser card now uses upper-start radio selection and an upper-end icon-only Actions button. Its popover uses Base's native top-layer pattern plus a non-clipping fixed fallback rather than expanding the card. No active Designer navigation escapes its Exit Guard; providers remain the sole authority for write/export. Source candidate only, pending exact-HEAD Level 1, protected Level 2 and interactive FIELD acceptance. See `docs/TEMPLATE-MANAGEMENT-CONSUMER.md`.

---

> **TL-M2 update (12 November 2026; candidate):** The shared Browser now renders provider-authorized template Actions in both global and contextual views via the internal `@cb-core/template-actions` module. A valid per-item `TemplateActionSourceInterface`, live actor authority and positive numeric revision are required for Rename/Duplicate/Export JSON/Delete. These operations use the existing provider-owned REST POST management boundary, not the read-only discovery endpoints, and never write active Designer documents. Preview and authorized Manage navigation are available only in the global Library; scoped Designer navigation remains exclusively under its Exit Guard. Generic Edit, Import and ZIP portability are not implemented by this patch. See `docs/TEMPLATE-MANAGEMENT-CONSUMER.md` for the exact contract and qualification gates. No consumer or merge/release GO is implied.

---

# Template Browser Foundation: T2 consumer integration

Status: **implementation candidate**, pending WordPress/runtime acceptance.
This is a shared, offline-first metadata browser. No Base-owned domain
documents, workflows, silent template application, Marketplace integration,
template downloads or outbound vendor calls.

## Public interfaces

PHP: CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface,
TemplateSourceRegistry, TemplateQuery, TemplatePage, TemplateMetadata,
TemplateDesignerAuthorityInterface, DesignerScope::issue(), Assets::enqueue().

ES module: **@cb-core/template-browser**, named export open() and
createBrowser(), or window.cbCore.templateBrowser.

PageRegistry foundation requirement: **template-browser**.
WordPress Admin global page: Core Blueprint > Template Library.
Global discovery is restricted to manage_options and source-level authorization.

## Automations as first consumer

1. During normal plugin load, attach the Automations provider registration
   callback to core_blueprint_register_template_sources. Register a source
   implementing TemplateSourceInterface inside that callback. Its extension_id
   must be the existing registered Automations extension identity. Its
   designer_type must be the exact workflow Designer identity.
2. The adapter returns bounded, ordered **metadata only**, applying all
   TemplateQuery predicates before pagination. Each item's source, extension
   and designer must match the registered source. Keep all workflow definitions,
   trigger/action payloads, actors, credentials and ownership ACL inside
   Automations.
3. On the existing authenticated wp-admin Designer screen, enqueue
   Assets::enqueue() (or the semantic PageRegistry requirement). Render the
   Browse templates action only when server-side
   its registered source implements TemplateDesignerAuthorityInterface. It must
   authorize the current admin launch screen and current actor/resource separately.
   After the server resolves the actual workflow resource, call
   DesignerScope::issue('automations-template-source', 'workflow', $workflow_id)
   with the registered source ID, validated resource type and server-resolved key.
   An unauthorized launch or unavailable resource returns null. Place the escaped
   token in a private data attribute on the action button; never put it in a URL,
   logger or browser storage. Tokens must be issued per authorized resource.
4. In Automations' registered JS module, declare @cb-core/template-browser
   as a WordPress script-module dependency and import the named open export:

       import { open } from '@cb-core/template-browser';
       button.addEventListener('click', () => open({
         contextToken: button.dataset.cbTemplateScope,
         onChoose: async (metadata) => {
           // Call existing consumer-owned authorized create-from-template API.
           // Never treat a selected metadata item as authority.
           // Ensure new workflow is DISABLED pending explicit operator review.
           return true;
         },
       }));

   onChoose receives a copy of source, extension, designer, id, title,
   description, origin, category, locale, version and compatible.
   Returning false holds the modal open. A successful callback closes it.
5. Revalidate per-template access, WP nonce, manage capability, workflow
   definition schema and server-issued source identity at Automations' own
   instantiation endpoint. **Imported or instantiated Automations workflows
   MUST default to disabled, with no inherited privileged principal,
   credentials or implicit approval.**
6. Preserve Automations' three existing WordPress starter workflows in its
   own source registry and persistent workflow service. This Base adapter is
   discovery/presentation only. Separate Automations M2 acceptance stays intact.

## Trusted consumer Designer authority (scope v2)

A source may implement `TemplateDesignerAuthorityInterface` alongside its existing
source contract. This is required for contextual tokens; global browsing does
not require it. The source implements two independent server-side checks:

- `can_launch_designer(resource_type, resource_key, user_id)` confirms the
  real authorized wp-admin launch context. Base calls it only at token issuance.
- `can_access_designer_resource(resource_type, resource_key, user_id)` confirms
  current actor permission and resource existence. Base checks it on issuance
  and **every** scoped REST request, including after revocation or deletion.

No consumer may derive resource authority from a browser-selected template,
registered source, generic role or JavaScript epoch. Scope v2 accepts one
registered source ID and one server-resolved Designer resource at issuance;
the source itself supplies extension/Designer identity. The native Mail/Reports
Trusted Host remains unchanged and does not admit external consumers.

Closing a browser tab does not guarantee server-side revocation; tokens expire
after 15 minutes and are denied when the WP session or consumer's current
resource authorization fails. Independently authorized concurrent tabs remain
valid. A consumer that supports explicit server-side context revocation must
reflect it in `can_access_designer_resource()`.

## Contractual security boundary

REST GET endpoints:
- /wp-json/core-blueprint/v1/template-library/sources
- /wp-json/core-blueprint/v1/template-library/templates

Browser cookie auth uses the standard X-WP-Nonce. Contextual requests also
send X-CB-Designer-Scope; token is HMAC authenticated, expires after 15 minutes,
and is bound to WordPress user ID, verified nonempty live session, site,
registered source, extension, Designer type and exact resource. The consumer's
can_access_designer_resource() is rechecked on every scoped request. Supplying
client-chosen extension/designer parameters cannot broaden the scope. Global
browsing requires manage_options.
All metadata is rechecked using source can_browse and per-item can_view.

Base does not expose a preview/apply/write endpoint. The shared browser uses
the existing @cb-core/modal inset fullscreen API for Designer browsing; it cannot activate or
persist domain data. Query limit defaults to 24 and maximum 48. HMAC cursor
binds to actor/context/query/source identities. No template content is sent
in metadata results; no vendor network access.

## Acceptance

- Base Level 1, canonical protected Level 2 and reproducible Level 3 build.
- Registry and browser contract tests, positive and negative authorization.
- Screen-reader/keyboard, responsive/workspace modal and RTL/locale checks.
- Automations owner-only save/apply and disabled-workflow E2E after its adapter.
- No merge, CI or deployment without separate explicit GO.

T3A now offers optional owner-delegated Preview/Manage navigation in the global browser (see docs/TEMPLATE-MANAGEMENT-CONSUMER.md). T3B still owns consumer-side personal/site save, duplicate and deletion. **Offline template export/import is a required product capability** for local backups and transfer to another WordPress site; T4 defines the shared portable package contract and consumers own authorized export/import execution. The requirement must be designed before Foundation Freeze and accepted before each relevant consumer's stable release. T7 online distribution remains deferred and is not required for offline portability. See docs/TEMPLATE-OFFLINE-PORTABILITY-REQUIREMENT.md.
