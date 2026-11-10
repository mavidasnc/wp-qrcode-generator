<?php
/**
 * Pagina "Codici QR > Aggiornamenti": versione installata, ultima release su GitHub,
 * verifica immediata e installazione con un clic.
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina di amministrazione degli aggiornamenti (release di GitHub).
 */
final class UpdatesPage {

	private const SLUG = 'wpqr-updates';

	/**
	 * Registra menu e azioni (admin-post, con nonce e capability update_plugins).
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_wpqr_update_check', array( self::class, 'handle_check' ) );
		add_action( 'admin_post_wpqr_update_install', array( self::class, 'handle_install' ) );
	}

	/**
	 * Voce di menu sotto "Codici QR". Installare plugin richiede update_plugins, non basta manage_options.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Code::POST_TYPE,
			__( 'Aggiornamenti', 'wp-qrcode-generator' ),
			__( 'Aggiornamenti', 'wp-qrcode-generator' ),
			'update_plugins',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * URL della pagina.
	 */
	private static function page_url(): string {
		return admin_url( 'edit.php?post_type=' . Code::POST_TYPE . '&page=' . self::SLUG );
	}

	/**
	 * Messaggio da mostrare alla prossima visita della pagina (per utente, una sola volta).
	 *
	 * @param string $type    success, warning o error.
	 * @param string $message Testo.
	 */
	private static function flash( string $type, string $message ): void {
		set_transient( 'wpqr_update_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	/**
	 * Verifica permessi e nonce di un'azione e restituisce lo stato dell'updater dopo un controllo su GitHub.
	 * Se qualcosa non va, mostra l'errore e torna alla pagina.
	 *
	 * @param string $action Nome dell'azione (anche nonce action).
	 * @return array{current:string,latest:string,update_available:bool,checked_at:int}
	 */
	private static function begin( string $action ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Non hai il permesso di aggiornare i plugin.', 'wp-qrcode-generator' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );

		$status = Updater::check();
		if ( null === $status ) {
			self::flash( 'error', __( 'Il sistema di aggiornamento non è disponibile (manca la cartella vendor/ del plugin).', 'wp-qrcode-generator' ) );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		return $status;
	}

	/**
	 * Azione "Verifica aggiornamenti".
	 */
	public static function handle_check(): void {
		$status = self::begin( 'wpqr_update_check' );

		if ( $status['update_available'] ) {
			/* translators: %s: numero di versione */
			self::flash( 'warning', sprintf( __( 'È disponibile la versione %s.', 'wp-qrcode-generator' ), $status['latest'] ) );
		} else {
			self::flash( 'success', __( 'Il plugin è aggiornato all\'ultima versione.', 'wp-qrcode-generator' ) );
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Azione "Installa": scarica l'ultima release e la installa con l'upgrader di WordPress.
	 */
	public static function handle_install(): void {
		$status = self::begin( 'wpqr_update_install' );

		if ( ! $status['update_available'] ) {
			self::flash( 'warning', __( 'Il plugin è già aggiornato.', 'wp-qrcode-generator' ) );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$basename   = plugin_basename( WPQR_FILE );
		$was_active = is_plugin_active( $basename );

		// La skin "ajax" raccoglie i messaggi senza stamparli, così si può ancora reindirizzare.
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $basename );

		if ( true !== $result ) {
			$messages = is_wp_error( $result ) ? array( $result->get_error_message() ) : array_map( 'wp_strip_all_tags', array_map( 'strval', $skin->get_upgrade_messages() ) );
			$errors   = $skin->get_errors();
			if ( $errors->has_errors() ) {
				$messages[] = $errors->get_error_message();
			}
			$text = trim( implode( ' ', array_filter( $messages ) ) );
			self::flash( 'error', '' !== $text ? $text : __( 'Installazione non riuscita.', 'wp-qrcode-generator' ) );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		// Il processo di aggiornamento può lasciare il plugin disattivato: si ripristina lo stato precedente.
		if ( $was_active && ! is_plugin_active( $basename ) ) {
			activate_plugin( $basename, '', false, true );
		}

		/* translators: %s: numero di versione */
		self::flash( 'success', sprintf( __( 'Aggiornato alla versione %s.', 'wp-qrcode-generator' ), $status['latest'] ) );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Disegna la pagina.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Non hai il permesso di aggiornare i plugin.', 'wp-qrcode-generator' ), '', array( 'response' => 403 ) );
		}

		$notice_key = 'wpqr_update_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		delete_transient( $notice_key );

		$status = Updater::status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Aggiornamenti', 'wp-qrcode-generator' ); ?></h1>
			<p><?php esc_html_e( 'Il plugin si aggiorna dalle release pubblicate su GitHub. WordPress controlla da solo ogni 12 ore: qui puoi farlo subito.', 'wp-qrcode-generator' ); ?></p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<?php if ( null === $status ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Il sistema di aggiornamento non è disponibile: manca la cartella vendor/ del plugin.', 'wp-qrcode-generator' ); ?></p></div>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Versione installata', 'wp-qrcode-generator' ); ?></th>
						<td><?php echo esc_html( $status['current'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ultima versione', 'wp-qrcode-generator' ); ?></th>
						<td>
							<?php echo esc_html( $status['latest'] ); ?>
							<?php if ( $status['update_available'] ) : ?>
								<strong><?php esc_html_e( '(nuova)', 'wp-qrcode-generator' ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ultimo controllo', 'wp-qrcode-generator' ); ?></th>
						<td>
							<?php
							echo $status['checked_at']
								? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['checked_at'] ) )
								: esc_html__( 'mai', 'wp-qrcode-generator' );
							?>
						</td>
					</tr>
				</table>

				<div style="display:flex;gap:8px;align-items:center;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wpqr_update_check">
						<?php wp_nonce_field( 'wpqr_update_check' ); ?>
						<?php submit_button( __( 'Verifica aggiornamenti', 'wp-qrcode-generator' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( $status['update_available'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="wpqr_update_install">
							<?php wp_nonce_field( 'wpqr_update_install' ); ?>
							<?php /* translators: %s: numero di versione */ ?>
							<?php submit_button( sprintf( __( 'Installa la versione %s', 'wp-qrcode-generator' ), $status['latest'] ), 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>

				<p><a href="<?php echo esc_url( Updater::RELEASES_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Note di versione su GitHub', 'wp-qrcode-generator' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
