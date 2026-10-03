<?php
declare(strict_types=1);
/**
 * Central Compliance Resources admin screen.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Compliance\Admin;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\Compliance\Repository;
use CoreBlueprint\Core\Compliance\Resolver;
use CoreBlueprint\Core\Compliance\ResourceRegistry;
use CoreBlueprint\Core\UI\Icon;
use CoreBlueprint\Core\UI\Notice;
use CoreBlueprint\Core\UI\ObjectPicker;
use CoreBlueprint\Core\UI\StateBadge;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public const SLUG = 'core-blueprint-compliance';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Compliance', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Compliance', 'core-blueprint' );
	}

	public function position(): ?int {
		return 40;
	}

	public function render(): void {
		$this->guard();
		ResourceRegistry::collect();

		$definitions = ResourceRegistry::all();
		$groups      = [];
		foreach ( $definitions as $definition ) {
			$owner = (string) $definition['owner'];
			$groups[ $owner ][] = $definition;
		}

		uksort(
			$groups,
			static function ( string $a, string $b ): int {
				if ( ResourceRegistry::BASE_OWNER === $a ) {
					return -1;
				}
				if ( ResourceRegistry::BASE_OWNER === $b ) {
					return 1;
				}
				return strcasecmp( ResourceRegistry::owner_label( $a ), ResourceRegistry::owner_label( $b ) );
			}
		);

		$search_nonce = wp_create_nonce( ObjectSearch::NONCE );
		?>
		<div class="wrap cb-core-wrap cb-core-compliance-page">
			<h1 class="cb-core-title"><?php esc_html_e( 'Compliance resources', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro">
				<?php esc_html_e( 'Keep the privacy, legal, security and governance resources used by your site and extensions in one central place. A resource can point to a published WordPress page or a public document in the Media Library.', 'core-blueprint' ); ?>
			</p>

			<?php $this->render_feedback(); ?>

			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
			echo Notice::render( [
				'variant' => Notice::INFO,
				'message' => __( 'Core Blueprint checks whether configured resources are available. It does not decide which documents your organisation is legally required to publish.', 'core-blueprint' ),
			] );
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<?php foreach ( $groups as $owner => $resources ) : ?>
				<?php $this->render_owner( $owner, $resources, $search_nonce ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int,array{key:string,owner:string,id:string,label:string,description:string,custom:bool}> $resources
	 */
	private function render_owner( string $owner, array $resources, string $search_nonce ): void {
		$owner_label = ResourceRegistry::owner_label( $owner );
		?>
		<section class="cb-core-section" aria-labelledby="cb-compliance-owner-<?php echo esc_attr( sanitize_html_class( $owner ) ); ?>">
			<h2 class="cb-core-section-title" id="cb-compliance-owner-<?php echo esc_attr( sanitize_html_class( $owner ) ); ?>"><?php echo esc_html( $owner_label ); ?></h2>
			<p class="description">
				<?php if ( ResourceRegistry::BASE_OWNER === $owner ) : ?>
					<?php esc_html_e( 'Base provides the standard roles below. You can assign or change their resources, but software-defined roles cannot be removed.', 'core-blueprint' ); ?>
				<?php else : ?>
					<?php
					/* translators: %s: extension or owner label. */
					printf( esc_html__( '%s contributes its own compliance roles. You can assign resources and add your own organisation-specific items in this section.', 'core-blueprint' ), esc_html( $owner_label ) );
					?>
				<?php endif; ?>
			</p>

			<div class="cb-core-stack cb-core-stack--compact">
				<?php foreach ( $resources as $definition ) : ?>
					<?php $this->render_resource( $definition, $search_nonce ); ?>
				<?php endforeach; ?>

				<?php if ( ResourceRegistry::owner_exists( $owner ) ) : ?>
					<?php $this->render_add_custom( $owner ); ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/** @param array{key:string,owner:string,id:string,label:string,description:string,custom:bool} $definition */
	private function render_resource( array $definition, string $search_nonce ): void {
		$key        = (string) $definition['key'];
		$assignment = Repository::assignment( $key );
		$resolved   = Resolver::resolve( $key );
		$default    = is_array( $assignment['default'] ) ? $assignment['default'] : null;
		$wp_fallback = false;

		// Present an existing WordPress Privacy Policy as the initial default
		// selection until the operator explicitly saves a Core Blueprint choice.
		if ( null === $default && null !== $resolved && 'wordpress' === $resolved['used_locale'] ) {
			$default     = $resolved['reference'];
			$wp_fallback = true;
		}

		$unavailable_default = null;
		if ( is_array( $assignment['default'] ) && null === Resolver::picker_item( $assignment['default'] ) ) {
			$unavailable_default = Resolver::assignment_item( $assignment['default'] );
			$default             = null;
		}

		$has_saved_assignment = null !== $assignment['default'] || [] !== $assignment['locales'];
		$status_label = null !== $resolved ? __( 'Available', 'core-blueprint' ) : ( $has_saved_assignment ? __( 'Unavailable', 'core-blueprint' ) : __( 'Not configured', 'core-blueprint' ) );
		$status_variant = null !== $resolved ? StateBadge::SUCCESS : ( $has_saved_assignment ? StateBadge::DANGER : StateBadge::WARNING );
		$row_state = null !== $resolved ? 'ok' : ( $has_saved_assignment ? 'critical' : 'warning' );
		$is_open = $this->requested_resource_key() === $key;
		?>
		<details id="resource-<?php echo esc_attr( $key ); ?>" class="cb-core-interactive-row cb-core-interactive-row--<?php echo esc_attr( $row_state ); ?>"<?php echo $is_open ? ' open' : ''; ?>>
			<summary class="cb-core-interactive-row__summary">
				<span class="cb-core-interactive-row__icon">
					<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted icon registry. ?>
				</span>
				<span class="cb-core-stack cb-core-stack--compact">
					<strong><?php echo esc_html( (string) $definition['label'] ); ?></strong>
					<?php if ( '' !== (string) $definition['description'] ) : ?>
						<span class="description"><?php echo esc_html( (string) $definition['description'] ); ?></span>
					<?php endif; ?>
				</span>
				<span class="cb-core-disclosure__meta">
					<?php echo StateBadge::render( $status_label, [ 'variant' => $status_variant, 'density' => StateBadge::DENSITY_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StateBadge escapes structured content. ?>
					<span class="cb-core-badge <?php echo true === $definition['custom'] ? 'cb-core-badge-standard' : 'cb-core-badge-identity'; ?>">
						<?php echo esc_html( true === $definition['custom'] ? __( 'Custom', 'core-blueprint' ) : __( 'Software-defined', 'core-blueprint' ) ); ?>
					</span>
				</span>
			</summary>

			<div class="cb-core-interactive-row__body cb-core-stack">
				<form class="cb-core-stack" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="<?php echo esc_attr( Actions::SAVE_ACTION ); ?>">
					<input type="hidden" name="resource_key" value="<?php echo esc_attr( $key ); ?>">
					<?php wp_nonce_field( 'cb_core_compliance_save:' . $key ); ?>

					<?php if ( null !== $unavailable_default ) : ?>
						<div class="cb-core-field">
							<span class="cb-core-field__label"><?php esc_html_e( 'Current assignment', 'core-blueprint' ); ?></span>
							<p>
								<strong><?php echo esc_html( $unavailable_default['label'] ); ?></strong>
								<span class="description"> · <?php echo esc_html( $unavailable_default['meta'] ); ?></span>
							</p>
							<p class="cb-core-field__hint"><?php esc_html_e( 'This saved assignment is unavailable. Select a replacement below, or save with no selection to clear it.', 'core-blueprint' ); ?></p>
						</div>
					<?php endif; ?>

					<?php $default_picker_id = 'cb-compliance-' . sanitize_html_class( $key . '-default' ); ?>
					<div class="cb-core-field">
						<label class="cb-core-field__label" for="<?php echo esc_attr( $default_picker_id ); ?>"><?php esc_html_e( 'Default page or document', 'core-blueprint' ); ?></label>
						<?php $this->render_object_picker( $default_picker_id, 'resource', $default, $search_nonce, $wp_fallback ? __( 'WordPress Privacy Policy fallback', 'core-blueprint' ) : '' ); ?>
						<p class="cb-core-field__hint"><?php esc_html_e( 'Search published Pages or supported documents in the Media Library. This is also the fallback when no locale-specific resource is configured.', 'core-blueprint' ); ?></p>
					</div>

					<details class="cb-core-disclosure cb-core-disclosure--compact">
						<summary class="cb-core-disclosure__summary">
							<span class="cb-core-disclosure__icon">
								<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted icon registry. ?>
							</span>
							<span class="cb-core-disclosure__title"><?php esc_html_e( 'Language / locale overrides', 'core-blueprint' ); ?></span>
						</summary>
						<div class="cb-core-disclosure__body">
							<p class="description"><?php esc_html_e( 'Optional. Map a WordPress locale such as nl_NL, en_GB or de_DE to another page or document. Core Blueprint uses the current WordPress locale and needs no multilingual-plugin adapter.', 'core-blueprint' ); ?></p>

							<?php foreach ( $assignment['locales'] as $locale => $reference ) : ?>
								<?php if ( ! is_array( $reference ) ) { continue; } ?>
								<div class="cb-core-field">
									<?php $locale_picker_id = 'cb-compliance-' . sanitize_html_class( $key . '-' . (string) $locale ); ?>
									<label class="cb-core-field__label" for="<?php echo esc_attr( $locale_picker_id ); ?>">
										<?php echo esc_html( (string) $locale ); ?>
									</label>
									<?php $this->render_object_picker( $locale_picker_id, 'locale_resource[' . (string) $locale . ']', $reference, $search_nonce ); ?>
									<label>
										<input type="checkbox" name="remove_locale[<?php echo esc_attr( (string) $locale ); ?>]" value="1">
										<?php esc_html_e( 'Remove this locale override', 'core-blueprint' ); ?>
									</label>
								</div>
							<?php endforeach; ?>

							<div class="cb-core-field">
								<label class="cb-core-field__label" for="cb-compliance-new-locale-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>"><?php esc_html_e( 'Add locale override', 'core-blueprint' ); ?></label>
								<input id="cb-compliance-new-locale-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>" type="text" name="new_locale" value="" placeholder="nl_NL" pattern="[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*">
								<?php $this->render_object_picker( 'cb-compliance-new-resource-' . sanitize_html_class( $key ), 'new_locale_resource', null, $search_nonce ); ?>
							</div>
						</div>
					</details>

					<div class="cb-core-form-actions">
						<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save resource', 'core-blueprint' ); ?></button>
					</div>
				</form>

				<details class="cb-core-disclosure cb-core-disclosure--compact">
					<summary class="cb-core-disclosure__summary">
						<span class="cb-core-disclosure__icon">
							<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted icon registry. ?>
						</span>
						<span class="cb-core-disclosure__title"><?php esc_html_e( 'Usage', 'core-blueprint' ); ?></span>
					</summary>
					<div class="cb-core-disclosure__body">
						<?php if ( null !== $resolved ) : ?>
							<div class="cb-core-field">
								<label class="cb-core-field__label"><?php esc_html_e( 'Resolved URL', 'core-blueprint' ); ?></label>
								<input class="large-text code" type="text" readonly value="<?php echo esc_attr( $resolved['url'] ); ?>">
							</div>
						<?php endif; ?>
						<div class="cb-core-field">
							<label class="cb-core-field__label"><?php esc_html_e( 'Link shortcode', 'core-blueprint' ); ?></label>
							<input class="large-text code" type="text" readonly value="<?php echo esc_attr( sprintf( '[cb_compliance_resource id="%s"]', $key ) ); ?>">
						</div>
						<div class="cb-core-field">
							<label class="cb-core-field__label"><?php esc_html_e( 'URL shortcode', 'core-blueprint' ); ?></label>
							<input class="large-text code" type="text" readonly value="<?php echo esc_attr( sprintf( '[cb_compliance_resource id="%s" format="url"]', $key ) ); ?>">
						</div>
					</div>
				</details>

				<?php if ( true === $definition['custom'] ) : ?>
					<details class="cb-core-disclosure cb-core-disclosure--compact">
						<summary class="cb-core-disclosure__summary">
							<span class="cb-core-disclosure__icon">
								<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted icon registry. ?>
							</span>
							<span class="cb-core-disclosure__title"><?php esc_html_e( 'Edit custom item', 'core-blueprint' ); ?></span>
						</summary>
						<div class="cb-core-disclosure__body">
							<form class="cb-core-stack" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
								<input type="hidden" name="action" value="<?php echo esc_attr( Actions::UPDATE_ACTION ); ?>">
								<input type="hidden" name="resource_key" value="<?php echo esc_attr( $key ); ?>">
								<?php wp_nonce_field( 'cb_core_compliance_update:' . $key ); ?>
								<div class="cb-core-field">
									<label class="cb-core-field__label" for="cb-compliance-edit-label-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>"><?php esc_html_e( 'Name', 'core-blueprint' ); ?></label>
									<input id="cb-compliance-edit-label-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>" class="regular-text" type="text" name="label" maxlength="120" value="<?php echo esc_attr( (string) $definition['label'] ); ?>" required>
								</div>
								<div class="cb-core-field">
									<label class="cb-core-field__label" for="cb-compliance-edit-description-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></label>
									<textarea id="cb-compliance-edit-description-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>" class="large-text" name="description" rows="2" maxlength="500"><?php echo esc_textarea( (string) $definition['description'] ); ?></textarea>
								</div>
								<div class="cb-core-form-actions">
									<button type="submit" class="button button-secondary cb-core-button"><?php esc_html_e( 'Save changes', 'core-blueprint' ); ?></button>
								</div>
							</form>

							<form class="cb-core-stack cb-core-stack--compact" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
								<input type="hidden" name="action" value="<?php echo esc_attr( Actions::DELETE_ACTION ); ?>">
								<input type="hidden" name="resource_key" value="<?php echo esc_attr( $key ); ?>">
								<?php wp_nonce_field( 'cb_core_compliance_delete:' . $key ); ?>
								<div class="cb-core-form-actions">
									<button type="submit" class="button button-secondary cb-core-button"><?php esc_html_e( 'Delete custom item', 'core-blueprint' ); ?></button>
								</div>
								<p class="description"><?php esc_html_e( 'This removes only the custom registry item and its assignment. The selected WordPress page or document is not deleted.', 'core-blueprint' ); ?></p>
							</form>
						</div>
					</details>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private function render_add_custom( string $owner ): void {
		?>
		<details class="cb-core-disclosure cb-core-disclosure--compact">
			<summary class="cb-core-disclosure__summary">
				<span class="cb-core-disclosure__icon">
					<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted icon registry. ?>
				</span>
				<span class="cb-core-disclosure__title"><?php esc_html_e( 'Add organisation-specific item', 'core-blueprint' ); ?></span>
			</summary>
			<div class="cb-core-disclosure__body">
				<form class="cb-core-stack" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="<?php echo esc_attr( Actions::ADD_ACTION ); ?>">
					<input type="hidden" name="owner" value="<?php echo esc_attr( $owner ); ?>">
					<?php wp_nonce_field( 'cb_core_compliance_add:' . $owner ); ?>
					<div class="cb-core-field">
						<label class="cb-core-field__label" for="cb-compliance-label-<?php echo esc_attr( sanitize_html_class( $owner ) ); ?>"><?php esc_html_e( 'Name', 'core-blueprint' ); ?></label>
						<input id="cb-compliance-label-<?php echo esc_attr( sanitize_html_class( $owner ) ); ?>" class="regular-text" type="text" name="label" maxlength="120" required>
					</div>
					<div class="cb-core-field">
						<label class="cb-core-field__label" for="cb-compliance-description-<?php echo esc_attr( sanitize_html_class( $owner ) ); ?>"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></label>
						<textarea id="cb-compliance-description-<?php echo esc_attr( sanitize_html_class( $owner ) ); ?>" class="large-text" name="description" rows="2" maxlength="500"></textarea>
					</div>
					<div class="cb-core-form-actions">
						<button type="submit" class="button button-secondary cb-core-button"><?php esc_html_e( 'Add item', 'core-blueprint' ); ?></button>
					</div>
				</form>
			</div>
		</details>
		<?php
	}

	/** @param array{type:string,object_id:int}|null $reference */
	private function render_object_picker( string $id, string $name, ?array $reference, string $search_nonce, string $meta_suffix = '' ): void {
		$item = Resolver::picker_item( $reference );
		if ( null !== $item && '' !== $meta_suffix ) {
			$item['meta'] .= ' · ' . $meta_suffix;
		}
		$selected = null === $item ? [] : [ $item ];

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
		echo ObjectPicker::render( [
			'id'            => $id,
			'name'          => $name,
			'multiple'      => false,
			'action'        => ObjectSearch::ACTION,
			'nonce'         => $search_nonce,
			'context'       => [],
			'selected'      => $selected,
			'placeholder'   => __( 'Search pages or documents…', 'core-blueprint' ),
			'empty_message' => __( 'No matching published pages or documents found.', 'core-blueprint' ),
			'show_hint'     => false,
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function requested_resource_key(): string {
		return isset( $_GET['cb_resource'] )
			? sanitize_text_field( (string) wp_unslash( $_GET['cb_resource'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presentation state.
			: '';
	}

	private function render_feedback(): void {
		$result = Actions::pull_result();
		$status = is_array( $result ) ? sanitize_key( (string) ( $result['status'] ?? '' ) ) : '';

		$success = [
			'saved'   => __( 'Compliance resource saved.', 'core-blueprint' ),
			'added'   => __( 'Custom compliance item added.', 'core-blueprint' ),
			'updated' => __( 'Custom compliance item updated.', 'core-blueprint' ),
			'deleted' => __( 'Custom compliance item deleted.', 'core-blueprint' ),
		];
		if ( isset( $success[ $status ] ) ) {
			?>
			<div data-cb-core-compliance-success="<?php echo esc_attr( $success[ $status ] ); ?>" hidden></div>
			<?php
			return;
		}

		$errors = [
			'invalid-resource'        => __( 'The selected page or document is not available.', 'core-blueprint' ),
			'invalid-locale'          => __( 'One of the locale codes is invalid.', 'core-blueprint' ),
			'invalid-locale-resource' => __( 'Add both a valid locale code and a valid page or document.', 'core-blueprint' ),
			'unknown-resource'        => __( 'That compliance resource is not registered.', 'core-blueprint' ),
			'save-failed'             => __( 'The compliance resource could not be saved.', 'core-blueprint' ),
			'add-failed'              => __( 'The custom compliance item could not be added.', 'core-blueprint' ),
			'update-failed'           => __( 'The custom compliance item could not be updated.', 'core-blueprint' ),
			'delete-failed'           => __( 'Only user-created compliance items can be deleted.', 'core-blueprint' ),
		];
		if ( ! isset( $errors[ $status ] ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
		echo Notice::render( [
			'variant' => Notice::ERROR,
			'message' => $errors[ $status ],
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
