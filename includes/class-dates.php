<?php
/**
 * My_IAPSNJ_Dates
 *
 * Every date helper the plugin uses, in one place, so the timezone rules
 * (audit item P1-4) are applied consistently:
 *
 *  - A calendar date *string* (paid_through = "2027-12-31") has no timezone.
 *    Round-trip it through UTC (strtotime + gmdate) so 12/31 stays 12/31 in
 *    every timezone. Never wp_date() it and never date() it.
 *  - A real instant (FluentCart created_at, a WP-Cron timestamp) is rendered
 *    in the site timezone with wp_date().
 *  - Bare date() is never used: it formats in the UTC process timezone and
 *    silently rolls a 12/31 expiration back to 12/30 west of Greenwich.
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Dates {

    /**
     * Validate a Y-m-d string. Returns the canonical string or '' if invalid.
     */
    public static function ymd( $value ): string {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return '';
        }
        $dt = \DateTime::createFromFormat( 'Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
        if ( $dt && $dt->format( 'Y-m-d' ) === $value ) {
            return $value;
        }
        $ts = strtotime( $value . ' UTC' );
        return $ts !== false ? gmdate( 'Y-m-d', $ts ) : '';
    }

    /**
     * Format a calendar date string for display. No timezone shift.
     */
    public static function ymd_display( $ymd, string $format = 'M j, Y' ): string {
        $ymd = self::ymd( $ymd );
        if ( $ymd === '' ) {
            return '';
        }
        $ts = strtotime( $ymd . ' 00:00:00 UTC' );
        return $ts !== false ? gmdate( $format, $ts ) : $ymd;
    }

    /**
     * Later of two Y-m-d strings ('' counts as "no date").
     */
    public static function ymd_max( $a, $b ): string {
        $a = self::ymd( $a );
        $b = self::ymd( $b );
        if ( $a === '' ) {
            return $b;
        }
        if ( $b === '' ) {
            return $a;
        }
        return strcmp( $a, $b ) >= 0 ? $a : $b;
    }

    /**
     * Convert a MySQL DATETIME stored in UTC (FluentCart convention) to a
     * Unix timestamp. Returns 0 when unparseable.
     */
    public static function mysql_utc_to_ts( $mysql ): int {
        $mysql = trim( (string) $mysql );
        if ( $mysql === '' || $mysql === '0000-00-00 00:00:00' ) {
            return 0;
        }
        $ts = strtotime( $mysql . ' UTC' );
        return $ts !== false ? (int) $ts : 0;
    }

    /**
     * Render a UTC MySQL DATETIME in the site timezone.
     */
    public static function mysql_utc_display( $mysql, string $format = 'M j, Y' ): string {
        $ts = self::mysql_utc_to_ts( $mysql );
        return $ts > 0 ? wp_date( $format, $ts ) : '';
    }

    /**
     * Whole days elapsed since a UTC MySQL DATETIME.
     */
    public static function days_since_utc( $mysql ): int {
        $ts = self::mysql_utc_to_ts( $mysql );
        if ( $ts <= 0 ) {
            return 0;
        }
        return (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
    }

    /**
     * Calendar year (site timezone) of a Unix timestamp.
     */
    public static function year_of_ts( int $ts ): int {
        return (int) wp_date( 'Y', $ts );
    }

    /**
     * Current date in the site timezone as Y-m-d.
     */
    public static function today(): string {
        return wp_date( 'Y-m-d' );
    }

    /**
     * Current UTC MySQL DATETIME (for our own table columns).
     */
    public static function now_utc(): string {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * True when the calendar date is strictly before today (site timezone).
     */
    public static function is_past( $ymd ): bool {
        $ymd = self::ymd( $ymd );
        return $ymd !== '' && strcmp( $ymd, self::today() ) < 0;
    }
}
