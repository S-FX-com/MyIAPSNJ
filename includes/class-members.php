<?php
/**
 * My_IAPSNJ_Members
 *
 * Members → Active Membership / Lapsed Members lists. Membership state is
 * the same rule as the daily expiry job (My_IAPSNJ_Schema::is_active_state):
 * Lifetime / Honorary are always active; anyone else is active while
 * paid_through plus the grace period is today or later. Contacts without a
 * member_type are not members. Read-only.
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Members {

    const STATE_ACTIVE = 'active';
    const STATE_LAPSED = 'lapsed';
    const PER_PAGE     = 50;

    /** @var array|null per-request cache of counts() */
    private static ?array $counts = null;

    public static function page_slug( string $state ): string {
        return $state === self::STATE_LAPSED ? 'my-iapsnj-lapsed' : 'my-iapsnj-members';
    }

    /**
     * First paid_through (Y-m-d) that still counts as active: today minus
     * the grace period (paid_through + grace >= today ⇔ paid_through >= today − grace).
     */
    public static function active_cutoff(): string {
        $today = My_IAPSNJ_Dates::today();
        $grace = max( 0, (int) ( My_IAPSNJ_Plugin::settings()['expiry_grace_days'] ?? 0 ) );
        return $grace > 0 ? gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - $grace * DAY_IN_SECONDS ) : $today;
    }

    /**
     * member_type / paid_through per contact (one row each).
     */
    private static function derived_sql(): string {
        global $wpdb;
        $meta = $wpdb->prefix . 'fc_subscriber_meta';
        return $wpdb->prepare(
            "SELECT subscriber_id, MAX(CASE WHEN `key` = %s THEN `value` END) AS member_type, MAX(CASE WHEN `key` = %s THEN LEFT(`value`, 10) END) AS paid_through FROM {$meta} WHERE object_type = 'custom_field' AND `key` IN (%s, %s) GROUP BY subscriber_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            My_IAPSNJ_Schema::FIELD_MEMBER_TYPE,
            My_IAPSNJ_Schema::FIELD_PAID_THROUGH,
            My_IAPSNJ_Schema::FIELD_MEMBER_TYPE,
            My_IAPSNJ_Schema::FIELD_PAID_THROUGH
        );
    }

    private static function active_sql(): string {
        global $wpdb;
        $comped = My_IAPSNJ_Schema::comped_types();
        return $wpdb->prepare(
            "(BINARY m.member_type IN (%s, %s) OR COALESCE(m.paid_through, '') >= %s)",
            (string) ( $comped[0] ?? '' ),
            (string) ( $comped[1] ?? '' ),
            self::active_cutoff()
        );
    }

    /**
     * Per member type: ['active' => n, 'lapsed' => n], plus '_total'.
     *
     * @return array<string,array{active:int,lapsed:int}>
     */
    public static function counts(): array {
        if ( self::$counts !== null ) {
            return self::$counts;
        }
        global $wpdb;
        $out   = [];
        $total = [ 'active' => 0, 'lapsed' => 0 ];
        try {
            $active = self::active_sql();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( "SELECT m.member_type AS type, SUM({$active}) AS active, SUM(NOT {$active}) AS lapsed FROM (" . self::derived_sql() . ") m WHERE COALESCE(m.member_type, '') <> '' GROUP BY m.member_type", ARRAY_A );
        } catch ( \Throwable $e ) {
            $rows = [];
        }
        $known = My_IAPSNJ_Schema::member_types();
        foreach ( $known as $type ) {
            $out[ $type ] = [ 'active' => 0, 'lapsed' => 0 ];
        }
        foreach ( (array) $rows as $r ) {
            $type = in_array( (string) $r['type'], $known, true ) ? (string) $r['type'] : 'Other';
            if ( ! isset( $out[ $type ] ) ) {
                $out[ $type ] = [ 'active' => 0, 'lapsed' => 0 ];
            }
            $out[ $type ]['active'] += (int) $r['active'];
            $out[ $type ]['lapsed'] += (int) $r['lapsed'];
            $total['active']        += (int) $r['active'];
            $total['lapsed']        += (int) $r['lapsed'];
        }
        $out['_total'] = $total;
        self::$counts  = $out;
        return $out;
    }

    /**
     * One page of the list.
     *
     * @param array $args type, s, paged
     * @return array{rows:array<int,array>,total:int}
     */
    public static function query( string $state, array $args = [] ): array {
        global $wpdb;
        $subs  = $wpdb->prefix . 'fc_subscribers';
        $where = "COALESCE(m.member_type, '') <> '' AND " . ( $state === self::STATE_LAPSED ? 'NOT ' : '' ) . self::active_sql();
        $type  = (string) ( $args['type'] ?? '' );
        if ( $type !== '' ) {
            $where .= $wpdb->prepare( ' AND m.member_type = %s', $type );
        }
        $s = trim( (string) ( $args['s'] ?? '' ) );
        if ( $s !== '' ) {
            $like   = '%' . $wpdb->esc_like( $s ) . '%';
            $where .= $wpdb->prepare( " AND (s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE %s)", $like, $like, $like, $like );
        }
        $paged  = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $offset = ( $paged - 1 ) * self::PER_PAGE;
        $order  = $state === self::STATE_LAPSED ? 'm.paid_through DESC, s.last_name ASC' : 's.last_name ASC, s.first_name ASC';
        $from   = '(' . self::derived_sql() . ") m INNER JOIN {$subs} s ON s.id = m.subscriber_id";
        try {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" );
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.first_name, s.last_name, s.email, s.user_id, m.member_type, m.paid_through FROM {$from} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d", self::PER_PAGE, $offset ), ARRAY_A );
            // phpcs:enable
        } catch ( \Throwable $e ) {
            return [ 'rows' => [], 'total' => 0 ];
        }
        return [ 'rows' => (array) $rows, 'total' => $total ];
    }

    /**
     * Type views, search and the table (inside the page wrap).
     */
    public static function render_list( string $state ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters
        $type  = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['type'] ) ) : '';
        $s     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        // phpcs:enable
        $slug   = self::page_slug( $state );
        $base   = admin_url( 'admin.php?page=' . $slug );
        $counts = self::counts();
        $result = self::query( $state, [ 'type' => $type, 's' => $s, 'paged' => $paged ] );
        $key    = $state === self::STATE_LAPSED ? 'lapsed' : 'active';

        echo '<div class="fcrm-section">';
        echo '<ul class="subsubsub">';
        $views = [ '' => [ __( 'All', 'my-iapsnj' ), (int) ( $counts['_total'][ $key ] ?? 0 ) ] ];
        foreach ( $counts as $t => $c ) {
            if ( $t !== '_total' && (int) $c[ $key ] > 0 ) {
                $views[ $t ] = [ $t, (int) $c[ $key ] ];
            }
        }
        $links = [];
        foreach ( $views as $t => [ $label, $n ] ) {
            $url     = $t === '' ? $base : add_query_arg( 'type', rawurlencode( (string) $t ), $base );
            $links[] = '<li><a href="' . esc_url( $url ) . '"' . ( (string) $t === $type ? ' class="current"' : '' ) . '>' . esc_html( (string) $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $n ) ) . ')</span></a></li>';
        }
        echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
        echo '</ul>';

        echo '<form method="get" style="float:right;margin:6px 0">';
        echo '<input type="hidden" name="page" value="' . esc_attr( $slug ) . '">';
        if ( $type !== '' ) {
            echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
        }
        echo '<input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'Name or email', 'my-iapsnj' ) . '"> ';
        echo '<button type="submit" class="button">' . esc_html__( 'Search', 'my-iapsnj' ) . '</button></form>';
        echo '<div style="clear:both"></div>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'my-iapsnj' ) . '</th><th>' . esc_html__( 'Email', 'my-iapsnj' ) . '</th><th>' . esc_html__( 'Member type', 'my-iapsnj' ) . '</th><th>' . esc_html__( 'Paid through', 'my-iapsnj' ) . '</th><th>' . esc_html__( 'WordPress user', 'my-iapsnj' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( ! $result['rows'] ) {
            echo '<tr><td colspan="5">' . esc_html__( 'No members found.', 'my-iapsnj' ) . '</td></tr>';
        }
        foreach ( $result['rows'] as $r ) {
            $name    = trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
            $crm_url = function_exists( 'fluentcrm_menu_url_base' )
                ? fluentcrm_menu_url_base( 'subscribers/' . (int) $r['id'] )
                : admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $r['id'] );
            $comped  = My_IAPSNJ_Schema::is_comped_type( (string) $r['member_type'] );
            $paid    = $comped ? __( 'No expiration', 'my-iapsnj' ) : ( (string) $r['paid_through'] !== '' ? My_IAPSNJ_Dates::ymd_display( (string) $r['paid_through'] ) : __( 'No date', 'my-iapsnj' ) );
            $uid     = (int) $r['user_id'];
            echo '<tr>';
            echo '<td><a href="' . esc_url( $crm_url ) . '">' . esc_html( $name !== '' ? $name : '#' . (int) $r['id'] ) . '</a></td>';
            echo '<td>' . esc_html( (string) $r['email'] ) . '</td>';
            echo '<td>' . esc_html( (string) $r['member_type'] ) . '</td>';
            echo '<td>' . esc_html( $paid ) . '</td>';
            echo '<td>' . ( $uid > 0 ? '<a href="' . esc_url( get_edit_user_link( $uid ) ) . '">' . esc_html__( 'Profile', 'my-iapsnj' ) . '</a>' : '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $pages = (int) ceil( $result['total'] / self::PER_PAGE );
        if ( $pages > 1 ) {
            echo '<p class="fcrm-pagination">';
            for ( $i = 1; $i <= $pages; $i++ ) {
                if ( $i === $paged ) {
                    echo '<strong>' . (int) $i . '</strong> ';
                    continue;
                }
                echo '<a href="' . esc_url( add_query_arg( array_filter( [ 'type' => $type, 's' => $s, 'paged' => $i ] ), $base ) ) . '">' . (int) $i . '</a> ';
            }
            echo '</p>';
        }
        echo '<p class="fcrm-muted">' . esc_html( sprintf( /* translators: %d: number of members */ _n( '%d member', '%d members', $result['total'], 'my-iapsnj' ), $result['total'] ) ) . '</p>';
        echo '</div>';
    }
}
