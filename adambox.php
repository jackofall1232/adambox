<?php
/**
 * Plugin Name: AdamBox
 * Plugin URI: https://askadamit.com
 * Description: Lightweight AI-powered moderation box for WordPress.
 * Version: 0.3.3
 * Author: Ask Adam
 * Author URI: https://askadamit.com
 * License: GPL v3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: adambox
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants
 */
define( 'ADAMBOX_VERSION', '0.3.3' );
define( 'ADAMBOX_PLUGIN_FILE', __FILE__ );
define( 'ADAMBOX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADAMBOX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load text domain
 */
function adambox_load_textdomain() {
	load_plugin_textdomain(
		'adambox',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'adambox_load_textdomain' );

/**
 * Include core files
 */
function adambox_includes() {

	require_once ADAMBOX_PLUGIN_DIR . 'includes/class-adambox-shortcode.php';
	require_once ADAMBOX_PLUGIN_DIR . 'includes/class-adambox-admin.php';
	require_once ADAMBOX_PLUGIN_DIR . 'includes/class-adambox-settings.php';
	require_once ADAMBOX_PLUGIN_DIR . 'includes/class-adambox-rest.php';
}
add_action( 'plugins_loaded', 'adambox_includes', 5 );

/**
 * Initialize plugin components
 */
function adambox_init() {

	// Shortcode
	if ( class_exists( 'AdamBox_Shortcode' ) ) {
		new AdamBox_Shortcode();
	}

	// Admin
	if ( is_admin() && class_exists( 'AdamBox_Admin' ) ) {
		new AdamBox_Admin();
	}

	// REST API
	if ( class_exists( 'AdamBox_REST' ) ) {
		new AdamBox_REST();
	}
}
add_action( 'plugins_loaded', 'adambox_init', 10 );
