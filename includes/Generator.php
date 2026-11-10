<?php
/**
 * Generazione delle immagini QR (SVG e PNG) e endpoint pubblico /qr-image/{codice}.{svg|png}.
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

defined( 'ABSPATH' ) || exit;

/**
 * Generazione delle immagini QR e relativo endpoint.
 */
final class Generator {

	private const DEFAULT_PNG_SIZE = 1024;

	/**
	 * Registra rewrite rule, query var e il gestore dell'endpoint.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_serve_image' ), 1 );
	}

	/**
	 * Aggiunge la rewrite rule /qr-image/{codice}.{svg|png}.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^qr-image/([a-z0-9-]+)\.(svg|png)/?$', 'index.php?wpqr_image=$matches[1]&wpqr_format=$matches[2]', 'top' );
	}

	/**
	 * Registra le query var dell'endpoint immagini.
	 *
	 * @param string[] $vars Query var registrate.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'wpqr_image';
		$vars[] = 'wpqr_format';
		return $vars;
	}

	/**
	 * SVG del QR. Il viewBox lo rende scalabile; $size aggiunge width/height
	 * perché aperto da solo nel browser altrimenti riempirebbe la finestra.
	 *
	 * @param string $data Testo da codificare.
	 * @param int    $size Lato in pixel, 0 per non impostarlo.
	 */
	public static function svg( string $data, int $size = 0 ): string {
		$svg = (string) self::qr( $data )->render();

		if ( $size > 0 ) {
			$svg = (string) preg_replace( '/<svg /', sprintf( '<svg width="%1$d" height="%1$d" ', $size ), $svg, 1 );
		}

		return $svg;
	}

	/**
	 * PNG del QR, scritto a mano dalla matrice dei moduli: immagine a 1 bit
	 * (nero/bianco) che richiede solo zlib, quindi niente GD né Imagick.
	 * Il lato reale è il multiplo intero più vicino (per difetto) a $size,
	 * così i moduli restano nitidi senza interpolazione.
	 *
	 * @param string $data Testo da codificare.
	 * @param int    $size Lato desiderato in pixel.
	 */
	public static function png( string $data, int $size = self::DEFAULT_PNG_SIZE ): string {
		$matrix  = self::qr( $data )->getQRMatrix()->getBooleanMatrix();
		$modules = count( $matrix );
		$scale   = max( 1, intdiv( $size, $modules ) );
		$side    = $modules * $scale;

		$raw = '';
		foreach ( $matrix as $row ) {
			$bits = '';
			foreach ( $row as $dark ) {
				// Scala di grigi a 1 bit: 0 = nero, 1 = bianco.
				$bits .= str_repeat( $dark ? '0' : '1', $scale );
			}
			// Ogni riga di pixel è riempita fino al byte intero con bianco.
			$bits .= str_repeat( '1', ( 8 - strlen( $bits ) % 8 ) % 8 );

			$line = "\0"; // Filtro PNG "none" a inizio riga.
			foreach ( str_split( $bits, 8 ) as $byte ) {
				$line .= chr( bindec( $byte ) );
			}
			$raw .= str_repeat( $line, $scale );
		}

		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', pack( 'NNCCCCC', $side, $side, 1, 0, 0, 0, 0 ) )
			. self::png_chunk( 'IDAT', (string) gzcompress( $raw, 9 ) )
			. self::png_chunk( 'IEND', '' );
	}

	/**
	 * Costruisce un chunk PNG: lunghezza, tipo, dati e CRC32.
	 *
	 * @param string $type Tipo del chunk (4 caratteri).
	 * @param string $data Contenuto del chunk.
	 */
	private static function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * Livello di correzione errori M (15%): buon compromesso tra densità e
	 * resistenza a sporco e danni sulla stampa.
	 *
	 * @param string $data Testo da codificare.
	 */
	private static function qr( string $data ): QRCode {
		$qr = new QRCode(
			new QROptions(
				array(
					'eccLevel'         => EccLevel::M,
					'quietzoneSize'    => 4,
					'outputBase64'     => false,
					'svgAddXmlHeader'  => true,
					'drawLightModules' => false,
				)
			)
		);
		$qr->addByteSegment( $data );

		return $qr;
	}

	/**
	 * Serve /qr-image/{codice}.{svg|png}. Parametri GET: size (lato in pixel,
	 * 64-4096) e download=1 per forzare il salvataggio del file.
	 */
	public static function maybe_serve_image(): void {
		$code = (string) get_query_var( 'wpqr_image' );
		if ( '' === $code ) {
			return;
		}

		$format = 'png' === get_query_var( 'wpqr_format' ) ? 'png' : 'svg';
		$posts  = get_posts(
			array(
				'post_type'      => Code::POST_TYPE,
				'name'           => sanitize_title( $code ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		$post   = $posts[0] ?? null;

		// Il QR di una bozza si vede solo a chi può modificarla.
		if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Endpoint pubblico in sola lettura.
		$size     = isset( $_GET['size'] ) ? absint( wp_unslash( $_GET['size'] ) ) : 0;
		$download = ! empty( $_GET['download'] );
		// phpcs:enable

		$payload = Code::public_url( $post->post_name );

		if ( 'png' === $format ) {
			$body = self::png( $payload, $size ? min( 4096, max( 64, $size ) ) : self::DEFAULT_PNG_SIZE );
			$mime = 'image/png';
		} else {
			$body = self::svg( $payload, $size ? min( 4096, max( 64, $size ) ) : 0 );
			$mime = 'image/svg+xml';
		}

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $download ) {
			header( sprintf( 'Content-Disposition: attachment; filename="qr-%s.%s"', $post->post_name, $format ) );
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Immagine binaria o SVG generato dal plugin.
		exit;
	}
}
