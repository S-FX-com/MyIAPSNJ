<?php
/**
 * [iapsnj_directory]: the members-only membership directory.
 *
 * Lists active members (the same rule as Members → Active Membership, through
 * My_IAPSNJ_Members' SQL) as cards: headshot (a placeholder when there is
 * none), name, member type, member since, department, rank, and email / phone
 * icons that open the mail or phone app. Filters: membership type (Regular,
 * Associate, Lifetime …), department, rank, join date range, retired. Filters are a plain GET form (works without scripts, and a
 * filtered page can be bookmarked or shared); results are paged.
 *
 * - Retired = a retirement date on the contact, or a Lifetime membership
 *   (Lifetime is for retired law enforcement only).
 * - Headshot = the photo the member uploaded on Edit Profile (the contact's
 *   avatar, stored by My_IAPSNJ_Headshot), else the user meta named by the
 *   shortcode's photo_meta (default "headshot"; an attachment id or an image
 *   URL), then the filter my_iapsnj/directory_photo_url, else a silhouette.
 * - Access: administrators and logged-in members whose own membership is
 *   active. Visitors and lapsed members see a message, never the list: the
 *   cards carry email addresses and phone numbers.
 *
 * Read only; nothing is written to the CRM.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

final class My_IAPSNJ_Directory {

    const SHORTCODE = 'iapsnj_directory';
    const HANDLE    = 'my-iapsnj-directory';

    /** Query-string keys (prefixed so they never meet WordPress's own). */
    const Q_TYPE    = 'mdir_type';
    const Q_DEPT    = 'mdir_dept';
    const Q_RANK    = 'mdir_rank';
    const Q_FROM    = 'mdir_from';
    const Q_TO      = 'mdir_to';
    const Q_RETIRED = 'mdir_retired';
    const Q_PAGE    = 'mdir_page';

    const PER_PAGE_DEFAULT = 24;
    const PER_PAGE_MAX     = 100;

    /** Custom fields the cards and filters read. */
    private static function key_department(): string { return My_IAPSNJ_Schema::FIELD_DEPARTMENT; }
    private static function key_rank(): string { return My_IAPSNJ_Schema::FIELD_RANK; }

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    public function register_assets(): void {
        wp_register_style( self::HANDLE, MY_IAPSNJ_URL . 'public/css/directory.css', [], MY_IAPSNJ_VERSION );
        $post = get_post();
        if ( $post instanceof WP_Post && is_singular() && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
            wp_enqueue_style( self::HANDLE );
        }
    }

    // -----------------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------------

    /** Administrators, and members whose own membership is active. */
    private static function viewer_allowed(): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        try {
            $contact = My_IAPSNJ_Engine::find_linked_subscriber( get_current_user_id() );
            if ( ! $contact instanceof Subscriber ) {
                return false;
            }
            return My_IAPSNJ_Schema::is_active_state(
                My_IAPSNJ_Schema::field( $contact, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ),
                My_IAPSNJ_Schema::field( $contact, My_IAPSNJ_Schema::FIELD_PAID_THROUGH )
            );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Query
    // -----------------------------------------------------------------------

    /** SQL: the contact has this custom field with this exact value. */
    private static function has_value_sql( string $slug, string $value ): string {
        global $wpdb;
        $t = My_IAPSNJ_Members::tables();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->prepare(
            "EXISTS (SELECT 1 FROM {$t['meta']} f WHERE f.subscriber_id = s.id AND f.object_type = 'custom_field' AND f.`key` = %s AND f.`value` = %s)",
            $slug,
            $value
        );
    }

    /**
     * WHERE for the active members matching the filters.
     *
     * @param array{type:string,dept:string,rank:string,from:string,to:string,retired:string} $f
     */
    private static function where_sql( array $f ): string {
        global $wpdb;
        $t     = My_IAPSNJ_Members::tables();
        $where = [ My_IAPSNJ_Members::is_member_sql(), My_IAPSNJ_Members::active_sql() ];

        if ( $f['type'] !== '' ) {
            $where[] = $wpdb->prepare( 'BINARY m.member_type = %s', $f['type'] );
        }

        if ( $f['dept'] !== '' ) {
            // The department is the primary company; the custom field is the fallback.
            $dept = self::has_value_sql( self::key_department(), $f['dept'] );
            if ( My_IAPSNJ_Schema::company_enabled() ) {
                $dept = '(' . $dept . ' OR ' . $wpdb->prepare( 'co.name = %s', $f['dept'] ) . ')';
            }
            $where[] = $dept;
        }
        if ( $f['rank'] !== '' ) {
            $where[] = self::has_value_sql( self::key_rank(), $f['rank'] );
        }
        if ( $f['from'] !== '' || $f['to'] !== '' ) {
            $range = [ $wpdb->prepare( 'f.`key` = %s', My_IAPSNJ_Schema::FIELD_JOIN_DATE ), $wpdb->prepare( 'f.`value` REGEXP %s', My_IAPSNJ_Members::YMD_REGEX ) ];
            if ( $f['from'] !== '' ) {
                $range[] = $wpdb->prepare( 'f.`value` >= %s', $f['from'] );
            }
            if ( $f['to'] !== '' ) {
                $range[] = $wpdb->prepare( 'f.`value` <= %s', $f['to'] );
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $where[] = "EXISTS (SELECT 1 FROM {$t['meta']} f WHERE f.subscriber_id = s.id AND f.object_type = 'custom_field' AND " . implode( ' AND ', $range ) . ')';
        }
        if ( $f['retired'] === 'yes' || $f['retired'] === 'no' ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $retired = '(' . $wpdb->prepare( 'BINARY m.member_type = %s', My_IAPSNJ_Schema::TYPE_LIFETIME )
                . " OR EXISTS (SELECT 1 FROM {$t['meta']} f WHERE f.subscriber_id = s.id AND f.object_type = 'custom_field' AND "
                . $wpdb->prepare( 'f.`key` = %s', My_IAPSNJ_Schema::FIELD_RETIREMENT_DATE )
                . " AND TRIM(COALESCE(f.`value`, '')) <> ''))";
            $where[] = $f['retired'] === 'yes' ? $retired : "NOT {$retired}";
        }
        return implode( ' AND ', $where );
    }

    /**
     * One page of members and the total.
     *
     * @param array<string,string> $f filters (see where_sql())
     * @return array{total:int,rows:array<int,array<string,mixed>>}
     */
    public static function query( array $f, int $per_page, int $paged ): array {
        global $wpdb;
        $t       = My_IAPSNJ_Members::tables();
        $derived = My_IAPSNJ_Members::derived_sql();
        $where   = self::where_sql( $f );
        $from    = "({$derived}) m INNER JOIN {$t['subs']} s ON s.id = m.subscriber_id";
        $company = My_IAPSNJ_Schema::company_enabled();
        if ( $company ) {
            $from .= " LEFT JOIN {$wpdb->prefix}fc_companies co ON co.id = s.company_id";
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" );
        if ( $total < 1 ) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        $pages  = max( 1, (int) ceil( $total / $per_page ) );
        $paged  = max( 1, min( $paged, $pages ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
        $raw = (array) $wpdb->get_results(
            "SELECT s.id, s.first_name, s.last_name, s.email, s.phone, s.avatar, s.user_id, m.member_type, " . ( $company ? 'co.name' : 'NULL' ) . " AS company
             FROM {$from} WHERE {$where}
             ORDER BY s.last_name ASC, s.first_name ASC, s.id ASC "
            . $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, ( $paged - 1 ) * $per_page ),
            ARRAY_A
        );

        $ids    = array_map( 'intval', array_column( $raw, 'id' ) );
        $fields = self::lookup_fields( $ids );
        $rows   = [];
        foreach ( $raw as $r ) {
            $id     = (int) $r['id'];
            $extra  = $fields[ $id ] ?? [];
            $rows[] = [
                'id'          => $id,
                'name'        => trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] ),
                'email'       => (string) $r['email'],
                'phone'       => (string) $r['phone'],
                'avatar'      => (string) $r['avatar'],
                'user_id'     => (int) $r['user_id'],
                'member_type' => (string) $r['member_type'],
                'department'  => trim( (string) $r['company'] ) !== '' ? trim( (string) $r['company'] ) : (string) ( $extra[ self::key_department() ] ?? '' ),
                'rank'        => (string) ( $extra[ self::key_rank() ] ?? '' ),
                'join_date'   => My_IAPSNJ_Dates::ymd( $extra[ My_IAPSNJ_Schema::FIELD_JOIN_DATE ] ?? '' ),
            ];
        }
        return [ 'total' => $total, 'rows' => $rows ];
    }

    /**
     * department / rank / join date of the contacts on the page.
     *
     * @param int[] $ids
     * @return array<int,array<string,string>>
     */
    private static function lookup_fields( array $ids ): array {
        global $wpdb;
        if ( ! $ids ) {
            return [];
        }
        $t    = My_IAPSNJ_Members::tables();
        $keys = [ self::key_department(), self::key_rank(), My_IAPSNJ_Schema::FIELD_JOIN_DATE ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholders only
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT subscriber_id, `key`, `value` FROM {$t['meta']}
             WHERE object_type = 'custom_field' AND `key` IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')
               AND subscriber_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
            array_merge( $keys, $ids )
        ), ARRAY_A );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r['subscriber_id'] ][ (string) $r['key'] ] = trim( (string) $r['value'] );
        }
        return $out;
    }

    /**
     * Values a filter dropdown offers: the distinct values among today's
     * active members, so no choice ever returns an empty list. Cached briefly.
     *
     * @return string[]
     */
    private static function filter_options( string $slug ): array {
        global $wpdb;
        $cache = 'my_iapsnj_dir_' . md5( $slug . '|' . My_IAPSNJ_Dates::today() );
        $hit   = get_transient( $cache );
        if ( is_array( $hit ) ) {
            return $hit;
        }
        $t = My_IAPSNJ_Members::tables();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
        $rows = (array) $wpdb->get_col(
            "SELECT DISTINCT f.`value`
             FROM {$t['meta']} f
             INNER JOIN (" . My_IAPSNJ_Members::derived_sql() . ") m ON m.subscriber_id = f.subscriber_id
             WHERE f.object_type = 'custom_field' AND " . $wpdb->prepare( 'f.`key` = %s', $slug ) . "
               AND TRIM(COALESCE(f.`value`, '')) <> ''
               AND " . My_IAPSNJ_Members::is_member_sql() . ' AND ' . My_IAPSNJ_Members::active_sql() . '
             ORDER BY f.`value` ASC'
        );
        if ( $slug === self::key_department() && My_IAPSNJ_Schema::company_enabled() ) {
            // Departments held as companies by the active members too.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
            $rows = array_merge( $rows, (array) $wpdb->get_col(
                "SELECT DISTINCT co.name
                 FROM {$wpdb->prefix}fc_companies co
                 INNER JOIN {$t['subs']} s ON s.company_id = co.id
                 INNER JOIN (" . My_IAPSNJ_Members::derived_sql() . ") m ON m.subscriber_id = s.id
                 WHERE TRIM(COALESCE(co.name, '')) <> '' AND " . My_IAPSNJ_Members::is_member_sql() . ' AND ' . My_IAPSNJ_Members::active_sql()
            ) );
        }
        $out = array_values( array_unique( array_map( 'trim', array_map( 'strval', $rows ) ) ) );
        natcasesort( $out );
        $out = array_values( $out );
        set_transient( $cache, $out, 15 * MINUTE_IN_SECONDS );
        return $out;
    }

    /**
     * Membership types the filter offers: those of today's active members, in
     * the order Regular, Associate, Lifetime, Honorary (so Honorary appears
     * only while an active Honorary member exists, and no choice is empty).
     *
     * @return string[]
     */
    private static function type_options(): array {
        $present = self::filter_options( My_IAPSNJ_Schema::FIELD_MEMBER_TYPE );
        return array_values( array_filter( My_IAPSNJ_Schema::member_types(), function ( $type ) use ( $present ) {
            return in_array( $type, $present, true );
        } ) );
    }

    // -----------------------------------------------------------------------
    // Headshot
    // -----------------------------------------------------------------------

    /** The member's headshot URL: their uploaded photo, else the user meta, else ''. */
    private static function photo_url( array $row, string $meta_key ): string {
        // The photo the member uploaded on Edit Profile (the contact's avatar),
        // never a Gravatar or another site's picture FluentCRM may hold there.
        $url = My_IAPSNJ_Headshot::is_ours( $row['avatar'] ) ? $row['avatar'] : '';
        if ( $url === '' && $row['user_id'] > 0 && $meta_key !== '' ) {
            $v = get_user_meta( $row['user_id'], $meta_key, true );
            if ( is_array( $v ) ) {
                $v = $v['ID'] ?? ( $v['url'] ?? '' ); // an ACF image array
            }
            if ( is_numeric( $v ) && (int) $v > 0 ) {
                $url = (string) wp_get_attachment_image_url( (int) $v, 'thumbnail' );
            } elseif ( is_string( $v ) && preg_match( '#^https?://#i', $v ) ) {
                $url = $v;
            }
        }
        /**
         * The headshot of a directory member ('' = show the placeholder).
         *
         * @param string $url     address found from the user meta
         * @param int    $user_id WordPress user id (0 when the contact has no account)
         * @param string $email   the contact's email
         */
        return (string) apply_filters( 'my_iapsnj/directory_photo_url', $url, $row['user_id'], $row['email'] );
    }

    // -----------------------------------------------------------------------
    // Shortcode
    // -----------------------------------------------------------------------

    /** @return array<string,string> the filters from the query string */
    private static function request_filters(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only filter form (GET)
        $get = static function ( string $key ): string {
            return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
        };
        // phpcs:enable
        $from = My_IAPSNJ_Dates::ymd( $get( self::Q_FROM ) );
        $to   = My_IAPSNJ_Dates::ymd( $get( self::Q_TO ) );
        if ( $from !== '' && $to !== '' && strcmp( $from, $to ) > 0 ) {
            [ $from, $to ] = [ $to, $from ];
        }
        $retired = strtolower( $get( self::Q_RETIRED ) );
        $type    = $get( self::Q_TYPE );
        return [
            'type'    => in_array( $type, My_IAPSNJ_Schema::member_types(), true ) ? $type : '',
            'dept'    => $get( self::Q_DEPT ),
            'rank'    => $get( self::Q_RANK ),
            'from'    => $from,
            'to'      => $to,
            'retired' => in_array( $retired, [ 'yes', 'no' ], true ) ? $retired : '',
        ];
    }

    /**
     * Values for add_query_arg(), which does not encode them: "Smith & Wesson"
     * or "Dept #2" would otherwise cut the address short.
     *
     * @param array<string,string> $args
     * @return array<string,string>
     */
    private static function encode_args( array $args ): array {
        return array_map( 'rawurlencode', $args );
    }

    private static function icon_mail(): string {
        return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V8l8 5 8-5v10zm-8-7L4 6h16l-8 5z"/></svg>';
    }

    private static function icon_phone(): string {
        return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>';
    }

    /**
     * [iapsnj_directory per_page="24" photo_meta="headshot"]
     *
     * @param array|string $atts
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'per_page'   => (string) self::PER_PAGE_DEFAULT,
            'photo_meta' => 'headshot',
        ], (array) $atts, self::SHORTCODE );

        if ( ! is_user_logged_in() ) {
            $login = wp_login_url( (string) get_permalink() );
            return '<p class="my-iapsnj-dir-notice">'
                . sprintf(
                    /* translators: %s: link to the login page */
                    esc_html__( 'Please %s to see the membership directory.', 'my-iapsnj' ),
                    '<a href="' . esc_url( $login ) . '">' . esc_html__( 'log in', 'my-iapsnj' ) . '</a>'
                ) . '</p>';
        }
        if ( ! self::viewer_allowed() ) {
            return '<p class="my-iapsnj-dir-notice">' . esc_html__( 'The membership directory is available to members with an active membership.', 'my-iapsnj' ) . '</p>';
        }

        wp_enqueue_style( self::HANDLE );

        $per_page = max( 1, min( self::PER_PAGE_MAX, (int) $atts['per_page'] ) );
        $filters  = self::request_filters();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging (GET)
        $paged    = isset( $_GET[ self::Q_PAGE ] ) ? max( 1, (int) $_GET[ self::Q_PAGE ] ) : 1;
        $result   = self::query( $filters, $per_page, $paged );
        $pages    = max( 1, (int) ceil( $result['total'] / $per_page ) );
        $paged    = min( $paged, $pages );
        $meta_key = sanitize_key( (string) $atts['photo_meta'] );

        $types = self::type_options();
        $depts = self::filter_options( self::key_department() );
        $ranks = self::filter_options( self::key_rank() );

        // The page address without our own keys: the form and the paging add them back.
        $base = remove_query_arg( [ self::Q_TYPE, self::Q_DEPT, self::Q_RANK, self::Q_FROM, self::Q_TO, self::Q_RETIRED, self::Q_PAGE ] );
        $keep = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only
        foreach ( (array) $_GET as $k => $v ) {
            if ( is_scalar( $v ) && strpos( (string) $k, 'mdir_' ) !== 0 ) {
                $keep[ sanitize_key( (string) $k ) ] = sanitize_text_field( wp_unslash( (string) $v ) ); // e.g. page_id on plain permalinks
            }
        }
        $active_filters = array_filter( $filters, 'strlen' );

        ob_start();
        ?>
        <div class="my-iapsnj-dir">
            <form class="my-iapsnj-dir-filters" method="get" action="<?php echo esc_url( $base ); ?>">
                <?php foreach ( $keep as $k => $v ) : ?>
                    <input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
                <?php endforeach; ?>
                <div class="my-iapsnj-dir-field my-iapsnj-dir-field--wide">
                    <label for="mdir-type"><?php esc_html_e( 'Membership type', 'my-iapsnj' ); ?></label>
                    <select id="mdir-type" name="<?php echo esc_attr( self::Q_TYPE ); ?>">
                        <option value=""><?php esc_html_e( 'All types', 'my-iapsnj' ); ?></option>
                        <?php foreach ( $types as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>"<?php selected( $filters['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="my-iapsnj-dir-field my-iapsnj-dir-field--wide">
                    <label for="mdir-dept"><?php esc_html_e( 'Department', 'my-iapsnj' ); ?></label>
                    <select id="mdir-dept" name="<?php echo esc_attr( self::Q_DEPT ); ?>">
                        <option value=""><?php esc_html_e( 'All departments', 'my-iapsnj' ); ?></option>
                        <?php foreach ( $depts as $d ) : ?>
                            <option value="<?php echo esc_attr( $d ); ?>"<?php selected( $filters['dept'], $d ); ?>><?php echo esc_html( $d ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="my-iapsnj-dir-field my-iapsnj-dir-field--wide">
                    <label for="mdir-rank"><?php esc_html_e( 'Rank', 'my-iapsnj' ); ?></label>
                    <select id="mdir-rank" name="<?php echo esc_attr( self::Q_RANK ); ?>">
                        <option value=""><?php esc_html_e( 'All ranks', 'my-iapsnj' ); ?></option>
                        <?php foreach ( $ranks as $r ) : ?>
                            <option value="<?php echo esc_attr( $r ); ?>"<?php selected( $filters['rank'], $r ); ?>><?php echo esc_html( $r ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <fieldset class="my-iapsnj-dir-field my-iapsnj-dir-field--range">
                    <legend><?php esc_html_e( 'Joined between', 'my-iapsnj' ); ?></legend>
                    <div class="my-iapsnj-dir-range">
                        <input type="date" id="mdir-from" name="<?php echo esc_attr( self::Q_FROM ); ?>" value="<?php echo esc_attr( $filters['from'] ); ?>" aria-label="<?php esc_attr_e( 'Joined from', 'my-iapsnj' ); ?>">
                        <input type="date" id="mdir-to" name="<?php echo esc_attr( self::Q_TO ); ?>" value="<?php echo esc_attr( $filters['to'] ); ?>" aria-label="<?php esc_attr_e( 'Joined to', 'my-iapsnj' ); ?>">
                    </div>
                </fieldset>
                <div class="my-iapsnj-dir-field my-iapsnj-dir-field--retired">
                    <label for="mdir-retired"><?php esc_html_e( 'Retired', 'my-iapsnj' ); ?></label>
                    <select id="mdir-retired" name="<?php echo esc_attr( self::Q_RETIRED ); ?>">
                        <option value=""><?php esc_html_e( 'Any', 'my-iapsnj' ); ?></option>
                        <option value="yes"<?php selected( $filters['retired'], 'yes' ); ?>><?php esc_html_e( 'Yes', 'my-iapsnj' ); ?></option>
                        <option value="no"<?php selected( $filters['retired'], 'no' ); ?>><?php esc_html_e( 'No', 'my-iapsnj' ); ?></option>
                    </select>
                </div>
                <div class="my-iapsnj-dir-actions">
                    <button type="submit" class="my-iapsnj-dir-submit"><?php esc_html_e( 'Filter', 'my-iapsnj' ); ?></button>
                    <?php if ( $active_filters ) : ?>
                        <a class="my-iapsnj-dir-reset" href="<?php echo esc_url( add_query_arg( self::encode_args( $keep ), $base ) ); ?>"><?php esc_html_e( 'Reset', 'my-iapsnj' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <p class="my-iapsnj-dir-count" aria-live="polite">
                <?php
                echo esc_html( sprintf(
                    /* translators: %s: number of members */
                    _n( '%s member', '%s members', $result['total'], 'my-iapsnj' ),
                    number_format_i18n( $result['total'] )
                ) );
                ?>
            </p>

            <?php if ( ! $result['rows'] ) : ?>
                <p class="my-iapsnj-dir-notice"><?php esc_html_e( 'No members match these filters.', 'my-iapsnj' ); ?></p>
            <?php else : ?>
                <ul class="my-iapsnj-dir-grid">
                    <?php foreach ( $result['rows'] as $m ) : ?>
                        <?php
                        $photo  = self::photo_url( $m, $meta_key );
                        $phone  = My_IAPSNJ_Phone::e164( $m['phone'] );
                        $detail = array_filter( [ $m['department'], $m['rank'] ], 'strlen' );
                        ?>
                        <li class="my-iapsnj-dir-card">
                            <span class="my-iapsnj-dir-photo">
                                <span class="my-iapsnj-dir-placeholder" role="img" aria-label="<?php esc_attr_e( 'No photo', 'my-iapsnj' ); ?>"><?php echo My_IAPSNJ_Headshot::placeholder_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
                                <?php if ( $photo !== '' ) : ?>
                                    <?php // On top of the silhouette; a photo that fails to load hides itself and the silhouette shows. ?>
                                    <img src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $m['name'] ); ?>" width="80" height="80" loading="lazy" onerror="this.style.display='none'">
                                <?php endif; ?>
                            </span>
                            <div class="my-iapsnj-dir-body">
                                <h3 class="my-iapsnj-dir-name"><?php echo esc_html( $m['name'] ); ?></h3>
                                <?php if ( $m['member_type'] !== '' ) : ?>
                                    <span class="my-iapsnj-dir-type"><?php echo esc_html( $m['member_type'] ); ?></span>
                                <?php endif; ?>
                                <?php if ( $detail ) : ?>
                                    <p class="my-iapsnj-dir-detail"><?php echo esc_html( implode( ' · ', $detail ) ); ?></p>
                                <?php endif; ?>
                                <?php if ( $m['join_date'] !== '' ) : ?>
                                    <p class="my-iapsnj-dir-since"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Member since %s', 'my-iapsnj' ), My_IAPSNJ_Dates::ymd_display( $m['join_date'] ) ) ); ?></p>
                                <?php endif; ?>
                                <p class="my-iapsnj-dir-contact">
                                    <?php if ( is_email( $m['email'] ) ) : ?>
                                        <a class="my-iapsnj-dir-icon" href="<?php echo esc_url( 'mailto:' . $m['email'] ); ?>" title="<?php echo esc_attr( $m['email'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member name */ __( 'Email %s', 'my-iapsnj' ), $m['name'] ) ); ?>"><?php echo self::icon_mail(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></a>
                                    <?php endif; ?>
                                    <?php if ( $phone !== '' && My_IAPSNJ_Phone::is_valid( $phone ) ) : ?>
                                        <a class="my-iapsnj-dir-icon" href="<?php echo esc_url( 'tel:' . $phone ); ?>" title="<?php echo esc_attr( My_IAPSNJ_Phone::display( $phone ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member name */ __( 'Call %s', 'my-iapsnj' ), $m['name'] ) ); ?>"><?php echo self::icon_phone(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></a>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php
                if ( $pages > 1 ) {
                    $args = [];
                    foreach ( [ self::Q_TYPE => 'type', self::Q_DEPT => 'dept', self::Q_RANK => 'rank', self::Q_FROM => 'from', self::Q_TO => 'to', self::Q_RETIRED => 'retired' ] as $q => $k ) {
                        if ( $filters[ $k ] !== '' ) {
                            $args[ $q ] = $filters[ $k ];
                        }
                    }
                    $links = paginate_links( [
                        'base'      => esc_url_raw( add_query_arg( array_merge( self::encode_args( $keep ), self::encode_args( $args ), [ self::Q_PAGE => '%#%' ] ), $base ) ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $pages,
                        'type'      => 'list',
                        'prev_text' => esc_html__( '‹ Previous', 'my-iapsnj' ),
                        'next_text' => esc_html__( 'Next ›', 'my-iapsnj' ),
                    ] );
                    echo '<nav class="my-iapsnj-dir-pages" aria-label="' . esc_attr__( 'Directory pages', 'my-iapsnj' ) . '">' . wp_kses_post( (string) $links ) . '</nav>';
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
