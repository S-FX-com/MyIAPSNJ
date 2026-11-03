<?php
/**
 * My_IAPSNJ_Payments
 *
 * The Payments screen's data (Membership → Payments):
 *
 *  - the ways a payment can be entered by hand (check, cash in person,
 *    Venmo) and how each one reads on an order and in the emails;
 *  - manual(): the log of payments entered by hand (check, cash, Venmo),
 *    including checks marked paid from Open Invoices;
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
    // Manual payment log
    // -----------------------------------------------------------------------

    /**
     * One page of the payments that did not go through a card: every paid
     * order of FluentCart's offline method (a check marked paid from Open
     * Invoices, or a payment entered under Record Payment), newest payment
     * date first. The payment date is the date the term counts from (a
     * check's deposit date, the day cash / Venmo was received), else the
     * order date for an order that predates it.
     *
     * $args: method (check | cash | venmo | ''), from / to (Y-m-d, inclusive),
     * s (name, email, check number or reference), paged, per_page.
     *
     * @return array{rows:array<int,array>,total:int,pages:int,paged:int,sum_cents:int,currency:string}
     */
    public static function manual( array $args = [] ): array {
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
        $method   = isset( self::methods()[ (string) ( $args['method'] ?? '' ) ] ) ? (string) $args['method'] : '';
        $meta     = "{$p}fct_order_meta";
        $when     = "COALESCE(NULLIF(d.meta_value, ''), DATE(o.created_at))";

        $where  = [ 'o.payment_method = %s', "o.payment_status IN ('paid', 'partially_refunded', 'refunded')" ];
        $params = [ My_IAPSNJ_Membership::META_DEPOSIT_DATE, My_IAPSNJ_Membership::OFFLINE_METHOD ];
        if ( $from !== '' ) {
            $where[]  = "{$when} >= %s";
            $params[] = $from;
        }
        if ( $to !== '' ) {
            $where[]  = "{$when} <= %s";
            $params[] = $to;
        }
        if ( $method === self::METHOD_CHECK ) {
            // A check is the default: orders from before the method was recorded have no meta.
            $where[]  = "NOT EXISTS (SELECT 1 FROM {$meta} mm WHERE mm.order_id = o.id AND mm.meta_key = %s AND mm.meta_value IN (%s, %s, %s, %s))";
            array_push( $params, My_IAPSNJ_Membership::META_PAY_METHOD, self::METHOD_CASH, self::METHOD_VENMO, '"' . self::METHOD_CASH . '"', '"' . self::METHOD_VENMO . '"' );
        } elseif ( $method !== '' ) {
            $where[]  = "EXISTS (SELECT 1 FROM {$meta} mm WHERE mm.order_id = o.id AND mm.meta_key = %s AND mm.meta_value IN (%s, %s))";
            array_push( $params, My_IAPSNJ_Membership::META_PAY_METHOD, $method, '"' . $method . '"' );
        }
        if ( $search !== '' ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = "( c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR CONCAT( c.first_name, ' ', c.last_name ) LIKE %s
                OR EXISTS (SELECT 1 FROM {$meta} mr WHERE mr.order_id = o.id AND mr.meta_key IN (%s, %s) AND mr.meta_value LIKE %s) )";
            array_push( $params, $like, $like, $like, $like, My_IAPSNJ_Membership::META_CHECK_NUMBER, My_IAPSNJ_Membership::META_PAY_REF, $like );
        }
        $from_sql = "{$p}fct_orders o
             LEFT JOIN {$p}fct_customers c ON c.id = o.customer_id
             LEFT JOIN {$meta} d ON d.order_id = o.id AND d.meta_key = %s
             WHERE " . implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- identifiers are the plugin's own, values are prepared
        $totals = $wpdb->get_row(
            $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(o.total_amount), 0) AS sum_cents, MAX(o.currency) AS currency FROM {$from_sql}", $params ),
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
            "SELECT o.id, o.total_amount, o.currency, o.payment_status, o.note, o.created_at,
                    c.first_name, c.last_name, c.email, d.meta_value AS paid_on
             FROM {$from_sql}
             ORDER BY {$when} DESC, o.id DESC
             LIMIT %d OFFSET %d",
            array_merge( $params, [ $per_page, ( $out['paged'] - 1 ) * $per_page ] )
        ), ARRAY_A );
        // phpcs:enable

        $rows      = (array) $rows;
        $order_ids = array_values( array_unique( array_map( 'intval', array_column( $rows, 'id' ) ) ) );
        $items     = self::order_items( $order_ids );
        $metas     = self::order_meta( $order_ids, [
            My_IAPSNJ_Membership::META_PAY_METHOD,
            My_IAPSNJ_Membership::META_CHECK_NUMBER,
            My_IAPSNJ_Membership::META_PAY_REF,
            My_IAPSNJ_Membership::META_SOURCE,
        ] );
        foreach ( $rows as $r ) {
            $oid    = (int) $r['id'];
            $m      = $metas[ $oid ] ?? [];
            $paid   = My_IAPSNJ_Dates::ymd( (string) $r['paid_on'] );
            $source = (string) ( $m[ My_IAPSNJ_Membership::META_SOURCE ] ?? '' );
            $out['rows'][] = [
                'order_id'  => $oid,
                'order_url' => My_IAPSNJ_Members::order_url( $oid ),
                'paid_on'   => $paid !== '' ? My_IAPSNJ_Dates::ymd_display( $paid ) : My_IAPSNJ_Dates::mysql_utc_display( (string) $r['created_at'] ),
                'method'    => self::label( self::normalize_method( (string) ( $m[ My_IAPSNJ_Membership::META_PAY_METHOD ] ?? '' ) ) ),
                'reference' => (string) ( ( $m[ My_IAPSNJ_Membership::META_CHECK_NUMBER ] ?? '' ) ?: ( $m[ My_IAPSNJ_Membership::META_PAY_REF ] ?? '' ) ),
                'name'      => trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] ),
                'email'     => (string) $r['email'],
                'items'     => $items[ $oid ] ?? [],
                'amount'    => My_IAPSNJ_Membership::format_money( (int) $r['total_amount'], (string) $r['currency'] ),
                'status'    => self::status_label( (string) $r['payment_status'] ),
                'refunded'  => in_array( (string) $r['payment_status'], [ 'refunded', 'partially_refunded' ], true ),
                'entered'   => My_IAPSNJ_Membership::is_manual_source( $source ),
                'note'      => (string) $r['note'],
            ];
        }
        return $out;
    }

    /**
     * Order meta of many orders in one query (first row per order and key).
     * Plain strings are stored as they are; a JSON-quoted one is unquoted.
     *
     * @param int[]    $order_ids
     * @param string[] $keys
     * @return array<int,array<string,string>>
     */
    private static function order_meta( array $order_ids, array $keys ): array {
        if ( ! $order_ids || ! $keys ) {
            return [];
        }
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT order_id, meta_key, meta_value FROM {$p}fct_order_meta
             WHERE meta_key IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')
             AND order_id IN (' . implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) ) . ') ORDER BY id ASC',
            array_merge( $keys, $order_ids )
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $oid = (int) $r['order_id'];
            $key = (string) $r['meta_key'];
            if ( isset( $out[ $oid ][ $key ] ) ) {
                continue;
            }
            $v = (string) $r['meta_value'];
            if ( strlen( $v ) > 1 && $v[0] === '"' ) {
                $decoded = json_decode( $v );
                $v       = is_string( $decoded ) ? $decoded : $v;
            }
            $out[ $oid ][ $key ] = $v;
        }
        return $out;
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
