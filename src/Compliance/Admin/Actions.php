<?php
declare(strict_types=1);
/**
 * Admin mutations for Compliance Resources.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Compliance\Admin;

use CB\Core\Compliance\Repository;
use CB\Core\Compliance\Resolver;
use CB\Core\Compliance\ResourceRegistry;
use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Actions {

	public const SAVE_ACTION   = 'cb_core_compliance_save_resource';
	public const ADD_ACTION    = 'cb_core_compliance_add_custom';
	public const UPDATE_ACTION = 'cb_core_compliance_update_custom';
	public const DELETE_ACTION = 'cb_core_compliance_delete_custom';

	public static function init(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, [ self::class, 'save_resource' ] );
		add_action( 'admin_post_' . self::ADD_ACTION, [ self::class, 'add_custom' ] );
		add_action( 'admin_post_' . self::UPDATE_ACTION, [ self::class, 'update_custom' ] );
		add_action( 'admin_post_' . self::DELETE_ACTION, [ self::class, 'delete_custom' ] );
	}

	public static function save_resource(): void {
		self::guard();
		$key = isset( $_POST['resource_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['resource_key'] ) ) : '';
		if ( null === ResourceRegistry::get( $key ) ) {
			self::redirect( 'unknown-resource' );
		}
		check_admin_referer( 'cb_core_compliance_save:' . $key );

		$raw_default = isset( $_POST['resource'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['resource'] ) ) : '';
		$default     = Resolver::parse_reference( $raw_default );
		if ( '' !== trim( $raw_default ) && null === $default ) {
			self::redirect( 'invalid-resource', $key );
		}

		$locale_resources = isset( $_POST['locale_resource'] ) && is_array( $_POST['locale_resource'] )
			? wp_unslash( $_POST['locale_resource'] )
			: [];
		$remove_locales = isset( $_POST['remove_locale'] ) && is_array( $_POST['remove_locale'] )
			? wp_unslash( $_POST['remove_locale'] )
			: [];
		$locales = [];

		foreach ( $locale_resources as $raw_locale => $raw_resource ) {
			$locale = Resolver::normalize_locale( (string) $raw_locale );
			if ( '' === $locale ) {
				self::redirect( 'invalid-locale', $key );
			}
			if ( isset( $remove_locales[ $raw_locale ] ) ) {
				continue;
			}
			$value = is_string( $raw_resource ) ? sanitize_text_field( $raw_resource ) : '';
			if ( '' === trim( $value ) ) {
				continue;
			}
			$reference = Resolver::parse_reference( $value );
			if ( null === $reference ) {
				self::redirect( 'invalid-resource', $key );
			}
			$locales[ $locale ] = $reference;
		}

		$new_locale_raw   = isset( $_POST['new_locale'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['new_locale'] ) ) : '';
		$new_resource_raw = isset( $_POST['new_locale_resource'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['new_locale_resource'] ) ) : '';
		if ( '' !== trim( $new_locale_raw ) || '' !== trim( $new_resource_raw ) ) {
			$new_locale    = Resolver::normalize_locale( $new_locale_raw );
			$new_reference = Resolver::parse_reference( $new_resource_raw );
			if ( '' === $new_locale || null === $new_reference ) {
				self::redirect( 'invalid-locale-resource', $key );
			}
			$locales[ $new_locale ] = $new_reference;
		}

		if ( ! Repository::set_assignment( $key, $default, $locales ) ) {
			self::redirect( 'save-failed', $key );
		}

		AuditLog::log( 'compliance.resource.assignment_changed', 'notice', [
			'resource_key'    => $key,
			'default_type'    => null === $default ? 'none' : $default['type'],
			'locale_variants' => array_keys( $locales ),
		] );
		self::redirect( 'saved', $key );
	}

	public static function add_custom(): void {
		self::guard();
		$owner = isset( $_POST['owner'] ) ? sanitize_key( (string) wp_unslash( $_POST['owner'] ) ) : '';
		check_admin_referer( 'cb_core_compliance_add:' . $owner );

		$label       = isset( $_POST['label'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['label'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['description'] ) ) : '';
		$key         = Repository::add_custom( $owner, $label, $description );
		if ( '' === $key ) {
			self::redirect( 'add-failed' );
		}

		AuditLog::log( 'compliance.resource.custom_added', 'notice', [
			'resource_key' => $key,
			'owner'        => $owner,
		] );
		self::redirect( 'added', $key );
	}

	public static function update_custom(): void {
		self::guard();
		$key = isset( $_POST['resource_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['resource_key'] ) ) : '';
		check_admin_referer( 'cb_core_compliance_update:' . $key );

		$definition = ResourceRegistry::get( $key );
		if ( null === $definition || true !== $definition['custom'] ) {
			self::redirect( 'update-failed', $key );
		}

		$label       = isset( $_POST['label'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['label'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['description'] ) ) : '';
		if ( ! Repository::update_custom( $key, $label, $description ) ) {
			self::redirect( 'update-failed', $key );
		}

		AuditLog::log( 'compliance.resource.custom_updated', 'notice', [
			'resource_key' => $key,
			'owner'        => $definition['owner'],
		] );
		self::redirect( 'updated', $key );
	}

	public static function delete_custom(): void {
		self::guard();
		$key = isset( $_POST['resource_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['resource_key'] ) ) : '';
		check_admin_referer( 'cb_core_compliance_delete:' . $key );

		$definition = ResourceRegistry::get( $key );
		if ( null === $definition || true !== $definition['custom'] || ! Repository::delete_custom( $key ) ) {
			self::redirect( 'delete-failed' );
		}

		AuditLog::log( 'compliance.resource.custom_deleted', 'warning', [
			'resource_key' => $key,
			'owner'        => $definition['owner'],
		] );
		self::redirect( 'deleted' );
	}

	private static function guard(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Invalid request method.', 'core-blueprint' ), '', [ 'response' => 405 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage compliance resources.', 'core-blueprint' ), '', [ 'response' => 403 ] );
		}
	}

	private static function redirect( string $notice, string $resource_key = '' ): never {
		$args = [
			'page'      => Page::SLUG,
			'cb_notice' => sanitize_key( $notice ),
		];
		if ( '' !== $resource_key ) {
			$args['cb_resource'] = sanitize_text_field( $resource_key );
		}
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		if ( '' !== $resource_key ) {
			$url .= '#resource-' . rawurlencode( $resource_key );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
