<?php
declare(strict_types=1);

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable explicit acknowledgement boundary for consequential admin mutations.
 *
 * Consumers provide operation-specific copy while this component owns the
 * accessible checkbox markup and strict server-side confirmation contract.
 */
final class MutationAcknowledgement {
	public static function render( string $field_name, string $id, string $label, string $description = '' ): void {
		$field_name = sanitize_key( $field_name );
		$id = sanitize_html_class( $id );

		if ( '' === $field_name || '' === $id ) {
			throw new \InvalidArgumentException( 'Mutation acknowledgement field name and id are required.' );
		}

		$description_id = '' !== $description ? $id . '-description' : '';
		?>
		<div class="cb-core-field cb-core-mutation-acknowledgement">
			<div class="cb-core-field__control">
				<label class="cb-core-check-row" for="<?php echo esc_attr( $id ); ?>">
					<input
						id="<?php echo esc_attr( $id ); ?>"
						type="checkbox"
						name="<?php echo esc_attr( $field_name ); ?>"
						value="1"
						required
						<?php if ( '' !== $description_id ) : ?>aria-describedby="<?php echo esc_attr( $description_id ); ?>"<?php endif; ?>
					>
					<span class="cb-core-check-row__body">
						<strong><?php echo esc_html( $label ); ?></strong>
						<?php if ( '' !== $description ) : ?>
							<small id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $description ); ?></small>
						<?php endif; ?>
					</span>
				</label>
			</div>
		</div>
		<?php
	}

	public static function confirmed( mixed $value ): bool {
		return '1' === $value;
	}

	public static function require_confirmed( mixed $value, string $message ): void {
		if ( ! self::confirmed( $value ) ) {
			throw new \InvalidArgumentException( $message );
		}
	}
}
