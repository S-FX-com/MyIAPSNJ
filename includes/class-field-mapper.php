<?php
/**
 * My_IAPSNJ_Field_Mapper
 *
 * Discovers fields on both sides (FluentCRM contact fields → WordPress user
 * fields) and manages the saved mirror map. Since 4.0 every mapping is
 * CRM → WP; the sync_direction key is kept in the record for compatibility
 * but is always 'fcrm_to_wp'.
 *
 * Mapping record shape stored in wp_options:
 * [
 *   'id'               => 'map_abc123',
 *   'wp_field_key'     => 'first_name',
 *   'wp_field_source'  => 'user' | 'meta' | 'acf',   // acf = user meta in ACF storage format
 *   'wp_field_label'   => 'First Name',
 *   'fcrm_field_key'   => 'first_name',
 *   'fcrm_field_source'=> 'default' | 'custom',
 *   'fcrm_field_label' => 'First Name',
 *   'field_type'       => 'text' | 'select' | 'date' | 'checkbox' | 'number' | 'email' | 'textarea',
 *   'sync_direction'   => 'fcrm_to_wp',
 *   'enabled'          => true,
 *   'date_format_wp'   => 'm/d/Y',   // how the WP side stores dates (ACF pickers: m/d/Y)
 *   'date_format_fcrm' => 'Y-m-d',   // FluentCRM always uses Y-m-d
 *   'value_map'        => [ 'wp_value' => 'fcrm_value', ... ],
 * ]
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Field_Mapper {

    // -----------------------------------------------------------------------
    // WordPress side
    // -----------------------------------------------------------------------

    /**
     * WP_User object properties that may be written from the CRM.
     */
    private static array $wp_user_object_fields = [
        'user_email'   => 'Email (user_email)',
        'user_url'     => 'Website (user_url)',
        'display_name' => 'Display Name',
    ];

    /**
     * Well-known core user_meta keys.
     */
    private static array $wp_core_meta_fields = [
        'first_name'  => 'First Name',
        'last_name'   => 'Last Name',
        'nickname'    => 'Nickname',
        'description' => 'Biographical Info',
    ];

    /**
     * Returns all WP fields: object props + core meta + ACF user fields (if
     * ACF is active) + any additional user_meta keys discovered in the DB.
     *
     * @return array<string, array{key:string, source:string, label:string, type:string}>
     */
    public function get_wp_fields(): array {
        $fields = [];

        foreach ( self::$wp_user_object_fields as $key => $label ) {
            $fields[ 'user__' . $key ] = [
                'key'    => $key,
                'source' => 'user',
                'label'  => $label,
                'type'   => $key === 'user_email' ? 'email' : 'text',
            ];
        }

        foreach ( self::$wp_core_meta_fields as $key => $label ) {
            $fields[ 'meta__' . $key ] = [
                'key'    => $key,
                'source' => 'meta',
                'label'  => $label . ' (user_meta)',
                'type'   => 'text',
            ];
        }

        if ( function_exists( 'acf_get_field_groups' ) ) {
            foreach ( $this->get_acf_user_fields() as $f ) {
                $uid = 'acf__' . $f['key'];
                if ( ! isset( $fields[ $uid ] ) ) {
                    $fields[ $uid ] = $f;
                }
            }
        }

        foreach ( $this->get_db_user_meta_keys() as $meta_key ) {
            $uid = 'meta__' . $meta_key;
            if ( ! isset( $fields[ $uid ] ) && ! isset( $fields[ 'acf__' . $meta_key ] ) ) {
                $fields[ $uid ] = [
                    'key'    => $meta_key,
                    'source' => 'meta',
                    'label'  => $meta_key . ' (user_meta)',
                    'type'   => 'text',
                ];
            }
        }

        return $fields;
    }

    private function get_acf_user_fields(): array {
        $result = [];
        $groups = acf_get_field_groups( [ 'user_form' => 'all' ] );
        foreach ( $groups as $group ) {
            $acf_fields = acf_get_fields( $group );
            if ( ! is_array( $acf_fields ) ) {
                continue;
            }
            foreach ( $acf_fields as $field ) {
                $options = [];
                if ( in_array( $field['type'], [ 'select', 'radio' ], true ) && ! empty( $field['choices'] ) ) {
                    foreach ( $field['choices'] as $value => $label ) {
                        $options[] = [ 'value' => (string) $value, 'label' => (string) $label ];
                    }
                }
                $result[] = [
                    'key'            => $field['name'],
                    'source'         => 'acf',
                    'label'          => $field['label'] . ' (ACF)',
                    'type'           => $this->map_acf_type_to_sync_type( $field['type'] ),
                    'acf_key'        => $field['key'],
                    'acf_field_type' => $field['type'],
                    'date_format_wp' => $field['return_format'] ?? 'm/d/Y',
                    'options'        => $options,
                ];
            }
        }
        return $result;
    }

    private function map_acf_type_to_sync_type( string $acf_type ): string {
        $map = [
            'date_picker'      => 'date',
            'date_time_picker' => 'date',
            'time_picker'      => 'text',
            'checkbox'         => 'checkbox',
            'radio'            => 'select',
            'select'           => 'select',
            'number'           => 'number',
            'email'            => 'email',
            'textarea'         => 'textarea',
            'wysiwyg'          => 'textarea',
            'url'              => 'text',
        ];
        return $map[ $acf_type ] ?? 'text';
    }

    /**
     * Distinct user_meta keys, excluding WordPress internals, ACF reference
     * keys and PMPro billing history (which is never a mirror target).
     */
    private function get_db_user_meta_keys(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $keys = $wpdb->get_col(
            "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
             WHERE meta_key NOT LIKE '\_%'
             AND meta_key NOT LIKE 'session_tokens'
             AND meta_key NOT LIKE 'community-events-location'
             ORDER BY meta_key
             LIMIT 300"
        );

        return array_values( array_filter( (array) $keys, function ( $k ) {
            if ( strpos( $k, 'field_' ) === 0 || strpos( $k, 'pmpro_' ) === 0 ) {
                return false;
            }
            $skip = [
                'wp_capabilities', 'wp_user_level', 'wp_user-settings',
                'wp_user-settings-time', 'dismissed_wp_pointers',
                'show_admin_bar_front', 'show_welcome_panel',
                'managenav-menuscolumnshidden', 'metaboxhidden_',
                'closedpostboxes_', 'wp_dashboard_quick_press_last_post_id',
                'rich_editing', 'syntax_highlighting', 'comment_shortcuts',
                'admin_color', 'use_ssl', 'locale', 'default_password_nag',
            ];
            foreach ( $skip as $prefix ) {
                if ( strpos( $k, $prefix ) === 0 ) {
                    return false;
                }
            }
            return true;
        } ) );
    }

    // -----------------------------------------------------------------------
    // FluentCRM side
    // -----------------------------------------------------------------------

    private static array $fcrm_default_fields = [
        'prefix'         => [ 'label' => 'Prefix',          'type' => 'text' ],
        'first_name'     => [ 'label' => 'First Name',      'type' => 'text' ],
        'last_name'      => [ 'label' => 'Last Name',       'type' => 'text' ],
        'email'          => [ 'label' => 'Email',           'type' => 'email' ],
        'phone'          => [ 'label' => 'Phone',           'type' => 'text' ],
        'address_line_1' => [ 'label' => 'Address Line 1',  'type' => 'text' ],
        'address_line_2' => [ 'label' => 'Address Line 2',  'type' => 'text' ],
        'city'           => [ 'label' => 'City',            'type' => 'text' ],
        'state'          => [ 'label' => 'State',           'type' => 'text' ],
        'postal_code'    => [ 'label' => 'Postal Code',     'type' => 'text' ],
        'country'        => [ 'label' => 'Country',         'type' => 'text' ],
        'date_of_birth'  => [ 'label' => 'Date of Birth',   'type' => 'date' ],
    ];

    /**
     * @return array<string, array{key:string, source:string, label:string, type:string}>
     */
    public function get_fcrm_fields(): array {
        $fields = [];

        foreach ( self::$fcrm_default_fields as $key => $def ) {
            $fields[ 'default__' . $key ] = [
                'key'    => $key,
                'source' => 'default',
                'label'  => $def['label'],
                'type'   => $def['type'],
            ];
        }

        $custom_field_defs = fluentcrm_get_option( 'contact_custom_fields', [] );
        if ( is_array( $custom_field_defs ) ) {
            foreach ( $custom_field_defs as $cf ) {
                if ( empty( $cf['slug'] ) ) {
                    continue;
                }
                $options = [];
                if ( ! empty( $cf['options'] ) && is_array( $cf['options'] ) ) {
                    foreach ( $cf['options'] as $opt ) {
                        if ( is_array( $opt ) ) {
                            $options[] = [
                                'value' => (string) ( $opt['value'] ?? $opt['label'] ?? '' ),
                                'label' => (string) ( $opt['label'] ?? $opt['value'] ?? '' ),
                            ];
                        } else {
                            $options[] = [ 'value' => (string) $opt, 'label' => (string) $opt ];
                        }
                    }
                }
                $fields[ 'custom__' . $cf['slug'] ] = [
                    'key'     => $cf['slug'],
                    'source'  => 'custom',
                    'label'   => ( $cf['label'] ?? $cf['slug'] ) . ' (custom)',
                    'type'    => $this->map_fcrm_type_to_sync_type( (string) ( $cf['type'] ?? 'text' ) ),
                    'options' => $options,
                ];
            }
        }

        return $fields;
    }

    private function map_fcrm_type_to_sync_type( string $fcrm_type ): string {
        $map = [
            'date'         => 'date',
            'date_time'    => 'date',
            'number'       => 'number',
            'checkbox'     => 'checkbox',
            'select-multi' => 'checkbox',
            'select'       => 'select',
            'select-one'   => 'select',
            'radio'        => 'select',
            'textarea'     => 'textarea',
        ];
        return $map[ $fcrm_type ] ?? 'text';
    }

    // -----------------------------------------------------------------------
    // Saved mappings CRUD
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array>
     */
    public function get_saved_mappings(): array {
        $raw = get_option( 'my_iapsnj_field_mappings', [] );
        return is_array( $raw ) ? $raw : [];
    }

    public function save_mappings( array $mappings ): void {
        foreach ( $mappings as &$m ) {
            $m['sync_direction'] = 'fcrm_to_wp';
        }
        unset( $m );
        update_option( 'my_iapsnj_field_mappings', array_values( $mappings ) );
    }

    public function get_active_mappings(): array {
        return array_values( array_filter( $this->get_saved_mappings(), fn( $m ) => ! empty( $m['enabled'] ) ) );
    }

    public static function generate_id(): string {
        return 'map_' . wp_generate_password( 8, false );
    }

    // -----------------------------------------------------------------------
    // Auto-recommendation
    // -----------------------------------------------------------------------

    private static function str_starts_with( string $haystack, string $needle ): bool {
        return $needle === '' || strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
    }

    private static function str_ends_with( string $haystack, string $needle ): bool {
        if ( $needle === '' ) {
            return true;
        }
        $len = strlen( $needle );
        return $len <= strlen( $haystack ) && substr_compare( $haystack, $needle, -$len ) === 0;
    }

    /**
     * Suggest the best WordPress target for a FluentCRM field.
     */
    public function get_recommended_wp_field( string $fcrm_key, string $fcrm_type, array $wp_fields ): string {
        $aliases = [
            'email'          => 'user__user_email',
            'first_name'     => 'meta__first_name',
            'last_name'      => 'meta__last_name',
            'phone'          => 'acf__primary_phone',
            'address_line_1' => 'acf__address',
            'address_line_2' => 'acf__address2',
            'city'           => 'acf__city',
            'state'          => 'acf__state',
            'postal_code'    => 'acf__zip_code',
            'date_of_birth'  => 'acf__date_of_birth',
            'prefix'         => 'acf__prefix',
            'country'        => 'acf__country',
        ];
        if ( isset( $aliases[ $fcrm_key ] ) ) {
            $preferred = $aliases[ $fcrm_key ];
            if ( isset( $wp_fields[ $preferred ] ) ) {
                return $preferred;
            }
            // ACF not active: the same key as plain user meta.
            $meta_alt = str_replace( 'acf__', 'meta__', $preferred );
            if ( isset( $wp_fields[ $meta_alt ] ) ) {
                return $meta_alt;
            }
        }

        $source_priority = [ 'acf' => 0, 'meta' => 1, 'user' => 2 ];
        $by_key  = [];
        $by_norm = [];
        foreach ( $wp_fields as $uid => $f ) {
            $key   = $f['key'];
            $norm  = strtolower( str_replace( [ '_', '-', ' ' ], '', $key ) );
            $entry = [ 'uid' => $uid, 'prio' => $source_priority[ $f['source'] ] ?? 9 ];
            $by_key[ $key ][]   = $entry;
            $by_norm[ $norm ][] = $entry;
        }
        $best = static function ( array $candidates ): string {
            usort( $candidates, fn( $a, $b ) => $a['prio'] - $b['prio'] );
            return $candidates[0]['uid'];
        };

        if ( isset( $by_key[ $fcrm_key ] ) ) {
            return $best( $by_key[ $fcrm_key ] );
        }
        $fcrm_norm = strtolower( str_replace( [ '_', '-', ' ' ], '', $fcrm_key ) );
        if ( isset( $by_norm[ $fcrm_norm ] ) ) {
            return $best( $by_norm[ $fcrm_norm ] );
        }
        $candidates = [];
        foreach ( $by_norm as $norm => $entries ) {
            $norm = (string) $norm;
            if ( self::str_ends_with( $norm, $fcrm_norm ) || self::str_ends_with( $fcrm_norm, $norm )
                || self::str_starts_with( $norm, $fcrm_norm ) || self::str_starts_with( $fcrm_norm, $norm ) ) {
                foreach ( $entries as $e ) {
                    $candidates[] = $e;
                }
            }
        }
        return $candidates ? $best( $candidates ) : '';
    }

    // -----------------------------------------------------------------------
    // Default mirror seed
    // -----------------------------------------------------------------------

    /**
     * Seed the CRM → WP mirror on first activation. These are the profile
     * fields the member-area profile screen reads; they are copied from the
     * CRM contact into user meta (ACF storage format for the ACF-era keys).
     */
    public static function seed_default_mappings(): void {
        // [ wp_key, wp_source, wp_label, fcrm_key, fcrm_source, fcrm_label, type ]
        $defaults = [
            [ 'first_name',      'meta', 'First Name',                'first_name',                          'default', 'First Name',            'text' ],
            [ 'last_name',       'meta', 'Last Name',                 'last_name',                           'default', 'Last Name',             'text' ],
            [ 'user_email',      'user', 'Email (user_email)',        'email',                               'default', 'Email',                 'email' ],
            [ 'MemberNum',       'acf',  'Member Number',             My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER, 'custom',  'Member Number (custom)', 'number' ],
            [ 'member_status',   'acf',  'Member Type',               My_IAPSNJ_Schema::FIELD_MEMBER_TYPE,   'custom',  'Member Type (custom)',  'select' ],
            [ 'expiration_date', 'acf',  'Membership Expiration Date', My_IAPSNJ_Schema::FIELD_PAID_THROUGH, 'custom',  'Paid Through (custom)', 'date' ],
            [ 'join_date',       'acf',  'Join Date',                 My_IAPSNJ_Schema::FIELD_JOIN_DATE,     'custom',  'Join Date (custom)',    'date' ],
            [ 'primary_phone',   'acf',  'Primary Phone',             'phone',                               'default', 'Phone',                 'text' ],
            [ 'address',         'acf',  'Street Address',            'address_line_1',                      'default', 'Address Line 1',        'text' ],
            [ 'address2',        'acf',  'Address Line 2',            'address_line_2',                      'default', 'Address Line 2',        'text' ],
            [ 'city',            'acf',  'City',                      'city',                                'default', 'City',                  'text' ],
            [ 'state',           'acf',  'State',                     'state',                               'default', 'State',                 'select' ],
            [ 'zip_code',        'acf',  'Zip Code',                  'postal_code',                         'default', 'Postal Code',           'text' ],
            [ 'department',      'acf',  'Department',                My_IAPSNJ_Schema::FIELD_DEPARTMENT,    'custom',  'Department (custom)',   'select' ],
            [ 'rank_level',      'acf',  'Rank',                      My_IAPSNJ_Schema::FIELD_RANK,          'custom',  'Rank (custom)',         'select' ],
        ];

        $mappings = [];
        foreach ( $defaults as $row ) {
            $mappings[] = self::build_mapping( ...$row );
        }
        update_option( 'my_iapsnj_field_mappings', $mappings );
    }

    public static function build_mapping(
        string $wp_key,
        string $wp_src,
        string $wp_label,
        string $fcrm_key,
        string $fcrm_src,
        string $fcrm_label,
        string $type
    ): array {
        return [
            'id'               => self::generate_id(),
            'wp_field_key'     => $wp_key,
            'wp_field_source'  => $wp_src,
            'wp_field_label'   => $wp_label,
            'fcrm_field_key'   => $fcrm_key,
            'fcrm_field_source'=> $fcrm_src,
            'fcrm_field_label' => $fcrm_label,
            'field_type'       => $type,
            'sync_direction'   => 'fcrm_to_wp',
            'enabled'          => true,
            'date_format_wp'   => 'm/d/Y',
            'date_format_fcrm' => 'Y-m-d',
            'value_map'        => [],
        ];
    }

    public static function mapping_exists( array $mappings, string $wp_key, string $wp_src, string $fcrm_key ): bool {
        foreach ( $mappings as $m ) {
            if ( ( $m['wp_field_key'] ?? '' ) === $wp_key
                && ( $m['wp_field_source'] ?? '' ) === $wp_src
                && ( $m['fcrm_field_key'] ?? '' ) === $fcrm_key
            ) {
                return true;
            }
        }
        return false;
    }
}
