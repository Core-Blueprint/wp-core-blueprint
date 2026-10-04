# Core Blueprint

> **An open-source foundation for responsible WordPress administration.**

Core Blueprint is a WordPress foundation focused on **governance, privacy-conscious design, defensive security, and maintainable site operations**.

It provides a shared baseline for WordPress sites and for the wider Core Blueprint plugin suite, while keeping WordPress itself at the center of the architecture.

Core Blueprint is designed to help site owners, developers, agencies, and operators build and manage WordPress installations with clearer boundaries, better operational visibility, and fewer unnecessary dependencies.

---

## Our mission

WordPress is powerful because it is open, extensible, and gives site owners control over their own platform.

Core Blueprint builds on those strengths.

The project aims to provide a practical foundation for WordPress environments where **ownership, transparency, governance, security, privacy, and long-term maintainability** matter.

That means:

- preferring open and inspectable systems over unnecessary black boxes;
- using WordPress-native concepts and APIs wherever they are a good fit;
- making administrative actions and consequences understandable;
- keeping integrations optional and isolated;
- avoiding unnecessary vendor lock-in;
- designing for extensibility without turning the foundation into a monolith.

Core Blueprint does **not** promise perfect security, perfect privacy, or a flawless website. Instead, it aims to provide sensible defensive controls, clearer governance, and a stronger operational baseline.

---

## What Core Blueprint is

Core Blueprint Base is the shared foundation plugin for the Core Blueprint suite.

It provides shared infrastructure used by Base itself and by optional Core Blueprint extensions, including:

- security-related baseline controls;
- audit logging;
- failsafe lockout prevention;
- access and maintenance modes;
- shared governance;
- permissions and role policy;
- admin UI foundations;
- site-wide locale preferences;
- media and package-management utilities;
- governed content-model infrastructure;
- public extension and UI contracts.

Functionality that does not belong in the shared foundation is kept outside Base and can be provided by separate extensions.

Those extensions are distributed independently and may use different licensing or availability models. Their existence does not make them part of the open-source Base package.

---

## Core principles

### Governance first

Administrative actions should have clear ownership, explicit permissions, understandable consequences, and useful auditability.

Core Blueprint treats governance as a first-class part of site administration rather than an afterthought.

### Privacy-conscious design

Core Blueprint aims to minimize unnecessary dependencies and external data flows.

Privacy is approached as an architectural consideration: what data is needed, where it lives, who can access it, and whether an external service is actually necessary.

### Defensive security

Core Blueprint provides defensive controls intended to reduce avoidable risk and improve visibility.

Security-sensitive workflows favor explicit permissions, auditable actions, safe defaults, and recovery paths.

No software can guarantee that a WordPress site will never be compromised. Core Blueprint should therefore be understood as a defensive layer, not a security guarantee.

### WordPress-native where practical

Core Blueprint prefers WordPress-native data structures, permissions, hooks, REST conventions, roles, capabilities, metadata, and administrative patterns where they can accurately represent the required behavior.

The goal is to extend WordPress rather than replace it with a closed parallel platform.

### Builder-agnostic by design

Core Blueprint is designed to work independently of any specific page builder.

Core data models and public contracts should remain usable without requiring a particular builder, and builder-specific functionality belongs behind isolated adapters.

Core Blueprint currently includes an adapter for **Bricks Builder** where builder-specific integration adds value, including within Content Models.

Bricks is **not required** to use Core Blueprint.

The adapter architecture is intentionally open to additional builders and integrations in the future, provided they remain optional and do not compromise the canonical WordPress/Core Blueprint data model.

### Open extensibility

Base provides shared contracts and foundations that optional extensions can consume without duplicating infrastructure.

Extensions should remain independently understandable and should only add the runtime they actually need.

---

## Optional modules

Some Core Blueprint capabilities are provided as optional modules and can be enabled or disabled independently from the Core Blueprint administration interface.

This allows site owners to keep the shared Core Blueprint foundation active without enabling functionality they do not need. If another plugin already manages the same responsibility, the corresponding Core Blueprint module can remain disabled rather than having two systems manage the same area.

