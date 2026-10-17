# G1d-A2.4-S0 | Full tracked-source inventory review and S1 scope escalation

**Date:** 2026-10-17  
**Gate:** **S0 SOURCE INVENTORY DELIVERED; S1/S2 IMPLEMENTATION HOLD pending Overseer scope decision + owner's explicit SOURCE GO.**  
**Source anchor:** `Core-Blueprint/wp-core-blueprint@ec4d31fc55a8538291eb110364f2a5248d90cd19`. Operator-supplied read-only local report: `cb-a24-s0-inventory.md` (1,043 tracked PHP/JS source files, zero unreadable, no tracked dirty files; excludes vendored PDF/Node). Report metadata is operator-supplied, not independent runtime verification.  
**Review method:** read report and independently inspect key source call paths against pinned GitHub SHA. Static search lists candidate matches and false positives, not a runtime trace. Inactive/third-party/dynamic hooks cannot be established from tracked-source scanning.

## 1. Direct writes to the *exact* Base Settings option

| Source | Mutation/race | Proposed protection; accepted file scope | Audit/option effects |
| --- | --- | --- | --- |
| `src/Core.php:354-355` | Truthiness-based default bootstrap + unconditional `update_option(CB_CORE_SETTINGS)`; falsey existing value is mistaken for absent | Existence-sensitive insert-once with verified readback, refuse invalid existing contents. **S1 expanded yes** | Preserve first-install WordPress option hooks and autoload |
| `src/SettingsMigrator.php:57-112` | Full-array future migration write without original-row compare; `settings.migrated` emitted *before* persistence at 94 | Original-bytes CAS; refuse conflict; audit only verified persisted state. **S1 expanded yes** | Existing migration audit, WordPress options hooks |
| `src/Settings.php:44-55,75-255,299-310` | Request-cached whole document and unconditional `update_option`; stale nested arrays and full array operations can clobber another writer; defaults audit bypasses return | Fresh row, bounded intent/expected-field or full-section mismatch refusal, one verified CAS, no-op semantics. **S1 original yes** | Preserve `settings.changed`, module/feature/default audits only after proven outcome |
| `uninstall.php:74,131` | Deliberate option deletion during uninstall, not a live Settings editor | Separate lifecycle boundary; **not** a concurrent live writer / no S1 production edit | Keep uninstall contract unchanged |

The scanned tracked source shows **these three live literal `CB_CORE_SETTINGS` write paths**; this is not proof about dynamically computed keys, untracked files or installed third-party code. `src/Design/Document/MigrationInventory.php:20,112-129` and `src/EmailAlerts.php:199,389` are reads, not live Base Settings writes. `src/OptionPolicy.php:15,85,94` changes autoload policy, not Settings content.

## 2. Settings facade callers: every relevant production route and action

Legend: **IN S1/S2** = inside existing approved candidate file boundaries; **OUT** = code change would need additional Overseer scope; **FACADE** = potentially protected by central API on conflict, but readback/failure behavior still requires source regression.

