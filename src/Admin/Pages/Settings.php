<?php
declare(strict_types=1);
/**
 * Settings Hub - canonical configuration directory for Core Blueprint extensions.
 *
 * Operational plugin work remains in each extension's own admin workspace.
 * Configuration is routed here through SettingsRegistry providers so the Core
 * Blueprint WordPress submenu does not become a flat extension directory.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Admin\Pages;

use CB\Core\Admin\PageBase;
use CB\Core\Admin\SettingsRegistry;
use CB\Core\UI\Card;
use CB\Core\UI\Icon;

defined( 'ABSPATH' ) || exit;

final class Settings extends PageBase {

	public const SLUG = 'core-blueprint-settings';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Extensions', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Extensions', 'core-blueprint' );
	}

	public function position(): ?int {
		return 99;
	}

	public function render(): void {
		$this->guard();

		$extension_id = isset( $_GET['extension'] ) ? sanitize_key( (string) wp_unslash( $_GET['extension'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing.
		if ( '' !== $extension_id ) {
			$this->render_provider( $extension_id );
			return;
		}

		$this->render_index();
	}

	private function render_index(): void {
		$providers   = SettingsRegistry::visible();
		$first_party = array_filter( $providers, static fn( array $provider ): bool => true === $provider['first_party'] );
		$third_party = array_filter( $providers, static fn( array $provider ): bool => true !== $provider['first_party'] );
		?>
		<div class="wrap cb-core-wrap cb-core-overview cb-core-settings-hub">
			<h1 class="cb-core-title"><?php esc_html_e( 'Extensions', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Configure Core Blueprint extension settings from one place. Operational work stays in each extension workspace; this hub contains configuration only.', 'core-blueprint' ); ?></p>

			<?php if ( [] === $providers ) : ?>
				<?php
				echo Card::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Card::render() escapes structured empty-state content.
					'title' => __( 'Extension settings', 'core-blueprint' ),
					'body'  => '',
					'empty' => [
						'title'       => __( 'No extension settings registered yet', 'core-blueprint' ),
						'description' => __( 'Installed extensions will appear here after they adopt the Core Blueprint Settings Hub contract.', 'core-blueprint' ),
					],
				] );
				?>
			<?php else : ?>
				<?php $this->render_provider_section(
					__( 'Official Core Blueprint Extensions', 'core-blueprint' ),
					__( 'Developed and supported by Core Blueprint.', 'core-blueprint' ),
					$first_party
				); ?>

				<?php if ( [] !== $third_party ) : ?>
					<?php $this->render_provider_section(
						__( 'Third-Party Extensions', 'core-blueprint' ),
						__( 'Extensions from independent developers that integrate with Core Blueprint. Support is provided by the extension developer, not by Core Blueprint.', 'core-blueprint' ),
						$third_party
					); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string,array<string,mixed>> $providers */
	private function render_provider_section( string $title, string $description, array $providers ): void {
		if ( [] === $providers ) {
			return;
		}
		?>
		<section class="cb-core-overview-quick-actions" aria-label="<?php echo esc_attr( $title ); ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="cb-core-intro"><?php echo esc_html( $description ); ?></p>
			<?php foreach ( self::groups() as $group => $group_label ) : ?>
				<?php $group_providers = array_filter( $providers, static fn( array $provider ): bool => $group === $provider['group'] ); ?>
				<?php if ( [] === $group_providers ) { continue; } ?>
				<h3><?php echo esc_html( $group_label ); ?></h3>
				<div class="cb-core-tab-cards cb-core-tab-cards--fixed-three">
					<?php foreach ( $group_providers as $provider ) : ?>
						<?php $this->render_provider_card( $provider ); ?>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/** @param array<string,mixed> $provider */
	private function render_provider_card( array $provider ): void {
		$icon = '' !== (string) $provider['icon'] ? (string) $provider['icon'] : 'settings';
		$url  = SettingsRegistry::url( (string) $provider['id'] );
		?>
		<a class="cb-core-tab-card" href="<?php echo esc_url( $url ); ?>">
			<span class="cb-core-tab-card__icon" aria-hidden="true"><?php echo Icon::render( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() is escape-clean. ?></span>
			<span class="cb-core-tab-card__body">
				<span class="cb-core-tab-card__label"><?php echo esc_html( (string) $provider['label'] ); ?></span>
				<span class="cb-core-tab-card__desc"><?php echo esc_html( (string) $provider['description'] ); ?></span>
				<span class="cb-core-tab-card__desc">
					<span class="cb-core-badge <?php echo true === $provider['first_party'] ? 'cb-core-badge-identity' : 'cb-core-badge-identity cb-core-badge-identity--muted'; ?>">
						<?php echo esc_html( true === $provider['first_party'] ? __( 'Official Core Blueprint', 'core-blueprint' ) : __( 'Third-party extension', 'core-blueprint' ) ); ?>
					</span>
				</span>
				<span class="cb-core-tab-card__desc"><?php printf( esc_html__( 'Developer: %s', 'core-blueprint' ), esc_html( (string) $provider['developer_name'] ) ); ?></span>
			</span>
			<span class="cb-core-tab-card__arrow" aria-hidden="true"><?php echo Icon::render( 'chevron-right', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::render() is escape-clean. ?></span>
		</a>
		<?php
	}

	private function render_provider( string $extension_id ): void {
		$provider = SettingsRegistry::get( $extension_id );
		if ( null === $provider || ! current_user_can( (string) $provider['capability'] ) ) {
			wp_die(
				esc_html__( 'This extension settings provider is not available to your account.', 'core-blueprint' ),
				esc_html__( 'Settings unavailable', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		$developer_name = (string) $provider['developer_name'];
		$developer_url  = (string) $provider['developer_url'];
		$support_url    = (string) $provider['support_url'];
		$first_party    = true === $provider['first_party'];

		ob_start();
		?>
		<p><span class="cb-core-badge <?php echo $first_party ? 'cb-core-badge-identity' : 'cb-core-badge-identity cb-core-badge-identity--muted'; ?>"><?php echo esc_html( $first_party ? __( 'Official Core Blueprint', 'core-blueprint' ) : __( 'Third-party extension', 'core-blueprint' ) ); ?></span></p>
		<p>
			<strong><?php esc_html_e( 'Developer:', 'core-blueprint' ); ?></strong>
			<?php if ( '' !== $developer_url ) : ?>
				<a href="<?php echo esc_url( $developer_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $developer_name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $developer_name ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $first_party ) : ?>
			<p><?php esc_html_e( 'This is an official Core Blueprint extension developed and supported by Core Blueprint.', 'core-blueprint' ); ?></p>
		<?php else : ?>
			<p><?php printf( esc_html__( 'This extension is developed and supported by %s, not by Core Blueprint.', 'core-blueprint' ), esc_html( $developer_name ) ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $support_url ) : ?>
			<p><a class="button" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Developer support', 'core-blueprint' ); ?> ↗</a></p>
		<?php endif; ?>
		<?php
		$identity_html = (string) ob_get_clean();
		?>
		<div class="wrap cb-core-wrap cb-core-settings-hub">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">← <?php esc_html_e( 'All extensions', 'core-blueprint' ); ?></a></p>
			<h1 class="cb-core-title"><?php echo esc_html( (string) $provider['label'] ); ?></h1>
			<p class="cb-core-intro"><?php echo esc_html( (string) $provider['description'] ); ?></p>
			<?php echo Card::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- identity_html is escaped above.
				'title' => __( 'Extension information', 'core-blueprint' ),
				'body'  => $identity_html,
			] ); ?>
			<div class="cb-core-settings-provider">
				<?php call_user_func( $provider['renderer'] ); ?>
			</div>
		</div>
		<?php
	}

	/** @return array<string,string> */
	private static function groups(): array {
		return [
			SettingsRegistry::GROUP_INFRASTRUCTURE     => __( 'Infrastructure', 'core-blueprint' ),
			SettingsRegistry::GROUP_CONTENT_PUBLISHING => __( 'Content & Publishing', 'core-blueprint' ),
			SettingsRegistry::GROUP_COMMUNITY          => __( 'Community', 'core-blueprint' ),
			SettingsRegistry::GROUP_BUSINESS           => __( 'Business', 'core-blueprint' ),
			SettingsRegistry::GROUP_COMMERCE           => __( 'Commerce', 'core-blueprint' ),
			SettingsRegistry::GROUP_OTHER              => __( 'Other', 'core-blueprint' ),
		];
	}
}
