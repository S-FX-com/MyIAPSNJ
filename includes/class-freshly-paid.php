<?php
/**
 * My_IAPSNJ_Freshly_Paid
 *
 * Membership → Freshly Paid: the members whose membership payment was
 * applied in the last 7, 15 or 30 days, with a CSV of their mailing address
 * for a label mail merge.
 *
 * A "payment" is a membership order the plugin applied to the CRM (the
 * _my_iapsnj_applied order meta, written by My_IAPSNJ_Membership), whatever
 * the method: card, check, cash, Venmo. The moment that counts is applied_at
 * (when the payment was confirmed on the site, not a back-dated deposit date),
 * so a payment entered today is on the list today. Fully refunded orders are
 * left out. One row per member (their newest payment in the window); the
 * name and address are read from the member's FluentCRM contact.
 *
 * The window starts at midnight (site time) N days ago, so a report run on
 * Monday for 7 days includes last Monday's payments.
 *
 * Nothing here writes anywhere.
 *
 * CSV (freshlypaid-YYYYMMDDHHMM.csv): First Name, Last Name, Street Address,
 * City, State, Zip. State is the USPS two-letter code for a US address.
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Freshly_Paid {

    const WINDOWS        = [ 7, 15, 30 ];
    const DEFAULT_WINDOW = 7;
    const SCREEN_LIMIT   = 500;
    /** Orders older than this are never scanned (a check can wait weeks for its deposit). */
    const SCAN_DAYS      = 400;
    const SCAN_LIMIT     = 20000;

    // -----------------------------------------------------------------------
    // Hooks
    // -----------------------------------------------------------------------

    public static function register_hooks(): void {
        add_action( 'wp_ajax_my_iapsnj_freshly_paid_csv', static function () {
            self::handle_csv();
        } );
    }

    // -----------------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------------

    /**
     * 7, 15 or 30 (anything else is 7).
     *
     * @param mixed $value
     */
    public static function normalize_window( $value ): int {
        $days = (int) $value;
        return in_array( $days, self::WINDOWS, true ) ? $days : self::DEFAULT_WINDOW;
    }

    /**
     * First day of the window, Y-m-d in the site timezone.
     */
    public static function since_ymd( int $days ): string {
        return wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
    }

    /**
     * Members who paid in the window, by last name.
     *
     * Each row: id (contact), first_name, last_name, email, street, city,
     * state (USPS code for a US address), zip, has_address, member_type,
     * member_number, paid_on (Y-m-d, site time), method (label), amount,
     * order_id, order_url, crm_url.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rows( int $days ): array {
        if ( ! My_IAPSNJ_Membership::is_available() ) {
            return [];
        }
        $days     = self::normalize_window( $days );
        $midnight = ( new DateTimeImmutable( self::since_ymd( $days ) . ' 00:00:00', wp_timezone() ) )->getTimestamp();
        $latest   = self::latest_payments( $midnight );
        if ( ! $latest ) {
            return [];
        }

        $contacts = self::contacts( array_keys( $latest ) );
        $fields   = My_IAPSNJ_Members::lookup_fields( array_keys( $contacts ) );
        $rows     = [];
        foreach ( $latest as $subscriber_id => $pay ) {
            $c = $contacts[ $subscriber_id ] ?? null;
            if ( ! $c ) {
                continue; // contact deleted since
            }
            $country = (string) $c['country'];
            $street  = trim( (string) $c['address_line_1'] . ' ' . (string) $c['address_line_2'] );
            $city    = trim( (string) $c['city'] );
            $zip     = trim( (string) $c['postal_code'] );
            $rows[]  = [
                'id'            => (int) $subscriber_id,
                'first_name'    => trim( (string) $c['first_name'] ),
                'last_name'     => trim( (string) $c['last_name'] ),
                'email'         => (string) $c['email'],
                'street'        => $street,
                'city'          => $city,
                'state'         => My_IAPSNJ_States::code( (string) $c['state'], $country ),
                'zip'           => $zip,
                'has_address'   => $street !== '' && $city !== '' && $zip !== '',
                'member_type'   => (string) $pay['member_type'],
                'member_number' => (string) ( $fields[ $subscriber_id ][ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] ?? '' ),
                'paid_on'       => wp_date( 'Y-m-d', $pay['ts'] ),
                'method'        => My_IAPSNJ_Payments::label( (string) $pay['payment'] ),
                'amount'        => My_IAPSNJ_Membership::format_money( (int) $pay['total_cents'], (string) $pay['currency'] ),
                'order_id'      => (int) $pay['order_id'],
                'order_url'     => My_IAPSNJ_Members::order_url( (int) $pay['order_id'] ),
                'crm_url'       => My_IAPSNJ_Members::crm_url( (int) $subscriber_id ),
            ];
        }
        usort( $rows, static function ( array $a, array $b ): int {
            return strcasecmp( $a['last_name'] . ' ' . $a['first_name'], $b['last_name'] . ' ' . $b['first_name'] );
        } );
        return $rows;
    }

    /**
     * The newest applied payment per member since $since_ts.
     *
     * @return array<int,array{ts:int,order_id:int,member_type:string,payment:string,total_cents:int,currency:string}>
     */
    private static function latest_payments( int $since_ts ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_results( $wpdb->prepare(
            "SELECT om.order_id, om.meta_value, o.total_amount, o.currency
             FROM {$p}fct_order_meta om
             INNER JOIN {$p}fct_orders o ON o.id = om.order_id
             WHERE om.meta_key = %s
             AND o.payment_status IN ('paid', 'partially_paid', 'partially_refunded')
             AND o.created_at >= %s
             ORDER BY om.order_id DESC
             LIMIT %d",
            My_IAPSNJ_Membership::META_APPLIED,
            gmdate( 'Y-m-d H:i:s', $since_ts - self::SCAN_DAYS * DAY_IN_SECONDS ),
            self::SCAN_LIMIT
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $found as $r ) {
            $applied = json_decode( (string) $r['meta_value'], true );
            if ( ! is_array( $applied ) ) {
                $applied = maybe_unserialize( (string) $r['meta_value'] );
            }
            if ( ! is_array( $applied ) ) {
                continue;
            }
            $subscriber_id = (int) ( $applied['subscriber_id'] ?? 0 );
            $ts            = My_IAPSNJ_Dates::mysql_utc_to_ts( (string) ( $applied['applied_at'] ?? '' ) );
            if ( $subscriber_id <= 0 || $ts < $since_ts ) {
                continue;
            }
            if ( isset( $out[ $subscriber_id ] ) && $out[ $subscriber_id ]['ts'] >= $ts ) {
                continue;
            }
            $out[ $subscriber_id ] = [
                'ts'          => $ts,
                'order_id'    => (int) $r['order_id'],
                'member_type' => (string) ( $applied['member_type'] ?? '' ),
                'payment'     => (string) ( $applied['payment'] ?? '' ),
                'total_cents' => (int) $r['total_amount'],
                'currency'    => (string) $r['currency'],
            ];
        }
        return $out;
    }

    /**
     * Name and address of contacts.
     *
     * @param int[] $ids
     * @return array<int,array<string,string>>
     */
    private static function contacts( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) {
            return [];
        }
        $t   = My_IAPSNJ_Members::tables();
        $out = [];
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, first_name, last_name, email, address_line_1, address_line_2, city, state, postal_code, country
                 FROM {$t['subs']} WHERE id IN (" . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
                $chunk
            ), ARRAY_A );
            foreach ( (array) $rows as $r ) {
                $out[ (int) $r['id'] ] = array_map( 'strval', $r );
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Screen
    // -----------------------------------------------------------------------

    /**
     * Page body (below the page title).
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
        $days  = self::normalize_window( isset( $_GET['days'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['days'] ) ) : '' );
        $base  = admin_url( 'admin.php?page=my-iapsnj-freshly-paid' );
        $since = My_IAPSNJ_Dates::ymd_display( self::since_ymd( $days ) );

        if ( ! My_IAPSNJ_Membership::is_available() ) {
            echo '<div class="fcrm-section"><p>' . esc_html__( 'FluentCart is not active, so there are no payments to list.', 'my-iapsnj' ) . '</p></div>';
            return;
        }
        $rows    = self::rows( $days );
        $missing = count( array_filter( $rows, static function ( array $r ): bool {
            return ! $r['has_address'];
        } ) );

        echo '<p class="description">' . esc_html__( 'Members whose membership payment (card, check, cash or Venmo) reached the site in the last 7, 15 or 30 days. Export the mailing addresses as a CSV for a label mail merge.', 'my-iapsnj' ) . '</p>';
        echo '<ul class="subsubsub">';
        foreach ( self::WINDOWS as $i => $window ) {
            echo '<li><a href="' . esc_url( add_query_arg( 'days', $window, $base ) ) . '"' . ( $window === $days ? ' class="current" aria-current="page"' : '' ) . '>'
                . esc_html( sprintf( /* translators: %d: number of days */ _n( 'Last %d day', 'Last %d days', $window, 'my-iapsnj' ), $window ) )
                . '</a>' . ( $i < count( self::WINDOWS ) - 1 ? ' |' : '' ) . '</li> ';
        }
        echo '</ul><br class="clear" />';

        echo '<div class="fcrm-section">';
        echo '<p><strong>' . esc_html( sprintf( /* translators: 1: number of members, 2: first day of the window */ _n( '%1$s member paid since %2$s.', '%1$s members paid since %2$s.', count( $rows ), 'my-iapsnj' ), number_format_i18n( count( $rows ) ), $since ) ) . '</strong> ';
        if ( $rows ) {
            echo '<a class="button button-primary" href="' . esc_url( self::export_url( $days ) ) . '">' . esc_html__( 'Export CSV', 'my-iapsnj' ) . '</a>';
        }
        echo '</p>';
        if ( $missing ) {
            echo '<p class="fcrm-error">' . esc_html( sprintf( /* translators: %d: number of members */ _n( '%d member has no complete mailing address (street, city and zip). Their label row in the CSV will be incomplete; add the address in FluentCRM first.', '%d members have no complete mailing address (street, city and zip). Their label rows in the CSV will be incomplete; add the addresses in FluentCRM first.', $missing, 'my-iapsnj' ), $missing ) ) . '</p>';
        }
        if ( ! $rows ) {
            echo '<p class="fcrm-muted">' . esc_html__( 'No membership payments in this period.', 'my-iapsnj' ) . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach ( [ __( 'Name', 'my-iapsnj' ), __( 'Member #', 'my-iapsnj' ), __( 'Type', 'my-iapsnj' ), __( 'Paid', 'my-iapsnj' ), __( 'Method', 'my-iapsnj' ), __( 'Amount', 'my-iapsnj' ), __( 'Mailing address', 'my-iapsnj' ) ] as $head ) {
            echo '<th>' . esc_html( $head ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $rows, 0, self::SCREEN_LIMIT ) as $r ) {
            $address = trim( $r['street'] . ', ' . $r['city'] . ', ' . trim( $r['state'] . ' ' . $r['zip'] ), ' ,' );
            echo '<tr>'
                . '<td><a href="' . esc_url( $r['crm_url'] ) . '">' . esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) ?: $r['email'] ) . '</a></td>'
                . '<td>' . esc_html( $r['member_number'] !== '' ? $r['member_number'] : '—' ) . '</td>'
                . '<td>' . esc_html( $r['member_type'] ) . '</td>'
                . '<td><a href="' . esc_url( $r['order_url'] ) . '">' . esc_html( My_IAPSNJ_Dates::ymd_display( $r['paid_on'] ) ) . '</a></td>'
                . '<td>' . esc_html( $r['method'] ) . '</td>'
                . '<td>' . esc_html( html_entity_decode( $r['amount'], ENT_QUOTES, 'UTF-8' ) ) . '</td>'
                . '<td>' . ( $r['has_address'] ? esc_html( $address ) : '<span class="fcrm-badge fcrm-badge-warn">' . esc_html__( 'Incomplete', 'my-iapsnj' ) . '</span> ' . esc_html( $address ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        if ( count( $rows ) > self::SCREEN_LIMIT ) {
            echo '<p class="description">' . esc_html( sprintf( /* translators: 1: rows shown, 2: total rows */ __( 'Showing the first %1$d of %2$d. The CSV has every member.', 'my-iapsnj' ), self::SCREEN_LIMIT, count( $rows ) ) ) . '</p>';
        }
        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // CSV
    // -----------------------------------------------------------------------

    private static function export_url( int $days ): string {
        return add_query_arg(
            [
                'action' => 'my_iapsnj_freshly_paid_csv',
                'nonce'  => wp_create_nonce( 'my_iapsnj_nonce' ),
                'days'   => $days,
            ],
            admin_url( 'admin-ajax.php' )
        );
    }

    /**
     * freshlypaid-YYYYMMDDHHMM.csv (site time).
     */
    public static function csv_filename(): string {
        return 'freshlypaid-' . wp_date( 'YmdHi' ) . '.csv';
    }

    /**
     * @return string[]
     */
    public static function csv_header(): array {
        return [
            __( 'First Name', 'my-iapsnj' ),
            __( 'Last Name', 'my-iapsnj' ),
            __( 'Street Address', 'my-iapsnj' ),
            __( 'City', 'my-iapsnj' ),
            __( 'State', 'my-iapsnj' ),
            __( 'Zip', 'my-iapsnj' ),
        ];
    }

    /**
     * @param array<string,mixed> $row a rows() row
     * @return string[]
     */
    public static function csv_row( array $row ): array {
        return [ $row['first_name'], $row['last_name'], $row['street'], $row['city'], $row['state'], $row['zip'] ];
    }

    private static function handle_csv(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ), '', [ 'response' => 403 ] );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
        $days = self::normalize_window( isset( $_GET['days'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['days'] ) ) : '' );
        $rows = self::rows( $days );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . self::csv_filename() . '"' );
        header( 'X-Content-Type-Options: nosniff' );

        $fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( ! $fh ) {
            exit;
        }
        fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 byte order mark, so Excel reads accents
        fputcsv( $fh, self::csv_header(), ',', '"', '' );
        foreach ( $rows as $row ) {
            fputcsv( $fh, array_map( [ 'My_IAPSNJ_Members', 'csv_cell' ], self::csv_row( $row ) ), ',', '"', '' );
        }
        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }
}
