<?php
/**
 * Aggiornamento automatico del plugin da GitHub Releases.
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Registra plugin-update-checker, che confronta WPQR_VERSION con l'ultima release su GitHub
 * e mostra l'aggiornamento nel pannello Plugin di WordPress.
 */
final class Updater {

	/**
	 * Repository GitHub del plugin (deve restare pubblico).
	 */
	private const REPO_URL = 'https://github.com/mavidasnc/wp-qrcode-generator/';

	/**
	 * Pagina delle release (note di versione).
	 */
	public const RELEASES_URL = 'https://github.com/mavidasnc/wp-qrcode-generator/releases';

	/**
	 * Checker creato da init().
	 *
	 * @var object|null
	 */
	private static ?object $checker = null;

	/**
	 * Inizializza il controllo aggiornamenti.
	 */
	public static function init(): void {
		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			WPQR_FILE,
			'wp-qrcode-generator'
		);

		// Si installa lo zip allegato alla release (con vendor/), non il sorgente di GitHub che ne è privo.
		$checker->getVcsApi()->enableReleaseAssets();
		self::$checker = $checker;
	}

	/**
	 * Stato degli aggiornamenti secondo l'ultimo controllo (senza contattare GitHub).
	 *
	 * @return array{current:string,latest:string,update_available:bool,checked_at:int}|null Null se l'updater non è disponibile.
	 */
	public static function status(): ?array {
		if ( null === self::$checker ) {
			return null;
		}

		$update = self::$checker->getUpdate();
		return array(
			'current'          => WPQR_VERSION,
			'latest'           => $update ? (string) $update->version : WPQR_VERSION,
			'update_available' => null !== $update,
			'checked_at'       => (int) self::$checker->getUpdateState()->getLastCheck(),
		);
	}

	/**
	 * Interroga GitHub subito (ignorando l'intervallo di controllo) e restituisce lo stato.
	 *
	 * @return array{current:string,latest:string,update_available:bool,checked_at:int}|null
	 */
	public static function check(): ?array {
		if ( null === self::$checker ) {
			return null;
		}
		self::$checker->checkForUpdates();
		return self::status();
	}
}
