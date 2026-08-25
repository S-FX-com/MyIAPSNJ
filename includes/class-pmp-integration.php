<?php
/**
 * My_IAPSNJ_PMP_Integration
 *
 * Integrates Paid Memberships Pro (PMPro) with the My IAPSNJ sync:
 *  - Triggers a WP → FluentCRM field sync when a user's membership level changes.
 *  - Applies FluentCRM tags to subscribers based on their active PMPro level(s).
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_PMP_Integration {

    /** @var self|null */
    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    private function register_hooks(): void {
        $settings = get_option( 'my_iapsnj_settings', [] );

        if ( ! empty( $settings['sync_on_pmp_change'] ) ) {
            add_action( 'pmpro_after_change_membership_level', [ $this, 'on_membership_level_change' ], 20, 2 );
        }

        add_action( 'pmpro_after_all_membership_level_changes', [ $this, 'on_all_membership_level_changes' ], 20 );
    }

    public function on_membership_level_change( int $level_id, int $user_id ): void {
        $engine = My_IAPSNJ_Engine::get_instance();
        $engine->sync_wp_to_fcrm( $user_id );
    }

    public function on_all_membership_level_changes( array $old_levels ): void {
        $tag_mappings = get_option( 'my_iapsnj_pmp_tag_mappings', [] );
        if ( empty( $tag_mappings ) ) {
            return;
        }

        foreach ( array_keys( $old_levels ) as $user_id ) {
            $this->sync_tags_for_user( (int) $user_id, $tag_mappings );
        }
    }

    // -----------------------------------------------------------------------
    // Tag synchronisation
    // -----------------------------------------------------------------------

    public function sync_tags_for_user( int $user_id, array $tag_mappings ): void {
        $subscriber = $this->find_subscriber( $user_id );
        if ( ! $subscriber ) {
            return;
        }

        $all_managed_tag_ids = [];
        foreach ( $tag_mappings as $tag_ids ) {
            foreach ( (array) $tag_ids as $tid ) {
                $all_managed_tag_ids[] = (int) $tid;
            }
        }
        $all_managed_tag_ids = array_unique( $all_managed_tag_ids );

        $active_tag_ids = [];
        $current_levels = self::get_user_levels( $user_id );

        foreach ( $current_levels as $level ) {
            $lid = (int) ( $level->id ?? $level->ID ?? 0 );
            if ( isset( $tag_mappings[ $lid ] ) ) {
                foreach ( (array) $tag_mappings[ $lid ] as $tid ) {
                    $active_tag_ids[] = (int) $tid;
                }
            }
        }
        $active_tag_ids = array_unique( $active_tag_ids );

        $tags_to_remove = array_values( array_diff( $all_managed_tag_ids, $active_tag_ids ) );
        if ( ! empty( $tags_to_remove ) ) {
            $subscriber->detachTags( $tags_to_remove );
        }

        if ( ! empty( $active_tag_ids ) ) {
            $subscriber->attachTags( $active_tag_ids );
        }
    }

    // -----------------------------------------------------------------------
    // Public static helpers
    // -----------------------------------------------------------------------

    public static function is_active(): bool {
        return function_exists( 'pmpro_getMembershipLevelForUser' );
    }

    public static function get_all_levels(): array {
        if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
            return [];
        }
        return (array) pmpro_getAllLevels( true );
    }

    public static function get_user_level( int $user_id ) {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return false;
        }
        return pmpro_getMembershipLevelForUser( $user_id );
    }

    public static function get_user_levels( int $user_id ): array {
        if ( function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
            return (array) pmpro_getMembershipLevelsForUser( $user_id );
        }
        $level = self::get_user_level( $user_id );
        return $level ? [ $level ] : [];
    }

    /**
     * Returns the "smart" expiration date for a PMPro member.
     */
    public static function get_smart_expiration_date( int $user_id, object $level ): ?string {
        if ( ! empty( $level->enddate ) && (int) $level->enddate > 0 ) {
            // wp_date(), not date(): PMPro hands back a UTC epoch and renders
            // it in the site timezone on its own screens. date() formats in
            // UTC (the WordPress process timezone), which rolls a 12/31
            // expiry back to 12/30 for any site west of Greenwich.
            return wp_date( 'Y-m-d', (int) $level->enddate );
        }

        if ( ! class_exists( 'MemberOrder' ) ) {
            return null;
        }

        $order = new MemberOrder();
        $order->getLastMemberOrder( $user_id, 'success' );

        if ( empty( $order->id ) ) {
            return null;
        }

        if ( function_exists( 'pmpro_next_payment' ) ) {
            $next_ts = pmpro_next_payment( $order, 'timestamp', false );
            if ( $next_ts && (int) $next_ts > 0 ) {
                return wp_date( 'Y-m-d', (int) $next_ts );
            }
        }

        if ( ! empty( $order->next_payment_date ) ) {
            // gmdate() here: this is a date *string* parsed by strtotime() in
            // UTC, so formatting back in UTC preserves the literal calendar
            // day rather than shifting it.
            $ts = strtotime( $order->next_payment_date );
            if ( false !== $ts && $ts > 0 ) {
                return gmdate( 'Y-m-d', $ts );
            }
        }

        return null;
    }

    /**
     * WP-Cron callback: sync expiration dates for all active PMPro members.
     */
    public static function run_expiry_cron(): void {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return;
        }

        global $wpdb;

        $engine             = My_IAPSNJ_Engine::get_instance();
        $expiry_mapping_ids = self::get_expiry_mapping_ids();

        if ( empty( $expiry_mapping_ids ) ) {
            // The seeded expiration_date mapping is ACF-sourced, so this filter
            // used to match nothing and the cron returned before doing any work
            // *and* before recording a timestamp — reading as "never ran"
            // rather than "ran and did nothing". Self-heal, then say so.
            My_IAPSNJ_Field_Mapper::ensure_pmp_expiry_mapping();
            $expiry_mapping_ids = self::get_expiry_mapping_ids();
        }

        if ( empty( $expiry_mapping_ids ) ) {
            error_log(
                'My IAPSNJ expiry cron: no enabled PMPro-sourced expiration_date mapping found; '
                . 'nothing to sync. Run Memberships → Auto-Setup Mapping.'
            );
            update_option( 'my_iapsnj_pmp_expiry_last_sync', current_time( 'mysql' ) );
            return;
        }

        $per_page = 50;
        $offset   = 0;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $user_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->prefix}pmpro_memberships_users
                 WHERE status = 'active'
                 ORDER BY user_id ASC
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ) );

            if ( empty( $user_ids ) ) {
                break;
            }

            foreach ( $user_ids as $user_id ) {
                try {
                    $engine->sync_wp_to_fcrm( (int) $user_id, $expiry_mapping_ids );
                } catch ( \Throwable $e ) {
                    error_log( 'My IAPSNJ expiry cron error for user ' . $user_id . ': ' . $e->getMessage() );
                }
            }

            $offset += $per_page;
        } while ( count( $user_ids ) === $per_page );

        update_option( 'my_iapsnj_pmp_expiry_last_sync', current_time( 'mysql' ) );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function find_subscriber( int $user_id ): ?Subscriber {
        return My_IAPSNJ_Engine::find_linked_subscriber( $user_id );
    }

    /**
     * IDs of every enabled mapping that pushes the PMPro expiration date.
     */
    private static function get_expiry_mapping_ids(): array {
        $ids = [];
        foreach ( My_IAPSNJ_Engine::get_instance()->get_mapper()->get_active_mappings() as $m ) {
            if ( ( $m['wp_field_key'] ?? '' ) === 'expiration_date'
                && ( $m['wp_field_source'] ?? '' ) === 'pmp'
                && ! empty( $m['id'] )
            ) {
                $ids[] = $m['id'];
            }
        }
        return $ids;
    }
}
