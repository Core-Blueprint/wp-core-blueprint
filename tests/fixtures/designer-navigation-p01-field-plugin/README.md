# Core Blueprint Navigation P01 | WordPress field fixture

**Development-only acceptance plugin. Not a product extension. Do not publish or deploy to production.**

Base source authority: Core-Blueprint/wp-core-blueprint at commit `7f9469243397ded78563b86be1475fb0bf261a29`, unmerged Designer Navigation P01.

Verified Base local development package: `core-blueprint-1.0.0.zip`, SHA-256 `8e31ea6d0eff35fe2bb9717e39367811462d60a4d9a861aa5fdba258010bfe05`. **The version 1.0.0 by itself is not proof that the P01 Base code is installed.** Confirm the exact candidate artifact on a separately approved isolated nonproduction WordPress site before field testing.

## Scope and safeguards

- Adds **Tools > Navigation P01 Fixture** for users with `manage_options` only in WordPress environments declared `local`, `development` or `staging`. WordPress's default `production` environment renders no Tools page/assets.
- Public Base PHP boundary: `CoreBlueprint\Core\Design\Editor\Assets::enqueue_designer_mode()`. Public JS module: `@cb-core/design-editor` and `createDesignerNavigation()`/`createDesignerShell()`. No private Base file imports or duplicate designer chrome.
- No domain persistence, REST/AJAX endpoints, network requests, cron, filesystem writes or front-end handlers.
- Does not create a WordPress installation, web server or safe target site. In particular, the P01 **PHPUnit** fixture with a schema and WordPress files does not automatically become a browser-accessible site.
- Installing or activating this test plugin and the P01 Base ZIP on any existing website requires separate site-specific operator authorization; **do not silently use production or existing staging**.
- Do not commit these files into Base runtime or the customer ZIP. They are under `tests/fixtures` on a **separate test-only feature branch**. Never merge this fixture into the source P01 feature without a specific review.

## Manual field acceptance

1. Confirm an explicitly approved isolated nonproduction WordPress target running the **exact P01 Base artifact** and a suitable privileged account; record WP version, PHP version, environment and artifact SHA-256. Install this standalone fixture plugin under `wp-content/plugins/cb-navigation-p01-field-fixture/` and activate it manually.
2. Visit **Tools > Navigation P01 Fixture**, choose **Navigation only**, click **Design with Core Blueprint**. On desktop width >1280 px expect palette, canvas and sidebar as persistent three rails. No extra tabbar or Dynamic Data placeholder.
3. With only keyboard/assistive tech, navigate the labelled `Workflow stages` landmark: three ordered list links; only `When` initially has `aria-current=location`. `Only if` / `Then` must scroll and focus their in-shell targets; active state must follow. Check status badges are legible and labels are text.
4. Choose **Navigation + Elements** and launch again. Confirm Base-normalized canonical tab order **Workflow / Elements**, ArrowLeft/ArrowRight/Home/End behavior, correct `aria-selected` state and panel visibility. Sample palette buttons display feedback but never save/insert.
5. At viewport width <=1280 px, open the left drawer from Base toolbar; check focus entry, Escape/backdrop closing and focus return, `aria-hidden`/`inert` closed state and left/right exclusivity. Switch back to desktop and confirm persistent three rails.
6. Repeat at 200% and 400% zoom, light/dark browser/admin themes where supported, reduced-motion preference and a screen reader. Verify no clipped controls, hidden keyboard targets, unintended external focus, console import failures or unexpected network requests.
7. Check existing Mail / Reports / Commerce designers **only if available** on the same explicitly authorized nonproduction installation. If absent, record **NOT TESTED** rather than PASS.
8. Record evidence (browser/version, screenshots, viewport, pass/fail, relevant accessible tree, known gaps) in the Overseer P01 audit. Installation and PHP/JS lint are not field acceptance.

## Structural details

The plugin renders both test modes on a native, read-only WordPress admin page. JS uses public Base Navigation and Shell APIs; Base Mode owns launch, full screen, responsive drawer and toolbar controls. No product data is edited.

These files are kept separate from the frozen P01 Base ZIP. The fixture must never be used as evidence of production readiness until it has been installed, exercised and reviewed on a specifically approved test site.
