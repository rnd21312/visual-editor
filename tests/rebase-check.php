<?php
/**
 * Self-check for VisualEdits::rebase (the AI draft merge). Run: php tests/rebase-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../src/VisualEdits.php';

use VisualEditor\VisualEdits;

$item = static fn ( string $id, string $sel ): array => array( 'id' => $id, 'sel' => $sel );

$base   = array( 'css' => 'a{}', 'edits' => array( $item( 'keep', 'a' ), $item( 'gone', 'b' ), $item( 'ai', 'c' ) ), 'seo' => array( '/' => array( 'title' => 'Old' ) ) );
$draft  = array( 'css' => "a{}\nb{}", 'edits' => array( $item( 'keep', 'a' ), $item( 'ai', 'c2' ), $item( 'new', 'd' ) ), 'seo' => array( '/' => array( 'title' => 'AI' ) ) );
$live   = array( 'css' => 'x{}', 'edits' => array( $item( 'keep', 'a-live' ), $item( 'gone', 'b' ), $item( 'ai', 'c' ), $item( 'admin', 'e' ) ), 'seo' => array( '/' => array( 'title' => 'Old' ), '/p/' => array( 'title' => 'P' ) ) );
$merged = VisualEdits::rebase( $base, $draft, $live );

assert( array( 'a-live', 'c2', 'e', 'd' ) === array_column( $merged['edits'], 'sel' ), 'live edits kept, AI edits applied, AI delete applied' );
assert( 'AI' === $merged['seo']['/']['title'] && 'P' === $merged['seo']['/p/']['title'], 'SEO merged per page' );
assert( "x{}\nb{}" === $merged['css'], 'appended CSS goes after live CSS' );
$same = VisualEdits::rebase( $base, $base, $live );
assert( $live['edits'] === $same['edits'] && $live['seo'] === $same['seo'] && $live['css'] === $same['css'], 'an unchanged draft changes nothing' );

echo "ok\n";
