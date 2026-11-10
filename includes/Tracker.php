<?php
/**
 * Tracciamento delle scansioni: tabelle, registrazione e statistiche.
 *
 * Due tabelle:
 * - wpqr_hits: una riga per QR e giorno con visite e visitatori unici del giorno;
 * - wpqr_visitors: hash degli IP già visti per QR e giorno, serve a capire se
 *   una visita è di un visitatore nuovo.
 *
 * L'IP non viene mai salvato: si conserva solo un HMAC con il salt del sito,
 * non reversibile ma stabile, quindi lo stesso visitatore resta riconoscibile
 * nei giorni (resta comunque un dato personale pseudonimizzato).
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

// I nomi delle tabelle vengono da $wpdb->prefix, non da input utente.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

defined( 'ABSPATH' ) || exit;

/**
 * Tracciamento e statistiche delle scansioni.
 */
final class Tracker {

	private const DB_VERSION = '1';
	private const DB_OPTION  = 'wpqr_db_version';

	/**
	 * Registra gli hook di aggiornamento schema e pulizia.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'maybe_upgrade_schema' ) );
		add_action( 'before_delete_post', array( self::class, 'delete_stats' ) );
	}

	/**
	 * Nome della tabella delle visite giornaliere.
	 */
	public static function hits_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpqr_hits';
	}

	/**
	 * Nome della tabella degli hash dei visitatori.
	 */
	public static function visitors_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpqr_visitors';
	}

	/**
	 * Crea o aggiorna le tabelle se la versione dello schema è cambiata.
	 */
	public static function maybe_upgrade_schema(): void {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install_schema();
		}
	}

	/**
	 * Crea o aggiorna le tabelle con dbDelta.
	 */
	public static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$hits    = self::hits_table();
		$visits  = self::visitors_table();

		// dbDelta è pignolo: un campo per riga e due spazi dopo PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$hits} (
  qr_id bigint(20) unsigned NOT NULL,
  hit_date date NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  uniques int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (qr_id,hit_date)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$visits} (
  qr_id bigint(20) unsigned NOT NULL,
  hit_date date NOT NULL,
  visitor char(32) NOT NULL,
  PRIMARY KEY  (qr_id,hit_date,visitor),
  KEY qr_visitor (qr_id,visitor)
) {$charset};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Registra una scansione del QR. Il giorno è quello del fuso del sito.
	 * Visita e unico vengono scritti con query atomiche: nessuna race tra due
	 * scansioni contemporanee.
	 *
	 * @param int $qr_id ID del post QR.
	 */
	public static function record( int $qr_id ): void {
		global $wpdb;

		$day     = current_time( 'Y-m-d' );
		$visitor = self::visitor_hash();
		$unique  = 0;

		if ( '' !== $visitor ) {
			// INSERT IGNORE: rows affected = 1 solo se la coppia (QR, giorno, IP) è nuova.
			$unique = (int) $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::visitors_table() . ' (qr_id, hit_date, visitor) VALUES (%d, %s, %s)',
					$qr_id,
					$day,
					$visitor
				)
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::hits_table() . ' (qr_id, hit_date, hits, uniques) VALUES (%d, %s, 1, %d)
				ON DUPLICATE KEY UPDATE hits = hits + 1, uniques = uniques + %d',
				$qr_id,
				$day,
				$unique,
				$unique
			)
		);
	}

	/**
	 * Decide se una richiesta va contata: niente HEAD, bot e anteprime dei
	 * link, né visite di chi può modificare il QR (altrimenti le prove in
	 * admin sporcano i numeri).
	 *
	 * @param int $qr_id ID del post QR.
	 */
	public static function should_track( int $qr_id ): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		$track = 'HEAD' !== $method
			&& '' !== $agent
			&& ! preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|curl|wget|python-requests|headless|lighthouse|uptime|monitor/i', $agent )
			&& ! current_user_can( 'edit_post', $qr_id );

		/**
		 * Permette di forzare o escludere il conteggio di una richiesta.
		 *
		 * @param bool $track  Esito del controllo predefinito.
		 * @param int  $qr_id  ID del post QR.
		 */
		return (bool) apply_filters( 'wpqr_should_track', $track, $qr_id );
	}

	/**
	 * Hash dell'IP, o stringa vuota se l'IP non è disponibile o non valido.
	 * Si usa solo REMOTE_ADDR perché gli header X-Forwarded-For sono falsificabili;
	 * dietro a un proxy fidato si filtra con "wpqr_client_ip".
	 */
	private static function visitor_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'wpqr_client_ip', $ip );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Totali di sempre: visite e visitatori unici (IP distinti, non la somma
	 * degli unici giornalieri, che conterebbe più volte lo stesso visitatore).
	 *
	 * @param int $qr_id ID del post QR.
	 * @return array{hits: int, uniques: int}
	 */
	public static function totals( int $qr_id ): array {
		global $wpdb;

		return array(
			'hits'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(hits), 0) FROM ' . self::hits_table() . ' WHERE qr_id = %d', $qr_id ) ),
			'uniques' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT visitor) FROM ' . self::visitors_table() . ' WHERE qr_id = %d', $qr_id ) ),
		);
	}

	/**
	 * Righe giorno per giorno (solo i giorni con visite), dalla più recente.
	 *
	 * @param int $qr_id ID del post QR.
	 * @param int $days  Quanti giorni indietro includere.
	 * @return array<int, array{hit_date: string, hits: int, uniques: int}>
	 */
	public static function daily( int $qr_id, int $days = 365 ): array {
		global $wpdb;

		$since = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . max( 1, $days - 1 ) . ' days' )->format( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT hit_date, hits, uniques FROM ' . self::hits_table() . ' WHERE qr_id = %d AND hit_date >= %s ORDER BY hit_date DESC',
				$qr_id,
				$since
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'hit_date' => $row['hit_date'],
				'hits'     => (int) $row['hits'],
				'uniques'  => (int) $row['uniques'],
			),
			(array) $rows
		);
	}

	/**
	 * Elimina le statistiche di un QR eliminato definitivamente.
	 *
	 * @param int $post_id ID del post eliminato.
	 */
	public static function delete_stats( int $post_id ): void {
		global $wpdb;

		if ( Code::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$wpdb->delete( self::hits_table(), array( 'qr_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( self::visitors_table(), array( 'qr_id' => $post_id ), array( '%d' ) );
	}
}
