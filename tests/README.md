# Core Blueprint Base test suite

This directory contains the pre-v1 automated regression foundation for Core Blueprint Base.

## Scope

The primary suite uses the real WordPress PHPUnit integration environment. It intentionally avoids mocking the WordPress lifecycle for bootstrap, registry, schema and permission-adjacent contracts.

BASE-V1-A1 covers foundational smoke and contract checks.

BASE-V1-A2 adds request-boundary lifecycle/data scenarios. Any transition that represents a new production WordPress request runs in a separate PHP process while one isolated WordPress table prefix preserves the database state between those processes. This keeps persistent WordPress state realistic without introducing production reset APIs for request-local statics.

BASE-V1-A3 closes the two outer regression boundaries:

- a pinned canonical Extension Starter must consume Base's documented public v1 contracts through the normal WordPress/Base lifecycle;
- WordPress must be able to delete Base through the real `delete_plugins()` flow while Base-owned state is removed and unrelated/site-owned state survives.

A3 is representative contract and ownership smoke coverage. The later BASE-V1-D ownership audit remains responsible for the exhaustive inventory of every Base-owned option, table, transient, metadata key, file and cron hook.

## Pinned matrix

CI runs the primary suite, A2 request-boundary scenario and A3 pinned Starter consumer smoke on:

- WordPress 7.0 + PHP 8.4
- WordPress 7.0 + PHP 8.5
- WordPress 7.1 + PHP 8.4
- WordPress 7.1 + PHP 8.5

The destructive A3 uninstall smoke runs in a separate disposable WordPress 7.0 + PHP 8.4 job/database.

The WordPress runtime is downloaded at the exact requested release. The matching `wp-phpunit` library is pinned to `7.0.0` or `7.1.0`.

The canonical Extension Starter fixture is pinned to immutable Git commit:

`55b7a6fc92f7156530c22adc3745404b6024468f`

PHPUnit and PHPUnit Polyfills are locked through `composer.lock`.

## Local setup

Create a disposable MariaDB database. The PHPUnit suite and all request-boundary/destructive scenarios create and remove tables, so never point any test layer at a real site database.

Export the environment used by the installer, for example:

```bash
export WP_CORE_DIR=/tmp/core-blueprint-wp
export WP_TESTS_DIR=/tmp/core-blueprint-wp-tests
export CB_PLUGIN_FILE=/tmp/core-blueprint-wp/wp-content/plugins/core-blueprint/core-blueprint.php
export WP_DB_NAME=wordpress_test
export WP_DB_USER=root
export WP_DB_PASSWORD=root
export WP_DB_HOST=127.0.0.1:3306
export CB_LIFECYCLE_TABLE_PREFIX=cblifecycle_
export CB_CONSUMER_TABLE_PREFIX=cbconsumer_
export CB_UNINSTALL_TABLE_PREFIX=cbuninstall_
```

Then run:

```bash
composer install
bash tests/bin/install-wp-tests.sh 7.1
vendor/bin/phpunit
bash tests/bin/run-lifecycle-scenario.sh
bash tests/bin/install-pinned-starter.sh
php "$WP_CORE_DIR/wp-content/plugins/core-blueprint-starter-plugin/tools/conformance.php"
bash tests/bin/run-consumer-scenario.sh
```

Use `7.0` instead of `7.1` to exercise the minimum WordPress release.

The uninstall scenario deletes the copied `core-blueprint/` plugin directory by design. Run it against a separately prepared disposable WordPress runtime, mirroring the dedicated CI job:

```bash
export WP_CORE_DIR=/tmp/core-blueprint-uninstall-wp
export WP_TESTS_DIR=/tmp/core-blueprint-uninstall-wp-tests
export CB_PLUGIN_FILE=/tmp/core-blueprint-uninstall-wp/wp-content/plugins/core-blueprint/core-blueprint.php
bash tests/bin/install-wp-tests.sh 7.0
bash tests/bin/run-uninstall-scenario.sh
```

