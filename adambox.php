<?php
/**
 * Plugin Name: AdamBox
 * Plugin URI: https://github.com/jackofall1232/adambox
 * Description: Lightweight AI-powered moderation chatbox for WordPress.
 * Version: 1.1.4
 * Author: Ask Adam
 * Author URI: https://github.com/jackofall1232
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
define( 'ADAMBOX_VERSION', '1.1.4' );
define( 'ADAMBOX_PLUGIN_FILE', __FILE__ );
define( 'ADAMBOX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADAMBOX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Include core files
 *
 * Order matters:
 * - Settings first
 * - Keywords (logic only)
 * - Moderator (AI handling)
 * - REST (wires everything)
 * - Shortcode / Admin last
 */
function adambox_includes() {

	$base = ADAMBOX_PLUGIN_DIR . 'includes/';

	$files = array(
		'class-adambox-settings.php',
		'class-adambox-keywords.php',
		'class-adambox-moderator.php',
		'class-adambox-rest.php',
		'class-adambox-shortcode.php',
		'class-adambox-admin.php',
	);

	foreach ( $files as $file ) {
		$path = $base . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
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
