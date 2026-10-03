<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\Notes;

defined( 'ABSPATH' ) || exit;

final class MarkdownRenderer {
    public static function render( string $markdown ): string {
        $markdown = str_replace( [ "\r\n", "\r" ], "\n", $markdown );
        $markdown = trim( $markdown );

        if ( '' === $markdown ) {
            return '';
        }

        $lines      = explode( "\n", $markdown );
        $html       = '';
        $paragraph  = [];
        $in_ul      = false;
        $in_ol      = false;
        $in_code    = false;
        $code_lines = [];

        foreach ( $lines as $line ) {
            $raw_line = rtrim( (string) $line );
            $trimmed  = trim( $raw_line );

            if ( preg_match( '/^```/', $trimmed ) ) {
                if ( $in_code ) {
                    $html      .= '<pre><code>' . esc_html( implode( "\n", $code_lines ) ) . '</code></pre>';
                    $code_lines = [];
                    $in_code    = false;
                } else {
                    self::flush_paragraph( $html, $paragraph );
                    self::close_lists( $html, $in_ul, $in_ol );
                    $in_code = true;
                }
                continue;
            }

            if ( $in_code ) {
                $code_lines[] = $raw_line;
                continue;
            }

            if ( preg_match('/^>\s+(.+)$/', $trimmed, $m) ) {
                self::flush_paragraph($html,$paragraph);
                $html .= '<blockquote>' . self::inline($m[1]) . '</blockquote>';
                continue;
            }

            if ( preg_match('/^---+$/', $trimmed) ) {
                self::flush_paragraph($html,$paragraph);
                $html .= '<hr />';
                continue;
            }

            if ( '' === $trimmed ) {
                self::flush_paragraph( $html, $paragraph );
                self::close_lists( $html, $in_ul, $in_ol );
                continue;
            }

            if ( preg_match( '/^###\s+(.+)$/', $trimmed, $matches ) ) {
                self::flush_paragraph( $html, $paragraph );
                self::close_lists( $html, $in_ul, $in_ol );
                $html .= '<h4>' . self::inline( $matches[1] ) . '</h4>';
                continue;
            }

            if ( preg_match( '/^##\s+(.+)$/', $trimmed, $matches ) ) {
                self::flush_paragraph( $html, $paragraph );
                self::close_lists( $html, $in_ul, $in_ol );
                $html .= '<h3>' . self::inline( $matches[1] ) . '</h3>';
                continue;
            }

            if ( preg_match( '/^#\s+(.+)$/', $trimmed, $matches ) ) {
                self::flush_paragraph( $html, $paragraph );
                self::close_lists( $html, $in_ul, $in_ol );
                $html .= '<h2>' . self::inline( $matches[1] ) . '</h2>';
                continue;
            }

            if ( preg_match( '/^[-*]\s+\[( |x|X)?\]\s+(.+)$/', $trimmed, $matches ) ) {
                self::flush_paragraph( $html, $paragraph );

                if ( ! $in_ul ) {
                    self::close_lists( $html, $in_ul, $in_ol );
                    $html .= '<ul class="cb-notes-checklist">';
                    $in_ul = true;
                }

                $checked = isset( $matches[1] ) && in_array( strtolower( $matches[1] ), [ 'x' ], true );
                $html   .= '<li class="cb-notes-checklist-item">';
                $html   .= '<input type="checkbox" disabled ' . checked( $checked, true, false ) . ' />';
                $html   .= '<span>' . self::inline( $matches[2] ) . '</span>';
                $html   .= '</li>';
                continue;
            }

            if ( preg_match( '/^\[( |x|X)?\]\s+(.+)$/', $trimmed, $matches ) ) {
                self::flush_paragraph( $html, $paragraph );

                if ( ! $in_ul ) {
                    self::close_lists( $html, $in_ul, $in_ol );
                    $html .= '<ul class="cb-notes-checklist">';
                    $in_ul = true;
                }

                $checked = isset( $matches[1] ) && in_array( strtolower( $matches[1] ), [ 'x' ], true );
                $html   .= '<li class="cb-notes-checklist-item">';
                $html   .= '<input type="checkbox" disabled ' . checked( $checked, true, false ) . ' />';
                $html   .= '<span>' . self::inline( $matches[2] ) . '</span>';
                $html   .= '</li>';
                continue;
            }

            if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $matches ) ) {
                self::flush_paragraph( $html, $paragraph );

                if ( ! $in_ul ) {
                    self::close_lists( $html, $in_ul, $in_ol );
                    $html .= '<ul>';
                    $in_ul = true;
                }

                $html .= '<li>' . self::inline( $matches[1] ) . '</li>';
                continue;
            }

            if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $matches ) ) {
                self::flush_paragraph( $html, $paragraph );

                if ( ! $in_ol ) {
                    self::close_lists( $html, $in_ul, $in_ol );
                    $html .= '<ol>';
                    $in_ol = true;
                }

                $html .= '<li>' . self::inline( $matches[1] ) . '</li>';
                continue;
            }

            self::close_lists( $html, $in_ul, $in_ol );
            $paragraph[] = $trimmed;
        }

        if ( $in_code ) {
            $html .= '<pre><code>' . esc_html( implode( "\n", $code_lines ) ) . '</code></pre>';
        }

        self::flush_paragraph( $html, $paragraph );
        self::close_lists( $html, $in_ul, $in_ol );

        return wp_kses( $html, self::allowed_html() );
    }

    public static function highlight( string $html, string $query ): string {
        $query = trim( $query );

        if ( '' === $query ) {
            return $html;
        }

        $pattern = '/' . preg_quote( $query, '/' ) . '/i';

        return preg_replace_callback(
            $pattern,
            static function ( array $matches ): string {
                return '<mark>' . esc_html( $matches[0] ) . '</mark>';
            },
            $html
        ) ?? $html;
    }

    private static function flush_paragraph( string &$html, array &$paragraph ): void {
        if ( empty( $paragraph ) ) {
            return;
        }

        $html     .= '<p>' . implode( '<br />', array_map( [ self::class, 'inline' ], $paragraph ) ) . '</p>';
        $paragraph = [];
    }

    private static function close_lists( string &$html, bool &$in_ul, bool &$in_ol ): void {
        if ( $in_ul ) {
            $html .= '</ul>';
            $in_ul = false;
        }

        if ( $in_ol ) {
            $html .= '</ol>';
            $in_ol = false;
        }
    }

    private static function inline( string $text ): string {
        $text = esc_html( $text );

        $text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $text );

        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            static function ( array $matches ): string {
                return '<a href="' . esc_url( $matches[2] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $matches[1] ) . '</a>';
            },
            $text
        );

        return $text;
    }

    private static function allowed_html(): array {
        return [
            'a'      => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
            'br'     => [],
            'code'   => [],
            'em'     => [],
            'h2'     => [],
            'h3'     => [],
            'h4'     => [],
            'input'  => [
                'type'     => true,
                'disabled' => true,
                'checked'  => true,
            ],
            'li'     => [
                'class' => true,
            ],
            'mark'   => [],
            'ol'     => [],
            'p'      => [],
            'pre'    => [],
            'span'   => [],
            'strong' => [],
            'ul'     => [
                'class' => true,
            ],
        ];
    }
}
