<?php
/**
 * My_IAPSNJ_Payments
 *
 * The Payments screen's data (Membership → Payments):
 *
 *  - the ways a payment can be entered by hand (check, cash in person,
 *    Venmo) and how each one reads on an order and in the emails;
 *  - stripe(): every payment taken by Stripe through FluentCart's own
 *    checkout, newest first, read straight from FluentCart's tables.
 *
 * Reading only: nothing here writes to FluentCart, FluentCRM or WordPress.
 * Entering a payment is My_IAPSNJ_Checks::record_payment(); marking an open
 * check invoice paid is My_IAPSNJ_Checks::mark_paid().
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Payments {

    const METHOD_CHECK = 'check';
    const METHOD_CASH  = 'cash';
    const METHOD_VENMO = 'venmo';

    const PER_PAGE = 50;

    /**
     * Manual payment methods, in the order the form lists them.
     *
     * label           how the method reads on the order, the staff email and in lists
     * reference_label the form's label for the reference field
     * date_label      the form's label for the date the payment counts from
     *
     * @return array<string,array{label:string,reference_label:string,date_label:string}>
     */
    public static function methods(): array {
        return [
            self::METHOD_CHECK => [
                'label'           => __( 'Check', 'my-iapsnj' ),
                'reference_label' => __( 'Check number', 'my-iapsnj' ),
                'date_label'      => __( 'Deposit date', 'my-iapsnj' ),
            ],
            self::METHOD_CASH  => [
                'label'           => __( 'Cash (in person)', 'my-iapsnj' ),
                'reference_label' => __( 'Receipt number', 'my-iapsnj' ),
                'date_label'      => __( 'Date received', 'my-iapsnj' ),
            ],
            self::METHOD_VENMO => [
                'label'           => __( 'Venmo', 'my-iapsnj' ),
                'reference_label' => __( 'Venmo transaction ID', 'my-iapsnj' ),
                'date_label'      => __( 'Date received', 'my-iapsnj' ),
            ],
        ];
    }

    /**
     * A method slug the plugin knows; check (the only offline method before
     * 4.39) for anything else, including an order that predates the meta.
     */
    public static function normalize_method( string $method ): string {
        $method = sanitize_key( $method );
        return isset( self::methods()[ $method ] ) ? $method : self::METHOD_CHECK;
    }

    /**
     * Label of an order's payment method: a manual method, 'card', or any
     * other FluentCart gateway slug.
     */
    public static function label( string $method ): string {
        $methods = self::methods();
        if ( isset( $methods[ $method ] ) ) {
            return $methods[ $method ]['label'];
        }
        if ( $method === 'card' || $method === 'stripe' ) {
            return __( 'Card', 'my-iapsnj' );
        }
        if ( $method === 'paypal' ) {
            return __( 'PayPal', 'my-iapsnj' );
        }
        return $method !== '' ? ucwords( str_replace( '_', ' ', $method ) ) : '';
    }

    // -----------------------------------------------------------------------
    // Stripe payments
    // -----------------------------------------------------------------------

    /**
     * One page of the successful Stripe charges, newest first. Each row is a
     * charge (a renewal is a charge on its own order), with the order's
     * current payment status so a refund still shows.
     *
     * $args: from / to (site-timezone Y-m-d, inclusive), s (name or email),
     * paged (≥ 1), per_page.
     *
     * @return array{rows:array<int,array>,total:int,pages:int,paged:int,sum_cents:int,currency:string}
     */
    public static function stripe( array $args = [] ): array {
        $out = [ 'rows' => [], 'total' => 0, 'pages' => 1, 'paged' => 1, 'sum_cents' => 0, 'currency' => '' ];
        if ( ! My_IAPSNJ_Membership::is_available() ) {
            return $out;
        }
        global $wpdb;
        $p        = $wpdb->prefix;
        $per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? self::PER_PAGE ) ) );
        $from     = My_IAPSNJ_Dates::ymd( $args['from'] ?? '' );
        $to       = My_IAPSNJ_Dates::ymd( $args['to'] ?? '' );
        $search   = trim( (string) ( $args['s'] ?? '' ) );

        $where  = [ "t.payment_method = 'stripe'", "t.transaction_type = 'charge'", "t.status = 'succeeded'" ];
        $params = [];
        if ( $from !== '' ) {
            $where[]  = 't.created_at >= %s';
            $params[] = get_gmt_from_date( $from . ' 00:00:00' );
        }
        if ( $to !== '' ) {
            $where[]  = 't.created_at <= %s';
            $params[] = get_gmt_from_date( $to . ' 23:59:59' );
        }
        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = "( c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR CONCAT( c.first_name, ' ', c.last_name ) LIKE %s )";
            array_push( $params, $like, $like, $like, $like );
        }
        $from_sql = "{$p}fct_order_transactions t
             INNER JOIN {$p}fct_orders o ON o.id = t.order_id
             LEFT JOIN {$p}fct_customers c ON c.id = o.customer_id
             WHERE " . implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- identifiers are the plugin's own, values are prepared
        $totals = $wpdb->get_row(
            $params
                ? $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(t.total), 0) AS sum_cents, MAX(t.currency) AS currency FROM {$from_sql}", $params )
                : "SELECT COUNT(*) AS n, COALESCE(SUM(t.total), 0) AS sum_cents, MAX(t.currency) AS currency FROM {$from_sql}",
            ARRAY_A
        );
        $out['total']     = (int) ( $totals['n'] ?? 0 );
        $out['sum_cents'] = (int) ( $totals['sum_cents'] ?? 0 );
        $out['currency']  = (string) ( $totals['currency'] ?? '' );
        $out['pages']     = max( 1, (int) ceil( $out['total'] / $per_page ) );
        $out['paged']     = min( max( 1, (int) ( $args['paged'] ?? 1 ) ), $out['pages'] );
        if ( $out['total'] === 0 ) {
            return $out;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id AS transaction_id, t.order_id, t.vendor_charge_id, t.total, t.currency, t.created_at,
                    o.payment_status, o.type AS order_type, o.mode,
                    c.first_name, c.last_name, c.email
             FROM {$from_sql}
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT %d OFFSET %d",
            array_merge( $params, [ $per_page, ( $out['paged'] - 1 ) * $per_page ] )
        ), ARRAY_A );
        // phpcs:enable

        $rows      = (array) $rows;
        $order_ids = array_values( array_unique( array_map( 'intval', array_column( $rows, 'order_id' ) ) ) );
        $items     = self::order_items( $order_ids );
        foreach ( $rows as $r ) {
            $oid   = (int) $r['order_id'];
            $name  = trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
            $charge = (string) $r['vendor_charge_id'];
            $out['rows'][] = [
                'order_id'     => $oid,
                'order_url'    => My_IAPSNJ_Members::order_url( $oid ),
                'date'         => My_IAPSNJ_Dates::mysql_utc_display( (string) $r['created_at'], 'M j, Y g:i a' ),
                'name'         => $name,
                'email'        => (string) $r['email'],
                'items'        => $items[ $oid ] ?? [],
                'amount'       => My_IAPSNJ_Membership::format_money( (int) $r['total'], (string) $r['currency'] ),
                'status'       => self::status_label( (string) $r['payment_status'] ),
                'refunded'     => in_array( (string) $r['payment_status'], [ 'refunded', 'partially_refunded' ], true ),
                'renewal'      => (string) $r['order_type'] === 'renewal',
                'charge_id'    => $charge,
                'stripe_url'   => self::stripe_url( $charge, (string) $r['mode'] ),
            ];
        }
        return $out;
    }

    /**
     * Product lines of orders, in one query.
     *
     * @param int[] $order_ids
     * @return array<int,string[]>
     */
    private static function order_items( array $order_ids ): array {
        if ( ! $order_ids ) {
            return [];
        }
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT order_id, post_title, title FROM {$p}fct_order_items
             WHERE order_id IN (" . implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) ) . ') ORDER BY id ASC',
            $order_ids
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $label = trim( (string) $r['post_title'] . ' ' . (string) $r['title'] );
            if ( $label !== '' ) {
                $out[ (int) $r['order_id'] ][] = $label;
            }
        }
        return $out;
    }

    private static function status_label( string $status ): string {
        switch ( $status ) {
            case 'paid':
                return __( 'Paid', 'my-iapsnj' );
            case 'refunded':
                return __( 'Refunded', 'my-iapsnj' );
            case 'partially_refunded':
                return __( 'Partially refunded', 'my-iapsnj' );
            case 'partially_paid':
                return __( 'Partially paid', 'my-iapsnj' );
        }
        return $status !== '' ? ucwords( str_replace( '_', ' ', $status ) ) : '';
    }

    /**
     * The charge in the Stripe dashboard ('' unless the id looks like a Stripe
     * charge / payment intent).
     */
    private static function stripe_url( string $charge_id, string $mode ): string {
        if ( ! preg_match( '/^(ch|pi)_[A-Za-z0-9]+$/', $charge_id ) ) {
            return '';
        }
        return 'https://dashboard.stripe.com/' . ( $mode === 'test' ? 'test/' : '' ) . 'payments/' . $charge_id;
    }
}