| File:line | Current intent / risk | CAS/hook/audit action | Scope |
| --- | --- | --- | --- |
| `src/Ajax/Handlers/Settings.php:45,61,77,87,140-148,207-214` | Module/feature/default setters and stale `audit/permissions/reports/integrity` nested alert arrays; several unconditional success JSON | Leaf intent plus explicit conflict/error/no-op response and post-commit audits | **IN S1** |
| `src/Ajax/Handlers/Permissions.php:175-185,200-221` | `hide_from_admins` and reports/integrity admin caps; ignores setter outcomes, audits and sends success; 2 different full-array writes | One grouped CAS for capability pair, leaf CAS for hide; audit/JSON only after verified commit | **OUT, P0 expansion requested** |
| `src/Ajax/Handlers/Branding.php:62-75,96-109` | Legacy/prepared Reports Designer saves complete `reports` group (branding/composer) and checks via request cache | Expected group state or bounded paths, conflict-safe legacy write; active mode already uses R-C adapter | **OUT, P0 expansion requested** |
| `src/CLI/Commands/Permissions/HidePage.php:40-60` / `ShowPage.php:27-48` | Read-modify-write `permissions`; unconditionally audit and return command success | Leaf CAS; CLI error on failure; no false `permissions.hide_changed` audit | **OUT, P0 expansion requested** |
| `src/Environment/Admin.php:54-71` | Sets governance policy; UI infers success from `Governance::policy()` | Fresh readback; preserve safe conflict notice; no unnecessary whole-array overwrite | FACADE; may need **OUT** amendment for error UX |
| `src/Integrity/Rest/ScanController.php:628-643,684-705` | Full integrity group replacement; ignores result before REST success + audit including locale override | Compare intended leaves/expected policy; conflict returns error, no success audit | **OUT, P0 expansion requested** |
| `src/Integrity/Scanner/CoreScanner.php:593-611` | Full integrity group after automatic detection; emits `core_blueprint_integrity_locale_detected` or inconclusive event even if write refused | Leaf CAS; condition subsequent hooks on persist | **OUT, P0 expansion requested** |
| `src/Integrity/Storage/ResultRepository.php:499-520` | Merges scan settings and ignores set result | Bounded scanner-config intent, failure propagated to callers | **OUT, review for amendment** |
| `src/Notes/Settings/SettingsRepository.php:30-51` | Merges partial Notes settings to full group; setter result ignored | Compare changed fields, propagate conflict | **OUT, review for amendment** |
| `src/Permissions/OperatorGuard.php:113-140` | Safety guard rewrites full permissions group and writes critical guard audit without checking CAS | Atomic leaf CAS, audit only on proven disable; preserve lockout protection | **OUT, P0 expansion requested** |
| `src/Reports/State.php:80-95` | Full Reports group to change enabled, afterwards checks `is_enabled()` | Use leaf CAS, fresh readback; preserve subsystem-transition audit | FACADE, source test |
| `src/Routing/Policy.php:30-53` | Full routing policy key; checks `enabled()` | Exact policy/leaf intent; fresh readback | FACADE, source test |
| `src/Security/CoreShieldState.php:32-43` | Calls shield setter and checks actual state | Atomic scalar CAS/fresh readback | FACADE, source test |
| `src/Security/LoginShield.php:574-610` | Full login-shield key, emits enabled/URL transition audit even if write failed | Expected-state CAS; no success audit or claimed saved state on conflict | **OUT, P0 expansion requested** |
| `src/Security/TwoFactor/PolicyMutation.php:102-116` | Replaces full 2FA policy; existing result + mode check; emits transition audit after check | Exact mode intent; reject stale revision and false readback, preserve safe audit | FACADE; regression |
| `src/Profiles/Sections/AuditNotificationsSection.php:20,47-51` | Full audit group write from snapshot | Block stale nested array and verify | FACADE; escalate if required edit |
| `src/Profiles/Sections/EnvironmentGovernanceSection.php:63` | Full governance policy | Expected key-state + verify | FACADE |
| `src/Profiles/Sections/PermissionsSection.php:81-113` | `permissions`, `reports`, `integrity` saved separately; partial-success possibility | One Settings-option CAS for all represented leaf changes | **IN S1** |
| `src/Profiles/Sections/ReportsSection.php:22-35,84-111` | Mixed Settings + R-C design; full `reports` group; active Profile import blocked | Read-only preview, revision witness, atomically apply selected retention+alerts + document; conditional reverse CAS | **IN S2** |
| `src/Profiles/Sections/SecuritySection.php:180-190` | Many module/feature leaf updates | Guard individual intents; no claim full multi-section atomicity | FACADE, tests |
| `src/Permissions/OperatorGuard.php:132`, `src/Notes/State.php`, `src/Integrity/State.php` | Indirect module state changes via established repositories/facade | Verify actual transitions before audit or side effects | FACADE or amendment as demonstrated |
| `src/Settings.php:68,106,139,193,223` | Scalar shield; module toggles; bulk toggles; recommended defaults | Preserve unchanged distinction, atomic bulk updates and success-only audits | **IN S1** |

