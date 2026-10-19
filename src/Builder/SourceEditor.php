<?php
/**
 * Precise edits of builder source by element id (data-sid), without re-serializing the rest of the source:
 * replace / remove / insert next to or inside an element, change its attributes or text, wrap it.
 * Every element of builder source carries a data-sid; ids() adds missing ones.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Builder;

defined( 'ABSPATH' ) || exit;

final class SourceEditor {

	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param' );

	/**
	 * Gives every element without a data-sid one (so the editor and the AI can address it).
	 */
	public static function ids( string $source ): string {
		return (string) preg_replace_callback(
			'/<([a-zA-Z][a-zA-Z0-9-]*)(?=[\s>\/])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
			static function ( array $m ): string {
				$tag = strtolower( $m[1] );
				if ( in_array( $tag, array( 'html', 'head', 'body', 'script', 'style', 'br', 'wbr', 'template', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'g', 'defs', 'stop', 'use', 'option', 'sve-empty', 'sve-slot' ), true ) || str_contains( $m[2], 'data-sid=' ) ) {
					return $m[0];
				}
				$self = str_ends_with( rtrim( $m[2] ), '/' );
				$rest = $self ? rtrim( substr( rtrim( $m[2] ), 0, -1 ) ) : $m[2];

				return '<' . $m[1] . $rest . ' data-sid="' . self::new_sid() . '"' . ( $self ? ' /' : '' ) . '>';
			},
			$source
		);
	}

	public static function new_sid(): string {
		return 's' . strtolower( wp_generate_password( 7, false ) );
	}

	/**
	 * Locates an element: start of its open tag, end of its open tag, end of the element.
	 *
	 * @return array{start: int, open_end: int, end: int, tag: string, close_start: int}|null
	 */
	public static function locate( string $source, string $sid ): ?array {
		if ( 1 !== preg_match( '/<([a-zA-Z][a-zA-Z0-9-]*)(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\sdata-sid=["\']' . preg_quote( $sid, '/' ) . '["\'](?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $source, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start    = (int) $m[0][1];
		$open_end = $start + strlen( $m[0][0] );
		$tag      = strtolower( $m[1][0] );
		if ( in_array( $tag, self::VOID, true ) || str_ends_with( rtrim( $m[0][0], '>' ), '/' ) ) {
			return array( 'start' => $start, 'open_end' => $open_end, 'end' => $open_end, 'tag' => $tag, 'close_start' => $open_end );
		}
		$depth = 1;
		$pos   = $open_end;
		$re    = '/<(\/?)' . preg_quote( $tag, '/' ) . '(?=[\s>\/])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';
		while ( $depth > 0 && 1 === preg_match( $re, $source, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
			$pos = (int) $t[0][1] + strlen( $t[0][0] );
			if ( '/' === $t[1][0] ) {
				--$depth;
				if ( 0 === $depth ) {
					return array( 'start' => $start, 'open_end' => $open_end, 'end' => $pos, 'tag' => $tag, 'close_start' => (int) $t[0][1] );
				}
			} elseif ( ! str_ends_with( rtrim( $t[0][0], '>' ), '/' ) ) {
				++$depth;
			}
		}

		return array( 'start' => $start, 'open_end' => $open_end, 'end' => strlen( $source ), 'tag' => $tag, 'close_start' => strlen( $source ) );
	}

	/**
	 * Applies one edit.
	 *
	 * @param array<string, mixed> $args html, text, attrs, wrapper.
	 * @return string|\WP_Error New source.
	 */
	public static function apply( string $source, string $sid, string $action, array $args ) {
		$at = self::locate( $source, $sid );
		if ( null === $at ) {
			return new \WP_Error( 'sve_no_sid', 'No element with data-sid="' . $sid . '" in this layout. Read the layout (get_content) or the page outline to find ids.' );
		}
		$html = self::ids( (string) ( $args['html'] ?? '' ) );

		switch ( $action ) {
			case 'replace':
				return substr( $source, 0, $at['start'] ) . $html . substr( $source, $at['end'] );
			case 'remove':
				return substr( $source, 0, $at['start'] ) . substr( $source, $at['end'] );
			case 'insert_before':
				return substr( $source, 0, $at['start'] ) . $html . substr( $source, $at['start'] );
			case 'insert_after':
				return substr( $source, 0, $at['end'] ) . $html . substr( $source, $at['end'] );
			case 'prepend':
				return substr( $source, 0, $at['open_end'] ) . $html . substr( $source, $at['open_end'] );
			case 'append':
				return substr( $source, 0, $at['close_start'] ) . $html . substr( $source, $at['close_start'] );
			case 'set_inner':
				return substr( $source, 0, $at['open_end'] ) . $html . substr( $source, $at['close_start'] );
			case 'set_text':
				return substr( $source, 0, $at['open_end'] ) . esc_html( (string) ( $args['text'] ?? '' ) ) . substr( $source, $at['close_start'] );
			case 'set_attrs':
				$open = substr( $source, $at['start'], $at['open_end'] - $at['start'] );
				foreach ( (array) ( $args['attrs'] ?? array() ) as $name => $value ) {
					$open = self::set_attr( $open, (string) $name, null === $value ? null : (string) $value );
				}
				return substr( $source, 0, $at['start'] ) . $open . substr( $source, $at['open_end'] );
			case 'wrap':
				$wrapper = trim( (string) ( $args['wrapper'] ?? '<div>' ) );
				if ( 1 !== preg_match( '/^<([a-zA-Z][a-zA-Z0-9-]*)[^>]*>$/', $wrapper, $w ) ) {
					return new \WP_Error( 'sve_bad_wrapper', 'wrapper must be one opening tag, e.g. <div class="row">.' );
				}
				$wrapper = self::ids( $wrapper );
				return substr( $source, 0, $at['start'] ) . $wrapper . substr( $source, $at['start'], $at['end'] - $at['start'] ) . '</' . $w[1] . '>' . substr( $source, $at['end'] );
			case 'duplicate':
				$copy = substr( $source, $at['start'], $at['end'] - $at['start'] );
				$copy = (string) preg_replace( '/\sdata-sid=["\'][^"\']*["\']/', '', $copy );
				return substr( $source, 0, $at['end'] ) . self::ids( $copy ) . substr( $source, $at['end'] );
			case 'move_before':
			case 'move_after':
			case 'move_into':
				$target = (string) ( $args['target'] ?? '' );
				$piece  = substr( $source, $at['start'], $at['end'] - $at['start'] );
				$rest   = substr( $source, 0, $at['start'] ) . substr( $source, $at['end'] );
				$to     = self::locate( $rest, $target );
				if ( null === $to ) {
					return new \WP_Error( 'sve_no_sid', 'No target element with data-sid="' . $target . '" (or it is inside the element being moved).' );
				}
				$pos = 'move_before' === $action ? $to['start'] : ( 'move_after' === $action ? $to['end'] : $to['close_start'] );
				return substr( $rest, 0, $pos ) . $piece . substr( $rest, $pos );
		}

		return new \WP_Error( 'sve_bad_action', 'Unknown action.' );
	}

	private static function set_attr( string $open, string $name, ?string $value ): string {
		if ( 1 !== preg_match( '/^[a-zA-Z_:@][-a-zA-Z0-9_:.]*$/', $name ) || 'data-sid' === $name ) {
			return $open;
		}
		$re   = '/\s' . preg_quote( $name, '/' ) . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=[\s>\/])/';
		$open = (string) preg_replace( $re, '', $open );
		if ( null === $value ) {
			return $open;
		}
		$attr = ' ' . $name . '="' . esc_attr( $value ) . '"';

		return (string) preg_replace( '/(\s*\/?>)$/', $attr . '$1', $open, 1 );
	}

	/**
	 * Outline of builder source: element ids, tags, classes and a little text — what the AI needs to address
	 * elements with edit_layout.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function outline( string $source, int $max = 400 ): array {
		$out = array();
		preg_match_all( '/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$depth = 0;
		foreach ( $tags as $t ) {
			$close = '/' === $t[1][0];
			$name  = strtolower( $t[2][0] );
			$void  = in_array( $name, self::VOID, true ) || str_ends_with( rtrim( $t[3][0] ), '/' );
			if ( $close ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}
			$attrs = Parser::attributes( '<x' . $t[3][0] . '>' );
			if ( isset( $attrs['data-sid'] ) && count( $out ) < $max ) {
				$after = substr( $source, (int) $t[0][1] + strlen( $t[0][0] ), 160 );
				$text  = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/<.*$/s', '', $after ) ) );
				$item  = array( 'sid' => $attrs['data-sid'], 'tag' => $name, 'depth' => $depth );
				if ( ! empty( $attrs['class'] ) ) {
					$item['class'] = mb_substr( $attrs['class'], 0, 160 );
				}
				foreach ( array( 'href', 'src', 'ref', 'query', 'id' ) as $key ) {
					if ( ! empty( $attrs[ $key ] ) ) {
						$item[ $key ] = mb_substr( $attrs[ $key ], 0, 160 );
					}
				}
				if ( '' !== $text ) {
					$item['text'] = mb_substr( $text, 0, 80 );
				}
				$out[] = $item;
			}
			if ( ! $void ) {
				++$depth;
			}
		}

		return $out;
	}
}
