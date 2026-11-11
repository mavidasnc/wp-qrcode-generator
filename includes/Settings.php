<?php
/**
 * Pagina "Codici QR > Impostazioni" e lettura delle opzioni del plugin.
 *
 * Una sola opzione array, `wpqr_settings`, con le chiavi:
 * - auto_code: assegna un codice casuale come slug al primo salvataggio;
 * - code_length: lunghezza del codice (4-12).
 *
 * @package Mavida\QRCodeGenerator
 */

namespace Mavida\QRCodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Impostazioni del plugin.
 */
final class Settings {

	public const OPTION = 'wpqr_settings';
	public const SLUG   = 'wpqr-settings';

	private const GROUP      = 'wpqr_settings_group';
	private const MIN_LENGTH = 4;
	private const MAX_LENGTH = 12;

	private const DEFAULTS = array(
		'auto_code'   => true,
		'code_length' => 6,
	);

	/**
	 * Registra menu e opzione (solo in wp-admin).
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	/**
	 * Impostazioni correnti. I default sono espliciti perché quelli di
	 * register_setting() valgono solo in admin, mentre Code::generate() gira
	 * anche via REST e nel frontend.
	 *
	 * @return array{auto_code: bool, code_length: int}
	 */
	public static function get(): array {
		$saved    = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::DEFAULTS );

		return array(
			'auto_code'   => (bool) $settings['auto_code'],
			'code_length' => self::normalize_length( $settings['code_length'] ),
		);
	}

	/**
	 * Se il codice va assegnato in automatico.
	 */
	public static function auto_code(): bool {
		return self::get()['auto_code'];
	}

	/**
	 * Lunghezza dei nuovi codici.
	 */
	public static function code_length(): int {
		return self::get()['code_length'];
	}

	/**
	 * Porta la lunghezza nell'intervallo consentito. Un valore vuoto o non
	 * numerico torna al default (non al minimo, che darebbe codici più corti
	 * senza che nessuno lo noti); niente absint(), che trasformerebbe -5 in 5.
	 *
	 * @param mixed $length Valore grezzo.
	 */
	private static function normalize_length( $length ): int {
		if ( ! is_numeric( $length ) ) {
			return self::DEFAULTS['code_length'];
		}
		return max( self::MIN_LENGTH, min( self::MAX_LENGTH, (int) $length ) );
	}

	/**
	 * Registra l'opzione con il suo sanitize callback.
	 */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( self::class, 'sanitize' ),
			)
		);
	}

	/**
	 * Pulisce i valori inviati dal form.
	 *
	 * @param mixed $input Valori grezzi.
	 * @return array{auto_code: bool, code_length: int}
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			// Una checkbox non spuntata non viene inviata: l'assenza della chiave vale "disattivato".
			'auto_code'   => ! empty( $input['auto_code'] ),
			'code_length' => self::normalize_length( $input['code_length'] ?? null ),
		);
	}

	/**
	 * Voce di menu sotto "Codici QR".
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Code::POST_TYPE,
			__( 'Impostazioni', 'wp-qrcode-generator' ),
			__( 'Impostazioni', 'wp-qrcode-generator' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Disegna la pagina: opzioni e, per chi può aggiornare i plugin, gli aggiornamenti.
	 */
	public static function render(): void {
		$settings = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Impostazioni', 'wp-qrcode-generator' ); ?></h1>

			<?php // Sotto un menu di un CPT WordPress non stampa da solo "Impostazioni salvate". ?>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Codice', 'wp-qrcode-generator' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_code]" value="1" <?php checked( $settings['auto_code'] ); ?>>
								<?php esc_html_e( 'Genera automaticamente il codice', 'wp-qrcode-generator' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Al primo salvataggio assegna come slug un codice casuale e, se il titolo è vuoto, lo copia nel titolo. Se disattivato si usa lo slug standard di WordPress, cioè quello ricavato dal titolo o l\'ID.', 'wp-qrcode-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpqr-code-length"><?php esc_html_e( 'Lunghezza del codice', 'wp-qrcode-generator' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="wpqr-code-length" name="<?php echo esc_attr( self::OPTION ); ?>[code_length]" value="<?php echo esc_attr( (string) $settings['code_length'] ); ?>" min="<?php echo esc_attr( (string) self::MIN_LENGTH ); ?>" max="<?php echo esc_attr( (string) self::MAX_LENGTH ); ?>" step="1" required>
							<p class="description"><?php esc_html_e( 'Da 4 a 12 caratteri. Vale solo per i nuovi codici: quelli esistenti non cambiano.', 'wp-qrcode-generator' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php // Fuori dal form delle opzioni: i form degli aggiornamenti vanno a admin-post.php e non possono essere annidati. ?>
			<?php if ( current_user_can( 'update_plugins' ) ) : ?>
				<?php UpdatesPage::render_section(); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