**Additional readers** (no Settings mutation shown by report): `src/EmailAlerts.php:199,389`, `src/Safeguards/Contributors.php:172`, `src/Security/ModuleRegistry.php:134`, `src/Reports/State.php:54`, `src/Reports/DesignDocumentAdapter.php:42`, `src/Permissions/Caps.php:70,111`, and UI/admin Settings readers. `Settings::get()` must never serve a stale request-local cache as proof of durable post-write state.

## 3. WordPress options hook compatibility: hard gate, not theoretical

**Existing test demonstrates required hook semantics:** `tests/integration/ModuleStateTransitionContractTest.php:113,147-161` installs dynamic `pre_update_option_cb_core_settings` filter returning the old value to reject writes, and asserts no false success audit or runtime side effects. Raw SQL CAS bypasses this filter and would violate an existing regression. S1 must either preserve an equivalent authorized pre-filter/refusal and post-commit action contract, or STOP for Overseer approval of an explicit compatibility change. Do not invoke third-party side-effecting callbacks within an InnoDB data transaction.

Tracked Base listeners of generic `updated_option`:
- `src/Log/SystemLog.php:275,653-687` watches a fixed WordPress option allowlist, explicitly excludes `cb_*`; no CB Settings handler.
- `src/Permissions/PrivilegedAccessGuard.php:72,215-221` and `src/Permissions/RolePolicySchema.php:36,231-239` respond only to the current roles option, not `CB_CORE_SETTINGS`.
- `src/Routing/Runtime.php:54-55` listens to *specific other option names* (`permalink_structure`, `category_base`) and is not evidence of CB Settings callback.
- No literal tracked Base registration of `pre_update_option_cb_core_settings` was established outside tests, but runtime-installed plugins may add dynamic callbacks. No assertion of universal hook equivalence may be made from source inventory alone.

**Caches/autoload:** `src/Settings.php:24,44-55,299-310` request cache; `src/OptionPolicy.php:15,83-95` request-hot/autoload policy (**read-only**); WordPress options `alloptions`, `notoptions`, per-option caches and persistent object cache. Atomic CAS and readback must refresh/invalidate correct cache entries, preserve actual autoload value, fail closed on uncertainty. Existing relevant tests: `tests/integration/OptionPolicyContractTest.php:23-103` and `tests/integration/ModuleStateTransitionContractTest.php`.

**Site scope:** Base v1 **does not support Multisite**. No feature expansion; preserve single-site correct `$wpdb->options` identity and forbid silent cross-blog cached state contamination. Source-only cross-site fixtures may test non-leakage but must not claim released multisite support.

## 4. Reconciliation and decision required

**Scanner status:** full tracked-source inventory obtained with zero unreadable files; review has filtered the relevant live Settings writers and discovered multiple new facade callers with success/side-effect risks. This meets the requested S0 *static inventory deliverable*, not automatic approval for implementation, hook compatibility, or Level 2.

**Escalate before GO:** existing S1 allowlist does **not** contain `src/Ajax/Handlers/Permissions.php`, `src/Ajax/Handlers/Branding.php`, either Permissions CLI command, `src/Integrity/Rest/ScanController.php`, `src/Integrity/Scanner/CoreScanner.php`, `src/Permissions/OperatorGuard.php`, `src/Security/LoginShield.php` or other listed FACADE callers if their error behavior requires edits. Recommend a **bounded S1 consumer-acknowledgement sub-batch** with explicitly approved per-file patches and tests, not a broad new Settings architecture round. `OptionPolicy.php` and R-C repository remain read-only.

**Critical hook decision:** preserve `pre_update_option_cb_core_settings` refusal contract; define safe post-commit `update_option*`/`updated_option` semantics before using raw SQL CAS. If equivalence is impossible without unsafe callback timing, request explicit approval for a documented incompatibility rather than proceeding.

**Gate status:** **S0 STATIC SOURCE INVENTORY DELIVERED; S1 SCOPE / HOOK POLICY NEEDS OVERSEER RECONCILIATION; S1+S2 SOURCE GO HOLD.** A2.3a operator-reported Level 1 remains separate. WP Level 2, Docker T2, A2.3b, CI, merges, releasebuild and deployment HOLD.
