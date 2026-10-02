=== Core Blueprint ===
Contributors: coreblueprint
Tags: security, audit-log, permissions, user-roles, admin-tools
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0-rc1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular governance and operations foundation for WordPress with security controls, audit logging, permissions and administration tools.

== Description ==

Core Blueprint is an open-source governance and operations foundation for WordPress. It brings defensive security controls, operational evidence, permissions and administration tools together in one modular plugin.

Use only the parts that belong in the site's workflow. Optional modules can remain disabled when WordPress itself or another plugin already owns that responsibility.

= Control =

Review safeguards, privileged access, roles and capabilities, environment-aware policies, access modes, recovery paths, and administration rules from one consistent governance layer.

= Evidence =

Audit logging, operational logs, reports, integrity checks, and review state help make important site changes and operational decisions visible without claiming perfect security.

= Administration =

Optional CMS and administration tools cover areas such as content models, media handling, mail, managed snippets, admin navigation, columns, notices, and package downloads.

Core Blueprint is designed around WordPress-native concepts and APIs. WordPress remains the canonical data and authorization layer. Builder integrations are optional, and Base can be used without a specific page builder.

Core functionality does not require a Core Blueprint account or an external Core Blueprint service.

= Optional modules =

Base includes optional modules that can be enabled or disabled independently. This allows site owners to avoid overlapping responsibility when another plugin already manages the same area.

Examples include Media Formats, Content Models, Mail Delivery, and related administration tooling.

= Managed Snippets =

Managed Snippets is an optional module and is disabled by default. It allows authorized Core Blueprint operators to manage PHP, JavaScript, CSS, and HTML snippets when that workflow is appropriate for the site.

Executable-code changes require explicit privileged authority and remain subject to WordPress file-modification policy. Imported snippets are disabled by default, PHP is validated before storage, managed code is integrity-checked before runtime, and runtime failures can automatically disable the affected snippet. Core Blueprint also provides a server-side emergency stop for the Snippets runtime.

= Mail Delivery =

Mail Delivery is optional. Sites may continue using the normal WordPress mail path or configure a supported transport.

Generic SMTP uses the SMTP server configured by the site administrator.

Brevo uses Brevo's transactional email API only after a site administrator enables Mail Delivery, selects Brevo, and provides a Brevo API key. See "External services" below.

= Privacy =

Core Blueprint does not include usage telemetry or advertising tracking.

Some diagnostic tools can make server-side requests back to the site's own public URL to verify site behavior, such as response headers or a configured custom login route. Those requests target the same WordPress site.

If the optional Brevo transport is configured, message data is sent to Brevo as described below.

When Core Scanner is run, Core Blueprint may ask WordPress.org for official checksum manifests for WordPress Core, plugins, or themes so local files can be compared with their published versions. No Core Blueprint account is involved. See "External services" below.

= Source code =

The maintained source repository and build tooling are available at:

https://github.com/Core-Blueprint/wp-core-blueprint

Bundled third-party components and their license or provenance information are documented in the plugin's `licenses/` directory and alongside the relevant vendored source.

== External services ==

= Brevo =

Core Blueprint can optionally send WordPress email through Brevo's transactional email API.

Brevo is contacted only when all of the following are true:

1. Core Blueprint Mail Delivery is enabled.
2. Brevo is selected as the active mail transport.
3. A Brevo API key has been configured.
4. WordPress sends an email or the administrator deliberately sends a mail test.

When Brevo is used, Core Blueprint sends the data needed to deliver that email. Depending on the message, this can include sender and recipient email addresses and names, subject, message body, CC/BCC and reply-to addresses, attachments, and the API key used to authenticate the request.

Service: https://www.brevo.com/
Terms of Service: https://www.brevo.com/legal/termsofuse/
Privacy Policy: https://www.brevo.com/legal/privacypolicy/

Brevo is an independent third-party service. Use of Brevo is subject to Brevo's own terms and privacy policy.

= WordPress.org checksum services =

Core Scanner can request official checksum manifests from WordPress.org when an administrator runs integrity scans for WordPress Core, plugins, or themes.

The request identifies the software component, version, and where applicable the WordPress distribution locale needed to resolve the matching checksum manifest. Core Blueprint uses the returned manifest only to compare local files with the official published checksums.

Service: https://wordpress.org/
Privacy Policy: https://wordpress.org/about/privacy/

These requests occur as part of an administrator-initiated or configured Core Scanner integrity scan.

== Installation ==

1. Upload the `core-blueprint` plugin directory to `/wp-content/plugins/`, or install the ZIP through Plugins > Add New > Upload Plugin.
2. Activate Core Blueprint through the WordPress Plugins screen.
3. Open Core Blueprint in WordPress Admin.
4. Review Core Setup and enable only the modules and policies appropriate for the site.

Core Blueprint requires WordPress 7.0 or later and PHP 8.4 or later.

== Frequently Asked Questions ==

= Does Core Blueprint require other Core Blueprint plugins? =

No. Base is a standalone WordPress plugin. Optional Core Blueprint extensions can use its shared public contracts, but they are not required for Base to function.

= Does Core Blueprint require an account? =

No. Base can be installed and used without a Core Blueprint account.

= What is the CB Operator role? =

When a genuine first activation is performed by an authenticated WordPress user, that account is assigned the CB Operator role. This establishes the initial trusted operator who can manage Core Blueprint governance and privileged settings. Activations without an authenticated WordPress user do not mint an operator automatically. Operator access can be reviewed or changed later under Core Blueprint > Preferences > Permissions.

= Does Core Blueprint send telemetry? =

No. Base does not include usage telemetry or advertising tracking.

= Does Core Blueprint contact external services? =

Only when a feature requires it. The optional Brevo mail transport contacts Brevo after the administrator deliberately configures that transport. Core Scanner may request official checksum manifests from WordPress.org when integrity scans are run or scheduled. Core diagnostic self-checks may also request the site's own public URL. See "External services" above for details.

= Does Core Blueprint replace a dedicated security product? =

Not necessarily. Core Blueprint provides defensive controls and governance infrastructure. Its modular design allows overlapping modules to remain disabled when another product owns the same responsibility.

= Does Core Blueprint require a page builder? =

No. Base is builder-agnostic. Builder-specific integrations are optional adapters and are not the canonical data model.

= What happens when Core Blueprint is deleted? =

Base removes the configuration, scheduled events, roles and capabilities, user metadata, and database tables that it owns. User-authored site content written through Content Models is preserved. Managed Snippets source files are also preserved while generated Snippets runtime state is neutralized. Quarantine evidence is retained for recovery and investigation rather than silently destroyed.

= Where is the source code? =

The public source repository is https://github.com/Core-Blueprint/wp-core-blueprint .

== Screenshots ==

1. Dashboard brings safeguards, operations, CMS tools, and extensions into one modular overview.
2. Core Setup guides site review across seven sections without changing configuration automatically.
3. Safeguards brings access, hardening, scanner, and recovery controls into one governed workspace.
4. Audit and Logs provide operational evidence for security, maintenance, login, and settings activity.
5. Permissions separates WordPress Administrator access from trusted Core Blueprint Operator authority.

== Changelog ==

= 1.0.0-rc1 =

* Release-candidate line for the Core Blueprint Base v1 quality and compatibility baseline.
* WordPress.org submission metadata and automated readiness contracts are maintained before the stable v1 release.
