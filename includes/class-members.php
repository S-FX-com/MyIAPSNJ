<?php
/**
 * My_IAPSNJ_Members
 *
 * The "Members → Active Membership" and "Members → Lapsed Members" lists:
 * a read-only view of FluentCRM contacts that have a member_type, split by
 * the same rule the daily expiry job uses (My_IAPSNJ_Schema::is_active_state):
 *
 *   active = member_type is Lifetime / Honorary, OR paid_through (Y-m-d) is
 *            on or after My_IAPSNJ_Schema::active_cutoff() (today − grace)
 *   lapsed = member_type set AND not active (includes a missing paid_through)
 *
 * The Member-Active tag is only a lagging copy of that state, so the lists
 * never use it to decide state; they flag it when it disagrees.
 *
 * Query shape (see docs, measured on 12k contacts): one derived "pivot" of
 * the two state keys from fc_subscriber_meta, joined to fc_subscribers, is
 * filtered, counted, sorted and paged in SQL; the other columns are loaded
 * for the rows of the current page only with a few IN(...) lookups. Every
 * value goes through $wpdb->prepare(); SQL identifiers and ORDER BY come
 * from whitelists only.
 *
 * A paid_through that is not a canonical YYYY-MM-DD string is counted as
 * lapsed here while PHP's lenient My_IAPSNJ_Dates::ymd() may parse it; such
 * rows are reported by the "bad_date" data check rather than silently
 * disagreeing with the expiry job.
 *
 * Nothing here writes to the CRM (CLAUDE.md: the only CRM writers are the
 * membership, checkout and migration code).
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Members {

    const STATE_ACTIVE = 'active';
    const STATE_LAPSED = 'lapsed';

    /** Views key for member_type values outside My_IAPSNJ_Schema::member_types(). */
    const TYPE_OTHER = 'Other';

    /** Data checks shown on the Lapsed page (GET check=…). */
    const CHECK_BAD_DATE      = 'bad_date';
    const CHECK_TAG_NO_TYPE   = 'tag_no_type';
    const CHECK_LAPSED_TAGGED = 'lapsed_tagged';
    const CHECK_COMPED_TAG    = 'comped_tag_mismatch';

    /** FluentCRM pivot object_type for tags. */
    const TAG_OBJECT_TYPE = 'FluentCrm\App\Models\Tag';

    /** Canonical, calendar-plausible YYYY-MM-DD. */
    const YMD_REGEX = '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$';

    const PER_PAGE_CHOICES = [ 25, 50, 100, 200 ];
    const PER_PAGE_DEFAULT = 50;
    const CSV_CHUNK        = 500;

    /** FluentCart subscription statuses that still bill. */
    const SUBSCRIPTION_LIVE = [ 'active', 'trialing', 'past_due', 'failing' ];

    /** fc_subscribers.status values worth flagging. */
    const CRM_STATUS_FLAGS = [ 'unsubscribed', 'bounced', 'complained', 'spammed' ];

    /** @var array<string,array> counts() per cutoff, per request */
    private static array $counts_cache = [];

    /** @var array<string,int>|null tag slug → id, per request */
    private static ?array $tag_map = null;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Admin page slug of a list.
     */
    public static function page_slug( string $state ): string {
        return self::norm_state( $state ) === self::STATE_LAPSED ? 'my-iapsnj-lapsed' : 'my-iapsnj-members';
    }

    /**
     * Active / lapsed / bad-date counts per member type (one GROUP BY,
     * memoised per request):
     *
     *   [ 'Regular' => [ 'active' => int, 'lapsed' => int, 'bad_date' => int ], …,
     *     'Other' => […] (only when unknown member_type values exist),
     *     '_total' => [ 'active' => int, 'lapsed' => int, 'bad_date' => int ] ]
     *
     * Only types that occur are listed. bad_date counts non-comped members
     * whose paid_through is set but not YYYY-MM-DD (they are in 'lapsed').
     *
     * @return array<string,array{active:int,lapsed:int,bad_date:int}>
     */
    public static function counts(): array {
        $cutoff = My_IAPSNJ_Schema::active_cutoff();
        if ( isset( self::$counts_cache[ $cutoff ] ) ) {
            return self::$counts_cache[ $cutoff ];
        }
        global $wpdb;
        $t      = self::tables();
        $active = self::active_sql();
        $bad    = self::bad_date_sql();
        $member = self::is_member_sql();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
        $rows = $wpdb->get_results(
            "SELECT BINARY m.member_type AS t,
                    SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) AS a,
                    SUM(CASE WHEN {$active} THEN 0 ELSE 1 END) AS l,
                    SUM(CASE WHEN {$bad} THEN 1 ELSE 0 END) AS b
             FROM (" . self::derived_sql() . ") m
             INNER JOIN {$t['subs']} s ON s.id = m.subscriber_id
             WHERE {$member}
             GROUP BY BINARY m.member_type",
            ARRAY_A
        );
        $out   = [];
        $total = [ 'active' => 0, 'lapsed' => 0, 'bad_date' => 0 ];
        foreach ( (array) $rows as $r ) {
            $type = (string) $r['t'];
            $key  = in_array( $type, My_IAPSNJ_Schema::member_types(), true ) ? $type : self::TYPE_OTHER;
            if ( ! isset( $out[ $key ] ) ) {
                $out[ $key ] = [ 'active' => 0, 'lapsed' => 0, 'bad_date' => 0 ];
            }
            $out[ $key ]['active']   += (int) $r['a'];
            $out[ $key ]['lapsed']   += (int) $r['l'];
            $out[ $key ]['bad_date'] += (int) $r['b'];
            $total['active']   += (int) $r['a'];
            $total['lapsed']   += (int) $r['l'];
            $total['bad_date'] += (int) $r['b'];
        }
        // Stable order: the schema's types first, then Other.
        $sorted = [];
        foreach ( array_merge( My_IAPSNJ_Schema::member_types(), [ self::TYPE_OTHER ] ) as $key ) {
            if ( isset( $out[ $key ] ) ) {
                $sorted[ $key ] = $out[ $key ];
            }
        }
        $sorted['_total'] = $total;
        self::$counts_cache[ $cutoff ] = $sorted;
        return $sorted;
    }

    /**
     * One page of a list.
     *
     * $args (all optional, unknown values fall back to defaults):
     *  - type     Regular | Associate | Lifetime | Honorary | Other
     *  - s        search: name / email (LIKE) or exact member_number
     *  - orderby  name | paid_through | member_type
     *  - order    asc | desc
     *  - paged    ≥ 1 (clamped to the last page)
     *  - per_page 25 | 50 | 100 | 200 (default 50)
     *  - active:  due=1 (not yet paid through Dec 31 next year),
     *             within=N (lapses within N days), comped=1 | 0
     *  - lapsed:  year=YYYY (paid_through year) | none (no date),
     *             since=N (lapsed within the last N days)
     *  - check    bad_date | tag_no_type | lapsed_tagged | comped_tag_mismatch
     *             (replaces the state rule with that data check)
     *
     * Each row: id, first_name, last_name, email, phone, user_id (0 unless
     * the WordPress user exists), crm_status, member_type, paid_through,
     * state ('active' | 'lapsed' | '' when not a member), lapsed_on (Y-m-d),
     * member_number, department, join_date, last_paid_year, has_active_tag,
     * pending_check, last_order (null | id, date, amount, method, url),
     * subscription (null | status, next_billing_date).
     *
     * @return array{rows: array<int,array>, total: int}
     */
    public static function query( string $state, array $args ): array {
        $state = self::norm_state( $state );
        $a     = self::normalize_args( $state, $args );
        $total = self::count_rows( $state, $a );
        $pages = max( 1, (int) ceil( $total / $a['per_page'] ) );
        $paged = min( $a['paged'], $pages );
        $rows  = $total > 0 ? self::fetch( $state, $a, $a['per_page'], ( $paged - 1 ) * $a['per_page'], false ) : [];
        return [ 'rows' => $rows, 'total' => $total ];
    }

    /**
     * Page body below the title: data checks (Lapsed), type views, filters,
     * search, the list with sortable columns and paging, Export CSV.
     */
    public static function render_list( string $state ): void {
        $state = self::norm_state( $state );
        $a     = self::normalize_args( $state, self::request_args() );

        if ( $state === self::STATE_LAPSED ) {
            self::render_checks( $a );
        }
        if ( $a['check'] !== '' ) {
            $labels = self::check_labels();
            echo '<p class="description">';
            printf(
                /* translators: %s: name of a data check */
                esc_html__( 'Showing the data check: %s', 'my-iapsnj' ),
                '<strong>' . esc_html( $labels[ $a['check'] ] ) . '</strong>'
            );
            echo ' &middot; <a href="' . esc_url( self::url( $state, [] ) ) . '">' . esc_html__( 'Back to the full list', 'my-iapsnj' ) . '</a></p>';
        }

        $table = new My_IAPSNJ_Members_List_Table(
            $state,
            $a,
            [
                'counts'     => self::counts(),
                'years'      => $state === self::STATE_LAPSED ? self::lapsed_years() : [],
                'export_url' => self::export_url( $state, $a ),
                'base_url'   => self::url( $state, [] ),
                'view_urls'  => self::view_urls( $state, $a ),
                'next_year'  => (int) substr( My_IAPSNJ_Dates::today(), 0, 4 ) + 1,
            ]
        );
        $table->prepare_items();

        $table->views();
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="my-iapsnj-members-form">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::page_slug( $state ) ) . '" />';
        foreach ( [ 'type', 'check', 'orderby', 'order' ] as $key ) {
            if ( $a[ $key ] !== '' ) {
                echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $a[ $key ] ) . '" />';
            }
        }
        $table->search_box( __( 'Search members', 'my-iapsnj' ), 'my-iapsnj-member-search' );
        $table->display();
        echo '</form>';
    }

    /**
     * Hooks: the streamed CSV export (admin-ajax, GET, nonce 'my_iapsnj_nonce').
     */
    public static function register_hooks(): void {
        add_action( 'wp_ajax_my_iapsnj_members_csv', static function () {
            self::handle_csv();
        } );
    }

    // -----------------------------------------------------------------------
    // Arguments
    // -----------------------------------------------------------------------

    private static function norm_state( string $state ): string {
        return $state === self::STATE_LAPSED ? self::STATE_LAPSED : self::STATE_ACTIVE;
    }

    /**
     * Raw list parameters from the query string.
     *
     * @return array<string,string>
     */
    private static function request_args(): array {
        $out = [];
        foreach ( [ 'type', 's', 'orderby', 'order', 'paged', 'per_page', 'due', 'within', 'comped', 'year', 'since', 'check' ] as $key ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters
            if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
                $out[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
        }
        return $out;
    }

    /**
     * Validate every argument against its whitelist.
     *
     * @return array{type:string,s:string,orderby:string,order:string,paged:int,per_page:int,due:string,within:int,comped:string,year:string,since:int,check:string}
     */
    private static function normalize_args( string $state, array $args ): array {
        $get = static function ( string $key ) use ( $args ): string {
            return isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ? trim( (string) $args[ $key ] ) : '';
        };

        $type = $get( 'type' );
        if ( ! in_array( $type, array_merge( My_IAPSNJ_Schema::member_types(), [ self::TYPE_OTHER ] ), true ) ) {
            $type = '';
        }
        $orderby = $get( 'orderby' );
        if ( ! in_array( $orderby, [ 'name', 'paid_through', 'member_type' ], true ) ) {
            $orderby = '';
        }
        $order = strtolower( $get( 'order' ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) {
            $order = '';
        }
        $per_page = (int) $get( 'per_page' );
        if ( ! in_array( $per_page, self::PER_PAGE_CHOICES, true ) ) {
            $per_page = self::PER_PAGE_DEFAULT;
        }
        $check = $get( 'check' );
        if ( ! isset( self::check_labels()[ $check ] ) ) {
            $check = '';
        }
        $a = [
            'type'     => $type,
            's'        => mb_substr( $get( 's' ), 0, 100 ),
            'orderby'  => $orderby,
            'order'    => $order,
            'paged'    => max( 1, (int) $get( 'paged' ) ),
            'per_page' => $per_page,
            'due'      => '',
            'within'   => 0,
            'comped'   => '',
            'year'     => '',
            'since'    => 0,
            'check'    => $check,
        ];
        if ( $state === self::STATE_ACTIVE ) {
            $a['due']    = $get( 'due' ) === '1' ? '1' : '';
            $a['within'] = min( 3660, max( 0, (int) $get( 'within' ) ) );
            $comped      = $get( 'comped' );
            $a['comped'] = in_array( $comped, [ '0', '1' ], true ) ? $comped : '';
        } else {
            $year      = $get( 'year' );
            $a['year'] = ( $year === 'none' || preg_match( '/^\d{4}$/', $year ) ) ? $year : '';
            $a['since'] = min( 3660, max( 0, (int) $get( 'since' ) ) );
        }
        return $a;
    }

    /**
     * Human labels of the data checks, keyed by the check=… value.
     *
     * @return array<string,string>
     */
    private static function check_labels(): array {
        return [
            self::CHECK_BAD_DATE      => __( 'paid_through is not a YYYY-MM-DD date', 'my-iapsnj' ),
            self::CHECK_TAG_NO_TYPE   => __( 'Tagged Member-Active but no member_type', 'my-iapsnj' ),
            self::CHECK_LAPSED_TAGGED => __( 'Lapsed but still tagged Member-Active', 'my-iapsnj' ),
            self::CHECK_COMPED_TAG    => __( 'Honorary / Lifetime tag but member_type is not Honorary / Lifetime', 'my-iapsnj' ),
        ];
    }

    // -----------------------------------------------------------------------
    // SQL building blocks
    // -----------------------------------------------------------------------

    /**
     * @return array{meta:string,subs:string,pivot:string,tags:string,users:string}
     */
    private static function tables(): array {
        global $wpdb;
        return [
            'meta'  => $wpdb->prefix . 'fc_subscriber_meta',
            'subs'  => $wpdb->prefix . 'fc_subscribers',
            'pivot' => $wpdb->prefix . 'fc_subscriber_pivot',
            'tags'  => $wpdb->prefix . 'fc_tags',
            'users' => $wpdb->users,
        ];
    }

    /**
     * Derived table m(subscriber_id, member_type, paid_through): one row per
     * contact with either key. MAX() keeps duplicate meta rows from creating
     * duplicate list rows.
     */
    private static function derived_sql(): string {
        global $wpdb;
        $t = self::tables();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->prepare(
            "SELECT subscriber_id,
                    MAX(CASE WHEN `key` = %s THEN `value` END) AS member_type,
                    MAX(CASE WHEN `key` = %s THEN `value` END) AS paid_through
             FROM {$t['meta']}
             WHERE object_type = 'custom_field' AND `key` IN (%s, %s)
             GROUP BY subscriber_id",
            My_IAPSNJ_Schema::FIELD_MEMBER_TYPE,
            My_IAPSNJ_Schema::FIELD_PAID_THROUGH,
            My_IAPSNJ_Schema::FIELD_MEMBER_TYPE,
            My_IAPSNJ_Schema::FIELD_PAID_THROUGH
        );
    }

    /** member_type set (state_of() !== ''). LENGTH, not <> '', so ' ' counts as PHP does. */
    private static function is_member_sql(): string {
        return "LENGTH(COALESCE(m.member_type, '')) > 0";
    }

    /** member_type is Lifetime / Honorary (case- and space-exact, like in_array strict). */
    private static function comped_sql(): string {
        global $wpdb;
        $comped = My_IAPSNJ_Schema::comped_types();
        return $wpdb->prepare(
            "BINARY COALESCE(m.member_type, '') IN (" . implode( ', ', array_fill( 0, count( $comped ), '%s' ) ) . ')',
            $comped
        );
    }

    /** paid_through is a canonical YYYY-MM-DD. */
    private static function valid_date_sql(): string {
        global $wpdb;
        return $wpdb->prepare( "COALESCE(m.paid_through, '') REGEXP %s", self::YMD_REGEX );
    }

    /** My_IAPSNJ_Schema::is_active_state() in SQL (never NULL). */
    private static function active_sql(): string {
        global $wpdb;
        return '(' . self::comped_sql() . ' OR (' . self::valid_date_sql() . $wpdb->prepare( ' AND m.paid_through >= %s', My_IAPSNJ_Schema::active_cutoff() ) . '))';
    }

    /** Non-comped member whose paid_through is set but not YYYY-MM-DD. */
    private static function bad_date_sql(): string {
        return '(NOT ' . self::comped_sql() . " AND TRIM(COALESCE(m.paid_through, '')) <> '' AND NOT " . self::valid_date_sql() . ')';
    }

    /** The contact carries one of these tags (0 ids = never). */
    private static function has_tag_sql( array $tag_ids ): string {
        global $wpdb;
        $tag_ids = array_values( array_filter( array_map( 'intval', $tag_ids ) ) );
        if ( ! $tag_ids ) {
            return '(1 = 0)';
        }
        $t = self::tables();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->prepare(
            "EXISTS (SELECT 1 FROM {$t['pivot']} p WHERE p.subscriber_id = s.id AND p.object_type = %s AND p.object_id IN (" . implode( ', ', array_fill( 0, count( $tag_ids ), '%d' ) ) . '))',
            array_merge( [ self::TAG_OBJECT_TYPE ], $tag_ids )
        );
    }

    /**
     * Tag slug → id for the tags the lists look at. Read-only (unlike
     * My_IAPSNJ_Schema::tag_ids(), which creates missing tags).
     *
     * @return array<string,int>
     */
    private static function tag_map(): array {
        if ( self::$tag_map !== null ) {
            return self::$tag_map;
        }
        global $wpdb;
        $t     = self::tables();
        $slugs = [ My_IAPSNJ_Schema::TAG_ACTIVE, My_IAPSNJ_Schema::TAG_PENDING_CHECK, My_IAPSNJ_Schema::TAG_HONORARY, My_IAPSNJ_Schema::TAG_LIFETIME ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT slug, id FROM {$t['tags']} WHERE slug IN (%s, %s, %s, %s)",
            $slugs
        ), ARRAY_A );
        self::$tag_map = [];
        foreach ( (array) $rows as $r ) {
            self::$tag_map[ (string) $r['slug'] ] = (int) $r['id'];
        }
        return self::$tag_map;
    }

    private static function tag_id( string $slug ): int {
        return (int) ( self::tag_map()[ $slug ] ?? 0 );
    }

    /**
     * Y-m-d plus N calendar days (UTC round trip, no timezone shift).
     */
    private static function ymd_add( string $ymd, int $days ): string {
        $ts = strtotime( $ymd . ' 00:00:00 UTC' );
        return $ts !== false ? gmdate( 'Y-m-d', $ts + $days * DAY_IN_SECONDS ) : $ymd;
    }

    private static function grace_days(): int {
        return max( 0, (int) ( My_IAPSNJ_Plugin::settings()['expiry_grace_days'] ?? 0 ) );
    }

    /**
     * FROM and WHERE for a list (already prepared).
     *
     * @return array{0:string,1:string} [ from, where ]
     */
    private static function from_where( string $state, array $a ): array {
        global $wpdb;
        $t       = self::tables();
        $derived = self::derived_sql();
        $member  = self::is_member_sql();
        $active  = self::active_sql();
        $comped  = self::comped_sql();
        $valid   = self::valid_date_sql();
        $cutoff  = My_IAPSNJ_Schema::active_cutoff();
        $tag_act = self::has_tag_sql( [ self::tag_id( My_IAPSNJ_Schema::TAG_ACTIVE ) ] );

        $inner = "({$derived}) m INNER JOIN {$t['subs']} s ON s.id = m.subscriber_id";
        $outer = "{$t['subs']} s LEFT JOIN ({$derived}) m ON m.subscriber_id = s.id";
        $from  = $inner;
        $where = [];

        switch ( $a['check'] ) {
            case self::CHECK_BAD_DATE:
                $where[] = $member;
                $where[] = self::bad_date_sql();
                break;
            case self::CHECK_TAG_NO_TYPE:
                $from    = $outer;
                $where[] = "NOT {$member}";
                $where[] = $tag_act;
                break;
            case self::CHECK_LAPSED_TAGGED:
                $where[] = $member;
                $where[] = "NOT {$active}";
                $where[] = $tag_act;
                break;
            case self::CHECK_COMPED_TAG:
                $from    = $outer;
                $where[] = self::has_tag_sql( [ self::tag_id( My_IAPSNJ_Schema::TAG_HONORARY ), self::tag_id( My_IAPSNJ_Schema::TAG_LIFETIME ) ] );
                $where[] = "NOT {$comped}";
                break;
            default:
                $where[] = $member;
                $where[] = $state === self::STATE_ACTIVE ? $active : "NOT {$active}";
        }

        // Type view.
        if ( $a['type'] === self::TYPE_OTHER ) {
            $types   = My_IAPSNJ_Schema::member_types();
            $where[] = $member;
            $where[] = $wpdb->prepare( 'BINARY m.member_type NOT IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')', $types );
        } elseif ( $a['type'] !== '' ) {
            $where[] = $wpdb->prepare( 'BINARY m.member_type = %s', $a['type'] );
        }

        // State-specific filters.
        if ( $state === self::STATE_ACTIVE ) {
            if ( $a['due'] === '1' ) {
                $next    = (int) substr( My_IAPSNJ_Dates::today(), 0, 4 ) + 1;
                $where[] = "NOT {$comped}";
                $where[] = $wpdb->prepare( 'm.paid_through < %s', $next . '-12-31' );
            }
            if ( $a['within'] > 0 ) {
                // Lapses on paid_through + grace + 1 day; within N days means
                // that day is on or before today + N.
                $where[] = "NOT {$comped}";
                $where[] = $wpdb->prepare( 'm.paid_through <= %s', self::ymd_add( $cutoff, $a['within'] - 1 ) );
            }
            if ( $a['comped'] === '1' ) {
                $where[] = $comped;
            } elseif ( $a['comped'] === '0' ) {
                $where[] = "NOT {$comped}";
            }
        } else {
            if ( $a['year'] === 'none' ) {
                $where[] = "TRIM(COALESCE(m.paid_through, '')) = ''";
            } elseif ( $a['year'] !== '' ) {
                $where[] = $valid;
                $where[] = $wpdb->prepare( 'LEFT(m.paid_through, 4) = %s', $a['year'] );
            }
            if ( $a['since'] > 0 ) {
                // Lapsed within the last N days (lapse day = paid_through + grace + 1).
                $where[] = $valid;
                $where[] = $wpdb->prepare( 'm.paid_through >= %s', self::ymd_add( $cutoff, - $a['since'] ) );
            }
        }

        // Search: name / email LIKE, or an exact member number.
        if ( $a['s'] !== '' ) {
            $like    = '%' . $wpdb->esc_like( $a['s'] ) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $where[] = $wpdb->prepare(
                "(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE %s
                  OR s.id IN (SELECT subscriber_id FROM {$t['meta']} WHERE object_type = 'custom_field' AND `key` = %s AND `value` = %s))",
                $like,
                $like,
                $like,
                $like,
                My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER,
                $a['s']
            );
        }

        return [ $from, implode( ' AND ', array_unique( $where ) ) ];
    }

    /**
     * ORDER BY from the whitelist (never interpolated from input).
     */
    private static function order_sql( string $state, array $a ): string {
        $orderby = $a['orderby'] !== '' ? $a['orderby'] : ( $state === self::STATE_LAPSED ? 'paid_through' : 'name' );
        $order   = $a['order'];
        if ( $order === '' ) {
            $order = ( $orderby === 'paid_through' && $state === self::STATE_LAPSED ) ? 'desc' : 'asc';
        }
        $dir = $order === 'desc' ? 'DESC' : 'ASC';
        switch ( $orderby ) {
            case 'paid_through':
                return "m.paid_through {$dir}, s.last_name ASC, s.first_name ASC, s.id ASC";
            case 'member_type':
                return "m.member_type {$dir}, s.last_name ASC, s.first_name ASC, s.id ASC";
            default:
                return "s.last_name {$dir}, s.first_name {$dir}, s.id {$dir}";
        }
    }

    private static function count_rows( string $state, array $a ): int {
        global $wpdb;
        [ $from, $where ] = self::from_where( $state, $a );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" );
    }

    /**
     * Rows for one page (or CSV chunk), enriched with the per-page lookups.
     *
     * @return array<int,array>
     */
    private static function fetch( string $state, array $a, int $limit, int $offset, bool $with_address ): array {
        global $wpdb;
        [ $from, $where ] = self::from_where( $state, $a );
        $order   = self::order_sql( $state, $a );
        $active  = self::active_sql();
        $member  = self::is_member_sql();
        $address = $with_address ? ', s.address_line_1, s.address_line_2, s.city, s.state, s.postal_code, s.country' : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared, ORDER BY is whitelisted
        $rows = $wpdb->get_results(
            "SELECT s.id, s.first_name, s.last_name, s.email, s.phone, s.user_id, s.status,
                    m.member_type, m.paid_through,
                    CASE WHEN {$member} THEN 1 ELSE 0 END AS is_member,
                    CASE WHEN {$active} THEN 1 ELSE 0 END AS is_active{$address}
             FROM {$from}
             WHERE {$where}
             ORDER BY {$order} "
            . $wpdb->prepare( 'LIMIT %d OFFSET %d', $limit, $offset ),
            ARRAY_A
        );
        $rows = (array) $rows;
        if ( ! $rows ) {
            return [];
        }
        return self::enrich( $rows, $with_address );
    }

    // -----------------------------------------------------------------------
    // Per-page lookups
    // -----------------------------------------------------------------------

    /**
     * @param array<int,array> $raw
     * @return array<int,array>
     */
    private static function enrich( array $raw, bool $with_address ): array {
        $ids    = array_map( 'intval', array_column( $raw, 'id' ) );
        $emails = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', array_column( $raw, 'email' ) ) ) ) ) );
        $uids   = array_values( array_unique( array_filter( array_map( 'intval', array_column( $raw, 'user_id' ) ) ) ) );

        $extra  = self::lookup_fields( $ids );
        $tags   = self::lookup_tags( $ids );
        $users  = self::lookup_users( $uids );
        $orders = [];
        $subs   = [];
        if ( My_IAPSNJ_Membership::is_available() && $emails ) {
            $orders = self::lookup_orders( $emails );
            $subs   = self::lookup_subscriptions( $emails );
        }
        $grace = self::grace_days();

        $out = [];
        foreach ( $raw as $r ) {
            $id    = (int) $r['id'];
            $email = strtolower( (string) $r['email'] );
            $uid   = (int) $r['user_id'];
            $pt    = trim( (string) $r['paid_through'] );
            $state = empty( $r['is_member'] ) ? '' : ( ! empty( $r['is_active'] ) ? self::STATE_ACTIVE : self::STATE_LAPSED );
            $valid = (bool) preg_match( '/' . self::YMD_REGEX . '/', $pt );
            $row   = [
                'id'             => $id,
                'crm_url'        => self::crm_url( $id ),
                'first_name'     => (string) $r['first_name'],
                'last_name'      => (string) $r['last_name'],
                'email'          => (string) $r['email'],
                'phone'          => (string) $r['phone'],
                'user_id'        => isset( $users[ $uid ] ) ? $uid : 0,
                'crm_status'     => (string) $r['status'],
                'member_type'    => (string) $r['member_type'],
                'paid_through'   => $pt,
                'paid_through_valid' => $valid,
                'is_comped'      => My_IAPSNJ_Schema::is_comped_type( (string) $r['member_type'] ),
                'state'          => $state,
                'lapsed_on'      => ( $state === self::STATE_LAPSED && $valid ) ? self::ymd_add( $pt, $grace + 1 ) : '',
                'member_number'  => $extra[ $id ][ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] ?? '',
                'department'     => $extra[ $id ][ My_IAPSNJ_Schema::FIELD_DEPARTMENT ] ?? '',
                'join_date'      => $extra[ $id ][ My_IAPSNJ_Schema::FIELD_JOIN_DATE ] ?? '',
                'last_paid_year' => (int) ( $tags[ $id ]['last_paid_year'] ?? 0 ),
                'has_active_tag' => ! empty( $tags[ $id ]['active'] ),
                'pending_check'  => ! empty( $tags[ $id ]['pending'] ),
                'last_order'     => $orders[ $email ] ?? null,
                'subscription'   => $subs[ $email ] ?? null,
            ];
            if ( $with_address ) {
                foreach ( [ 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ] as $k ) {
                    $row[ 'addr_' . $k ] = (string) ( $r[ $k ] ?? '' );
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * member_number / department / join_date for the page.
     *
     * @param int[] $ids
     * @return array<int,array<string,string>>
     */
    private static function lookup_fields( array $ids ): array {
        global $wpdb;
        if ( ! $ids ) {
            return [];
        }
        $t    = self::tables();
        $keys = [ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER, My_IAPSNJ_Schema::FIELD_DEPARTMENT, My_IAPSNJ_Schema::FIELD_JOIN_DATE ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT subscriber_id, `key`, `value` FROM {$t['meta']}
             WHERE object_type = 'custom_field' AND `key` IN (%s, %s, %s)
             AND subscriber_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
            array_merge( $keys, $ids )
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $v = maybe_unserialize( (string) $r['value'] );
            $out[ (int) $r['subscriber_id'] ][ (string) $r['key'] ] = is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v;
        }
        return $out;
    }

    /**
     * Newest Paid-YYYY year, Member-Active and Payment-Pending-Check per contact.
     *
     * @param int[] $ids
     * @return array<int,array{last_paid_year:int,active:bool,pending:bool}>
     */
    private static function lookup_tags( array $ids ): array {
        global $wpdb;
        if ( ! $ids ) {
            return [];
        }
        $t = self::tables();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.subscriber_id, t.slug FROM {$t['pivot']} p
             INNER JOIN {$t['tags']} t ON t.id = p.object_id
             WHERE p.object_type = %s
             AND ( t.slug LIKE %s OR t.slug IN (%s, %s) )
             AND p.subscriber_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
            array_merge(
                [ self::TAG_OBJECT_TYPE, $wpdb->esc_like( My_IAPSNJ_Schema::TAG_PAID_PREFIX ) . '%', My_IAPSNJ_Schema::TAG_ACTIVE, My_IAPSNJ_Schema::TAG_PENDING_CHECK ],
                $ids
            )
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $sid  = (int) $r['subscriber_id'];
            $slug = (string) $r['slug'];
            if ( ! isset( $out[ $sid ] ) ) {
                $out[ $sid ] = [ 'last_paid_year' => 0, 'active' => false, 'pending' => false ];
            }
            if ( $slug === My_IAPSNJ_Schema::TAG_ACTIVE ) {
                $out[ $sid ]['active'] = true;
            } elseif ( $slug === My_IAPSNJ_Schema::TAG_PENDING_CHECK ) {
                $out[ $sid ]['pending'] = true;
            } else {
                $out[ $sid ]['last_paid_year'] = max( $out[ $sid ]['last_paid_year'], My_IAPSNJ_Schema::year_from_paid_slug( $slug ) );
            }
        }
        return $out;
    }

    /**
     * WordPress user ids that still exist.
     *
     * @param int[] $uids
     * @return array<int,true>
     */
    private static function lookup_users( array $uids ): array {
        global $wpdb;
        if ( ! $uids ) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID IN (" . implode( ', ', array_fill( 0, count( $uids ), '%d' ) ) . ')',
            $uids
        ) );
        return array_fill_keys( array_map( 'intval', (array) $found ), true );
    }

    /**
     * Newest paid membership order (one that the plugin applied) per email.
     *
     * @param string[] $emails lower-case
     * @return array<string,array{id:int,date:string,amount:string,method:string,url:string}>
     */
    private static function lookup_orders( array $emails ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.email, o.id, o.created_at, o.total_amount, o.currency, o.payment_method
             FROM {$p}fct_customers c
             INNER JOIN {$p}fct_orders o ON o.customer_id = c.id
             WHERE c.email IN (" . implode( ', ', array_fill( 0, count( $emails ), '%s' ) ) . ")
             AND o.payment_status IN ('paid', 'partially_paid', 'partially_refunded')
             AND EXISTS (SELECT 1 FROM {$p}fct_order_meta om WHERE om.order_id = o.id AND om.meta_key = %s)
             ORDER BY o.id DESC",
            array_merge( $emails, [ My_IAPSNJ_Membership::META_APPLIED ] )
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $email = strtolower( (string) $r['email'] );
            if ( isset( $out[ $email ] ) ) {
                continue;
            }
            $id            = (int) $r['id'];
            $out[ $email ] = [
                'id'     => $id,
                'date'   => (string) $r['created_at'],
                'amount' => My_IAPSNJ_Membership::format_money( (int) $r['total_amount'], (string) $r['currency'] ),
                'method' => self::method_label( (string) $r['payment_method'] ),
                'url'    => self::order_url( $id ),
            ];
        }
        return $out;
    }

    /**
     * Newest billing FluentCart subscription on a membership product per email.
     *
     * @param string[] $emails lower-case
     * @return array<string,array{status:string,next_billing_date:string}>
     */
    private static function lookup_subscriptions( array $emails ): array {
        global $wpdb;
        $p          = $wpdb->prefix;
        $variations = array_map( 'intval', array_keys( My_IAPSNJ_Membership::products_config() ) );
        $var_sql    = $variations
            ? ' AND sub.variation_id IN (' . implode( ', ', $variations ) . ')'
            : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.email, sub.status, sub.next_billing_date
             FROM {$p}fct_subscriptions sub
             INNER JOIN {$p}fct_customers c ON c.id = sub.customer_id
             WHERE c.email IN (" . implode( ', ', array_fill( 0, count( $emails ), '%s' ) ) . ')
             AND sub.status IN (' . implode( ', ', array_fill( 0, count( self::SUBSCRIPTION_LIVE ), '%s' ) ) . ")
             {$var_sql}
             ORDER BY sub.id DESC",
            array_merge( $emails, self::SUBSCRIPTION_LIVE )
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $email = strtolower( (string) $r['email'] );
            if ( ! isset( $out[ $email ] ) ) {
                $out[ $email ] = [
                    'status'            => (string) $r['status'],
                    'next_billing_date' => (string) $r['next_billing_date'],
                ];
            }
        }
        return $out;
    }

    private static function method_label( string $method ): string {
        if ( $method === My_IAPSNJ_Membership::OFFLINE_METHOD ) {
            return __( 'Check', 'my-iapsnj' );
        }
        if ( $method === 'stripe' ) {
            return __( 'Card', 'my-iapsnj' );
        }
        if ( $method === 'paypal' ) {
            return __( 'PayPal', 'my-iapsnj' );
        }
        return $method !== '' ? ucwords( str_replace( '_', ' ', $method ) ) : '';
    }

    // -----------------------------------------------------------------------
    // URLs
    // -----------------------------------------------------------------------

    /**
     * List URL with the given query args (empty values dropped).
     *
     * @param array<string,scalar> $query
     */
    private static function url( string $state, array $query ): string {
        $url   = admin_url( 'admin.php?page=' . self::page_slug( $state ) );
        $query = array_filter(
            $query,
            static function ( $v ) {
                return $v !== '' && $v !== 0 && $v !== null;
            }
        );
        return $query ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url ) : $url;
    }

    /**
     * The current filters (no paging, no check) as query args.
     *
     * @return array<string,scalar>
     */
    private static function filter_query( string $state, array $a ): array {
        $q = [ 's' => $a['s'], 'orderby' => $a['orderby'], 'order' => $a['order'] ];
        if ( $a['per_page'] !== self::PER_PAGE_DEFAULT ) {
            $q['per_page'] = $a['per_page'];
        }
        if ( $state === self::STATE_ACTIVE ) {
            $q += [ 'due' => $a['due'], 'within' => $a['within'], 'comped' => $a['comped'] ];
        } else {
            $q += [ 'year' => $a['year'], 'since' => $a['since'] ];
        }
        return $q;
    }

    /**
     * Type views: '' (all) and each type → URL.
     *
     * @return array<string,string>
     */
    private static function view_urls( string $state, array $a ): array {
        $base = self::filter_query( $state, $a );
        $out  = [ '' => self::url( $state, $base ) ];
        foreach ( array_merge( My_IAPSNJ_Schema::member_types(), [ self::TYPE_OTHER ] ) as $type ) {
            $out[ $type ] = self::url( $state, array_merge( $base, [ 'type' => $type ] ) );
        }
        return $out;
    }

    private static function export_url( string $state, array $a ): string {
        $q = array_merge(
            self::filter_query( $state, $a ),
            [ 'type' => $a['type'], 'check' => $a['check'] ]
        );
        $q = array_filter(
            $q,
            static function ( $v ) {
                return $v !== '' && $v !== 0;
            }
        );
        $q = array_merge(
            [
                'action' => 'my_iapsnj_members_csv',
                'nonce'  => wp_create_nonce( 'my_iapsnj_nonce' ),
                'state'  => $state,
            ],
            array_map( 'strval', $q )
        );
        return add_query_arg( array_map( 'rawurlencode', $q ), admin_url( 'admin-ajax.php' ) );
    }

    // -----------------------------------------------------------------------
    // Lapsed page: data checks and year options
    // -----------------------------------------------------------------------

    /**
     * Counts of the four data checks (one query).
     *
     * @return array<string,int>
     */
    private static function check_counts(): array {
        global $wpdb;
        $t      = self::tables();
        $member = self::is_member_sql();
        $active = self::active_sql();
        $comped = self::comped_sql();
        $act    = self::tag_id( My_IAPSNJ_Schema::TAG_ACTIVE );
        $cmp    = array_values( array_filter( [ self::tag_id( My_IAPSNJ_Schema::TAG_HONORARY ), self::tag_id( My_IAPSNJ_Schema::TAG_LIFETIME ) ] ) );
        $all    = array_values( array_filter( array_merge( [ $act ], $cmp ) ) );

        // bad_date needs no tags: counts() already has it.
        $out = array_fill_keys( array_keys( self::check_labels() ), 0 );
        $out[ self::CHECK_BAD_DATE ] = (int) ( self::counts()['_total']['bad_date'] ?? 0 );
        if ( ! $all ) {
            return $out;
        }
        // The tag checks start from the (few) contacts carrying those tags.
        $cmp_sql = $cmp ? 'MAX(object_id IN (' . implode( ', ', array_map( 'intval', $cmp ) ) . '))' : '0';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $tagged = $wpdb->prepare(
            "SELECT subscriber_id, MAX(object_id = %d) AS act, {$cmp_sql} AS cmp
             FROM {$t['pivot']}
             WHERE object_type = %s AND object_id IN (" . implode( ', ', array_fill( 0, count( $all ), '%d' ) ) . ')
             GROUP BY subscriber_id',
            array_merge( [ $act, self::TAG_OBJECT_TYPE ], $all )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
        $r = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN tg.act = 1 AND NOT {$member} THEN 1 ELSE 0 END) AS tag_no_type,
                SUM(CASE WHEN tg.act = 1 AND {$member} AND NOT {$active} THEN 1 ELSE 0 END) AS lapsed_tagged,
                SUM(CASE WHEN tg.cmp = 1 AND NOT {$comped} THEN 1 ELSE 0 END) AS comped_tag_mismatch
             FROM ({$tagged}) tg
             INNER JOIN {$t['subs']} s ON s.id = tg.subscriber_id
             LEFT JOIN (" . self::derived_sql() . ') m ON m.subscriber_id = tg.subscriber_id',
            ARRAY_A
        );
        foreach ( [ self::CHECK_TAG_NO_TYPE, self::CHECK_LAPSED_TAGGED, self::CHECK_COMPED_TAG ] as $key ) {
            $out[ $key ] = (int) ( $r[ $key ] ?? 0 );
        }
        return $out;
    }

    private static function render_checks( array $a ): void {
        $counts = array_filter( self::check_counts() );
        if ( ! $counts ) {
            return;
        }
        $labels = self::check_labels();
        $help   = [
            self::CHECK_BAD_DATE      => __( 'Listed as lapsed here; the daily expiry job may read the date differently. Correct the date on the contact.', 'my-iapsnj' ),
            self::CHECK_TAG_NO_TYPE   => __( 'Not a member, so the daily job never removes the tag (for example a manual tag).', 'my-iapsnj' ),
            self::CHECK_LAPSED_TAGGED => __( 'The next daily expiry run (or Apply now) removes the tag.', 'my-iapsnj' ),
            self::CHECK_COMPED_TAG    => __( 'Membership state follows member_type only: set member_type to Honorary / Lifetime if the member is comped.', 'my-iapsnj' ),
        ];
        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Data checks', 'my-iapsnj' ) . '</strong></p><ul>';
        foreach ( $counts as $key => $n ) {
            $current = $a['check'] === $key;
            printf(
                '<li><a href="%1$s"%2$s>%3$s</a> &mdash; %4$s</li>',
                esc_url( self::url( self::STATE_LAPSED, [ 'check' => $key ] ) ),
                $current ? ' class="current" aria-current="page"' : '',
                esc_html( sprintf(
                    /* translators: 1: number of contacts, 2: data check label */
                    _n( '%1$s contact: %2$s', '%1$s contacts: %2$s', $n, 'my-iapsnj' ),
                    number_format_i18n( $n ),
                    $labels[ $key ]
                ) ),
                esc_html( $help[ $key ] )
            );
        }
        echo '</ul></div>';
    }

    /**
     * Lapsed members by paid_through year ('none' = no date), newest first.
     *
     * @return array<string,int>
     */
    private static function lapsed_years(): array {
        global $wpdb;
        $t      = self::tables();
        $member = self::is_member_sql();
        $active = self::active_sql();
        $valid  = self::valid_date_sql();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fragments are prepared
        $rows = $wpdb->get_results(
            "SELECT CASE WHEN TRIM(COALESCE(m.paid_through, '')) = '' THEN 'none'
                         WHEN {$valid} THEN LEFT(m.paid_through, 4)
                         ELSE 'bad' END AS y,
                    COUNT(*) AS n
             FROM (" . self::derived_sql() . ") m
             INNER JOIN {$t['subs']} s ON s.id = m.subscriber_id
             WHERE {$member} AND NOT {$active}
             GROUP BY y",
            ARRAY_A
        );
        $years = [];
        $none  = 0;
        foreach ( (array) $rows as $r ) {
            if ( $r['y'] === 'none' ) {
                $none = (int) $r['n'];
            } elseif ( $r['y'] !== 'bad' ) {
                $years[ (string) $r['y'] ] = (int) $r['n'];
            }
        }
        krsort( $years, SORT_STRING );
        if ( $none ) {
            $years['none'] = $none;
        }
        return $years;
    }

    // -----------------------------------------------------------------------
    // CSV export
    // -----------------------------------------------------------------------

    private static function handle_csv(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ), '', [ 'response' => 403 ] );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
        $state = self::norm_state( isset( $_GET['state'] ) ? sanitize_key( wp_unslash( (string) $_GET['state'] ) ) : '' );
        $a     = self::normalize_args( $state, self::request_args() );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="iapsnj-' . $state . '-members-' . wp_date( 'Ymd' ) . '.csv"' );
        header( 'X-Content-Type-Options: nosniff' );

        $fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( ! $fh ) {
            exit;
        }
        fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fputcsv( $fh, self::csv_header( $state ), ',', '"', '' );
        $offset = 0;
        do {
            $rows = self::fetch( $state, $a, self::CSV_CHUNK, $offset, true );
            foreach ( $rows as $row ) {
                fputcsv( $fh, array_map( [ __CLASS__, 'csv_cell' ], self::csv_row( $state, $row ) ), ',', '"', '' );
            }
            $offset += self::CSV_CHUNK;
            flush();
        } while ( count( $rows ) === self::CSV_CHUNK );
        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /**
     * @return string[]
     */
    private static function csv_header( string $state ): array {
        $h = [
            __( 'Contact ID', 'my-iapsnj' ),
            __( 'First name', 'my-iapsnj' ),
            __( 'Last name', 'my-iapsnj' ),
            __( 'Email', 'my-iapsnj' ),
            __( 'Phone', 'my-iapsnj' ),
            __( 'Address line 1', 'my-iapsnj' ),
            __( 'Address line 2', 'my-iapsnj' ),
            __( 'City', 'my-iapsnj' ),
            __( 'State', 'my-iapsnj' ),
            __( 'Postal code', 'my-iapsnj' ),
            __( 'Country', 'my-iapsnj' ),
            __( 'Member type', 'my-iapsnj' ),
            __( 'Paid through', 'my-iapsnj' ),
        ];
        if ( $state === self::STATE_LAPSED ) {
            $h[] = __( 'Lapsed on', 'my-iapsnj' );
        }
        return array_merge( $h, [
            __( 'Member number', 'my-iapsnj' ),
            __( 'Department', 'my-iapsnj' ),
            __( 'Join date', 'my-iapsnj' ),
            __( 'Last paid year', 'my-iapsnj' ),
            __( 'Member-Active tag', 'my-iapsnj' ),
            __( 'Check pending', 'my-iapsnj' ),
            __( 'Last order ID', 'my-iapsnj' ),
            __( 'Last order date', 'my-iapsnj' ),
            __( 'Last order amount', 'my-iapsnj' ),
            __( 'Last order method', 'my-iapsnj' ),
            __( 'Subscription status', 'my-iapsnj' ),
            __( 'Subscription next billing', 'my-iapsnj' ),
            __( 'CRM status', 'my-iapsnj' ),
            __( 'WordPress user ID', 'my-iapsnj' ),
        ] );
    }

    /**
     * Raw values: dates as Y-m-d, like the CRM stores them.
     *
     * @return array<int,string>
     */
    private static function csv_row( string $state, array $r ): array {
        $yes   = __( 'Yes', 'my-iapsnj' );
        $order = is_array( $r['last_order'] ) ? $r['last_order'] : null;
        $sub   = is_array( $r['subscription'] ) ? $r['subscription'] : null;
        $cells = [
            (string) $r['id'],
            $r['first_name'],
            $r['last_name'],
            $r['email'],
            $r['phone'],
            $r['addr_address_line_1'] ?? '',
            $r['addr_address_line_2'] ?? '',
            $r['addr_city'] ?? '',
            $r['addr_state'] ?? '',
            $r['addr_postal_code'] ?? '',
            $r['addr_country'] ?? '',
            $r['member_type'],
            $r['paid_through'],
        ];
        if ( $state === self::STATE_LAPSED ) {
            $cells[] = $r['lapsed_on'];
        }
        return array_merge( $cells, [
            $r['member_number'],
            $r['department'],
            $r['join_date'],
            $r['last_paid_year'] ? (string) $r['last_paid_year'] : '',
            $r['has_active_tag'] ? $yes : '',
            $r['pending_check'] ? $yes : '',
            $order ? (string) $order['id'] : '',
            $order ? substr( (string) $order['date'], 0, 10 ) : '',
            $order ? html_entity_decode( (string) $order['amount'], ENT_QUOTES, 'UTF-8' ) : '',
            $order ? (string) $order['method'] : '',
            $sub ? (string) $sub['status'] : '',
            $sub ? substr( (string) $sub['next_billing_date'], 0, 10 ) : '',
            $r['crm_status'],
            $r['user_id'] ? (string) $r['user_id'] : '',
        ] );
    }

    /**
     * Neutralise spreadsheet formulas (CSV injection): a cell starting with
     * = + - @ tab or CR gets a leading apostrophe.
     */
    private static function csv_cell( $value ): string {
        $value = (string) $value;
        if ( $value !== '' && strpos( "=+-@\t\r", $value[0] ) !== false ) {
            return "'" . $value;
        }
        return $value;
    }

    // -----------------------------------------------------------------------
    // Link helpers used by the list table
    // -----------------------------------------------------------------------

    /**
     * FluentCRM contact screen.
     */
    private static function crm_url( int $id ): string {
        if ( function_exists( 'fluentcrm_menu_url_base' ) ) {
            return (string) fluentcrm_menu_url_base( 'subscribers/' . $id );
        }
        return admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . $id );
    }

    /**
     * FluentCart admin order screen (Order::getViewUrl('admin') format).
     */
    private static function order_url( int $id ): string {
        if ( class_exists( '\FluentCart\App\Services\URL' ) && method_exists( '\FluentCart\App\Services\URL', 'getDashboardUrl' ) ) {
            try {
                return (string) \FluentCart\App\Services\URL::getDashboardUrl( 'orders/' . $id . '/view' );
            } catch ( \Throwable $e ) {
                // fall through
            }
        }
        return admin_url( 'admin.php?page=fluent-cart#/orders/' . $id . '/view' );
    }
}