Because WordPress plugins can interact in many different ways, this modular design should **not** be interpreted as a guarantee of compatibility with every third-party plugin, theme, hosting environment, or configuration.

---

## Included capabilities

### Security baseline

Core Blueprint includes shared security-oriented infrastructure such as:

- a security module registry;
- defensive access controls;
- failsafe lockout prevention;
- governed permission boundaries;
- audit logging;
- security-related status and administration surfaces.

These controls are intended to improve defensive posture and operational clarity without presenting Core Blueprint as a complete replacement for every specialized security product or operational process.

### Audit logging

Core Blueprint maintains a durable audit trail for relevant administrative and security-sensitive events.

The audit system supports retention management and export and provides a shared governance boundary that Core Blueprint extensions can use.

### Failsafe lockout prevention

Access restrictions should never make site recovery impossible.

Core Blueprint includes layered failsafe behavior intended to reduce the risk of administrators accidentally locking themselves out through restrictive site modes or related configuration.

### Access modes

Core Blueprint supports governed site-access states such as:

- Public
- Coming Soon
- Maintenance
- Admin-Only

These are treated as explicit operational states rather than as a generic on/off switch.

### Permissions and user roles

Core Blueprint builds on WordPress roles and capabilities while adding suite-wide policy, governance, and audit behavior.

The goal is to keep authorization understandable and compatible with WordPress instead of introducing a completely separate identity system.

### Media Replace

Media Replace allows an existing Media Library file to be replaced while preserving the attachment identity and regenerating WordPress media metadata where appropriate.

Relevant replacements are recorded through the audit layer.

### Routing & URLs

Routing & URLs provides opt-in governance for public category archive routes while keeping WordPress rewrite/query behavior canonical.

Clean Archive URLs can remove the category base from category archives and use compact pagination such as `/blog/p2/`. Activation requires a collision preflight, existing WordPress category routes can redirect to the clean canonical routes, and the policy remains reversible.

If a later content or routing change introduces a known collision, Core Blueprint suspends its clean archive rewrites and fails open to WordPress default category routing until the conflict is resolved.

### Media Formats

*Optional module — can be enabled or disabled independently.*

If another plugin already manages media-format handling for the site, Media Formats can remain disabled.

The Media Formats module provides governed upload/output policy for supported image formats and server capabilities, including:

- SVG;
- WebP;
- AVIF;
- experimental JPEG XL support where available;
- HEIC/HEIF import where supported;
- generated-image format control.

Support remains dependent on the capabilities exposed by WordPress and the hosting environment.

### Package Downloads

Core Blueprint can package installed plugins and themes into installable ZIP archives from WordPress administration without modifying the original package source directories.

### Content Models

*Optional module — can be enabled or disabled independently.*

If another plugin already manages structured content models or field registration for the site, Content Models can remain disabled.

Content Models provides a governed, WordPress-native schema layer for structured site data.

Its scope includes concepts such as:

- post types;
- taxonomies;
- Option Pages;
- post, term, and user fields;
- Relations;
- Group and Repeater structures;
- Conditional Logic;
- JSON schema transfer and portability;
- a public plugin API;
- isolated builder adapters.

The canonical model remains independent of any single visual builder.

The current implementation includes an isolated **Bricks Builder adapter** while preserving canonical JSON/WordPress portability.

---

## UI Foundation

Core Blueprint exposes a shared UI Foundation without forcing one visual skin onto every WordPress admin screen.

### Core Blueprint admin surfaces

Pages that live under the Core Blueprint administration area use the shared Core Admin Theme, design tokens, components, semantic states, Lucide icons, and dark/light presentation.

Core Admin follows WordPress interaction and layout conventions wherever WordPress already provides a strong pattern.

Custom interfaces are used when the workflow genuinely benefits from them rather than simply to make WordPress look different.

### Standalone extension surfaces

Standalone Core Blueprint plugins and operational screens should remain visually close to native WordPress administration where appropriate.

