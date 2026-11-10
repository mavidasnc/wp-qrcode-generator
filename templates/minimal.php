<?php
/**
 * Pagina minimale di un QR: solo titolo, contenuto e allegati, senza header
 * e footer del tema. Per personalizzarla copia questo file in
 * {tema}/wpqr/minimal.php.
 *
 * @package Mavida\QRCodeGenerator
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'wpqr-minimal' ); ?>>
<?php wp_body_open(); ?>
<main class="wpqr-minimal__main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_title( '<h1 class="wpqr-minimal__title">', '</h1>' );
		echo '<div class="wpqr-minimal__content">';
		the_content();
		echo '</div>';
	endwhile;
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
