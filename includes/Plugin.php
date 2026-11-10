<?php
/**
 * Bootstrap del plugin.
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap del plugin.
 */
final class Plugin {

	/**
	 * Inizializza i componenti; Admin solo in wp-admin.
	 */
	public static function init(): void {
		PostType::init();
		Generator::init();
		Tracker::init();
		Frontend::init();

		if ( is_admin() ) {
			Admin::init();
		}
	}

	/**
	 * All'attivazione crea le tabelle e rende subito valide le rewrite rule
	 * (il CPT si registra all'hook init, che a questo punto è già passato).
	 */
	public static function activate(): void {
		Tracker::install_schema();
		PostType::register();
		Generator::add_rewrite_rules();
		flush_rewrite_rules();
	}
}
