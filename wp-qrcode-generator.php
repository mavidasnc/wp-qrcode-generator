<?php
/**
 * Plugin Name:       WP QRCode Generator
 * Plugin URI:        https://github.com/mavidasnc/wp-qrcode-generator
 * Description:       Genera codici QR che fanno redirect o mostrano contenuti e allegati, con statistiche di scansione giorno per giorno.
 * Version:           0.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Mavida
 * Author URI:        https://www.mavida.com
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-qrcode-generator
 * Update URI:        https://github.com/mavidasnc/wp-qrcode-generator
 *
 * @package Mavida\QRCodeGenerator
 */

defined( 'ABSPATH' ) || exit;

define( 'WPQR_VERSION', '0.4.0' );
define( 'WPQR_FILE', __FILE__ );
define( 'WPQR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPQR_URL', plugin_dir_url( __FILE__ ) );

// La libreria QR e l'autoload delle classi arrivano da Composer (vendor/ va distribuita con il plugin).
if ( ! is_readable( WPQR_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WP QRCode Generator: dipendenze mancanti. Esegui "composer install --no-dev" nella cartella del plugin.', 'wp-qrcode-generator' );
			echo '</p></div>';
		}
	);
	return;
}

require_once WPQR_PATH . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \Mavida\QRCodeGenerator\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

add_action( 'plugins_loaded', array( \Mavida\QRCodeGenerator\Plugin::class, 'init' ) );
