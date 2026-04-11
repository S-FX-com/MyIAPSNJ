<?php
/**
 * Plugin Name:       FluentCRM WordPress Sync
 * Plugin URI:        https://github.com/S-FX-com/WP-FluentCRM-Sync
 * Description:       Bidirectional sync between FluentCRM contacts and WordPress users with configurable field mapping, ACF support, and mismatch resolution.
 * Version:           1.8.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  fluent-crm
 * Author:            S-FX
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fcrm-wp-sync
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the renamed plugin bootstrap from this same plugin directory.
 */
function fcrm_wp_sync_load_renamed_plugin(): void {
	if ( class_exists( 'My_IAPSNJ_Plugin' ) ) {
		return;
	}

	$bootstrap = __DIR__ . '/my-iapsnj.php';
	if ( file_exists( $bootstrap ) ) {
		require_once $bootstrap;
	}
}

register_activation_hook( __FILE__, function (): void {
	fcrm_wp_sync_load_renamed_plugin();
	if ( class_exists( 'My_IAPSNJ_Plugin' ) ) {
		My_IAPSNJ_Plugin::activate();
	}
} );

register_deactivation_hook( __FILE__, function (): void {
	if ( class_exists( 'My_IAPSNJ_Plugin' ) ) {
		My_IAPSNJ_Plugin::deactivate();
	}
} );

add_action( 'plugins_loaded', function (): void {
	fcrm_wp_sync_load_renamed_plugin();

	if ( class_exists( 'My_IAPSNJ_Plugin' ) ) {
		My_IAPSNJ_Plugin::get_instance();
	}
} );

