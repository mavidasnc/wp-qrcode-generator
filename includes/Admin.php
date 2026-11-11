<?php
/**
 * Interfaccia in wp-admin: meta box (comportamento, codice QR, statistiche),
 * salvataggio, azzeramento delle statistiche e colonne dell'elenco
 * (incluso il grafico degli ultimi 30 giorni).
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Interfaccia in wp-admin del plugin.
 */
final class Admin {

	/**
	 * Registra gli hook dell'interfaccia admin.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . Code::POST_TYPE, array( self::class, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Code::POST_TYPE, array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'manage_' . Code::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Code::POST_TYPE . '_posts_custom_column', array( self::class, 'column_content' ), 10, 2 );
		add_action( 'admin_post_wpqr_reset_stats', array( self::class, 'handle_reset_stats' ) );
		add_action( 'admin_notices', array( self::class, 'reset_notice' ) );
		add_filter( 'removable_query_args', array( self::class, 'removable_query_args' ) );
	}

	/**
	 * Carica CSS e JS solo nelle schermate del post type.
	 *
	 * @param string $hook Hook della schermata admin.
	 */
	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || Code::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'wpqr-admin', WPQR_URL . 'assets/admin.css', array(), WPQR_VERSION );

		if ( 'edit.php' === $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'wpqr-admin', WPQR_URL . 'assets/admin.js', array(), WPQR_VERSION, true );
		wp_localize_script(
			'wpqr-admin',
			'wpqrAdmin',
			array(
				'chooseTitle'  => __( 'Scegli gli allegati', 'wp-qrcode-generator' ),
				'chooseButton' => __( 'Usa questi file', 'wp-qrcode-generator' ),
				'remove'       => __( 'Rimuovi', 'wp-qrcode-generator' ),
				'copied'       => __( 'Copiato', 'wp-qrcode-generator' ),
				'confirmReset' => __( 'Azzerare tutte le statistiche di questo QR? L\'operazione non si può annullare.', 'wp-qrcode-generator' ),
			)
		);
	}

	/**
	 * Registra i tre meta box del QR.
	 */
	public static function add_meta_boxes(): void {
		add_meta_box( 'wpqr-behaviour', __( 'Cosa succede alla scansione', 'wp-qrcode-generator' ), array( self::class, 'render_behaviour' ), Code::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wpqr-code', __( 'Codice QR', 'wp-qrcode-generator' ), array( self::class, 'render_code' ), Code::POST_TYPE, 'side', 'high' );
		add_meta_box( 'wpqr-stats', __( 'Statistiche', 'wp-qrcode-generator' ), array( self::class, 'render_stats' ), Code::POST_TYPE, 'normal', 'default' );
	}

	/**
	 * Meta box della modalità, dell'URL di destinazione e degli allegati.
	 *
	 * @param \WP_Post $post Post in modifica.
	 */
	public static function render_behaviour( \WP_Post $post ): void {
		$mode = Code::get_mode( $post->ID );
		$url  = (string) get_post_meta( $post->ID, Code::META_URL, true );
		$ids  = Code::get_attachment_ids( $post->ID );

		wp_nonce_field( 'wpqr_save', 'wpqr_nonce' );
		?>
		<div class="wpqr-modes">
			<?php foreach ( Code::modes() as $slug => $info ) : ?>
				<label class="wpqr-modes__option">
					<input type="radio" name="wpqr_mode" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $mode, $slug ); ?>>
					<span class="wpqr-modes__label"><?php echo esc_html( $info['label'] ); ?></span>
					<span class="wpqr-modes__description"><?php echo esc_html( $info['description'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="wpqr-panel" data-wpqr-modes="<?php echo esc_attr( Code::MODE_REDIRECT ); ?>">
			<label class="wpqr-panel__label" for="wpqr_redirect_url"><?php esc_html_e( 'URL di destinazione', 'wp-qrcode-generator' ); ?></label>
			<input type="text" class="widefat" id="wpqr_redirect_url" name="wpqr_redirect_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://esempio.com/pagina oppure /pagina-interna">
			<p class="description">
				<?php esc_html_e( 'URL completo (esterno) oppure percorso che inizia con / (interno al sito).', 'wp-qrcode-generator' ); ?>
				<?php if ( Code::MODE_REDIRECT === $mode && '' === Code::get_redirect_url( $post->ID ) ) : ?>
					<strong class="wpqr-panel__warning"><?php esc_html_e( 'Senza un URL valido viene mostrata la pagina del tema.', 'wp-qrcode-generator' ); ?></strong>
				<?php endif; ?>
			</p>
		</div>

		<div class="wpqr-panel" data-wpqr-modes="<?php echo esc_attr( Code::MODE_THEME . ' ' . Code::MODE_MINIMAL ); ?>">
			<p class="wpqr-panel__label"><?php esc_html_e( 'Allegati', 'wp-qrcode-generator' ); ?></p>
			<p class="description"><?php esc_html_e( 'Il testo da mostrare si scrive nell\'editor qui sopra; i file scelti qui compaiono sotto, come elenco di link.', 'wp-qrcode-generator' ); ?></p>
			<ul class="wpqr-picker__list" id="wpqr-picker-list">
				<?php foreach ( $ids as $attachment_id ) : ?>
					<?php if ( 'attachment' === get_post_type( $attachment_id ) ) : ?>
						<li class="wpqr-picker__item" data-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
							<span class="wpqr-picker__name"><?php echo esc_html( get_the_title( $attachment_id ) ); ?></span>
							<button type="button" class="button-link wpqr-picker__remove"><?php esc_html_e( 'Rimuovi', 'wp-qrcode-generator' ); ?></button>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<input type="hidden" id="wpqr_attachments" name="wpqr_attachments" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
			<button type="button" class="button" id="wpqr-picker-add"><?php esc_html_e( 'Aggiungi allegati', 'wp-qrcode-generator' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Meta box con anteprima, URL e download del codice QR.
	 *
	 * @param \WP_Post $post Post in modifica.
	 */
	public static function render_code( \WP_Post $post ): void {
		$code = (string) $post->post_name;

		if ( '' === $code ) {
			// Con la generazione automatica il codice nasce al primo salvataggio; altrimenti è lo slug standard, assegnato da WordPress alla pubblicazione.
			$message = Settings::auto_code()
				? __( 'Salva la bozza per generare il codice QR.', 'wp-qrcode-generator' )
				: __( 'Pubblica il QR per generare il codice.', 'wp-qrcode-generator' );
			echo '<p>' . esc_html( $message ) . '</p>';
			return;
		}

		$url = Code::public_url( $code );
		?>
		<div class="wpqr-code">
			<img class="wpqr-code__image" src="<?php echo esc_url( Code::image_url( $code, 'svg' ) ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: codice */ __( 'Codice QR %s', 'wp-qrcode-generator' ), $code ) ); ?>" width="200" height="200">
			<input type="text" class="widefat wpqr-code__url" id="wpqr-code-url" readonly value="<?php echo esc_attr( $url ); ?>">
			<p class="wpqr-code__actions">
				<button type="button" class="button" id="wpqr-copy-url"><?php esc_html_e( 'Copia URL', 'wp-qrcode-generator' ); ?></button>
				<a class="button" href="<?php echo esc_url( Code::image_url( $code, 'svg', 0, true ) ); ?>"><?php esc_html_e( 'Scarica SVG', 'wp-qrcode-generator' ); ?></a>
				<a class="button" href="<?php echo esc_url( Code::image_url( $code, 'png', 1024, true ) ); ?>"><?php esc_html_e( 'Scarica PNG', 'wp-qrcode-generator' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'Il QR codifica sempre questo URL: puoi cambiare modalità o destinazione senza ristamparlo. Funziona solo a post pubblicato; modificare lo slug ne cambia il contenuto.', 'wp-qrcode-generator' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Meta box delle statistiche: riquadri, grafico e tabella giornaliera.
	 *
	 * @param \WP_Post $post Post in modifica.
	 */
	public static function render_stats( \WP_Post $post ): void {
		if ( '' === $post->post_name ) {
			echo '<p>' . esc_html__( 'Le statistiche compaiono dopo la prima scansione.', 'wp-qrcode-generator' ) . '</p>';
			return;
		}

		$totals = Tracker::totals( $post->ID );
		$rows   = Tracker::daily( $post->ID, 365 );
		$series = self::last_30_days( $rows );

		$tiles = array(
			__( 'Visite totali', 'wp-qrcode-generator' )   => $totals['hits'],
			__( 'Visitatori unici', 'wp-qrcode-generator' ) => $totals['uniques'],
			__( 'Oggi', 'wp-qrcode-generator' )            => end( $series ),
			__( 'Ultimi 7 giorni', 'wp-qrcode-generator' ) => array_sum( array_slice( $series, -7 ) ),
			__( 'Ultimi 30 giorni', 'wp-qrcode-generator' ) => array_sum( $series ),
		);
		?>
		<div class="wpqr-stats">
			<div class="wpqr-stats__tiles">
				<?php foreach ( $tiles as $label => $value ) : ?>
					<div class="wpqr-stats__tile">
						<span class="wpqr-stats__value"><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></span>
						<span class="wpqr-stats__label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php echo self::chart( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG costruito con valori numerici e testi già escapati. ?>

			<?php if ( $rows ) : ?>
				<div class="wpqr-stats__scroll">
					<table class="widefat striped wpqr-stats__table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Giorno', 'wp-qrcode-generator' ); ?></th>
								<th><?php esc_html_e( 'Visite', 'wp-qrcode-generator' ); ?></th>
								<th><?php esc_html_e( 'Visitatori unici', 'wp-qrcode-generator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row['hit_date'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $row['hits'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $row['uniques'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'Nessuna scansione registrata.', 'wp-qrcode-generator' ); ?></p>
			<?php endif; ?>

			<?php if ( $totals['hits'] > 0 && current_user_can( 'edit_post', $post->ID ) ) : ?>
				<p>
					<?php // Link e non form: il meta box sta già dentro il form dell'editor, i form non si annidano. ?>
					<a class="button button-link-delete wpqr-stats__reset" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpqr_reset_stats&post=' . $post->ID ), 'wpqr_reset_stats_' . $post->ID ) ); ?>"><?php esc_html_e( 'Azzera statistiche', 'wp-qrcode-generator' ); ?></a>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Il giorno segue il fuso orario del sito. Gli unici si contano per IP (conservato solo come hash): nel totale sono IP distinti, nelle righe giornaliere quelli del giorno, quindi la loro somma può superare il totale. Non vengono contati bot, anteprime dei link e visite di chi può modificare il QR; la tabella mostra gli ultimi 365 giorni.', 'wp-qrcode-generator' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Visite degli ultimi 30 giorni (oggi incluso), anche quelli senza visite, in ordine cronologico.
	 *
	 * @param array<int, array{hit_date: string, hits: int, uniques: int}> $rows Righe di Tracker::daily().
	 * @return array<string, int> Visite per giorno, con chiave Y-m-d.
	 */
	private static function last_30_days( array $rows ): array {
		$byday  = array_column( $rows, 'hits', 'hit_date' );
		$today  = new \DateTimeImmutable( 'today', wp_timezone() );
		$series = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$day            = $today->modify( "-{$i} days" )->format( 'Y-m-d' );
			$series[ $day ] = (int) ( $byday[ $day ] ?? 0 );
		}

		return $series;
	}

	/**
	 * Grafico a barre SVG delle visite giornaliere.
	 *
	 * @param array<string, int> $series Visite per giorno (Y-m-d), in ordine cronologico.
	 */
	private static function chart( array $series ): string {
		$width  = 300;
		$height = 80;
		$max    = max( 1, ...array_values( $series ) );
		$step   = $width / count( $series );

		$bars = '';
		$i    = 0;
		foreach ( $series as $day => $hits ) {
			$bar_h = $hits > 0 ? max( 2, ( $hits / $max ) * ( $height - 4 ) ) : 0;
			$bars .= sprintf(
				'<rect class="wpqr-chart__bar" x="%.2f" y="%.2f" width="%.2f" height="%.2f"><title>%s</title></rect>',
				$i * $step + 1,
				$height - 1 - $bar_h,
				$step - 2,
				$bar_h,
				esc_html( mysql2date( get_option( 'date_format' ), $day ) . ': ' . $hits )
			);
			++$i;
		}

		return sprintf(
			'<svg class="wpqr-chart" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s"><rect class="wpqr-chart__base" x="0" y="%4$d" width="%1$d" height="1"/>%5$s</svg>',
			$width,
			$height,
			esc_attr__( 'Visite degli ultimi 30 giorni', 'wp-qrcode-generator' ),
			$height - 1,
			$bars
		);
	}

	/**
	 * Salvataggio dei campi del QR.
	 *
	 * @param int $post_id ID del post QR.
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST['wpqr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpqr_nonce'] ) ), 'wpqr_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = isset( $_POST['wpqr_mode'] ) ? sanitize_key( wp_unslash( $_POST['wpqr_mode'] ) ) : '';
		update_post_meta( $post_id, Code::META_MODE, isset( Code::modes()[ $mode ] ) ? $mode : Code::MODE_REDIRECT );

		// Percorso interno (/pagina) o URL http(s): qualsiasi altro schema viene scartato.
		$url = isset( $_POST['wpqr_redirect_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpqr_redirect_url'] ) ) ) : '';
		update_post_meta( $post_id, Code::META_URL, esc_url_raw( $url, array( 'http', 'https' ) ) );

		$raw = isset( $_POST['wpqr_attachments'] ) ? sanitize_text_field( wp_unslash( $_POST['wpqr_attachments'] ) ) : '';
		$ids = array_filter(
			array_unique( array_map( 'absint', explode( ',', $raw ) ) ),
			static fn( int $id ): bool => $id > 0 && 'attachment' === get_post_type( $id )
		);
		update_post_meta( $post_id, Code::META_ATTACHMENTS, array_values( $ids ) );
	}

	/**
	 * Aggiunge le colonne del QR all'elenco.
	 *
	 * @param string[] $columns Colonne predefinite.
	 * @return string[]
	 */
	public static function columns( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['wpqr_qr']      = __( 'QR', 'wp-qrcode-generator' );
				$result['wpqr_url']     = __( 'URL', 'wp-qrcode-generator' );
				$result['wpqr_mode']    = __( 'Modalità', 'wp-qrcode-generator' );
				$result['wpqr_hits']    = __( 'Visite', 'wp-qrcode-generator' );
				$result['wpqr_uniques'] = __( 'Unici', 'wp-qrcode-generator' );
				$result['wpqr_trend']   = __( 'Ultimi 30 giorni', 'wp-qrcode-generator' );
			}
		}
		return $result;
	}

	/**
	 * Contenuto delle colonne aggiunte all'elenco.
	 *
	 * @param string $column  Chiave della colonna.
	 * @param int    $post_id ID del post QR.
	 */
	public static function column_content( string $column, int $post_id ): void {
		$code = (string) get_post_field( 'post_name', $post_id );

		switch ( $column ) {
			case 'wpqr_qr':
				if ( '' !== $code ) {
					printf( '<img class="wpqr-column__qr" src="%s" alt="" width="56" height="56">', esc_url( Code::image_url( $code, 'svg' ) ) );
				}
				break;
			case 'wpqr_url':
				if ( '' !== $code ) {
					printf( '<a href="%1$s" target="_blank" rel="noopener"><code>%2$s</code></a>', esc_url( Code::public_url( $code ) ), esc_html( $code ) );
				}
				break;
			case 'wpqr_mode':
				echo esc_html( Code::modes()[ Code::get_mode( $post_id ) ]['label'] );
				break;
			case 'wpqr_hits':
			case 'wpqr_uniques':
				// Le due colonne leggono gli stessi totali: una sola coppia di query per riga.
				static $totals        = array();
				$totals[ $post_id ] ??= Tracker::totals( $post_id );
				echo esc_html( number_format_i18n( $totals[ $post_id ][ 'wpqr_hits' === $column ? 'hits' : 'uniques' ] ) );
				break;
			case 'wpqr_trend':
				if ( '' !== $code ) {
					echo self::chart( self::last_30_days( Tracker::daily( $post_id, 30 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG costruito con valori numerici e testi già escapati.
				}
				break;
		}
	}

	/**
	 * Azzera le statistiche di un QR (link del meta box, via admin-post).
	 */
	public static function handle_reset_stats(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- il nonce dipende dall'ID e si verifica subito sotto.

		if ( Code::POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Non hai il permesso di azzerare le statistiche di questo QR.', 'wp-qrcode-generator' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wpqr_reset_stats_' . $post_id );

		Tracker::reset( $post_id );

		wp_safe_redirect( add_query_arg( 'wpqr_reset', 1, get_edit_post_link( $post_id, 'url' ) ) );
		exit;
	}

	/**
	 * Conferma dopo l'azzeramento, nella schermata di modifica del QR.
	 */
	public static function reset_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || Code::POST_TYPE !== $screen->post_type || 'post' !== $screen->base || ! isset( $_GET['wpqr_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo un messaggio, nessuna azione.
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statistiche azzerate.', 'wp-qrcode-generator' ) . '</p></div>';
	}

	/**
	 * Fa togliere dall'URL il parametro della conferma (WordPress lo rimuove con history.replaceState).
	 *
	 * @param string[] $args Parametri da rimuovere.
	 * @return string[]
	 */
	public static function removable_query_args( array $args ): array {
		$args[] = 'wpqr_reset';
		return $args;
	}
}
