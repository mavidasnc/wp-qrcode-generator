<?php
/**
 * Custom post type "qr_code".
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Registrazione del post type dei codici QR.
 */
final class PostType {

	/**
	 * Registra gli hook del post type.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_filter( 'use_block_editor_for_post_type', array( self::class, 'disable_block_editor' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'assign_code' ), 10, 2 );
	}

	/**
	 * Registra il post type qr_code.
	 */
	public static function register(): void {
		register_post_type(
			Code::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Codici QR', 'wp-qrcode-generator' ),
					'singular_name'      => __( 'Codice QR', 'wp-qrcode-generator' ),
					'add_new'            => __( 'Aggiungi', 'wp-qrcode-generator' ),
					'add_new_item'       => __( 'Aggiungi codice QR', 'wp-qrcode-generator' ),
					'edit_item'          => __( 'Modifica codice QR', 'wp-qrcode-generator' ),
					'new_item'           => __( 'Nuovo codice QR', 'wp-qrcode-generator' ),
					'view_item'          => __( 'Vedi pagina del QR', 'wp-qrcode-generator' ),
					'search_items'       => __( 'Cerca codici QR', 'wp-qrcode-generator' ),
					'not_found'          => __( 'Nessun codice QR', 'wp-qrcode-generator' ),
					'not_found_in_trash' => __( 'Nessun codice QR nel cestino', 'wp-qrcode-generator' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true, // Esposto in REST; l'editor a blocchi resta comunque disattivato (vedi disable_block_editor()).
				'menu_icon'           => 'dashicons-screenoptions',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'rewrite'             => array(
					'slug'       => Code::BASE,
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor' ),
			)
		);
	}

	/**
	 * Disattiva Gutenberg per i QR: con l'editor classico i meta box ("Cosa succede
	 * alla scansione", URL, allegati) stanno subito sotto il contenuto e si vedono,
	 * invece di finire in fondo alla pagina.
	 *
	 * @param bool   $use_block_editor Se il post type usa l'editor a blocchi.
	 * @param string $post_type        Post type in modifica.
	 */
	public static function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		return Code::POST_TYPE === $post_type ? false : $use_block_editor;
	}

	/**
	 * Al primo salvataggio assegna come slug un codice breve e casuale invece
	 * dello slug del titolo, così l'URL (e il QR) restano corti e non rivelano
	 * il nome interno; se il titolo è vuoto ci copia il codice. Se lo slug viene
	 * modificato a mano non viene toccato. Non fa nulla se la generazione
	 * automatica è disattivata nelle impostazioni.
	 *
	 * @param array<string, mixed> $data    Dati del post in scrittura.
	 * @param array<string, mixed> $postarr Dati grezzi passati a wp_insert_post().
	 * @return array<string, mixed>         Dati del post, con lo slug assegnato se serve.
	 */
	public static function assign_code( array $data, array $postarr ): array {
		if ( Code::POST_TYPE !== $data['post_type'] || in_array( $data['post_status'], array( 'auto-draft', 'trash' ), true ) || ! Settings::auto_code() ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$stored  = $post_id ? (string) get_post_field( 'post_name', $post_id ) : '';

		if ( '' === $stored && '' === (string) ( $postarr['post_name'] ?? '' ) ) {
			$data['post_name'] = Code::generate();
		}

		// Si lavora sui dati in scrittura: save_post + wp_update_post farebbe ricorsione.
		if ( '' === trim( (string) $data['post_title'] ) && '' !== (string) $data['post_name'] ) {
			$data['post_title'] = $data['post_name'];
		}

		return $data;
	}
}
