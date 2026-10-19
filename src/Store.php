<?php
/**
 * The editor document: storage, revisions, AI drafts and three-way merging.
 *
 * Every write bumps `rev`. A client that saves with an outdated `rev` gets a conflict instead of silently
 * overwriting someone else's work; it can then ask for a merge (its base + its version + the live one).
 * AI drafts remember the live document they started from, so publishing a draft later merges it into whatever
 * the site looks like by then instead of replacing it.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Store {

	public const OPTION = 'sve_document';

	public const DRAFT = 'sve_draft';

	public const REVISIONS = 'sve_revisions';

	public const ACTIVITY = 'sve_activity';

	private const MAX_REVISIONS = 30;

	private const MAX_ACTIVITY = 150;

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/** @var array<string, true> Named locks this request holds. */
	private static array $held = array();

	/** Inside a batch: only the first write archives a revision, so a batch is one step to undo. */
	private static bool $batching = false;

	private static bool $archived = false;

	/**
	 * Runs several writes as one: one lock, one revision, and if any of them fails the document and the AI draft
	 * go back to how they were (writes to posts and media are not part of this).
	 *
	 * @param callable(): mixed $work Work to run.
	 */
	public static function batch( callable $work ): mixed {
		return self::locked(
			static function () use ( $work ): mixed {
				$option = get_option( self::OPTION, null );
				$draft  = get_option( self::DRAFT, null );
				self::$batching = true;
				self::$archived = false;
				$revisions      = self::revisions_raw();
				try {
					return $work();
				} catch ( \Throwable $error ) {
					null === $option ? delete_option( self::OPTION ) : update_option( self::OPTION, $option, false );
					null === $draft ? delete_option( self::DRAFT ) : update_option( self::DRAFT, $draft, false );
					update_option( self::REVISIONS, $revisions, false );
					self::$cache = null;
					Cache::purge();
					throw $error;
				} finally {
					self::$batching = false;
					self::$archived = false;
				}
			}
		);
	}

	/** @var array<string, mixed>|null Unsaved document of the editor, used while it previews the site. */
	private static ?array $working = null;

	public const WORKING_META = 'sve_working_doc';

	/**
	 * The live document (validated when it was written; only shaped here).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		if ( null !== self::$working ) {
			return self::$working;
		}
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = Sanitizer::shape( is_array( $saved ) ? $saved : array() );
			Compiler::set_breakpoints( (array) self::$cache['settings']['breakpoints'] );
		}

		return self::$cache;
	}

	/**
	 * The live document, never the editor's working copy.
	 *
	 * @return array<string, mixed>
	 */
	public static function live(): array {
		$working       = self::$working;
		self::$working = null;
		$doc           = self::get();
		self::$working = $working;

		return $doc;
	}

	/**
	 * In the editor's preview: render with the editor's unsaved document (components, tokens, libraries…).
	 */
	public static function use_working(): void {
		$doc = get_user_meta( get_current_user_id(), self::WORKING_META, true );
		if ( is_array( $doc ) && array() !== $doc ) {
			self::$working = Sanitizer::shape( $doc );
			Compiler::set_breakpoints( (array) self::$working['settings']['breakpoints'] );
		}
	}

	/**
	 * Stores the editor's unsaved document for its preview (validated like a save, per user).
	 *
	 * @param array<string, mixed>|null $doc Document, or null to forget it.
	 */
	public static function set_working( ?array $doc ): void {
		if ( null === $doc ) {
			delete_user_meta( get_current_user_id(), self::WORKING_META );
			return;
		}
		$sanitizer = new Sanitizer( Sanitizer::user_trusted(), self::live() );
		update_user_meta( get_current_user_id(), self::WORKING_META, $sanitizer->document( $doc ) );
	}

	/**
	 * Capability needed to open the editor: administrators by default, editors when an administrator allows it.
	 */
	public static function capability(): string {
		return 'editor' === ( self::live()['access'] ?? '' ) ? 'edit_pages' : 'manage_options';
	}

	/**
	 * Validates and saves a document sent by a client.
	 *
	 * @param array<string, mixed>      $input   Document.
	 * @param bool                      $trusted Author may post unfiltered HTML / code.
	 * @param int|null                  $base    Revision the client started from (null = do not check).
	 * @param array<string, mixed>|null $merge_base Client's base document: merge instead of failing on conflict.
	 * @return array{ok: bool, doc: array<string, mixed>, warnings: array<int, string>, conflict?: bool, merged?: bool}
	 */
	public static function save( array $input, bool $trusted, ?int $base = null, ?array $merge_base = null, string $actor = '' ): array {
		$live   = self::live();
		$merged = false;
		if ( null !== $base && $base !== (int) $live['rev'] ) {
			if ( null === $merge_base ) {
				return array(
					'ok'       => false,
					'conflict' => true,
					'doc'      => $live,
					'warnings' => array(),
				);
			}
			$input  = self::merge( $merge_base, $input, $live );
			$merged = true;
		}

		$sanitizer = new Sanitizer( $trusted, $live );
		$doc       = $sanitizer->document( $input );
		if ( ! current_user_can( 'manage_options' ) && ! self::acting_as_admin_key() ) {
			$doc['access'] = $live['access']; // Only administrators decide who gets access.
		}
		$doc = self::store( $doc, $actor );

		return array(
			'ok'       => true,
			'doc'      => $doc,
			'warnings' => $sanitizer->warnings(),
			'merged'   => $merged,
		);
	}

	private static function acting_as_admin_key(): bool {
		return 'admin' === Api::current_level();
	}

	/**
	 * Writes an already sanitized document as the live one, keeping the previous one as a revision.
	 *
	 * @param array<string, mixed> $doc Sanitized document.
	 * @return array<string, mixed>
	 */
	public static function store( array $doc, string $actor = '', string $label = '' ): array {
		return self::locked( static fn (): array => self::store_unlocked( $doc, $actor, $label ) );
	}

	/**
	 * Read-modify-write under a database lock so two requests (admin save and an AI write) cannot overwrite each other.
	 *
	 * @param callable(): mixed $work Work to run alone.
	 */
	public static function locked( callable $work, string $lock = 'sve_document' ): mixed {
		global $wpdb;
		$name = $wpdb->prefix . $lock;
		if ( isset( self::$held[ $name ] ) ) {
			return $work(); // Already inside this lock (a batch): GET_LOCK is not re-entrant on every MySQL.
		}
		// ponytail: MySQL named lock, fine for one database server; swap for a row lock if the site ever shards.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) {
			throw new \RuntimeException( 'The document is busy. Try again in a moment.' );
		}
		self::$held[ $name ] = true;
		try {
			// Another request may have written since this one cached the options.
			foreach ( array( self::OPTION, self::DRAFT, self::REVISIONS, OAuth::OPTION, 'notoptions' ) as $option ) {
				wp_cache_delete( $option, 'options' );
			}
			self::$cache = null;

			return $work();
		} finally {
			unset( self::$held[ $name ] );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * @param array<string, mixed> $doc Sanitized document.
	 * @return array<string, mixed>
	 */
	private static function store_unlocked( array $doc, string $actor, string $label ): array {
		$previous = get_option( self::OPTION, null );
		if ( is_array( $previous ) && array() !== $previous && ! self::$archived ) {
			self::$archived = self::$batching;
			$revisions   = self::revisions_raw();
			$revisions[] = array(
				'id'    => Sanitizer::new_id(),
				'time'  => time(),
				'by'    => '' !== $actor ? $actor : self::actor(),
				'label' => '' !== $label ? 'Before: ' . $label : '',
				'doc'   => $previous,
			);
			update_option( self::REVISIONS, array_slice( $revisions, -self::MAX_REVISIONS ), false );
		}
		$doc['rev']     = (int) ( is_array( $previous ) ? ( $previous['rev'] ?? 0 ) : 0 ) + 1;
		$doc['updated'] = time();
		update_option( self::OPTION, $doc, false );
		self::$cache = null;
		Cache::purge();

		return self::live();
	}

	/**
	 * Who is acting ("user:3", "ai:Claude key").
	 */
	public static function actor(): string {
		$key = Api::current_key();
		if ( null !== $key ) {
			return 'ai:' . $key['label'];
		}
		$user = wp_get_current_user();

		return $user->exists() ? 'user:' . $user->display_name : 'system';
	}

	/* ------------------------------------------------------------------ revisions */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function revisions_raw(): array {
		$list = get_option( self::REVISIONS, array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $index => $rev ) {
			if ( ! is_array( $rev ) || ! is_array( $rev['doc'] ?? null ) ) {
				continue;
			}
			$rev['id'] = (string) ( $rev['id'] ?? 'r' . $index . '-' . ( $rev['time'] ?? 0 ) ); // v1 revisions had no id.
			$out[]     = $rev;
		}

		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function revisions(): array {
		$out = array();
		foreach ( array_reverse( self::revisions_raw() ) as $rev ) {
			$doc   = $rev['doc'];
			$out[] = array(
				'id'         => $rev['id'],
				'time'       => (int) ( $rev['time'] ?? 0 ),
				'by'         => (string) ( $rev['by'] ?? '' ),
				'label'      => (string) ( $rev['label'] ?? '' ),
				'rev'        => (int) ( $doc['rev'] ?? 0 ),
				'edits'      => count( (array) ( $doc['edits'] ?? array() ) ),
				'ops'        => count( (array) ( $doc['ops'] ?? array() ) ),
				'components' => count( (array) ( $doc['components'] ?? array() ) ),
			);
		}

		return $out;
	}

	/**
	 * Makes an older revision the live document (the current one becomes a revision itself).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function restore( string $id ): ?array {
		foreach ( self::revisions_raw() as $rev ) {
			if ( $rev['id'] === $id ) {
				return self::store( Sanitizer::shape( $rev['doc'] ), '', 'Restored revision ' . $id );
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ AI drafts */

	/**
	 * Changes proposed by an AI key with the "draft" level.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function draft(): ?array {
		$saved = get_option( self::DRAFT, null );
		if ( ! is_array( $saved ) ) {
			return null;
		}
		$doc = is_array( $saved['doc'] ?? null ) ? $saved['doc'] : $saved; // v1 stored the bare document.

		return Sanitizer::shape( $doc );
	}

	/**
	 * Document the draft started from.
	 *
	 * @return array<string, mixed>
	 */
	public static function draft_base(): array {
		$saved = get_option( self::DRAFT, null );

		return is_array( $saved['base'] ?? null ) ? Sanitizer::shape( $saved['base'] ) : self::get();
	}

	/**
	 * @param array<string, mixed>|null $doc Sanitized draft, or null to discard it.
	 */
	public static function set_draft( ?array $doc ): void {
		if ( null === $doc ) {
			delete_option( self::DRAFT );
			return;
		}
		$saved = get_option( self::DRAFT, null );
		$base  = is_array( $saved['base'] ?? null ) ? $saved['base'] : self::get();
		update_option(
			self::DRAFT,
			array(
				'doc'  => $doc,
				'base' => $base,
				'time' => time(),
			),
			false
		);
	}

	/**
	 * The document an AI draft write starts from: the pending draft, else the live document.
	 *
	 * @return array<string, mixed>
	 */
	public static function working_draft(): array {
		return self::draft() ?? self::get();
	}

	/**
	 * Publishes the AI draft by merging it into the current live document.
	 *
	 * @return array<string, mixed>|null The new live document, or null without a draft.
	 */
	public static function publish_draft(): ?array {
		$draft = self::draft();
		if ( null === $draft ) {
			return null;
		}
		$merged    = self::merge( self::draft_base(), $draft, self::get() );
		$sanitizer = new Sanitizer( false, self::get() );
		$doc       = self::store( $sanitizer->document( $merged ), '', 'Published AI draft' );
		self::set_draft( null );

		return $doc;
	}

	/**
	 * How many edits, ops, components and settings differ between two documents.
	 *
	 * @param array<string, mixed> $live  Live document.
	 * @param array<string, mixed> $draft Proposed document.
	 */
	public static function difference( array $live, array $draft ): int {
		return count( self::changes( $live, $draft ) );
	}

	/**
	 * Readable list of what a draft changes compared with the live document.
	 *
	 * @param array<string, mixed> $live  Live document.
	 * @param array<string, mixed> $draft Proposed document.
	 * @return array<int, array{type: string, action: string, id: string, label: string}>
	 */
	public static function changes( array $live, array $draft ): array {
		$out = array();
		foreach ( array( 'edits', 'ops', 'components', 'scripts', 'libraries' ) as $list ) {
			$before = self::by_id( (array) ( $live[ $list ] ?? array() ) );
			$after  = self::by_id( (array) ( $draft[ $list ] ?? array() ) );
			foreach ( $after as $id => $item ) {
				if ( ! isset( $before[ $id ] ) ) {
					$out[] = array( 'type' => $list, 'action' => 'added', 'id' => $id, 'label' => self::item_label( $item ) );
				} elseif ( ! Sanitizer::same( $before[ $id ], $item ) ) {
					$out[] = array( 'type' => $list, 'action' => 'changed', 'id' => $id, 'label' => self::item_label( $item ) );
				}
			}
			foreach ( $before as $id => $item ) {
				if ( ! isset( $after[ $id ] ) ) {
					$out[] = array( 'type' => $list, 'action' => 'removed', 'id' => $id, 'label' => self::item_label( $item ) );
				}
			}
		}
		$seo_before = (array) ( $live['seo'] ?? array() );
		$seo_after  = (array) ( $draft['seo'] ?? array() );
		foreach ( array_unique( array_merge( array_keys( $seo_before ), array_keys( $seo_after ) ) ) as $key ) {
			if ( wp_json_encode( $seo_before[ $key ] ?? null ) !== wp_json_encode( $seo_after[ $key ] ?? null ) ) {
				$out[] = array( 'type' => 'seo', 'action' => isset( $seo_after[ $key ] ) ? 'changed' : 'removed', 'id' => (string) $key, 'label' => 'SEO ' . $key );
			}
		}
		foreach ( array( 'css', 'tokens', 'tailwind', 'settings' ) as $key ) {
			if ( wp_json_encode( $live[ $key ] ?? null ) !== wp_json_encode( $draft[ $key ] ?? null ) ) {
				$out[] = array( 'type' => $key, 'action' => 'changed', 'id' => $key, 'label' => $key );
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	private static function item_label( array $item ): string {
		return (string) ( $item['label'] ?? $item['name'] ?? $item['sel'] ?? $item['id'] ?? '' );
	}

	/**
	 * @param array<int, mixed> $list List of items with ids.
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_id( array $list ): array {
		$out = array();
		foreach ( $list as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$out[ (string) $item['id'] ] = $item;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ merging */

	/**
	 * Three-way merge: changes from `$mine` (relative to `$base`) are applied onto `$theirs`.
	 * Items are matched by id; when both sides changed the same item, `$mine` wins.
	 *
	 * @param array<string, mixed> $base   Common ancestor.
	 * @param array<string, mixed> $mine   Version being saved.
	 * @param array<string, mixed> $theirs Current live version.
	 * @return array<string, mixed>
	 */
	public static function merge( array $base, array $mine, array $theirs ): array {
		$out = $theirs;
		foreach ( array( 'edits', 'ops', 'components', 'scripts', 'libraries' ) as $list ) {
			$out[ $list ] = self::merge_list( (array) ( $base[ $list ] ?? array() ), (array) ( $mine[ $list ] ?? array() ), (array) ( $theirs[ $list ] ?? array() ) );
		}
		$out['seo'] = self::merge_map( (array) ( $base['seo'] ?? array() ), (array) ( $mine['seo'] ?? array() ), (array) ( $theirs['seo'] ?? array() ) );
		foreach ( array( 'tokens', 'settings', 'tailwind' ) as $key ) {
			$out[ $key ] = self::merge_map( (array) ( $base[ $key ] ?? array() ), (array) ( $mine[ $key ] ?? array() ), (array) ( $theirs[ $key ] ?? array() ) );
		}
		foreach ( array( 'css', 'access' ) as $key ) {
			$b = $base[ $key ] ?? null;
			$m = $mine[ $key ] ?? null;
			$t = $theirs[ $key ] ?? null;
			if ( $m !== $b ) {
				$out[ $key ] = ( 'css' === $key && $t !== $b && is_string( $m ) && is_string( $b ) && is_string( $t ) && str_starts_with( $m, $b ) )
					? $t . substr( $m, strlen( $b ) ) // Both changed: keep theirs and append what mine added.
					: $m;
			}
		}

		return $out;
	}

	/**
	 * @param array<int, mixed> $base   Base list.
	 * @param array<int, mixed> $mine   Mine.
	 * @param array<int, mixed> $theirs Theirs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_list( array $base, array $mine, array $theirs ): array {
		$b   = self::by_id( $base );
		$m   = self::by_id( $mine );
		$t   = self::by_id( $theirs );
		$out = array();
		foreach ( $t as $id => $item ) {
			$in_base = isset( $b[ $id ] );
			if ( ! isset( $m[ $id ] ) ) {
				if ( $in_base && Sanitizer::same( $b[ $id ], $item ) ) {
					continue; // Mine deleted it, theirs did not touch it.
				}
				$out[] = $item;
				continue;
			}
			$out[] = ( $in_base && Sanitizer::same( $b[ $id ], $m[ $id ] ) ) ? $item : $m[ $id ];
		}
		foreach ( $m as $id => $item ) {
			if ( ! isset( $t[ $id ] ) && ! isset( $b[ $id ] ) ) {
				$out[] = $item; // New in mine.
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $base   Base map.
	 * @param array<string, mixed> $mine   Mine.
	 * @param array<string, mixed> $theirs Theirs.
	 * @return array<string, mixed>
	 */
	private static function merge_map( array $base, array $mine, array $theirs ): array {
		$out = $theirs;
		foreach ( array_unique( array_merge( array_keys( $base ), array_keys( $mine ) ) ) as $key ) {
			$b = $base[ $key ] ?? null;
			$m = $mine[ $key ] ?? null;
			if ( wp_json_encode( $b ) === wp_json_encode( $m ) ) {
				continue;
			}
			if ( null === $m ) {
				unset( $out[ $key ] );
			} else {
				$out[ $key ] = $m;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ activity */

	/**
	 * Remembers what someone (person or AI) did, for the editor's activity feed.
	 */
	public static function log( string $action, string $summary, array $extra = array() ): void {
		$list   = get_option( self::ACTIVITY, array() );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array_merge(
			array(
				'id'      => Sanitizer::new_id(),
				'time'    => microtime( true ),
				'actor'   => self::actor(),
				'action'  => $action,
				'summary' => mb_substr( $summary, 0, 300 ),
			),
			$extra
		);
		update_option( self::ACTIVITY, array_slice( $list, -self::MAX_ACTIVITY ), false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function activity( float $since = 0 ): array {
		$list = get_option( self::ACTIVITY, array() );

		return array_values( array_filter( is_array( $list ) ? $list : array(), static fn ( $item ): bool => is_array( $item ) && (float) ( $item['time'] ?? 0 ) > $since ) );
	}
}
