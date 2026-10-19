<?php
/**
 * Everything the public site prints: design tokens and fonts, the compiled CSS of the edits that apply to the
 * current page, enabled libraries, custom scripts, and — only when the page needs it — the small runtime that
 * replays content edits, structural edits, motions, forms and React islands.
 *
 * Nothing here filters stored data again: the document was validated when it was saved, so a page view only
 * reads one option and selects what applies.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	/** Query argument the editor adds to its preview iframe: saved edits are left out, the editor supplies them live. */
	public const PREVIEW_ARG = 'sve';

	/** @var array<string, bool> Features found while rendering builder content (forms, islands). */
	private static array $features = array();

	private static bool $runtime_printed = false;

	public static function hooks(): void {
		add_action(
			'wp',
			static function (): void {
				if ( self::is_preview() ) {
					Store::use_working();
				}
			},
			0
		);
		add_action( 'wp_head', array( self::class, 'print_context' ), 1 );
		add_action( 'wp_head', array( self::class, 'print_tokens' ), 7 );
		add_action( 'wp_head', array( self::class, 'print_data' ), 98 );
		add_action( 'wp_head', array( self::class, 'print_css' ), 99 );
		add_action( 'wp_head', array( self::class, 'print_head_scripts' ), 100 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( self::class, 'print_footer' ), 99 );
		add_filter( 'show_admin_bar', array( self::class, 'hide_bar_in_preview' ) );
		add_filter( 'wp_robots', array( self::class, 'preview_robots' ) );
	}

	/**
	 * True while the visual editor previews the site.
	 */
	public static function is_preview(): bool {
		static $preview = null;
		if ( null === $preview ) {
			$preview = isset( $_GET[ self::PREVIEW_ARG ] ) && is_user_logged_in() && current_user_can( Store::capability() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return $preview;
	}

	public static function flag( string $feature ): void {
		self::$features[ $feature ] = true;
	}

	public static function hide_bar_in_preview( bool $show ): bool {
		// The editor's own preview and its screenshots for the AI show the page without the admin bar.
		return self::is_preview() || isset( $_GET['sve_shot'] ) ? false : $show; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * @param array<string, mixed> $robots Directives.
	 * @return array<string, mixed>
	 */
	public static function preview_robots( array $robots ): array {
		return self::is_preview() ? array_merge( $robots, array( 'noindex' => true ) ) : $robots;
	}

	/**
	 * Whether an edit or op belongs on the page being rendered.
	 *
	 * @param array<string, mixed> $item Edit or op.
	 */
	public static function applies( array $item, string $page, string $tpl ): bool {
		return match ( (string) ( $item['scope'] ?? 'page' ) ) {
			'site'     => true,
			'template' => '' !== $tpl && (string) ( $item['tpl'] ?? '' ) === $tpl,
			default    => (string) ( $item['page'] ?? '' ) === $page,
		};
	}

	/**
	 * Edits, ops and motions of the current page (computed once per request).
	 *
	 * @return array{edits: array<int, array<string, mixed>>, content: array<int, array<string, mixed>>, ops: array<int, array<string, mixed>>, motions: array<int, array<string, mixed>>}
	 */
	public static function page_items(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$doc  = Store::get();
		$page = PageKey::current();
		$tpl  = Templates::current()['key'];

		$edits   = array();
		$content = array();
		$motions = array();
		foreach ( $doc['edits'] as $edit ) {
			if ( ! self::applies( $edit, $page, $tpl ) ) {
				continue;
			}
			$edits[] = $edit;
			if ( isset( $edit['text'] ) || isset( $edit['html'] ) || ! empty( $edit['attrs'] ) ) {
				$item = array( 'sel' => $edit['sel'] );
				foreach ( array( 'text', 'html', 'attrs' ) as $key ) {
					if ( isset( $edit[ $key ] ) ) {
						$item[ $key ] = $edit[ $key ];
					}
				}
				if ( ! empty( $edit['trusted'] ) ) {
					$item['t'] = 1;
				}
				$content[] = $item;
			}
			if ( ! empty( $edit['motion'] ) ) {
				$motions[] = array(
					'sel' => $edit['sel'],
					'm'   => $edit['motion'],
				);
			}
		}

		$components = array();
		foreach ( $doc['components'] as $component ) {
			$components[ $component['id'] ] = $component;
		}
		$ops = array();
		foreach ( $doc['ops'] as $op ) {
			if ( ! self::applies( $op, $page, $tpl ) ) {
				continue;
			}
			// A component reference is replaced by its current markup, so the page needs no library.
			if ( 'insert' === $op['kind'] && ! empty( $op['comp'] ) && isset( $components[ $op['comp'] ] ) ) {
				$component  = $components[ $op['comp'] ];
				$op['html'] = Builder\Renderer::component_html( $component, (array) ( $op['props'] ?? array() ) );
				if ( 'embed' === $component['kind'] ) {
					$op['exec'] = true; // Embed snippets bring scripts that must run.
				}
				if ( 'react' === $component['kind'] ) {
					self::flag( 'islands' );
				}
			}
			if ( 'insert' === $op['kind'] && str_contains( (string) ( $op['html'] ?? '' ), 'data-sve-form' ) ) {
				self::flag( 'forms' );
			}
			unset( $op['label'], $op['scope'], $op['page'], $op['tpl'], $op['props'] );
			if ( ! empty( $op['trusted'] ) ) {
				$op['t'] = 1;
			}
			unset( $op['trusted'] );
			$ops[] = $op;
		}

		$cache = array(
			'edits'   => $edits,
			'content' => $content,
			'ops'     => $ops,
			'motions' => $motions,
		);

		return $cache;
	}

	/* ------------------------------------------------------------------ head */

	/**
	 * Only in the editor's preview: identity of the page (page key, template, builder sources) for the editor.
	 */
	public static function print_context(): void {
		if ( ! self::is_preview() && ! Inspector::is_probe() ) {
			return;
		}
		$template = Templates::current( true );
		$context  = array(
			'page'      => PageKey::current(),
			'template'  => $template,
			'post'      => is_singular() ? (int) get_queried_object_id() : 0,
			'postType'  => is_singular() ? (string) get_post_type() : '',
			'title'     => wp_get_document_title(),
			'builder'   => Builder\Router::context(),
			'rev'       => (int) Store::get()['rev'],
		);
		printf( '<meta name="sve-context" content="%s">' . "\n", esc_attr( (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * Design tokens (CSS variables) and web fonts. Printed in the preview too (the editor overrides them live).
	 */
	public static function print_tokens(): void {
		$tokens = (array) Store::get()['tokens'];
		$fonts  = (array) ( $tokens['fonts'] ?? array() );

		$google = array();
		$bunny  = array();
		foreach ( $fonts as $font ) {
			$weights = array() !== (array) $font['weights'] ? (array) $font['weights'] : array( 400, 700 );
			sort( $weights );
			$family = str_replace( ' ', '+', (string) $font['family'] ) . ':wght@' . implode( ';', array_unique( $weights ) );
			if ( 'google' === $font['source'] ) {
				$google[] = 'family=' . $family;
			} elseif ( 'bunny' === $font['source'] ) {
				$bunny[] = 'family=' . $family;
			} elseif ( 'url' === $font['source'] && ! empty( $font['url'] ) ) {
				printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( (string) $font['url'] ) );
			}
		}
		if ( array() !== $google ) {
			echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
			printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( 'https://fonts.googleapis.com/css2?' . implode( '&', $google ) . '&display=swap' ) );
		}
		if ( array() !== $bunny ) {
			echo '<link rel="preconnect" href="https://fonts.bunny.net">' . "\n";
			printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( 'https://fonts.bunny.net/css2?' . implode( '&', $bunny ) . '&display=swap' ) );
		}

		$vars = (array) ( $tokens['vars'] ?? array() );
		foreach ( $fonts as $font ) {
			if ( '' !== (string) $font['role'] ) {
				$vars[ '--sve-font-' . $font['role'] ] = '"' . $font['family'] . '", system-ui, sans-serif';
			}
		}
		if ( array() !== $vars ) {
			$css = '';
			foreach ( $vars as $name => $value ) {
				$css .= $name . ':' . $value . ';';
			}
			printf( '<style id="sve-tokens">:root{%s}</style>' . "\n", $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated on save ("<", braces and ";" removed).
		}
	}

	/**
	 * Compiled CSS: global CSS, Tailwind output, components used by structural edits, and the edits of this page.
	 */
	public static function print_css(): void {
		if ( is_admin() || self::is_preview() ) {
			return;
		}

		$doc   = Store::get();
		$items = self::page_items();
		$css   = array();
		if ( ! empty( $doc['tailwind']['enabled'] ) && '' !== trim( (string) $doc['tailwind']['css'] ) ) {
			$css[] = $doc['tailwind']['css'];
		}
		$used = array();
		foreach ( $doc['ops'] as $op ) {
			if ( ! empty( $op['comp'] ) ) {
				$used[ $op['comp'] ] = true;
			}
		}
		foreach ( $doc['components'] as $component ) {
			if ( isset( $used[ $component['id'] ] ) && '' !== trim( (string) $component['css'] ) ) {
				$css[] = $component['css'];
			}
		}
		if ( '' !== trim( (string) $doc['css'] ) ) {
			$css[] = $doc['css'];
		}
		foreach ( $items['edits'] as $edit ) {
			if ( '' !== trim( (string) ( $edit['compiled'] ?? '' ) ) ) {
				$css[] = $edit['compiled'];
			}
		}
		// Until the runtime has applied content edits, hide the elements they change (no flash of old content).
		if ( ! Ssr::active() ) {
			$pending = array();
			foreach ( $items['content'] as $item ) {
				if ( isset( $item['text'] ) || isset( $item['html'] ) ) {
					$pending[] = 'html.sve-pending ' . $item['sel'] . '{visibility:hidden}';
				}
			}
			foreach ( $items['ops'] as $op ) {
				if ( 'remove' === $op['kind'] ) {
					$pending[] = 'html.sve-pending ' . $op['sel'] . '{display:none}';
				}
			}
			$css = array_merge( $css, $pending );
		}

		if ( array() !== $css ) {
			printf( '<style id="sve-css">%s</style>' . "\n", implode( "\n", $css ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- "<" stripped on save.
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function payload(): array {
		$items = self::page_items();
		$doc   = Store::get();

		return array(
			'v'       => (int) $doc['rev'],
			'edits'   => $items['content'],
			'ops'     => $items['ops'],
			'motions' => $items['motions'],
			'reduced' => ! empty( $doc['settings']['reducedMotion'] ),
			'rest'    => esc_url_raw( rest_url( 'sve/v1/' ) ),
			'islands' => Islands::loader_url(),
		);
	}

	/**
	 * Data + runtime in <head>, so content edits are applied while the page is parsed.
	 */
	public static function print_data(): void {
		if ( is_admin() || self::is_preview() ) {
			return;
		}
		$items = self::page_items();
		if ( array() === $items['content'] && array() === $items['ops'] && array() === $items['motions'] ) {
			return;
		}
		self::print_runtime();
	}

	private static function print_runtime(): void {
		if ( self::$runtime_printed ) {
			return;
		}
		$code = Assets::runtime_code();
		if ( null === $code ) {
			return;
		}
		self::$runtime_printed = true;
		printf(
			'<script type="application/json" id="sve-data">%s</script>' . "\n",
			wp_json_encode( self::payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HEX-escaped JSON.
		);
		echo '<script id="sve-runtime">' . $code . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled file of this plugin.
	}

	/**
	 * Libraries (enabled ones + those the page needs: GSAP for motions, React for islands), and custom scripts.
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		$doc   = Store::get();
		$extra = array();
		if ( ! self::is_preview() && array() !== self::page_items()['motions'] ) {
			$extra[] = 'gsap';
			$extra[] = 'gsap-scrolltrigger';
		}
		if ( self::is_preview() ) {
			$extra[] = 'gsap'; // The editor previews motions.
			$extra[] = 'gsap-scrolltrigger';
		}
		Libraries::enqueue( Libraries::for_page( $doc, PageKey::current(), Templates::current()['key'], $extra ) );
		Islands::register();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function scripts( string $where ): array {
		$out  = array();
		$page = PageKey::current();
		$tpl  = Templates::current()['key'];
		foreach ( Store::get()['scripts'] as $script ) {
			if ( ! empty( $script['enabled'] ) && $where === $script['where'] && self::applies( $script, $page, $tpl ) ) {
				$out[] = $script;
			}
		}

		return $out;
	}

	private static function print_scripts( string $where ): void {
		foreach ( self::scripts( $where ) as $script ) {
			printf(
				'<script%s data-sve-script="%s">%s</script>' . "\n",
				! empty( $script['module'] ) ? ' type="module"' : '',
				esc_attr( (string) $script['id'] ),
				$script['code'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- administrators only (checked on save).
			);
		}
	}

	public static function print_head_scripts(): void {
		if ( ! is_admin() ) {
			self::print_scripts( 'head' );
		}
	}

	public static function print_footer(): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! self::is_preview() && ( ! empty( self::$features['forms'] ) || ! empty( self::$features['islands'] ) ) ) {
			self::print_runtime();
		}
		if ( ! empty( self::$features['islands'] ) || self::is_preview() ) {
			Islands::print_loader();
		}
		self::print_scripts( 'footer' );
		Builder\Renderer::print_component_scripts();
	}
}
