<?php
/**
 * Which theme template renders a request. Single products, blog posts, category archives and so on are one
 * template for many URLs: edits made there are saved once for the template instead of once per URL.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Templates {

	/**
	 * Template of the current front-end request.
	 *
	 * @return array{key: string, label: string, templated: bool, count: int}
	 */
	public static function current( bool $with_count = false ): array {
		static $cache = null;
		if ( null !== $cache && ! $with_count ) {
			return $cache;
		}
		$info = static fn ( string $key, string $label, bool $templated, int $count = 0 ): array => array(
			'key'       => $key,
			'label'     => $label,
			'templated' => $templated,
			'count'     => $count,
		);

		if ( is_404() ) {
			$out = $info( '404', __( '404 page', 'visual-editor' ), true );
		} elseif ( is_search() ) {
			$out = $info( 'search', __( 'Search results', 'visual-editor' ), true );
		} elseif ( is_front_page() ) {
			$out = $info( 'front', __( 'Front page', 'visual-editor' ), false );
		} elseif ( is_home() ) {
			$out = $info( 'blog-index', __( 'Blog index', 'visual-editor' ), true );
		} elseif ( is_singular() ) {
			$type = (string) get_post_type();
			if ( 'page' === $type ) {
				$custom = (string) get_page_template_slug();
				$out    = $info( '' !== $custom ? 'page-template:' . $custom : 'page-default', __( 'Page', 'visual-editor' ), false );
			} else {
				$object = get_post_type_object( $type );
				$name   = $object ? (string) $object->labels->singular_name : $type;
				$count  = $with_count ? (int) ( ( (array) wp_count_posts( $type ) )['publish'] ?? 0 ) : 0;
				/* translators: %s: post type name, e.g. Product */
				$out = $info( 'single:' . $type, sprintf( __( 'Single %s template', 'visual-editor' ), $name ), true, $count );
			}
		} elseif ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
			$type = '' !== $type ? $type : 'post';
			/* translators: %s: post type name */
			$out = $info( 'archive:' . $type, sprintf( __( '%s archive template', 'visual-editor' ), $type ), true );
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term     = get_queried_object();
			$taxonomy = $term instanceof \WP_Term ? $term->taxonomy : 'category';
			$count    = $with_count && $term instanceof \WP_Term ? (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) ) : 0;
			/* translators: %s: taxonomy name */
			$out = $info( 'tax:' . $taxonomy, sprintf( __( '%s archive template', 'visual-editor' ), $taxonomy ), true, $count );
		} elseif ( is_author() ) {
			$out = $info( 'author', __( 'Author archive', 'visual-editor' ), true );
		} elseif ( is_date() ) {
			$out = $info( 'date', __( 'Date archive', 'visual-editor' ), true );
		} else {
			$out = $info( 'other', __( 'Other', 'visual-editor' ), false );
		}

		if ( ! $with_count ) {
			$cache = $out;
		}

		return $out;
	}

	/**
	 * Every template of the site with one URL to preview it.
	 *
	 * @return array<int, array{key: string, label: string, url: string, count: int, thumb: string, type: string}>
	 */
	public static function listing(): array {
		$out = array();

		$out[] = array( 'key' => 'front', 'label' => __( 'Front page', 'visual-editor' ), 'url' => home_url( '/' ), 'count' => 1, 'thumb' => '', 'type' => 'page' );
		$out[] = array( 'key' => 'page-default', 'label' => __( 'Default page template', 'visual-editor' ), 'url' => self::sample_url( 'page' ), 'count' => (int) ( ( (array) wp_count_posts( 'page' ) )['publish'] ?? 0 ), 'thumb' => '', 'type' => 'page' );

		$types = array_merge( array( 'post' ), array_values( get_post_types( array( 'public' => true, '_builtin' => false ) ) ) );
		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || 'attachment' === $type ) {
				continue;
			}
			$latest = get_posts( array( 'post_type' => $type, 'numberposts' => 1, 'post_status' => 'publish' ) );
			$counts = (array) wp_count_posts( $type );
			$out[]  = array(
				'key'   => 'single:' . $type,
				/* translators: %s: post type name */
				'label' => sprintf( __( 'Single %s template', 'visual-editor' ), (string) $object->labels->singular_name ),
				'url'   => array() !== $latest ? (string) get_permalink( $latest[0] ) : '',
				'count' => (int) ( $counts['publish'] ?? 0 ),
				'thumb' => array() !== $latest ? (string) get_the_post_thumbnail_url( $latest[0], 'medium' ) : '',
				'type'  => $type,
			);

			$archive = 'post' === $type ? (string) ( get_option( 'page_for_posts' ) ? get_permalink( (int) get_option( 'page_for_posts' ) ) : '' ) : (string) get_post_type_archive_link( $type );
			$out[]   = array(
				'key'   => 'post' === $type ? 'blog-index' : 'archive:' . $type,
				/* translators: %s: post type name */
				'label' => sprintf( __( '%s archive template', 'visual-editor' ), (string) $object->labels->name ),
				'url'   => $archive,
				'count' => 0,
				'thumb' => '',
				'type'  => $type,
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomy->name, 'number' => 1, 'hide_empty' => true ) );
			$link  = is_array( $terms ) && array() !== $terms ? get_term_link( $terms[0] ) : '';
			$out[] = array(
				'key'   => 'tax:' . $taxonomy->name,
				/* translators: %s: taxonomy name */
				'label' => sprintf( __( '%s archive template', 'visual-editor' ), (string) $taxonomy->labels->singular_name ),
				'url'   => is_string( $link ) ? $link : '',
				'count' => (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => true ) ),
				'thumb' => '',
				'type'  => 'taxonomy',
			);
		}

		$out[] = array( 'key' => 'author', 'label' => __( 'Author archive', 'visual-editor' ), 'url' => (string) get_author_posts_url( (int) ( get_users( array( 'number' => 1, 'fields' => 'ID', 'has_published_posts' => true ) )[0] ?? 1 ) ), 'count' => 0, 'thumb' => '', 'type' => 'other' );
		$out[] = array( 'key' => 'search', 'label' => __( 'Search results', 'visual-editor' ), 'url' => add_query_arg( 's', 'a', home_url( '/' ) ), 'count' => 0, 'thumb' => '', 'type' => 'other' );
		$out[] = array( 'key' => '404', 'label' => __( '404 page', 'visual-editor' ), 'url' => home_url( '/sve-preview-404/' ), 'count' => 0, 'thumb' => '', 'type' => 'other' );

		return $out;
	}

	private static function sample_url( string $type ): string {
		$posts = get_posts( array( 'post_type' => $type, 'numberposts' => 1, 'post_status' => 'publish', 'exclude' => array( (int) get_option( 'page_on_front' ) ) ) );

		return array() !== $posts ? (string) get_permalink( $posts[0] ) : '';
	}
}
