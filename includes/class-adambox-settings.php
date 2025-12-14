<?php
/**
 * Settings helper for AdamBox
 *
 * File: includes/class-adambox-settings.php
 *
 * @package AdamBox
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdamBox_Settings {

	/**
	 * Option name used in wp_options
	 */
	const OPTION_NAME = 'adambox_settings';

	/**
	 * Default settings
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'openai_api_key'        => '',
			'moderation_strictness' => 'medium',
			'ai_intervention_level' => 'intervene_only',
		);
	}

	/**
	 * Get all settings merged with defaults
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Get a single setting value
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Optional fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Check if API key is configured
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		$key = self::get( 'openai_api_key', '' );
		return is_string( $key ) && $key !== '';
	}

	/**
	 * Get moderation strictness (validated)
	 *
	 * @return string
	 */
	public static function moderation_strictness() {
		$value   = self::get( 'moderation_strictness', 'medium' );
		$allowed = array( 'low', 'medium', 'high' );

		return in_array( $value, $allowed, true ) ? $value : 'medium';
	}

	/**
	 * Get AI intervention level (validated)
	 *
	 * @return string
	 */
	public static function intervention_level() {
		$value   = self::get( 'ai_intervention_level', 'intervene_only' );
		$allowed = array(
			'intervene_only',
			'summarize_when_needed',
			'actively_guide',
		);

		return in_array( $value, $allowed, true ) ? $value : 'intervene_only';
	}
}
