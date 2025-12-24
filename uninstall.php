<?php
/**
 * Uninstall cleanup for AdamBox
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Removes plugin options only. No user data is stored.
 *
 * @package AdamBox
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Option name (prefixed).
$adambox_option_name = 'adambox_settings';

// Remove option from current site.
delete_option( $adambox_option_name );

// Multisite: remove option from all sites.
if ( is_multisite() && function_exists( 'get_sites' ) ) {

	$adambox_sites = get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	);

	foreach ( $adambox_sites as $adambox_blog_id ) {
		switch_to_blog( (int) $adambox_blog_id );
		delete_option( $adambox_option_name );
		restore_current_blog();
	}
}
