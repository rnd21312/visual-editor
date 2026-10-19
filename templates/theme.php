<?php
/**
 * Builder template rendered inside the theme's own header and footer (classic and block themes).
 *
 * @package VisualEditor
 */

use VisualEditor\Builder\Router;

defined( 'ABSPATH' ) || exit;

$sve_main = Router::main_content();

if ( wp_is_block_theme() ) {
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php block_template_part( 'header' ); ?>
<main id="sve-main" class="sve-main"><?php echo $sve_main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></main>
	<?php block_template_part( 'footer' ); ?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
	return;
}

get_header();
?>
<main id="sve-main" class="sve-main"><?php echo $sve_main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></main>
<?php
get_footer();
