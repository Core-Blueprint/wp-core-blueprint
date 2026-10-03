<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\Notes\Support;

defined( 'ABSPATH' ) || exit;

final class MarkdownPreview {
    public static function to_plain_text( string $content, int $word_limit = 26 ): string {
        $content = preg_replace( '/```[\s\S]*?```/m', ' ', $content ) ?? $content;
        $content = preg_replace( '/~~~[\s\S]*?~~~/m', ' ', $content ) ?? $content;
        $content = preg_replace( '/`([^`]*)`/', '$1', $content ) ?? $content;

        // Markdown images and links: keep the readable label, discard the target.
        $content = preg_replace( '/!\[([^\]]*)\]\([^\)]*\)/', '$1', $content ) ?? $content;
        $content = preg_replace( '/\[([^\]]+)\]\([^\)]*\)/', '$1', $content ) ?? $content;

        // Remove common block markers while keeping their text.
        $content = preg_replace( '/^\s{0,3}#{1,6}\s+/m', '', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}>\s?/m', '', $content ) ?? $content;
        $content = preg_replace( '/^\s*[-*+]\s+/m', '', $content ) ?? $content;
        $content = preg_replace( '/^\s*\d+\.\s+/m', '', $content ) ?? $content;
        $content = preg_replace( '/^\s*[-*_]{3,}\s*$/m', ' ', $content ) ?? $content;

        // Remove inline formatting markers.
        $content = str_replace( [ '**', '__', '*', '_', '~~' ], '', $content );

        $content = wp_strip_all_tags( $content );
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $content = preg_replace( '/\s+/', ' ', $content ) ?? $content;
        $content = trim( $content );

        if ( '' === $content ) {
            return __( 'No note content yet.', 'core-blueprint' );
        }

        return wp_trim_words( $content, $word_limit, '…' );
    }
}
