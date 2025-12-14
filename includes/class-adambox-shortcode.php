<?php
/**
 * Shortcode handler for AdamBox
 *
 * File: includes/class-adambox-shortcode.php
 *
 * @package AdamBox
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdamBox_Shortcode {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_shortcode( 'adambox', array( $this, 'render_shortcode' ) );

		// Only enqueue assets when the shortcode is actually present on the page.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enqueue frontend assets only when shortcode exists in the current queried content.
	 */
	public function maybe_enqueue_assets() {

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, 'adambox' ) ) {
			return;
		}

		$css_path = ADAMBOX_PLUGIN_DIR . 'assets/css/adambox.css';
		$js_path  = ADAMBOX_PLUGIN_DIR . 'assets/js/adambox.js';

		wp_register_style(
			'adambox',
			ADAMBOX_PLUGIN_URL . 'assets/css/adambox.css',
			array(),
			file_exists( $css_path ) ? filemtime( $css_path ) : ADAMBOX_VERSION
		);

		wp_register_script(
			'adambox',
			ADAMBOX_PLUGIN_URL . 'assets/js/adambox.js',
			array(),
			file_exists( $js_path ) ? filemtime( $js_path ) : ADAMBOX_VERSION,
			true
		);

		// Provide base config to JS (REST comes later; this stays harmless).
		wp_localize_script(
			'adambox',
			'AdamBoxConfig',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'restUrl'  => esc_url_raw( rest_url( 'adambox/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'maxMsgs'  => 10,
				'version'  => ADAMBOX_VERSION,
			)
		);

		wp_enqueue_style( 'adambox' );
		wp_enqueue_script( 'adambox' );
	}

	/**
	 * Render the [adambox] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {

		$atts = shortcode_atts(
			array(
				'title'       => __( 'AdamBox', 'adambox' ),
				'placeholder' => __( 'Type a message…', 'adambox' ),
				'button'      => __( 'Send', 'adambox' ),
			),
			$atts,
			'adambox'
		);

		$instance_id = 'adambox-' . wp_generate_uuid4();

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="adambox"
			role="region"
			aria-label="<?php echo esc_attr__( 'AdamBox moderation area', 'adambox' ); ?>"
			data-adambox="1"
		>
			<div class="adambox__header">
				<div class="adambox__title"><?php echo esc_html( $atts['title'] ); ?></div>
				<div class="adambox__subtitle"><?php esc_html_e( 'AI moderation (session-only). No tracking.', 'adambox' ); ?></div>
			</div>

			<div class="adambox__body">
				<div class="adambox__messages" aria-live="polite" aria-relevant="additions text">
					<div class="adambox__message adambox__message--system">
						<?php esc_html_e( 'AdamBox is ready. Messages are session-based and not stored.', 'adambox' ); ?>
					</div>
				</div>
			</div>

			<form class="adambox__composer" method="post" action="#" autocomplete="off">
				<label class="screen-reader-text" for="<?php echo esc_attr( $instance_id ); ?>-input">
					<?php esc_html_e( 'Message', 'adambox' ); ?>
				</label>

				<input
					id="<?php echo esc_attr( $instance_id ); ?>-input"
					class="adambox__input"
					type="text"
					inputmode="text"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					maxlength="500"
				/>

				<button class="adambox__send" type="submit">
					<?php echo esc_html( $atts['button'] ); ?>
				</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
