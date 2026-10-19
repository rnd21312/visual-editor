<?php
/**
 * Full-page shell of builder pages and templates in "canvas" (builder header + footer) or "blank" mode.
 *
 * @package VisualEditor
 */

use VisualEditor\Builder\Router;

defined( 'ABSPATH' ) || exit;

$sve_mode = (string) ( $GLOBALS['sve_shell']['mode'] ?? 'canvas' );
$sve_main = Router::main_content(); // Rendered before wp_head so components, forms and islands are known.
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php
if ( 'canvas' === $sve_mode ) {
	echo Router::part_html( 'header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered builder output.
}
?>
<main id="sve-main" class="sve-main"><?php echo $sve_main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></main>
<?php
if ( 'canvas' === $sve_mode ) {
	echo Router::part_html( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
wp_footer();
?>
</body>
</html>
