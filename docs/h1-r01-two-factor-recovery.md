# H1-R01 — manual two-factor enrollment lock recovery

Base v1 supports single-site WordPress only. The enrollment lock lives in a
non-autoloaded WordPress option scoped to the site and user ID.

## Routine

1. Identify the exact affected user ID and **independently verify that the
   originating PHP, cron, or CLI process has stopped**. A lock's age is not
   proof that the operation is inactive. Do not recover a possibly active lock.
2. On a trusted server terminal, inspect without changing state:

   `wp cb two-factor unlock <user-id> --inspect`

3. Record the displayed SHA-256 identity and review the creation timestamp.
   Newly created locks cannot be recovered in the first 600 seconds, even with
   explicit confirmation.
4. Only after verifying the process is stopped, execute:

   `wp cb two-factor unlock <user-id> --expected-sha256=<exact-sha256> --confirm-inactive=I_VERIFIED_PROCESS_STOPPED`

5. For pre-H1 string locks with **no timestamp**, repeat the independent
   process-termination review. Explicitly append `--legacy-lock=YES`.
   Timestamp-free locks are never automatically removed.
6. Re-inspect and verify the lock is absent. Use `wp cb two-factor status
   <user-id>` to check the credential state. If authorized account recovery
   remains necessary, use the separately governed `wp cb two-factor reset
   <user-id>` procedure; unlock never changes authentication data itself.

The expected digest is derived from the exact option database bytes. A
mismatch, a concurrent replacement, a database error, a fresh lock, a missing
confirmation, or execution outside WP-CLI must refuse recovery. Normal release
uses the same exact-value SQL condition and invalidates the WordPress options
cache. No browser or REST lock-recovery endpoint exists.

## Interrupted recovery-code delivery

Last-code replacement is atomic. Newly generated codes are saved only as
hashes and shown once. If their response is lost, the user can regenerate
codes using password plus working TOTP. Without working TOTP or an already
known unused recovery code, authorized server-side 2FA reset is required.
This must not implicitly mint a fresh second-factor enrollment, bypass
Operator approval, or persist plaintext codes.

## Acceptance

H1 source review is separate from WP runtime acceptance. Verify fresh/legacy
lock handling, wrong identity, concurrent replacement, database failures,
cache consistency, 2FA reset and challenge revocation before a release.
No Level 2 test slot may be reused or reset without explicit authorization.
