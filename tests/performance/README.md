# BASE-V1-F3A performance + query-source attribution harness

This harness extends the F2 observation baseline with opt-in query tracing for the rendered logged-in operator frontend. The purpose is to identify where measured Base query overhead originates before any production optimization is considered.

It measures fourteen isolated profile requests in one temporary WordPress installation, plus isolated setup/state-transition requests that are not included as profile records.

**Before Base activation (WordPress-only controls):**

- anonymous frontend;
- logged-in administrator frontend;
- logged-in administrator frontend with the footer/render phase executed and query tracing enabled;
- generic WordPress admin dashboard.

**After Base activation, while public-v1 default-off modules remain off:**

- first authenticated WordPress admin request after activation;
- anonymous frontend;
- logged-in operator frontend (and verifies the user has `cb_core_hud_use`);
- logged-in operator frontend with the footer/HUD render phase executed and query tracing enabled;
- generic WordPress admin dashboard;
- Core Blueprint Dashboard;
- Logs;
- Safeguards.

**Explicit opt-in observation:**

- Reports, measured only after the harness enables the default-off Reports subsystem through its canonical state authority. The record is marked with `context.opt_in_modules: ["reports"]` so it is never mistaken for default Base footprint.

**Disabled-module observation:**

- generic WordPress admin dashboard after the harness disables every canonical optional module.

Every normal scenario emits JSON with request memory, query count, classic/script-module asset counts, Core Blueprint local asset bytes, the WordPress autoload footprint and scheduled Core Blueprint cron hooks/events. Records include whether Base was active, whether the request was authenticated, whether query tracing was enabled, whether the footer/render phase was executed and which optional modules, if any, were explicitly enabled for that observation.

`baseline.json` contains five direct control → Base comparisons:

- `anonymous_frontend`: WordPress-only anonymous frontend → Base-enabled anonymous frontend;
- `operator_frontend`: WordPress-only logged-in frontend → Base-enabled logged-in operator frontend;
- `operator_frontend_rendered`: the same comparison with `wp_footer` executed so actual HUD rendering is included;
- `generic_admin`: WordPress-only admin dashboard → Base-enabled generic admin dashboard;
- `generic_admin_modules_disabled`: WordPress-only admin dashboard → Base-enabled generic admin dashboard after every canonical optional module is disabled.

## F3A query trace

Only the rendered operator pair enables `SAVEQUERIES`. It is enabled through `tests/performance/query-trace-prepend.php`, loaded with PHP `auto_prepend_file`, so WordPress query collection starts before `wp-settings.php` boots without changing production code or the normal request harness.

The temporary MU performance guard records query-count boundaries at:

1. `wp_loaded` — end of WordPress/Base bootstrap for this harness;
2. `wp` — after the frontend main-query/routing phase;
3. `wp_enqueue_scripts` — after enqueue/capability gating;
4. `wp_footer` — after the real footer/HUD render phase.

The raw query traces are stored under `query-traces/` in the CI artifact. Each trace entry contains:

- sequential query index;
- attributed phase (`bootstrap`, `wp`, `enqueue`, `render`);
- whitespace-normalized SQL;
- WordPress `SAVEQUERIES` caller string;
- query duration.

`baseline.json` also contains `query_attribution`, with per-phase control/Base counts and a multiset difference of Base-extra and control-only queries. The difference key is phase + normalized SQL; the Base-side caller is retained for each unmatched query so the source can be inspected directly.

These data remain **diagnostic observations only**. F3A introduces no numeric performance budgets and changes no production code.

Run through `tests/bin/run-performance-baseline.sh` after `tests/bin/install-wp-tests.sh` has prepared the pinned WordPress runtime. Results are written to `CB_PERFORMANCE_RESULTS_DIR` when set, otherwise to the system temporary directory. CI uploads the complete directory for every supported WP/PHP lane.
