<?php
/**
 * Validates the editor document.
 *
 * Runs only when something is written (never on page views). Items that arrive unchanged keep their stored
 * version untouched, so a save by someone who may not post unfiltered HTML (an editor on multisite, an AI key)
 * never strips the scripts or embeds an administrator added. Changed or new items are filtered for the author
 * that sends them and remember whether that author was trusted.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Sanitizer {

	public const LIMITS = array(
		'edits'      => 3000,
		'ops'        => 3000,
		'components' => 500,
		'seo'        => 5000,
		'scripts'    => 200,
		'libraries'  => 200,
	);

	private const DEVICES = array( 'base', 'tablet', 'mobile' );

	/** Attributes edits may set. Event handlers, style and srcdoc are never allowed. */
	private const ATTRS = array( 'href', 'src', 'srcset', 'sizes', 'alt', 'title', 'target', 'rel', 'placeholder', 'aria-label', 'aria-hidden', 'aria-expanded', 'aria-controls', 'role', 'id', 'class', 'name', 'value', 'type', 'loading', 'decoding', 'fetchpriority', 'width', 'height', 'poster', 'download', 'tabindex', 'hidden', 'colspan', 'rowspan', 'for', 'action', 'method', 'required', 'disabled', 'checked', 'selected', 'autoplay', 'muted', 'loop', 'controls', 'playsinline', 'datetime', 'lang', 'dir' );

	private const URL_ATTRS = array( 'href', 'src', 'poster', 'action' );

	private const TAGS = array( 'div', 'section', 'article', 'aside', 'nav', 'header', 'footer', 'main', 'figure', 'figcaption', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'a', 'button', 'label', 'strong', 'em', 'small', 'address', 'details', 'summary' );

	private const POSITIONS = array( 'before', 'after', 'prepend', 'append' );

	private const MOTION_PRESETS = array( 'fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in', 'zoom-out', 'flip-up', 'rotate-in', 'blur-in', 'slide-up', 'slide-down', 'slide-left', 'slide-right', 'parallax', 'float', 'pulse', 'spin', 'stagger-children', 'reveal-text', 'custom' );

	/** @var array<int, string> */
	private array $warnings = array();

	private bool $trusted;

	/** @var array<string, array<string, array<string, mixed>>> list => id => previous item */
	private array $previous = array();

	/**
	 * @param bool                      $trusted  Whether the author may post unfiltered HTML / code.
	 * @param array<string, mixed>|null $previous Currently stored document (unchanged items are kept as they are).
	 */
	public function __construct( bool $trusted, ?array $previous = null ) {
		$this->trusted = $trusted;
		foreach ( array( 'edits', 'ops', 'components', 'scripts' ) as $list ) {
			foreach ( (array) ( $previous[ $list ] ?? array() ) as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$this->previous[ $list ][ (string) $item['id'] ] = $item;
				}
			}
		}
	}

	/**
	 * @return array<int, string>
	 */
	public function warnings(): array {
		return array_values( array_unique( $this->warnings ) );
	}

	/**
	 * Whether a user may post unfiltered markup and code.
	 */
	public static function user_trusted(): bool {
		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * @param array<string, mixed> $input Raw document.
	 * @return array<string, mixed>
	 */
	public function document( array $input ): array {
		$settings = $this->settings( $input['settings'] ?? array() );
		Compiler::set_breakpoints( $settings['breakpoints'] );

		return array(
			'v'          => 2,
			'rev'        => (int) ( $input['rev'] ?? 0 ),
			'css'        => Html::css( $input['css'] ?? '', $this->trusted ),
			'edits'      => $this->each( 'edits', $input['edits'] ?? array(), array( $this, 'edit' ) ),
			'ops'        => $this->each( 'ops', $input['ops'] ?? array(), array( $this, 'op' ) ),
			'components' => $this->each( 'components', $input['components'] ?? array(), array( $this, 'component' ) ),
			'scripts'    => $this->each( 'scripts', $input['scripts'] ?? array(), array( $this, 'script' ) ),
			'libraries'  => $this->libraries( $input['libraries'] ?? array() ),
			'seo'        => $this->seo( $input['seo'] ?? array() ),
			'tokens'     => $this->tokens( $input['tokens'] ?? array() ),
			'tailwind'   => $this->tailwind( $input['tailwind'] ?? array() ),
			'settings'   => $settings,
			'access'     => 'editor' === ( $input['access'] ?? '' ) ? 'editor' : 'admin',
		);
	}

	/**
	 * Fills defaults of a stored document without filtering it again (stored data was validated when written).
	 *
	 * @param array<string, mixed> $doc Stored document.
	 * @return array<string, mixed>
	 */
	public static function shape( array $doc ): array {
		$out = array_merge(
			array(
				'v'          => 2,
				'rev'        => 0,
				'css'        => '',
				'edits'      => array(),
				'ops'        => array(),
				'components' => array(),
				'scripts'    => array(),
				'libraries'  => array(),
				'seo'        => array(),
				'tokens'     => array( 'vars' => array(), 'fonts' => array() ),
				'tailwind'   => array( 'enabled' => false, 'css' => '' ),
				'settings'   => self::default_settings(),
				'access'     => 'admin',
			),
			$doc
		);
		foreach ( array( 'edits', 'ops', 'components', 'scripts', 'libraries' ) as $list ) {
			$out[ $list ] = is_array( $out[ $list ] ) ? array_values( $out[ $list ] ) : array();
		}
		if ( (int) ( $doc['v'] ?? 1 ) < 2 ) {
			// v1 stored percent-encoded page keys; v2 stores decoded ones.
			foreach ( array( 'edits', 'ops' ) as $list ) {
				foreach ( $out[ $list ] as $i => $item ) {
					if ( is_array( $item ) && isset( $item['page'] ) ) {
						$out[ $list ][ $i ]['page'] = PageKey::sanitize( (string) $item['page'] );
					}
				}
			}
			$seo = array();
			foreach ( (array) $out['seo'] as $key => $value ) {
				$seo[ str_starts_with( (string) $key, 'tpl:' ) ? (string) $key : PageKey::sanitize( (string) $key ) ] = $value;
			}
			$out['seo'] = $seo;
			$out['v']   = 2;
		}
		$out['settings'] = array_merge( self::default_settings(), is_array( $out['settings'] ) ? $out['settings'] : array() );

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'ssr'           => 'auto',
			'reducedMotion' => true,
			'breakpoints'   => array( 'tablet' => 1023, 'mobile' => 767 ),
		);
	}

	/**
	 * Runs `$fn` on every item of a list, keeping unchanged stored items as they are.
	 *
	 * @param mixed    $raw Raw list.
	 * @param callable $fn  Item sanitizer.
	 * @return array<int, array<string, mixed>>
	 */
	private function each( string $list, mixed $raw, callable $fn ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$limit = self::LIMITS[ $list ];
		if ( count( $raw ) > $limit ) {
			/* translators: 1: number, 2: list name */
			$this->warnings[] = sprintf( __( 'Only the first %1$d %2$s were kept.', 'visual-editor' ), $limit, $list );
		}

		$out  = array();
		$seen = array();
		foreach ( array_slice( array_values( $raw ), 0, $limit ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id   = isset( $item['id'] ) ? (string) $item['id'] : '';
			$prev = '' !== $id ? ( $this->previous[ $list ][ $id ] ?? null ) : null;
			if ( null !== $prev && self::same( $prev, $item ) ) {
				$clean = $prev;
			} else {
				$clean = $fn( $item, $prev );
			}
			if ( null === $clean || isset( $seen[ $clean['id'] ] ) ) {
				continue;
			}
			$seen[ $clean['id'] ] = true;
			$out[]                = $clean;
		}

		return $out;
	}

	/**
	 * Whether an incoming item equals the stored one (ignoring the trust flag, which only the server sets).
	 *
	 * @param array<string, mixed> $a Stored.
	 * @param array<string, mixed> $b Incoming.
	 */
	public static function same( array $a, array $b ): bool {
		unset( $a['trusted'], $b['trusted'] );

		return self::canonical( $a ) === self::canonical( $b );
	}

	private static function canonical( mixed $value ): string {
		$sort = static function ( mixed $v ) use ( &$sort ): mixed {
			if ( ! is_array( $v ) ) {
				return $v;
			}
			if ( ! array_is_list( $v ) ) {
				ksort( $v );
			}

			return array_map( $sort, $v );
		};

		return (string) wp_json_encode( $sort( $value ) );
	}

	/* ------------------------------------------------------------------ small values */

	public function id( mixed $value ): string {
		$id = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', is_scalar( $value ) ? (string) $value : '' ), 0, 40 );

		return '' === $id ? self::new_id() : $id;
	}

	public static function new_id(): string {
		return strtolower( wp_generate_password( 10, false ) );
	}

	public static function selector( mixed $value ): string {
		$sel = trim( str_replace( array( '<', '{', '}', ';', "\0" ), '', is_string( $value ) ? $value : '' ) );

		return strlen( $sel ) > 1500 ? '' : $sel;
	}

	public static function scope( mixed $value ): string {
		return in_array( $value, array( 'site', 'template' ), true ) ? (string) $value : 'page';
	}

	/** Template key such as "single:product" or "tax:category". */
	public static function tpl( mixed $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9:_\-.\/]/', '', is_string( $value ) ? $value : '' ), 0, 120 );
	}

	private function label( mixed $value, int $max = 120 ): string {
		return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $max );
	}

	/**
	 * @param array<string, mixed> $item Raw edit or op.
	 * @return array{scope: string, page: string, tpl: string}
	 */
	private function target( array $item ): array {
		return array(
			'scope' => self::scope( $item['scope'] ?? '' ),
			'page'  => PageKey::sanitize( is_string( $item['page'] ?? null ) ? $item['page'] : '/' ),
			'tpl'   => self::tpl( $item['tpl'] ?? '' ),
		);
	}

	/* ------------------------------------------------------------------ edits */

	/**
	 * @param array<string, mixed>      $raw  Raw edit.
	 * @param array<string, mixed>|null $prev Stored version.
	 * @return array<string, mixed>|null
	 */
	public function edit( array $raw, ?array $prev = null ): ?array {
		$sel = self::selector( $raw['sel'] ?? '' );
		if ( '' === $sel ) {
			return null;
		}

		$edit = array_merge(
			array( 'id' => $this->id( $raw['id'] ?? '' ) ),
			$this->target( $raw ),
			array(
				'sel'      => $sel,
				'label'    => $this->label( $raw['label'] ?? '' ),
				'styles'   => $this->styles( $raw['styles'] ?? array() ),
				'css'      => Html::css( $raw['css'] ?? '', $this->trusted, 50000 ),
				'compiled' => '',
			)
		);

		$states = array();
		foreach ( array( 'hover', 'focus', 'active', 'focus-visible', 'focus-within', 'before', 'after', 'placeholder', 'first-child', 'last-child' ) as $state ) {
			$styles = $this->styles( $raw['states'][ $state ] ?? array() );
			if ( array() !== $styles ) {
				$states[ $state ] = $styles;
			}
		}
		if ( array() !== $states ) {
			$edit['states'] = $states;
		}

		$hide = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $key ) {
			if ( ! empty( $raw['hide'][ $key ] ) ) {
				$hide[ $key ] = true;
			}
		}
		if ( array() !== $hide ) {
			$edit['hide'] = $hide;
		}

		if ( isset( $raw['text'] ) && is_string( $raw['text'] ) ) {
			$edit['text'] = mb_substr( (string) wp_check_invalid_utf8( $raw['text'] ), 0, 20000 );
		}
		if ( isset( $raw['html'] ) && is_string( $raw['html'] ) ) {
			$edit['html'] = Html::clean( $raw['html'], $this->trusted, 100000 );
		}

		$attrs = $this->attrs( $raw['attrs'] ?? array() );
		if ( array() !== $attrs ) {
			$edit['attrs'] = $attrs;
		}

		if ( ! $this->trusted && Guard::protected_target( $sel ) && ( isset( $edit['text'] ) || isset( $edit['html'] ) || isset( $edit['attrs'] ) ) ) {
			unset( $edit['text'], $edit['html'], $edit['attrs'] );
			$this->warnings[] = __( 'The content of scripts, styles and meta tags can only be changed by administrators.', 'visual-editor' );
		}

		$motion = $this->motion( $raw['motion'] ?? null );
		if ( null !== $motion ) {
			$edit['motion'] = $motion;
		}

		$edit['compiled'] = Compiler::compile( $edit );
		if ( $this->trusted ) {
			$edit['trusted'] = true;
		}

		return $edit;
	}

	/**
	 * @param mixed $raw Device → property → value.
	 * @return array<string, array<string, string>>
	 */
	public function styles( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( self::DEVICES as $device ) {
			$props = array();
			foreach ( array_slice( (array) ( $raw[ $device ] ?? array() ), 0, 200, true ) as $prop => $value ) {
				$prop = strtolower( trim( (string) $prop ) );
				if ( ! preg_match( '/^(--[a-z0-9_-]{1,80}|-?[a-z][a-z-]{0,60})$/', $prop ) || ! is_scalar( $value ) ) {
					continue;
				}
				$value = trim( str_replace( array( '<', '{', '}', ';', "\0" ), '', (string) $value ) );
				if ( ! $this->trusted && preg_match( '/expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding/i', $value ) ) {
					continue;
				}
				if ( '' !== $value && strlen( $value ) <= 2000 ) {
					$props[ $prop ] = $value;
				}
			}
			if ( array() !== $props ) {
				$out[ $device ] = $props;
			}
		}

		return $out;
	}

	/**
	 * @param mixed $raw Attribute → value (null removes the attribute).
	 * @return array<string, string|null>
	 */
	private function attrs( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( array_slice( $raw, 0, 60, true ) as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( ! in_array( $name, self::ATTRS, true ) && ! preg_match( '/^(data|aria)-[a-z0-9_.-]+$/', $name ) ) {
				continue;
			}
			if ( null === $value ) {
				$out[ $name ] = null;
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = mb_substr( (string) $value, 0, 4000 );
			if ( in_array( $name, self::URL_ATTRS, true ) ) {
				$value = '' === $value ? '' : esc_url_raw( $value );
			} elseif ( 'srcset' === $name ) {
				$value = implode( ', ', array_map( static fn ( string $part ): string => (string) preg_replace_callback( '/^\s*(\S+)/', static fn ( array $m ): string => esc_url_raw( $m[1] ), $part ), explode( ',', $value ) ) );
			} else {
				$value = sanitize_text_field( $value );
			}
			$out[ $name ] = $value;
		}

		return $out;
	}

	/**
	 * Scroll / load / hover animation of an element (played by GSAP on the public site).
	 *
	 * @param mixed $raw Raw motion.
	 * @return array<string, mixed>|null
	 */
	public function motion( mixed $raw ): ?array {
		if ( ! is_array( $raw ) || empty( $raw['preset'] ) ) {
			return null;
		}
		$preset = (string) $raw['preset'];
		if ( ! in_array( $preset, self::MOTION_PRESETS, true ) ) {
			return null;
		}
		$num   = static fn ( mixed $v, float $min, float $max, float $def ): float => is_numeric( $v ) ? max( $min, min( $max, (float) $v ) ) : $def;
		$ease  = preg_replace( '/[^a-zA-Z0-9.()\-,]/', '', (string) ( $raw['ease'] ?? 'power2.out' ) );
		$out   = array(
			'preset'   => $preset,
			'trigger'  => in_array( $raw['trigger'] ?? '', array( 'load', 'scroll', 'hover', 'click', 'scrub' ), true ) ? (string) $raw['trigger'] : 'scroll',
			'duration' => $num( $raw['duration'] ?? null, 0, 20, 0.8 ),
			'delay'    => $num( $raw['delay'] ?? null, 0, 20, 0 ),
			'stagger'  => $num( $raw['stagger'] ?? null, 0, 5, 0 ),
			'distance' => $num( $raw['distance'] ?? null, 0, 2000, 40 ),
			'repeat'   => (int) $num( $raw['repeat'] ?? null, -1, 100, 0 ),
			'yoyo'     => ! empty( $raw['yoyo'] ),
			'once'     => ! isset( $raw['once'] ) || ! empty( $raw['once'] ),
			'ease'     => '' !== $ease ? substr( (string) $ease, 0, 40 ) : 'power2.out',
			'start'    => substr( (string) preg_replace( '/[^a-zA-Z0-9%\s+\-=]/', '', (string) ( $raw['start'] ?? 'top 85%' ) ), 0, 40 ),
		);
		if ( 'custom' === $preset ) {
			foreach ( array( 'from', 'to' ) as $key ) {
				$vars = array();
				foreach ( (array) ( $raw[ $key ] ?? array() ) as $prop => $value ) {
					if ( preg_match( '/^[a-zA-Z]{1,30}$/', (string) $prop ) && is_scalar( $value ) ) {
						$vars[ (string) $prop ] = is_numeric( $value ) ? (float) $value : substr( (string) preg_replace( '/[<>{};]/', '', (string) $value ), 0, 120 );
					}
				}
				$out[ $key ] = $vars;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ structural ops */

	/**
	 * @param array<string, mixed>      $item Raw op.
	 * @param array<string, mixed>|null $prev Stored version.
	 * @return array<string, mixed>|null
	 */
	public function op( array $item, ?array $prev = null ): ?array {
		$kind = (string) ( $item['kind'] ?? '' );
		$sel  = self::selector( $item['sel'] ?? '' );
		if ( '' === $sel || ! in_array( $kind, array( 'insert', 'move', 'remove', 'tag' ), true ) ) {
			return null;
		}

		$op  = array_merge(
			array(
				'id'   => $this->id( $item['id'] ?? '' ),
				'kind' => $kind,
			),
			$this->target( $item ),
			array(
				'label' => $this->label( $item['label'] ?? '' ),
				'sel'   => $sel,
			)
		);
		$pos = (string) ( $item['pos'] ?? 'after' );
		$pos = in_array( $pos, self::POSITIONS, true ) ? $pos : 'after';

		if ( 'insert' === $kind ) {
			$op['pos']  = $pos;
			$op['html'] = Html::clean( is_string( $item['html'] ?? null ) ? $item['html'] : '', $this->trusted, 100000 );
			if ( ! empty( $item['comp'] ) ) {
				$op['comp'] = $this->id( $item['comp'] );
				if ( isset( $item['props'] ) && is_array( $item['props'] ) ) {
					$op['props'] = $this->props_values( $item['props'] );
				}
			}
			// Embed snippets may run scripts, which only trusted authors can add.
			if ( ! empty( $item['exec'] ) && $this->trusted ) {
				$op['exec'] = true;
			} elseif ( ! empty( $item['exec'] ) ) {
				$this->warnings[] = __( 'Scripts in inserted markup were removed: only administrators can add them.', 'visual-editor' );
			}
			if ( '' === trim( $op['html'] ) && empty( $op['comp'] ) ) {
				return null;
			}
		} elseif ( 'move' === $kind ) {
			$to = self::selector( $item['to'] ?? '' );
			if ( '' === $to ) {
				return null;
			}
			$op['to']  = $to;
			$op['pos'] = $pos;
		} elseif ( 'tag' === $kind ) {
			$tag = strtolower( (string) ( $item['tag'] ?? '' ) );
			if ( ! in_array( $tag, self::TAGS, true ) ) {
				return null;
			}
			$op['tag'] = $tag;
		}
		if ( $this->trusted ) {
			$op['trusted'] = true;
		}

		return $op;
	}

	/**
	 * @param array<string, mixed> $raw Prop values.
	 * @return array<string, mixed>
	 */
	public function props_values( array $raw ): array {
		$out = array();
		foreach ( array_slice( $raw, 0, 60, true ) as $name => $value ) {
			$name = (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
			if ( '' === $name ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $name ] = json_decode( (string) wp_json_encode( $value ), true );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $name ] = is_string( $value ) ? Html::clean( $value, $this->trusted, 20000 ) : $value;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ components, scripts, libraries */

	/**
	 * @param array<string, mixed>      $item Raw component.
	 * @param array<string, mixed>|null $prev Stored version.
	 * @return array<string, mixed>|null
	 */
	public function component( array $item, ?array $prev = null ): ?array {
		$kind = (string) ( $item['kind'] ?? 'html' );
		$kind = in_array( $kind, array( 'html', 'section', 'embed', 'jsonld', 'react' ), true ) ? $kind : 'html';
		if ( 'embed' === $kind && ! $this->trusted ) {
			$this->warnings[] = __( 'Embed components run scripts: only administrators can create or change them.', 'visual-editor' );
			return $prev;
		}
		$name = $this->label( $item['name'] ?? '', 80 );
		if ( '' === $name ) {
			$name = __( 'Component', 'visual-editor' );
		}

		$props = array();
		foreach ( array_slice( (array) ( $item['props'] ?? array() ), 0, 40 ) as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$prop_name = (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $prop['name'] ?? '' ) );
			if ( '' === $prop_name ) {
				continue;
			}
			$type    = (string) ( $prop['type'] ?? 'text' );
			$props[] = array(
				'name'    => $prop_name,
				'label'   => $this->label( $prop['label'] ?? $prop_name, 80 ),
				'type'    => in_array( $type, array( 'text', 'html', 'url', 'image', 'number', 'bool', 'color', 'select', 'json', 'list' ), true ) ? $type : 'text',
				'default' => is_scalar( $prop['default'] ?? null ) ? Html::clean( (string) $prop['default'], $this->trusted, 20000 ) : ( is_array( $prop['default'] ?? null ) ? json_decode( (string) wp_json_encode( $prop['default'] ), true ) : '' ),
				'options' => array_values( array_map( 'strval', array_slice( (array) ( $prop['options'] ?? array() ), 0, 50 ) ) ),
			);
		}

		$out = array(
			'id'       => $this->id( $item['id'] ?? '' ),
			'name'     => $name,
			'kind'     => $kind,
			'category' => $this->label( $item['category'] ?? '', 40 ),
			'html'     => 'jsonld' === $kind ? substr( is_string( $item['html'] ?? null ) ? $item['html'] : '', 0, 100000 ) : Html::clean( is_string( $item['html'] ?? null ) ? $item['html'] : '', $this->trusted, 200000 ),
			'css'      => Html::css( $item['css'] ?? '', $this->trusted, 100000 ),
			'props'    => $props,
			'updated'  => time(),
		);
		if ( 'jsonld' === $kind && ! is_array( json_decode( $out['html'], true ) ) ) {
			$out['html'] = '{}';
		}

		$js = Html::code( $item['js'] ?? null, $this->trusted );
		if ( null !== $js && '' !== trim( $js ) ) {
			$out['js'] = $js;
		} elseif ( isset( $item['js'] ) && '' !== trim( (string) $item['js'] ) && ! $this->trusted ) {
			if ( isset( $prev['js'] ) ) {
				$out['js'] = $prev['js'];
			}
			$this->warnings[] = __( 'Component JavaScript can only be changed by administrators.', 'visual-editor' );
		}

		if ( 'react' === $kind ) {
			$jsx      = is_string( $item['jsx'] ?? null ) ? substr( $item['jsx'], 0, 200000 ) : '';
			$compiled = is_string( $item['compiled'] ?? null ) ? substr( $item['compiled'], 0, 400000 ) : '';
			if ( ! $this->trusted && ( $jsx !== ( $prev['jsx'] ?? '' ) || $compiled !== ( $prev['compiled'] ?? '' ) ) ) {
				// React components run code in visitors' browsers.
				$this->warnings[] = __( 'React components run code: only administrators can create or change them.', 'visual-editor' );

				return $prev;
			}
			$out['jsx']      = $jsx;
			$out['compiled'] = $compiled;
			$out['imports']  = array_values( array_filter( array_map( static fn ( mixed $v ): string => substr( (string) preg_replace( '/[^A-Za-z0-9@\/._\-]/', '', (string) $v ), 0, 120 ), array_slice( (array) ( $item['imports'] ?? array() ), 0, 40 ) ) ) );
		}
		if ( $this->trusted ) {
			$out['trusted'] = true;
		}

		return $out;
	}

	/**
	 * Custom JavaScript (only trusted authors).
	 *
	 * @param array<string, mixed>      $item Raw script.
	 * @param array<string, mixed>|null $prev Stored version.
	 * @return array<string, mixed>|null
	 */
	public function script( array $item, ?array $prev = null ): ?array {
		if ( ! $this->trusted ) {
			$this->warnings[] = __( 'Custom scripts can only be added or changed by administrators.', 'visual-editor' );

			return $prev;
		}

		return array_merge(
			array(
				'id'      => $this->id( $item['id'] ?? '' ),
				'label'   => $this->label( $item['label'] ?? '' ),
				'code'    => (string) Html::code( $item['code'] ?? '', true ),
				'where'   => 'head' === ( $item['where'] ?? '' ) ? 'head' : 'footer',
				'module'  => ! empty( $item['module'] ),
				'enabled' => ! isset( $item['enabled'] ) || ! empty( $item['enabled'] ),
			),
			$this->target( $item ),
			array( 'trusted' => true )
		);
	}

	/**
	 * Enabled libraries: catalog ids (see Libraries::catalog) or custom URLs.
	 *
	 * @param mixed $raw Raw list.
	 * @return array<int, array<string, mixed>>
	 */
	private function libraries( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$catalog = Libraries::catalog();
		foreach ( array_slice( array_values( $raw ), 0, self::LIMITS['libraries'] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $item['id'] ?? '' ) ) );
			if ( '' === $id ) {
				continue;
			}
			$lib = array(
				'id'      => $id,
				'scope'   => in_array( $item['scope'] ?? '', array( 'page', 'template' ), true ) ? (string) $item['scope'] : 'site',
				'pages'   => array_values( array_map( array( PageKey::class, 'sanitize' ), array_map( 'strval', array_slice( (array) ( $item['pages'] ?? array() ), 0, 200 ) ) ) ),
				'tpls'    => array_values( array_map( array( self::class, 'tpl' ), array_slice( (array) ( $item['tpls'] ?? array() ), 0, 50 ) ) ),
				'enabled' => ! isset( $item['enabled'] ) || ! empty( $item['enabled'] ),
			);
			if ( ! isset( $catalog[ $id ] ) ) {
				// Custom library: loading code from another server is an administrator decision.
				$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
				if ( '' === $url || ! str_starts_with( $url, 'https://' ) ) {
					continue;
				}
				if ( ! $this->trusted ) {
					$this->warnings[] = __( 'Custom libraries can only be added by administrators.', 'visual-editor' );
					continue;
				}
				$type        = (string) ( $item['type'] ?? 'script' );
				$lib['url']  = $url;
				$lib['type'] = in_array( $type, array( 'script', 'module', 'style' ), true ) ? $type : 'script';
				$lib['name'] = $this->label( $item['name'] ?? $id, 60 );
			}
			$out[] = $lib;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ seo, tokens, settings */

	/**
	 * @param mixed $raw Raw SEO map.
	 * @return array<string, array<string, mixed>>
	 */
	public function seo( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		if ( count( $raw ) > self::LIMITS['seo'] ) {
			$this->warnings[] = __( 'Too many SEO entries: only the first 5000 were kept.', 'visual-editor' );
		}

		foreach ( array_slice( $raw, 0, self::LIMITS['seo'], true ) as $page => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean = self::seo_entry( $item );
			if ( array() !== $clean ) {
				$key         = (string) $page;
				$key         = str_starts_with( $key, 'tpl:' ) ? 'tpl:' . self::tpl( substr( $key, 4 ) ) : PageKey::sanitize( $key );
				$out[ $key ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $item Raw SEO fields.
	 * @return array<string, mixed>
	 */
	public static function seo_entry( array $item ): array {
		$clean = array();
		foreach ( array( 'title' => 200, 'description' => 500, 'ogTitle' => 200, 'ogDescription' => 500, 'keywords' => 300 ) as $key => $max ) {
			$value = trim( mb_substr( sanitize_text_field( (string) ( $item[ $key ] ?? '' ) ), 0, $max ) );
			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}
		foreach ( array( 'image', 'canonical' ) as $key ) {
			$value = esc_url_raw( trim( (string) ( $item[ $key ] ?? '' ) ) );
			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}
		foreach ( array( 'noindex', 'nofollow' ) as $key ) {
			if ( ! empty( $item[ $key ] ) ) {
				$clean[ $key ] = true;
			}
		}
		$json = trim( (string) ( $item['jsonld'] ?? '' ) );
		if ( '' !== $json && strlen( $json ) <= 100000 && is_array( json_decode( $json, true ) ) ) {
			$clean['jsonld'] = $json;
		}

		return $clean;
	}

	/**
	 * Design tokens: CSS custom properties (colors, sizes, radii, shadows…) and fonts.
	 *
	 * @param mixed $raw Raw tokens.
	 * @return array{vars: array<string, string>, fonts: array<int, array<string, mixed>>}
	 */
	public function tokens( mixed $raw ): array {
		$vars  = array();
		$fonts = array();
		if ( is_array( $raw ) ) {
			foreach ( array_slice( (array) ( $raw['vars'] ?? array() ), 0, 400, true ) as $name => $value ) {
				$name = strtolower( (string) $name );
				if ( ! str_starts_with( $name, '--' ) ) {
					$name = '--' . $name;
				}
				if ( ! preg_match( '/^--[a-z0-9_-]{1,80}$/', $name ) || ! is_scalar( $value ) ) {
					continue;
				}
				$value = trim( str_replace( array( '<', '{', '}', ';', "\0" ), '', (string) $value ) );
				if ( '' !== $value && strlen( $value ) <= 500 ) {
					$vars[ $name ] = $value;
				}
			}
			foreach ( array_slice( (array) ( $raw['fonts'] ?? array() ), 0, 12 ) as $font ) {
				if ( ! is_array( $font ) ) {
					continue;
				}
				$family = trim( (string) preg_replace( '/[^\p{L}\p{N} \-_]/u', '', (string) ( $font['family'] ?? '' ) ) );
				if ( '' === $family ) {
					continue;
				}
				$source  = (string) ( $font['source'] ?? 'google' );
				$entry   = array(
					'family'  => mb_substr( $family, 0, 60 ),
					'source'  => in_array( $source, array( 'google', 'bunny', 'url', 'system' ), true ) ? $source : 'google',
					'weights' => array_values( array_filter( array_map( 'intval', array_slice( (array) ( $font['weights'] ?? array( 400, 700 ) ), 0, 9 ) ), static fn ( int $w ): bool => $w >= 100 && $w <= 900 ) ),
					'role'    => in_array( $font['role'] ?? '', array( 'body', 'heading', 'mono', 'accent' ), true ) ? (string) $font['role'] : '',
				);
				if ( 'url' === $entry['source'] ) {
					$url = esc_url_raw( (string) ( $font['url'] ?? '' ) );
					if ( ! str_starts_with( $url, 'https://' ) ) {
						continue;
					}
					$entry['url'] = $url;
				}
				$fonts[] = $entry;
			}
		}

		return array(
			'vars'  => $vars,
			'fonts' => $fonts,
		);
	}

	/**
	 * Tailwind CSS compiled by the editor (the public site loads plain CSS, never the Tailwind compiler).
	 *
	 * @param mixed $raw Raw settings.
	 * @return array{enabled: bool, css: string, config: string}
	 */
	private function tailwind( mixed $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'enabled' => ! empty( $raw['enabled'] ),
			'css'     => Html::css( $raw['css'] ?? '', $this->trusted, 1500000 ),
			'config'  => Html::css( $raw['config'] ?? '', $this->trusted, 50000 ),
		);
	}

	/**
	 * @param mixed $raw Raw settings.
	 * @return array<string, mixed>
	 */
	private function settings( mixed $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$def = self::default_settings();
		$bp  = (array) ( $raw['breakpoints'] ?? array() );

		return array(
			'ssr'           => in_array( $raw['ssr'] ?? '', array( 'auto', 'on', 'off' ), true ) ? (string) $raw['ssr'] : $def['ssr'],
			'reducedMotion' => ! isset( $raw['reducedMotion'] ) || ! empty( $raw['reducedMotion'] ),
			'breakpoints'   => array(
				'tablet' => max( 600, min( 1600, (int) ( $bp['tablet'] ?? 1023 ) ) ),
				'mobile' => max( 320, min( 1000, (int) ( $bp['mobile'] ?? 767 ) ) ),
			),
		);
	}
}
