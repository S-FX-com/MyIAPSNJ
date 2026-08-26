<?php
/**
 * My_IAPSNJ_CLI
 *
 * WP-CLI front end for the migration toolkit and schema helpers.
 *
 *   wp iapsnj census [--level-map=<map>]
 *   wp iapsnj migrate <step|all> [--dry-run] [--level-map=<map>] [--from-year=<y>]
 *                    [--order-statuses=<list>] [--order-tz=<utc|site>]
 *                    [--address-mode=<mode>] [--pmpro-fresh-days=<n>]
 *                    [--limit=<n>] [--report=<file>] [--no-create-contacts]
 *   wp iapsnj export-orders --file=<path>
 *   wp iapsnj verify-logins [--expected=<n>]
 *   wp iapsnj reconcile
 *   wp iapsnj crm-schema [--years=<from-to>]
 *   wp iapsnj offline-label --label=<text> [--instructions=<html>]
 *
 * Every migrate step supports --dry-run and prints a reviewable report; the
 * same steps are available from My IAPSNJ → Migration in wp-admin for sites
 * without shell access.
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_CLI {

    public static function register(): void {
        if ( class_exists( 'WP_CLI' ) ) {
            WP_CLI::add_command( 'iapsnj', self::class );
        }
    }

    /**
     * Phase 1 census: levels, orphaned level IDs, Honorary / Lifetime counts.
     *
     * ## OPTIONS
     *
     * [--level-map=<map>]
     * : PMPro level id → member type, e.g. "1:Regular,4:Associate,2:Lifetime,6:Honorary".
     *
     * [--format=<format>]
     * : table (default) or json.
     */
    public function census( $args, $assoc ) {
        $report = My_IAPSNJ_Migration::run( 'census', true, 0, 0, self::parse_args( $assoc ) );
        $this->print_report( $report, $assoc );
    }

    /**
     * Run a migration step (or all of them) with paging.
     *
     * ## OPTIONS
     *
     * <step>
     * : census | link_subscribers | consolidate_addresses | backfill_year_tags | migrate_comped | set_member_state | verify_logins | reconciliation | all
     *
     * [--dry-run]
     * : Report what would change without writing anything.
     *
     * [--level-map=<map>]
     * [--from-year=<year>]
     * [--order-statuses=<list>]
     * [--order-tz=<tz>]
     * [--address-mode=<mode>]
     * : prefer_recent (default) | prefer_acf | prefer_pmpro | fill_empty
     * [--pmpro-fresh-days=<n>]
     * [--include-zero]
     * [--no-create-contacts]
     * [--limit=<n>]
     * : Rows per page (default 200).
     * [--report=<file>]
     * : Write the full row-level report as JSON to this file.
     * [--format=<format>]
     */
    public function migrate( $args, $assoc ) {
        $step  = (string) ( $args[0] ?? '' );
        $steps = $step === 'all' ? My_IAPSNJ_Migration::STEPS : [ $step ];
        foreach ( $steps as $s ) {
            if ( ! in_array( $s, My_IAPSNJ_Migration::STEPS, true ) ) {
                WP_CLI::error( 'Unknown step: ' . $s . '. Valid: ' . implode( ', ', My_IAPSNJ_Migration::STEPS ) . ', all' );
            }
        }
        $dry     = ! empty( $assoc['dry-run'] );
        $limit   = max( 1, (int) ( $assoc['limit'] ?? 200 ) );
        $margs   = self::parse_args( $assoc );
        $reports = [];

        foreach ( $steps as $s ) {
            WP_CLI::log( sprintf( '== %s %s', $s, $dry ? '(dry run)' : '(APPLY)' ) );
            $agg = null;
            $offset = 0;
            do {
                $page = My_IAPSNJ_Migration::run( $s, $dry, $offset, $limit, $margs );
                if ( ! empty( $page['fatal'] ) ) {
                    WP_CLI::error( $page['fatal'] );
                }
                $agg    = My_IAPSNJ_Migration::merge_reports( $agg, $page );
                $offset = (int) $page['next_offset'];
                if ( ! empty( $page['total'] ) ) {
                    WP_CLI::log( sprintf( '   … %d / %d', min( $offset, (int) $page['total'] ), (int) $page['total'] ) );
                }
            } while ( ! empty( $page['has_more'] ) );
            $reports[ $s ] = $agg;
            $this->print_report( $agg, $assoc );
        }

        if ( ! empty( $assoc['report'] ) ) {
            file_put_contents( (string) $assoc['report'], wp_json_encode( $reports, JSON_PRETTY_PRINT ) );
            WP_CLI::success( 'Report written to ' . $assoc['report'] );
        }
    }

    /**
     * Export the full PMPro order history to CSV (the treasurer's record).
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Destination CSV path.
     */
    public function export_orders( $args, $assoc ) {
        $file = (string) ( $assoc['file'] ?? '' );
        if ( $file === '' ) {
            WP_CLI::error( '--file=<path> is required.' );
        }
        $result = My_IAPSNJ_Migration::export_orders_csv( $file );
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        WP_CLI::success( sprintf( '%d orders written to %s', (int) $result['rows'], $result['path'] ) );
    }

    /**
     * Verify every WordPress login survived: user_login, user_email, hashed password.
     *
     * ## OPTIONS
     *
     * [--expected=<n>]
     * : Expected user count (e.g. the production count at clone time).
     */
    public function verify_logins( $args, $assoc ) {
        $agg = null;
        $offset = 0;
        $margs = self::parse_args( $assoc );
        do {
            $page   = My_IAPSNJ_Migration::run( 'verify_logins', true, $offset, 500, $margs );
            $agg    = My_IAPSNJ_Migration::merge_reports( $agg, $page );
            $offset = (int) $page['next_offset'];
        } while ( ! empty( $page['has_more'] ) );
        $this->print_report( $agg, $assoc );
        $c = $agg['counts'];
        $bad = (int) $c['missing_login'] + (int) $c['invalid_email'] + (int) $c['unhashed_password'];
        if ( $bad > 0 ) {
            WP_CLI::error( sprintf( '%d login problem(s) found.', $bad ) );
        }
        if ( ! empty( $assoc['expected'] ) && (int) $assoc['expected'] !== (int) $c['users'] ) {
            WP_CLI::error( sprintf( 'Expected %d users, found %d.', (int) $assoc['expected'], (int) $c['users'] ) );
        }
        WP_CLI::success( sprintf( 'All %d logins intact.', (int) $c['users'] ) );
    }

    /**
     * Before/after reconciliation totals.
     */
    public function reconcile( $args, $assoc ) {
        $this->print_report( My_IAPSNJ_Migration::run( 'reconciliation', true, 0, 0, self::parse_args( $assoc ) ), $assoc );
    }

    /**
     * Create missing FluentCRM tags and custom fields.
     *
     * ## OPTIONS
     *
     * [--years=<range>]
     * : Paid-YYYY tags to create, e.g. 2024-2031 (default 2024 to current year + 5).
     */
    public function crm_schema( $args, $assoc ) {
        $range = (string) ( $assoc['years'] ?? ( '2024-' . ( (int) wp_date( 'Y' ) + 5 ) ) );
        $years = self::parse_year_range( $range );
        $r     = My_IAPSNJ_Schema::ensure_crm_schema( $years );
        WP_CLI::log( 'Tags created:   ' . ( $r['tags_created'] ? implode( ', ', $r['tags_created'] ) : '(none, all present)' ) );
        WP_CLI::log( 'Fields created: ' . ( $r['fields_created'] ? implode( ', ', $r['fields_created'] ) : '(none, all present)' ) );
        WP_CLI::success( 'CRM schema is complete.' );
    }

    /**
     * Rename FluentCart's offline payment method at checkout.
     *
     * ## OPTIONS
     *
     * --label=<text>
     * : e.g. "Pay by Check"
     *
     * [--instructions=<html>]
     * : Customer-facing instructions (mailing address, "write your member number on the memo line").
     */
    public function offline_label( $args, $assoc ) {
        $label = (string) ( $assoc['label'] ?? '' );
        if ( $label === '' ) {
            WP_CLI::error( '--label is required.' );
        }
        $r = My_IAPSNJ_Membership::apply_offline_labels( $label, (string) ( $assoc['instructions'] ?? '' ) );
        if ( is_wp_error( $r ) ) {
            WP_CLI::error( $r->get_error_message() );
        }
        WP_CLI::success( 'Offline payment method now labelled "' . $label . '".' );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function parse_args( array $assoc ): array {
        $out = [];
        if ( ! empty( $assoc['level-map'] ) ) {
            $out['level_map'] = My_IAPSNJ_Migration::parse_level_map( (string) $assoc['level-map'] );
        }
        if ( ! empty( $assoc['from-year'] ) ) {
            $out['from_year'] = (int) $assoc['from-year'];
        }
        if ( ! empty( $assoc['order-statuses'] ) ) {
            $out['order_statuses'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['order-statuses'] ) ) ) );
        }
        if ( ! empty( $assoc['order-tz'] ) ) {
            $out['order_tz'] = (string) $assoc['order-tz'];
        }
        if ( ! empty( $assoc['address-mode'] ) ) {
            $out['address_mode'] = (string) $assoc['address-mode'];
        }
        if ( ! empty( $assoc['pmpro-fresh-days'] ) ) {
            $out['pmpro_fresh_days'] = (int) $assoc['pmpro-fresh-days'];
        }
        if ( isset( $assoc['include-zero'] ) ) {
            $out['include_zero'] = true;
        }
        if ( isset( $assoc['no-create-contacts'] ) ) {
            $out['create_missing_contacts'] = false;
        }
        if ( ! empty( $assoc['expected'] ) ) {
            $out['expected'] = (int) $assoc['expected'];
        }
        return $out;
    }

    private static function parse_year_range( string $range ): array {
        if ( preg_match( '/^(\d{4})\s*-\s*(\d{4})$/', trim( $range ), $m ) ) {
            return range( (int) $m[1], (int) $m[2] );
        }
        return array_values( array_filter( array_map( 'intval', explode( ',', $range ) ) ) );
    }

    private function print_report( array $report, array $assoc ): void {
        if ( ( $assoc['format'] ?? 'table' ) === 'json' ) {
            WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
            return;
        }
        $rows = [];
        foreach ( (array) ( $report['counts'] ?? [] ) as $k => $v ) {
            $rows[] = [ 'metric' => $k, 'value' => is_array( $v ) ? wp_json_encode( $v ) : (string) $v ];
        }
        if ( $rows ) {
            WP_CLI\Utils\format_items( 'table', $rows, [ 'metric', 'value' ] );
        }
        foreach ( (array) ( $report['sections'] ?? [] ) as $title => $table ) {
            if ( ! is_array( $table ) || ! $table ) {
                continue;
            }
            WP_CLI::log( '-- ' . $title );
            $first = reset( $table );
            if ( is_array( $first ) ) {
                WP_CLI\Utils\format_items( 'table', array_values( $table ), array_keys( $first ) );
            } else {
                foreach ( $table as $k => $v ) {
                    WP_CLI::log( sprintf( '   %s: %s', $k, is_array( $v ) ? wp_json_encode( $v ) : (string) $v ) );
                }
            }
        }
        $sample = (array) ( $report['rows'] ?? [] );
        if ( $sample ) {
            WP_CLI::log( sprintf( '-- sample rows (%d shown)', count( $sample ) ) );
            $first = reset( $sample );
            if ( is_array( $first ) ) {
                $flat = array_map( function ( $r ) {
                    foreach ( $r as $k => $v ) {
                        if ( is_array( $v ) ) {
                            $r[ $k ] = wp_json_encode( $v );
                        }
                    }
                    return $r;
                }, array_values( $sample ) );
                WP_CLI\Utils\format_items( 'table', $flat, array_keys( $first ) );
            }
        }
        foreach ( (array) ( $report['warnings'] ?? [] ) as $w ) {
            WP_CLI::warning( $w );
        }
        foreach ( (array) ( $report['errors'] ?? [] ) as $e ) {
            WP_CLI::warning( 'ERROR: ' . $e );
        }
    }
}
