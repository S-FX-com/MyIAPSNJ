<?php
/**
 * Phone numbers: one formatting rule for everything the plugin writes to the
 * CRM, so checkout, migration and the bulk tool agree.
 *
 * - The contact's own Phone column is stored in E.164 (`+19084152478`), the
 *   value FluentCRM's phone input saves; FluentCRM shows it as
 *   "+1 908-415-2478" with the US flag.
 * - Text custom fields (Work phone, Alternate phone) are not formatted by
 *   FluentCRM, so they are stored ready to read: "+1 908-415-2478".
 *
 * A 10-digit number (or 11 digits starting with 1) is a US number and gets
 * +1. A number typed with a leading + and another country code is kept as
 * that international number. Anything else (extensions, letters, too few
 * digits) is left exactly as entered so nothing is lost.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Phone {

    /**
     * E.164 form, or the trimmed input when it cannot be read as a number.
     */
    public static function e164( string $value ): string {
        $value = trim( $value );
        if ( $value === '' || preg_match( '/[a-z]/i', $value ) ) {
            return $value;
        }
        $digits = (string) preg_replace( '/\D+/', '', $value );
        if ( strpos( $value, '+' ) === 0 ) {
            if ( strpos( $digits, '1' ) === 0 ) {
                return self::us( substr( $digits, 1 ) ) ?? $value;
            }
            $len = strlen( $digits );
            return ( $len >= 8 && $len <= 15 ) ? '+' . $digits : $value;
        }
        if ( strlen( $digits ) === 11 && strpos( $digits, '1' ) === 0 ) {
            $digits = substr( $digits, 1 );
        }
        return self::us( $digits ) ?? $value;
    }

    /**
     * Display form: "+1 908-415-2478" for US numbers, E.164 for other
     * countries, the trimmed input otherwise.
     */
    public static function display( string $value ): string {
        $e164 = self::e164( $value );
        if ( preg_match( '/^\+1([2-9]\d{2})([2-9]\d{2})(\d{4})$/', $e164, $m ) ) {
            return '+1 ' . $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return $e164;
    }

    /**
     * True when the value reads as a phone number (US or international).
     */
    public static function is_valid( string $value ): bool {
        // e164() returns unreadable input unchanged, which may itself look
        // like E.164 (e.g. "+13000000000"), so check the shape it produces:
        // a real US number, or another country code (never 0 or 1).
        $e164 = self::e164( $value );
        return (bool) ( preg_match( '/^\+1[2-9]\d{2}[2-9]\d{6}$/', $e164 ) || preg_match( '/^\+[2-9]\d{7,14}$/', $e164 ) );
    }

    /**
     * Bring the phone numbers already in the CRM to the same format: the
     * contact Phone column to E.164, the Work / Alternate phone custom
     * fields to "+1 908-415-2478". Values that cannot be read as a number
     * are left alone and reported so they can be fixed by hand.
     *
     * Writes go straight to the FluentCRM tables (no contact-updated hooks,
     * so no automations fire for a reformat). Apply handles up to $limit
     * changes per call; the admin page repeats until nothing is left.
     *
     * @return array{checked:int,to_change:int,changed:int,unreadable:int,samples:string[],unreadable_samples:string[]}
     */
    public static function normalize_contacts( bool $dry, int $limit = 500 ): array {
        global $wpdb;
        $subs = $wpdb->prefix . 'fc_subscribers';
        $meta = $wpdb->prefix . 'fc_subscriber_meta';
        $jobs = [];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( (array) $wpdb->get_results( "SELECT id, email, phone FROM {$subs} WHERE phone IS NOT NULL AND phone <> ''", ARRAY_A ) as $r ) {
            $jobs[] = [ 'table' => $subs, 'col' => 'phone', 'id' => (int) $r['id'], 'email' => (string) $r['email'], 'field' => 'Phone', 'from' => (string) $r['phone'], 'to' => self::e164( (string) $r['phone'] ) ];
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.`key`, m.`value`, s.email FROM {$meta} m INNER JOIN {$subs} s ON s.id = m.subscriber_id
             WHERE m.object_type = 'custom_field' AND m.`key` IN (%s, %s) AND m.`value` <> ''",
            My_IAPSNJ_Schema::FIELD_PHONE_WORK,
            My_IAPSNJ_Schema::FIELD_PHONE2
        ), ARRAY_A );
        // phpcs:enable
        foreach ( (array) $rows as $r ) {
            $jobs[] = [ 'table' => $meta, 'col' => 'value', 'id' => (int) $r['id'], 'email' => (string) $r['email'], 'field' => (string) $r['key'], 'from' => (string) $r['value'], 'to' => self::display( (string) $r['value'] ) ];
        }

        $out = [ 'checked' => count( $jobs ), 'to_change' => 0, 'changed' => 0, 'unreadable' => 0, 'samples' => [], 'unreadable_samples' => [] ];
        foreach ( $jobs as $job ) {
            if ( ! self::is_valid( $job['from'] ) ) {
                $out['unreadable']++;
                if ( count( $out['unreadable_samples'] ) < 25 ) {
                    $out['unreadable_samples'][] = $job['email'] . ' · ' . $job['field'] . ': ' . $job['from'];
                }
                continue;
            }
            if ( $job['to'] === $job['from'] ) {
                continue;
            }
            $out['to_change']++;
            if ( count( $out['samples'] ) < 25 ) {
                $out['samples'][] = $job['email'] . ' · ' . $job['field'] . ': ' . $job['from'] . ' → ' . $job['to'];
            }
            if ( ! $dry && $out['changed'] < $limit ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( $wpdb->update( $job['table'], [ $job['col'] => $job['to'] ], [ 'id' => $job['id'] ] ) !== false ) {
                    $out['changed']++;
                }
            }
        }
        return $out;
    }

    /** +1 and the 10 digits, or null when they are not a US number. */
    private static function us( string $digits ): ?string {
        // NANP: area code and exchange never start with 0 or 1.
        return preg_match( '/^[2-9]\d{2}[2-9]\d{6}$/', $digits ) ? '+1' . $digits : null;
    }
}
