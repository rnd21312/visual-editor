<?php
/**
 * Imports a site package in steps that each stay well under a hosting time limit: the dashboard page and the AI
 * call step() again until it reports done. Order: theme files and theme → product attributes → terms → images →
 * posts → their meta, parents and terms → menus → settings → snippets → Visual Editor document → caches. The
 * state (what was mapped to what) lives in a file next to the package, so a step can resume after any break.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class TransferJob {

	private const STAGES = array( 'theme', 'attributes', 'terms', 'media', 'posts', 'meta', 'menus', 'options', 'snippets', 'document', 'finish' );

	private const BUDGET = 20; // Seconds of work per step.

	/** @var array<string, mixed> */
	private array $job;

	/** @var array<string, mixed>|null */
	private ?array $data = null;

	/* ------------------------------------------------------------------ start */

	/**
	 * Starts a job from a package file (JSON or zip) that is already in the transfer folder.
	 *
	 * @param array<int, string>   $groups  Parts to import (those the package has).
	 * @param array<string, mixed> $options media, max_media, theme, replace.
	 * @return array<string, mixed> Status.
	 */
	public static function start( string $file, array $groups, array $options ): array {
		$id  = bin2hex( random_bytes( 8 ) );
		$dir = SiteTransfer::folder() . '/job-' . $id;
		wp_mkdir_p( $dir );

		$head = (string) file_get_contents( $file, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( str_starts_with( $head, 'PK' ) ) {
			if ( ! class_exists( '\ZipArchive' ) ) {
				throw new RuntimeException( __( 'This package is a zip file, but the server has no PHP zip extension.', 'visual-editor' ) );
			}
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $file ) ) {
				throw new RuntimeException( __( 'The zip file could not be opened.', 'visual-editor' ) );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( str_contains( $name, '..' ) || str_starts_with( $name, '/' ) || ( 'site.json' !== $name && ! str_starts_with( $name, 'themes/' ) ) ) {
					throw new RuntimeException( __( 'The zip file holds unexpected paths; it was not made by this plugin.', 'visual-editor' ) );
				}
			}
			$zip->extractTo( $dir );
			$zip->close();
		} else {
			copy( $file, $dir . '/site.json' );
		}
		wp_delete_file( $file );

		$data = json_decode( (string) file_get_contents( $dir . '/site.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || SiteTransfer::FORMAT !== ( $data['format'] ?? '' ) ) {
			self::remove( $dir );
			throw new RuntimeException( __( 'This is not a Visual Editor site package.', 'visual-editor' ) );
		}
		if ( untrailingslashit( (string) $data['source'] ) === untrailingslashit( home_url() ) ) {
			self::remove( $dir );
			throw new RuntimeException( sprintf( /* translators: %s: site address */ __( 'This package was exported from this same site (%s). Export it on the other site and import it here.', 'visual-editor' ), (string) $data['source'] ) );
		}

		$available = (array) ( $data['groups'] ?? SiteTransfer::DEFAULT_GROUPS );
		$groups    = array_values( array_intersect( array() === $groups ? $available : $groups, $available ) );
		if ( in_array( 'theme_files', $groups, true ) && ! is_dir( $dir . '/themes' ) ) {
			$groups = array_values( array_diff( $groups, array( 'theme_files' ) ) );
		}

		$job = new self(
			array(
				'id'      => $id,
				'dir'     => $dir,
				'source'  => (string) $data['source'],
				'groups'  => $groups,
				'options' => array(
					'media'     => ! array_key_exists( 'media', $options ) || ! empty( $options['media'] ),
					'max_media' => isset( $options['max_media'] ) ? max( 0, (int) $options['max_media'] ) : 2000,
					'theme'     => ! array_key_exists( 'theme', $options ) || ! empty( $options['theme'] ),
					'replace'   => ! array_key_exists( 'replace', $options ) || ! empty( $options['replace'] ),
				),
				'stage'   => 'theme',
				'cursor'  => 0,
				'maps'    => array( 'urls' => array(), 'media' => array(), 'posts' => array(), 'terms' => array(), 'menus' => array() ),
				'done'    => array(),
				'warn'    => array(),
				'count'   => array(),
				'started' => time(),
				'user'    => get_current_user_id(),
			)
		);
		$job->save();
		update_option( 'sve_transfer_job', $id, false );

		return $job->status() + array( 'summary' => SiteTransfer::summary( $data ) );
	}

	/**
	 * Downloads a package from a link (export_site on another site) and starts a job.
	 *
	 * @param array<int, string>   $groups  Parts.
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed>
	 */
	public static function start_from_url( string $url, array $groups, array $options ): array {
		if ( ! wp_http_validate_url( $url ) ) {
			throw new RuntimeException( __( 'Give the export link from the other site.', 'visual-editor' ) );
		}
		$file     = SiteTransfer::folder() . '/download-' . bin2hex( random_bytes( 8 ) );
		$response = wp_remote_get( $url, array( 'timeout' => 300, 'stream' => true, 'filename' => $file ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $file );
			throw new RuntimeException( sprintf( /* translators: %s: error */ __( 'Could not download the package: %s. The link may have expired, or the other site blocks this server.', 'visual-editor' ), is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) ) );
		}

		return self::start( $file, $groups, $options );
	}

	/**
	 * Describes a package without importing it.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_url( string $url ): array {
		$status = self::start_from_url( $url, array(), array() );
		$job    = self::load( (string) $status['job'] );
		$theme  = (array) $job->data()['theme'];
		$out    = array(
			'preview'        => true,
			'target'         => home_url(),
			'themeInstalled' => wp_get_theme( (string) $theme['stylesheet'] )->exists(),
		) + $status['summary'];
		$job->cancel();

		return $out;
	}

	/* ------------------------------------------------------------------ run */

	public static function load( string $id ): self {
		$file = SiteTransfer::folder() . '/job-' . preg_replace( '/[^a-f0-9]/', '', $id ) . '/job.json';
		$job  = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $job ) ) {
			throw new RuntimeException( __( 'This import job does not exist any more. Start the import again.', 'visual-editor' ) );
		}

		return new self( $job );
	}

	/**
	 * @param array<string, mixed> $job State.
	 */
	private function __construct( array $job ) {
		$this->job = $job;
	}

	/**
	 * Does up to BUDGET seconds of work and reports where the job is.
	 *
	 * @return array<string, mixed>
	 */
	public function step(): array {
		if ( 'done' === $this->job['stage'] ) {
			return $this->status();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		wp_raise_memory_limit( 'admin' );
		kses_remove_filters(); // Layouts carry scripts, iframes and data attributes; only administrators import.
		$this->register_attributes();

		$until = microtime( true ) + self::BUDGET;
		while ( 'done' !== $this->job['stage'] && microtime( true ) < $until ) {
			$stage = (string) $this->job['stage'];
			try {
				$finished = $this->{'stage_' . $stage}( $until );
			} catch ( \Throwable $error ) {
				$this->warn( $stage . ': ' . $error->getMessage() );
				$finished = true;
			}
			if ( $finished ) {
				$next                 = array_search( $stage, self::STAGES, true );
				$this->job['stage']   = self::STAGES[ $next + 1 ] ?? 'done';
				$this->job['cursor']  = 0;
			}
			$this->save();
		}
		kses_init_filters();

		return $this->status();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$index = array_search( $this->job['stage'], self::STAGES, true );
		$total = $this->stage_total();

		return array(
			'job'      => $this->job['id'],
			'done'     => 'done' === $this->job['stage'],
			'stage'    => $this->job['stage'],
			'progress' => 'done' === $this->job['stage'] ? 100 : (int) floor( ( (int) $index + ( $total > 0 ? min( 1, $this->job['cursor'] / $total ) : 0 ) ) / count( self::STAGES ) * 100 ),
			'step'     => $total > 0 ? $this->job['cursor'] . ' / ' . $total : '',
			'report'   => $this->job['done'],
			'warnings' => array_slice( $this->job['warn'], -60 ),
		);
	}

	public function cancel(): void {
		self::remove( (string) $this->job['dir'] );
	}

	private function stage_total(): int {
		if ( 'done' === $this->job['stage'] ) {
			return 0;
		}
		$data = $this->data();

		return match ( $this->job['stage'] ) {
			'media'         => min( count( (array) ( $data['media'] ?? array() ) ), (int) $this->job['options']['max_media'] ),
			'posts', 'meta' => count( (array) $data['posts'] ),
			default         => 0,
		};
	}

	/* ------------------------------------------------------------------ stages (true when finished) */

	private function stage_theme(): bool {
		$theme = (array) $this->data()['theme'];
		if ( $this->has( 'theme_files' ) ) {
			foreach ( array_unique( array( (string) $theme['template'], (string) $theme['stylesheet'] ) ) as $slug ) {
				$from = $this->job['dir'] . '/themes/' . $slug;
				if ( '' !== $slug && is_dir( $from ) && 1 === preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) ) {
					self::copy_dir( $from, get_theme_root() . '/' . $slug );
					$this->done( sprintf( /* translators: %s: theme */ __( 'Theme files of "%s" installed.', 'visual-editor' ), $slug ) );
				}
			}
			wp_clean_themes_cache();
		}
		if ( $this->job['options']['theme'] && get_stylesheet() !== $theme['stylesheet'] && ( $this->has( 'design' ) || $this->has( 'theme_files' ) ) ) {
			if ( wp_get_theme( (string) $theme['stylesheet'] )->exists() && wp_get_theme( (string) $theme['template'] )->exists() ) {
				switch_theme( (string) $theme['stylesheet'] );
				$this->done( sprintf( /* translators: %s: theme */ __( 'Theme switched to %s.', 'visual-editor' ), (string) $theme['name'] ) );
			} else {
				$this->warn( sprintf( /* translators: 1: theme name, 2: folder */ __( 'Theme "%1$s" (%2$s) is not installed here: install it, or export again with "Theme files".', 'visual-editor' ), (string) $theme['name'], (string) $theme['stylesheet'] ) );
			}
		}

		return true;
	}

	private function stage_attributes(): bool {
		if ( ! $this->has( 'products' ) || ! function_exists( 'wc_create_attribute' ) ) {
			return true;
		}
		foreach ( (array) ( $this->data()['attributes'] ?? array() ) as $attribute ) {
			if ( ! wc_attribute_taxonomy_id_by_name( (string) $attribute['name'] ) ) {
				$id = wc_create_attribute( array( 'name' => (string) $attribute['label'], 'slug' => (string) $attribute['name'], 'type' => (string) $attribute['type'], 'order_by' => (string) $attribute['orderby'], 'has_archives' => (bool) $attribute['public'] ) );
				if ( is_wp_error( $id ) ) {
					$this->warn( 'Attribute ' . $attribute['name'] . ': ' . $id->get_error_message() );
				}
			}
		}
		$this->register_attributes();

		return true;
	}

	private function stage_terms(): bool {
		$map = (array) $this->job['maps']['terms'];
		foreach ( (array) $this->data()['terms'] as $taxonomy => $terms ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) || 'nav_menu' === $taxonomy ) {
				continue;
			}
			// Parents first: repeat until no term is waiting for its parent.
			$waiting = (array) $terms;
			for ( $round = 0; $round < 10 && array() !== $waiting; $round++ ) {
				$later = array();
				foreach ( $waiting as $term ) {
					$parent = (int) $term['parent'];
					if ( $parent > 0 && ! isset( $map[ $taxonomy ][ $parent ] ) && in_array( $parent, array_column( $waiting, 'id' ), true ) ) {
						$later[] = $term;
						continue;
					}
					$args     = array( 'slug' => (string) $term['slug'], 'description' => (string) $term['description'], 'parent' => (int) ( $map[ $taxonomy ][ $parent ] ?? 0 ) );
					$existing = get_term_by( 'slug', (string) $term['slug'], $taxonomy );
					$result   = $existing instanceof \WP_Term ? wp_update_term( $existing->term_id, $taxonomy, $args + array( 'name' => (string) $term['name'] ) ) : wp_insert_term( (string) $term['name'], $taxonomy, $args );
					if ( is_wp_error( $result ) ) {
						$this->warn( $taxonomy . ' "' . $term['name'] . '": ' . $result->get_error_message() );
						continue;
					}
					$new                               = (int) $result['term_id'];
					$map[ $taxonomy ][ (int) $term['id'] ] = $new;
					foreach ( (array) $term['meta'] as $key => $values ) {
						if ( 'thumbnail_id' === $key ) {
							continue; // Set after the images are copied (stage meta).
						}
						delete_term_meta( $new, (string) $key );
						foreach ( (array) $values as $value ) {
							add_term_meta( $new, (string) $key, wp_slash( $this->rewrite( $value ) ) );
						}
					}
				}
				$waiting = $later;
			}
		}
		$this->job['maps']['terms'] = $map;
		$this->count( 'terms', array_sum( array_map( 'count', $map ) ) );

		return true;
	}

	private function stage_media( float $until ): bool {
		$media = array_slice( (array) ( $this->data()['media'] ?? array() ), 0, (int) $this->job['options']['max_media'] );
		if ( ! $this->job['options']['media'] || array() === $media ) {
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$total = count( $media );
		while ( $this->job['cursor'] < $total && microtime( true ) < $until ) {
			$item = $media[ $this->job['cursor'] ];
			++$this->job['cursor'];
			$url = (string) $item['url'];
			$new = $this->copy_file( $url );
			if ( 0 === $new ) {
				continue;
			}
			$this->job['maps']['urls'][ $url ] = (string) wp_get_attachment_url( $new );
			if ( (int) $item['id'] > 0 ) {
				$this->job['maps']['media'][ (int) $item['id'] ] = $new;
			}
		}
		if ( $this->job['cursor'] < $total ) {
			return false;
		}
		$this->done( sprintf( /* translators: 1: copied, 2: total */ __( '%1$d of %2$d images and files copied.', 'visual-editor' ), count( $this->job['maps']['urls'] ), count( (array) ( $this->data()['media'] ?? array() ) ) ) );

		return true;
	}

	private function stage_posts( float $until ): bool {
		$posts = (array) $this->data()['posts'];
		$total = count( $posts );
		while ( $this->job['cursor'] < $total && microtime( true ) < $until ) {
			$post = $posts[ $this->job['cursor'] ];
			++$this->job['cursor'];

			$type     = (string) $post['type'];
			$existing = null;
			if ( $this->job['options']['replace'] && '' !== (string) $post['slug'] && 'product_variation' !== $type ) {
				$existing = get_page_by_path( (string) $post['slug'], OBJECT, $type );
			}
			if ( null !== $existing && 'product' === $type ) {
				// The variations come again with the product.
				foreach ( get_children( array( 'post_parent' => $existing->ID, 'post_type' => 'product_variation', 'fields' => 'ids' ) ) as $child ) {
					wp_delete_post( (int) $child, true );
				}
			}
			$args = array(
				'post_type'      => $type,
				'post_name'      => (string) $post['slug'],
				'post_title'     => (string) $post['title'],
				'post_content'   => (string) $this->rewrite( (string) $post['content'] ),
				'post_excerpt'   => (string) $this->rewrite( (string) $post['excerpt'] ),
				'post_status'    => (string) $post['status'],
				'post_date'      => (string) ( $post['date'] ?? '' ),
				'menu_order'     => (int) $post['order'],
				'post_mime_type' => (string) ( $post['mime'] ?? '' ),
				'comment_status' => (string) ( $post['comment'] ?? 'closed' ),
				'post_author'    => (int) $this->job['user'],
			);
			if ( null !== $existing ) {
				$args['ID'] = $existing->ID;
			}
			$id = wp_insert_post( wp_slash( $args ), true );
			if ( is_wp_error( $id ) ) {
				$this->warn( $type . ' "' . $post['title'] . '": ' . $id->get_error_message() );
				continue;
			}
			$this->job['maps']['posts'][ (int) $post['id'] ] = (int) $id;
			$this->count( null !== $existing ? 'updated' : 'created', 1, true );
		}
		if ( $this->job['cursor'] < $total ) {
			return false;
		}
		$this->done( sprintf( /* translators: 1: created, 2: updated */ __( '%1$d pages, posts, products and layouts created, %2$d replaced.', 'visual-editor' ), (int) ( $this->job['count']['created'] ?? 0 ), (int) ( $this->job['count']['updated'] ?? 0 ) ) );

		return true;
	}

	private function stage_meta( float $until ): bool {
		$posts = (array) $this->data()['posts'];
		$total = count( $posts );
		$map   = (array) $this->job['maps']['posts'];
		while ( $this->job['cursor'] < $total && microtime( true ) < $until ) {
			$post = $posts[ $this->job['cursor'] ];
			++$this->job['cursor'];
			$id = (int) ( $map[ (int) $post['id'] ] ?? 0 );
			if ( 0 === $id ) {
				continue;
			}
			if ( (int) $post['parent'] > 0 && isset( $map[ (int) $post['parent'] ] ) ) {
				wp_update_post( array( 'ID' => $id, 'post_parent' => (int) $map[ (int) $post['parent'] ] ) );
			}
			foreach ( (array) $post['meta'] as $key => $values ) {
				delete_post_meta( $id, (string) $key );
				foreach ( (array) $values as $value ) {
					add_post_meta( $id, (string) $key, wp_slash( self::map_ids( (string) $key, $this->rewrite( $value ), (array) $this->job['maps']['media'], $map ) ) );
				}
			}
			foreach ( (array) $post['terms'] as $taxonomy => $ids ) {
				if ( ! taxonomy_exists( (string) $taxonomy ) ) {
					continue;
				}
				$new = array();
				foreach ( (array) $ids as $old ) {
					if ( isset( $this->job['maps']['terms'][ $taxonomy ][ (int) $old ] ) ) {
						$new[] = (int) $this->job['maps']['terms'][ $taxonomy ][ (int) $old ];
					}
				}
				wp_set_object_terms( $id, $new, (string) $taxonomy );
			}
			if ( 'product' === $post['type'] && function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
		}
		if ( $this->job['cursor'] < $total ) {
			return false;
		}
		// Term images, now that the images are here.
		foreach ( (array) $this->data()['terms'] as $taxonomy => $terms ) {
			foreach ( (array) $terms as $term ) {
				$old = (int) ( $term['meta']['thumbnail_id'][0] ?? 0 );
				$new = (int) ( $this->job['maps']['terms'][ $taxonomy ][ (int) $term['id'] ] ?? 0 );
				if ( $old > 0 && $new > 0 && isset( $this->job['maps']['media'][ $old ] ) ) {
					update_term_meta( $new, 'thumbnail_id', (int) $this->job['maps']['media'][ $old ] );
				}
			}
		}
		if ( $this->has( 'products' ) && function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables();
		}

		return true;
	}

	private function stage_menus(): bool {
		if ( ! $this->has( 'menus' ) ) {
			return true;
		}
		$posts = (array) $this->job['maps']['posts'];
		$map   = array();
		foreach ( (array) ( $this->data()['menus']['menus'] ?? array() ) as $menu ) {
			$existing = wp_get_nav_menu_object( (string) $menu['slug'] );
			if ( ! $existing ) {
				$existing = wp_get_nav_menu_object( (string) $menu['name'] );
			}
			$id = $existing ? (int) $existing->term_id : (int) wp_create_nav_menu( (string) $menu['name'] );
			if ( $id <= 0 ) {
				continue;
			}
			foreach ( (array) wp_get_nav_menu_items( $id, array( 'post_status' => 'any' ) ) as $old ) {
				wp_delete_post( (int) $old->ID, true );
			}
			$items = array();
			foreach ( (array) $menu['items'] as $item ) {
				$args = array(
					'menu-item-title'       => (string) $item['title'],
					'menu-item-url'         => (string) $this->rewrite( (string) $item['url'] ),
					'menu-item-target'      => (string) $item['target'],
					'menu-item-attr-title'  => (string) $item['attr'],
					'menu-item-classes'     => implode( ' ', (array) $item['classes'] ),
					'menu-item-xfn'         => (string) $item['xfn'],
					'menu-item-description' => (string) $item['desc'],
					'menu-item-position'    => (int) $item['order'],
					'menu-item-status'      => 'publish',
					'menu-item-parent-id'   => (int) ( $items[ (int) $item['parent'] ] ?? 0 ),
					'menu-item-type'        => 'custom',
				);
				$target = $this->menu_target( $item, $posts );
				if ( null !== $target ) {
					$args = array_merge( $args, $target );
				}
				$new = wp_update_nav_menu_item( $id, 0, wp_slash( $args ) );
				if ( is_wp_error( $new ) ) {
					continue;
				}
				$items[ (int) $item['id'] ] = (int) $new;
				foreach ( (array) ( $item['meta'] ?? array() ) as $key => $value ) {
					update_post_meta( (int) $new, (string) $key, wp_slash( $this->rewrite( $value ) ) );
				}
			}
			$map[ (string) $menu['slug'] ] = $id;
		}
		$this->job['maps']['menus'] = $map;
		$this->done( sprintf( /* translators: %d: menus */ __( '%d menus imported.', 'visual-editor' ), count( $map ) ) );

		return true;
	}

	/**
	 * Where a menu item points on this site: the imported page, or the page / term with the same slug here
	 * (products and categories that stayed on this site), otherwise a custom link.
	 *
	 * @param array<string, mixed> $item  Menu item.
	 * @param array<int, int>      $posts Old post id → new id.
	 * @return array<string, mixed>|null
	 */
	private function menu_target( array $item, array $posts ): ?array {
		$object = (string) $item['object'];
		if ( 'post_type' === $item['type'] ) {
			$id = (int) ( $posts[ (int) $item['objectId'] ] ?? 0 );
			if ( 0 === $id && '' !== (string) $item['slug'] ) {
				$found = get_page_by_path( (string) $item['slug'], OBJECT, $object );
				$id    = $found ? $found->ID : 0;
			}

			return $id > 0 ? array( 'menu-item-type' => 'post_type', 'menu-item-object' => $object, 'menu-item-object-id' => $id ) : null;
		}
		if ( 'taxonomy' === $item['type'] && taxonomy_exists( $object ) ) {
			$id = (int) ( $this->job['maps']['terms'][ $object ][ (int) $item['objectId'] ] ?? 0 );
			if ( 0 === $id && '' !== (string) $item['slug'] ) {
				$term = get_term_by( 'slug', (string) $item['slug'], $object );
				$id   = $term instanceof \WP_Term ? $term->term_id : 0;
			}

			return $id > 0 ? array( 'menu-item-type' => 'taxonomy', 'menu-item-object' => $object, 'menu-item-object-id' => $id ) : null;
		}
		if ( 'post_type_archive' === $item['type'] ) {
			return array( 'menu-item-type' => 'post_type_archive', 'menu-item-object' => $object );
		}

		return null;
	}

	private function stage_options(): bool {
		$data  = $this->data();
		$posts = (array) $this->job['maps']['posts'];
		$media = (array) $this->job['maps']['media'];
		$count = 0;

		if ( $this->has( 'design' ) ) {
			foreach ( (array) ( $data['options'] ?? array() ) as $name => $value ) {
				$name  = (string) $name;
				$value = $this->rewrite( $value );
				if ( str_starts_with( $name, 'theme_mods_' ) && is_array( $value ) ) {
					if ( isset( $value['custom_logo'] ) ) {
						$value['custom_logo'] = $media[ (int) $value['custom_logo'] ] ?? 0;
					}
					if ( isset( $value['custom_css_post_id'] ) ) {
						$value['custom_css_post_id'] = $posts[ (int) $value['custom_css_post_id'] ] ?? -1;
					}
					unset( $value['nav_menu_locations'] );
					$value = array_merge( (array) get_option( $name, array() ), $value );
				}
				if ( 'elementor_active_kit' === $name || ( str_starts_with( $name, 'woocommerce_' ) && str_ends_with( $name, '_page_id' ) ) || 'wp_page_for_privacy_policy' === $name ) {
					if ( ! isset( $posts[ (int) $value ] ) ) {
						continue; // That page was not imported: keep this site's.
					}
					$value = $posts[ (int) $value ];
				}
				update_option( $name, $value );
				++$count;
			}
			$reading = (array) ( $data['reading'] ?? array() );
			if ( isset( $posts[ (int) ( $reading['front'] ?? 0 ) ] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $posts[ (int) $reading['front'] ] );
			}
			if ( isset( $posts[ (int) ( $reading['posts'] ?? 0 ) ] ) ) {
				update_option( 'page_for_posts', $posts[ (int) $reading['posts'] ] );
			}
			if ( isset( $media[ (int) ( $data['siteIcon'] ?? 0 ) ] ) ) {
				update_option( 'site_icon', $media[ (int) $data['siteIcon'] ] );
			}
		}
		if ( $this->has( 'menus' ) ) {
			$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
			foreach ( (array) ( $data['menus']['locations'] ?? array() ) as $location => $slug ) {
				if ( isset( $this->job['maps']['menus'][ $slug ] ) ) {
					$locations[ $location ] = $this->job['maps']['menus'][ $slug ];
				}
			}
			set_theme_mod( 'nav_menu_locations', $locations );
		}
		if ( $this->has( 'settings' ) ) {
			foreach ( (array) ( $data['settings'] ?? array() ) as $name => $value ) {
				update_option( (string) $name, $this->rewrite( $value ) );
				++$count;
			}
		}
		if ( $count > 0 ) {
			$this->done( sprintf( /* translators: %d: settings */ __( '%d theme, widget and site settings applied.', 'visual-editor' ), $count ) );
		}

		return true;
	}

	private function stage_snippets(): bool {
		global $wpdb;
		$rows  = (array) ( $this->data()['snippets'] ?? array() );
		$table = $wpdb->prefix . 'snippets';
		if ( ! $this->has( 'snippets' ) || array() === $rows ) {
			return true;
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->warn( __( 'Code snippets were not imported: install and activate the Code Snippets plugin, then import again.', 'visual-editor' ) );
			return true;
		}
		$columns = array_flip( (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count   = 0;
		foreach ( $rows as $row ) {
			$row = array_intersect_key( (array) $this->rewrite( $row ), $columns );
			unset( $row['id'] );
			$same = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE name = %s LIMIT 1", (string) ( $row['name'] ?? '' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$same ? $wpdb->update( $table, $row, array( 'id' => (int) $same ) ) : $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			++$count;
		}
		wp_cache_delete( 'all_snippet_tags', 'code_snippets' );
		$this->done( sprintf( /* translators: %d: snippets */ __( '%d code snippets imported (active as on the old site).', 'visual-editor' ), $count ) );

		return true;
	}

	private function stage_document(): bool {
		if ( ! $this->has( 'design' ) || ! isset( $this->data()['document'] ) ) {
			return true;
		}
		$saved = Store::save( (array) $this->rewrite( $this->data()['document'] ), true, null, null, 'import from ' . wp_parse_url( (string) $this->job['source'], PHP_URL_HOST ) );
		$this->done( __( 'Visual Editor styles, elements, components, tokens and libraries imported.', 'visual-editor' ) );
		foreach ( $saved['warnings'] as $warning ) {
			$this->warn( 'Editor: ' . $warning );
		}

		return true;
	}

	private function stage_finish(): bool {
		delete_post_meta_by_key( '_elementor_css' );
		delete_option( '_elementor_global_css' );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		do_action( 'litespeed_purge_all' );
		flush_rewrite_rules();
		Store::log( 'import', 'Imported ' . implode( ', ', (array) $this->job['groups'] ) . ' from ' . $this->job['source'] );
		$this->done( __( 'Caches cleared. Import finished.', 'visual-editor' ) );
		$this->cleanup_files();

		return true;
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * @return array<string, mixed>
	 */
	private function data(): array {
		if ( null === $this->data ) {
			$data       = json_decode( (string) file_get_contents( $this->job['dir'] . '/site.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->data = is_array( $data ) ? $data : array( 'posts' => array(), 'terms' => array(), 'theme' => array() );
		}

		return $this->data;
	}

	private function has( string $group ): bool {
		return in_array( $group, (array) $this->job['groups'], true );
	}

	private function save(): void {
		file_put_contents( $this->job['dir'] . '/job.json', (string) wp_json_encode( $this->job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	private function done( string $line ): void {
		$this->job['done'][] = $line;
	}

	private function warn( string $line ): void {
		$this->job['warn'][] = $line;
	}

	private function count( string $name, int $value, bool $add = false ): void {
		$this->job['count'][ $name ] = ( $add ? (int) ( $this->job['count'][ $name ] ?? 0 ) : 0 ) + $value;
	}

	/** Product attribute taxonomies created in an earlier step are registered by WooCommerce only on init. */
	private function register_attributes(): void {
		if ( ! function_exists( 'wc_attribute_taxonomy_name' ) || ! in_array( 'products', (array) $this->job['groups'], true ) ) {
			return;
		}
		foreach ( (array) ( $this->data()['attributes'] ?? array() ) as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( (string) $attribute['name'] );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
			}
		}
	}

	/**
	 * Copies one file of the old site into this media library (the original when a resized copy was linked).
	 *
	 * @return int New attachment id, 0 when it failed.
	 */
	private function copy_file( string $url ): int {
		$name = rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$tmp  = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$this->warn( sprintf( /* translators: 1: error, 2: address */ __( 'Not copied (%1$s): %2$s', 'visual-editor' ), $tmp->get_error_message(), $url ) );
			return 0;
		}
		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			$this->warn( sprintf( /* translators: 1: error, 2: address */ __( 'Not copied (%1$s): %2$s', 'visual-editor' ), $id->get_error_message(), $url ) );
			return 0;
		}

		return (int) $id;
	}

	/**
	 * Addresses of the old site in any value: copied files first, then the site address.
	 */
	private function rewrite( mixed $value ): mixed {
		$data = $this->data();

		return self::rewrite_urls( $value, (array) $this->job['maps']['urls'], (string) ( $data['uploads'] ?? $data['source'] . '/wp-content/uploads' ), (string) $data['source'], untrailingslashit( home_url() ) );
	}

	/**
	 * Replaces addresses in every string of a value: copied files (and their resized copies) first, then the
	 * old site's address — also the "https:\/\/…" form inside JSON strings (Elementor data). Files that were not
	 * copied keep loading from the old site.
	 *
	 * @param array<string, string> $urls Old file URL → new file URL.
	 */
	public static function rewrite_urls( mixed $value, array $urls, string $uploads, string $source, string $target ): mixed {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewrite_urls( $item, $urls, $uploads, $source, $target );
			}

			return $value;
		}
		if ( ! is_string( $value ) || ! str_contains( $value, (string) wp_parse_url( $source, PHP_URL_HOST ) ) ) {
			return $value;
		}
		foreach ( array( false, true ) as $escaped ) {
			$e     = static fn ( string $s ): string => $escaped ? str_replace( '/', '\\/', $s ) : $s;
			$value = (string) preg_replace_callback(
				'#' . preg_quote( $e( $uploads ), '#' ) . '[^"\'\s\)<>]+?\.[a-z0-9]{2,5}#i',
				static function ( array $m ) use ( $urls, $e, $escaped, $source ): string {
					$plain = $escaped ? str_replace( '\\/', '/', $m[0] ) : $m[0];
					$new   = $urls[ $plain ] ?? $urls[ self::original( $plain ) ] ?? null;

					return null === $new ? str_replace( $e( $source ), "\x00SVE\x00", $m[0] ) : $e( $new );
				},
				$value
			);
			$value = str_replace( array( $e( $source ), "\x00SVE\x00" ), array( $e( $target ), $e( $source ) ), $value );
		}

		return $value;
	}

	/** "photo-300x200.jpg" → "photo.jpg", "photo-scaled.jpg" → "photo.jpg". */
	public static function original( string $url ): string {
		return (string) preg_replace( '#-(?:\d+x\d+|scaled|rotated)(\.[a-z0-9]+)$#i', '$1', $url );
	}

	/**
	 * Attachment and post ids inside meta: thumbnails, galleries, Elementor "id" next to a copied image,
	 * template references.
	 *
	 * @param array<int, int> $media Old attachment id → new id.
	 * @param array<int, int> $posts Old post id → new id.
	 */
	public static function map_ids( string $key, mixed $value, array $media, array $posts ): mixed {
		if ( '_thumbnail_id' === $key ) {
			return $media[ (int) $value ] ?? '';
		}
		if ( '_product_image_gallery' === $key ) {
			return implode( ',', array_filter( array_map( static fn ( string $id ): int => (int) ( $media[ (int) $id ] ?? 0 ), explode( ',', (string) $value ) ) ) );
		}
		if ( '_elementor_data' === $key && is_string( $value ) && array() !== $media ) {
			return (string) preg_replace_callback(
				'#"id":"?(\d+)"?#',
				static fn ( array $m ): string => isset( $media[ (int) $m[1] ] ) ? '"id":' . $media[ (int) $m[1] ] : $m[0],
				$value
			);
		}
		if ( in_array( $key, array( '_elementor_template_id', '_sve_page', '_variation_parent' ), true ) && is_numeric( $value ) ) {
			return $posts[ (int) $value ] ?? $value;
		}

		return $value;
	}

	private static function copy_dir( string $from, string $to ): void {
		wp_mkdir_p( $to );
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			$target = $to . '/' . substr( wp_normalize_path( (string) $item->getPathname() ), strlen( wp_normalize_path( $from ) ) + 1 );
			$item->isDir() ? wp_mkdir_p( $target ) : copy( (string) $item->getPathname(), $target );
		}
	}

	private function cleanup_files(): void {
		wp_delete_file( $this->job['dir'] . '/site.json' );
		if ( is_dir( $this->job['dir'] . '/themes' ) ) {
			self::remove( $this->job['dir'] . '/themes' );
		}
	}

	private static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) || ! str_starts_with( wp_normalize_path( $dir ), wp_normalize_path( SiteTransfer::folder() ) ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( (string) $item->getPathname() ) : unlink( (string) $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
