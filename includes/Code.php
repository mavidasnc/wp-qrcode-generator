<?php
/**
 * Costanti, meta key e helper condivisi: modalità di un QR, codice, URL pubblici.
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Costanti e helper condivisi del plugin.
 */
final class Code {

	public const POST_TYPE = 'qr_code';

	/** Base dell'URL pubblico: /qr/{codice}. */
	public const BASE = 'qr';

	public const MODE_REDIRECT = 'redirect';
	public const MODE_THEME    = 'theme';
	public const MODE_MINIMAL  = 'minimal';

	public const META_MODE        = '_wpqr_mode';
	public const META_URL         = '_wpqr_redirect_url';
	public const META_ATTACHMENTS = '_wpqr_attachments';

	// Senza caratteri ambigui (0/o, 1/l/i) perché il codice può essere letto o digitato a mano.
	private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

	/**
	 * Modalità disponibili con etichetta e descrizione.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function modes(): array {
		return array(
			self::MODE_REDIRECT => array(
				'label'       => __( 'Redirect', 'wp-qrcode-generator' ),
				'description' => __( 'Chi scansiona viene mandato a un altro URL, interno o esterno.', 'wp-qrcode-generator' ),
			),
			self::MODE_THEME    => array(
				'label'       => __( 'Pagina del tema', 'wp-qrcode-generator' ),
				'description' => __( 'Mostra contenuto e allegati con la pagina single del tema.', 'wp-qrcode-generator' ),
			),
			self::MODE_MINIMAL  => array(
				'label'       => __( 'Pagina minimale', 'wp-qrcode-generator' ),
				'description' => __( 'Mostra solo contenuto e allegati, senza header e footer del tema.', 'wp-qrcode-generator' ),
			),
		);
	}

	/**
	 * Codice casuale e unico, usato come slug del post (quindi nell'URL /qr/{codice}).
	 * Sei caratteri su 31 simboli sono circa 887 milioni di combinazioni.
	 */
	public static function generate(): string {
		global $wpdb;

		do {
			$code = '';
			for ( $i = 0; $i < 6; $i++ ) {
				$code .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			}
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s LIMIT 1", self::POST_TYPE, $code )
			);
		} while ( $exists );

		return $code;
	}

	/**
	 * Modalità del QR (redirect se mancante o sconosciuta).
	 *
	 * @param int $post_id ID del post QR.
	 */
	public static function get_mode( int $post_id ): string {
		$mode = (string) get_post_meta( $post_id, self::META_MODE, true );
		return isset( self::modes()[ $mode ] ) ? $mode : self::MODE_REDIRECT;
	}

	/**
	 * URL di destinazione già pronto per il redirect, oppure stringa vuota se
	 * mancante o non valido. Accetta percorsi interni ("/pagina") e URL http(s).
	 *
	 * @param int $post_id ID del post QR.
	 */
	public static function get_redirect_url( int $post_id ): string {
		$url = trim( (string) get_post_meta( $post_id, self::META_URL, true ) );

		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return home_url( $url );
		}

		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return $url;
		}

		return '';
	}

	/**
	 * ID degli allegati scelti per il QR, nell'ordine impostato.
	 *
	 * @param int $post_id ID del post QR.
	 * @return int[]
	 */
	public static function get_attachment_ids( int $post_id ): array {
		$ids = get_post_meta( $post_id, self::META_ATTACHMENTS, true );
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * URL che il QR codifica. Non dipende dal tema: si può cambiare modalità o
	 * destinazione senza dover ristampare il codice.
	 *
	 * @param string $code Codice (slug) del QR.
	 */
	public static function public_url( string $code ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( self::BASE . '/' . $code ) );
		}
		return add_query_arg( self::POST_TYPE, $code, home_url( '/' ) );
	}

	/**
	 * URL dell'immagine del QR (svg o png). Con $download = true il browser la scarica.
	 *
	 * @param string $code     Codice (slug) del QR.
	 * @param string $format   Formato: svg o png.
	 * @param int    $size     Lato in pixel, 0 per il valore predefinito.
	 * @param bool   $download Se true l'URL forza il download.
	 */
	public static function image_url( string $code, string $format, int $size = 0, bool $download = false ): string {
		$args = array_filter(
			array(
				'size'     => $size,
				'download' => $download ? 1 : 0,
			)
		);

		if ( get_option( 'permalink_structure' ) ) {
			$url = home_url( "/qr-image/{$code}.{$format}" );
		} else {
			$url = add_query_arg(
				array(
					'wpqr_image'  => $code,
					'wpqr_format' => $format,
				),
				home_url( '/' )
			);
		}

		return $args ? add_query_arg( $args, $url ) : $url;
	}
}
