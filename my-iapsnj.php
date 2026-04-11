<?php
/**
 * Plugin Name:       My IAPSNJ
 * Plugin URI:        https://github.com/s-fx-com/my-iapsnj
 * Description:       Member data sync and CRM tools for the IAPSNJ website. Bidirectional sync between FluentCRM contacts and WordPress users with pre-configured IAPSNJ field mappings, ACF support, mismatch resolution, and an AI-powered CRM Assistant.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  fluent-crm
 * Author:            S-FX
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       my-iapsnj
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MY_IAPSNJ_VERSION', '2.0.0' );
define( 'MY_IAPSNJ_DIR',     plugin_dir_path( __FILE__ ) );
define( 'MY_IAPSNJ_URL',     plugin_dir_url( __FILE__ ) );
define( 'MY_IAPSNJ_FILE',    __FILE__ );

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
spl_autoload_register( function ( $class ) {
    $prefix = 'My_IAPSNJ_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $short = substr( $class, strlen( $prefix ) ); // e.g. "Field_Mapper"
    $file  = MY_IAPSNJ_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ---------------------------------------------------------------------------
// GitHub Releases auto-updater (admin only)
// ---------------------------------------------------------------------------
if ( is_admin() ) {
    new My_IAPSNJ_Github_Updater();
}

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, [ 'My_IAPSNJ_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'My_IAPSNJ_Plugin', 'deactivate' ] );

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', [ 'My_IAPSNJ_Plugin', 'get_instance' ] );

/**
 * Main plugin bootstrap class.
 */
final class My_IAPSNJ_Plugin {

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Guard: FluentCRM must be active.
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_fluentcrm_missing' ] );
            return;
        }

        My_IAPSNJ_Engine::get_instance();
        My_IAPSNJ_Admin::get_instance();
        My_IAPSNJ_REST_API::get_instance();
        My_IAPSNJ_CRM_Assistant::get_instance();

        // Boot PMPro integration only when Paid Memberships Pro is active.
        if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            My_IAPSNJ_PMP_Integration::get_instance();
            add_action( 'my_iapsnj_pmp_expiry_cron', [ 'My_IAPSNJ_PMP_Integration', 'run_expiry_cron' ] );
        }
    }

    public function notice_fluentcrm_missing(): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'My IAPSNJ requires FluentCRM to be installed and activated.', 'my-iapsnj' )
            . '</p></div>';
    }

    // -----------------------------------------------------------------------
    // Activation: migrate old options, seed defaults
    // -----------------------------------------------------------------------
    public static function activate(): void {
        // Migrate from old fcrm_wp_sync_* option keys if they exist.
        $migrations = [
            'fcrm_wp_sync_field_mappings'        => 'my_iapsnj_field_mappings',
            'fcrm_wp_sync_settings'              => 'my_iapsnj_settings',
            'fcrm_wp_sync_pmp_tag_mappings'      => 'my_iapsnj_pmp_tag_mappings',
            'fcrm_wp_sync_pmp_expiry_cron_enabled' => 'my_iapsnj_pmp_expiry_cron_enabled',
            'fcrm_wp_sync_pmp_expiry_last_sync'  => 'my_iapsnj_pmp_expiry_last_sync',
            'fcrm_wp_sync_last_bulk_sync'        => 'my_iapsnj_last_bulk_sync',
        ];
        foreach ( $migrations as $old => $new ) {
            if ( get_option( $new ) === false ) {
                $old_val = get_option( $old );
                if ( $old_val !== false ) {
                    add_option( $new, $old_val );
                }
            }
        }

        // Seed default options for new installations.
        if ( get_option( 'my_iapsnj_field_mappings' ) === false ) {
            add_option( 'my_iapsnj_field_mappings', [] );
        }
        if ( get_option( 'my_iapsnj_settings' ) === false ) {
            add_option( 'my_iapsnj_settings', [
                'default_sync_direction' => 'both',
                'sync_on_user_register'  => true,
                'sync_on_profile_update' => true,
                'sync_on_user_delete'    => true,
                'sync_on_fcrm_update'    => true,
                'sync_on_pmp_change'     => false,
                'ai_provider'            => 'anthropic',
                'anthropic_api_key'      => '',
                'openai_api_key'         => '',
                'gemini_api_key'         => '',
            ] );
        }
        if ( get_option( 'my_iapsnj_pmp_tag_mappings' ) === false ) {
            add_option( 'my_iapsnj_pmp_tag_mappings', [] );
        }
        if ( get_option( 'my_iapsnj_pmp_expiry_cron_enabled' ) === false ) {
            add_option( 'my_iapsnj_pmp_expiry_cron_enabled', false );
        }
        if ( get_option( 'my_iapsnj_pmp_expiry_last_sync' ) === false ) {
            add_option( 'my_iapsnj_pmp_expiry_last_sync', '' );
        }

        // Seed IAPSNJ default field mappings when no mappings are configured yet.
        $existing_mappings = get_option( 'my_iapsnj_field_mappings', [] );
        if ( empty( $existing_mappings ) ) {
            My_IAPSNJ_Field_Mapper::seed_default_mappings();
        }

        // Re-schedule the cron if it was already enabled (e.g. re-activation).
        if ( get_option( 'my_iapsnj_pmp_expiry_cron_enabled' ) ) {
            if ( ! wp_next_scheduled( 'my_iapsnj_pmp_expiry_cron' ) ) {
                wp_schedule_event( time(), 'daily', 'my_iapsnj_pmp_expiry_cron' );
            }
        }
    }

    public static function deactivate(): void {
        // Clear the expiry cron on deactivation; data is preserved.
        wp_clear_scheduled_hook( 'my_iapsnj_pmp_expiry_cron' );
    }
}
