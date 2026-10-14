# Template Browser Foundation: T2 consumer integration

Status: **implementation candidate**, pending WordPress/runtime acceptance.
This is a shared, offline-first metadata browser. No Base-owned domain
documents, workflows, silent template application, Marketplace integration,
template downloads or outbound vendor calls.

## Public interfaces

PHP: CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface,
TemplateSourceRegistry, TemplateQuery, TemplatePage, TemplateMetadata,
DesignerScope::issue(), Assets::enqueue().

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
   DesignerScope::issue('core-blueprint-automations', 'workflow') returns
   a non-null token. Replace 'workflow' with the exact Designer identity.
   Place the escaped token in a private data attribute on the action button;
   do not put it in a URL, logger or browser storage.
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

## Contractual security boundary

REST GET endpoints:
- /wp-json/core-blueprint/v1/template-library/sources
- /wp-json/core-blueprint/v1/template-library/templates

Browser cookie auth uses the standard X-WP-Nonce. Contextual requests also
send X-CB-Designer-Scope; token is HMAC authenticated, expires after 15 minutes,
and is bound to WordPress user ID, logged-in session, source authorization and
the exact extension/Designer type. Supplying client-chosen extension/designer
parameters cannot broaden it. Global browsing requires manage_options.
All metadata is rechecked using source can_browse and per-item can_view.

Base does not expose a preview/apply/write endpoint. The shared browser uses
the existing @cb-core/modal workspace+expandable API; it cannot activate or
persist domain data. Query limit defaults to 24 and maximum 48. HMAC cursor
binds to actor/context/query/source identities. No template content is sent
in metadata results; no vendor network access.

## Acceptance

- Base Level 1, canonical protected Level 2 and reproducible Level 3 build.
- Registry and browser contract tests, positive and negative authorization.
- Screen-reader/keyboard, responsive/workspace modal and RTL/locale checks.
- Automations owner-only save/apply and disabled-workflow E2E after its adapter.
- No merge, CI or deployment without separate explicit GO.

T3 owns personal/site save and management, T4 owns declarative offline pack
import, and T7 optional explicitly configured Updates network distribution.
