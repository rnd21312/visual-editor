<?php
/**
 * Which theme template renders a request. Single products, blog posts, category archives and so on are one
 * template for many URLs: edits made there are saved once for the template instead of once per URL.
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Templates {

	/**
	 * Template of the current front-end request.
	 *
	 * @return array{key: string, label: string, templated: bool, count: int}
	 */
	public static function current(): array {
		$info = static fn ( string $key, string $label, bool $templated, int $count = 0 ): array => array(
			'key'       => $key,
			'label'     => $label,
			'templated' => $templated,
			'count'     => $count,
		);

		if ( is_404() ) {
			return $info( '404', __( '404 page', 'suntourz-visual-editor' ), true );
		}
		if ( is_search() ) {
			return $info( 'search', __( 'Search results', 'suntourz-visual-editor' ), true );
		}
		if ( is_front_page() ) {
			return $info( 'front', __( 'Front page', 'suntourz-visual-editor' ), false );
		}
		if ( is_home() ) {
			return $info( 'blog-index', __( 'Blog index', 'suntourz-visual-editor' ), true );
		}

		if ( is_singular() ) {
			$type = (string) get_post_type();
			if ( 'page' === $type ) {
				$custom = (string) get_page_template_slug();

				return $info( '' !== $custom ? 'page-template:' . $custom : 'page-default', __( 'Page', 'suntourz-visual-editor' ), false );
			}
			$object = get_post_type_object( $type );
			$name   = $object ? (string) $object->labels->singular_name : $type;
			$counts = (array) wp_count_posts( $type );

			return $info( 'single:' . $type, sprintf( /* translators: %s: post type name, e.g. Product */ __( 'Single %s template', 'suntourz-visual-editor' ), $name ), true, (int) ( $counts['publish'] ?? 0 ) );
		}

		if ( is_post_type_archive() ) {
			$type = (string) get_query_var( 'post_type' );
			$type = '' !== $type ? $type : 'post';

			return $info( 'archive:' . $type, sprintf( /* translators: %s: post type name */ __( '%s archive template', 'suntourz-visual-editor' ), $type ), true );
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$term     = get_queried_object();
			$taxonomy = $term instanceof \WP_Term ? $term->taxonomy : 'category';
			$count    = $term instanceof \WP_Term ? (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) ) : 0;

			return $info( 'tax:' . $taxonomy, sprintf( /* translators: %s: taxonomy name */ __( '%s archive template', 'suntourz-visual-editor' ), $taxonomy ), true, $count );
		}
		if ( is_author() ) {
			return $info( 'author', __( 'Author archive', 'suntourz-visual-editor' ), true );
		}
		if ( is_date() ) {
			return $info( 'date', __( 'Date archive', 'suntourz-visual-editor' ), true );
		}

		return $info( 'other', __( 'Other', 'suntourz-visual-editor' ), false );
	}

	/**
	 * Every template of the site with one URL to preview it, for the page picker.
	 *
	 * @return array<int, array{key: string, label: string, url: string, count: int, thumb: string, type: string}>
	 */
	public static function listing(): array {
		$out = array();

		$types = array_merge( array( 'post' ), array_values( get_post_types( array( 'public' => true, '_builtin' => false ) ) ) );
		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || 'attachment' === $type ) {
				continue;
			}
			$latest = get_posts( array( 'post_type' => $type, 'numberposts' => 1, 'post_status' => 'publish' ) );
			if ( array() === $latest ) {
				continue;
			}
			$counts = (array) wp_count_posts( $type );
			$out[]  = array(
				'key'   => 'single:' . $type,
				'label' => sprintf( /* translators: %s: post type name */ __( 'Single %s template', 'suntourz-visual-editor' ), (string) $object->labels->singular_name ),
				'url'   => (string) get_permalink( $latest[0] ),
				'count' => (int) ( $counts['publish'] ?? 0 ),
				'thumb' => (string) get_the_post_thumbnail_url( $latest[0], 'medium' ),
				'type'  => $type,
			);

			$archive = 'post' === $type ? '' : get_post_type_archive_link( $type );
			if ( $archive ) {
				$out[] = array(
					'key'   => 'archive:' . $type,
					'label' => sprintf( /* translators: %s: post type name */ __( '%s archive template', 'suntourz-visual-editor' ), (string) $object->labels->name ),
					'url'   => (string) $archive,
					'count' => 0,
					'thumb' => '',
					'type'  => $type,
				);
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy->name, 'number' => 1, 'hide_empty' => true ) );
			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}
			$link = get_term_link( $terms[0] );
			if ( is_string( $link ) ) {
				$out[] = array(
					'key'   => 'tax:' . $taxonomy->name,
					'label' => sprintf( /* translators: %s: taxonomy name */ __( '%s archive template', 'suntourz-visual-editor' ), (string) $taxonomy->labels->singular_name ),
					'url'   => $link,
					'count' => (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => true ) ),
					'thumb' => '',
					'type'  => 'taxonomy',
				);
			}
		}

		$out[] = array( 'key' => 'search', 'label' => __( 'Search results', 'suntourz-visual-editor' ), 'url' => add_query_arg( 's', 'a', home_url( '/' ) ), 'count' => 0, 'thumb' => '', 'type' => 'other' );
		$out[] = array( 'key' => '404', 'label' => __( '404 page', 'suntourz-visual-editor' ), 'url' => home_url( '/sve-preview-404/' ), 'count' => 0, 'thumb' => '', 'type' => 'other' );

		return $out;
	}
}
