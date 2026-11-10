<?php
/**
 * Pagina pubblica /qr/{codice}: conteggio della visita e poi, secondo la
 * modalità scelta, redirect, pagina del tema o pagina minimale.
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Comportamento pubblico di /qr/{codice}.
 */
final class Frontend {

	/**
	 * Registra gli hook del frontend.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'handle' ), 5 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'the_content', array( self::class, 'append_attachments' ), 20 );
	}

	/**
	 * Conta la visita e applica la modalità del QR (redirect, pagina del tema o minimale).
	 */
	public static function handle(): void {
		if ( ! is_singular( Code::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// La pagina cambia a ogni scansione (conteggio e destinazione modificabile):
		// va esclusa dalle cache di pagina, altrimenti le visite non verrebbero contate.
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Costante standard dei plugin di cache.
		}
		do_action( 'litespeed_control_set_nocache', 'wp-qrcode-generator' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook di LiteSpeed Cache.

		// Le pagine dei QR non devono finire nei motori di ricerca.
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		if ( Tracker::should_track( $post->ID ) ) {
			Tracker::record( $post->ID );
		}

		$mode = Code::get_mode( $post->ID );

		if ( Code::MODE_REDIRECT === $mode ) {
			$url = Code::get_redirect_url( $post->ID );
			if ( '' !== $url ) {
				header( 'X-Robots-Tag: noindex, nofollow' );
				// 302 e non 301: la destinazione è modificabile e il browser non deve ricordarla.
				wp_redirect( $url, 302, 'WP-QRCode-Generator' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destinazione esterna voluta, impostata da chi può modificare il QR.
				exit;
			}
			// Redirect senza URL valido: si mostra la pagina del tema invece di un errore.
			return;
		}

		if ( Code::MODE_MINIMAL === $mode ) {
			// La pagina minimale scrive il <title> da sé.
			remove_action( 'wp_head', '_wp_render_title_tag', 1 );

			// Un tema può sostituire il template copiando il file in wpqr/minimal.php.
			$template = locate_template( 'wpqr/minimal.php' );
			include '' !== $template ? $template : WPQR_PATH . 'templates/minimal.php';
			exit;
		}
	}

	/**
	 * Carica il CSS dell'elenco allegati sulle pagine dei QR.
	 */
	public static function enqueue(): void {
		if ( is_singular( Code::POST_TYPE ) ) {
			wp_enqueue_style( 'wpqr-frontend', WPQR_URL . 'assets/frontend.css', array(), WPQR_VERSION );
		}
	}

	/**
	 * Dopo il contenuto aggiunge l'elenco degli allegati (pagina del tema e minimale).
	 *
	 * @param string $content Contenuto del post.
	 */
	public static function append_attachments( string $content ): string {
		if ( ! is_singular( Code::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return $content . self::attachments_html( (int) get_the_ID() );
	}

	/**
	 * HTML dell'elenco allegati del QR, stringa vuota se non ce ne sono.
	 *
	 * @param int $post_id ID del post QR.
	 */
	private static function attachments_html( int $post_id ): string {
		$items = '';

		foreach ( Code::get_attachment_ids( $post_id ) as $attachment_id ) {
			$url = 'attachment' === get_post_type( $attachment_id ) ? wp_get_attachment_url( $attachment_id ) : false;
			if ( ! $url ) {
				continue; // Allegato eliminato dalla libreria media.
			}

			$meta = strtoupper( (string) wp_check_filetype( $url )['ext'] );
			$file = get_attached_file( $attachment_id );
			if ( $file && is_readable( $file ) ) {
				$meta .= ( '' !== $meta ? ' · ' : '' ) . size_format( (int) filesize( $file ), 1 );
			}

			$items .= sprintf(
				'<li class="wpqr-attachments__item"><a class="wpqr-attachments__link" href="%1$s" target="_blank" rel="noopener">%2$s</a>%3$s</li>',
				esc_url( $url ),
				esc_html( get_the_title( $attachment_id ) ),
				'' !== $meta ? ' <span class="wpqr-attachments__meta">' . esc_html( $meta ) . '</span>' : ''
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return '<section class="wpqr-attachments"><h2 class="wpqr-attachments__title">'
			. esc_html__( 'Allegati', 'wp-qrcode-generator' )
			. '</h2><ul class="wpqr-attachments__list">' . $items . '</ul></section>';
	}
}
