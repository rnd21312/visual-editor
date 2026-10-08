<?php
/**
 * Page finder for the editor's picker and for the AI API: pages, posts and every public custom post type,
 * with search, type filter, paging and a preview image.
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Pages {

	/**
	 * Public post types that have a page on the site.
	 *
	 * @return array<string, string> slug => plural label
	 */
	public static function types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$out[ $type->name ] = (string) $type->labels->name;
			}
		}

		return $out;
	}

	/**
	 * @param array{search?: string, type?: string, page?: int, per_page?: int} $args Filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, types: array<string, string>}
	 */
	public static function search( array $args ): array {
		$types = self::types();
		$type  = (string) ( $args['type'] ?? '' );
		$per   = max( 1, min( 40, (int) ( $args['per_page'] ?? 18 ) ) );
		$paged = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query = new \WP_Query(
			array(
				'post_type'           => isset( $types[ $type ] ) ? $type : array_keys( $types ),
				'post_status'         => array( 'publish', 'private' ),
				's'                   => (string) ( $args['search'] ?? '' ),
				'posts_per_page'      => $per,
				'paged'               => $paged,
				'orderby'             => '' !== (string) ( $args['search'] ?? '' ) ? 'relevance' : 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		$front = (int) get_option( 'page_on_front' );
		$posts = (int) get_option( 'page_for_posts' );
		$items = array();
		foreach ( $query->posts as $post ) {
			$object  = get_post_type_object( $post->post_type );
			$items[] = array(
				'id'        => (int) $post->ID,
				'title'     => '' !== get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'suntourz-visual-editor' ),
				'url'       => (string) get_permalink( $post ),
				'type'      => $post->post_type,
				'typeLabel' => $object ? (string) $object->labels->singular_name : $post->post_type,
				'thumb'     => (string) get_the_post_thumbnail_url( $post, 'medium' ),
				'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
				'status'    => $post->post_status,
				'role'      => $front === (int) $post->ID ? 'front' : ( $posts === (int) $post->ID ? 'blog' : '' ),
			);
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'types' => $types,
		);
	}
}