## A2 request-boundary lifecycle/data scenario

`tests/bin/run-lifecycle-scenario.sh` deliberately starts a new PHP process for every request transition while keeping one isolated WordPress site/database prefix alive between stages.

It covers:

- bare WordPress install with Base still inactive;
- genuine first activation and first trust-root bootstrap;
- deactivation cleanup without deleting persistent Base state;
- reactivation without minting a new Operator or approval;
- established-site Trust Schema / Role Policy drift remaining observable rather than silently auto-repaired;
- preservation of user-selected Base settings across deactivation/reactivation;
- next-request repair of a missing current-version audit table;
- late public schema registration;
- duplicate/reserved schema ownership rejection;
- all-or-nothing schema version-marker advancement for incomplete multi-table installs.

The scenario cleans only its own `CB_LIFECYCLE_TABLE_PREFIX` tables and exact A2-owned mail guard.

## A3 pinned Extension Starter consumer scenario

`tests/bin/install-pinned-starter.sh` downloads only the fixed canonical Starter revision named above into the canonical `core-blueprint-starter-plugin/` folder. CI never follows the Starter's moving `main` branch.

The source-level `tools/conformance.php` check runs first. It remains supplemental: the blocking consumer scenario then boots Base and Starter in a real isolated WordPress site.

`tests/bin/run-consumer-scenario.sh` uses a fresh PHP process for each stage:

1. bare WordPress install;
2. Base activation;
3. Starter activation with Base already active;
4. normal subsequent runtime request;
5. simulated wp-admin request.

The harness never calls Starter registration methods directly. The normal `plugins_loaded`, `init`, `core_blueprint_register_extensions`, `admin_menu` and `core_blueprint_register_pages` lifecycles must produce the registrations.

The runtime assertions cover the Core API 1.0 dependency gate, extension inventory/compatibility, module health and Governance `EventRegistry`/`Audit` write path. The admin assertions prove the registered page receives a WordPress hook suffix, scopes its own asset to that hook and renders the documented Base `panels` and `Notice` contracts through its registered page callback.

The scenario cleans only its own `CB_CONSUMER_TABLE_PREFIX` tables, exact A3 consumer mail guard and downloaded Starter fixture.

## A3 destructive uninstall scenario

`tests/bin/run-uninstall-scenario.sh` is deliberately isolated from the normal integration matrix because it deletes the Base plugin files. It uses a fresh PHP process for every stage while keeping one disposable WordPress site/database alive:

1. install WordPress;
2. activate Base;
3. seed representative Base-owned and non-Base ownership sentinels;
4. deactivate Base;
5. initialize WordPress' filesystem layer and call `delete_plugins( [ 'core-blueprint/core-blueprint.php' ] )`;
6. boot a final request after the Base files are gone and inspect persisted state.

`delete_plugins()` returning anything other than literal `true` is a hard failure. The harness has no fallback path that includes `uninstall.php` directly or removes the plugin directory manually.

Representative must-delete assertions cover the Base Operator role/capabilities, options/prefix state, transients, Base user metadata, Media Replace operational metadata/lock, Base-owned tables and Base cron hooks.

Representative must-survive assertions cover ordinary WordPress content, ordinary content/user metadata, quarantine evidence, synthetic extension/sibling options, a third-party role/capability, a third-party transient/table and a third-party cron hook.

## Boundaries

- Composer is development-only. Base does not load `vendor/` at runtime.
- No Node or browser-test stack is required.
- The plugin is copied into the canonical `core-blueprint/` WordPress plugin folder before integration tests run.
- A2/A3 add no production reset API and do not use Reflection to mutate private static state.
- Request-boundary behaviour gets a fresh PHP process; pure same-request contracts may stay in one process.
- The A3 Starter test validates Base as a public API provider; it is not a replacement for the Starter repository's full dependency-behaviour test suite.
- The A3 uninstall smoke is representative. Exhaustive ownership inventory remains BASE-V1-D.
