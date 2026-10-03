<?php
/**
 * Core Blueprint - shared About page.
 *
 * Renders the About page that's reachable from every CB plugin's admin
 * menu. Core Blueprint is the canonical owner of this file from v1.0+ -
 * sibling CB plugins call core_blueprint_about_page() rather than
 * shipping their own copy.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

function core_blueprint_about_page(): void {
	$cap = (string) apply_filters( 'core_blueprint_menu_capability', 'manage_options' );
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'core-blueprint' ) );
	}

	$plugins = core_blueprint_detect_plugins();
	$meta    = defined( 'CB_CORE_FILE' )
		? get_file_data(
			CB_CORE_FILE,
			[
				'version'      => 'Version',
				'plugin_uri'   => 'Plugin URI',
				'license'      => 'License',
				'license_uri'  => 'License URI',
				'requires_wp'  => 'Requires at least',
				'requires_php' => 'Requires PHP',
			],
			'plugin'
		)
		: [];

	$version      = (string) ( $meta['version'] ?? ( defined( 'CB_CORE_VERSION' ) ? CB_CORE_VERSION : '' ) );
	$plugin_uri   = (string) ( $meta['plugin_uri'] ?? 'https://coreblueprint.io' );
	$license      = (string) ( $meta['license'] ?? 'GPL-2.0+' );
	$license_uri  = (string) ( $meta['license_uri'] ?? 'https://www.gnu.org/licenses/gpl-2.0.html' );
	$requires_wp  = (string) ( $meta['requires_wp'] ?? '' );
	$requires_php = (string) ( $meta['requires_php'] ?? '' );
	?>
	<div class="wrap cb-core-wrap cb-core-wrap--narrow cb-core-about">
		<h1 class="cb-core-title"><?php esc_html_e( 'About', 'core-blueprint' ); ?></h1>

		<div class="cb-core-about__intro">
			<div class="cb-core-about__identity">
				<strong class="cb-core-about__product">Core Blueprint</strong>
				<?php if ( '' !== $version ) : ?>
					<span class="cb-core-badge cb-core-badge-tech"><?php echo esc_html( 'v' . $version ); ?></span>
				<?php endif; ?>
			</div>
			<p class="cb-core-intro cb-core-about__lead">
				<?php esc_html_e( 'Core Blueprint is the governance and foundation layer for a coherent WordPress site: shared security, privacy, administration, and infrastructure that extensions can build on without recreating the same systems.', 'core-blueprint' ); ?>
			</p>
		</div>

		<section class="cb-core-preferences-section cb-core-about__principles-section">
			<h2><?php esc_html_e( 'Built around three principles', 'core-blueprint' ); ?></h2>
			<div class="cb-core-about__principles">
				<div class="cb-core-about__principle">
					<h3><?php esc_html_e( 'WordPress first', 'core-blueprint' ); ?></h3>
					<p><?php esc_html_e( 'Use familiar WordPress admin patterns for ordinary settings, tables, and workflows, then add Core Blueprint identity and polish where it improves the experience.', 'core-blueprint' ); ?></p>
				</div>
				<div class="cb-core-about__principle">
					<h3><?php esc_html_e( 'Governed by design', 'core-blueprint' ); ?></h3>
					<p><?php esc_html_e( 'Security, privacy, permissions, auditability, and safe defaults are treated as part of the architecture rather than optional afterthoughts.', 'core-blueprint' ); ?></p>
				</div>
				<div class="cb-core-about__principle">
					<h3><?php esc_html_e( 'One shared foundation', 'core-blueprint' ); ?></h3>
					<p><?php esc_html_e( 'Suite extensions reuse the same Foundation contracts for common behavior and presentation, keeping the admin experience consistent and maintainable.', 'core-blueprint' ); ?></p>
				</div>
			</div>
		</section>

		<section class="cb-core-preferences-section cb-core-about__plugins">
			<h2><?php esc_html_e( 'Installed Core Blueprint plugins', 'core-blueprint' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Core Blueprint Base and detected Suite extensions installed on this WordPress site.', 'core-blueprint' ); ?></p>

			<?php if ( empty( $plugins ) ) : ?>
				<p class="description"><?php esc_html_e( 'No Core Blueprint plugins detected.', 'core-blueprint' ); ?></p>
			<?php else : ?>
				<div class="cb-core-about__table-wrap">
					<table class="widefat striped cb-core-about-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Plugin', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Version', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $plugins as $plugin ) : ?>
								<?php $is_active = ! empty( $plugin['active'] ); ?>
								<tr>
									<td><strong><?php echo esc_html( $plugin['name'] ); ?></strong></td>
									<td><code><?php echo esc_html( $plugin['version'] ); ?></code></td>
									<td>
										<?php
										// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
										echo \CoreBlueprint\Core\UI\StateBadge::render(
											$is_active ? __( 'Active', 'core-blueprint' ) : __( 'Inactive', 'core-blueprint' ),
											[ 'variant' => $is_active ? \CoreBlueprint\Core\UI\StateBadge::SUCCESS : \CoreBlueprint\Core\UI\StateBadge::NEUTRAL ]
										);
										// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
										?>
									</td>
									<td><?php echo esc_html( $plugin['description'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>

		<section class="cb-core-preferences-section cb-core-about__project">
			<h2><?php esc_html_e( 'Project information', 'core-blueprint' ); ?></h2>
			<table class="cb-core-kv cb-core-about__project-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Version', 'core-blueprint' ); ?></th>
						<td><code><?php echo esc_html( $version ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'License', 'core-blueprint' ); ?></th>
						<td><a href="<?php echo esc_url( $license_uri ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $license ); ?></a></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Requires WordPress', 'core-blueprint' ); ?></th>
						<td><?php echo esc_html( '' !== $requires_wp ? $requires_wp . '+' : '—' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Requires PHP', 'core-blueprint' ); ?></th>
						<td><?php echo esc_html( '' !== $requires_php ? $requires_php . '+' : '—' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Website', 'core-blueprint' ); ?></th>
						<td><a href="<?php echo esc_url( $plugin_uri ); ?>" target="_blank" rel="noopener noreferrer">coreblueprint.io</a></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Contact', 'core-blueprint' ); ?></th>
						<td><a href="mailto:hi@coreblueprint.io">hi@coreblueprint.io</a></td>
					</tr>
				</tbody>
			</table>
		</section>
	</div>
	<?php

}

function core_blueprint_detect_plugins(): array {
	// Prefer the authoritative detector from Core Blueprint when present.
	if ( class_exists( \CoreBlueprint\Core\Extensions::class ) ) {
		$extensions = \CoreBlueprint\Core\Extensions::detected();
		$out        = [];

		// Include Core Blueprint itself.
		if ( function_exists( 'get_plugin_data' ) && defined( 'CB_CORE_FILE' ) ) {
			$self  = get_plugin_data( CB_CORE_FILE, false, false );
			$out[] = [
				'name'        => $self['Name']    ?? 'Core Blueprint',
				'version'     => $self['Version'] ?? ( defined( 'CB_CORE_VERSION' ) ? CB_CORE_VERSION : '' ),
				'description' => wp_strip_all_tags( (string) ( $self['Description'] ?? '' ) ),
				'active'      => true,
			];
		}

		foreach ( $extensions as $e ) {
			$out[] = [
				'name'        => $e['name'],
				'version'     => $e['version'],
				'description' => $e['description'],
				'active'      => ! empty( $e['active'] ),
			];
		}
		return $out;
	}

	// Fallback path - used only when this file runs without Core Blueprint
	// loaded (defensive; Core Blueprint should always be present in v1.0+).
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all        = get_plugins();
	$active     = (array) get_option( 'active_plugins', [] );
	$active_map = array_flip( $active );
	$cb_plugins = [];
	foreach ( $all as $file => $data ) {
		$slug   = dirname( $file );
		$author = trim( wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ) );
		if ( str_starts_with( $slug, 'core-blueprint-' ) && 'Core Blueprint' === $author ) {
			$cb_plugins[] = [
				'name'        => $data['Name']    ?? $slug,
				'version'     => $data['Version'] ?? '',
				'description' => wp_strip_all_tags( (string) ( $data['Description'] ?? '' ) ),
				'active'      => isset( $active_map[ $file ] ),
			];
		}
	}
	return $cb_plugins;
}
