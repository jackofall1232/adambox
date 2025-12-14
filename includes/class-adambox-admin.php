<?php
/**
 * Admin handler for AdamBox
 *
 * File: includes/class-adambox-admin.php
 *
 * @package AdamBox
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdamBox_Admin {

	/**
	 * Option group slug
	 */
	const OPTION_GROUP = 'adambox_settings_group';

	/**
	 * Option name
	 */
	const OPTION_NAME = 'adambox_settings';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register settings page
	 */
	public function register_menu() {
		add_options_page(
			__( 'AdamBox Settings', 'adambox' ),
			__( 'AdamBox', 'adambox' ),
			'manage_options',
			'adambox',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings
	 */
	public function register_settings() {

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// API Section
		add_settings_section(
			'adambox_api_section',
			__( 'API Configuration', 'adambox' ),
			'__return_false',
			'adambox'
		);

		add_settings_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'adambox' ),
			array( $this, 'render_api_key_field' ),
			'adambox',
			'adambox_api_section'
		);

		// Moderation Section
		add_settings_section(
			'adambox_moderation_section',
			__( 'Moderation Controls', 'adambox' ),
			'__return_false',
			'adambox'
		);

		add_settings_field(
			'moderation_strictness',
			__( 'Moderation Strictness', 'adambox' ),
			array( $this, 'render_strictness_field' ),
			'adambox',
			'adambox_moderation_section'
		);

		add_settings_field(
			'ai_intervention_level',
			__( 'AI Intervention Level', 'adambox' ),
			array( $this, 'render_intervention_field' ),
			'adambox',
			'adambox_moderation_section'
		);
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {

		if ( 'settings_page_adambox' !== $hook ) {
			return;
		}

		$css_path = ADAMBOX_PLUGIN_DIR . 'assets/css/adambox-admin.css';
		$js_path  = ADAMBOX_PLUGIN_DIR . 'assets/js/adambox-admin.js';

		wp_enqueue_style(
			'adambox-admin',
			ADAMBOX_PLUGIN_URL . 'assets/css/adambox-admin.css',
			array(),
			file_exists( $css_path ) ? filemtime( $css_path ) : ADAMBOX_VERSION
		);

		wp_enqueue_script(
			'adambox-admin',
			ADAMBOX_PLUGIN_URL . 'assets/js/adambox-admin.js',
			array( 'jquery' ),
			file_exists( $js_path ) ? filemtime( $js_path ) : ADAMBOX_VERSION,
			true
		);
	}

	/**
	 * Sanitize settings input
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {

		$output = array();

		$output['openai_api_key'] = isset( $input['openai_api_key'] )
			? sanitize_text_field( $input['openai_api_key'] )
			: '';

		$allowed_strictness = array( 'low', 'medium', 'high' );
		$output['moderation_strictness'] = in_array( $input['moderation_strictness'] ?? 'medium', $allowed_strictness, true )
			? $input['moderation_strictness']
			: 'medium';

		$allowed_intervention = array(
			'intervene_only',
			'summarize_when_needed',
			'actively_guide',
		);

		$output['ai_intervention_level'] = in_array( $input['ai_intervention_level'] ?? 'intervene_only', $allowed_intervention, true )
			? $input['ai_intervention_level']
			: 'intervene_only';

		return $output;
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		?>
		<div class="wrap adambox-admin">
			<h1><?php esc_html_e( 'AdamBox Settings', 'adambox' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'adambox' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render API key field
	 */
	public function render_api_key_field() {
		$options = get_option( self::OPTION_NAME, array() );
		$value   = isset( $options['openai_api_key'] ) ? $options['openai_api_key'] : '';
		?>
		<input
			type="password"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off"
		/>
		<p class="description">
			<?php esc_html_e( 'Optional. Used only for AI moderation. Never exposed publicly.', 'adambox' ); ?>
		</p>
		<?php
	}

	/**
	 * Render moderation strictness field
	 */
	public function render_strictness_field() {
		$options = get_option( self::OPTION_NAME, array() );
		$value   = $options['moderation_strictness'] ?? 'medium';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[moderation_strictness]">
			<option value="low" <?php selected( $value, 'low' ); ?>><?php esc_html_e( 'Low', 'adambox' ); ?></option>
			<option value="medium" <?php selected( $value, 'medium' ); ?>><?php esc_html_e( 'Medium', 'adambox' ); ?></option>
			<option value="high" <?php selected( $value, 'high' ); ?>><?php esc_html_e( 'High', 'adambox' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render AI intervention level field
	 */
	public function render_intervention_field() {
		$options = get_option( self::OPTION_NAME, array() );
		$value   = $options['ai_intervention_level'] ?? 'intervene_only';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_intervention_level]">
			<option value="intervene_only" <?php selected( $value, 'intervene_only' ); ?>>
				<?php esc_html_e( 'Intervene only', 'adambox' ); ?>
			</option>
			<option value="summarize_when_needed" <?php selected( $value, 'summarize_when_needed' ); ?>>
				<?php esc_html_e( 'Summarize when needed', 'adambox' ); ?>
			</option>
			<option value="actively_guide" <?php selected( $value, 'actively_guide' ); ?>>
				<?php esc_html_e( 'Actively guide', 'adambox' ); ?>
			</option>
		</select>
		<?php
	}
}