They can consume shared accessibility helpers, semantics, icons, and behavior without being forced to inherit the full Core Admin visual skin.

### Shared components

Base owns shared UI contracts for recurring patterns such as:

- buttons;
- notices;
- state badges;
- status presentation;
- disclosures;
- interactive rows;
- form fields;
- toolbars;
- modals;
- loading/busy states;
- feedback states;
- icons.

This helps extensions share behavior and semantics without each plugin shipping a competing mini design system.

### Lucide icons

Lucide is the canonical icon source for the Core Blueprint suite.

Base owns a curated icon registry so extensions can use shared semantic icons without bundling separate Lucide copies when Base is available.

---

## Builder integrations

Core Blueprint deliberately separates **content and system architecture** from **builder-specific presentation**.

A builder adapter may expose Core Blueprint functionality inside a builder, but it should not become the canonical storage or runtime model.

### Bricks Builder

Bricks Builder is currently the first builder for which Core Blueprint includes an isolated adapter where that integration is useful.

This reflects real-world use of Bricks within Core Blueprint development, while keeping the project itself builder-independent.

### Other builders

Core Blueprint is open to additional builder adapters in the future.

A new adapter should:

- remain optional;
- be isolated from the canonical data model;
- avoid making the builder a dependency of Base;
- preserve WordPress-native access to the underlying content or configuration;
- follow the same public contracts used by other Core Blueprint integrations.

---

## Base and extensions

Core Blueprint uses a foundation-and-extensions model.

**Core Blueprint Base is free and open source.**

Base owns the shared infrastructure, governance boundaries, UI foundations, and public contracts that other Core Blueprint software can build on.

Additional Core Blueprint extensions are developed and distributed separately. They are not automatically included with Base, and their licensing or availability may differ from the Base plugin.

This separation keeps the open-source foundation independently useful while allowing more specialized functionality to remain outside the core package.

An inactive extension should not be required for Base to function.

---

## A European perspective on WordPress infrastructure

Core Blueprint is being developed from Europe with a strong appreciation for **digital autonomy, privacy, transparency, portability, and open systems**.

Those values influence architectural decisions, but Core Blueprint is not intended only for European users.

The project aims to remain useful to the wider WordPress community while demonstrating that modern WordPress infrastructure can be built around ownership and interoperability rather than mandatory dependence on external platforms.

---

## Open source and community

Core Blueprint is intended to grow as an open-source project.

Good code alone is not enough for a healthy open-source ecosystem, so documentation and understandable extension boundaries are important parts of the project.

The long-term direction is to make it easier for developers and contributors to:

- understand how Base is structured;
- build against stable public contracts;
- create integrations without modifying Core Blueprint internals;
- propose support for additional builders and tools;
- improve documentation;
- report bugs and security concerns responsibly;
- contribute improvements without introducing unnecessary lock-in.

The project continues to evolve after its initial stable release, so APIs and architecture should only be presented as stable once they have passed the relevant release gates.

---

## For developers

Core Blueprint exposes documented public contracts so extensions can build on Base without depending on internal implementation details.

