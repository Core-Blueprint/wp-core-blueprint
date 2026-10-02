<?php
declare(strict_types=1);
/**
 * Authenticated Object Picker search for Compliance Resources.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Compliance\Admin;

use CB\Core\Compliance\Resolver;

defined( 'ABSPATH' ) || exit;

final class ObjectSearch {

	public const ACTION = 'cb_core_compliance_search_objects';
	public const NONCE  = 'cb_core_compliance_object_search';

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'search' ] );
	}

	public static function search(): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to search compliance resources.', 'core-blueprint' ) ], 403 );
		}

		$term = isset( $_POST['search'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['search'] ) ) : '';
		$term = trim( $term );
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( [ 'items' => [] ] );
		}

		$items = [];
		$pages = get_posts( [
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => 10,
			's'                => $term,
			'orderby'          => 'relevance',
			'order'            => 'DESC',
			'suppress_filters' => true,
		] );
		foreach ( $pages as $page ) {
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}
			$title = get_the_title( $page );
			$items[] = [
				'id'    => 'page:' . $page->ID,
				/* translators: %d: WordPress page ID. */
				'label' => '' !== trim( (string) $title ) ? (string) $title : sprintf( __( 'Page #%d', 'core-blueprint' ), $page->ID ),
				/* translators: %s: page permalink path. */
				'meta'  => sprintf( __( 'Published page · %s', 'core-blueprint' ), '/' . ltrim( (string) $page->post_name, '/' ) . '/' ),
			];
		}

		$attachments = get_posts( [
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => 30,
			's'                => $term,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => true,
		] );
		$document_count = 0;
		foreach ( $attachments as $attachment ) {
			if ( ! $attachment instanceof \WP_Post || $document_count >= 10 ) {
				continue;
			}
			$reference = [ 'type' => 'document', 'object_id' => (int) $attachment->ID ];
			if ( ! Resolver::is_valid_reference( $reference ) ) {
				continue;
			}
			$mime = (string) get_post_mime_type( $attachment->ID );
			$items[] = [
				'id'    => 'document:' . $attachment->ID,
				'label' => Resolver::label_for_reference( $reference ),
				/* translators: %s: document MIME type. */
				'meta'  => sprintf( __( 'Document · %s', 'core-blueprint' ), $mime ),
			];
			$document_count++;
		}

		wp_send_json_success( [ 'items' => $items ] );
	}
}
