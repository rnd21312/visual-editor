<?php
/**
 * Locates the built editor and runtime files (frontend/vite.editor.config.ts → dist/).
 * Dev mode: define( 'SVE_VITE_DEV', true ) loads the editor from the Vite dev server (http://localhost:5175).
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private const EDITOR  = 'src/editor/main.tsx';
	private const RUNTIME = 'src/runtime/visual.ts';

	private static function is_dev(): bool {
		return defined( 'SVE_VITE_DEV' ) && SVE_VITE_DEV;
	}

	private static function dev_url(): string {
		return rtrim( defined( 'SVE_VITE_URL' ) ? (string) SVE_VITE_URL : 'http://localhost:5175', '/' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function manifest(): array {
		static $manifest = null;

		if ( null === $manifest ) {
			$manifest = array();
			$file     = SVE_DIR . 'dist/.vite/manifest.json';

			if ( is_readable( $file ) ) {
				$decoded = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $decoded ) ) {
					$manifest = $decoded;
				}
			}
		}

		return $manifest;
	}

	/**
	 * @param array<string, array<string, mixed>> $manifest Vite manifest.
	 * @param array<string, bool>                 $seen     Visited chunks.
	 * @return string[]
	 */
	private static function css( array $manifest, string $key, array &$seen = array() ): array {
		if ( isset( $seen[ $key ] ) || ! isset( $manifest[ $key ] ) ) {
			return array();
		}
		$seen[ $key ] = true;

		$css = (array) ( $manifest[ $key ]['css'] ?? array() );
		foreach ( (array) ( $manifest[ $key ]['imports'] ?? array() ) as $import ) {
			$css = array_merge( $css, self::css( $manifest, (string) $import, $seen ) );
		}

		return $css;
	}

	/**
	 * URL of the public-page runtime, or null when it has not been built.
	 */
	public static function runtime_url(): ?string {
		$chunk = self::manifest()[ self::RUNTIME ] ?? null;

		return null === $chunk ? null : SVE_URL . 'dist/' . $chunk['file'];
	}

	/**
	 * Prints the editor's tags straight into the standalone editor page.
	 */
	public static function print_editor(): void {
		if ( self::is_dev() ) {
			$url = self::dev_url();
			?>
<script type="module">
import RefreshRuntime from "<?php echo esc_url( $url ); ?>/@react-refresh";
RefreshRuntime.injectIntoGlobalHook(window);
window.$RefreshReg$ = () => {};
window.$RefreshSig$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
</script>
<script type="module" src="<?php echo esc_url( $url . '/@vite/client' ); ?>"></script>
<script type="module" src="<?php echo esc_url( $url . '/' . self::EDITOR ); ?>"></script>
			<?php
			return;
		}

		$manifest = self::manifest();
		$chunk    = $manifest[ self::EDITOR ] ?? null;
		if ( null === $chunk ) {
			echo '<p style="padding:24px;font:14px system-ui">' . esc_html__( 'The editor files are missing. Run "npm run build" in the frontend folder.', 'suntourz-visual-editor' ) . '</p>';
			return;
		}

		foreach ( array_values( array_unique( self::css( $manifest, self::EDITOR ) ) ) as $css ) {
			printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( SVE_URL . 'dist/' . $css ) );
		}
		printf( '<script type="module" src="%s"></script>' . "\n", esc_url( SVE_URL . 'dist/' . $chunk['file'] ) );
	}
}
