<?php
/**
 * My_IAPSNJ_Migration
 *
 * PMPro → FluentCRM migration toolkit (plan Phase 1 census + Phase 3).
 *
 * Every step:
 *  - reads PMPro's tables with $wpdb only, so it works with PMPro deactivated
 *    (the tables are retained forever; the plugin is not);
 *  - supports dry runs: nothing is written and the report says what would be;
 *  - is paged (offset / limit) so it can run from WP-CLI or from the admin
 *    screen without timing out on 4,000 members;
 *  - is idempotent: running it twice converges on the same state.
 *
 * Honorary and Lifetime members are migrated from pmpro_memberships_users,
 * never from orders — they have no orders. Anything driven off orders would
 * drop them silently.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Migration {

    const STEPS = [
        'census',
        'link_subscribers',
        'consolidate_addresses',
        'backfill_year_tags',
        'migrate_comped',
        'set_member_state',
        'verify_logins',
        'reconciliation',
    ];

    /** Steps that never write, whatever the dry-run flag says. */
    const READ_ONLY_STEPS = [ 'census', 'verify_logins', 'reconciliation' ];

    const SAMPLE_ROWS = 100;

    /** ACF user-meta keys that hold the member-edited address. */
    const ACF_ADDRESS = [
        'address_line_1' => 'address',
        'address_line_2' => 'address2',
        'city'           => 'city',
        'state'          => 'state',
        'postal_code'    => 'zip_code',
        'phone'          => 'primary_phone',
    ];

    /** PMPro checkout billing meta (transaction history — never written to). */
    const PMPRO_ADDRESS = [
        'address_line_1' => 'pmpro_baddress1',
        'address_line_2' => 'pmpro_baddress2',
        'city'           => 'pmpro_bcity',
        'state'          => 'pmpro_bstate',
        'postal_code'    => 'pmpro_bzipcode',
        'country'        => 'pmpro_bcountry',
        'phone'          => 'pmpro_bphone',
    ];

    // -----------------------------------------------------------------------
    // Arguments
    // -----------------------------------------------------------------------

    public static function default_args(): array {
        return [
            'level_map'               => [],          // level id → member type (auto-guessed from names when empty)
            'from_year'               => 2024,        // first Paid-YYYY tag to backfill
            'order_statuses'          => [ 'success' ],
            'order_tz'                => 'utc',       // PMPro ≥ 2.x stores UTC; 'site' treats stored datetimes as local
            'include_zero'            => false,       // $0 orders never earn a Paid tag unless asked
            'address_mode'            => 'prefer_recent', // prefer_recent | prefer_acf | prefer_pmpro | fill_empty
            'pmpro_fresh_days'        => 365,
            'default_country'         => 'US',
            'create_missing_contacts' => true,
            'mu_statuses'             => [ 'active' ],
            'expected'                => 0,
        ];
    }

    public static function args( array $args ): array {
        $a = array_merge( self::default_args(), array_filter( $args, function ( $v ) {
            return $v !== null && $v !== '';
        } ) );
        $a['level_map'] = self::level_map( $a );
        return $a;
    }

    /**
     * "1:Regular,4:Associate,2:Lifetime,6:Honorary" → [1 => 'Regular', …]
     */
    public static function parse_level_map( string $spec ): array {
        $out = [];
        foreach ( preg_split( '/[,\s]+/', trim( $spec ) ) as $pair ) {
            if ( strpos( $pair, ':' ) === false ) {
                continue;
            }
            [ $id, $type ] = array_map( 'trim', explode( ':', $pair, 2 ) );
            $type = self::normalize_type( $type );
            if ( (int) $id > 0 && $type !== '' ) {
                $out[ (int) $id ] = $type;
            }
        }
        return $out;
    }

    public static function normalize_type( string $type ): string {
        $t = strtolower( trim( $type ) );
        foreach ( My_IAPSNJ_Schema::member_types() as $canonical ) {
            if ( strtolower( $canonical ) === $t ) {
                return $canonical;
            }
        }
        return '';
    }

    /**
     * Guess a member type from a PMPro level name.
     */
    public static function guess_type( string $name ): string {
        $n = strtolower( $name );
        if ( strpos( $n, 'honor' ) !== false ) {
            return My_IAPSNJ_Schema::TYPE_HONORARY;
        }
        if ( strpos( $n, 'life' ) !== false ) {
            return My_IAPSNJ_Schema::TYPE_LIFETIME;
        }
        if ( strpos( $n, 'assoc' ) !== false ) {
            return My_IAPSNJ_Schema::TYPE_ASSOCIATE;
        }
        return My_IAPSNJ_Schema::TYPE_REGULAR;
    }

    // -----------------------------------------------------------------------
    // PMPro tables
    // -----------------------------------------------------------------------

    public static function tables(): array {
        global $wpdb;
        return [
            'levels' => $wpdb->prefix . 'pmpro_membership_levels',
            'mu'     => $wpdb->prefix . 'pmpro_memberships_users',
            'orders' => $wpdb->prefix . 'pmpro_membership_orders',
        ];
    }

    public static function tables_exist(): bool {
        global $wpdb;
        foreach ( self::tables() as $t ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int,string> level id → name
     */
    public static function levels(): array {
        global $wpdb;
        $t = self::tables();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT id, name FROM `{$t['levels']}` ORDER BY id" );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = (string) $r->name;
        }
        return $out;
    }

    /**
     * Final level id → member type map (explicit overrides win over guesses).
     */
    public static function level_map( array $args ): array {
        $map = [];
        foreach ( self::levels() as $id => $name ) {
            $map[ $id ] = self::guess_type( $name );
        }
        foreach ( (array) ( $args['level_map'] ?? [] ) as $id => $type ) {
            $type = self::normalize_type( (string) $type );
            if ( $type !== '' ) {
                $map[ (int) $id ] = $type;
            }
        }
        return $map;
    }

    private static function comped_level_ids( array $map ): array {
        $ids = [];
        foreach ( $map as $id => $type ) {
            if ( My_IAPSNJ_Schema::is_comped_type( $type ) ) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    // -----------------------------------------------------------------------
    // Runner
    // -----------------------------------------------------------------------

    private static function blank_report( string $step, bool $dry, int $offset, int $limit ): array {
        return [
            'step'        => $step,
            'dry_run'     => $dry,
            'offset'      => $offset,
            'limit'       => $limit,
            'processed'   => 0,
            'has_more'    => false,
            'next_offset' => $offset,
            'total'       => 0,
            'counts'      => [],
            'sections'    => [],
            'rows'        => [],
            'warnings'    => [],
            'errors'      => [],
            'fatal'       => '',
        ];
    }

    /**
     * Run one page of a step.
     */
    public static function run( string $step, bool $dry_run, int $offset, int $limit, array $args = [] ): array {
        $report = self::blank_report( $step, $dry_run, $offset, $limit );
        if ( ! in_array( $step, self::STEPS, true ) ) {
            $report['fatal'] = 'Unknown step: ' . $step;
            return $report;
        }
        if ( in_array( $step, self::READ_ONLY_STEPS, true ) ) {
            $report['dry_run'] = true;
            $dry_run           = true;
        }
        if ( ! self::tables_exist() && $step !== 'verify_logins' ) {
            $report['fatal'] = 'PMPro tables not found (pmpro_membership_levels / pmpro_memberships_users / pmpro_membership_orders). Nothing to migrate from.';
            return $report;
        }
        $args   = self::args( $args );
        $method = 'step_' . $step;
        try {
            self::$method( $report, $dry_run, $offset, max( 1, $limit ), $args );
        } catch ( \Throwable $e ) {
            $report['fatal'] = $e->getMessage();
        }
        return $report;
    }

    /**
     * Merge a page report into the running aggregate.
     */
    public static function merge_reports( ?array $agg, array $page ): array {
        if ( $agg === null ) {
            return $page;
        }
        $agg['processed']  += (int) $page['processed'];
        $agg['has_more']    = ! empty( $page['has_more'] );
        $agg['next_offset'] = (int) $page['next_offset'];
        $agg['total']       = max( (int) $agg['total'], (int) $page['total'] );
        $agg['counts']      = self::merge_counts( $agg['counts'], $page['counts'] );
        foreach ( (array) $page['sections'] as $title => $table ) {
            if ( ! isset( $agg['sections'][ $title ] ) ) {
                $agg['sections'][ $title ] = $table;
            } elseif ( is_array( $table ) && isset( $table[0] ) ) {
                $agg['sections'][ $title ] = array_merge( (array) $agg['sections'][ $title ], $table );
            } else {
                $agg['sections'][ $title ] = self::merge_counts( (array) $agg['sections'][ $title ], (array) $table );
            }
        }
        $agg['rows']     = array_slice( array_merge( $agg['rows'], $page['rows'] ), 0, 500 );
        $agg['warnings'] = array_slice( array_values( array_unique( array_merge( $agg['warnings'], $page['warnings'] ) ) ), 0, 200 );
        $agg['errors']   = array_slice( array_merge( $agg['errors'], $page['errors'] ), 0, 200 );
        if ( ! empty( $page['fatal'] ) ) {
            $agg['fatal'] = $page['fatal'];
        }
        return $agg;
    }

    private static function merge_counts( array $a, array $b ): array {
        foreach ( $b as $k => $v ) {
            if ( is_array( $v ) ) {
                $a[ $k ] = self::merge_counts( (array) ( $a[ $k ] ?? [] ), $v );
            } elseif ( is_int( $v ) || is_float( $v ) ) {
                $a[ $k ] = ( $a[ $k ] ?? 0 ) + $v;
            } else {
                $a[ $k ] = $v;
            }
        }
        return $a;
    }

    private static function inc( array &$report, string $key, int $by = 1 ): void {
        $report['counts'][ $key ] = (int) ( $report['counts'][ $key ] ?? 0 ) + $by;
    }

    private static function inc_map( array &$report, string $key, $sub, int $by = 1 ): void {
        if ( ! isset( $report['counts'][ $key ] ) || ! is_array( $report['counts'][ $key ] ) ) {
            $report['counts'][ $key ] = [];
        }
        $report['counts'][ $key ][ (string) $sub ] = (int) ( $report['counts'][ $key ][ (string) $sub ] ?? 0 ) + $by;
    }

    private static function row( array &$report, array $row ): void {
        if ( count( $report['rows'] ) < self::SAMPLE_ROWS ) {
            $report['rows'][] = $row;
        }
    }

    // -----------------------------------------------------------------------
    // Subscriber resolution (no writes in dry runs)
    // -----------------------------------------------------------------------

    /**
     * @param array<int,Subscriber|null> $cache
     */
    private static function resolve_subscriber( int $user_id, ?\WP_User $user, array &$cache, string &$how ): ?Subscriber {
        $how = '';
        if ( array_key_exists( $user_id, $cache ) ) {
            $how = 'cache';
            return $cache[ $user_id ];
        }
        $sub = Subscriber::where( 'user_id', $user_id )->first();
        if ( $sub instanceof Subscriber ) {
            $how = 'user_id';
            return $cache[ $user_id ] = $sub;
        }
        $meta_id = (int) get_user_meta( $user_id, My_IAPSNJ_Engine::LINK_META_KEY, true );
        if ( $meta_id > 0 ) {
            $sub = Subscriber::where( 'id', $meta_id )->first();
            if ( $sub instanceof Subscriber && ( empty( $sub->user_id ) || (int) $sub->user_id === $user_id ) ) {
                $how = 'meta';
                return $cache[ $user_id ] = $sub;
            }
        }
        if ( ! $user ) {
            $user = get_userdata( $user_id ) ?: null;
        }
        if ( $user && $user->user_email ) {
            $sub = Subscriber::where( 'email', $user->user_email )->first();
            if ( $sub instanceof Subscriber ) {
                if ( empty( $sub->user_id ) || (int) $sub->user_id === $user_id ) {
                    $how = 'email';
                    return $cache[ $user_id ] = $sub;
                }
                $how = 'conflict';
                return $cache[ $user_id ] = null;
            }
        }
        $how = 'none';
        return $cache[ $user_id ] = null;
    }

    /**
     * Resolve or create the contact for a WordPress user.
     */
    private static function ensure_subscriber( int $user_id, bool $dry, array $args, array &$report, array &$cache ): ?Subscriber {
        $user = get_userdata( $user_id ) ?: null;
        if ( ! $user ) {
            self::inc( $report, 'no_wp_user' );
            return null;
        }
        $how = '';
        $sub = self::resolve_subscriber( $user_id, $user, $cache, $how );
        if ( $sub instanceof Subscriber ) {
            if ( ! $dry ) {
                if ( empty( $sub->user_id ) ) {
                    $sub->user_id = $user_id;
                    $sub->save();
                }
                My_IAPSNJ_Engine::remember_subscriber_link( $user_id, (int) $sub->id );
            }
            return $sub;
        }
        if ( $how === 'conflict' ) {
            self::inc( $report, 'email_conflicts' );
            $report['warnings'][] = sprintf( 'User #%d (%s): contact with that email is linked to a different WordPress user.', $user_id, $user->user_email );
            return null;
        }
        if ( empty( $args['create_missing_contacts'] ) || ! is_email( $user->user_email ) ) {
            self::inc( $report, 'no_contact' );
            return null;
        }
        self::inc( $report, 'contacts_created' );
        if ( $dry ) {
            return null;
        }
        $created = FluentCrmApi( 'contacts' )->createOrUpdate( [
            'email'      => $user->user_email,
            'first_name' => (string) $user->first_name,
            'last_name'  => (string) $user->last_name,
            'user_id'    => $user_id,
            'status'     => 'subscribed',
            'source'     => 'pmpro-migration',
        ] );
        if ( ! $created instanceof Subscriber ) {
            self::inc( $report, 'contacts_created', -1 );
            $report['errors'][] = sprintf( 'User #%d (%s): contact creation failed.', $user_id, $user->user_email );
            return null;
        }
        My_IAPSNJ_Engine::remember_subscriber_link( $user_id, (int) $created->id );
        return $cache[ $user_id ] = $created;
    }

    // -----------------------------------------------------------------------
    // Step: census (read-only)
    // -----------------------------------------------------------------------

    private static function step_census( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        $t      = self::tables();
        $levels = self::levels();
        $map    = $args['level_map'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $mu = $wpdb->get_results( "SELECT membership_id, status, COUNT(*) AS n, COUNT(DISTINCT user_id) AS users FROM `{$t['mu']}` GROUP BY membership_id, status" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orders = $wpdb->get_results( "SELECT membership_id, status, COUNT(*) AS n, COUNT(DISTINCT user_id) AS users, MIN(`timestamp`) AS first_order, MAX(`timestamp`) AS last_order, SUM(total) AS total FROM `{$t['orders']}` GROUP BY membership_id, status" );

        $active_by_level = [];
        $rows_by_level   = [];
        foreach ( (array) $mu as $r ) {
            $lid = (int) $r->membership_id;
            $rows_by_level[ $lid ] = ( $rows_by_level[ $lid ] ?? 0 ) + (int) $r->n;
            if ( $r->status === 'active' ) {
                $active_by_level[ $lid ] = (int) $r->users;
            }
        }

        $level_rows = [];
        foreach ( $levels as $id => $name ) {
            $level_rows[] = [
                'level_id'       => $id,
                'name'           => $name,
                'member_type'    => $map[ $id ] ?? '',
                'active_members' => $active_by_level[ $id ] ?? 0,
                'history_rows'   => $rows_by_level[ $id ] ?? 0,
            ];
        }
        $report['sections']['levels'] = $level_rows;

        $order_rows  = [];
        $orphan_rows = [];
        $orphan_ids  = [];
        foreach ( (array) $orders as $r ) {
            $lid = (int) $r->membership_id;
            $order_rows[] = [
                'membership_id' => $lid,
                'level'         => $levels[ $lid ] ?? '** ORPHAN (deleted level) **',
                'status'        => (string) $r->status,
                'orders'        => (int) $r->n,
                'users'         => (int) $r->users,
                'first_order'   => (string) $r->first_order,
                'last_order'    => (string) $r->last_order,
                'total'         => number_format( (float) $r->total, 2 ),
            ];
            if ( ! isset( $levels[ $lid ] ) ) {
                $orphan_ids[ $lid ] = true;
            }
        }
        $report['sections']['orders_by_level_and_status'] = $order_rows;

        foreach ( array_keys( $rows_by_level ) as $lid ) {
            if ( ! isset( $levels[ $lid ] ) ) {
                $orphan_ids[ $lid ] = true;
            }
        }
        foreach ( array_keys( $orphan_ids ) as $lid ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $u = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS rows_, COUNT(DISTINCT user_id) AS users, SUM(status='active') AS active FROM `{$t['mu']}` WHERE membership_id = %d", $lid ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $o = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COUNT(DISTINCT user_id) AS users, MIN(`timestamp`) AS f, MAX(`timestamp`) AS l FROM `{$t['orders']}` WHERE membership_id = %d", $lid ) );
            $orphan_rows[] = [
                'membership_id'    => $lid,
                'membership_rows'  => (int) ( $u->rows_ ?? 0 ),
                'distinct_users'   => (int) ( $u->users ?? 0 ),
                'still_active'     => (int) ( $u->active ?? 0 ),
                'orders'           => (int) ( $o->n ?? 0 ),
                'order_users'      => (int) ( $o->users ?? 0 ),
                'first_order'      => (string) ( $o->f ?? '' ),
                'last_order'       => (string) ( $o->l ?? '' ),
            ];
        }
        $report['sections']['orphaned_levels'] = $orphan_rows;
        $report['counts']['orphan_level_ids'] = implode( ', ', array_keys( $orphan_ids ) ) ?: '(none)';
        if ( $orphan_rows ) {
            $report['warnings'][] = 'Level IDs ' . implode( ', ', array_keys( $orphan_ids ) ) . ' are referenced by history but no longer exist. They will not resolve to a level name; backfill tags them by year and records legacy_pmpro_level on the contact.';
        }

        // Comped census.
        $comped_rows = [];
        foreach ( self::comped_level_ids( $map ) as $lid ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM `{$t['mu']}` WHERE membership_id = %d AND status = 'active'", $lid ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $any_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT mu.user_id) FROM `{$t['mu']}` mu INNER JOIN `{$t['orders']}` o ON o.user_id = mu.user_id WHERE mu.membership_id = %d AND mu.status = 'active'", $lid ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $paid_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT mu.user_id) FROM `{$t['mu']}` mu INNER JOIN `{$t['orders']}` o ON o.user_id = mu.user_id AND o.status = 'success' AND o.total > 0 WHERE mu.membership_id = %d AND mu.status = 'active'", $lid ) );
            $comped_rows[] = [
                'level_id'             => $lid,
                'name'                 => $levels[ $lid ] ?? '?',
                'member_type'          => $map[ $lid ],
                'active_members'       => $active,
                'with_any_order'       => $any_order,
                'with_paid_order'      => $paid_order,
                'without_any_order'    => $active - $any_order,
            ];
            $report['counts'][ strtolower( $map[ $lid ] ) . '_active' ] = ( $report['counts'][ strtolower( $map[ $lid ] ) . '_active' ] ?? 0 ) + $active;
        }
        $report['sections']['honorary_lifetime_census'] = $comped_rows;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['wp_users']       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
        $report['counts']['crm_contacts']   = (int) Subscriber::count();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['active_members'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM `{$t['mu']}` WHERE status = 'active'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['orders_total']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['orders']}`" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['orders_success'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['orders']}` WHERE status = 'success'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['active_members_without_email'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT mu.user_id) FROM `{$t['mu']}` mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id WHERE mu.status = 'active' AND (u.user_email IS NULL OR u.user_email = '')" );
        $report['counts']['level_map']      = $map;
        $report['processed'] = 1;
    }

    // -----------------------------------------------------------------------
    // Step: link_subscribers (P3-7)
    // -----------------------------------------------------------------------

    private static function step_link_subscribers( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $report['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
        $users = get_users( [ 'number' => $limit, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC' ] );
        $cache = [];
        foreach ( $users as $user ) {
            $report['processed']++;
            self::inc( $report, 'users' );
            $how = '';
            $sub = self::resolve_subscriber( (int) $user->ID, $user, $cache, $how );
            if ( $sub instanceof Subscriber ) {
                self::inc( $report, 'linked_by_' . $how );
                $needs_user_id = empty( $sub->user_id );
                $needs_meta    = (int) get_user_meta( $user->ID, My_IAPSNJ_Engine::LINK_META_KEY, true ) !== (int) $sub->id;
                if ( $needs_user_id ) {
                    self::inc( $report, 'contact_user_id_set' );
                }
                if ( $needs_meta ) {
                    self::inc( $report, 'user_meta_written' );
                }
                if ( ! $dry ) {
                    if ( $needs_user_id ) {
                        $sub->user_id = (int) $user->ID;
                        $sub->save();
                    }
                    if ( $needs_meta ) {
                        update_user_meta( $user->ID, My_IAPSNJ_Engine::LINK_META_KEY, (int) $sub->id );
                    }
                }
            } elseif ( $how === 'conflict' ) {
                self::inc( $report, 'email_conflicts' );
                self::row( $report, [ 'user_id' => $user->ID, 'email' => $user->user_email, 'issue' => 'contact with this email belongs to another user' ] );
            } else {
                self::inc( $report, 'unmatched' );
                self::row( $report, [ 'user_id' => $user->ID, 'email' => $user->user_email, 'issue' => 'no CRM contact' ] );
            }
        }
        $report['has_more']    = count( $users ) === $limit;
        $report['next_offset'] = $offset + count( $users );
    }

    // -----------------------------------------------------------------------
    // Step: consolidate_addresses (P1-3)
    // -----------------------------------------------------------------------

    private static function step_consolidate_addresses( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        $t = self::tables();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $report['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
        $users = get_users( [ 'number' => $limit, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC' ] );
        if ( ! $users ) {
            return;
        }
        $ids          = array_map( 'intval', wp_list_pluck( $users, 'ID' ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last_orders = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MAX(`timestamp`) AS last_ts FROM `{$t['orders']}` WHERE status = 'success' AND user_id IN ({$placeholders}) GROUP BY user_id", ...$ids ), OBJECT_K );

        $mode  = (string) $args['address_mode'];
        $fresh = max( 1, (int) $args['pmpro_fresh_days'] ) * DAY_IN_SECONDS;
        $cache = [];

        foreach ( $users as $user ) {
            $report['processed']++;
            $uid = (int) $user->ID;
            self::inc( $report, 'users' );

            $acf   = [];
            $pmpro = [];
            foreach ( self::ACF_ADDRESS as $crm => $key ) {
                $v = trim( (string) get_user_meta( $uid, $key, true ) );
                if ( $v !== '' ) {
                    $acf[ $crm ] = $v;
                }
            }
            foreach ( self::PMPRO_ADDRESS as $crm => $key ) {
                $v = trim( (string) get_user_meta( $uid, $key, true ) );
                if ( $v !== '' ) {
                    $pmpro[ $crm ] = $v;
                }
            }
            $has_acf   = ! empty( $acf['address_line_1'] );
            $has_pmpro = ! empty( $pmpro['address_line_1'] );
            if ( ! $has_acf && ! $has_pmpro ) {
                self::inc( $report, 'no_address_anywhere' );
                continue;
            }

            // Choose a source.
            if ( $has_acf && ! $has_pmpro ) {
                $source = 'acf';
                self::inc( $report, 'acf_only' );
            } elseif ( $has_pmpro && ! $has_acf ) {
                $source = 'pmpro';
                self::inc( $report, 'pmpro_only' );
            } else {
                $same = strcasecmp( $acf['address_line_1'], $pmpro['address_line_1'] ) === 0
                    && strcasecmp( $acf['postal_code'] ?? '', $pmpro['postal_code'] ?? '' ) === 0;
                self::inc( $report, $same ? 'both_same' : 'both_differ' );
                if ( $mode === 'prefer_acf' ) {
                    $source = 'acf';
                } elseif ( $mode === 'prefer_pmpro' ) {
                    $source = 'pmpro';
                } else {
                    // prefer_recent: PMPro billing meta is rewritten at every
                    // checkout, so a recent successful order means it is the
                    // fresher record; otherwise the member-edited profile wins.
                    $last_ts = isset( $last_orders[ $uid ] ) ? My_IAPSNJ_Dates::mysql_utc_to_ts( $last_orders[ $uid ]->last_ts ) : 0;
                    $source  = ( $last_ts > 0 && ( time() - $last_ts ) <= $fresh ) ? 'pmpro' : 'acf';
                }
            }
            $chosen = $source === 'acf' ? $acf : $pmpro;
            // Fill gaps from the other side (e.g. phone from PMPro when ACF has none).
            $other = $source === 'acf' ? $pmpro : $acf;
            foreach ( $other as $k => $v ) {
                if ( empty( $chosen[ $k ] ) ) {
                    $chosen[ $k ] = $v;
                }
            }
            self::inc( $report, 'chose_' . $source );

            $sub = self::ensure_subscriber( $uid, $dry, $args, $report, $cache );
            if ( ! $sub instanceof Subscriber ) {
                if ( ! $dry || empty( $args['create_missing_contacts'] ) ) {
                    self::inc( $report, 'skipped_no_contact' );
                }
                continue;
            }

            $changes = [];
            foreach ( [ 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'phone' ] as $field ) {
                $new = trim( (string) ( $chosen[ $field ] ?? '' ) );
                $cur = trim( (string) $sub->{ $field } );
                if ( $field === 'country' && $new === '' && $cur === '' ) {
                    $new = (string) $args['default_country'];
                }
                if ( $new === '' || strcasecmp( $new, $cur ) === 0 ) {
                    continue;
                }
                if ( $mode === 'fill_empty' && $cur !== '' ) {
                    continue;
                }
                $changes[ $field ] = [ 'from' => $cur, 'to' => $new ];
            }
            if ( ! $changes ) {
                self::inc( $report, 'contacts_unchanged' );
                continue;
            }
            self::inc( $report, 'contacts_updated' );
            self::inc( $report, 'fields_written', count( $changes ) );
            self::row( $report, [ 'user_id' => $uid, 'email' => $user->user_email, 'source' => $source, 'changes' => $changes ] );
            if ( ! $dry ) {
                foreach ( $changes as $field => $c ) {
                    $sub->{ $field } = $c['to'];
                }
                $sub->save();
            }
        }
        $report['has_more']    = count( $users ) === $limit;
        $report['next_offset'] = $offset + count( $users );
    }

    // -----------------------------------------------------------------------
    // Step: backfill_year_tags
    // -----------------------------------------------------------------------

    private static function step_backfill_year_tags( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        $t        = self::tables();
        $statuses = array_values( array_filter( array_map( 'sanitize_key', (array) $args['order_statuses'] ) ) ) ?: [ 'success' ];
        $ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t['orders']}` WHERE status IN ({$ph})", ...$statuses ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, membership_id, status, total, `timestamp` FROM `{$t['orders']}` WHERE status IN ({$ph}) ORDER BY id ASC LIMIT %d OFFSET %d",
            ...array_merge( $statuses, [ $limit, $offset ] )
        ) );

        $levels    = self::levels();
        $from_year = (int) $args['from_year'];
        $cache     = [];
        $tags_for  = []; // subscriber id → [slug…]
        $legacy    = []; // subscriber id → [level id…]
        $subs      = [];

        foreach ( (array) $orders as $o ) {
            $report['processed']++;
            self::inc( $report, 'orders' );
            // 'site': the stored datetime is already local — take its year as
            // written. 'utc': convert the instant to the site timezone first.
            if ( $args['order_tz'] === 'site' ) {
                $year = (int) substr( (string) $o->timestamp, 0, 4 );
            } else {
                $ts   = My_IAPSNJ_Dates::mysql_utc_to_ts( (string) $o->timestamp );
                $year = $ts ? My_IAPSNJ_Dates::year_of_ts( $ts ) : (int) substr( (string) $o->timestamp, 0, 4 );
            }
            if ( $year < $from_year ) {
                self::inc( $report, 'skipped_before_from_year' );
                continue;
            }
            if ( (float) $o->total <= 0 && empty( $args['include_zero'] ) ) {
                self::inc( $report, 'skipped_zero_total' );
                continue;
            }
            $uid = (int) $o->user_id;
            if ( $uid <= 0 ) {
                self::inc( $report, 'orders_without_user' );
                continue;
            }
            $sub = self::ensure_subscriber( $uid, $dry, $args, $report, $cache );
            $lid = (int) $o->membership_id;
            if ( ! isset( $levels[ $lid ] ) ) {
                self::inc_map( $report, 'orphan_level_orders', $lid );
            }
            self::inc_map( $report, 'tags_by_year', $year );
            if ( ! $sub instanceof Subscriber ) {
                // Dry run with a to-be-created contact, or unmatched user.
                self::row( $report, [ 'order_id' => (int) $o->id, 'user_id' => $uid, 'year' => $year, 'level' => $levels[ $lid ] ?? ( 'ORPHAN ' . $lid ), 'contact' => $dry ? '(would create)' : '(none)' ] );
                continue;
            }
            $subs[ $sub->id ]       = $sub;
            $tags_for[ $sub->id ][] = My_IAPSNJ_Schema::paid_tag_slug( $year );
            if ( ! isset( $levels[ $lid ] ) ) {
                $legacy[ $sub->id ][] = $lid;
            }
            self::row( $report, [ 'order_id' => (int) $o->id, 'user_id' => $uid, 'contact' => (int) $sub->id, 'year' => $year, 'level' => $levels[ $lid ] ?? ( 'ORPHAN ' . $lid ) ] );
        }

        foreach ( $tags_for as $sid => $slugs ) {
            $slugs = array_values( array_unique( $slugs ) );
            self::inc( $report, 'tag_attachments', count( $slugs ) );
            if ( $dry ) {
                continue;
            }
            $ids = My_IAPSNJ_Schema::tag_ids( $slugs );
            $subs[ $sid ]->attachTags( array_values( $ids ) );
            if ( ! empty( $legacy[ $sid ] ) ) {
                $existing = My_IAPSNJ_Schema::field( $subs[ $sid ], My_IAPSNJ_Schema::FIELD_LEGACY_LEVEL );
                $all      = array_values( array_unique( array_filter( array_merge( preg_split( '/\s*,\s*/', $existing ), array_map( 'strval', $legacy[ $sid ] ) ) ) ) );
                My_IAPSNJ_Schema::set_fields( $subs[ $sid ], [ My_IAPSNJ_Schema::FIELD_LEGACY_LEVEL => implode( ', ', $all ) ] );
            }
        }
        if ( ! empty( $report['counts']['orphan_level_orders'] ) ) {
            $report['warnings'][] = 'Orders on deleted PMPro levels were tagged by year and the level id was recorded in legacy_pmpro_level: ' . wp_json_encode( $report['counts']['orphan_level_orders'] );
        }
        $report['has_more']    = count( (array) $orders ) === $limit;
        $report['next_offset'] = $offset + count( (array) $orders );
    }

    // -----------------------------------------------------------------------
    // Step: migrate_comped (Honorary / Lifetime from memberships_users)
    // -----------------------------------------------------------------------

    private static function step_migrate_comped( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        $t   = self::tables();
        $map = $args['level_map'];
        $ids = self::comped_level_ids( $map );
        if ( ! $ids ) {
            $report['warnings'][] = 'No level maps to Honorary or Lifetime. Pass --level-map (e.g. 2:Lifetime,6:Honorary).';
            return;
        }
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t['mu']}` WHERE status = 'active' AND membership_id IN ({$ph})", ...$ids ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, membership_id, startdate FROM `{$t['mu']}` WHERE status = 'active' AND membership_id IN ({$ph}) ORDER BY id ASC LIMIT %d OFFSET %d",
            ...array_merge( $ids, [ $limit, $offset ] )
        ) );
        $cache = [];
        foreach ( (array) $rows as $r ) {
            $report['processed']++;
            $type = $map[ (int) $r->membership_id ];
            self::inc_map( $report, 'members_by_type', $type );
            $sub = self::ensure_subscriber( (int) $r->user_id, $dry, $args, $report, $cache );
            if ( ! $sub instanceof Subscriber ) {
                self::row( $report, [ 'user_id' => (int) $r->user_id, 'type' => $type, 'contact' => $dry ? '(would create)' : '(none)' ] );
                continue;
            }
            $slug = $type === My_IAPSNJ_Schema::TYPE_HONORARY ? My_IAPSNJ_Schema::TAG_HONORARY : My_IAPSNJ_Schema::TAG_LIFETIME;
            self::row( $report, [ 'user_id' => (int) $r->user_id, 'contact' => (int) $sub->id, 'email' => $sub->email, 'type' => $type, 'tag' => $slug, 'paid_through' => 'null' ] );
            self::inc( $report, 'contacts_updated' );
            if ( $dry ) {
                continue;
            }
            $sub->attachTags( array_values( My_IAPSNJ_Schema::tag_ids( [ $slug ] ) ) );
            My_IAPSNJ_Schema::set_fields( $sub, [
                My_IAPSNJ_Schema::FIELD_MEMBER_TYPE  => $type,
                My_IAPSNJ_Schema::FIELD_PAID_THROUGH => '', // null by contract — never a far-future date
            ] );
        }
        $report['has_more']    = count( (array) $rows ) === $limit;
        $report['next_offset'] = $offset + count( (array) $rows );
    }

    // -----------------------------------------------------------------------
    // Step: set_member_state (Regular / Associate from current level)
    // -----------------------------------------------------------------------

    private static function step_set_member_state( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        $t      = self::tables();
        $map    = $args['level_map'];
        $levels = self::levels();
        $comped = self::comped_level_ids( $map );
        $mu_statuses = array_values( array_filter( array_map( 'sanitize_key', (array) $args['mu_statuses'] ) ) ) ?: [ 'active' ];
        $sph    = implode( ',', array_fill( 0, count( $mu_statuses ), '%s' ) );
        $not    = $comped ? ' AND membership_id NOT IN (' . implode( ',', array_map( 'intval', $comped ) ) . ')' : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t['mu']}` WHERE status IN ({$sph}){$not}", ...$mu_statuses ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, membership_id, status, startdate, enddate FROM `{$t['mu']}` WHERE status IN ({$sph}){$not} ORDER BY id ASC LIMIT %d OFFSET %d",
            ...array_merge( $mu_statuses, [ $limit, $offset ] )
        ) );
        if ( ! $rows ) {
            return;
        }
        $uids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'user_id' ) ) ) );
        $uph  = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last_paid = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MAX(`timestamp`) AS last_ts FROM `{$t['orders']}` WHERE status = 'success' AND total > 0 AND user_id IN ({$uph}) GROUP BY user_id", ...$uids ), OBJECT_K );

        $cache = [];
        foreach ( $rows as $r ) {
            $report['processed']++;
            $lid  = (int) $r->membership_id;
            $type = $map[ $lid ] ?? My_IAPSNJ_Schema::TYPE_REGULAR;
            $is_orphan = ! isset( $levels[ $lid ] );
            self::inc_map( $report, 'members_by_type', $type );

            // paid_through from PMPro enddate, else from the last paid order year.
            $paid_through = '';
            $derivation   = 'enddate';
            $end          = (string) $r->enddate;
            if ( $end !== '' && strpos( $end, '0000-00-00' ) !== 0 ) {
                $paid_through = $args['order_tz'] === 'site'
                    ? substr( $end, 0, 10 )
                    : wp_date( 'Y-m-d', My_IAPSNJ_Dates::mysql_utc_to_ts( $end ) );
            } elseif ( isset( $last_paid[ (int) $r->user_id ] ) ) {
                $last_ts_raw = (string) $last_paid[ (int) $r->user_id ]->last_ts;
                if ( $args['order_tz'] === 'site' ) {
                    $year = (int) substr( $last_ts_raw, 0, 4 );
                } else {
                    $ts   = My_IAPSNJ_Dates::mysql_utc_to_ts( $last_ts_raw );
                    $year = $ts ? My_IAPSNJ_Dates::year_of_ts( $ts ) : 0;
                }
                if ( $year > 1900 ) {
                    $paid_through = $year . '-12-31';
                    $derivation   = 'last_order_year';
                    self::inc( $report, 'paid_through_derived_from_orders' );
                }
            }
            if ( $paid_through === '' ) {
                $derivation = 'unknown';
                self::inc( $report, 'paid_through_unknown' );
            }

            $sub = self::ensure_subscriber( (int) $r->user_id, $dry, $args, $report, $cache );
            if ( ! $sub instanceof Subscriber ) {
                self::row( $report, [ 'user_id' => (int) $r->user_id, 'level' => $levels[ $lid ] ?? ( 'ORPHAN ' . $lid ), 'type' => $type, 'paid_through' => $paid_through, 'contact' => $dry ? '(would create)' : '(none)' ] );
                continue;
            }

            $cur_type = My_IAPSNJ_Schema::field( $sub, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE );
            $cur_pt   = My_IAPSNJ_Schema::field( $sub, My_IAPSNJ_Schema::FIELD_PAID_THROUGH );
            $fields   = [];
            // Never downgrade Lifetime / Honorary set by migrate_comped.
            if ( My_IAPSNJ_Schema::member_type_rank( $cur_type ) < My_IAPSNJ_Schema::member_type_rank( $type ) || $cur_type === '' ) {
                $fields[ My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ] = $type;
            }
            if ( ! My_IAPSNJ_Schema::is_comped_type( $cur_type ) ) {
                $new_pt = My_IAPSNJ_Dates::ymd_max( $cur_pt, $paid_through );
                if ( $new_pt !== '' && $new_pt !== My_IAPSNJ_Dates::ymd( $cur_pt ) ) {
                    $fields[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ] = $new_pt;
                }
            }
            if ( $is_orphan ) {
                $existing = My_IAPSNJ_Schema::field( $sub, My_IAPSNJ_Schema::FIELD_LEGACY_LEVEL );
                $all      = array_values( array_unique( array_filter( array_merge( preg_split( '/\s*,\s*/', $existing ), [ (string) $lid ] ) ) ) );
                $fields[ My_IAPSNJ_Schema::FIELD_LEGACY_LEVEL ] = implode( ', ', $all );
                self::inc_map( $report, 'orphan_level_members', $lid );
            }
            if ( ! $fields ) {
                self::inc( $report, 'contacts_unchanged' );
                continue;
            }
            self::inc( $report, 'contacts_updated' );
            self::row( $report, [ 'user_id' => (int) $r->user_id, 'contact' => (int) $sub->id, 'email' => $sub->email, 'level' => $levels[ $lid ] ?? ( 'ORPHAN ' . $lid ), 'derivation' => $derivation, 'changes' => $fields ] );
            if ( ! $dry ) {
                My_IAPSNJ_Schema::set_fields( $sub, $fields );
            }
        }
        $report['has_more']    = count( $rows ) === $limit;
        $report['next_offset'] = $offset + count( $rows );
    }

    // -----------------------------------------------------------------------
    // Step: verify_logins (read-only)
    // -----------------------------------------------------------------------

    private static function step_verify_logins( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $report['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login, user_email, user_pass FROM {$wpdb->users} ORDER BY ID ASC LIMIT %d OFFSET %d", $limit, $offset ) );
        foreach ( (array) $rows as $u ) {
            $report['processed']++;
            self::inc( $report, 'users' );
            $problems = [];
            if ( trim( (string) $u->user_login ) === '' ) {
                self::inc( $report, 'missing_login' );
                $problems[] = 'missing user_login';
            }
            if ( ! is_email( (string) $u->user_email ) ) {
                self::inc( $report, 'invalid_email' );
                $problems[] = 'missing/invalid user_email';
            }
            $pass = (string) $u->user_pass;
            if ( $pass === '' || ! preg_match( '/^(\$P\$|\$H\$|\$2[axyb]\$|\$wp\$|\$argon2)/', $pass ) ) {
                self::inc( $report, 'unhashed_password' );
                $problems[] = $pass === '' ? 'empty password' : 'password not a recognised hash';
            }
            if ( $problems ) {
                self::row( $report, [ 'user_id' => (int) $u->ID, 'login' => (string) $u->user_login, 'email' => (string) $u->user_email, 'problems' => implode( '; ', $problems ) ] );
            }
        }
        foreach ( [ 'missing_login', 'invalid_email', 'unhashed_password' ] as $k ) {
            $report['counts'][ $k ] = (int) ( $report['counts'][ $k ] ?? 0 );
        }
        if ( $offset === 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $report['counts']['duplicate_emails'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT LOWER(user_email) e, COUNT(*) n FROM {$wpdb->users} WHERE user_email <> '' GROUP BY LOWER(user_email) HAVING n > 1) d" );
            if ( ! empty( $args['expected'] ) ) {
                $report['counts']['expected_users'] = (int) $args['expected'];
                if ( (int) $args['expected'] !== (int) $report['total'] ) {
                    $report['warnings'][] = sprintf( 'Expected %d users, table has %d.', (int) $args['expected'], (int) $report['total'] );
                }
            }
        }
        $report['has_more']    = count( (array) $rows ) === $limit;
        $report['next_offset'] = $offset + count( (array) $rows );
    }

    // -----------------------------------------------------------------------
    // Step: reconciliation (read-only)
    // -----------------------------------------------------------------------

    private static function step_reconciliation( array &$report, bool $dry, int $offset, int $limit, array $args ): void {
        global $wpdb;
        $t      = self::tables();
        $levels = self::levels();
        $meta   = $wpdb->prefix . 'fc_subscriber_meta';
        $subs   = $wpdb->prefix . 'fc_subscribers';
        $pivot  = $wpdb->prefix . 'fc_subscriber_pivot';
        $tags   = $wpdb->prefix . 'fc_tags';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $report['counts']['wp_users']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
        $report['counts']['crm_contacts'] = (int) Subscriber::count();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['crm_contacts_linked_to_users'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$subs}` WHERE user_id IS NOT NULL AND user_id > 0" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['pmpro_active_members'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM `{$t['mu']}` WHERE status = 'active'" );

        // Per level (PMPro) vs per type (CRM).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT membership_id, COUNT(DISTINCT user_id) n FROM `{$t['mu']}` WHERE status = 'active' GROUP BY membership_id" );
        $by_level = [];
        foreach ( (array) $rows as $r ) {
            $by_level[ ( $levels[ (int) $r->membership_id ] ?? 'ORPHAN ' . $r->membership_id ) . ' (' . (int) $r->membership_id . ')' ] = (int) $r->n;
        }
        $report['sections']['pmpro_active_by_level'] = $by_level;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT `value` v, COUNT(*) n FROM `{$meta}` WHERE object_type = 'custom_field' AND `key` = %s GROUP BY `value`", My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ) );
        $by_type = [];
        foreach ( (array) $rows as $r ) {
            $by_type[ (string) $r->v ] = (int) $r->n;
        }
        $report['sections']['crm_member_type'] = $by_type;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tg.slug, COUNT(p.id) n FROM `{$tags}` tg LEFT JOIN `{$pivot}` p ON p.object_id = tg.id AND p.object_type = %s WHERE tg.slug LIKE %s OR tg.slug IN (%s, %s, %s, %s) GROUP BY tg.slug ORDER BY tg.slug",
            'FluentCrm\App\Models\Tag',
            $wpdb->esc_like( My_IAPSNJ_Schema::TAG_PAID_PREFIX ) . '%',
            My_IAPSNJ_Schema::TAG_HONORARY,
            My_IAPSNJ_Schema::TAG_LIFETIME,
            My_IAPSNJ_Schema::TAG_PENDING_CHECK,
            My_IAPSNJ_Schema::TAG_ABANDONED
        ) );
        $by_tag = [];
        foreach ( (array) $rows as $r ) {
            $by_tag[ (string) $r->slug ] = (int) $r->n;
        }
        $report['sections']['crm_tags'] = $by_tag;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $paid_through_set = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$meta}` WHERE object_type = 'custom_field' AND `key` = %s AND `value` <> ''", My_IAPSNJ_Schema::FIELD_PAID_THROUGH ) );
        $report['counts']['crm_paid_through_set'] = $paid_through_set;

        // Addresses before / after.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $acf_addr   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", self::ACF_ADDRESS['address_line_1'] ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $pmpro_addr = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", self::PMPRO_ADDRESS['address_line_1'] ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $any_addr   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) AND meta_value <> ''", self::ACF_ADDRESS['address_line_1'], self::PMPRO_ADDRESS['address_line_1'] ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $crm_addr_linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$subs}` WHERE address_line_1 <> '' AND user_id IS NOT NULL AND user_id > 0" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $crm_addr_total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$subs}` WHERE address_line_1 <> ''" );
        $report['sections']['addresses'] = [
            'users_with_acf_address'         => $acf_addr,
            'users_with_pmpro_billing'       => $pmpro_addr,
            'users_with_any_address_before'  => $any_addr,
            'crm_linked_contacts_with_address' => $crm_addr_linked,
            'crm_all_contacts_with_address'  => $crm_addr_total,
        ];

        // Emails / duplicates.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $no_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email IS NULL OR user_email = ''" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dup_crm  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT LOWER(email) e, COUNT(*) n FROM `{$subs}` GROUP BY LOWER(email) HAVING n > 1) d" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dup_uid  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT user_id, COUNT(*) n FROM `{$subs}` WHERE user_id IS NOT NULL AND user_id > 0 GROUP BY user_id HAVING n > 1) d" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $missing  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$subs}` s LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id WHERE s.user_id IS NOT NULL AND s.user_id > 0 AND u.ID IS NULL" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $unlinked_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} u LEFT JOIN `{$subs}` s ON s.user_id = u.ID LEFT JOIN `{$subs}` e ON LOWER(e.email) = LOWER(u.user_email) WHERE s.id IS NULL AND e.id IS NULL" );
        $report['sections']['integrity'] = [
            'users_without_email'                => $no_email,
            'users_without_crm_contact'          => $unlinked_users,
            'crm_duplicate_emails'               => $dup_crm,
            'crm_contacts_sharing_a_user_id'     => $dup_uid,
            'crm_contacts_pointing_at_missing_user' => $missing,
        ];

        // Member number presence (from PMPro-era ACF → CRM sync).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $report['counts']['crm_contacts_with_member_number'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$meta}` WHERE object_type = 'custom_field' AND `key` = %s AND `value` <> ''", My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ) );

        $report['processed'] = 1;
    }

    // -----------------------------------------------------------------------
    // Export: PMPro order history → CSV
    // -----------------------------------------------------------------------

    /**
     * Full order history for the treasurer's records. Card fields
     * (accountnumber, expiration, tokens) are excluded on purpose.
     *
     * @return array{rows:int,path:string}|WP_Error
     */
    public static function export_orders_csv( string $path ) {
        global $wpdb;
        if ( ! self::tables_exist() ) {
            return new WP_Error( 'no_tables', 'PMPro tables not found.' );
        }
        $t  = self::tables();
        $fh = fopen( $path, 'w' );
        if ( ! $fh ) {
            return new WP_Error( 'cannot_write', 'Cannot write to ' . $path );
        }
        $skip   = [ 'accountnumber', 'expirationmonth', 'expirationyear', 'paypal_token', 'session_id', 'cardtype' ];
        $offset = 0;
        $limit  = 500;
        $rows   = 0;
        $header = false;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $batch = $wpdb->get_results( $wpdb->prepare(
                "SELECT o.*, u.user_email, u.display_name, l.name AS level_name
                 FROM `{$t['orders']}` o
                 LEFT JOIN {$wpdb->users} u ON u.ID = o.user_id
                 LEFT JOIN `{$t['levels']}` l ON l.id = o.membership_id
                 ORDER BY o.id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ), ARRAY_A );
            foreach ( (array) $batch as $r ) {
                foreach ( $skip as $k ) {
                    unset( $r[ $k ] );
                }
                if ( ! $header ) {
                    fputcsv( $fh, array_keys( $r ) );
                    $header = true;
                }
                fputcsv( $fh, array_map( function ( $v ) {
                    return is_null( $v ) ? '' : (string) $v;
                }, $r ) );
                $rows++;
            }
            $offset += $limit;
        } while ( count( (array) $batch ) === $limit );
        fclose( $fh );
        return [ 'rows' => $rows, 'path' => $path ];
    }
}
