<?php
/**
 * FCRM_WP_Sync_PMP_Integration
 *
 * Integrates Paid Memberships Pro (PMPro) with FluentCRM Sync:
 *  - Triggers a WP → FluentCRM field sync when a user's membership level changes
 *    (so join date and expiration date are pushed to FluentCRM automatically).
 *  - Applies FluentCRM tags to subscribers based on their active PMPro level(s),
 *    using the admin-configured level → tag mapping.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class FCRM_WP_Sync_PMP_Integration {

    /** @var self|null */
    private static ?self $instance = null;

    // -----------------------------------------------------------------------

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    // -----------------------------------------------------------------------
    // Hook registration
    // -----------------------------------------------------------------------

    private function register_hooks(): void {
        $settings = get_option( 'fcrm_wp_sync_settings', [] );

        if ( ! empty( $settings['sync_on_pmp_change'] ) ) {
            // Fire a field sync each time a single level changes.
            add_action( 'pmpro_after_change_membership_level', [ $this, 'on_membership_level_change' ], 20, 2 );
        }

        // Tag mapping is always active when configured — fires once per page load
        // after all membership changes for all affected users have been written.
        add_action( 'pmpro_after_all_membership_level_changes', [ $this, 'on_all_membership_level_changes' ], 20 );
    }

    // -----------------------------------------------------------------------
    // PMPro hook callbacks
    // -----------------------------------------------------------------------

    /**
     * Fires after a single membership level change.
     * Triggers a WP → FluentCRM field sync so date fields are updated immediately.
     *
     * @param int $level_id  New level ID (0 = cancelled/no level).
     * @param int $user_id
     */
    public function on_membership_level_change( int $level_id, int $user_id ): void {
        $engine = FCRM_WP_Sync_Engine::get_instance();
        $engine->sync_wp_to_fcrm( $user_id );
    }

    /**
     * Fires once per page load after all membership level changes are committed.
     * Re-synchronises FluentCRM tags for every affected user.
     *
     * @param array $old_levels  [ user_id => [old_level_objects] ]
     */
    public function on_all_membership_level_changes( array $old_levels ): void {
        $tag_mappings = get_option( 'fcrm_wp_sync_pmp_tag_mappings', [] );
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

    /**
     * Re-applies FluentCRM tags for a user based on their current PMPro level(s).
     *
     * Strategy:
     *  1. Collect every tag ID that any level mapping controls.
     *  2. Determine which tags belong to the user's CURRENT active levels.
     *  3. Remove managed tags that no longer apply; add the ones that do.
     *
     * @param int   $user_id
     * @param array $tag_mappings  [ level_id (int|string) => [ tag_id (int), … ] ]
     */
    public function sync_tags_for_user( int $user_id, array $tag_mappings ): void {
        $subscriber = $this->find_subscriber( $user_id );
        if ( ! $subscriber ) {
            return;
        }

        // All FluentCRM tag IDs managed by any PMP level mapping.
        $all_managed_tag_ids = [];
        foreach ( $tag_mappings as $tag_ids ) {
            foreach ( (array) $tag_ids as $tid ) {
                $all_managed_tag_ids[] = (int) $tid;
            }
        }
        $all_managed_tag_ids = array_unique( $all_managed_tag_ids );

        // Tags that should be active based on the user's current PMP levels.
        $active_tag_ids  = [];
        $current_levels  = self::get_user_levels( $user_id );

        foreach ( $current_levels as $level ) {
            $lid = (int) ( $level->id ?? $level->ID ?? 0 );
            if ( isset( $tag_mappings[ $lid ] ) ) {
                foreach ( (array) $tag_mappings[ $lid ] as $tid ) {
                    $active_tag_ids[] = (int) $tid;
                }
            }
        }
        $active_tag_ids = array_unique( $active_tag_ids );

        // Remove managed tags that no longer apply.
        $tags_to_remove = array_values( array_diff( $all_managed_tag_ids, $active_tag_ids ) );
        if ( ! empty( $tags_to_remove ) ) {
            $subscriber->detachTags( $tags_to_remove );
        }

        // Attach tags for current levels.
        if ( ! empty( $active_tag_ids ) ) {
            $subscriber->attachTags( $active_tag_ids );
        }
    }

    // -----------------------------------------------------------------------
    // Public static helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true if Paid Memberships Pro is installed and active.
     */
    public static function is_active(): bool {
        return function_exists( 'pmpro_getMembershipLevelForUser' );
    }

    /**
     * Returns all defined PMPro membership levels (keyed by level ID).
     *
     * @return array<int, object>
     */
    public static function get_all_levels(): array {
        if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
            return [];
        }
        return (array) pmpro_getAllLevels( true );
    }

    /**
     * Returns the primary active membership level for a user, or false.
     *
     * @param int $user_id
     * @return object|false
     */
    public static function get_user_level( int $user_id ) {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return false;
        }
        return pmpro_getMembershipLevelForUser( $user_id );
    }

    /**
     * Returns all active membership levels for a user (multi-level aware).
     *
     * @param int $user_id
     * @return array
     */
    public static function get_user_levels( int $user_id ): array {
        // pmpro_getMembershipLevelsForUser was added in PMPro 2.x
        if ( function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
            return (array) pmpro_getMembershipLevelsForUser( $user_id );
        }
        // Fall back to single-level API for older PMPro versions.
        $level = self::get_user_level( $user_id );
        return $level ? [ $level ] : [];
    }

    /**
     * Returns the "smart" expiration date for a PMPro member.
     *
     * Resolution order:
     *  1. $level->enddate > 0  — fixed end date (non-recurring, or recurring with a
     *     defined billing cycle end). This covers the vast majority of cases.
     *  2. enddate = 0, recurring — look up the next scheduled payment date from the
     *     user's most recent successful order via MemberOrder::getLastMemberOrder()
     *     + pmpro_next_payment() (gateway-aware) or $order->next_payment_date
     *     (direct property set by some gateways).
     *  3. Truly unlimited / no date determinable — return null. The engine treats
     *     null as "nothing to sync" and leaves the FluentCRM field unchanged.
     *
     * @param int    $user_id
     * @param object $level   Already-resolved result of pmpro_getMembershipLevelForUser().
     * @return string|null    Y-m-d, or null.
     */
    public static function get_smart_expiration_date( int $user_id, object $level ): ?string {
        // Case 1: fixed end date.
        if ( ! empty( $level->enddate ) && (int) $level->enddate > 0 ) {
            return date( 'Y-m-d', (int) $level->enddate );
        }

        // Case 2: recurring with enddate = 0 — query the last order.
        if ( ! class_exists( 'MemberOrder' ) ) {
            return null;
        }

        $order = new MemberOrder();
        $order->getLastMemberOrder( $user_id, 'success' );

        if ( empty( $order->id ) ) {
            return null;
        }

        // Try pmpro_next_payment() first — it queries the payment gateway for the
        // next scheduled billing date. Third param false = use cached/local data only.
        if ( function_exists( 'pmpro_next_payment' ) ) {
            $next_ts = pmpro_next_payment( $order, 'timestamp', false );
            if ( $next_ts && (int) $next_ts > 0 ) {
                return date( 'Y-m-d', (int) $next_ts );
            }
        }

        // Fallback: some gateways write next_payment_date directly on the order object.
        if ( ! empty( $order->next_payment_date ) ) {
            $ts = strtotime( $order->next_payment_date );
            if ( false !== $ts && $ts > 0 ) {
                return date( 'Y-m-d', $ts );
            }
        }

        return null;
    }

    /**
     * WP-Cron callback: sync expiration dates for all active PMPro members.
     *
     * Processes in batches of 50 to avoid memory exhaustion on large sites.
     * Only runs if a pmp__expiration_date mapping is active.
     */
    public static function run_expiry_cron(): void {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return;
        }

        global $wpdb;

        $engine   = FCRM_WP_Sync_Engine::get_instance();
        $mappings = $engine->get_mapper()->get_active_mappings();

        $expiry_mapping_ids = [];
        foreach ( $mappings as $m ) {
            if ( ( $m['wp_field_key'] ?? '' ) === 'expiration_date'
                && ( $m['wp_field_source'] ?? '' ) === 'pmp'
            ) {
                $expiry_mapping_ids[] = $m['id'];
            }
        }

        if ( empty( $expiry_mapping_ids ) ) {
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
                    error_log( 'FCRM WP Sync expiry cron error for user ' . $user_id . ': ' . $e->getMessage() );
                }
            }

            $offset += $per_page;
        } while ( count( $user_ids ) === $per_page );

        update_option( 'fcrm_wp_sync_pmp_expiry_last_sync', current_time( 'mysql' ) );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Locate the FluentCRM subscriber for a WP user, by user_id or email.
     *
     * @param int $user_id
     * @return Subscriber|null
     */
    private function find_subscriber( int $user_id ): ?Subscriber {
        $subscriber = Subscriber::where( 'user_id', $user_id )->first();
        if ( $subscriber ) {
            return $subscriber;
        }
        $user = get_userdata( $user_id );
        return $user ? Subscriber::where( 'email', $user->user_email )->first() : null;
    }
}
