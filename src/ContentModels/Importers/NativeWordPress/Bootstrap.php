<?php
declare(strict_types=1);
/**
 * Admin workflow for vendor-neutral WordPress-native Content Models adoption.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels\Importers\NativeWordPress;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\ContentModels\Admin\Page;
use CB\Core\ContentModels\FieldTypes;
use CB\Core\ContentModels\State;
use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	private const ACKNOWLEDGEMENT_FIELD = 'content_models_native_import_acknowledgement';

	public static function boot(): void {
		add_action( 'admin_post_cb_core_content_models_native_discover', [ __CLASS__, 'discover' ] );
		add_action( 'admin_post_cb_core_content_models_native_create_plan', [ __CLASS__, 'create_plan' ] );
		add_action( 'admin_post_cb_core_content_models_native_apply_plan', [ __CLASS__, 'apply_plan' ] );
		add_action( 'admin_post_cb_core_content_models_native_discard', [ __CLASS__, 'discard' ] );
	}

	public static function discover(): void {
		self::guard( 'cb_core_content_models_native_discover' );
		PlanStore::clear();
		PlanStore::save_discovery( Discovery::snapshot() );
		self::redirect( [ 'cb_cm_native_preview' => '1' ] );
	}

	public static function create_plan(): void {
		self::guard( 'cb_core_content_models_native_create_plan' );
		try {
			$input = is_array( $_POST ) ? wp_unslash( $_POST ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verifies nonce.
			Importer::create_plan( $input );
			self::redirect( [ 'cb_cm_native_plan' => '1' ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( [ 'cb_cm_native_error' => rawurlencode( $e->getMessage() ), 'cb_cm_native_preview' => '1' ] );
		}
	}

	public static function apply_plan(): void {
		self::guard( 'cb_core_content_models_native_apply_plan' );
		try {
			MutationAcknowledgement::require_confirmed(
				$_POST[ self::ACKNOWLEDGEMENT_FIELD ] ?? null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce; strict literal confirmation only.
				__( 'Confirm your responsibility for backup and recovery before applying the WordPress import plan.', 'core-blueprint' )
			);
			$plan = PlanStore::plan();
			if ( is_array( $plan ) && class_exists( AuditLog::class ) ) {
				AuditLog::log( 'content_models_native_import_acknowledged', 'notice', [
					'plan_id'                 => (string) ( $plan['plan_id'] ?? '' ),
					'summary'                 => (array) ( $plan['summary'] ?? [] ),
					'recovery_responsibility' => true,
				] );
			}
			Importer::apply_plan();
			self::redirect( [ 'cb_cm_native_imported' => '1' ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( [ 'cb_cm_native_error' => rawurlencode( $e->getMessage() ), 'cb_cm_native_plan' => '1' ] );
		}
	}

	public static function discard(): void {
		self::guard( 'cb_core_content_models_native_discard' );
		PlanStore::clear();
		self::redirect( [] );
	}

	public static function render(): void {
		$discovery = PlanStore::discovery();
		$plan = PlanStore::plan();
		$error = isset( $_GET['cb_cm_native_error'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['cb_cm_native_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<section class="cb-core-section cb-content-models-native-importer">
			<h2><?php esc_html_e( 'Native WordPress Import', 'core-blueprint' ); ?></h2>
			<p><?php esc_html_e( 'Adopt only schema that WordPress itself exposes through runtime registrations. Core Blueprint does not inspect arbitrary metadata tables, infer Option Pages or copy customer values.', 'core-blueprint' ); ?></p>
			<p class="description"><?php esc_html_e( 'Workflow: discover → review and map → create a short-lived plan → disable the original registrar → apply. Unsupported or ambiguous semantics are never guessed.', 'core-blueprint' ); ?></p>
			<?php if ( '' !== $error ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
			<?php if ( ! empty( $_GET['cb_cm_native_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'The reviewed WordPress-native schema was adopted. Existing WordPress content and metadata values were not copied or modified.', 'core-blueprint' ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $plan ) ) : ?>
				<?php self::render_plan( $plan ); ?>
			<?php elseif ( is_array( $discovery ) ) : ?>
				<?php self::render_discovery( $discovery ); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cb_core_content_models_native_discover" />
					<?php wp_nonce_field( 'cb_core_content_models_native_discover' ); ?>
					<button class="button cb-core-button cb-core-button--secondary" type="submit"><?php esc_html_e( 'Discover WordPress registrations', 'core-blueprint' ); ?></button>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/** @param array<string,mixed> $snapshot */
	private static function render_discovery( array $snapshot ): void {
		$post_types = is_array( $snapshot['post_types'] ?? null ) ? $snapshot['post_types'] : [];
		$taxonomies = is_array( $snapshot['taxonomies'] ?? null ) ? $snapshot['taxonomies'] : [];
		$meta = is_array( $snapshot['meta'] ?? null ) ? $snapshot['meta'] : [];
		?>
		<div class="cb-core-stack cb-core-stack--loose">
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Ready means the effective WordPress registration can be represented without known loss. Mapping required means WordPress proves the storage contract but you must supply missing Content Models UI semantics. Existing and Unsupported items cannot be selected.', 'core-blueprint' ); ?></p></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_core_content_models_native_create_plan" />
				<?php wp_nonce_field( 'cb_core_content_models_native_create_plan' ); ?>
				<?php self::render_structure_table( __( 'Post Types', 'core-blueprint' ), $post_types ); ?>
				<?php self::render_structure_table( __( 'Taxonomies', 'core-blueprint' ), $taxonomies ); ?>
				<?php self::render_meta_tables( $meta ); ?>
				<p><button class="button cb-core-button cb-core-button--primary" type="submit"><?php esc_html_e( 'Create reviewed import plan', 'core-blueprint' ); ?></button></p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_core_content_models_native_discover" />
				<?php wp_nonce_field( 'cb_core_content_models_native_discover' ); ?>
				<button class="button cb-core-button cb-core-button--secondary" type="submit"><?php esc_html_e( 'Discover again', 'core-blueprint' ); ?></button>
			</form>
		</div>
		<?php
	}

	/** @param array<string,array<string,mixed>> $entries */
	private static function render_structure_table( string $title, array $entries ): void {
		if ( [] === $entries ) {
			return;
		}
		?>
		<h3><?php echo esc_html( $title ); ?></h3>
		<table class="widefat striped"><thead><tr><th class="check-column"></th><th><?php esc_html_e( 'Registration', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Reason', 'core-blueprint' ); ?></th></tr></thead><tbody>
		<?php foreach ( $entries as $entry ) : if ( ! is_array( $entry ) ) { continue; } $ready = Discovery::READY === (string) ( $entry['status'] ?? '' ); ?>
			<tr>
				<td><?php if ( $ready ) : ?><input type="checkbox" name="selected[]" value="<?php echo esc_attr( (string) ( $entry['token'] ?? '' ) ); ?>" /><?php endif; ?></td>
				<td><strong><?php echo esc_html( (string) ( $entry['label'] ?? $entry['key'] ?? '' ) ); ?></strong><br><code><?php echo esc_html( (string) ( $entry['key'] ?? '' ) ); ?></code></td>
				<td><?php echo esc_html( self::status_label( (string) ( $entry['status'] ?? '' ) ) ); ?></td>
				<td><?php echo esc_html( implode( ' ', array_map( 'strval', (array) ( $entry['reasons'] ?? [] ) ) ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/** @param array<string,array<string,mixed>> $entries */
	private static function render_meta_tables( array $entries ): void {
		if ( [] === $entries ) {
			return;
		}
		$contexts = [];
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$contexts[ (string) ( $entry['context_id'] ?? '' ) ][] = $entry;
			}
		}
		$labels = FieldTypes::labels();
		?>
		<h3><?php esc_html_e( 'Registered Metadata', 'core-blueprint' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Only explicitly registered metadata is shown. Values are checked for compatibility only after you select a mapping; metadata keys are never discovered by scanning storage tables.', 'core-blueprint' ); ?></p>
		<?php foreach ( $contexts as $context_id => $items ) : if ( '' === $context_id || [] === $items ) { continue; } $first = $items[0]; $context_token = (string) ( $first['context_token'] ?? '' ); ?>
			<h4><?php echo esc_html( (string) ( $first['context_label'] ?? $context_id ) ); ?> <code><?php echo esc_html( $context_id ); ?></code></h4>
			<p><label><strong><?php esc_html_e( 'Field Group title', 'core-blueprint' ); ?></strong><br><input class="regular-text" type="text" name="group_title[<?php echo esc_attr( $context_token ); ?>]" value="" placeholder="<?php esc_attr_e( 'Enter a title when selecting metadata below', 'core-blueprint' ); ?>" /></label></p>
			<table class="widefat striped"><thead><tr><th class="check-column"></th><th><?php esc_html_e( 'Meta key', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'WordPress type', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Content Models mapping', 'core-blueprint' ); ?></th></tr></thead><tbody>
			<?php foreach ( $items as $entry ) : $mappable = Discovery::MAPPING_REQUIRED === (string) ( $entry['status'] ?? '' ); $token = (string) ( $entry['token'] ?? '' ); ?>
				<tr>
					<td><?php if ( $mappable ) : ?><input type="checkbox" name="selected[]" value="<?php echo esc_attr( $token ); ?>" /><?php endif; ?></td>
					<td><code><?php echo esc_html( (string) ( $entry['key'] ?? '' ) ); ?></code><?php if ( '' !== (string) ( $entry['description'] ?? '' ) ) : ?><br><span class="description"><?php echo esc_html( (string) $entry['description'] ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( (string) ( $entry['registered_type'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( self::status_label( (string) ( $entry['status'] ?? '' ) ) ); ?><?php if ( ! empty( $entry['reasons'] ) ) : ?><br><span class="description"><?php echo esc_html( implode( ' ', array_map( 'strval', (array) $entry['reasons'] ) ) ); ?></span><?php endif; ?></td>
					<td>
					<?php if ( $mappable ) : ?>
						<label><?php esc_html_e( 'Label', 'core-blueprint' ); ?> <input type="text" name="meta_label[<?php echo esc_attr( $token ); ?>]" value="" /></label>
						<label><?php esc_html_e( 'Field type', 'core-blueprint' ); ?> <select name="meta_field_type[<?php echo esc_attr( $token ); ?>]"><option value=""><?php esc_html_e( 'Choose…', 'core-blueprint' ); ?></option>
						<?php foreach ( (array) ( $entry['allowed_field_types'] ?? [] ) as $type ) : ?><option value="<?php echo esc_attr( (string) $type ); ?>"<?php selected( (string) ( $entry['suggested_field_type'] ?? '' ), (string) $type ); ?>><?php echo esc_html( (string) ( $labels[ $type ] ?? $type ) ); ?></option><?php endforeach; ?>
						</select></label>
					<?php else : ?>—<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endforeach;
	}

	/** @param array<string,mixed> $plan */
	private static function render_plan( array $plan ): void {
		$summary = is_array( $plan['summary'] ?? null ) ? $plan['summary'] : [];
		$expires = (int) ( $plan['expires_at'] ?? 0 );
		?>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Import plan ready.', 'core-blueprint' ); ?></strong> <?php esc_html_e( 'Before applying it, disable or remove the code that currently registers every selected post type, taxonomy and metadata key. Core Blueprint will refuse to apply while an original registration still exists.', 'core-blueprint' ); ?></p></div>
		<p><strong><?php esc_html_e( 'Plan ID', 'core-blueprint' ); ?>:</strong> <code><?php echo esc_html( (string) ( $plan['plan_id'] ?? '' ) ); ?></code><br>
		<strong><?php esc_html_e( 'Expires', 'core-blueprint' ); ?>:</strong> <?php echo esc_html( $expires > time() ? human_time_diff( time(), $expires ) : __( 'expired', 'core-blueprint' ) ); ?></p>
		<ul class="ul-disc">
			<li><?php $count = (int) ( $summary['post_types'] ?? 0 ); echo esc_html( sprintf( _n( '%d post type', '%d post types', $count, 'core-blueprint' ), $count ) ); ?></li>
			<li><?php $count = (int) ( $summary['taxonomies'] ?? 0 ); echo esc_html( sprintf( _n( '%d taxonomy', '%d taxonomies', $count, 'core-blueprint' ), $count ) ); ?></li>
			<li><?php $count = (int) ( $summary['field_groups'] ?? 0 ); echo esc_html( sprintf( _n( '%d Field Group', '%d Field Groups', $count, 'core-blueprint' ), $count ) ); ?></li>
			<li><?php $count = (int) ( $summary['meta_fields'] ?? 0 ); echo esc_html( sprintf( _n( '%d registered metadata field', '%d registered metadata fields', $count, 'core-blueprint' ), $count ) ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'The plan is user-scoped, short-lived and integrity-signed. Apply is all-or-nothing: any stale registration, Content Models change, field conflict or metadata-value change rejects the complete plan.', 'core-blueprint' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cb_core_content_models_native_apply_plan" />
			<?php wp_nonce_field( 'cb_core_content_models_native_apply_plan' ); ?>
			<?php MutationAcknowledgement::render(
				self::ACKNOWLEDGEMENT_FIELD,
				'cb-content-models-native-import-acknowledgement',
				__( 'I understand that applying this plan transfers the selected WordPress schema registrations to Core Blueprint and that I am responsible for having a recent backup or other recovery option available.', 'core-blueprint' ),
				__( 'The original registrar must remain disabled after adoption. Plugins, themes and custom code may affect the resulting runtime.', 'core-blueprint' )
			); ?>
			<button class="button cb-core-button cb-core-button--primary" type="submit"><?php esc_html_e( 'Apply reviewed import plan', 'core-blueprint' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
			<input type="hidden" name="action" value="cb_core_content_models_native_discard" />
			<?php wp_nonce_field( 'cb_core_content_models_native_discard' ); ?>
			<button class="button cb-core-button cb-core-button--secondary" type="submit"><?php esc_html_e( 'Discard import plan', 'core-blueprint' ); ?></button>
		</form>
		<?php
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			Discovery::READY            => __( 'Ready', 'core-blueprint' ),
			Discovery::MAPPING_REQUIRED => __( 'Mapping required', 'core-blueprint' ),
			Discovery::EXISTING         => __( 'Existing', 'core-blueprint' ),
			default                     => __( 'Unsupported', 'core-blueprint' ),
		};
	}

	private static function guard( string $action ): void {
		if ( ! State::is_enabled() || ! current_user_can( 'cb_manage_content_models' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Content Models.', 'core-blueprint' ), esc_html__( 'Forbidden', 'core-blueprint' ), [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	/** @param array<string,string> $args */
	private static function redirect( array $args ): void {
		$url = add_query_arg( array_merge( [ 'page' => Page::SLUG, 'tab' => 'tools' ], $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
