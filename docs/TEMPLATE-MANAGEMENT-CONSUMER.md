# Template Library T3A: delegated management navigation

Status: **additive code candidate**, awaiting Base Level 1, protected WordPress
integration, and browser QA. This is the first bounded T3 slice.
No consumer documents or credentials are saved by Core Blueprint Base.

## What is available

Base global Library can display an optional **Preview** and **Manage**
navigation link on a template card supplied by an authorized provider.
The Designer-locked picker remains selection-only; it never exposes
cross-Designer management actions. Sources that do not implement the optional management interface simply
expose no management links. This is a current capability boundary, not a
promise to preserve deprecated provider interfaces.

Only a registered source implementing
CoreBlueprint\Core\TemplateLibrary\TemplateManagementSourceInterface
opts into the new capability. This interface extends the existing
TemplateSourceInterface and adds:

- `can_manage(string $template_id, int $user_id): bool` as a **separate**
  per-item write authorization gate; `can_view` never grants write rights.
- `navigation(TemplateMetadata $item, int $user_id): TemplateNavigation`
  returning 0–2 **local wp-admin page navigation links**.

A provider example, inside its existing source implementation:

    public function can_manage(string $id, int $user_id): bool {
        return $this->own_storage_policy_can_edit($id, $user_id);
    }

    public function navigation(TemplateMetadata $item, int $user_id): TemplateNavigation {
        return new TemplateNavigation(
            preview: [
                'url' => admin_url('admin.php?page=extension-preview&template=' . rawurlencode($item->id)),
                'label' => __('Preview template', 'my-extension'),
            ],
            manage: [
                'url' => admin_url('admin.php?page=extension-editor&template=' . rawurlencode($item->id)),
                'label' => __('Manage template', 'my-extension'),
            ]
        );
    }

The labels above belong to the **extension's translations**. Base does not
introduce English-only new strings or a new translation catalog delta.
The provider MUST first authorize the actor and target when constructing
links, and MUST independently recheck identity, record visibility, capabilities
and any CSRF/nonce/mutation boundary at its destination.

Base enforces current-user source browse and per-item view authorization
again, and checks the source/extension/Designer identity. Only when requested
globally, origin is personal/site and can_manage passes does Base project
the manage URL. Built-in and installed-pack defaults are not directly
manageable/overwritable through Base; consumers can offer a separately
authorized **Save as a new personal copy** inside their own Designer.

All links are validated against the current site's exact wp-admin origin
and base path. Relative/external/non-HTTP destinations, unsafe traversal,
credentials/fragments and direct admin-post/admin-ajax/update handler routes
are refused. Links are HTML/DOM escaped or constructed with safe URL APIs.
Provider exceptions fail closed and never expose document payloads.

## Explicit non-goals of T3A

- No Base template CREATE, UPDATE, DELETE, DUPLICATE, EXPORT or
  shared-domain persistence endpoint. These are **consumer-owned**.
- No GET mutation. Manage/Preview links must navigate to a safe owner
  screen, not trigger duplication, deletion, export or secret disclosure.
- No self-service personal-to-site sharing without the extension's explicit
  actor policy and approval.
- No provider-default overwrite, user-owned content overwrite or
  WordPress site-network cross-boundary escalation.
- No Marketplace/Repository/Updates online request.

**Future T3B** delivers Save as template, scoped owner storage, duplicate,
delete and independent instance creation in the **Automations consumer**, and
corresponding provider-specific actions in other Designers. **Offline export
and import are required** for user backups and WordPress-site portability.
T4 defines a shared portable package contract, with per-consumer authorized
export/import; this is separate from the T3A navigation candidate and must be
accepted before the relevant products are called complete. Online distribution
is explicitly not a prerequisite. See
[TEMPLATE-OFFLINE-PORTABILITY-REQUIREMENT.md](TEMPLATE-OFFLINE-PORTABILITY-REQUIREMENT.md).

## Tests and evidence

- PHP source and WordPress contract:
  `tests/integration/TemplateLibraryManagementContractTest.php`.
  Tests personal manage, site denied manage, built-in immutable,
  contextual selection never showing Manage, forged/malicious URL,
  actor isolation and old source compatibility.
- JS source regression:
  `tests/js/template-browser-boundary.test.mjs` checks
  safe same-origin global-only links and lack of write transport.
- Full Base Level 1, protected Level 2 and canonical Level 3
  packaging plus browser/multirole, mobile/keyboard/RTL QA remain required
  before calling this stable or publicly released.

No merge, CI, database mutations, staging install or consumer deployment
without separate operator GO.
