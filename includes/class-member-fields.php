<?php
/**
 * My_IAPSNJ_Member_Fields
 *
 * Keeps the CRM's "Membership" group of custom fields (Member Number, Member
 * Status, Join Date, Expiration Date: what the contact screen in FluentCRM
 * shows) in step with the membership state this plugin owns.
 *
 * The plugin's own fields are member_type and paid_through (set by a payment,
 * a refund and the migration). The CRM also has the fields of the ACF era,
 * which nothing updated any more, so a member who joined or renewed showed an
 * empty Member Status and Expiration Date:
 *
 *  - expiration_date  ← paid_through (deleted for Lifetime / Honorary);
 *  - member_status    ← the option of that dropdown that names the member's
 *                       type ("Regular", "Associate Member" …); while the
 *                       membership has lapsed, an Expired / Lapsed / Inactive
 *                       option when the list has one; a list about standing
 *                       (Active / Expired) gets that. No option fits: the field
 *                       is left alone (Settings → Configurations says so);
 *  - member_number    ← the WordPress user id, when empty (it always was just
 *                       that); an existing number is never changed;
 *  - join_date        is set directly on the first payment (My_IAPSNJ_Membership).
 *
 * A field the CRM does not have is skipped, never created. Called from
 * My_IAPSNJ_Schema::set_fields() (every write of member_type / paid_through),
 * after a payment links the member's WordPress user, when a membership lapses,
 * and in bulk from Settings → Configurations.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

final class My_IAPSNJ_Member_Fields {

    /** Contacts read per query in the bulk run. */
    const CHUNK = 500;

    /** @var array<string,array>|null slug => FluentCRM custom field definition */
    private static $defs = null;

    /**
     * The FluentCRM custom field with this slug, or null when the CRM does not
     * have it. Read once per request.
     *
     * @return array<string,mixed>|null
     */
    public static function field_def( string $slug ): ?array {
        if ( self::$defs === null ) {
            self::$defs = [];
            $all        = function_exists( 'fluentcrm_get_option' ) ? fluentcrm_get_option( 'contact_custom_fields', [] ) : [];
            foreach ( (array) $all as $cf ) {
                if ( is_array( $cf ) && ! empty( $cf['slug'] ) ) {
                    self::$defs[ sanitize_key( (string) $cf['slug'] ) ] = $cf;
                }
            }
        }
        return self::$defs[ $slug ] ?? null;
    }

    /** Forget the fields read (after the CRM's fields change). */
    public static function reset(): void {
        self::$defs = null;
    }

    /**
     * Options of a dropdown field, trimmed, blanks dropped.
     *
     * @param array<string,mixed> $def
     * @return string[]
     */
    private static function options_of( array $def ): array {
        $out = [];
        foreach ( (array) ( $def['options'] ?? [] ) as $option ) {
            if ( is_scalar( $option ) && trim( (string) $option ) !== '' ) {
                $out[] = trim( (string) $option );
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * The option of the Member Status dropdown for a member: see the class
     * comment. '' when none fits.
     *
     * @param string[] $options the dropdown's options
     */
    public static function status_option( string $type, bool $active, array $options ): string {
        $pick = static function ( array $words ) use ( $options ): string {
            foreach ( $words as $word ) {
                foreach ( $options as $option ) {
                    if ( strcasecmp( $option, $word ) === 0 ) {
                        return $option;
                    }
                }
            }
            foreach ( $words as $word ) {
                foreach ( $options as $option ) {
                    // A whole word: "Active" must not be found in "Inactive".
                    if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/iu', $option ) ) {
                        return $option;
                    }
                }
            }
            return '';
        };
        if ( ! $active ) {
            $lapsed = $pick( [ 'expired', 'lapsed', 'inactive', 'former' ] );
            if ( $lapsed !== '' ) {
                return $lapsed;
            }
        }
        if ( $type !== '' ) {
            $named = $pick( [ $type ] );
            if ( $named !== '' ) {
                return $named;
            }
        }
        return $active ? $pick( [ 'active', 'current', 'good standing' ] ) : '';
    }

    /**
     * What the CRM's Membership fields should change to for a contact: slug =>
     * new value ('' = delete), only fields the CRM has and only those that
     * differ from what the contact holds.
     *
     * @param array<string,mixed> $custom  the contact's custom fields
     * @param int                 $user_id the contact's WordPress user (0 = none)
     * @param array<string,mixed> $pending member_type / paid_through just written but not readable yet
     * @return array<string,string>
     */
    public static function changes( array $custom, int $user_id, array $pending = [] ): array {
        $read = static function ( string $slug, bool $with_pending = true ) use ( $custom, $pending ): string {
            if ( $with_pending && array_key_exists( $slug, $pending ) ) {
                return trim( (string) $pending[ $slug ] );
            }
            $v = $custom[ $slug ] ?? '';
            return trim( is_array( $v ) ? implode( ', ', $v ) : (string) $v );
        };
        $type = $read( My_IAPSNJ_Schema::FIELD_MEMBER_TYPE );
        $paid = My_IAPSNJ_Dates::ymd( $read( My_IAPSNJ_Schema::FIELD_PAID_THROUGH ) );
        // A refund of the first payment sets member_type back to '': the contact is no
        // longer a member, so what we wrote goes too. Any other contact without a type
        // keeps whatever the CRM holds (older data).
        $cleared = array_key_exists( My_IAPSNJ_Schema::FIELD_MEMBER_TYPE, $pending ) && $type === '';
        if ( $type === '' && ! $cleared ) {
            return [];
        }

        $want = [];
        if ( self::field_def( My_IAPSNJ_Schema::FIELD_EXPIRATION_DATE ) ) {
            $want[ My_IAPSNJ_Schema::FIELD_EXPIRATION_DATE ] = $type === '' ? '' : $paid;
        }
        $status = self::field_def( My_IAPSNJ_Schema::FIELD_MEMBER_STATUS );
        if ( $status ) {
            if ( $type === '' ) {
                $want[ My_IAPSNJ_Schema::FIELD_MEMBER_STATUS ] = '';
            } elseif ( in_array( (string) ( $status['type'] ?? '' ), [ 'select-one', 'radio' ], true ) ) {
                $option = self::status_option( $type, My_IAPSNJ_Schema::is_active_state( $type, $paid ), self::options_of( $status ) );
                if ( $option !== '' ) {
                    $want[ My_IAPSNJ_Schema::FIELD_MEMBER_STATUS ] = $option;
                }
            } elseif ( (string) ( $status['type'] ?? '' ) === 'text' ) {
                $want[ My_IAPSNJ_Schema::FIELD_MEMBER_STATUS ] = $type;
            }
        }
        if ( $type !== '' && $user_id > 0 && $read( My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER, false ) === '' && self::field_def( My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ) ) {
            $want[ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] = (string) $user_id;
        }

        $changes = [];
        foreach ( $want as $slug => $value ) {
            $now = $read( $slug, false );
            if ( $slug === My_IAPSNJ_Schema::FIELD_EXPIRATION_DATE ) {
                $now = My_IAPSNJ_Dates::ymd( $now );
            }
            if ( $now !== $value ) {
                $changes[ $slug ] = $value;
            }
        }
        return $changes;
    }

    /**
     * Write the changes for one contact. Never throws into a payment or a
     * contact save.
     *
     * @param array<string,mixed>      $pending member_type / paid_through just written
     * @param array<string,mixed>|null $custom  pre-loaded custom fields
     * @return array<string,string> what was written
     */
    public static function sync( Subscriber $subscriber, array $pending = [], ?array $custom = null ): array {
        try {
            $changes = self::changes( $custom ?? $subscriber->custom_fields(), (int) $subscriber->user_id, $pending );
            if ( $changes ) {
                $subscriber->syncCustomFieldValues( $changes, true );
            }
            return $changes;
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: could not update the CRM membership fields of contact #' . (int) $subscriber->id . ': ' . $e->getMessage() );
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Settings → Configurations
    // -----------------------------------------------------------------------

    /**
     * What the Configurations card shows: for each field, whether the CRM has
     * it and where its value comes from; and how the Member Status dropdown's
     * options are used.
     *
     * @return array{fields:array<int,array{slug:string,label:string,exists:bool,source:string}>,status_options:string[],status_map:array<string,string>,status_kind:string}
     */
    public static function overview(): array {
        $fields = [
            [ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER, __( 'Member Number', 'my-iapsnj' ), __( 'The WordPress user id, filled in when empty (an existing number is never changed).', 'my-iapsnj' ) ],
            [ My_IAPSNJ_Schema::FIELD_MEMBER_STATUS, __( 'Member Status', 'my-iapsnj' ), __( 'The member type (Regular, Associate …); Expired / Lapsed once the membership has run out, if the list has such an option.', 'my-iapsnj' ) ],
            [ My_IAPSNJ_Schema::FIELD_JOIN_DATE, __( 'Join Date', 'my-iapsnj' ), __( 'The date of the first payment (set once, never overwritten).', 'my-iapsnj' ) ],
            [ My_IAPSNJ_Schema::FIELD_EXPIRATION_DATE, __( 'Expiration Date', 'my-iapsnj' ), __( 'Paid through (empty for Lifetime and Honorary).', 'my-iapsnj' ) ],
        ];
        $out = [ 'fields' => [], 'status_options' => [], 'status_map' => [], 'status_kind' => '' ];
        foreach ( $fields as [ $slug, $label, $source ] ) {
            $out['fields'][] = [ 'slug' => $slug, 'label' => $label, 'exists' => self::field_def( $slug ) !== null, 'source' => $source ];
        }
        $status = self::field_def( My_IAPSNJ_Schema::FIELD_MEMBER_STATUS );
        if ( $status ) {
            $out['status_kind'] = (string) ( $status['type'] ?? '' );
            $out['status_options'] = self::options_of( $status );
            foreach ( My_IAPSNJ_Schema::member_types() as $type ) {
                $out['status_map'][ $type ] = self::status_option( $type, true, $out['status_options'] );
            }
            $out['status_map'][ __( 'A lapsed member', 'my-iapsnj' ) ] = self::status_option( My_IAPSNJ_Schema::TYPE_REGULAR, false, $out['status_options'] );
        }
        return $out;
    }

    /**
     * Bring the CRM's Membership fields of every member in step: a Preview
     * (dry) or Apply (up to $limit contacts per call; the page repeats until
     * nothing is left). Writes go straight to the FluentCRM tables, like the
     * phone and name tools, so a backfill fires no contact-updated
     * automations.
     *
     * @return array{checked:int,to_change:int,changed:int,by_field:array<string,int>,samples:string[],missing_fields:string[],status_unmatched:int,status_unmatched_types:string[]}
     */
    public static function sync_all( bool $dry, int $limit = 500 ): array {
        global $wpdb;
        $subs = $wpdb->prefix . 'fc_subscribers';
        $meta = $wpdb->prefix . 'fc_subscriber_meta';
        $out  = [
            'checked'                => 0,
            'to_change'              => 0,
            'changed'                => 0,
            'by_field'               => [],
            'samples'                => [],
            'missing_fields'         => [],
            'status_unmatched'       => 0,
            'status_unmatched_types' => [],
        ];
        foreach ( [ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER, My_IAPSNJ_Schema::FIELD_MEMBER_STATUS, My_IAPSNJ_Schema::FIELD_EXPIRATION_DATE ] as $slug ) {
            if ( ! self::field_def( $slug ) ) {
                $out['missing_fields'][] = $slug;
            }
        }
        $status_def     = self::field_def( My_IAPSNJ_Schema::FIELD_MEMBER_STATUS );
        $status_options = $status_def ? self::options_of( $status_def ) : [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $members = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.email, s.user_id FROM {$subs} s
             WHERE EXISTS (SELECT 1 FROM {$meta} t WHERE t.subscriber_id = s.id AND t.object_type = 'custom_field' AND t.`key` = %s AND TRIM(COALESCE(t.`value`, '')) <> '')
             ORDER BY s.id ASC",
            My_IAPSNJ_Schema::FIELD_MEMBER_TYPE
        ), ARRAY_A );

        $keys = [
            My_IAPSNJ_Schema::FIELD_MEMBER_TYPE,
            My_IAPSNJ_Schema::FIELD_PAID_THROUGH,
            My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER,
            My_IAPSNJ_Schema::FIELD_MEMBER_STATUS,
            My_IAPSNJ_Schema::FIELD_EXPIRATION_DATE,
        ];
        foreach ( array_chunk( $members, self::CHUNK ) as $chunk ) {
            $ids = array_map( 'intval', array_column( $chunk, 'id' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholders only
            $rows   = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT subscriber_id, `key`, `value` FROM {$meta}
                 WHERE object_type = 'custom_field' AND `key` IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')
                   AND subscriber_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')
                 ORDER BY id ASC',
                array_merge( $keys, $ids )
            ), ARRAY_A );
            $custom = [];
            foreach ( $rows as $r ) {
                $custom[ (int) $r['subscriber_id'] ][ (string) $r['key'] ] = (string) $r['value'];
            }

            foreach ( $chunk as $c ) {
                $id = (int) $c['id'];
                $out['checked']++;
                $values  = $custom[ $id ] ?? [];
                $changes = self::changes( $values, (int) $c['user_id'] );
                if ( $status_def && in_array( (string) ( $status_def['type'] ?? '' ), [ 'select-one', 'radio' ], true ) && ! isset( $changes[ My_IAPSNJ_Schema::FIELD_MEMBER_STATUS ] ) ) {
                    $type = trim( (string) ( $values[ My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ] ?? '' ) );
                    $paid = My_IAPSNJ_Dates::ymd( (string) ( $values[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ] ?? '' ) );
                    if ( self::status_option( $type, My_IAPSNJ_Schema::is_active_state( $type, $paid ), $status_options ) === '' ) {
                        $out['status_unmatched']++;
                        $out['status_unmatched_types'][ $type ] = $type;
                    }
                }
                if ( ! $changes ) {
                    continue;
                }
                $out['to_change']++;
                foreach ( $changes as $slug => $value ) {
                    $out['by_field'][ $slug ] = ( $out['by_field'][ $slug ] ?? 0 ) + 1;
                }
                if ( count( $out['samples'] ) < 25 ) {
                    $parts = [];
                    foreach ( $changes as $slug => $value ) {
                        $parts[] = $slug . ': ' . ( trim( (string) ( $values[ $slug ] ?? '' ) ) !== '' ? $values[ $slug ] : '—' ) . ' → ' . ( $value !== '' ? $value : '—' );
                    }
                    $out['samples'][] = (string) $c['email'] . ' · ' . implode( ' · ', $parts );
                }
                if ( ! $dry && $out['changed'] < $limit && self::write_raw( $id, $changes ) ) {
                    $out['changed']++;
                }
            }
        }
        $out['status_unmatched_types'] = array_values( $out['status_unmatched_types'] );
        return $out;
    }

    /**
     * Store custom field values straight in the FluentCRM meta table: replace
     * the contact's row for each slug, or delete it for ''.
     *
     * @param array<string,string> $changes
     */
    private static function write_raw( int $subscriber_id, array $changes ): bool {
        global $wpdb;
        $meta = $wpdb->prefix . 'fc_subscriber_meta';
        $now  = gmdate( 'Y-m-d H:i:s' );
        foreach ( $changes as $slug => $value ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $meta, [ 'subscriber_id' => $subscriber_id, 'object_type' => 'custom_field', 'key' => $slug ] );
            if ( $value === '' ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ok = $wpdb->insert( $meta, [
                'subscriber_id' => $subscriber_id,
                'object_type'   => 'custom_field',
                'key'           => $slug,
                'value'         => $value,
                'created_by'    => get_current_user_id(),
                'created_at'    => $now,
                'updated_at'    => $now,
            ] );
            if ( $ok === false ) {
                return false;
            }
        }
        return true;
    }
}
