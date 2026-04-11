<?php
/**
 * My_IAPSNJ_Field_Mapper
 *
 * Discovers available fields on both sides (WordPress + FluentCRM) and
 * manages the saved field-mapping configuration.
 *
 * Mapping record shape stored in wp_options:
 * [
 *   'id'               => 'map_abc123',
 *   'wp_field_key'     => 'first_name',
 *   'wp_field_source'  => 'user' | 'meta' | 'acf' | 'pmp',
 *   'wp_field_label'   => 'First Name',
 *   'fcrm_field_key'   => 'first_name',
 *   'fcrm_field_source'=> 'default' | 'custom',
 *   'fcrm_field_label' => 'First Name',
 *   'field_type'       => 'text' | 'select' | 'date' | 'checkbox' | 'number' | 'email' | 'textarea',
 *   'sync_direction'   => 'both' | 'wp_to_fcrm' | 'fcrm_to_wp',
 *   'enabled'          => true,
 *   'date_format_wp'   => 'm/d/Y',   // ACF return format for date pickers
 *   'date_format_fcrm' => 'Y-m-d',   // FluentCRM always uses Y-m-d
 *   'value_map'        => [ 'wp_value' => 'fcrm_value', ... ],  // select/radio value translation
 * ]
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Field_Mapper {

    // -----------------------------------------------------------------------
    // WordPress side
    // -----------------------------------------------------------------------

    /**
     * Well-known WP_User object properties (not in user_meta).
     */
    private static array $wp_user_object_fields = [
        'ID'                => 'User ID (WP ID)',
        'user_login'        => 'Username (user_login)',
        'user_email'        => 'Email (user_email)',
        'user_url'          => 'Website (user_url)',
        'display_name'      => 'Display Name',
        'user_registered'   => 'Registration Date (user_registered)',
    ];

    /**
     * PMPro billing address meta keys stored in wp_usermeta (pmpro_b* prefix).
     */
    private static array $pmp_billing_meta_fields = [
        'pmpro_baddress1' => 'PMPro Billing Address Line 1',
        'pmpro_baddress2' => 'PMPro Billing Address Line 2',
        'pmpro_bcity'     => 'PMPro Billing City',
        'pmpro_bstate'    => 'PMPro Billing State',
        'pmpro_bzipcode'  => 'PMPro Billing Postal Code',
        'pmpro_bcountry'  => 'PMPro Billing Country',
        'pmpro_bphone'    => 'PMPro Billing Phone',
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
     * Returns all WP fields: object props + core meta + ACF user fields +
     * any additional user_meta keys discovered in the DB.
     *
     * @return array<string, array{key:string, source:string, label:string, type:string}>
     */
    public function get_wp_fields(): array {
        $fields = [];

        // 1. WP_User object properties
        $user_obj_readonly = [ 'ID', 'user_login' ];
        foreach ( self::$wp_user_object_fields as $key => $label ) {
            $type = 'text';
            if ( $key === 'user_registered' ) {
                $type = 'date';
            } elseif ( $key === 'ID' ) {
                $type = 'number';
            }
            $fields[ 'user__' . $key ] = [
                'key'      => $key,
                'source'   => 'user',
                'label'    => $label,
                'type'     => $type,
                'readonly' => in_array( $key, $user_obj_readonly, true ),
            ];
        }

        // 2. Core user_meta
        foreach ( self::$wp_core_meta_fields as $key => $label ) {
            $fields[ 'meta__' . $key ] = [
                'key'    => $key,
                'source' => 'meta',
                'label'  => $label . ' (user_meta)',
                'type'   => 'text',
            ];
        }

        // 3. ACF user fields (if ACF is active)
        if ( function_exists( 'acf_get_field_groups' ) ) {
            $acf_fields = $this->get_acf_user_fields();
            foreach ( $acf_fields as $f ) {
                $uid = 'acf__' . $f['key'];
                if ( ! isset( $fields[ $uid ] ) ) {
                    $fields[ $uid ] = $f;
                }
            }
        }

        // 4. Extra user_meta keys found in DB (excluding ACF internal keys)
        $db_meta_keys = $this->get_db_user_meta_keys();
        foreach ( $db_meta_keys as $meta_key ) {
            $uid = 'meta__' . $meta_key;
            if ( ! isset( $fields[ $uid ] ) ) {
                $fields[ $uid ] = [
                    'key'    => $meta_key,
                    'source' => 'meta',
                    'label'  => $meta_key . ' (user_meta)',
                    'type'   => 'text',
                ];
            }
        }

        // 5. Paid Memberships Pro fields (if PMPro is active)
        if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            $pmp_fields = [
                'pmp__startdate'  => [
                    'key'      => 'startdate',
                    'source'   => 'pmp',
                    'label'    => 'PMPro Join Date',
                    'type'     => 'date',
                    'readonly' => true,
                ],
                'pmp__enddate'    => [
                    'key'      => 'enddate',
                    'source'   => 'pmp',
                    'label'    => 'PMPro Expiration / Renewal Date',
                    'type'     => 'date',
                    'readonly' => true,
                ],
                'pmp__expiration_date' => [
                    'key'      => 'expiration_date',
                    'source'   => 'pmp',
                    'label'    => 'PMPro Smart Expiration Date',
                    'type'     => 'date',
                    'readonly' => true,
                ],
                'pmp__level_name' => [
                    'key'      => 'level_name',
                    'source'   => 'pmp',
                    'label'    => 'PMPro Level Name',
                    'type'     => 'text',
                    'readonly' => true,
                ],
                'pmp__level_id'   => [
                    'key'      => 'level_id',
                    'source'   => 'pmp',
                    'label'    => 'PMPro Level ID',
                    'type'     => 'number',
                    'readonly' => true,
                ],
            ];
            foreach ( $pmp_fields as $uid => $field ) {
                $fields[ $uid ] = $field;
            }

            foreach ( self::$pmp_billing_meta_fields as $meta_key => $label ) {
                $fields[ 'pmp_addr__' . $meta_key ] = [
                    'key'    => $meta_key,
                    'source' => 'meta',
                    'label'  => $label,
                    'type'   => 'text',
                ];
            }
        }

        return $fields;
    }

    /**
     * Get ACF field definitions scoped to the user form.
     */
    private function get_acf_user_fields(): array {
        $result = [];
        $groups = acf_get_field_groups( [ 'user_form' => 'all' ] );
        foreach ( $groups as $group ) {
            $acf_fields = acf_get_fields( $group );
            if ( ! is_array( $acf_fields ) ) {
                continue;
            }
            foreach ( $acf_fields as $field ) {
                $sync_type = $this->map_acf_type_to_sync_type( $field['type'] );

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
                    'type'           => $sync_type,
                    'acf_key'        => $field['key'],
                    'acf_field_type' => $field['type'],
                    'date_format_wp' => $field['return_format'] ?? 'm/d/Y',
                    'options'        => $options,
                ];
            }
        }
        return $result;
    }

    /**
     * Map ACF field type to our internal sync type.
     */
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
     * Fetch distinct meta_key values from usermeta, excluding internal WP/ACF keys.
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

        $pmp_billing_keys = array_keys( self::$pmp_billing_meta_fields );

        return array_filter( $keys, function ( $k ) use ( $pmp_billing_keys ) {
            if ( strpos( $k, 'field_' ) === 0 ) {
                return false;
            }
            if ( in_array( $k, $pmp_billing_keys, true ) ) {
                return false;
            }
            $skip = [
                'wp_capabilities', 'wp_user_level', 'wp_user-settings',
                'wp_user-settings-time', 'dismissed_wp_pointers',
                'show_admin_bar_front', 'show_welcome_panel',
                'managenav-menuscolumnshidden', 'metaboxhidden_',
                'closedpostboxes_', 'wp_dashboard_quick_press_last_post_id',
            ];
            foreach ( $skip as $prefix ) {
                if ( strpos( $k, $prefix ) === 0 ) {
                    return false;
                }
            }
            return true;
        } );
    }

    // -----------------------------------------------------------------------
    // FluentCRM side
    // -----------------------------------------------------------------------

    /**
     * FluentCRM default subscriber fields.
     */
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
        'gender'         => [ 'label' => 'Gender',          'type' => 'text' ],
    ];

    /**
     * Returns all FluentCRM fields: defaults + custom fields.
     *
     * @return array<string, array{key:string, source:string, label:string, type:string}>
     */
    public function get_fcrm_fields(): array {
        $fields = [];

        // 1. Default fields
        foreach ( self::$fcrm_default_fields as $key => $def ) {
            $fields[ 'default__' . $key ] = [
                'key'    => $key,
                'source' => 'default',
                'label'  => $def['label'],
                'type'   => $def['type'],
            ];
        }

        // 2. Custom fields from FluentCRM options
        $custom_field_defs = fluentcrm_get_option( 'contact_custom_fields', [] );
        if ( is_array( $custom_field_defs ) ) {
            foreach ( $custom_field_defs as $cf ) {
                if ( empty( $cf['slug'] ) ) {
                    continue;
                }
                $fcrm_type = $cf['type'] ?? 'text';
                $sync_type = $this->map_fcrm_type_to_sync_type( $fcrm_type );

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
                    'type'    => $sync_type,
                    'options' => $options,
                ];
            }
        }

        return $fields;
    }

    /**
     * Map FluentCRM field type to our internal sync type.
     */
    private function map_fcrm_type_to_sync_type( string $fcrm_type ): string {
        $map = [
            'date'      => 'date',
            'date_time' => 'date',
            'number'    => 'number',
            'checkbox'  => 'checkbox',
            'select'    => 'select',
            'radio'     => 'select',
            'textarea'  => 'textarea',
        ];
        return $map[ $fcrm_type ] ?? 'text';
    }

    // -----------------------------------------------------------------------
    // Saved mappings CRUD
    // -----------------------------------------------------------------------

    /**
     * Returns the array of saved field mappings.
     *
     * @return array<int, array>
     */
    public function get_saved_mappings(): array {
        $raw = get_option( 'my_iapsnj_field_mappings', [] );
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Replaces the saved mappings with a new array.
     *
     * @param array $mappings
     */
    public function save_mappings( array $mappings ): void {
        update_option( 'my_iapsnj_field_mappings', $mappings );
    }

    /**
     * Returns only the enabled mappings.
     */
    public function get_active_mappings(): array {
        return array_filter( $this->get_saved_mappings(), fn( $m ) => ! empty( $m['enabled'] ) );
    }

    /**
     * Build a unique mapping ID string.
     */
    public static function generate_id(): string {
        return 'map_' . wp_generate_password( 8, false );
    }

    // -----------------------------------------------------------------------
    // IAPSNJ default field mappings seed
    // -----------------------------------------------------------------------

    /**
     * Seeds the default IAPSNJ field mappings on first activation.
     * Maps ACF Member Profile fields to FluentCRM custom/default fields
     * as configured for the IAPSNJ website.
     */
    public static function seed_default_mappings(): void {
        // Raw definition rows: [ wp_key, wp_source, wp_label, fcrm_key, fcrm_source, fcrm_label, type, direction ]
        $defaults = [
            [ 'first_name',            'meta',    'First Name',                      'first_name',           'default', 'First Name',               'text',     'both' ],
            [ 'last_name',             'meta',    'Last Name',                       'last_name',            'default', 'Last Name',                'text',     'both' ],
            [ 'user_email',            'user',    'Email (user_email)',               'email',                'default', 'Email',                    'email',    'both' ],
            [ 'MemberNum',             'acf',     'Member Number (ACF)',              'member_number',        'custom',  'Member Number (custom)',    'number',   'both' ],
            [ 'member_status',         'acf',     'Member Status (ACF)',              'member_status',        'custom',  'Member Status (custom)',    'select',   'both' ],
            [ 'join_date',             'acf',     'Join Date (ACF)',                  'join_date',            'custom',  'Join Date (custom)',        'date',     'both' ],
            [ 'expiration_date',       'acf',     'Membership Expiration Date (ACF)','expiration_date',      'custom',  'Expiration Date (custom)',  'date',     'both' ],
            [ 'last_payment_date',     'acf',     'Last Payment Date (ACF)',          'last_payment_date',    'custom',  'Last Payment Date (custom)','date',     'wp_to_fcrm' ],
            [ 'primary_phone',         'acf',     'Primary Phone (ACF)',              'phone',                'default', 'Phone',                    'text',     'both' ],
            [ 'alternate_phone',       'acf',     'Alternate Phone (ACF)',            'phone2',               'custom',  'Phone 2 (custom)',          'text',     'both' ],
            [ 'address',               'acf',     'Street Address (ACF)',             'address_line_1',       'default', 'Address Line 1',            'text',     'both' ],
            [ 'address2',              'acf',     'Address Line 2 (ACF)',             'address_line_2',       'default', 'Address Line 2',            'text',     'both' ],
            [ 'city',                  'acf',     'City (ACF)',                       'city',                 'default', 'City',                     'text',     'both' ],
            [ 'state',                 'acf',     'State (ACF)',                      'state',                'default', 'State',                    'select',   'both' ],
            [ 'zip_code',              'acf',     'Zip Code (ACF)',                   'postal_code',          'default', 'Postal Code',              'text',     'both' ],
            [ 'department',            'acf',     'Department (ACF)',                 'department',           'custom',  'Department (custom)',       'select',   'both' ],
            [ 'rank_level',            'acf',     'Rank (ACF)',                       'rank_level',           'custom',  'Rank (custom)',             'select',   'both' ],
            [ 'work_phone',            'acf',     'Work Phone (ACF)',                 'phone_work',           'custom',  'Work Phone (custom)',       'text',     'both' ],
            [ 'retirement_date',       'acf',     'Retirement Date (ACF)',            'retirement_date',      'custom',  'Retirement Date (custom)', 'date',     'both' ],
            [ 'union_affiliation',     'acf',     'Union Affiliation (ACF)',          'union_affiliation',    'custom',  'Union Affiliation (custom)','text',     'both' ],
            [ 'union_position',        'acf',     'Union Position (ACF)',             'union_position',       'custom',  'Union Position (custom)',   'text',     'both' ],
            [ 'date_of_birth',         'acf',     'Date of Birth (ACF)',              'date_of_birth',        'default', 'Date of Birth',             'date',     'both' ],
            [ 'marital_status',        'acf',     'Marital Status (ACF)',             'marital_status',       'custom',  'Marital Status (custom)',   'select',   'both' ],
            [ 'spouse_name',           'acf',     'Spouse Name (ACF)',                'spouse_name',          'custom',  'Spouse Name (custom)',      'text',     'both' ],
            [ 'armed_service',         'acf',     'Armed Service (ACF)',              'armed_service',        'custom',  'Armed Service (custom)',    'checkbox', 'both' ],
            [ 'additional_information','acf',     'Additional Information (ACF)',     'additional_information','custom', 'Additional Info (custom)',  'textarea', 'both' ],
            [ 'company_name',          'acf',     'Company Name (ACF)',               'company_name',         'custom',  'Company Name (custom)',     'text',     'both' ],
            [ 'company_title',         'acf',     'Company Title (ACF)',              'company_title',        'custom',  'Company Title (custom)',    'text',     'both' ],
            [ 'company_type',          'acf',     'Company Type (ACF)',               'company_type',         'custom',  'Company Type (custom)',     'text',     'both' ],
            [ 'admin_notes',           'acf',     'Admin Notes (ACF)',                'admin_notes',          'custom',  'Admin Notes (custom)',      'textarea', 'both' ],
            [ 'referred_by',           'acf',     'Referred By (ACF)',                'referred_by',          'custom',  'Referred By (custom)',      'text',     'both' ],
            [ 'elo_title',             'acf',     'ELO Title (ACF)',                  'elo_title',            'custom',  'ELO Title (custom)',        'select',   'both' ],
        ];

        $mappings = [];
        foreach ( $defaults as $row ) {
            [ $wp_key, $wp_src, $wp_label, $fcrm_key, $fcrm_src, $fcrm_label, $type, $direction ] = $row;

            $m = [
                'id'               => self::generate_id(),
                'wp_field_key'     => $wp_key,
                'wp_field_source'  => $wp_src,
                'wp_field_label'   => $wp_label,
                'fcrm_field_key'   => $fcrm_key,
                'fcrm_field_source'=> $fcrm_src,
                'fcrm_field_label' => $fcrm_label,
                'field_type'       => $type,
                'sync_direction'   => $direction,
                'enabled'          => true,
                'date_format_wp'   => 'm/d/Y',
                'date_format_fcrm' => 'Y-m-d',
                'value_map'        => [],
            ];
            $mappings[] = $m;
        }

        update_option( 'my_iapsnj_field_mappings', $mappings );
    }
}
