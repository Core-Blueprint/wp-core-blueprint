<?php
declare(strict_types=1);
/**
 * Core Setup presentation vocabulary.
 *
 * Keeps translated labels and explanatory copy out of the evidence domain.
 * No status or configuration decisions are made here.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup;

defined( 'ABSPATH' ) || exit;

final class Presentation {

	public static function status_label( string $status ): string {
		return match ( $status ) {
			StatusResolver::CONFIGURED     => __( 'Configured', 'core-blueprint' ),
			StatusResolver::NEEDS_REVIEW   => __( 'Needs review', 'core-blueprint' ),
			StatusResolver::ATTENTION      => __( 'Attention', 'core-blueprint' ),
			StatusResolver::LATER          => __( 'Later', 'core-blueprint' ),
			StatusResolver::NOT_APPLICABLE => __( 'Not applicable', 'core-blueprint' ),
			default                        => __( 'Unknown', 'core-blueprint' ),
		};
	}

	public static function status_badge_variant( string $status ): string {
		return match ( $status ) {
			StatusResolver::CONFIGURED     => 'success',
			StatusResolver::ATTENTION      => 'danger',
			StatusResolver::LATER          => 'warning',
			StatusResolver::NOT_APPLICABLE => 'neutral',
			default                        => 'info',
		};
	}

	public static function overall_label( string $state ): string {
		return match ( $state ) {
			Summary::NEEDS_ATTENTION   => __( 'Needs attention', 'core-blueprint' ),
			Summary::REVIEW_INCOMPLETE => __( 'Review incomplete', 'core-blueprint' ),
			default                    => __( 'Reviewed', 'core-blueprint' ),
		};
	}

	public static function check_description( string $check_id ): string {
		return match ( $check_id ) {
			'environment-identity'             => __( 'Confirm the WordPress environment identity used by environment-aware Core Blueprint policies.', 'core-blueprint' ),
			'environment-indexing-protection'  => __( 'Review whether non-production environments are protected from search indexing.', 'core-blueprint' ),
			'access-mode'                      => __( 'Review whether the site is Public, Coming Soon, in Maintenance, or restricted to administrators.', 'core-blueprint' ),
			'privileged-access-protection'     => __( 'Review the policy that governs unapproved privileged WordPress identities.', 'core-blueprint' ),
			'privileged-access-review'         => __( 'Check whether privileged identities are waiting for operator review.', 'core-blueprint' ),
			'two-factor-readiness'             => __( 'Review the two-factor policy and whether privileged accounts have an available second-factor path.', 'core-blueprint' ),
			'failsafe-readiness'               => __( 'Confirm that the emergency recovery and lockout-prevention layers are available.', 'core-blueprint' ),
			'core-shield'                      => __( 'Review the Core Shield master state and its active hardening modules.', 'core-blueprint' ),
			'login-shield'                     => __( 'Review the custom login endpoint policy and whether Login Shield can enforce it safely.', 'core-blueprint' ),
			'core-scanner-policy'              => __( 'Review whether Core Scanner is enabled and how scheduled integrity scans are configured.', 'core-blueprint' ),
			'core-scanner-readiness'           => __( 'Review the latest Scanner readiness and integrity result without changing files.', 'core-blueprint' ),
			'operational-logs'                 => __( 'Confirm that the Core Blueprint Audit and Logs foundation is available for operational evidence.', 'core-blueprint' ),
			'notifications-policy'             => __( 'Review which Core Blueprint events send email notifications and whether each enabled group has a recipient path.', 'core-blueprint' ),
			'operational-tools'                => __( 'Decide whether the optional Notes and Reports tools belong in this site workflow.', 'core-blueprint' ),
			'mail-delivery-strategy'           => __( 'Decide whether Core Blueprint or another transport owns outbound WordPress mail delivery.', 'core-blueprint' ),
			'mail-delivery-readiness'          => __( 'When Core Blueprint owns delivery, review transport configuration and active mail-plugin conflicts.', 'core-blueprint' ),
			'mail-designer'                    => __( 'Decide whether the Core Blueprint Mail Designer should style supported outgoing messages.', 'core-blueprint' ),
			'privacy-ip-handling'              => __( 'Review how IP addresses are stored in Core Blueprint logs.', 'core-blueprint' ),
			'audit-retention'                  => __( 'Review retention periods for security, maintenance, login, settings, and general audit data.', 'core-blueprint' ),
			'audit-verbosity'                  => __( 'Review how much optional system activity Core Blueprint records in the Audit Log.', 'core-blueprint' ),
			'content-models'                   => __( 'Decide whether governed custom post types, taxonomies, option pages, and field groups are needed.', 'core-blueprint' ),
			'snippets'                         => __( 'Decide whether managed PHP, CSS, JavaScript, and HTML snippets are used on this site.', 'core-blueprint' ),
			'user-roles'                       => __( 'Decide whether Core Blueprint should provide the WordPress roles and capabilities management surface.', 'core-blueprint' ),
			'media-replace'                    => __( 'Decide whether administrators need governed media replacement without changing attachment identity.', 'core-blueprint' ),
			'media-formats'                    => __( 'Review optional modern image and SVG handling against the capabilities of this server.', 'core-blueprint' ),
			'package-downloads'                => __( 'Decide whether administrators may export installed plugins and themes as installable ZIP packages.', 'core-blueprint' ),
			'admin-navigation'                 => __( 'Review whether the native WordPress admin menu and Toolbar need site-specific presentation rules.', 'core-blueprint' ),
			'admin-columns'                    => __( 'Review whether WordPress list-table columns need site-wide ordering or visibility rules.', 'core-blueprint' ),
			'admin-notices'                    => __( 'Review whether supported WordPress admin notice sources need audience rules for operators and client-facing roles.', 'core-blueprint' ),
			default                            => __( 'Review the current Core Blueprint configuration for this check.', 'core-blueprint' ),
		};
	}

	private function __construct() {}
}
