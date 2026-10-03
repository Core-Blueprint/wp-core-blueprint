# User Profile Surface Foundation

Status: **public v1 UI Foundation contract**.

The User Profile Surface Foundation is the canonical Core Blueprint contribution boundary for WordPress' native **Profile** and **Edit User** screens.

Its purpose is deliberately narrow:

- Base owns section registration, deterministic ordering and context dispatch.
- Base loads the existing WP-native Form Composition adapter when registered sections are present.
- WordPress continues to own the surrounding profile form, controls, colours, focus behavior and admin chrome.
- Consumers own product/domain meaning, authorization, fields, actions, validation and persistence.

The Foundation never turns `profile.php` or `user-edit.php` into a Core Admin surface.

## Registration lifecycle

Register sections only during:

```php
cb_core_register_user_profile_sections
```

Use the public registry:

```php
use CoreBlueprint\Core\Admin\UserProfileSectionRegistry;

add_action( 'cb_core_register_user_profile_sections', static function (): void {
    UserProfileSectionRegistry::register(
        'vendor-profile-preferences',
        [
            'title'    => __( 'Profile preferences', 'vendor-plugin' ),
            'order'    => 300,
            'contexts' => [
                UserProfileSectionRegistry::CONTEXT_SELF,
                UserProfileSectionRegistry::CONTEXT_EDIT,
            ],
            'visible'  => static function ( WP_User $user, string $context ): bool {
                return current_user_can( 'edit_user', $user->ID );
            },
            'renderer' => [ Vendor\Admin\UserProfile::class, 'render' ],
        ]
    );
} );
```

Section IDs use lower-case namespaced kebab-case. Duplicate or malformed registrations fail closed.

## Definition contract

Supported keys are exactly:

- `title` — required caller-localized section heading.
- `order` — optional integer from `-1000` through `1000`; default `100`; lower values render first.
- `contexts` — required non-empty array containing `self`, `edit`, or both.
- `visible` — optional read-only callable receiving `(WP_User $user, string $context)` and returning a boolean.
- `renderer` — required callable receiving `(WP_User $user, string $context)` and rendering the consumer-owned section body.

Base renders the section `<h2>`. The consumer renders the body below that heading.

A visibility callback is presentation gating, not a replacement for mutation authorization. Server-side save/action handlers remain authoritative.

## Presentation boundary

These screens are WordPress-native.

Base automatically loads the WP-native Form Composition adapter for a profile context that has registered sections. Consumers may therefore use the public Field + Stack markup contract without loading Core Admin tokens or private stylesheet handles.

Canonical ownership is:

```text
WordPress                 -> profile page and native controls
User Profile Surface      -> section registration, order and context
Form Composition          -> field grouping and vertical rhythm
consumer                  -> product semantics, authorization and persistence
```

Do not:

- add `.cb-core-wrap` around WordPress Profile/Edit User content;
- load Core Admin theme/tokens merely to style a profile contribution;
- copy Base component CSS into an extension;
- add local margin chains when Field/Stack express the composition.

## Form ownership and independent actions

WordPress fires `show_user_profile` / `edit_user_profile` **inside its existing profile form**.

A registered renderer MUST NOT output another `<form>` element inside that surface.

Normal profile fields should participate in WordPress' existing profile form and use the normal validation/save hooks.

A feature that requires an independent action endpoint must use standards-compliant form association or another non-nested mechanism. Base's current detached action-form implementation used by Base 2FA is internal and is not a public v1 API.

The absence of a public detached-action helper is deliberate. Promote that mechanism only after a second proven consumer needs the same generic contract.

## Ordering

Base sorts sections by:

1. ascending `order`;
2. section ID as the deterministic tie-breaker.

Consumers must not depend on plugin load order.

## Contexts

`CONTEXT_SELF` means the logged-in user is viewing their own WordPress Profile screen.

`CONTEXT_EDIT` means the operator is viewing WordPress Edit User for another user.

Supporting one context does not imply support for the other. Consumers must declare this explicitly.

## Security

The registry is a presentation/integration boundary, not an authorization service.

Consumers remain responsible for:

- capability and object-level authorization;
- nonces on mutations;
- validation and sanitization;
- safe output escaping;
- preventing unauthorized cross-user changes;
- keeping secrets out of profile HTML when they are not required for the current flow.

## Public/private boundary

Public:

- `CoreBlueprint\Core\Admin\UserProfileSectionRegistry`
- `cb_core_register_user_profile_sections`
- `CONTEXT_SELF`
- `CONTEXT_EDIT`
- the definition contract documented above

Private:

- asset handles and filenames;
- Base 2FA detached action-form implementation;
- Base-internal section IDs and rendering internals beyond documented markup ownership.

Consumers must feature-detect the required public class when their runtime depends on this Foundation.
