<?php
/**
 * Visual editor storage + public output.
 *
 * The editor saves one document: global CSS plus a list of edits, each
 * bound to a CSS selector and scoped to one page or the whole site. The editor compiles every edit to
 * CSS before saving; this class only validates it, stores it in one option and prints the part that
 * belongs to the current page (CSS in <head>, text/HTML/attribute edits as JSON for the theme bundle).
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class VisualEdits {

	public const OPTION = 'sve_document';

	public const DRAFT = 'sve_draft';

	public const REVISIONS = 'sve_revisions';

	private const MAX_REVISIONS = 15;

	/** Query argument the editor adds to its preview iframe: saved edits are not printed, the editor supplies them live. */
	public const PREVIEW_ARG = 'sve';

	private const MAX_EDITS = 500;

	private const DEVICES = array( 'base', 'tablet', 'mobile' );

	private const URL_ATTRS = array( 'href', 'src', 'poster' );

	private const TAGS = array( 'div', 'section', 'article', 'aside', 'nav', 'header', 'footer', 'main', 'figure', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'a', 'button', 'label' );

	private const POSITIONS = array( 'before', 'after', 'prepend', 'append' );

	/** Whether the document being sanitized may keep unfiltered HTML and runnable embeds (set by sanitize()). */
	private bool $unfiltered = false;

	/** md5 of the HTML already stored, which a user without unfiltered_html may keep when saving (see save()). */
	private array $known = array();

	public function hooks(): void {
		add_action( 'wp_head', array( $this, 'print_css' ), 99 );
		add_action( 'wp_head', array( $this, 'print_data' ), 98 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_runtime' ) );

		// Per-page SEO set in the editor.
		add_filter( 'stz_seo_override', array( $this, 'seo_override' ) );
		add_filter( 'stz_seo_canonical', array( $this, 'seo_canonical' ) );
		add_filter( 'get_canonical_url', array( $this, 'seo_canonical' ) );
		add_filter( 'wp_robots', array( $this, 'seo_robots' ), 20 );
		add_action( 'wp_head', array( $this, 'print_jsonld' ), 20 );
		add_action( 'wp_head', array( $this, 'print_template_meta' ), 1 );
		add_filter( 'pre_get_document_title', array( $this, 'seo_title' ), 99 );
		add_action( 'wp_head', array( $this, 'print_seo_tags' ), 5 );
	}

	/**
	 * @return array{v:int, css:string, edits:array<int, array<string, mixed>>}
	 */
	public function get(): array {
		$saved = get_option( self::OPTION, array() );

		// Stored documents were filtered when they were written: re-filtering for whoever reads them would strip
		// an administrator's embeds for every visitor.
		return $this->sanitize( is_array( $saved ) ? $saved : array(), true );
	}

	/**
	 * @param array<string, mixed> $input Document from the editor.
	 * @return array{v:int, css:string, edits:array<int, array<string, mixed>>}
	 */
	public function save( array $input ): array {
		// People without unfiltered_html (editors) have markup filtered, but must not lose what an administrator
		// put in earlier (embeds, scripts): HTML that is already stored is kept as it is.
		$this->known = current_user_can( 'unfiltered_html' ) ? array() : $this->stored_html( $this->get() );
		$doc         = $this->sanitize( $input );
		$this->known = array();

		return $this->locked(
			function () use ( $doc ): array {
				if ( ! current_user_can( 'manage_options' ) ) {
					$doc['access'] = $this->get()['access']; // Only administrators decide who gets access.
				}

				return $this->store( $doc );
			}
		);
	}

	/**
	 * @param array<string, mixed> $doc Stored document.
	 * @return array<string, true> md5 of every HTML string in it.
	 */
	private function stored_html( array $doc ): array {
		$set = array();
		foreach ( array( 'edits', 'ops', 'components' ) as $list ) {
			foreach ( (array) ( $doc[ $list ] ?? array() ) as $item ) {
				if ( is_array( $item ) && ! empty( $item['html'] ) && is_string( $item['html'] ) ) {
					$set[ md5( $item['html'] ) ] = true;
				}
			}
		}

		return $set;
	}

	/**
	 * Runs a read-modify-write of the document so concurrent requests (parallel AI tool calls, an editor save)
	 * cannot overwrite each other's changes.
	 *
	 * @template T
	 * @param callable(): T $work Reads and writes the document.
	 * @return T
	 */
	public function locked( callable $work ): mixed {
		global $wpdb;
		$name = $wpdb->prefix . 'sve_document';
		// ponytail: MySQL named lock, fine for one database server; swap for a row lock if the site ever shards.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) {
			throw new \RuntimeException( 'The document is busy. Try again in a moment.' );
		}
		try {
			// Another request may have written since this one cached the options.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( self::DRAFT, 'options' );
			wp_cache_delete( 'notoptions', 'options' );

			return $work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Writes the live document, keeping the previous one as a revision so any change can be undone.
	 *
	 * @param array<string, mixed> $doc Already sanitized document.
	 * @return array<string, mixed>
	 */
	public function store( array $doc ): array {
		$previous = get_option( self::OPTION, null );
		if ( is_array( $previous ) && array() !== $previous ) {
			$revisions   = (array) get_option( self::REVISIONS, array() );
			$revisions[] = array(
				'time' => time(),
				'doc'  => $previous,
			);
			update_option( self::REVISIONS, array_slice( $revisions, -self::MAX_REVISIONS ), false );
		}
		update_option( self::OPTION, $doc, false );

		return $doc;
	}

	/**
	 * Changes proposed by the AI API, waiting for a person to review them, replayed on top of the live document
	 * so approving a draft never reverts what was saved live after the AI started it.
	 *
	 * @return array<string, mixed>|null
	 */
	public function draft(): ?array {
		$saved = get_option( self::DRAFT, null );
		if ( ! is_array( $saved ) ) {
			return null;
		}
		$doc = is_array( $saved['doc'] ?? null ) ? self::rebase( (array) ( $saved['base'] ?? array() ), $saved['doc'], $this->get() ) : $saved;

		return $this->sanitize( $doc, true );
	}

	/**
	 * @param array<string, mixed>|null $doc Sanitized document, or null to discard the draft.
	 */
	public function set_draft( ?array $doc ): void {
		if ( null === $doc ) {
			delete_option( self::DRAFT );
		} else {
			// The live document it was based on, so draft() can replay only the AI's own changes.
			update_option( self::DRAFT, array( 'base' => $this->get(), 'doc' => $doc ), false );
		}
	}

	/**
	 * Three-way merge: the changes `$mine` made to `$base`, applied to `$theirs`. Items match by id (SEO by page
	 * key); where both sides changed the same item, `$mine` wins.
	 *
	 * @param array<string, mixed> $base   Document the draft started from.
	 * @param array<string, mixed> $mine   Draft.
	 * @param array<string, mixed> $theirs Current live document.
	 * @return array<string, mixed>
	 */
	public static function rebase( array $base, array $mine, array $theirs ): array {
		$merge = static function ( array $old, array $new, array $result ): array {
			foreach ( $old as $key => $item ) {
				if ( ! array_key_exists( $key, $new ) ) {
					unset( $result[ $key ] );
				}
			}
			foreach ( $new as $key => $item ) {
				if ( ! array_key_exists( $key, $old ) || $old[ $key ] !== $item ) {
					$result[ $key ] = $item;
				}
			}

			return $result;
		};
		$by_id = static fn ( mixed $list ): array => array_column( is_array( $list ) ? $list : array(), null, 'id' );

		$out = $theirs;
		foreach ( array( 'edits', 'ops', 'components' ) as $list ) {
			$out[ $list ] = array_values( $merge( $by_id( $base[ $list ] ?? null ), $by_id( $mine[ $list ] ?? null ), $by_id( $theirs[ $list ] ?? null ) ) );
		}
		$out['seo'] = $merge( (array) ( $base['seo'] ?? array() ), (array) ( $mine['seo'] ?? array() ), (array) ( $theirs['seo'] ?? array() ) );

		$old_css = (string) ( $base['css'] ?? '' );
		$new_css = (string) ( $mine['css'] ?? '' );
		if ( $new_css !== $old_css ) {
			// CSS the draft appended goes after the live CSS; any other change replaces it.
			$out['css'] = str_starts_with( $new_css, $old_css ) ? trim( (string) ( $theirs['css'] ?? '' ) . "\n" . trim( substr( $new_css, strlen( $old_css ) ) ) ) : $new_css;
		}

		return $out;
	}

	/**
	 * @return array<int, array{id: int, time: int, edits: int, ops: int}>
	 */
	public function revisions(): array {
		$out = array();
		foreach ( array_reverse( (array) get_option( self::REVISIONS, array() ), true ) as $id => $revision ) {
			$doc   = is_array( $revision['doc'] ?? null ) ? $revision['doc'] : array();
			$out[] = array(
				'id'    => (int) $id,
				'time'  => (int) ( $revision['time'] ?? 0 ),
				'edits' => count( (array) ( $doc['edits'] ?? array() ) ),
				'ops'   => count( (array) ( $doc['ops'] ?? array() ) ),
			);
		}

		return $out;
	}

	/**
	 * Makes an older revision the live document (the current one becomes a revision itself).
	 *
	 * @return array<string, mixed>|null
	 */
	public function restore( int $id ): ?array {
		$revisions = (array) get_option( self::REVISIONS, array() );
		if ( ! isset( $revisions[ $id ]['doc'] ) || ! is_array( $revisions[ $id ]['doc'] ) ) {
			return null;
		}
		$doc = $this->sanitize( $revisions[ $id ]['doc'], true );
		$this->store( $doc );

		return $doc;
	}

	/**
	 * @param array<string, mixed> $input      Raw document.
	 * @param bool|null            $unfiltered Keep unfiltered HTML and embeds; null = whether the current user may post them.
	 * @return array{v:int, css:string, edits:array<int, array<string, mixed>>}
	 */
	public function sanitize( array $input, ?bool $unfiltered = null ): array {
		$this->unfiltered = $unfiltered ?? current_user_can( 'unfiltered_html' );
		$edits            = array();
		$seen  = array();

		foreach ( array_slice( (array) ( $input['edits'] ?? array() ), 0, self::MAX_EDITS ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$edit = $this->sanitize_edit( $raw );
			if ( null === $edit || isset( $seen[ $edit['id'] ] ) ) {
				continue;
			}
			$seen[ $edit['id'] ] = true;
			$edits[]             = $edit;
		}

		return array(
			'v'          => 1,
			'css'        => $this->css( $input['css'] ?? '' ),
			'edits'      => $edits,
			'ops'        => $this->ops( $input['ops'] ?? array() ),
			'components' => $this->components( $input['components'] ?? array() ),
			'seo'        => $this->seo( $input['seo'] ?? array() ),
			'access'     => 'editor' === ( $input['access'] ?? '' ) ? 'editor' : 'admin',
		);
	}

	/**
	 * Capability needed to open the editor: administrators by default, editors when the admin allows it.
	 */
	public static function capability(): string {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) && 'editor' === ( $saved['access'] ?? '' ) ? 'edit_pages' : 'manage_options';
	}

	private function html( mixed $value, int $max = 50000 ): string {
		$html = substr( is_string( $value ) ? $value : '', 0, $max );

		return $this->unfiltered || isset( $this->known[ md5( $html ) ] ) ? $html : wp_kses_post( $html );
	}

	private function id( mixed $value ): string {
		$id = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', is_string( $value ) ? $value : '' ), 0, 40 );

		return '' === $id ? wp_generate_password( 12, false ) : $id;
	}

	private function selector( mixed $value ): string {
		$sel = trim( str_replace( array( '<', "\0" ), '', is_string( $value ) ? $value : '' ) );

		return strlen( $sel ) > 1000 ? '' : $sel;
	}

	/**
	 * Structural edits (insert / move / remove / re-tag), kept in the order they were made.
	 *
	 * @param mixed $raw Raw ops.
	 * @return array<int, array<string, mixed>>
	 */
	private function ops( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( array_slice( $raw, 0, self::MAX_EDITS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kind = (string) ( $item['kind'] ?? '' );
			$sel  = $this->selector( $item['sel'] ?? '' );
			if ( '' === $sel || ! in_array( $kind, array( 'insert', 'move', 'remove', 'tag' ), true ) ) {
				continue;
			}

			$op = array(
				'id'    => $this->id( $item['id'] ?? '' ),
				'kind'  => $kind,
				'scope' => $this->scope( $item['scope'] ?? '' ),
				'page'  => $this->page( (string) ( $item['page'] ?? '/' ) ),
				'tpl'   => $this->tpl( $item['tpl'] ?? '' ),
				'label' => substr( sanitize_text_field( (string) ( $item['label'] ?? '' ) ), 0, 120 ),
				'sel'   => $sel,
			);
			$pos = (string) ( $item['pos'] ?? 'after' );
			$pos = in_array( $pos, self::POSITIONS, true ) ? $pos : 'after';

			if ( 'insert' === $kind ) {
				$op['pos']  = $pos;
				$op['html'] = $this->html( $item['html'] ?? '' );
				if ( ! empty( $item['comp'] ) ) {
					$op['comp'] = $this->id( $item['comp'] );
				}
				// Embed snippets may run scripts, which only users allowed to post unfiltered HTML can add.
				if ( ! empty( $item['exec'] ) && ( $this->unfiltered || isset( $this->known[ md5( $op['html'] ) ] ) ) ) {
					$op['exec'] = true;
				}
				if ( '' === trim( $op['html'] ) && empty( $op['comp'] ) ) {
					continue;
				}
			} elseif ( 'move' === $kind ) {
				$to = $this->selector( $item['to'] ?? '' );
				if ( '' === $to ) {
					continue;
				}
				$op['to']  = $to;
				$op['pos'] = $pos;
			} elseif ( 'tag' === $kind ) {
				$tag = strtolower( (string) ( $item['tag'] ?? '' ) );
				if ( ! in_array( $tag, self::TAGS, true ) ) {
					continue;
				}
				$op['tag'] = $tag;
			}

			$out[] = $op;
		}

		return $out;
	}

	/**
	 * Reusable snippets.
	 *
	 * @param mixed $raw Raw components.
	 * @return array<int, array{id:string, name:string, html:string, css:string}>
	 */
	private function components( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( array_slice( $raw, 0, 100 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kind  = (string) ( $item['kind'] ?? 'html' );
			$out[] = array(
				'id'   => $this->id( $item['id'] ?? '' ),
				'name' => substr( sanitize_text_field( (string) ( $item['name'] ?? '' ) ), 0, 80 ),
				'html' => $this->html( $item['html'] ?? '' ),
				'css'  => $this->css( $item['css'] ?? '' ),
				'kind' => in_array( $kind, array( 'html', 'embed', 'jsonld' ), true ) ? $kind : 'html',
			);
		}

		return $out;
	}

	/**
	 * Per-page SEO overrides, keyed by page key.
	 *
	 * @param mixed $raw Raw SEO map.
	 * @return array<string, array<string, mixed>>
	 */
	private function seo( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( array_slice( $raw, 0, 200, true ) as $page => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean = array();
			foreach ( array( 'title' => 200, 'description' => 500 ) as $key => $max ) {
				$value = trim( substr( sanitize_text_field( (string) ( $item[ $key ] ?? '' ) ), 0, $max ) );
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
			if ( '' !== $json && strlen( $json ) <= 50000 && is_array( json_decode( $json, true ) ) ) {
				$clean['jsonld'] = $json;
			}

			if ( array() !== $clean ) {
				$key = (string) $page;
				$out[ str_starts_with( $key, 'tpl:' ) ? 'tpl:' . $this->tpl( substr( $key, 4 ) ) : $this->page( $key ) ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * SEO overrides of the current page.
	 *
	 * @return array<string, mixed>
	 */
	private function current_seo(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$all      = (array) $this->get()['seo'];
		$template = (array) ( $all[ 'tpl:' . self::current_template_key() ] ?? array() );
		$page     = (array) ( $all[ self::current_page() ] ?? array() );

		// Template titles and descriptions may use %title% and %site%.
		$tokens = array(
			'%title%' => self::current_title(),
			'%site%'  => (string) get_bloginfo( 'name' ),
		);
		foreach ( array( 'title', 'description' ) as $key ) {
			if ( isset( $template[ $key ] ) ) {
				$template[ $key ] = strtr( (string) $template[ $key ], $tokens );
			}
		}

		$cache = array_merge( $template, $page ); // The page's own settings win.

		return $cache;
	}

	/** Title of the thing being viewed, for the %title% token. */
	private static function current_title(): string {
		if ( is_singular() ) {
			return (string) get_the_title();
		}
		$object = get_queried_object();
		if ( $object instanceof \WP_Term ) {
			return $object->name;
		}
		if ( $object instanceof \WP_Post_Type ) {
			return (string) $object->labels->name;
		}

		return (string) get_bloginfo( 'name' );
	}

	/** Template key of the current request (see Templates::current). */
	public static function current_template_key(): string {
		static $key = null;
		if ( null === $key ) {
			$key = Templates::current()['key'];
		}

		return $key;
	}

	/**
	 * @param array<string, mixed> $out Theme SEO fields.
	 * @return array<string, mixed>
	 */
	public function seo_override( array $out ): array {
		$seo = $this->current_seo();
		foreach ( array( 'title', 'description', 'image' ) as $key ) {
			if ( ! empty( $seo[ $key ] ) ) {
				$out[ $key ] = (string) $seo[ $key ];
			}
		}
		if ( ! empty( $seo['noindex'] ) ) {
			$out['noindex'] = true;
		}

		return $out;
	}

	public function seo_canonical( mixed $url ): mixed {
		$canonical = (string) ( $this->current_seo()['canonical'] ?? '' );

		return '' !== $canonical ? $canonical : $url;
	}

	/**
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public function seo_robots( array $robots ): array {
		$seo = $this->current_seo();
		if ( ! empty( $seo['noindex'] ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		if ( ! empty( $seo['nofollow'] ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		return $robots;
	}

	/** Only in the editor's preview: which template renders this URL. */
	public function print_template_meta(): void {
		if ( ! self::is_editor_preview() ) {
			return;
		}
		$template = Templates::current();
		printf(
			'<meta name="sve-template" content="%1$s" data-label="%2$s" data-templated="%3$s" data-count="%4$d">' . "\n",
			esc_attr( $template['key'] ),
			esc_attr( $template['label'] ),
			$template['templated'] ? '1' : '0',
			(int) $template['count']
		);
	}

	public function print_jsonld(): void {
		$json = (string) ( $this->current_seo()['jsonld'] ?? '' );
		$data = '' === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HEX-escaped JSON.
		);
	}

	/**
	 * @param array<string, mixed> $raw Raw edit.
	 * @return array<string, mixed>|null
	 */
	private function sanitize_edit( array $raw ): ?array {
		$sel = trim( str_replace( '<', '', (string) ( $raw['sel'] ?? '' ) ) );
		if ( '' === $sel || strlen( $sel ) > 1000 ) {
			return null;
		}

		$id = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $raw['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = wp_generate_password( 12, false );
		}

		$edit = array(
			'id'       => substr( $id, 0, 40 ),
			'scope'    => $this->scope( $raw['scope'] ?? '' ),
			'page'     => $this->page( (string) ( $raw['page'] ?? '/' ) ),
			'tpl'      => $this->tpl( $raw['tpl'] ?? '' ),
			'sel'      => $sel,
			'label'    => substr( sanitize_text_field( (string) ( $raw['label'] ?? '' ) ), 0, 120 ),
			'styles'   => $this->styles( $raw['styles'] ?? array() ),
			'css'      => $this->css( $raw['css'] ?? '' ),
			'compiled' => $this->css( $raw['compiled'] ?? '' ),
		);

		$states = array();
		foreach ( array( 'hover', 'focus', 'active' ) as $state ) {
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
			$edit['text'] = substr( (string) wp_check_invalid_utf8( $raw['text'] ), 0, 20000 );
		}
		if ( isset( $raw['html'] ) && is_string( $raw['html'] ) ) {
			$edit['html'] = $this->html( $raw['html'] );
		}

		$attrs = $this->attrs( $raw['attrs'] ?? array() );
		if ( array() !== $attrs ) {
			$edit['attrs'] = $attrs;
		}

		return $edit;
	}

	private function css( mixed $value ): string {
		// "<" never occurs in valid CSS outside strings; dropping it makes breaking out of <style> impossible.
		return substr( str_replace( array( '<', "\0" ), '', is_string( $value ) ? $value : '' ), 0, 200000 );
	}

	private function scope( mixed $value ): string {
		return in_array( $value, array( 'site', 'template' ), true ) ? (string) $value : 'page';
	}

	/** Template key such as "single:product" or "tax:category". */
	private function tpl( mixed $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9:_\-.\/]/', '', is_string( $value ) ? $value : '' ), 0, 120 );
	}

	/**
	 * Whether an edit or op belongs on the page being rendered.
	 *
	 * @param array<string, mixed> $item Edit or op.
	 */
	private function applies( array $item, string $page, string $tpl ): bool {
		return match ( (string) $item['scope'] ) {
			'site'     => true,
			'template' => '' !== $tpl && (string) ( $item['tpl'] ?? '' ) === $tpl,
			default    => (string) $item['page'] === $page,
		};
	}

	private function page( string $value ): string {
		$value = '/' . trim( (string) preg_replace( '#[^A-Za-z0-9_\-./%~]#', '', $value ), '/' );

		return '/' === $value ? '/' : substr( $value, 0, 300 ) . '/';
	}

	/**
	 * @param mixed $raw Device → property → value.
	 * @return array<string, array<string, string>>
	 */
	private function styles( mixed $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( self::DEVICES as $device ) {
			$props = array();
			foreach ( array_slice( (array) ( $raw[ $device ] ?? array() ), 0, 200, true ) as $prop => $value ) {
				$prop = strtolower( (string) $prop );
				if ( ! preg_match( '/^(--[a-z0-9_-]+|[a-z-]+)$/', $prop ) || ! is_scalar( $value ) ) {
					continue;
				}
				$value = trim( str_replace( array( '<', "\0" ), '', (string) $value ) );
				if ( '' !== $value && strlen( $value ) <= 600 ) {
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

		foreach ( array_slice( $raw, 0, 40, true ) as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( ! Guard::attribute( $name ) ) { // Event handlers, style and srcdoc are never allowed.
				continue;
			}
			if ( null === $value ) {
				$out[ $name ] = null;
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = substr( (string) $value, 0, 2000 );
			if ( in_array( $name, self::URL_ATTRS, true ) ) {
				$value = '' === $value ? '' : esc_url_raw( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$out[ $name ] = $value;
		}

		return $out;
	}

	/**
	 * Page key of the current request, relative to the site's home path ("/", "/tours/", …).
	 */
	public static function current_page(): string {
		return self::normalize_path( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Page key ("/", "/tours/phuket/") of a URL of this site, or null for other sites / empty input.
	 */
	public static function page_key_for_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return null;
		}

		return self::normalize_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	private static function normalize_path( string $path ): string {
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path = trim( rawurldecode( $path ), '/' );

		return '' === $path ? '/' : '/' . $path . '/';
	}

	/**
	 * True while the visual editor previews the site: the saved edits are left out because the editor applies its own copy live.
	 */
	public static function is_editor_preview(): bool {
		return isset( $_GET[ self::PREVIEW_ARG ] ) && current_user_can( self::capability() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Edits that apply to a page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function for_page( string $page ): array {
		return array_values(
			array_filter(
				$this->get()['edits'],
				fn ( array $edit ): bool => $this->applies( $edit, $page, self::current_template_key() )
			)
		);
	}

	public function print_css(): void {
		if ( is_admin() || self::is_editor_preview() ) {
			return;
		}

		$doc = $this->get();
		$css = array();
		foreach ( $doc['components'] as $component ) {
			if ( '' !== trim( $component['css'] ) ) {
				$css[] = $component['css'];
			}
		}
		if ( '' !== trim( $doc['css'] ) ) {
			$css[] = $doc['css'];
		}
		foreach ( $this->for_page( self::current_page() ) as $edit ) {
			if ( '' !== trim( (string) $edit['compiled'] ) ) {
				$css[] = $edit['compiled'];
			}
		}

		if ( array() !== $css ) {
			printf( '<style id="sve-css">%s</style>' . "\n", implode( "\n", $css ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- "<" stripped on save.
		}
	}

	/**
	 * Content edits and structural ops that belong to the current page (what the runtime replays).
	 *
	 * @return array{edits: array<int, array<string, mixed>>, ops: array<int, array<string, mixed>>}
	 */
	private function replay_payload(): array {
		$doc     = $this->get();
		$page    = self::current_page();
		$content = array();

		foreach ( $this->for_page( $page ) as $edit ) {
			if ( ! isset( $edit['text'] ) && ! isset( $edit['html'] ) && empty( $edit['attrs'] ) ) {
				continue;
			}
			$item = array( 'sel' => $edit['sel'] );
			foreach ( array( 'text', 'html', 'attrs' ) as $key ) {
				if ( isset( $edit[ $key ] ) ) {
					$item[ $key ] = $edit[ $key ];
				}
			}
			$content[] = $item;
		}

		$components = array();
		$embeds     = array();
		foreach ( $doc['components'] as $component ) {
			$components[ $component['id'] ] = $component['html'];
			if ( 'embed' === $component['kind'] ) {
				$embeds[ $component['id'] ] = true;
			}
		}
		$ops = array();
		foreach ( $doc['ops'] as $op ) {
			if ( ! $this->applies( $op, $page, self::current_template_key() ) ) {
				continue;
			}
			// A component reference is replaced by its current markup, so the page needs no library.
			if ( 'insert' === $op['kind'] && ! empty( $op['comp'] ) && isset( $components[ $op['comp'] ] ) ) {
				$op['html'] = $components[ $op['comp'] ];
				if ( isset( $embeds[ $op['comp'] ] ) ) {
					$op['exec'] = true; // embed snippets bring scripts that must run
				}
			}
			$ops[] = $op;
		}

		return array(
			'edits' => $content,
			'ops'   => $ops,
		);
	}

	private function skip_output(): bool {
		return is_admin() || self::is_editor_preview();
	}

	public function print_data(): void {
		if ( $this->skip_output() ) {
			return;
		}

		$payload = $this->replay_payload();
		if ( array() === $payload['edits'] && array() === $payload['ops'] ) {
			return;
		}

		printf(
			'<script type="application/json" id="sve-data">%s</script>' . "\n",
			wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HEX-escaped JSON.
		);
	}

	/**
	 * Loads the small runtime on pages that have text, HTML, attribute or structure edits (CSS needs none).
	 */
	public function enqueue_runtime(): void {
		if ( $this->skip_output() ) {
			return;
		}

		$payload = $this->replay_payload();
		$url     = Assets::runtime_url();
		if ( null === $url || ( array() === $payload['edits'] && array() === $payload['ops'] ) ) {
			return;
		}

		// No ?ver=: the module imports its own chunks by bare URL, hashed file names already bust caches.
		wp_enqueue_script_module( 'sve-runtime', $url, array(), null );
	}

	/* ------------------------------------------------------------------ SEO for themes without their own hooks */

	/** Themes that read the stz_seo_* filters themselves (they define STZ_THEME_VERSION) print their own tags; everywhere else this plugin does. */
	private function prints_own_seo(): bool {
		return ! defined( 'STZ_THEME_VERSION' ) && ! $this->seo_plugin_active();
	}

	private function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}

	public function seo_title( mixed $title ): mixed {
		$custom = (string) ( $this->current_seo()['title'] ?? '' );

		return '' !== $custom && $this->prints_own_seo() ? $custom : $title;
	}

	public function print_seo_tags(): void {
		if ( ! $this->prints_own_seo() || is_admin() ) {
			return;
		}

		$seo = $this->current_seo();
		if ( ! empty( $seo['description'] ) ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( (string) $seo['description'] ) );
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( (string) $seo['description'] ) );
		}
		if ( ! empty( $seo['title'] ) ) {
			printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( (string) $seo['title'] ) );
		}
		if ( ! empty( $seo['image'] ) ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( (string) $seo['image'] ) );
			printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( (string) $seo['image'] ) );
		}
		// Core prints (and lets us filter) the canonical of single posts and pages only.
		if ( ! empty( $seo['canonical'] ) && ( ! is_singular() || ! has_action( 'wp_head', 'rel_canonical' ) ) ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( (string) $seo['canonical'] ) );
		}
	}
}