- [**Extension Starter**](https://github.com/Core-Blueprint/wp-core-blueprint-starter-plugin) — a minimal production-grade reference implementation for building a Core Blueprint extension.
- [**Developer Documentation**](docs/PUBLIC-API.md) — the canonical entry point for supported public API contracts and extension boundaries.
- [**Core Admin Design Foundation**](docs/CORE-ADMIN-DESIGN-FOUNDATION.md) — shared admin UI contracts, semantics, and component guidance.

Only contracts documented as public API should be treated as stable extension boundaries.

---

## Open-source ecosystem

Core Blueprint Base is the main open-source foundation, but it does not have to be the only open-source project built around these principles.

Current public projects include:

- [**Core Blueprint Base**](https://github.com/Core-Blueprint/wp-core-blueprint) — the free and open-source WordPress foundation described in this README.
- [**Core Blueprint Content Migrator**](https://github.com/Core-Blueprint/wp-core-blueprint-content-migrator) — a free and open-source, safety-first utility for migrating registered post types and taxonomies on the same WordPress site. It can run standalone and optionally integrates with Core Blueprint governance when Base is available.

This list may grow over time as additional projects are released as open source.

Other Core Blueprint software may be developed and distributed separately under different licensing or availability models. Being part of the wider Core Blueprint ecosystem does not automatically mean that a project is included with Base or released as open source.

---

## Requirements

Current Base requirements:

- **WordPress 7.0 or newer**
- **PHP 8.4 or newer**
- PHP 8.5 recommended
- **PHP Sodium / libsodium**

If required runtime capabilities are missing, Core Blueprint should refuse activation with a clear WordPress-admin error rather than failing later at runtime.

---

## Architecture

### Foundation layer

The `CoreBlueprint\Core\*` foundation layer is always active and contains the baseline infrastructure shared across Core Blueprint.

Key boundaries include:

- extension registration and lifecycle;
- security modules;
- audit and governance;
- access modes;
- roles and capabilities;
- shared admin chrome;
- UI Foundation;
- locale handling;
- media utilities;
- package utilities;
- Content Models;
- public helper and registry contracts.

### Optional runtime belongs outside Base

Feature-specific or remote-management runtime should live in the relevant extension instead of gradually turning Base into a monolith.

Base exposes the generic contracts those extensions need.

---

## Uninstalling

Deleting Core Blueprint through WordPress runs the Base uninstall process.

Base removes the configuration, scheduled events, capabilities, roles, user metadata, and database structures that it owns where removal is safe and intended.

User-authored content values written through Content Models are preserved for portability. Quarantine evidence remains available for recovery and investigation instead of being silently destroyed.

Data owned by separate extensions remains the responsibility of those extensions.

---

## Development

- Source classes live under `src/`.
- Classes are PSR-4-style autoloaded by the plugin bootstrap.
- Base does not require Composer at runtime.
- There is no mandatory frontend build step for the plugin runtime.
- Text domain: `core-blueprint`.
- Translations live in `languages/`.

### Production invariants

Some identifiers form part of the operational contract and must be changed with care, including:

- plugin folder name: `core-blueprint`;
- parent menu slug: `core-blueprint`;
- REST namespace: `core-blueprint/v1`;
- persistent database identifiers;
- public error/status identifiers consumed by other Core Blueprint components.

See `CHANGELOG.md` for version history.

---

## Project status

Core Blueprint is currently in **stable v1 release and ongoing release-quality review**.

The project is actively being hardened around:

- Base/extension contracts;
- governance and permissions;
- security boundaries;
- lifecycle behavior;
- UI Foundation consistency;
- internationalization;
- packaging;
- runtime validation;
- extension interoperability.

Documentation should distinguish between implemented behavior, stable public contracts, release candidates, and future direction.

---

## Documentation

Clear documentation is part of the Core Blueprint project goal, not an optional extra.

Planned and evolving documentation should cover:

- installation and requirements;
- administrator workflows;
- security and governance concepts;
- Base public APIs;
- extension development;
- Content Models;
- builder adapters;
- UI Foundation usage;
- upgrade and release guidance.

---

## Contributing

Core Blueprint welcomes contributions that align with the project's architectural principles.

In particular, contributions should aim to preserve:

- WordPress-native interoperability;
- builder independence;
- clear permission boundaries;
- privacy-conscious design;
- defensive security;
- explicit lifecycle behavior;
- accessible administrative UX;
- maintainable public contracts;
- portability of user-owned data.

Builder integrations are welcome when they are implemented as optional adapters rather than dependencies.

More detailed contribution and development guidelines will continue to evolve alongside the project.

---

## Responsible security reporting

Security issues should not be disclosed through a public issue when doing so would expose users before a fix is available.

The current private-reporting and coordinated-disclosure process is documented in [SECURITY.md](https://github.com/Core-Blueprint/wp-core-blueprint/blob/main/SECURITY.md).

---

## Philosophy in one sentence

**Own your WordPress stack, understand its behavior, and keep the freedom to change what sits around it.**
