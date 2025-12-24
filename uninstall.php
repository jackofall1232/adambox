<?php
/**
 * Uninstall cleanup for AdamBox
 *
 * This file runs when the plugin is deleted via WordPress admin.
 * It removes plugin options only. No user data is stored.
 *
 * @package AdamBox
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Safety: ensure option name matches settings class.
$adambox_option_name = 'adambox_settings';

// Remove plugin options.
delete_option( $adambox_option_name );

// Multisite support: remove option from all sites if network activated.
if ( is_multisite() ) {
	global $wpdb;

	$adambox_blog_ids = $wpdb->get_col(
		"SELECT blog_id FROM {$wpdb->blogs}"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	foreach ( $adambox_blog_ids as $adambox_blog_id ) {
		switch_to_blog( (int) $adambox_blog_id );
		delete_option( $adambox_option_name );
		restore_current_blog();
	}
}
