<?php
/**
 * My_IAPSNJ_Reports
 *
 * Read-only operational reports. With FluentCRM as the single source of
 * truth there is nothing to "reconcile" field by field any more; what
 * remains is:
 *
 *  - Orphan check   WordPress users with no CRM contact, and CRM contacts
 *                   pointing at a WordPress user that no longer exists
 *  - Orphan report  paid orders with no application, applications with no
 *                   order (the abandoned-checkout follow-up list)
 *  - Aging report   checks pending 30+ days
 *  - Summary        counts for the dashboard
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Reports {

    // -----------------------------------------------------------------------
    // Orphan check: WP ↔ CRM
    // -----------------------------------------------------------------------

    /**
     * WordPress users with no FluentCRM contact (neither user_id link nor email).
     *
     * @return array{items: array, has_more: bool, next_offset: int, total_users: int}
     */
    public static function users_without_contact( int $offset = 0, int $limit = 200 ): array {
        $users = get_users( [
            'number'  => $limit,
            'offset'  => $offset,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => [ 'ID', 'user_email', 'display_name', 'user_login', 'user_registered' ],
        ] );
        $total = (int) count_users()['total_users'];
        if ( ! $users ) {
            return [ 'items' => [], 'has_more' => false, 'next_offset' => $offset, 'total_users' => $total ];
        }
        $ids    = array_map( 'intval', wp_list_pluck( $users, 'ID' ) );
        $emails = array_map( 'strtolower', array_filter( wp_list_pluck( $users, 'user_email' ) ) );

        $linked_ids    = [];
        $linked_emails = [];
        foreach ( Subscriber::whereIn( 'user_id', $ids )->get( [ 'id', 'user_id', 'email' ] ) as $s ) {
            $linked_ids[ (int) $s->user_id ] = true;
        }
        if ( $emails ) {
            foreach ( Subscriber::whereIn( 'email', $emails )->get( [ 'id', 'email' ] ) as $s ) {
                $linked_emails[ strtolower( (string) $s->email ) ] = true;
            }
        }

        $items = [];
        foreach ( $users as $u ) {
            if ( isset( $linked_ids[ (int) $u->ID ] ) || isset( $linked_emails[ strtolower( (string) $u->user_email ) ] ) ) {
                continue;
            }
            $items[] = [
                'user_id'    => (int) $u->ID,
                'login'      => (string) $u->user_login,
                'email'      => (string) $u->user_email,
                'name'       => (string) $u->display_name,
                'registered' => (string) $u->user_registered,
                'edit_url'   => admin_url( 'user-edit.php?user_id=' . (int) $u->ID ),
            ];
        }
        return [
            'items'       => $items,
            'has_more'    => count( $users ) === $limit,
            'next_offset' => $offset + count( $users ),
            'total_users' => $total,
        ];
    }

    /**
     * CRM contacts whose user_id points at a WordPress user that does not exist.
     *
     * @return array{items: array, has_more: bool, next_offset: int}
     */
    public static function contacts_with_missing_user( int $offset = 0, int $limit = 500 ): array {
        global $wpdb;
        $subs = Subscriber::whereNotNull( 'user_id' )->where( 'user_id', '>', 0 )
            ->orderBy( 'id' )->skip( $offset )->take( $limit )->get( [ 'id', 'user_id', 'email', 'first_name', 'last_name' ] );
        if ( $subs->isEmpty() ) {
            return [ 'items' => [], 'has_more' => false, 'next_offset' => $offset ];
        }
        $ids = array_values( array_unique( array_map( 'intval', $subs->pluck( 'user_id' )->toArray() ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID IN ({$placeholders})", ...$ids ) ) );
        $existing = array_flip( $existing );

        $items = [];
        foreach ( $subs as $s ) {
            if ( isset( $existing[ (int) $s->user_id ] ) ) {
                continue;
            }
            $items[] = [
                'subscriber_id' => (int) $s->id,
                'user_id'       => (int) $s->user_id,
                'email'         => (string) $s->email,
                'name'          => trim( (string) $s->first_name . ' ' . (string) $s->last_name ),
                'crm_url'       => admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $s->id ),
            ];
        }
        return [
            'items'       => $items,
            'has_more'    => $subs->count() === $limit,
            'next_offset' => $offset + $subs->count(),
        ];
    }

    // -----------------------------------------------------------------------
    // Orphan report: orders ↔ applications
    // -----------------------------------------------------------------------

    /**
     * Paid membership orders with no application row. Orders recorded through
     * "Record a Check" are excluded by design (there is no application).
     *
     * @return array<int,array>
     */
    public static function orders_without_application( int $days_back = 400, int $limit = 500 ): array {
        if ( ! My_IAPSNJ_Membership::is_available() ) {
            return [];
        }
        $settings = My_IAPSNJ_Plugin::settings();
        $since_ts = time() - max( 1, $days_back ) * DAY_IN_SECONDS;
        $cutover  = My_IAPSNJ_Dates::ymd( $settings['cutover_date'] ?? '' );
        if ( $cutover !== '' ) {
            $since_ts = max( $since_ts, (int) strtotime( $cutover . ' 00:00:00 UTC' ) );
        }
        $since = gmdate( 'Y-m-d H:i:s', $since_ts );

        $orders = \FluentCart\App\Models\Order::query()
            ->where( 'payment_status', 'paid' )
            ->where( 'created_at', '>=', $since )
            ->with( [ 'customer', 'order_items' ] )
            ->orderBy( 'created_at', 'desc' )
            ->limit( $limit )
            ->get();

        $ids       = array_map( 'intval', $orders->pluck( 'id' )->toArray() );
        $with_app  = array_flip( My_IAPSNJ_Applications::order_ids_with_application( $ids ) );

        $items = [];
        foreach ( $orders as $order ) {
            if ( isset( $with_app[ (int) $order->id ] ) ) {
                continue;
            }
            if ( ! My_IAPSNJ_Membership::plan_for_order( $order ) ) {
                continue;
            }
            if ( (string) $order->getMeta( My_IAPSNJ_Membership::META_SOURCE ) === 'manual_check' ) {
                continue;
            }
            $customer = is_object( $order->customer ?? null ) ? $order->customer : null;
            $items[]  = [
                'order_id'   => (int) $order->id,
                'date'       => My_IAPSNJ_Dates::mysql_utc_display( (string) $order->created_at ),
                'email'      => $customer ? (string) $customer->email : '',
                'name'       => $customer ? trim( (string) $customer->first_name . ' ' . (string) $customer->last_name ) : '',
                'total'      => My_IAPSNJ_Membership::format_money( (int) $order->total_amount, (string) $order->currency ),
                'payment'    => (string) $order->payment_method === My_IAPSNJ_Membership::OFFLINE_METHOD ? 'check' : 'card',
                'applied'    => (bool) $order->getMeta( My_IAPSNJ_Membership::META_APPLIED ),
                'admin_url'  => My_IAPSNJ_Membership::order_admin_url( $order ),
            ];
        }
        return $items;
    }

    /**
     * Applications with no paid order (pending + awaiting check).
     *
     * @return array<int,array>
     */
    public static function applications_without_order( int $older_than_days = 0 ): array {
        $rows  = My_IAPSNJ_Applications::open_applications( $older_than_days );
        $items = [];
        foreach ( $rows as $r ) {
            $items[] = [
                'id'            => (int) $r->id,
                'kind'          => (string) $r->kind,
                'status'        => (string) $r->status,
                'date'          => My_IAPSNJ_Dates::mysql_utc_display( (string) $r->created_at ),
                'age_days'      => My_IAPSNJ_Dates::days_since_utc( (string) $r->created_at ),
                'name'          => trim( (string) $r->first_name . ' ' . (string) $r->last_name ),
                'email'         => (string) $r->email,
                'subscriber_id' => (int) $r->subscriber_id,
                'order_id'      => (int) $r->order_id,
                'crm_url'       => $r->subscriber_id ? admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $r->subscriber_id ) : '',
                'entry_url'     => ( $r->form_id && $r->submission_id ) ? admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $r->form_id . '#/entries/' . (int) $r->submission_id ) : '',
            ];
        }
        return $items;
    }

    // -----------------------------------------------------------------------
    // Aging report
    // -----------------------------------------------------------------------

    /**
     * Checks pending N+ days (default from settings, 30).
     */
    public static function aging_checks( int $days = 0 ): array {
        if ( $days <= 0 ) {
            $days = (int) ( My_IAPSNJ_Plugin::settings()['aging_days'] ?? 30 );
        }
        return My_IAPSNJ_Checks::pending( [ 'min_age_days' => max( 1, $days ) ] );
    }

    // -----------------------------------------------------------------------
    // Summary
    // -----------------------------------------------------------------------

    public static function summary(): array {
        global $wpdb;

        $out = [
            'wp_users'          => (int) count_users()['total_users'],
            'crm_contacts'      => (int) Subscriber::count(),
            'members_by_type'   => [],
            'paid_years'        => [],
            'pending_checks'    => 0,
            'pending_total'     => '',
            'aging_checks'      => 0,
            'open_applications' => 0,
            'applications'      => My_IAPSNJ_Applications::count_by_status(),
        ];

        // member_type distribution.
        $meta_table = $wpdb->prefix . 'fc_subscriber_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT `value` AS v, COUNT(*) AS n FROM `{$meta_table}` WHERE object_type = 'custom_field' AND `key` = %s GROUP BY `value`",
            My_IAPSNJ_Schema::FIELD_MEMBER_TYPE
        ) );
        foreach ( (array) $rows as $r ) {
            $out['members_by_type'][ (string) $r->v ] = (int) $r->n;
        }

        // Paid-YYYY tag counts.
        $pivot = $wpdb->prefix . 'fc_subscriber_pivot';
        $tags  = $wpdb->prefix . 'fc_tags';
        $like  = $wpdb->esc_like( My_IAPSNJ_Schema::TAG_PAID_PREFIX ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.slug, COUNT(p.id) AS n FROM `{$tags}` t
             LEFT JOIN `{$pivot}` p ON p.object_id = t.id AND p.object_type = %s
             WHERE t.slug LIKE %s GROUP BY t.slug ORDER BY t.slug",
            'FluentCrm\App\Models\Tag',
            $like
        ) );
        foreach ( (array) $rows as $r ) {
            $out['paid_years'][ (string) $r->slug ] = (int) $r->n;
        }

        if ( My_IAPSNJ_Checks::is_available() ) {
            $pending = My_IAPSNJ_Checks::pending();
            $cents   = 0;
            $cur     = '';
            $aging   = (int) ( My_IAPSNJ_Plugin::settings()['aging_days'] ?? 30 );
            foreach ( $pending as $p ) {
                $cents += (int) $p['total_cents'];
                $cur    = $cur ?: $p['currency'];
                if ( $p['age_days'] >= $aging ) {
                    $out['aging_checks']++;
                }
            }
            $out['pending_checks'] = count( $pending );
            $out['pending_total']  = My_IAPSNJ_Membership::format_money( $cents, $cur );
        }

        $out['open_applications'] = (int) ( $out['applications'][ My_IAPSNJ_Applications::STATUS_PENDING ] ?? 0 )
            + (int) ( $out['applications'][ My_IAPSNJ_Applications::STATUS_AWAITING_CHECK ] ?? 0 );

        return $out;
    }
}
