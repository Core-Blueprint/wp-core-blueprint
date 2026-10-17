# G1d-A2.4-S1 | WordPress Options hook, cache and CAS protocol

**2026-10-17. Source scope only.** Anchor: `ec4d31fc55a8538291eb110364f2a5248d90cd19`; Overseer final S0 decision `5581e309dfa20793a5e69cbe5e499210ae34f232`. This protocol is a **design gate**, not a claim of WordPress runtime parity, authorization for S2, or Level 2 evidence.

## Canonical behavior and order

WordPress `update_option()` (reference: https://developer.wordpress.org/reference/functions/update_option/) performs, for an existing option:
1. Clone object value where applicable; `sanitize_option($name,$value)`.
2. `apply_filters("pre_update_option_{$name}", $value, $old_value, $name)`.
3. `apply_filters('pre_update_option', $value, $name, $old_value)`.
4. Return false on value equality / serialized no-op.
5. Call `do_action('update_option', $name, $old_value, $value)` **before SQL**.
6. Write database, update WordPress option caches.
7. Call `do_action("update_option_{$name}", $old_value, $value, $name)` then `do_action('updated_option', $name, $old_value, $value)` **only after successful SQL**.

For first-install creation, WordPress `add_option()` has its distinct sanitize/pre-add/add hooks and autoload behavior; do not emulate an update by writing a falsey or invalid pre-existing option.

## First-party S1 CAS contract (proposed, implementation held pending fixture)

- Operate on the **actual existing site-specific** `$wpdb->options` row; detect truly missing/invalid/SQL error separately. `CB_CORE_SETTINGS` is Base v1 single-site only. Preserve the *raw stored autoload value*; `OptionPolicy.php` remains read-only.
- Obtain an expected user intent from the Base request-cache original snapshot **before** interpreting a proposed full group array. Derive only changed leaf paths; all sibling paths on the latest committed document remain untouched. A stale full `reports` group cannot reset unrelated settings. For intended paths, compare expected source leaf (including present-vs-absent) to the freshly read row before writing; on mismatch fail as conflict. For multi-leaf Permissions/config intents, the full changed-path set is applied in one conditional update or not at all.
- Outside a transaction: prepare candidate by merging intended leaves into the latest raw document; run `sanitize_option` and both WordPress pre-update filters **once, in native order**, passing the actual fresh old decoded value. Old-value veto returns `refused` with no SQL, transition audit or downstream event. Reject malformed or transformed values that change any non-intended field; do not silently widen mutation.
- An unchanged post-filter serialized document returns `unchanged` (not `saved`), no transition audit/action. Intent conflict returns `conflict`; invalid storage or SQL uncertainty returns `failed/uncertain`. Distinguish those states internally while keeping old public facade success types.
- `update_option` **pre-write action** is emitted outside the DB transaction, after approved filters and no-op check. It may run on a subsequently failed CAS just as WordPress's native pre-action may run before a failed DB update; therefore do not let a first-party security/audit success depend on that pre-action. Reentrant changes during the hook must cause a CAS conflict, never be clobbered.
- Conditional SQL must compare `option_value` **binary-safely** against exact raw bytes (not case-insensitive collation) and use `option_name`/site-specific table identity. Never use a blanket `update_option()` for the CAS write, because it has no expected-old predicate. Never replay input `$wpdb->update` by itself without a predicate.
- Only on proven successful write: invalidate/refresh `Settings::$cached` and WordPress `alloptions`, per-option and `notoptions` caches, verify an authoritative fresh raw row, and then emit `update_option_cb_core_settings` followed by `updated_option` outside the transaction. WordPress hooks must not see an uncommitted write. If a cache operation fails, no false-success acknowledgment; return `uncertain` and require recovery. We do not promise distributed cache read linearizability on shared hosting.
- No promise of compatibility with independent third-party/direct WordPress `update_option('cb_core_settings')` overwrites that bypass Base CAS. Their pre-filters can still intercept/refuse first-party writes but full cross-writer serializability is not established. If full hook equivalence or external-writer coordination proves necessary, **STOP for Overseer**, do not fake equality with native Options API.
- **S2 note:** an owned two-row transaction must not execute arbitrary pre-/post-hooks inside it. Perform filter preflight outside transaction, revalidate inside locked rows, commit, verify, then postcommit hooks; unresolved pre-write semantics are a blocking compatibility decision.

## Required executable proof

Standalone Level 1 fixtures must prove:
- callbacks invoked exactly once in `sanitize_option` → per-option pre-filter → generic pre-filter order; old-value veto and no-op never write or audit;
- no duplicate pre-hooks on conflict, no callback inside a held transaction, and only a successful CAS emits the specific/generic updated hooks in native order;
- two workers with stale full nested groups: one changes Reports branding, another changes Reports retention, neither loses the other's leaf; same-leaf collision fails;
- never confuse missing with stored `false`; distinguish no-op, conflict and SQL uncertainty;
- byte-sensitive CAS, preservation of actual autoload and site/table identity; no cross-site claims;
- refusal/failure prevents successful AJAX/REST/CLI acknowledgements, event hooks and security audits; test immutable snapshots, with real WP/Persistent Cache parity deferred to Level 2.

**P0 unresolved until executable code:** precise cache behavior on persistent stores and inherently side-effecting third-party pre-write hooks. Source tests alone cannot close them. **S1 review required before S2**; WP Level 2, Docker, CI, merge, cutover, release/deploy separately HOLD.
