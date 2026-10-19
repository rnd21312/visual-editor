<?php
/**
 * Filters of bindings: {{ value | name:arg:arg }}.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Builder;

defined( 'ABSPATH' ) || exit;

final class Filters {

	/** Names and one-line help, also shown to the AI. */
	public const HELP = array(
		'default'   => 'default:"text" — fallback when empty',
		'upper'     => 'UPPERCASE',
		'lower'     => 'lowercase',
		'capitalize' => 'First letter upper case',
		'trim'      => 'remove surrounding spaces',
		'truncate'  => 'truncate:120 — cut to N characters with …',
		'words'     => 'words:20 — first N words',
		'excerpt'   => 'excerpt:30 — plain text, N words',
		'strip'     => 'remove HTML tags',
		'date'      => 'date:"j F Y" — format a date or timestamp (site language)',
		'ago'       => 'human time difference ("3 days ago")',
		'number'    => 'number:2 — localized number with N decimals',
		'money'     => 'price with the shop currency (WooCommerce)',
		'percent'   => 'append %',
		'json'      => 'JSON encode (for data attributes)',
		'url'       => 'escape as URL',
		'urlencode' => 'URL-encode a value',
		'slug'      => 'sanitize_title()',
		'nl2br'     => 'new lines to <br>',
		'markdown'  => 'very small Markdown (bold, italic, links, line breaks)',
		'img'       => 'img:"medium" — <img> tag of an image / attachment id',
		'size'      => 'size:"large" — URL of an image size',
		'count'     => 'number of items',
		'first'     => 'first item',
		'last'      => 'last item',
		'join'      => 'join:", " — join a list (of terms, strings…)',
		'pluck'     => 'pluck:"name" — field of every item of a list',
		'limit'     => 'limit:3 — first N items of a list',
		'reverse'   => 'reverse a list',
		'replace'   => 'replace:"a":"b"',
		'prepend'   => 'prepend:"text"',
		'append'    => 'append:"text"',
		'plus'      => 'plus:1',
		'minus'     => 'minus:1',
		'times'     => 'times:2',
		'divide'    => 'divide:2',
		'round'     => 'round:0',
		'eq'        => 'eq:"x" — true when equal (use in {{#if}})',
		'stars'     => 'rating to ★★★★☆',
		'esc'       => 'force plain-text escaping of markup',
		'raw'       => 'print markup as is (trusted authors; filtered for others)',
		'translate' => 'translate the text with the site language files',
	);

	/**
	 * @param array<int, mixed> $args Arguments.
	 */
	public static function apply( string $name, mixed $value, array $args, bool $trusted ): mixed {
		$text = static fn ( mixed $v ): string => $v instanceof SafeHtml ? wp_strip_all_tags( $v->html ) : Data::text( $v );
		$arg  = $args[0] ?? null;

		switch ( $name ) {
			case 'default':
				return Data::truthy( $value ) ? $value : $arg;
			case 'upper':
				return mb_strtoupper( $text( $value ) );
			case 'lower':
				return mb_strtolower( $text( $value ) );
			case 'capitalize':
				$t = $text( $value );
				return mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
			case 'trim':
				return trim( $text( $value ) );
			case 'truncate':
				$t   = $text( $value );
				$max = max( 1, (int) ( $arg ?? 120 ) );
				return mb_strlen( $t ) > $max ? rtrim( mb_substr( $t, 0, $max ) ) . '…' : $t;
			case 'words':
			case 'excerpt':
				return wp_trim_words( $text( $value ), max( 1, (int) ( $arg ?? 30 ) ), '…' );
			case 'strip':
				return $text( $value );
			case 'date':
				$t  = $text( $value );
				$ts = is_numeric( $t ) ? (int) $t : strtotime( $t );
				return false === $ts ? $t : (string) wp_date( (string) ( $arg ?? get_option( 'date_format' ) ), $ts );
			case 'ago':
				$t  = $text( $value );
				$ts = is_numeric( $t ) ? (int) $t : strtotime( $t );
				/* translators: %s: time difference */
				return false === $ts ? $t : sprintf( __( '%s ago', 'visual-editor' ), human_time_diff( $ts ) );
			case 'number':
				return number_format_i18n( (float) $text( $value ), (int) ( $arg ?? 0 ) );
			case 'money':
				return function_exists( 'wc_price' ) ? new SafeHtml( (string) wc_price( (float) $text( $value ) ) ) : number_format_i18n( (float) $text( $value ), 2 );
			case 'percent':
				return $text( $value ) . '%';
			case 'json':
				return (string) wp_json_encode( $value instanceof Node ? $value->text() : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			case 'url':
				return esc_url( $text( $value ) );
			case 'urlencode':
				return rawurlencode( $text( $value ) );
			case 'slug':
				return sanitize_title( $text( $value ) );
			case 'nl2br':
				return new SafeHtml( nl2br( esc_html( $text( $value ) ) ) );
			case 'markdown':
				$t = esc_html( $text( $value ) );
				$t = (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t );
				$t = (string) preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $t );
				$t = (string) preg_replace_callback( '/\[([^\]]+)\]\(([^)\s]+)\)/', static fn ( array $m ): string => '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>', $t );
				return new SafeHtml( nl2br( $t ) );
			case 'img':
				$size = (string) ( $arg ?? 'large' );
				$id   = $value instanceof ImageNode ? $value->id : ( is_numeric( $value ) ? (int) $value : 0 );
				if ( $id > 0 ) {
					return new SafeHtml( (string) wp_get_attachment_image( $id, $size, false, array( 'loading' => 'lazy' ) ) );
				}
				$url = $text( $value );
				return '' !== $url ? new SafeHtml( '<img src="' . esc_url( $url ) . '" alt="" loading="lazy">' ) : '';
			case 'size':
				return $value instanceof ImageNode ? $value->get( (string) ( $arg ?? 'large' ) ) : $value;
			case 'count':
				return is_array( $value ) ? count( $value ) : ( is_countable( $value ) ? count( $value ) : 0 );
			case 'first':
				return is_array( $value ) ? ( $value[ array_key_first( $value ) ] ?? null ) : $value;
			case 'last':
				return is_array( $value ) ? ( $value[ array_key_last( $value ) ] ?? null ) : $value;
			case 'join':
				return is_array( $value ) ? implode( (string) ( $arg ?? ', ' ), array_map( $text, $value ) ) : $text( $value );
			case 'pluck':
				return is_array( $value ) ? array_map( static fn ( $item ): mixed => Data::get( $item, (string) $arg ), $value ) : array();
			case 'limit':
				return is_array( $value ) ? array_slice( $value, 0, max( 0, (int) $arg ) ) : $value;
			case 'reverse':
				return is_array( $value ) ? array_reverse( $value ) : implode( '', array_reverse( mb_str_split( $text( $value ) ) ) );
			case 'replace':
				return str_replace( (string) $arg, (string) ( $args[1] ?? '' ), $text( $value ) );
			case 'prepend':
				return Data::truthy( $value ) ? (string) $arg . $text( $value ) : '';
			case 'append':
				return Data::truthy( $value ) ? $text( $value ) . (string) $arg : '';
			case 'plus':
				return (float) $text( $value ) + (float) $arg;
			case 'minus':
				return (float) $text( $value ) - (float) $arg;
			case 'times':
				return (float) $text( $value ) * (float) $arg;
			case 'divide':
				return 0.0 === (float) $arg ? 0 : (float) $text( $value ) / (float) $arg;
			case 'round':
				return round( (float) $text( $value ), (int) ( $arg ?? 0 ) );
			case 'eq':
				return $text( $value ) === Data::text( $arg );
			case 'stars':
				$rating = max( 0, min( 5, (float) $text( $value ) ) );
				$full   = (int) round( $rating );
				return str_repeat( '★', $full ) . str_repeat( '☆', 5 - $full );
			case 'esc':
				return $text( $value );
			case 'raw':
				$t = $value instanceof SafeHtml ? $value->html : Data::text( $value );
				return new SafeHtml( $trusted ? $t : wp_kses_post( $t ) );
			case 'translate':
			case 't':
				return translate( $text( $value ), (string) ( $arg ?? 'default' ) ); // phpcs:ignore WordPress.WP.I18n
		}

		return $value;
	}
}
