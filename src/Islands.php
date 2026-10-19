<?php
/**
 * React islands: components of kind "react" are compiled by the editor (JSX → ES module, npm imports resolved
 * through esm.sh with one shared React) and mounted on the public site where they are used. Everything else on
 * the page stays plain server-rendered HTML.
 *
 * Compiled modules are written to uploads/sve/islands/<id>-<hash>.js (cacheable static files); when uploads are
 * not writable they are served by the REST API instead.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Islands {

	private static bool $printed = false;

	public static function register(): void {
		// Islands need no registration: the loader imports React from esm.sh by absolute URL (no import map).
	}

	public static function loader_url(): ?string {
		return null;
	}

	/**
	 * Rewrites bare imports of an ES module to esm.sh URLs that share one React (no import map needed).
	 * "react" → https://esm.sh/react@18.3.1, "@radix-ui/react-tabs" → https://esm.sh/@radix-ui/react-tabs?deps=react@…
	 */
	public static function resolve_imports( string $code ): string {
		$v   = Libraries::REACT;
		$map = array(
			'react'             => "https://esm.sh/react@{$v}",
			'react/jsx-runtime' => "https://esm.sh/react@{$v}/jsx-runtime",
			'react-dom'         => "https://esm.sh/react-dom@{$v}",
			'react-dom/client'  => "https://esm.sh/react-dom@{$v}/client",
		);

		return (string) preg_replace_callback(
			'/(\bfrom\s*|\bimport\s*\(\s*|\bimport\s+)(["\'])([^"\'\s]+)\2/',
			static function ( array $m ) use ( $map, $v ): string {
				$spec = $m[3];
				if ( preg_match( '#^(https?:|/|\./|\.\./|data:)#', $spec ) ) {
					return $m[0];
				}
				$url = $map[ $spec ] ?? 'https://esm.sh/' . $spec . ( str_contains( $spec, '?' ) ? '&' : '?' ) . "deps=react@{$v},react-dom@{$v}";

				return $m[1] . $m[2] . $url . $m[2];
			},
			$code
		);
	}

	/**
	 * Markup of one island: server-rendered fallback inside a mount point.
	 *
	 * @param array<string, mixed> $component Component.
	 * @param array<string, mixed> $props     Props.
	 */
	public static function markup( array $component, array $props, string $fallback = '' ): string {
		Frontend::flag( 'islands' );

		return sprintf(
			'<div class="sve-island" data-sve-island="%1$s" data-src="%2$s" data-props="%3$s">%4$s</div>',
			esc_attr( (string) $component['id'] ),
			esc_url( self::module_url( $component ) ),
			esc_attr( (string) wp_json_encode( (object) $props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			$fallback
		);
	}

	/**
	 * URL of the compiled module of a component.
	 *
	 * @param array<string, mixed> $component Component.
	 */
	public static function module_url( array $component ): string {
		$code = (string) ( $component['compiled'] ?? '' );
		$hash = substr( md5( $code ), 0, 10 );
		$id   = (string) $component['id'];
		$dir  = self::dir();
		if ( null !== $dir ) {
			$file = $dir['path'] . '/' . $id . '-' . $hash . '.js';
			if ( ! file_exists( $file ) && '' !== $code ) {
				foreach ( (array) glob( $dir['path'] . '/' . $id . '-*.js' ) as $old ) {
					wp_delete_file( (string) $old );
				}
				file_put_contents( $file, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			if ( file_exists( $file ) ) {
				return $dir['url'] . '/' . $id . '-' . $hash . '.js';
			}
		}

		return add_query_arg( 'h', $hash, rest_url( 'sve/v1/island/' . $id ) );
	}

	/**
	 * @return array{path: string, url: string}|null
	 */
	private static function dir(): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$path = $uploads['basedir'] . '/sve/islands';
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return null;
		}
		if ( ! wp_is_writable( $path ) ) {
			return null;
		}

		return array(
			'path' => $path,
			'url'  => set_url_scheme( $uploads['baseurl'] . '/sve/islands' ),
		);
	}

	/**
	 * Serves a compiled module through the REST API (fallback when uploads are not writable).
	 */
	public static function serve( string $id ): void {
		foreach ( Store::get()['components'] as $component ) {
			if ( $component['id'] === $id && 'react' === $component['kind'] ) {
				header( 'Content-Type: text/javascript; charset=utf-8' );
				header( 'Cache-Control: public, max-age=31536000, immutable' );
				echo (string) $component['compiled']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript module of an administrator.
				exit;
			}
		}
		status_header( 404 );
		exit;
	}

	/**
	 * Mounts every island of the page (once React is downloaded). Islands far below the fold mount when they
	 * come near the viewport (data-load="visible"), others immediately.
	 */
	public static function print_loader(): void {
		if ( self::$printed ) {
			return;
		}
		self::$printed = true;
		$react         = 'https://esm.sh/react@' . Libraries::REACT;
		$dom           = 'https://esm.sh/react-dom@' . Libraries::REACT . '/client';
		?>
<script type="module" id="sve-islands">
let lib=null;const react=()=>lib||(lib=Promise.all([import(<?php echo wp_json_encode( $react ); ?>),import(<?php echo wp_json_encode( $dom ); ?>)]));
const mount=async el=>{if(el.dataset.mounted)return;el.dataset.mounted='1';try{const [[R,D],m]=await Promise.all([react(),import(el.dataset.src)]);const C=m.default||Object.values(m).find(v=>typeof v==='function');let p={};try{p=JSON.parse(el.dataset.props||'{}')}catch(e){}
if(el.__sveRoot)el.__sveRoot.unmount();el.__sveRoot=D.createRoot(el);el.__sveRoot.render(R.createElement(C,p));}catch(e){console.error('[sve island]',el.dataset.sveIsland,e);el.dataset.mounted='error';}};
const io='IntersectionObserver'in window?new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){io.unobserve(e.target);mount(e.target)}}),{rootMargin:'300px'}):null;
window.__sveMountIslands=(root=document)=>root.querySelectorAll('[data-sve-island]:not([data-mounted])').forEach(el=>el.dataset.load==='visible'&&io?io.observe(el):mount(el));
window.__sveMountIslands();
</script>
		<?php
	}
}
