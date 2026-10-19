<?php
/**
 * HTML filtering for markup that comes from people or keys that may not post unfiltered HTML.
 *
 * Starts from WordPress' post rules and adds what a site builder needs and cannot use to run code: the builder's
 * own <sve-*> tags, inline SVG icons, iframes (video/maps), and form controls. Scripts, event handlers and
 * javascript: URLs are always removed.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Html {

	/**
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		static $allowed = null;
		if ( null !== $allowed ) {
			return $allowed;
		}

		$global = array(
			'class'    => true,
			'id'       => true,
			'style'    => true,
			'title'    => true,
			'role'     => true,
			'lang'     => true,
			'dir'      => true,
			'hidden'   => true,
			'tabindex' => true,
			'data-*'   => true,
			'aria-*'   => true,
		);
		$extra  = array(
			// Builder tags.
			'sve-loop'      => array( 'query' => true, 'as' => true, 'empty' => true, 'paginate' => true, 'source' => true ),
			'sve-component' => array( 'ref' => true, 'props' => true ),
			'sve-island'    => array( 'ref' => true, 'props' => true, 'load' => true ),
			'sve-content'   => array(),
			'sve-shortcode' => array(),
			'sve-block'     => array( 'name' => true, 'attrs' => true ),
			'sve-menu'      => array( 'location' => true, 'menu' => true, 'depth' => true, 'item-class' => true, 'link-class' => true ),
			'sve-slot'      => array( 'name' => true ),
			'sve-if'        => array( 'test' => true, 'not' => true ),
			'sve-pagination' => array(),
			'sve-search'    => array( 'placeholder' => true, 'button' => true ),
			'sve-breadcrumbs' => array(),
			// Inline SVG icons.
			'svg'           => array( 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'preserveaspectratio' => true, 'focusable' => true ),
			'g'             => array( 'fill' => true, 'stroke' => true, 'transform' => true, 'opacity' => true ),
			'path'          => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'fill-rule' => true, 'clip-rule' => true, 'transform' => true, 'opacity' => true ),
			'circle'        => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ),
			'ellipse'       => array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true ),
			'rect'          => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ),
			'line'          => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true ),
			'polyline'      => array( 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ),
			'polygon'       => array( 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ),
			'defs'          => array(),
			'lineargradient' => array( 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradientunits' => true ),
			'radialgradient' => array( 'id' => true, 'cx' => true, 'cy' => true, 'r' => true, 'gradientunits' => true ),
			'stop'          => array( 'offset' => true, 'stop-color' => true, 'stop-opacity' => true ),
			'use'           => array( 'href' => true ),
			'symbol'        => array( 'viewbox' => true ),
			// Embeds.
			'iframe'        => array( 'src' => true, 'width' => true, 'height' => true, 'allow' => true, 'allowfullscreen' => true, 'loading' => true, 'frameborder' => true, 'referrerpolicy' => true, 'name' => true ),
			'picture'       => array(),
			'source'        => array( 'src' => true, 'srcset' => true, 'sizes' => true, 'type' => true, 'media' => true ),
			// Forms (submitted by the runtime to this site's form endpoint).
			'form'          => array( 'action' => true, 'method' => true, 'name' => true, 'novalidate' => true, 'autocomplete' => true ),
			'input'         => array( 'type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'required' => true, 'min' => true, 'max' => true, 'step' => true, 'pattern' => true, 'checked' => true, 'disabled' => true, 'readonly' => true, 'autocomplete' => true, 'minlength' => true, 'maxlength' => true, 'multiple' => true, 'accept' => true ),
			'select'        => array( 'name' => true, 'required' => true, 'multiple' => true, 'disabled' => true ),
			'option'        => array( 'value' => true, 'selected' => true, 'disabled' => true ),
			'optgroup'      => array( 'label' => true ),
			'textarea'      => array( 'name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true, 'required' => true, 'maxlength' => true, 'disabled' => true ),
			'button'        => array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true ),
			'label'         => array( 'for' => true ),
			'fieldset'      => array(),
			'legend'        => array(),
			'output'        => array( 'for' => true, 'name' => true ),
			'dialog'        => array( 'open' => true ),
			'details'       => array( 'open' => true, 'name' => true ),
			'summary'       => array(),
			'main'          => array(),
			'template'      => array(),
		);

		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( $extra as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? array(), $attrs, $global );
		}
		foreach ( $allowed as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( is_array( $attrs ) ? $attrs : array(), $global );
		}

		return $allowed;
	}

	/**
	 * Filters markup unless the author is trusted.
	 */
	public static function clean( string $html, bool $trusted, int $max = 200000 ): string {
		$html = substr( $html, 0, $max );
		if ( $trusted ) {
			return $html;
		}
		$html = (string) wp_check_invalid_utf8( $html );

		return wp_kses( $html, self::allowed(), wp_allowed_protocols() );
	}

	/**
	 * Plain CSS: "<" never occurs in valid CSS outside strings, dropping it makes leaving <style> impossible.
	 * Untrusted CSS additionally may not import or execute anything.
	 */
	public static function css( mixed $value, bool $trusted, int $max = 300000 ): string {
		$css = substr( str_replace( array( '<', "\0" ), '', is_string( $value ) ? $value : '' ), 0, $max );
		if ( $trusted ) {
			return $css;
		}
		$css = (string) preg_replace( '/@import[^;]*;?/i', '', $css );

		return (string) preg_replace( '/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding/i', '', $css );
	}

	/**
	 * Whether a code string (JS) may be stored for this author.
	 */
	public static function code( mixed $value, bool $trusted, int $max = 300000 ): ?string {
		if ( ! $trusted || ! is_string( $value ) ) {
			return null;
		}

		return substr( $value, 0, $max );
	}
}
