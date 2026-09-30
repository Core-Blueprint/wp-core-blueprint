=== Core Blueprint ===
Tags: governance, security, audit-log, privacy, administration
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Core Blueprint provides governance, security, audit logging, permissions, administration tools, and shared foundations for WordPress.

== Description ==

Core Blueprint is an open-source foundation for responsible WordPress administration.

It provides shared governance, defensive security controls, audit logging, access and maintenance modes, role and capability policy, administration foundations, media utilities, structured content infrastructure and public contracts for optional Core Blueprint extensions.

Core Blueprint is designed around WordPress-native concepts and APIs. The canonical data and authorization model remains in WordPress, builder integrations are optional, and Base can be used without a specific page builder.

Core Blueprint does not claim to provide perfect security or privacy. It is a defensive and governance-oriented foundation intended to improve operational clarity, recovery paths and administrative control.

Core functionality does not require a Core Blueprint account or an external Core Blueprint service.

= Optional modules =

Base includes optional modules that can be enabled or disabled independently. This lets site owners avoid overlapping responsibility when another plugin already manages the same area.

Examples include Media Formats, Content Models, Mail Delivery and related administration tooling.

= Mail Delivery =

Mail Delivery is optional. Sites may continue using the normal WordPress mail path or configure a supported transport.

Generic SMTP uses the SMTP server configured by the site administrator.

Brevo uses Brevo's transactional email API only after a site administrator enables Mail Delivery, selects Brevo and provides a Brevo API key. See "External services" below.

= Privacy =

Core Blueprint does not include usage telemetry or advertising tracking.

Some diagnostic tools can make server-side requests back to the site's own public URL to verify site behavior, such as response headers or a configured custom login route. Those requests target the same WordPress site.

If the optional Brevo transport is configured, message data is sent to Brevo as described below.

= Source code =

The maintained source repository and build tooling are available at:

https://github.com/Core-Blueprint/wp-core-blueprint

Bundled third-party components and their license/provenance information are documented in the plugin's `licenses/` directory and alongside the relevant vendored source.

== External services ==

= Brevo =

Core Blueprint can optionally send WordPress email through Brevo's transactional email API.

Brevo is contacted only when all of the following are true:

1. Core Blueprint Mail Delivery is enabled.
2. Brevo is selected as the active mail transport.
3. A Brevo API key has been configured.
4. WordPress sends an email or the administrator deliberately sends a mail test.

When Brevo is used, Core Blueprint sends the data needed to deliver that email. Depending on the message, this can include sender and recipient email addresses and names, subject, message body, CC/BCC and reply-to addresses, attachments, and the Brevo API key used to authenticate the request.

Service: https://www.brevo.com/
Terms of Service: https://www.brevo.com/legal/termsofuse/
Privacy Policy: https://www.brevo.com/legal/privacypolicy/

Brevo is an independent third-party service. Use of Brevo is subject to Brevo's own terms and privacy policy.

== Installation ==

1. Upload the `core-blueprint` plugin directory to `/wp-content/plugins/`, or install the ZIP through Plugins > Add New > Upload Plugin.
2. Activate Core Blueprint through the WordPress Plugins screen.
3. Open Core Blueprint in WordPress Admin.
4. Review Core Setup and enable only the modules and policies appropriate for the site.

Core Blueprint requires WordPress 7.0 or later and PHP 8.4 or later.

== Frequently Asked Questions ==

= Does Core Blueprint require an account? =

No. Base can be installed and used without a Core Blueprint account.

= Does Core Blueprint send telemetry? =

No. Base does not include usage telemetry or advertising tracking.

= Does Core Blueprint contact external services? =

Only when a feature requires it. The optional Brevo mail transport contacts Brevo after the administrator deliberately configures that transport. Core diagnostic self-checks may request the site's own public URL.

= Does Core Blueprint replace a dedicated security product? =

Not necessarily. Core Blueprint provides defensive controls and governance infrastructure. Its modular design allows overlapping modules to remain disabled when another product owns the same responsibility.

= Does Core Blueprint require a page builder? =

No. Base is builder-agnostic. Builder-specific integrations are optional adapters and are not the canonical data model.

= Where is the source code? =

The public source repository is https://github.com/Core-Blueprint/wp-core-blueprint .

== Changelog ==

= 1.0.0 =

* Stable v1 release.
* Completed the Base v1 Golden Audit and release hardening.
* Added WordPress.org submission metadata and automated Plugin Check coverage.
* Documented the optional Brevo external service boundary.
