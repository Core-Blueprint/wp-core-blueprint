<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, opt-in revision contract for Profile sections backed by more
 * than the normal exported configuration snapshot.
 *
 * Witnesses contain hashes and opaque revisions, never Profile payloads.
 * Neither the public Profile JSON nor the generic SectionInterface changes.
 */
interface VersionGuardedSectionInterface {
    /** @return array<string,string> */
    public function version_witness(): array;

    /** Reject unsupported applies before Engine mutates any selected section. */
    public function assert_apply_allowed(): void;

    /**
     * Compensate only if the exact recorded post-apply witness is still valid.
     * Without an after-witness, verify untouched before state or fail closed.
     *
     * @param array<string,mixed> $before_witness
     * @param array<string,mixed>|null $after_witness
     */
    public function restore_guarded(
        array $snapshot,
        array $incoming,
        string $actor,
        array $before_witness,
        ?array $after_witness
    ): void;
}
