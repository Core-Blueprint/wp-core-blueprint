<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\TemplateLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable local wp-admin navigation only, never a mutation endpoint.
 * Consumer supplies translated labels and independently authorized destinations.
 */
final class TemplateNavigation {
	/** @var array{url:string,label:string}|null */
	public readonly ?array $preview;
	/** @var array{url:string,label:string}|null */
	public readonly ?array $manage;

	/**
	 * @param array{url:string,label:string}|null $preview
	 * @param array{url:string,label:string}|null $manage
	 */
	public function __construct( ?array $preview = null, ?array $manage = null ) {
		$this->preview = self::link( $preview );
		$this->manage  = self::link( $manage );
	}

	/** @return array{preview?:array{url:string,label:string},manage?:array{url:string,label:string}} */
	public function public_links( bool $include_manage ): array {
		$links = [];
		if ( null !== $this->preview ) {
			$links['preview'] = $this->preview;
		}
		if ( $include_manage && null !== $this->manage ) {
			$links['manage'] = $this->manage;
		}
		return $links;
	}

	/** @param array{url:string,label:string}|null $link
	 * @return array{url:string,label:string}|null
	 */
	private static function link( ?array $link ): ?array {
		if ( null === $link ) {
			return null;
		}
		$url = $link['url'] ?? null;
		$label = $link['label'] ?? null;
		if ( ! is_string( $url ) || ! is_string( $label )
			|| strlen( $url ) > 1024 || '' === $url
			|| strlen( $label ) > 120 || '' === trim( $label )
			|| trim( wp_strip_all_tags( $label ) ) !== $label ) {
			throw new \InvalidArgumentException( 'Invalid template navigation link.' );
		}
		$target = wp_parse_url( $url );
		$admin = wp_parse_url( admin_url() );
		if ( ! is_array( $target ) || ! is_array( $admin )
			|| ! isset( $target['scheme'], $target['host'], $target['path'] )
			|| ! isset( $admin['scheme'], $admin['host'], $admin['path'] )
			|| ! in_array( strtolower( $target['scheme'] ), [ 'http', 'https' ], true )
			|| strtolower( $target['scheme'] ) !== strtolower( $admin['scheme'] )
			|| strtolower( $target['host'] ) !== strtolower( $admin['host'] )
			|| (int) ( $target['port'] ?? ( 'https' === $target['scheme'] ? 443 : 80 ) )
				!== (int) ( $admin['port'] ?? ( 'https' === $admin['scheme'] ? 443 : 80 ) )
			|| isset( $target['user'] ) || isset( $target['pass'] ) || isset( $target['fragment'] )
			|| ! str_starts_with( $target['path'], $admin['path'] ) ) {
			throw new \InvalidArgumentException( 'Template navigation must stay within local wp-admin.' );
		}
		$path = rawurldecode( $target['path'] );
		if ( preg_match( '~(?:^|/)\.{1,2}(?:/|$)|\\\\|[\x00-\x1f\x7f]~', $path )
			|| in_array( basename( $path ), [ 'admin-ajax.php', 'admin-post.php', 'update.php' ], true ) ) {
			throw new \InvalidArgumentException( 'Template navigation cannot target unsafe routes.' );
		}
		return [ 'url' => $url, 'label' => $label ];
	}
}
