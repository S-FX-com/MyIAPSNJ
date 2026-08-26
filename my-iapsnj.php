<?php
/**
 * Plugin Name:       My IAPSNJ
 * Plugin URI:        https://github.com/S-FX-com/MyIAPSNJ
 * Description:       Membership operations for the IAPSNJ website. FluentCRM is the single source of truth: FluentCart payments set membership state (Paid-YYYY tags, member_type, paid_through), Fluent Forms applications are tracked until they are paid, mailed checks are reconciled in batch, and WordPress user profiles are mirrored one way from the CRM. Includes the PMPro → FluentCRM migration toolkit.
 * Version:           4.0.0
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

define( 'MY_IAPSNJ_VERSION', '4.0.0' );
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
// GitHub Releases auto-updater
// ---------------------------------------------------------------------------
// Registered unconditionally: WordPress refreshes the plugin-update transient
// from the `wp_update_plugins` cron event, which runs in a front-end request
// where is_admin() is false. Gating this on is_admin() means background and
// automatic updates never see a new release. The class registers its
// admin-only UI hooks internally.
new My_IAPSNJ_Github_Updater();

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

        // Apply any pending data migrations before the modules read options.
        self::maybe_upgrade();

        My_IAPSNJ_Engine::get_instance();
        My_IAPSNJ_Admin::get_instance();
        My_IAPSNJ_REST_API::get_instance();

        // FluentCart → membership state. Boots only when FluentCart is active;
        // the admin screens explain what is missing otherwise.
        if ( My_IAPSNJ_Membership::is_available() ) {
            My_IAPSNJ_Membership::get_instance();
        }

        // Fluent Forms → application tracking.
        if ( My_IAPSNJ_Applications::is_available() ) {
            My_IAPSNJ_Applications::get_instance();
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            My_IAPSNJ_CLI::register();
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
            'fcrm_wp_sync_field_mappings' => 'my_iapsnj_field_mappings',
            'fcrm_wp_sync_settings'       => 'my_iapsnj_settings',
            'fcrm_wp_sync_last_bulk_sync' => 'my_iapsnj_last_bulk_sync',
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
            add_option( 'my_iapsnj_settings', self::default_settings() );
        }
        if ( get_option( My_IAPSNJ_Membership::OPTION_PRODUCTS ) === false ) {
            add_option( My_IAPSNJ_Membership::OPTION_PRODUCTS, [] );
        }

        // Seed IAPSNJ default field mappings when no mappings are configured yet.
        $existing_mappings = get_option( 'my_iapsnj_field_mappings', [] );
        if ( empty( $existing_mappings ) ) {
            My_IAPSNJ_Field_Mapper::seed_default_mappings();
        }

        // Applications table (Fluent Forms submissions awaiting payment).
        My_IAPSNJ_Applications::create_table();

        // Bring existing installs up to the current data version.
        self::maybe_upgrade();
    }

    public static function deactivate(): void {
        // Legacy PMPro expiry cron (pre-4.0). Data is preserved.
        wp_clear_scheduled_hook( 'my_iapsnj_pmp_expiry_cron' );
    }

    /**
     * Default plugin settings (my_iapsnj_settings).
     */
    public static function default_settings(): array {
        return [
            // CRM → WP mirror triggers.
            'sync_on_fcrm_update'     => true,
            'link_on_user_register'   => true,
            'sync_on_user_delete'     => true,
            // Fluent Forms.
            'join_form_id'            => 0,
            'renewal_form_id'         => 0,
            'form_email_field'        => 'email',
            // Notifications.
            'notify_new_member'       => true,
            'notify_emails'           => get_option( 'admin_email' ),
            // Checkout.
            'checkout_fill_address'   => 'empty_only', // empty_only | overwrite
            // Reports.
            'aging_days'              => 30,
            'cutover_date'            => '',
        ];
    }

    /**
     * Read settings merged over the defaults.
     */
    public static function settings(): array {
        $saved = get_option( 'my_iapsnj_settings', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return array_merge( self::default_settings(), $saved );
    }

    // -----------------------------------------------------------------------
    // Data migrations
    // -----------------------------------------------------------------------

    /**
     * Data-schema version. Bump this whenever a new migration step is added
     * below; it is independent of MY_IAPSNJ_VERSION so that ordinary releases
     * do not re-run migrations.
     */
    const DATA_VERSION = 5;

    /**
     * Runs any migration steps this install has not seen yet.
     *
     * Safe to call on every request: it short-circuits on an option read once
     * the install is current.
     */
    public static function maybe_upgrade(): void {
        $installed = (int) get_option( 'my_iapsnj_data_version', 0 );

        if ( $installed >= self::DATA_VERSION ) {
            return;
        }

        // Guard against two concurrent requests running the same migration.
        if ( ! self::acquire_upgrade_lock() ) {
            return;
        }

        try {
            // ---- v1–v3: PMPro-era steps. --------------------------------------
            // They seeded PMPro mappings that v5 removes again, so on a fresh
            // 4.x install there is nothing to do for them.

            // ---- v4: purge the removed CRM Assistant's credentials ---------
            if ( $installed < 4 ) {
                $settings = get_option( 'my_iapsnj_settings', [] );
                if ( is_array( $settings ) ) {
                    $removed = array_intersect_key(
                        $settings,
                        array_flip( [ 'ai_provider', 'anthropic_api_key', 'openai_api_key', 'gemini_api_key' ] )
                    );
                    if ( ! empty( $removed ) ) {
                        unset(
                            $settings['ai_provider'],
                            $settings['anthropic_api_key'],
                            $settings['openai_api_key'],
                            $settings['gemini_api_key']
                        );
                        update_option( 'my_iapsnj_settings', $settings );
                    }
                }
            }

            // ---- v5: PMPro retired, sync becomes CRM → WP only -------------
            // * Drop PMPro-sourced and pmpro_b* billing mappings.
            // * Force every surviving mapping to fcrm_to_wp.
            // * Remove PMPro options and the expiry cron.
            // * Rename sync_on_user_register → link_on_user_register.
            // * Create the applications table.
            if ( $installed < 5 ) {
                $mappings = get_option( 'my_iapsnj_field_mappings', [] );
                if ( is_array( $mappings ) ) {
                    $kept = [];
                    foreach ( $mappings as $m ) {
                        $src = $m['wp_field_source'] ?? '';
                        $key = (string) ( $m['wp_field_key'] ?? '' );
                        if ( $src === 'pmp' ) {
                            continue;
                        }
                        if ( $src === 'meta' && strpos( $key, 'pmpro_' ) === 0 ) {
                            continue;
                        }
                        // ID / username can never be written from the CRM.
                        if ( $src === 'user' && in_array( $key, [ 'ID', 'user_login' ], true ) ) {
                            continue;
                        }
                        $m['sync_direction'] = 'fcrm_to_wp';
                        $kept[] = $m;
                    }
                    update_option( 'my_iapsnj_field_mappings', array_values( $kept ) );
                }

                $settings = get_option( 'my_iapsnj_settings', [] );
                if ( ! is_array( $settings ) ) {
                    $settings = [];
                }
                if ( isset( $settings['sync_on_user_register'] ) && ! isset( $settings['link_on_user_register'] ) ) {
                    $settings['link_on_user_register'] = ! empty( $settings['sync_on_user_register'] );
                }
                unset(
                    $settings['sync_on_user_register'],
                    $settings['sync_on_profile_update'],
                    $settings['sync_on_pmp_change'],
                    $settings['default_sync_direction']
                );
                update_option( 'my_iapsnj_settings', array_merge( self::default_settings(), $settings ) );

                delete_option( 'my_iapsnj_pmp_tag_mappings' );
                delete_option( 'my_iapsnj_pmp_expiry_cron_enabled' );
                delete_option( 'my_iapsnj_pmp_expiry_last_sync' );
                wp_clear_scheduled_hook( 'my_iapsnj_pmp_expiry_cron' );

                if ( get_option( My_IAPSNJ_Membership::OPTION_PRODUCTS ) === false ) {
                    add_option( My_IAPSNJ_Membership::OPTION_PRODUCTS, [] );
                }

                My_IAPSNJ_Applications::create_table();
            }

            update_option( 'my_iapsnj_data_version', self::DATA_VERSION );
        } finally {
            delete_transient( 'my_iapsnj_upgrading' );
        }
    }

    /**
     * Best-effort mutex so concurrent requests do not run migrations twice.
     */
    private static function acquire_upgrade_lock(): bool {
        if ( get_transient( 'my_iapsnj_upgrading' ) ) {
            return false;
        }
        set_transient( 'my_iapsnj_upgrading', 1, 5 * MINUTE_IN_SECONDS );
        return true;
    }
}
