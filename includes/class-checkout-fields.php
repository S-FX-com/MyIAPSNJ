<?php
/**
 * My_IAPSNJ_Checkout_Fields
 *
 * The membership application lives inside the FluentCart checkout page.
 * There is no separate form: the member picks a product on the Join page
 * (each option is an instant-checkout link), lands on checkout, and fills
 * FluentCart's own name / email / phone / billing-address fields plus the
 * application fields this class injects.
 *
 * The fields are defined by the admin in My IAPSNJ → Checkout Builder. There
 * can be several checkout forms (a form can be duplicated); each membership
 * level uses one of them: Regular (Lifetime is a Regular membership without
 * an expiry, so it uses the Regular form) and Associate. In a form, built-in
 * fields (department, rank …) can be toggled, required, relabelled and given
 * options; new fields can be added with a type, options and a FluentCRM
 * target (an existing custom field, the contact's date of birth, a brand-new
 * custom field created on save, or "not stored").
 *
 * Which form a checkout shows is decided by the cart: the highest member
 * type among the cart's products configured in Membership Products. A cart
 * without a membership product (event registration, merchandise) shows no
 * application fields and none are validated, so FluentCart works as a plain
 * store for those.
 *
 * Hooks (FluentCart 1.6.x, dev.fluentcart.com):
 *
 *   fluent_cart/before_payment_methods        render the fields inside the form
 *   fluent_cart/checkout/validate_data        server-side validation
 *   fluent_cart/checkout/prepare_other_data   order created (before payment):
 *                                             save the values on the order,
 *                                             record the application row
 *   fluent_cart/checkout/form_data_changed    email typed at checkout: create
 *                                             the CRM contact, tag it
 *                                             Checkout-Abandoned, open the
 *                                             application row
 *
 * The values are written to the CRM contact by My_IAPSNJ_Membership when the
 * order is placed offline (check) or paid — never before a contact exists,
 * and never touching member_type / paid_through, which only payments set.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Checkout_Fields {

    /** @var self|null */
    private static ?self $instance = null;

    const OPTION       = 'my_iapsnj_checkout_fields';       // ≤ 4.6 single field list; read by the v10 migration only
    const OPTION_FORMS = 'my_iapsnj_checkout_forms';        // ['forms' => id => form, 'assign' => level => id]
    const DEFAULT_FORM = 'default';
    const META_FIELDS  = '_my_iapsnj_application';          // order meta: key => value
    const META_FORM    = '_my_iapsnj_checkout_form';        // order meta: checkout form id
    const META_APPLIED = '_my_iapsnj_application_applied';  // order meta: UTC datetime
    const META_NAME    = '_my_iapsnj_typed_name';           // order meta: ['first','last'] as typed at checkout
    const PREFIX       = 'iapsnj_';                          // input name prefix
    const FORM_INPUT   = 'iapsnj__form';                     // hidden input: form printed on the page
    const PREVIEW_PARAM = 'iapsnj_preview';                  // "View checkout": form to show an administrator
    const PREVIEW_NONCE = 'iapsnj_preview_nonce';
    const NEW_TARGET   = '__new__';                          // "create a CRM custom field" picker value

    /** @var string[] 'section' is a heading row, not an input */
    const TYPES = [ 'text', 'phone', 'textarea', 'select', 'radio', 'date', 'checkbox', 'section' ];

    /** @var array<string,string> FluentCRM contact columns offered as targets */
    const DEFAULT_TARGETS = [
        'date_of_birth' => 'Date of birth',
        'prefix'        => 'Prefix (Mr / Mrs …)',
    ];

    /** @var bool Record a Check places an admin order; it is not an application. */
    private static bool $suppress = false;

    /** @var bool The fields were printed on this request. */
    private bool $rendered = false;

    /** @var bool A checkout page was rendered: print the phone formatter. */
    private bool $phone_script = false;

    /** @var array<string,array<string,array>> per-request cache: form id => parsed fields */
    private static array $config_cache = [];

    /** @var array|null per-request cache of the forms option */
    private static ?array $store_cache = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'fluent_cart/before_payment_methods',      [ $this, 'render' ], 10, 1 );
        // FluentCart's own name / email / billing fields, prefilled from the CRM contact.
        add_filter( 'fluent_cart/checkout_page_name_fields_schema', [ $this, 'prefill_name_fields' ], 20, 2 );
        add_filter( 'fluent_cart/checkout_renderer/billing_fields', [ $this, 'prefill_billing_fields' ], 20, 2 );
        add_filter( 'fluent_cart/checkout/validate_data',      [ $this, 'validate' ], 10, 2 );
        add_action( 'fluent_cart/checkout/prepare_other_data', [ $this, 'on_prepare_other_data' ], 10, 1 );
        add_action( 'fluent_cart/checkout/form_data_changed',  [ $this, 'on_form_data_changed' ], 10, 1 );
        add_action( 'fluent_cart/after_receipt_first_time',    [ $this, 'print_clear_script' ], 10, 1 );
        add_action( 'wp_footer',                               [ $this, 'print_footer_script' ], 99 );
        add_shortcode( 'iapsnj_renew_link', [ $this, 'shortcode_renew_link' ] );
    }

    public static function suppress( bool $state ): void {
        self::$suppress = $state;
    }

    /** Were the application fields printed on this request? */
    public function was_rendered(): bool {
        return $this->rendered;
    }

    // -----------------------------------------------------------------------
    // Built-in fields
    // -----------------------------------------------------------------------

    /**
     * Built-in application fields: the seed for a fresh install and the
     * fallback definition when a built-in key is missing from the saved list.
     *
     * This is the whole ACF-era onboarding form minus what FluentCart itself
     * collects (name, email, phone, billing address), the membership state
     * the payment sets (member number, type, dates) and admin notes. Keys
     * match the ACF field names so option lists can be pulled from ACF.
     *
     * crm_kind: 'custom' (FluentCRM custom field slug), 'default' (contact
     * column), 'none' (not stored on the contact).
     *
     * Dropdown option lists are empty here on purpose: they are filled by
     * import_options() (ACF choices → existing CRM values → built-in list)
     * on install / upgrade and from the Checkout Builder.
     *
     * @return array<string,array>
     */
    public static function definitions(): array {
        $custom = function ( string $label, string $help, string $type, string $slug, bool $enabled = true, bool $required = false ): array {
            return [
                'label'    => $label,
                'help'     => $help,
                'type'     => $type,
                'options'  => [],
                'crm'      => $slug,
                'crm_kind' => 'custom',
                'enabled'  => $enabled,
                'required' => $required,
            ];
        };
        $fields = [
            // -- Law-enforcement profile ------------------------------------
            'department'      => $custom( __( 'Department', 'my-iapsnj' ), __( 'Your law-enforcement agency.', 'my-iapsnj' ), 'select', My_IAPSNJ_Schema::FIELD_DEPARTMENT, true, true ),
            'rank_level'      => $custom( __( 'Rank', 'my-iapsnj' ), '', 'select', My_IAPSNJ_Schema::FIELD_RANK, true, true ),
            'retirement_date' => $custom( __( 'Retirement date (if retired)', 'my-iapsnj' ), '', 'date', My_IAPSNJ_Schema::FIELD_RETIREMENT_DATE ),
            'phone_work'      => $custom( __( 'Work phone', 'my-iapsnj' ), '', 'phone', My_IAPSNJ_Schema::FIELD_PHONE_WORK ),
            'phone2'          => $custom( __( 'Alternate phone', 'my-iapsnj' ), '', 'phone', My_IAPSNJ_Schema::FIELD_PHONE2 ),
            'union_affiliation' => $custom( __( 'Union affiliation', 'my-iapsnj' ), __( 'PBA, FOP, STFA … (optional)', 'my-iapsnj' ), 'text', My_IAPSNJ_Schema::FIELD_UNION_AFFILIATION ),
            'union_position'  => $custom( __( 'Union position', 'my-iapsnj' ), '', 'text', My_IAPSNJ_Schema::FIELD_UNION_POSITION ),
            // -- Personal ---------------------------------------------------
            // Required since 4.16 (client request); data v11 requires it in saved forms.
            'date_of_birth'   => [
                'label'    => __( 'Date of birth', 'my-iapsnj' ),
                'help'     => '',
                'type'     => 'date',
                'options'  => [],
                'crm'      => 'date_of_birth',
                'crm_kind' => 'default',
                'enabled'  => true,
                'required' => true,
            ],
            'marital_status'  => $custom( __( 'Marital status', 'my-iapsnj' ), '', 'select', My_IAPSNJ_Schema::FIELD_MARITAL_STATUS ),
            'spouse_name'     => $custom( __( 'Spouse\'s name', 'my-iapsnj' ), '', 'text', My_IAPSNJ_Schema::FIELD_SPOUSE_NAME ),
            'armed_service'   => $custom( __( 'I have served in the U.S. Armed Forces', 'my-iapsnj' ), '', 'checkbox', My_IAPSNJ_Schema::FIELD_ARMED_SERVICE ),
            // -- Employer (Associate members) -------------------------------
            'company_name'    => $custom( __( 'Employer / company name', 'my-iapsnj' ), __( 'Associate members: where you work.', 'my-iapsnj' ), 'text', My_IAPSNJ_Schema::FIELD_COMPANY_NAME ),
            'company_title'   => $custom( __( 'Job title', 'my-iapsnj' ), '', 'text', My_IAPSNJ_Schema::FIELD_COMPANY_TITLE ),
            'company_type'    => $custom( __( 'Type of business', 'my-iapsnj' ), '', 'text', My_IAPSNJ_Schema::FIELD_COMPANY_TYPE ),
            // -- Other ------------------------------------------------------
            'referred_by'     => $custom( __( 'Referred by', 'my-iapsnj' ), __( 'Name of the member who referred you (optional).', 'my-iapsnj' ), 'text', My_IAPSNJ_Schema::FIELD_REFERRED_BY ),
            'additional_information' => $custom( __( 'Additional information', 'my-iapsnj' ), __( 'Anything else you would like us to know (optional).', 'my-iapsnj' ), 'textarea', My_IAPSNJ_Schema::FIELD_ADDITIONAL_INFO ),
            // Existed on the ACF form; meaning to confirm with the client, so
            // hidden until the options are imported / the client asks for it.
            'elo_title'       => $custom( __( 'ELO title', 'my-iapsnj' ), '', 'select', My_IAPSNJ_Schema::FIELD_ELO_TITLE, false ),
            'certify'         => [
                'label'    => __( 'I certify that the information I provided is accurate and that I meet the eligibility requirements for IAPSNJ membership.', 'my-iapsnj' ),
                'help'     => '',
                'type'     => 'checkbox',
                'options'  => [],
                'crm'      => '',
                'crm_kind' => 'none',
                'enabled'  => true,
                'required' => true,
            ],
        ];
        // People's names are stored capitalised like the member's own name
        // (My_IAPSNJ_Capitalization): "mcdonald" → "McDonald".
        foreach ( [ 'spouse_name', 'referred_by' ] as $key ) {
            $fields[ $key ]['case'] = My_IAPSNJ_Capitalization::NAME;
        }
        return $fields;
    }

    /**
     * Built-in keys whose dropdown options are known without ACF or CRM data.
     *
     * @return array<string,string[]>
     */
    public static function builtin_options(): array {
        return [
            'rank_level'     => My_IAPSNJ_Schema::default_rank_options(),
            'marital_status' => My_IAPSNJ_Schema::marital_status_options(),
        ];
    }

    // -----------------------------------------------------------------------
    // Dropdown options: ACF choices → existing CRM values → built-in list
    // -----------------------------------------------------------------------

    /**
     * Candidate options for a dropdown / radio field, in order of preference:
     * the choices of the ACF user field with the same name (the retired
     * onboarding form), the distinct values already stored in the CRM custom
     * field (existing members), the distinct values in the ACF-era user meta
     * of the same name, then the built-in list. Returns
     * ['source' => 'acf'|'crm'|'usermeta'|'builtin'|'', 'options' => string[]].
     *
     * @param string $key      checkout field key (matches the ACF field name)
     * @param string $crm_slug FluentCRM custom-field slug ('' when not stored)
     */
    public static function discover_options( string $key, string $crm_slug ): array {
        $acf = self::acf_choices( $key );
        if ( $acf ) {
            return [ 'source' => 'acf', 'options' => $acf ];
        }
        if ( $crm_slug !== '' ) {
            $crm = self::crm_distinct_values( $crm_slug );
            if ( $crm ) {
                return [ 'source' => 'crm', 'options' => $crm ];
            }
        }
        $meta = self::usermeta_distinct_values( $key );
        if ( $meta ) {
            return [ 'source' => 'usermeta', 'options' => $meta ];
        }
        $builtin = self::builtin_options();
        if ( ! empty( $builtin[ $key ] ) ) {
            return [ 'source' => 'builtin', 'options' => $builtin[ $key ] ];
        }
        return [ 'source' => '', 'options' => [] ];
    }

    /**
     * Choices of the ACF user-profile field named $name (select / radio /
     * checkbox), [] when ACF is inactive or the field has none.
     *
     * @return string[]
     */
    public static function acf_choices( string $name ): array {
        if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return [];
        }
        try {
            foreach ( (array) acf_get_field_groups( [ 'user_form' => 'all' ] ) as $group ) {
                $fields = acf_get_fields( $group );
                if ( ! is_array( $fields ) ) {
                    continue;
                }
                foreach ( $fields as $field ) {
                    if ( ( $field['name'] ?? '' ) !== $name || empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
                        continue;
                    }
                    $out = [];
                    foreach ( $field['choices'] as $label ) {
                        $label = trim( (string) $label );
                        if ( $label !== '' && ! self::is_placeholder_option( $label ) ) {
                            $out[] = $label;
                        }
                    }
                    return array_values( array_unique( $out ) );
                }
            }
        } catch ( \Throwable $e ) {
            // ACF is optional; fall through
        }
        return [];
    }

    /**
     * Distinct non-empty values stored in a FluentCRM custom field, most
     * frequent first, then alphabetical (capped at 300).
     *
     * @return string[]
     */
    public static function crm_distinct_values( string $slug ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_subscriber_meta';
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT value, COUNT(*) AS n FROM {$table} WHERE object_type = 'custom_field' AND `key` = %s AND value <> '' GROUP BY value ORDER BY n DESC, value ASC LIMIT 300", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $slug
            ), ARRAY_A );
        } catch ( \Throwable $e ) {
            return [];
        }
        return self::values_from_rows( (array) $rows );
    }

    /**
     * Distinct non-empty values of a user-meta key (the ACF-era profile
     * fields), most frequent first (capped at 300).
     *
     * @return string[]
     */
    public static function usermeta_distinct_values( string $meta_key ): array {
        global $wpdb;
        $meta_key = sanitize_key( $meta_key );
        if ( $meta_key === '' ) {
            return [];
        }
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_value AS value, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value ORDER BY n DESC, meta_value ASC LIMIT 300",
                $meta_key
            ), ARRAY_A );
        } catch ( \Throwable $e ) {
            return [];
        }
        return self::values_from_rows( (array) $rows );
    }

    /**
     * @param array<int,array> $rows [['value' => string], …]; serialized arrays are expanded
     * @return string[]
     */
    private static function values_from_rows( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $value = (string) ( $row['value'] ?? '' );
            $value = is_serialized( $value ) ? maybe_unserialize( $value ) : $value;
            foreach ( (array) $value as $v ) {
                $v = trim( (string) $v );
                if ( $v !== '' && ! self::is_placeholder_option( $v ) ) {
                    $out[ $v ] = true;
                }
            }
        }
        return array_keys( $out );
    }

    private static function is_placeholder_option( string $value ): bool {
        $v = strtolower( trim( $value, " -—–\t" ) );
        return in_array( $v, [ 'n/a', 'na', 'none', 'select', 'select one', 'please select', 'choose', '' ], true );
    }

    /**
     * Fill the option list of every configured dropdown / radio field that
     * has none (or of every one, when $only_empty is false) from
     * discover_options(). Saves the configuration. Idempotent.
     *
     * @param string $form_id one form, or '' for every form
     * @return array<string,array{source:string,count:int}> key => what was imported (only fields that changed)
     */
    public static function import_options( bool $only_empty = true, string $form_id = '' ): array {
        $store  = self::store();
        $ids    = $form_id !== '' && isset( $store['forms'][ $form_id ] ) ? [ $form_id ] : array_keys( $store['forms'] );
        $report = [];
        $found_cache = [];
        foreach ( $ids as $id ) {
            $saved   = (array) ( $store['forms'][ $id ]['fields'] ?? [] );
            $changed = false;
            foreach ( self::config( $id ) as $key => $def ) {
                if ( ! in_array( $def['type'], [ 'select', 'radio' ], true ) ) {
                    continue;
                }
                if ( $only_empty && ! empty( $def['options'] ) ) {
                    continue;
                }
                $crm_slug  = $def['crm_kind'] === 'custom' ? (string) $def['crm'] : '';
                $cache_key = $key . '|' . $crm_slug;
                if ( ! isset( $found_cache[ $cache_key ] ) ) {
                    $found_cache[ $cache_key ] = self::discover_options( $key, $crm_slug );
                }
                $found = $found_cache[ $cache_key ];
                if ( ! $found['options'] || $found['options'] === (array) $def['options'] ) {
                    continue;
                }
                if ( ! isset( $saved[ $key ] ) || ! is_array( $saved[ $key ] ) ) {
                    unset( $def['builtin'] );
                    $saved[ $key ] = array_merge( [ 'key' => $key ], $def );
                }
                $saved[ $key ]['options'] = $found['options'];
                $report[ $key ]           = [ 'source' => $found['source'], 'count' => count( $found['options'] ) ];
                $changed                  = true;
            }
            if ( $changed ) {
                $store['forms'][ $id ]['fields'] = $saved;
                self::save_store( $store );
            }
        }
        return $report;
    }

    /**
     * Append built-in fields that a saved form does not know yet, with their
     * default visibility (a new release adding fields shows them without an
     * admin having to switch each one on), in every form. Existing rows are
     * not touched. Idempotent.
     *
     * @return string[] keys added (to any form)
     */
    public static function add_missing_builtins(): array {
        $store = self::store();
        $added = [];
        foreach ( $store['forms'] as $id => $form ) {
            $rows = self::rows_with_missing_builtins( (array) ( $form['fields'] ?? [] ), $keys );
            if ( $keys ) {
                $store['forms'][ $id ]['fields'] = $rows;
                $added = array_merge( $added, $keys );
            }
        }
        if ( $added ) {
            self::save_store( $store );
        }
        return array_values( array_unique( $added ) );
    }

    /**
     * $saved plus the built-ins it lacks, slotted before a trailing
     * certification checkbox.
     *
     * @param array<string|int,array> $saved
     * @param string[]|null           $added set to the keys added
     * @return array<string|int,array>
     */
    private static function rows_with_missing_builtins( array $saved, ?array &$added = null ): array {
        $added = [];
        if ( ! $saved ) {
            return $saved;
        }
        $have = [];
        foreach ( $saved as $k => $row ) {
            $have[ sanitize_key( (string) ( is_array( $row ) && isset( $row['key'] ) ? $row['key'] : $k ) ) ] = true;
        }
        $rows  = [];
        // Keep the saved order; slot new built-ins before the certification
        // checkbox when it is the last row, otherwise append.
        $certify  = null;
        $last_row = end( $saved );
        $last_key = sanitize_key( (string) ( is_array( $last_row ) && isset( $last_row['key'] ) ? $last_row['key'] : key( $saved ) ) );
        foreach ( $saved as $k => $row ) {
            $key = sanitize_key( (string) ( is_array( $row ) && isset( $row['key'] ) ? $row['key'] : $k ) );
            if ( $key === 'certify' && $last_key === 'certify' ) {
                $certify = [ $k, $row ];
                continue;
            }
            $rows[ $k ] = $row;
        }
        foreach ( self::definitions() as $key => $def ) {
            if ( isset( $have[ $key ] ) ) {
                continue;
            }
            $rows[ $key ] = array_merge( [ 'key' => $key ], $def );
            $added[]      = $key;
        }
        if ( $certify ) {
            $rows[ $certify[0] ] = $certify[1];
        }
        return $rows;
    }

    // -----------------------------------------------------------------------
    // Checkout forms and level assignment
    // -----------------------------------------------------------------------

    /**
     * Membership levels a checkout form is assigned to: member type => label.
     * Lifetime is a Regular membership without an expiry and Honorary is
     * never sold, so both use the Regular form.
     *
     * @return array<string,string>
     */
    public static function levels(): array {
        return [
            My_IAPSNJ_Schema::TYPE_REGULAR   => __( 'Regular Membership (Police Officer) — also Lifetime', 'my-iapsnj' ),
            My_IAPSNJ_Schema::TYPE_ASSOCIATE => __( 'Associate Membership (Business Owner / Friend)', 'my-iapsnj' ),
        ];
    }

    /**
     * The level keys without labels (safe before translations load).
     *
     * @return string[]
     */
    public static function level_keys(): array {
        return [ My_IAPSNJ_Schema::TYPE_REGULAR, My_IAPSNJ_Schema::TYPE_ASSOCIATE ];
    }

    /**
     * The level whose form a member type uses ('' when none).
     */
    public static function level_for_member_type( string $type ): string {
        if ( $type === '' || ! in_array( $type, My_IAPSNJ_Schema::member_types(), true ) ) {
            return '';
        }
        return $type === My_IAPSNJ_Schema::TYPE_ASSOCIATE ? My_IAPSNJ_Schema::TYPE_ASSOCIATE : My_IAPSNJ_Schema::TYPE_REGULAR;
    }

    /**
     * The forms option, normalised: ['forms' => id => [name, heading, intro,
     * fields], 'assign' => level => id]. Never empty: before the option is
     * saved it is built from the ≤ 4.6 field list (or the built-in fields).
     */
    public static function store(): array {
        if ( self::$store_cache !== null ) {
            return self::$store_cache;
        }
        $raw = get_option( self::OPTION_FORMS, false );
        if ( ! is_array( $raw ) || empty( $raw['forms'] ) || ! is_array( $raw['forms'] ) ) {
            $raw = self::initial_store();
        }
        $forms = [];
        foreach ( $raw['forms'] as $id => $form ) {
            $id = sanitize_key( (string) $id );
            if ( $id === '' || ! is_array( $form ) ) {
                continue;
            }
            $forms[ $id ] = [
                'name'    => (string) ( $form['name'] ?? '' ) !== '' ? (string) $form['name'] : $id,
                'heading' => (string) ( $form['heading'] ?? '' ),
                'intro'   => (string) ( $form['intro'] ?? '' ),
                'fields'  => is_array( $form['fields'] ?? null ) ? $form['fields'] : [],
            ];
        }
        if ( ! $forms ) {
            $forms = self::initial_store()['forms'];
        }
        $first  = (string) array_key_first( $forms );
        $assign = [];
        foreach ( self::level_keys() as $level ) {
            $id               = sanitize_key( (string) ( $raw['assign'][ $level ] ?? '' ) );
            $assign[ $level ] = isset( $forms[ $id ] ) ? $id : $first;
        }
        self::$store_cache = [ 'forms' => $forms, 'assign' => $assign ];
        return self::$store_cache;
    }

    private static function save_store( array $store ): void {
        update_option( self::OPTION_FORMS, [
            'forms'  => $store['forms'],
            'assign' => $store['assign'],
        ], false );
        self::$store_cache  = null;
        self::$config_cache = [];
    }

    /**
     * The first forms option: one form holding the ≤ 4.6 field list and the
     * heading / intro from the settings, used by every level. A fresh
     * install gets the built-in fields.
     */
    private static function initial_store(): array {
        $legacy = get_option( self::OPTION, [] );
        // Legacy rows are kept as saved (4.1 override or full-row shape):
        // config() parses either, and the v7 / v8 steps still see which
        // built-ins the list really lacks.
        $rows   = is_array( $legacy ) && $legacy
            ? $legacy
            : self::rows_from_config( self::parse_rows( [] ) );
        $settings = get_option( 'my_iapsnj_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $form     = [
            'name'    => __( 'Membership Application', 'my-iapsnj' ),
            'heading' => sanitize_text_field( (string) ( $settings['application_heading'] ?? '' ) ),
            'intro'   => sanitize_text_field( (string) ( $settings['application_intro'] ?? '' ) ),
            'fields'  => $rows,
        ];
        $assign = [];
        foreach ( self::level_keys() as $level ) {
            $assign[ $level ] = self::DEFAULT_FORM;
        }
        return [ 'forms' => [ self::DEFAULT_FORM => $form ], 'assign' => $assign ];
    }

    /**
     * id => [name, heading, intro] (no fields), in admin order.
     *
     * @return array<string,array{name:string,heading:string,intro:string}>
     */
    public static function forms(): array {
        $out = [];
        foreach ( self::store()['forms'] as $id => $form ) {
            $out[ $id ] = [
                'name'    => $form['name'],
                'heading' => $form['heading'],
                'intro'   => $form['intro'],
            ];
        }
        return $out;
    }

    public static function form_exists( string $form_id ): bool {
        return $form_id !== '' && isset( self::store()['forms'][ $form_id ] );
    }

    /**
     * level => form id (every level has one).
     *
     * @return array<string,string>
     */
    public static function assignments(): array {
        return self::store()['assign'];
    }

    /**
     * Levels that use a form.
     *
     * @return string[]
     */
    public static function levels_for_form( string $form_id ): array {
        return array_keys( array_filter( self::assignments(), function ( $id ) use ( $form_id ) {
            return $id === $form_id;
        } ) );
    }

    /**
     * The form edited / shown when no form is named: the Regular form.
     */
    public static function default_form_id(): string {
        $assign = self::assignments();
        return (string) ( $assign[ My_IAPSNJ_Schema::TYPE_REGULAR ] ?? array_key_first( self::store()['forms'] ) );
    }

    private static function resolve_form_id( string $form_id ): string {
        return self::form_exists( $form_id ) ? $form_id : self::default_form_id();
    }

    /**
     * @param array<string,string> $assign level => form id; unknown levels / forms are ignored
     */
    public static function save_assignments( array $assign ): void {
        $store = self::store();
        foreach ( self::level_keys() as $level ) {
            $id = sanitize_key( (string) ( $assign[ $level ] ?? '' ) );
            if ( isset( $store['forms'][ $id ] ) ) {
                $store['assign'][ $level ] = $id;
            }
        }
        self::save_store( $store );
    }

    /**
     * Name, section heading and intro of a form.
     */
    public static function save_form_meta( string $form_id, string $name, string $heading, string $intro ): void {
        $store = self::store();
        if ( ! isset( $store['forms'][ $form_id ] ) ) {
            return;
        }
        $name = sanitize_text_field( $name );
        $store['forms'][ $form_id ]['name']    = $name !== '' ? $name : $store['forms'][ $form_id ]['name'];
        $store['forms'][ $form_id ]['heading'] = sanitize_text_field( $heading );
        $store['forms'][ $form_id ]['intro']   = sanitize_text_field( $intro );
        self::save_store( $store );
    }

    /**
     * Create a form: a copy of $source_id (fields, heading, intro) or, with
     * no source, the built-in fields with their default visibility. Returns
     * the new form id.
     */
    public static function create_form( string $name, string $source_id = '' ): string {
        $store = self::store();
        $name  = sanitize_text_field( $name );
        if ( $source_id !== '' && isset( $store['forms'][ $source_id ] ) ) {
            $form         = $store['forms'][ $source_id ];
            $form['name'] = $name !== '' ? $name : sprintf( /* translators: form name */ __( '%s (copy)', 'my-iapsnj' ), $form['name'] );
        } else {
            $form = [
                'name'    => $name !== '' ? $name : __( 'New checkout form', 'my-iapsnj' ),
                'heading' => '',
                'intro'   => '',
                'fields'  => self::rows_from_config( self::parse_rows( [] ) ),
            ];
        }
        $base = sanitize_key( str_replace( ' ', '_', strtolower( remove_accents( $form['name'] ) ) ) );
        $base = substr( $base !== '' ? $base : 'form', 0, 30 );
        $id   = $base;
        $n    = 2;
        while ( isset( $store['forms'][ $id ] ) ) {
            $id = $base . '_' . $n++;
        }
        $store['forms'][ $id ] = $form;
        self::save_store( $store );
        return $id;
    }

    public static function duplicate_form( string $form_id ): string {
        return self::form_exists( $form_id ) ? self::create_form( '', $form_id ) : '';
    }

    /**
     * Delete a form that no level uses (and never the last one).
     *
     * @return true|WP_Error
     */
    public static function delete_form( string $form_id ) {
        $store = self::store();
        if ( ! isset( $store['forms'][ $form_id ] ) ) {
            return new WP_Error( 'not_found', __( 'That checkout form no longer exists.', 'my-iapsnj' ) );
        }
        if ( count( $store['forms'] ) < 2 ) {
            return new WP_Error( 'last_form', __( 'The last checkout form cannot be deleted.', 'my-iapsnj' ) );
        }
        if ( self::levels_for_form( $form_id ) ) {
            return new WP_Error( 'in_use', __( 'This form is used by a membership level. Assign another form to that level first.', 'my-iapsnj' ) );
        }
        unset( $store['forms'][ $form_id ] );
        self::save_store( $store );
        return true;
    }

    // -----------------------------------------------------------------------
    // Which form a checkout / order uses
    // -----------------------------------------------------------------------

    /**
     * Highest member type among variations configured in Membership
     * Products ('' when none is a membership). Same ranking as
     * My_IAPSNJ_Membership::plan_for_order().
     *
     * @param int[] $variation_ids
     */
    public static function member_type_for_variations( array $variation_ids ): string {
        $config = My_IAPSNJ_Membership::products_config();
        $type   = '';
        foreach ( $variation_ids as $vid ) {
            $cfg = $config[ (int) $vid ] ?? null;
            if ( $cfg && My_IAPSNJ_Schema::member_type_rank( (string) $cfg['member_type'] ) > My_IAPSNJ_Schema::member_type_rank( $type ) ) {
                $type = (string) $cfg['member_type'];
            }
        }
        return $type;
    }

    public static function form_for_member_type( string $type ): string {
        $level = self::level_for_member_type( $type );
        return $level === '' ? '' : (string) ( self::assignments()[ $level ] ?? '' );
    }

    /**
     * The checkout form for a cart; '' when the cart holds no membership
     * product (or cannot be read), i.e. no application fields.
     *
     * @param object|null $cart FluentCart Cart
     */
    public static function form_for_cart( $cart ): string {
        $vids = self::cart_variation_ids( $cart );
        if ( ! $vids ) {
            return '';
        }
        return self::form_for_member_type( self::member_type_for_variations( $vids ) );
    }

    /**
     * The checkout form an order was placed with: the id stored on the order,
     * else the form of the membership it buys, else ''.
     *
     * @param object $order FluentCart Order
     */
    public static function form_for_order( $order ): string {
        if ( ! is_object( $order ) ) {
            return '';
        }
        try {
            $stored = sanitize_key( (string) $order->getMeta( self::META_FORM ) );
            if ( self::form_exists( $stored ) ) {
                return $stored;
            }
            $plan = My_IAPSNJ_Membership::plan_for_order( $order );
            return $plan ? self::form_for_member_type( (string) $plan['member_type'] ) : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    // -----------------------------------------------------------------------
    // Configuration (ordered field list of one form)
    // -----------------------------------------------------------------------

    /**
     * A form's fields in display order: key => definition (label, help,
     * type, options, crm, crm_kind, enabled, required, builtin).
     *
     * @param string $form_id '' or unknown → the default (Regular) form
     * @return array<string,array>
     */
    public static function config( string $form_id = '' ): array {
        $form_id = self::resolve_form_id( $form_id );
        if ( isset( self::$config_cache[ $form_id ] ) ) {
            return self::$config_cache[ $form_id ];
        }
        $rows = (array) ( self::store()['forms'][ $form_id ]['fields'] ?? [] );
        self::$config_cache[ $form_id ] = self::parse_rows( $rows );
        return self::$config_cache[ $form_id ];
    }

    /**
     * Field definitions for reading stored values: the form's own, then any
     * key only another form defines (the form was edited after the order).
     *
     * @return array<string,array>
     */
    public static function field_defs( string $form_id = '' ): array {
        $defs = self::config( $form_id );
        foreach ( array_keys( self::store()['forms'] ) as $id ) {
            $defs += self::config( $id );
        }
        return $defs;
    }

    /**
     * Saved rows → field definitions (reads the 4.1 per-key override shape
     * and the full-row shape); built-ins missing from $saved are kept,
     * disabled (or with their defaults when $saved is empty).
     *
     * @param array<string|int,mixed> $saved
     * @return array<string,array>
     */
    private static function parse_rows( array $saved ): array {
        $builtins = self::definitions();
        $out      = [];

        foreach ( $saved as $k => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $key = sanitize_key( (string) ( $row['key'] ?? $k ) );
            if ( $key === '' || isset( $out[ $key ] ) ) {
                continue;
            }
            $base = $builtins[ $key ] ?? [
                'label'    => '',
                'help'     => '',
                'type'     => 'text',
                'options'  => [],
                'crm'      => '',
                'crm_kind' => 'none',
                'enabled'  => true,
                'required' => false,
            ];
            $def = $base;
            // 4.1.0 stored only overrides per built-in key (no 'type'); a full
            // row always carries 'type'. Both shapes are read.
            if ( array_key_exists( 'enabled', $row ) ) {
                $def['enabled'] = ! empty( $row['enabled'] );
            }
            if ( array_key_exists( 'required', $row ) ) {
                $def['required'] = ! empty( $row['required'] );
            }
            if ( isset( $row['label'] ) && trim( (string) $row['label'] ) !== '' ) {
                $def['label'] = (string) $row['label'];
            }
            if ( isset( $row['help'] ) ) {
                $def['help'] = (string) $row['help'];
            }
            if ( isset( $row['options'] ) && is_array( $row['options'] ) ) {
                $def['options'] = array_values( array_filter( array_map( 'trim', array_map( 'strval', $row['options'] ) ), 'strlen' ) );
            }
            // A built-in field's type comes from definitions() (its type
            // picker is read-only), so a changed built-in type reaches forms
            // saved before the change, e.g. Work phone → Phone in 4.13.
            if ( ! isset( $builtins[ $key ] ) && isset( $row['type'] ) && in_array( $row['type'], self::TYPES, true ) ) {
                $def['type'] = $row['type'];
            }
            if ( array_key_exists( 'crm_kind', $row ) && in_array( $row['crm_kind'], [ 'custom', 'default', 'none' ], true ) ) {
                $def['crm_kind'] = $row['crm_kind'];
                $def['crm']      = $row['crm_kind'] === 'none' ? '' : sanitize_key( (string) ( $row['crm'] ?? '' ) );
            }
            $def['parent']    = sanitize_key( (string) ( $row['parent'] ?? '' ) );
            $def['show_when'] = self::clean_values( $row['show_when'] ?? [] );
            if ( $def['label'] === '' ) {
                continue;
            }
            $def['builtin']  = isset( $builtins[ $key ] );
            $def['required'] = $def['required'] && $def['enabled'] && $def['type'] !== 'section';
            $out[ $key ]     = $def;
        }

        // Built-ins missing from the saved list are kept, disabled, so they
        // can be switched on again from the screen.
        foreach ( $builtins as $key => $def ) {
            if ( ! isset( $out[ $key ] ) ) {
                $def['enabled']   = $saved ? false : $def['enabled'];
                $def['required']  = $def['required'] && $def['enabled'];
                $def['builtin']   = true;
                $def['parent']    = '';
                $def['show_when'] = [];
                $out[ $key ]      = $def;
            }
        }

        // Fresh install (nothing saved): built-in order and defaults.
        return self::normalise_conditions( $out );
    }

    /**
     * @param mixed $raw array or one value per line
     * @return string[]
     */
    private static function clean_values( $raw ): array {
        if ( is_string( $raw ) ) {
            $raw = preg_split( '/\r\n|\r|\n/', $raw );
        }
        $out = [];
        foreach ( (array) $raw as $v ) {
            $v = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
            if ( $v !== '' && ! in_array( $v, $out, true ) ) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Conditional display is one level deep: a child ('parent' set) is shown
     * only when its parent — the nearest shown, top-level input above it,
     * with no section heading in between — has an answer (a ticked box, or
     * one of 'show_when' for a dropdown / radio; any answer when 'show_when'
     * is empty). Anything else loses its condition here: a hidden row, a
     * heading, a parent that is itself a child or not directly above.
     * 'show_when' keeps only values the parent can take.
     *
     * @param array<string,array> $defs in display order
     * @return array<string,array>
     */
    private static function normalise_conditions( array $defs ): array {
        $top = '';
        foreach ( $defs as $key => $def ) {
            $parent = (string) ( $def['parent'] ?? '' );
            if ( empty( $def['enabled'] ) || $def['type'] === 'section' ) {
                $defs[ $key ]['parent']    = '';
                $defs[ $key ]['show_when'] = [];
                if ( $def['type'] === 'section' && ! empty( $def['enabled'] ) ) {
                    $top = ''; // a heading starts a new group
                }
                continue;
            }
            if ( $parent === '' || $parent !== $top ) {
                $defs[ $key ]['parent']    = '';
                $defs[ $key ]['show_when'] = [];
                $top                       = (string) $key;
                continue;
            }
            $p    = $defs[ $parent ];
            $when = (array) ( $def['show_when'] ?? [] );
            if ( in_array( $p['type'], [ 'select', 'radio' ], true ) && ! empty( $p['options'] ) ) {
                $kept = array_values( array_intersect( $when, (array) $p['options'] ) );
                // Answers that are no longer options keep the child hidden (and
                // not required) instead of widening it to "any answer"; the
                // editor asks for new ones.
                $when = ( $when && ! $kept ) ? array_values( $when ) : $kept;
            } else {
                $when = []; // tick box / free text: "is ticked" / "has an answer"
            }
            $defs[ $key ]['show_when'] = $when;
        }
        return $defs;
    }

    /**
     * Is a (child) field shown for these answers?
     *
     * @param array<string,string> $values key => sanitized value of the parents
     * @param array<string,array>  $fields the form's shown inputs
     */
    public static function condition_met( array $def, array $values, array $fields ): bool {
        $parent = (string) ( $def['parent'] ?? '' );
        if ( $parent === '' ) {
            return true;
        }
        if ( ! isset( $fields[ $parent ] ) ) {
            return false;
        }
        $value = (string) ( $values[ $parent ] ?? '' );
        if ( $value === '' ) {
            return false;
        }
        $when = (array) ( $def['show_when'] ?? [] );
        return ! $when || in_array( $value, $when, true );
    }

    /**
     * The form's inputs shown for a request: top-level inputs, plus children
     * whose condition the posted parent answer meets. A child that is not
     * shown is neither required nor stored.
     *
     * @param array<string,mixed> $request posted checkout data (iapsnj_* keys)
     * @return array<string,array>
     */
    public static function visible_inputs( string $form_id, array $request ): array {
        $fields = self::input_fields( $form_id );
        $values = [];
        foreach ( $fields as $key => $def ) {
            if ( (string) ( $def['parent'] ?? '' ) === '' ) {
                $values[ $key ] = self::sanitize_value( $def, $request[ self::input_name( $key ) ] ?? null );
            }
        }
        return array_filter( $fields, function ( $def ) use ( $values, $fields ) {
            return self::condition_met( $def, $values, $fields );
        } );
    }

    /**
     * Parsed definitions → the saved row shape (key first, no 'builtin').
     *
     * @param array<string,array> $config
     * @return array<string,array>
     */
    private static function rows_from_config( array $config ): array {
        $rows = [];
        foreach ( $config as $key => $def ) {
            unset( $def['builtin'] );
            $rows[ $key ] = array_merge( [ 'key' => $key ], $def );
        }
        return $rows;
    }

    /**
     * Shown rows of a form, section headings included, in display order.
     *
     * @param string $form_id '' → the default (Regular) form
     * @return array<string,array>
     */
    public static function enabled_fields( string $form_id = '' ): array {
        return array_filter( self::config( $form_id ), function ( $def ) {
            return ! empty( $def['enabled'] );
        } );
    }

    /**
     * Shown inputs of a form (section headings left out): what the member
     * fills in, what is validated and stored.
     *
     * @return array<string,array>
     */
    public static function input_fields( string $form_id = '' ): array {
        return array_filter( self::enabled_fields( $form_id ), function ( $def ) {
            return $def['type'] !== 'section';
        } );
    }

    // -----------------------------------------------------------------------
    // FluentCRM fields a form does not use yet
    // -----------------------------------------------------------------------

    /**
     * FluentCRM custom fields (membership-state fields excluded): slug => definition.
     *
     * @return array<string,array>
     */
    public static function crm_custom_fields(): array {
        $out    = [];
        $system = My_IAPSNJ_Schema::system_fields();
        $custom = function_exists( 'fluentcrm_get_option' ) ? fluentcrm_get_option( 'contact_custom_fields', [] ) : [];
        foreach ( (array) $custom as $cf ) {
            if ( ! is_array( $cf ) || empty( $cf['slug'] ) ) {
                continue;
            }
            $slug = sanitize_key( (string) $cf['slug'] );
            if ( $slug === '' || in_array( $slug, $system, true ) ) {
                continue;
            }
            $out[ $slug ] = $cf;
        }
        return $out;
    }

    /**
     * Checkout field type for a FluentCRM custom field type.
     *
     * @param string[] $options
     */
    public static function type_from_crm( string $crm_type, array $options, string $name = '' ): string {
        switch ( $crm_type ) {
            case 'textarea':
                return 'textarea';
            case 'date':
            case 'date_time':
                return 'date';
            case 'radio':
                return $options ? 'radio' : 'text';
            case 'select-one':
            case 'select-multi':
                return $options ? 'select' : 'text';
            case 'checkbox':
                // One option ("Yes") is a tick box; several are a choice.
                return count( $options ) > 1 ? 'select' : 'checkbox';
            default:
                // A CRM text field named like a phone number is offered as one.
                return preg_match( '/phone|mobile|cell|\bfax\b/i', $name ) ? 'phone' : 'text';
        }
    }

    /**
     * Every FluentCRM field no row of the form writes to, as a hidden row the
     * admin can switch on: key => definition (+ 'auto' => true). Contact
     * columns FluentCart already collects (name, email, phone, address) and
     * the membership-state fields are not offered.
     *
     * With $include_used the fields a row already writes to are returned too,
     * flagged 'used' => true: the editor keeps them out of sight and shows
     * one again as soon as no row writes to it any more.
     *
     * @return array<string,array>
     */
    public static function crm_candidates( string $form_id = '', bool $include_used = false ): array {
        $config  = self::config( $form_id );
        $used    = [];
        foreach ( $config as $def ) {
            $used[ self::target_value( $def ) ] = true;
        }
        $taken = $config + self::definitions();
        $key_for = function ( string $slug ) use ( &$taken ): string {
            $key = sanitize_key( $slug );
            while ( isset( $taken[ $key ] ) ) {
                $key = 'crm_' . $key;
            }
            $taken[ $key ] = true;
            return $key;
        };
        $out = [];
        foreach ( self::DEFAULT_TARGETS as $column => $label ) {
            $in_use = isset( $used[ 'default:' . $column ] );
            if ( $in_use && ! $include_used ) {
                continue;
            }
            $out[ $key_for( $column ) ] = [
                'used'     => $in_use,
                'label'    => $label,
                'help'     => '',
                'type'     => $column === 'date_of_birth' ? 'date' : 'text',
                'options'  => [],
                'crm'      => $column,
                'crm_kind' => 'default',
                'enabled'  => false,
                'required' => false,
                'builtin'  => false,
                'auto'     => true,
            ];
        }
        foreach ( self::crm_custom_fields() as $slug => $cf ) {
            $in_use = isset( $used[ 'custom:' . $slug ] );
            if ( $in_use && ! $include_used ) {
                continue;
            }
            $options = array_values( array_filter( array_map( 'strval', (array) ( $cf['options'] ?? [] ) ), 'strlen' ) );
            $type    = self::type_from_crm( (string) ( $cf['type'] ?? 'text' ), $options, $slug . ' ' . (string) ( $cf['label'] ?? '' ) );
            $out[ $key_for( $slug ) ] = [
                'used'     => $in_use,
                'label'    => (string) ( $cf['label'] ?? $slug ) !== '' ? (string) $cf['label'] : $slug,
                'help'     => '',
                'type'     => $type,
                'options'  => in_array( $type, [ 'select', 'radio' ], true ) ? $options : [],
                'crm'      => $slug,
                'crm_kind' => 'custom',
                'enabled'  => false,
                'required' => false,
                'builtin'  => false,
                'auto'     => true,
            ];
        }
        return $out;
    }

    /**
     * FluentCRM targets a field can be written to, for the admin picker:
     * value => label. Values are "none", "default:column", "custom:slug" and
     * "__new__" (create a custom field named after the checkout field).
     *
     * @return array<string,string>
     */
    public static function crm_targets(): array {
        $out = [ 'none' => __( '— not stored on the contact —', 'my-iapsnj' ) ];
        foreach ( self::DEFAULT_TARGETS as $column => $label ) {
            $out[ 'default:' . $column ] = $label . ' ' . __( '(contact field)', 'my-iapsnj' );
        }
        // Membership-state fields (member_type, paid_through …) are left
        // out: only payments set them.
        foreach ( self::crm_custom_fields() as $slug => $cf ) {
            $out[ 'custom:' . $slug ] = (string) ( $cf['label'] ?? $slug ) . ' (' . $slug . ')';
        }
        $out[ self::NEW_TARGET ] = __( '+ Create a new CRM custom field for this field', 'my-iapsnj' );
        return $out;
    }

    public static function target_value( array $def ): string {
        if ( $def['crm_kind'] === 'custom' && $def['crm'] !== '' ) {
            return 'custom:' . $def['crm'];
        }
        if ( $def['crm_kind'] === 'default' && $def['crm'] !== '' ) {
            return 'default:' . $def['crm'];
        }
        return 'none';
    }

    /**
     * Persist the field list of one form. Refused (nothing saved, no CRM
     * field created) when two rows would write the same FluentCRM field.
     *
     * @param array<string|int,array> $rows Posted rows: ['key','label','help','type','options'(string|array),'crm_target','enabled','required','order','parent','show_when','auto']
     * @param string                  $form_id '' → the default (Regular) form
     * @return true|WP_Error
     */
    public static function save_config( array $rows, string $form_id = '' ) {
        $form_id  = self::resolve_form_id( $form_id );
        $builtins = self::definitions();
        $existing = self::field_defs( $form_id );
        $ordered  = [];
        $position = 0;
        foreach ( $rows as $row_id => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $order = isset( $row['order'] ) && is_numeric( $row['order'] ) ? (int) $row['order'] : $position;
            $ordered[] = [ $order, $position, $row_id, $row ];
            $position++;
        }
        usort( $ordered, function ( $a, $b ) {
            return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
        } );

        $clean    = [];
        $used     = [];
        $row_to   = []; // posted row id (a new row's temporary id) => saved key
        $create   = []; // key => [label, type, options]: "+ Create a new CRM custom field"
        $reserved = []; // keys posted by existing rows: a new row never takes one
        foreach ( $ordered as [ , , , $row ] ) {
            if ( empty( $row['auto'] ) || ! empty( $row['enabled'] ) ) {
                $posted_key = sanitize_key( (string) ( $row['key'] ?? '' ) );
                if ( $posted_key !== '' ) {
                    $reserved[ $posted_key ] = true;
                }
            }
        }
        foreach ( $ordered as [ , , $row_id, $row ] ) {
            // A FluentCRM field offered in "Inactive" and left hidden is not
            // saved: it is offered again from the CRM on the next visit.
            if ( ! empty( $row['auto'] ) && empty( $row['enabled'] ) ) {
                continue;
            }
            $label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            $key   = sanitize_key( (string) ( $row['key'] ?? '' ) );
            $is_builtin = $key !== '' && isset( $builtins[ $key ] );
            if ( $label === '' ) {
                if ( $is_builtin ) {
                    $label = $builtins[ $key ]['label'];
                } else {
                    continue; // a custom field or section needs a label
                }
            }
            $is_section = ! $is_builtin && (string) ( $row['type'] ?? '' ) === 'section';
            $generated  = $key === '';
            if ( $generated ) {
                $key = ( $is_section ? 'section_' : 'app_' ) . sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
                $key = substr( $key, 0, 40 ) ?: ( $is_section ? 'section' : 'app_field' );
            }
            $base_key = $key;
            $n        = 2;
            while ( isset( $used[ $key ] ) || ( $generated && isset( $reserved[ $key ] ) ) ) {
                $key = $base_key . '_' . $n++;
            }
            $used[ $key ] = true;

            $type = $is_builtin ? $builtins[ $base_key ]['type'] : (string) ( $row['type'] ?? 'text' );
            if ( ! in_array( $type, self::TYPES, true ) ) {
                $type = 'text';
            }
            $options = $row['options'] ?? [];
            if ( is_string( $options ) ) {
                $options = preg_split( '/\r\n|\r|\n/', $options );
            }
            $options = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $options ) ), 'strlen' ) );

            // CRM target.
            $target   = (string) ( $row['crm_target'] ?? '' );
            $crm_kind = 'none';
            $crm      = '';
            if ( $target === '' && isset( $existing[ $key ] ) ) {
                $target = self::target_value( $existing[ $key ] );
            }
            if ( $type === 'section' ) {
                $target = 'none';
            }
            if ( $target === self::NEW_TARGET ) {
                // Created below, once the form is known to be valid; the slug
                // is the row's key (create_crm_field()).
                $crm_kind       = 'custom';
                $crm            = sanitize_key( $key );
                $create[ $key ] = [ $label, $type, $options ];
            } elseif ( strpos( $target, 'custom:' ) === 0 ) {
                $crm_kind = 'custom';
                $crm      = sanitize_key( substr( $target, 7 ) );
            } elseif ( strpos( $target, 'default:' ) === 0 ) {
                $column = sanitize_key( substr( $target, 8 ) );
                if ( isset( self::DEFAULT_TARGETS[ $column ] ) ) {
                    $crm_kind = 'default';
                    $crm      = $column;
                }
            }
            if ( $crm === '' || ( $crm_kind === 'custom' && in_array( $crm, My_IAPSNJ_Schema::system_fields(), true ) ) ) {
                $crm_kind = 'none';
                $crm      = '';
            }

            $enabled = ! empty( $row['enabled'] );
            $row_to[ sanitize_key( (string) $row_id ) ] = $key;
            $clean[ $key ] = [
                'key'       => $key,
                'label'     => $label,
                'help'      => sanitize_text_field( (string) ( $row['help'] ?? '' ) ),
                'type'      => $type,
                'options'   => in_array( $type, [ 'select', 'radio' ], true ) ? $options : [],
                'crm'       => $crm,
                'crm_kind'  => $crm_kind,
                'enabled'   => $enabled,
                // A hidden field is never required; a heading never is.
                'required'  => $enabled && $type !== 'section' && ! empty( $row['required'] ),
                // Posted as the parent row's id; resolved to its key below.
                'parent'    => $type === 'section' ? '' : sanitize_key( (string) ( $row['parent'] ?? '' ) ),
                'show_when' => self::clean_values( $row['show_when'] ?? [] ),
            ];
        }
        // Two rows writing one CRM field would overwrite each other's answer.
        $by_target = [];
        foreach ( $clean as $def ) {
            if ( $def['crm_kind'] !== 'none' && $def['crm'] !== '' && $def['type'] !== 'section' ) {
                $by_target[ $def['crm_kind'] . ':' . $def['crm'] ][] = $def['label'];
            }
        }
        $dupes = array_filter( $by_target, function ( $labels ) {
            return count( $labels ) > 1;
        } );
        if ( $dupes ) {
            $lines = [];
            foreach ( $dupes as $target => $labels ) {
                $lines[] = substr( $target, strpos( $target, ':' ) + 1 ) . ': ' . implode( ', ', $labels );
            }
            return new WP_Error( 'duplicate_crm_field', sprintf(
                /* translators: %s: CRM field slug followed by the labels of the rows writing to it */
                __( 'Not saved: several fields are stored in the same FluentCRM field (%s). Pick another "Stored in FluentCRM as" for all but one.', 'my-iapsnj' ),
                implode( '; ', $lines )
            ) );
        }
        foreach ( $create as $key => [ $label, $type, $options ] ) {
            $slug = self::create_crm_field( $key, $label, $type, $options );
            $clean[ $key ]['crm']      = $slug;
            $clean[ $key ]['crm_kind'] = $slug !== '' ? 'custom' : 'none';
        }

        foreach ( $clean as $key => $def ) {
            if ( $def['parent'] === '' ) {
                continue;
            }
            $parent = $row_to[ $def['parent'] ] ?? ( isset( $clean[ $def['parent'] ] ) ? $def['parent'] : '' );
            $clean[ $key ]['parent'] = $parent;
            // A child goes wherever its parent goes: hidden with it.
            if ( $parent !== '' && empty( $clean[ $parent ]['enabled'] ) ) {
                $clean[ $key ]['enabled']  = false;
                $clean[ $key ]['required'] = false;
            }
        }
        $clean = self::rows_from_config( self::normalise_conditions( $clean ) );
        $store = self::store();
        $store['forms'][ $form_id ]['fields'] = $clean;
        self::save_store( $store );
        return true;
    }

    /**
     * Create a FluentCRM custom field for a checkout field (idempotent by
     * slug). Returns the slug, '' on failure.
     *
     * @param string[] $options
     */
    public static function create_crm_field( string $key, string $label, string $type, array $options ): string {
        $slug = sanitize_key( $key );
        if ( $slug === '' ) {
            return '';
        }
        $fields = fluentcrm_get_option( 'contact_custom_fields', [] );
        if ( ! is_array( $fields ) ) {
            $fields = [];
        }
        foreach ( $fields as $f ) {
            if ( ( $f['slug'] ?? '' ) === $slug ) {
                return $slug;
            }
        }
        $map = [
            'text'     => 'text',
            'phone'    => 'text',
            'textarea' => 'textarea',
            'select'   => 'select-one',
            'radio'    => 'radio',
            'date'     => 'date',
            'checkbox' => 'checkbox',
        ];
        $def = [
            'group' => 'default',
            'slug'  => $slug,
            'label' => $label,
            'type'  => $map[ $type ] ?? 'text',
        ];
        if ( in_array( $def['type'], [ 'select-one', 'radio' ], true ) ) {
            $def['options'] = $options;
        } elseif ( $def['type'] === 'checkbox' ) {
            $def['options'] = [ 'Yes' ];
        }
        $fields[] = $def;
        fluentcrm_update_option( 'contact_custom_fields', array_values( $fields ) );
        return $slug;
    }

    /**
     * Save the forms option if it does not exist yet (idempotent). An
     * install upgraded from ≤ 4.6 gets one form holding its field list and
     * the heading / intro from the settings, used by every level.
     */
    public static function seed_defaults(): void {
        if ( get_option( self::OPTION_FORMS ) === false ) {
            self::$store_cache = null;
            add_option( self::OPTION_FORMS, self::initial_store(), '', false );
            self::$store_cache  = null;
            self::$config_cache = [];
        }
    }

    /**
     * Data migration (v7): rewrite every form's rows in the ordered full-row
     * format (the 4.1.0 per-key override shape is read by parse_rows()).
     * Safe to run repeatedly.
     */
    public static function upgrade_config(): void {
        self::seed_defaults();
        $store = self::store();
        foreach ( array_keys( $store['forms'] ) as $id ) {
            $store['forms'][ $id ]['fields'] = self::rows_from_config( self::parse_rows( (array) $store['forms'][ $id ]['fields'] ) );
        }
        self::save_store( $store );
    }

    /**
     * Switch built-in fields on in every form (data migration v8).
     *
     * @param string[] $keys
     */
    public static function enable_fields( array $keys ): void {
        $store   = self::store();
        $changed = false;
        foreach ( $store['forms'] as $id => $form ) {
            foreach ( $keys as $key ) {
                if ( isset( $form['fields'][ $key ] ) && is_array( $form['fields'][ $key ] ) && empty( $form['fields'][ $key ]['enabled'] ) ) {
                    $store['forms'][ $id ]['fields'][ $key ]['enabled'] = true;
                    $changed = true;
                }
            }
        }
        if ( $changed ) {
            self::save_store( $store );
        }
    }

    /**
     * Show and require the field that writes the contact's date of birth,
     * in every form (data migration v11). A form whose shown row writing
     * date_of_birth is an added field gets that row required; otherwise the
     * built-in Date of birth row is shown and required. Idempotent.
     *
     * @return int rows changed
     */
    public static function require_date_of_birth(): int {
        self::add_missing_builtins();
        $store   = self::store();
        $changed = 0;
        foreach ( array_keys( $store['forms'] ) as $id ) {
            $defs = self::config( (string) $id );
            $keys = [];
            foreach ( $defs as $key => $def ) {
                if ( ! empty( $def['enabled'] ) && $def['type'] !== 'section' && self::target_value( $def ) === 'default:date_of_birth' ) {
                    $keys[] = (string) $key;
                }
            }
            if ( ! $keys && isset( $defs['date_of_birth'] ) ) {
                $keys = [ 'date_of_birth' ];
            }
            $rows = (array) $store['forms'][ $id ]['fields'];
            foreach ( $keys as $key ) {
                $slot = null;
                foreach ( $rows as $k => $row ) {
                    if ( is_array( $row ) && sanitize_key( (string) ( $row['key'] ?? $k ) ) === $key ) {
                        $slot = $k;
                        break;
                    }
                }
                if ( $slot === null ) {
                    $def = $defs[ $key ];
                    unset( $def['builtin'] );
                    $slot          = $key;
                    $rows[ $slot ] = array_merge( [ 'key' => $key ], $def );
                }
                if ( empty( $rows[ $slot ]['enabled'] ) || empty( $rows[ $slot ]['required'] ) ) {
                    $rows[ $slot ]['enabled']  = true;
                    $rows[ $slot ]['required'] = true;
                    $changed++;
                }
            }
            $store['forms'][ $id ]['fields'] = $rows;
        }
        if ( $changed ) {
            self::save_store( $store );
        }
        return $changed;
    }

    public static function input_name( string $key ): string {
        return self::PREFIX . $key;
    }

    // -----------------------------------------------------------------------
    // Values
    // -----------------------------------------------------------------------

    /**
     * Normalise one posted value for a field definition. '' when empty.
     *
     * @param mixed $raw
     */
    public static function sanitize_value( array $def, $raw ): string {
        if ( is_array( $raw ) ) {
            $raw = reset( $raw );
        }
        $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
        switch ( $def['type'] ) {
            case 'checkbox':
                return ( $raw !== '' && $raw !== '0' && strtolower( $raw ) !== 'no' ) ? 'yes' : '';
            case 'date':
                return My_IAPSNJ_Dates::ymd( $raw );
            case 'textarea':
                return sanitize_textarea_field( $raw );
            case 'phone':
                // Stored ready to read ("+1 908-415-2478"): CRM text fields
                // are not formatted by FluentCRM.
                return My_IAPSNJ_Phone::display( sanitize_text_field( $raw ) );
            default:
                $value = sanitize_text_field( $raw );
                $case  = (string) ( $def['case'] ?? '' );
                return $case !== '' ? My_IAPSNJ_Capitalization::apply( $value, $case ) : $value;
        }
    }

    /**
     * A form's enabled-field values found in a request payload: key => value
     * (non-empty only).
     *
     * @return array<string,string>
     */
    public static function collect( array $request, string $form_id = '' ): array {
        $out = [];
        // Children whose condition is not met are dropped: what a member
        // typed before changing the parent answer is not stored.
        foreach ( self::visible_inputs( $form_id, $request ) as $key => $def ) {
            $value = self::sanitize_value( $def, $request[ self::input_name( $key ) ] ?? null );
            if ( $value !== '' ) {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }

    /**
     * Human-readable rows for an order's stored application: label => value.
     *
     * @param object $order FluentCart Order
     * @return array<string,string>
     */
    public static function summary_for_order( $order ): array {
        $values = My_IAPSNJ_Membership::meta_array( $order, self::META_FIELDS );
        return is_array( $values ) ? self::summary( $values, self::form_for_order( $order ) ) : [];
    }

    /**
     * @param array<string,string> $values
     * @param string               $form_id the form the values were collected with ('' = any)
     * @return array<string,string>
     */
    public static function summary( array $values, string $form_id = '' ): array {
        $out    = [];
        $config = self::field_defs( $form_id );
        foreach ( $values as $key => $value ) {
            if ( ! isset( $config[ $key ] ) || $value === '' ) {
                continue;
            }
            $def = $config[ $key ];
            if ( $def['type'] === 'section' ) {
                continue;
            }
            if ( $def['type'] === 'checkbox' ) {
                $out[ $def['label'] ] = __( 'Yes', 'my-iapsnj' );
            } elseif ( $def['type'] === 'date' ) {
                $out[ $def['label'] ] = My_IAPSNJ_Dates::ymd_display( $value );
            } else {
                $out[ $def['label'] ] = (string) $value;
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/before_payment_methods — print the application fields.
     *
     * @param mixed $args FluentCart passes its view context; ['cart'] when available.
     */
    public function render( $args = [] ): void {
        $args    = is_array( $args ) ? $args : [];
        // "View checkout" from the Checkout Builder: an administrator sees
        // the named form whatever the cart holds.
        $preview = self::preview_form_id();
        $form_id = $preview !== '' ? $preview : self::form_for_cart( $args['cart'] ?? null );
        if ( $form_id === '' ) {
            return; // no membership product in the cart: a plain store checkout
        }
        if ( ! self::input_fields( $form_id ) ) {
            return; // headings alone are not an application
        }
        $this->rendered = true;
        wp_enqueue_style( 'my-iapsnj-checkout', MY_IAPSNJ_URL . 'public/css/checkout-fields.css', [], MY_IAPSNJ_VERSION );

        $values  = $this->prefill_values( $args, $form_id );
        $form    = self::forms()[ $form_id ];
        $heading = $form['heading'];
        $intro   = $form['intro'];

        echo '<div class="fct-checkout-section my-iapsnj-application" id="my-iapsnj-application" data-my-iapsnj-form="' . esc_attr( $form_id ) . '">';
        if ( $preview !== '' ) {
            echo '<p class="my-iapsnj-preview-notice" role="note">' . esc_html( sprintf(
                /* translators: %s: checkout form name */
                __( 'Preview of the checkout form "%s" (only administrators see this notice). An order placed from here is refused if the cart needs a different form.', 'my-iapsnj' ),
                $form['name']
            ) ) . '</p>';
        }
        echo '<h3 class="fct-section-title my-iapsnj-application-title">' . esc_html( $heading !== '' ? $heading : __( 'Membership Application', 'my-iapsnj' ) ) . '</h3>';
        if ( $intro !== '' ) {
            echo '<p class="my-iapsnj-application-intro">' . esc_html( $intro ) . '</p>';
        }
        // Which form was printed: validate() refuses the order when the cart
        // changed on the page to one that needs another form.
        echo '<input type="hidden" name="' . esc_attr( self::FORM_INPUT ) . '" value="' . esc_attr( $form_id ) . '">';
        // Conditional fields start shown or hidden for the prefilled answers;
        // the footer script keeps them in step as the member answers.
        $inputs  = self::input_fields( $form_id );
        $parents = [];
        foreach ( $inputs as $key => $def ) {
            if ( (string) ( $def['parent'] ?? '' ) === '' ) {
                $parents[ $key ] = self::sanitize_value( $def, $values[ $key ] ?? '' );
            }
        }
        foreach ( self::sections( $form_id ) as $section ) {
            if ( ! $section['fields'] ) {
                continue; // a heading with nothing shown under it
            }
            $shown = [];
            foreach ( $section['fields'] as $key => $def ) {
                $shown[ $key ] = self::condition_met( $def, $parents, $inputs );
            }
            if ( $section['key'] !== '' ) {
                $title_id = 'my-iapsnj-section-' . sanitize_html_class( $section['key'] );
                echo '<div class="my-iapsnj-section" role="group" aria-labelledby="' . esc_attr( $title_id ) . '" data-my-iapsnj-section="' . esc_attr( $section['key'] ) . '"' . ( in_array( true, $shown, true ) ? '' : ' style="display:none"' ) . '>';
                echo '<h3 class="my-iapsnj-section-title" id="' . esc_attr( $title_id ) . '">' . esc_html( $section['label'] ) . '</h3>';
                if ( $section['help'] !== '' ) {
                    echo '<p class="my-iapsnj-section-intro">' . esc_html( $section['help'] ) . '</p>';
                }
            }
            foreach ( $section['fields'] as $key => $def ) {
                $this->render_field( $key, $def, (string) ( $values[ $key ] ?? '' ), $shown[ $key ] );
            }
            if ( $section['key'] !== '' ) {
                echo '</div>';
            }
        }
        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // "View checkout" preview (administrators)
    // -----------------------------------------------------------------------

    /**
     * The form an administrator asked to preview on the checkout page
     * (?iapsnj_preview=<form>&iapsnj_preview_nonce=…), '' otherwise. The
     * preview only changes what the administrator sees; validate() still
     * decides by the cart, so an order that needs another form is refused.
     */
    public static function preview_form_id(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below
        $raw = $_GET[ self::PREVIEW_PARAM ] ?? '';
        if ( ! is_string( $raw ) || $raw === '' || ! current_user_can( 'manage_options' ) ) {
            return '';
        }
        $form_id = sanitize_key( wp_unslash( $raw ) );
        if ( ! self::form_exists( $form_id ) ) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified here
        $nonce = $_GET[ self::PREVIEW_NONCE ] ?? '';
        $nonce = is_string( $nonce ) ? sanitize_text_field( wp_unslash( $nonce ) ) : '';
        return wp_verify_nonce( $nonce, 'my_iapsnj_preview_' . $form_id ) ? $form_id : '';
    }

    /**
     * The mapped membership product a preview of $form_id opens with: one of
     * the level the form is assigned to (Regular before Lifetime), else any
     * membership product. 0 when none is mapped.
     */
    public static function preview_variation( string $form_id ): int {
        $levels   = self::levels_for_form( $form_id );
        $want     = $levels ? $levels[0] : My_IAPSNJ_Schema::TYPE_REGULAR;
        $best     = 0;
        $fallback = 0;
        foreach ( My_IAPSNJ_Membership::products_config() as $vid => $cfg ) {
            $type = (string) $cfg['member_type'];
            if ( $fallback === 0 ) {
                $fallback = (int) $vid;
            }
            if ( self::level_for_member_type( $type ) !== $want ) {
                continue;
            }
            if ( $best === 0 || $type === $want ) {
                $best = (int) $vid;
                if ( $type === $want ) {
                    break;
                }
            }
        }
        return $best ?: $fallback;
    }

    /**
     * Front-end checkout URL that shows $form_id to the current administrator
     * ('' when no membership product is mapped). Opening it puts the product
     * in the administrator's cart; nothing is charged unless an order is placed.
     */
    public static function preview_url( string $form_id ): string {
        $vid = self::form_exists( $form_id ) ? self::preview_variation( $form_id ) : 0;
        if ( $vid <= 0 ) {
            return '';
        }
        return My_IAPSNJ_Membership::checkout_url( $vid, [
            self::PREVIEW_PARAM => $form_id,
            self::PREVIEW_NONCE => wp_create_nonce( 'my_iapsnj_preview_' . $form_id ),
        ] );
    }

    /**
     * A form's shown rows grouped under their section headings, in order.
     * The first group ('key' => '') holds the inputs before any heading.
     *
     * @return array<int,array{key:string,label:string,help:string,fields:array<string,array>}>
     */
    public static function sections( string $form_id = '' ): array {
        $groups = [ [ 'key' => '', 'label' => '', 'help' => '', 'fields' => [] ] ];
        foreach ( self::enabled_fields( $form_id ) as $key => $def ) {
            if ( $def['type'] === 'section' ) {
                $groups[] = [ 'key' => (string) $key, 'label' => (string) $def['label'], 'help' => (string) $def['help'], 'fields' => [] ];
                continue;
            }
            $groups[ count( $groups ) - 1 ]['fields'][ $key ] = $def;
        }
        return $groups;
    }

    /**
     * One application input. A conditional field (one with a parent) carries
     * its rule for the footer script and starts hidden and disabled — so it
     * is neither submitted nor browser-validated — unless $shown.
     */
    private function render_field( string $key, array $def, string $value, bool $shown = true ): void {
        $name     = self::input_name( $key );
        $id       = 'my-iapsnj-' . sanitize_html_class( $key );
        $required = ! empty( $def['required'] );
        $req_attr = $required ? ' required aria-required="true"' : '';
        $star     = $required ? ' <span class="fct_required my-iapsnj-required" aria-hidden="true">*</span>' : '';
        $help     = (string) ( $def['help'] ?? '' );
        $type     = (string) $def['type'];
        $options  = (array) ( $def['options'] ?? [] );
        $parent   = (string) ( $def['parent'] ?? '' );
        $req_attr .= $shown ? '' : ' disabled';

        $wrap = ' data-my-iapsnj-field="' . esc_attr( $key ) . '"';
        if ( $parent !== '' ) {
            $wrap .= ' data-my-iapsnj-parent="' . esc_attr( self::input_name( $parent ) ) . '"'
                . ' data-my-iapsnj-show-when="' . esc_attr( (string) wp_json_encode( array_values( (array) ( $def['show_when'] ?? [] ) ) ) ) . '"'
                . ( $shown ? '' : ' style="display:none"' );
        }
        echo '<div class="fct_form_group my-iapsnj-field my-iapsnj-field-' . esc_attr( $type ) . ( $parent !== '' ? ' my-iapsnj-conditional' : '' ) . '"' . $wrap . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above

        if ( $type === 'checkbox' ) {
            echo '<label class="fct_input_label fct_input_label_checkbox my-iapsnj-checkbox" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" class="fct-input fct-input-checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="yes"' . checked( $value, 'yes', false ) . $req_attr . '> ';
            echo '<span>' . esc_html( $def['label'] ) . '</span>' . $star; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $star is static markup
            echo '</label>';
        } elseif ( $type === 'radio' && $options ) {
            echo '<fieldset class="my-iapsnj-radio-group"><legend class="fct_input_label">' . esc_html( $def['label'] ) . $star . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $i = 0;
            foreach ( $options as $opt ) {
                $oid = $id . '-' . $i++;
                echo '<label class="fct_input_label fct_input_label_checkbox my-iapsnj-radio" for="' . esc_attr( $oid ) . '">';
                echo '<input type="radio" class="fct-input" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt ) . '"' . checked( $value, $opt, false ) . ( $required && $i === 1 ? ' required' : '' ) . ( $shown ? '' : ' disabled' ) . '> ';
                echo '<span>' . esc_html( $opt ) . '</span></label>';
            }
            echo '</fieldset>';
        } else {
            echo '<label class="fct_input_label" for="' . esc_attr( $id ) . '">' . esc_html( $def['label'] ) . $star . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ( $type === 'select' && $options ) {
                echo '<select class="fct-input fct-select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req_attr . '>';
                echo '<option value="">' . esc_html__( '— Select —', 'my-iapsnj' ) . '</option>';
                foreach ( $options as $opt ) {
                    echo '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
                }
                echo '</select>';
            } elseif ( $type === 'textarea' ) {
                echo '<textarea class="fct-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . $req_attr . '>' . esc_textarea( $value ) . '</textarea>';
            } elseif ( $type === 'phone' ) {
                echo '<input type="tel" inputmode="tel" class="fct-input my-iapsnj-phone" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( My_IAPSNJ_Phone::display( $value ) ) . '" placeholder="+1 555-555-5555" autocomplete="off"' . $req_attr . '>';
            } else {
                $input_type = $type === 'date' ? 'date' : 'text';
                $case       = $input_type === 'text' ? (string) ( $def['case'] ?? '' ) : '';
                $extra      = $input_type === 'text' ? ' autocomplete="off"' : '';
                if ( $case !== '' ) {
                    // Capitalised as the member leaves the field (My_IAPSNJ_Capitalization::print_script()).
                    $extra .= ' data-my-iapsnj-case="' . esc_attr( $case ) . '"';
                }
                if ( $input_type === 'date' && ( $def['crm'] ?? '' ) === 'date_of_birth' ) {
                    $extra .= ' max="' . esc_attr( My_IAPSNJ_Dates::today() ) . '"'; // a birth date is in the past
                }
                echo '<input type="' . esc_attr( $input_type ) . '" class="fct-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req_attr . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above
            }
        }
        if ( $help !== '' ) {
            echo '<p class="fct_input_help my-iapsnj-help">' . esc_html( $help ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * The CRM contact behind the current checkout: the logged-in user's
     * contact (by user id or email), the FluentCRM secure-link cookie (a
     * member arriving from a CRM email), or the email already typed into the
     * cart. Null when none.
     *
     * @param object|null $cart FluentCart Cart
     */
    public static function current_contact( $cart = null ): ?Subscriber {
        static $cache = [];
        $cart_key = is_object( $cart ) && ! empty( $cart->cart_hash ) ? (string) $cart->cart_hash : '-';
        if ( array_key_exists( $cart_key, $cache ) ) {
            return $cache[ $cart_key ];
        }
        $contact = null;
        try {
            if ( function_exists( 'fluentcrm_get_current_contact' ) ) {
                $contact = fluentcrm_get_current_contact();
            }
            if ( ! $contact instanceof Subscriber && get_current_user_id() > 0 ) {
                $contact = My_IAPSNJ_Engine::find_linked_subscriber( get_current_user_id() );
            }
            if ( ! $contact instanceof Subscriber && is_object( $cart ) ) {
                $email = '';
                if ( ! empty( $cart->email ) ) {
                    $email = (string) $cart->email;
                }
                if ( $email === '' ) {
                    $cd    = $cart->checkout_data;
                    $email = is_array( $cd ) ? (string) ( $cd['form_data']['billing_email'] ?? ( $cd['billing_email'] ?? '' ) ) : '';
                }
                $email = sanitize_email( $email );
                if ( is_email( $email ) ) {
                    $contact = Subscriber::where( 'email', $email )->first();
                }
            }
        } catch ( \Throwable $e ) {
            $contact = null;
        }
        $cache[ $cart_key ] = $contact instanceof Subscriber ? $contact : null;
        return $cache[ $cart_key ];
    }

    /**
     * fluent_cart/checkout_page_name_fields_schema — first name, last name,
     * full name and email from the CRM contact when FluentCart has nothing.
     *
     * @param mixed $fields
     * @param mixed $data ['cart','scope']
     * @return mixed
     */
    public function prefill_name_fields( $fields, $data = [] ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }
        $contact = self::current_contact( is_array( $data ) ? ( $data['cart'] ?? null ) : null );
        if ( ! $contact instanceof Subscriber ) {
            return $fields;
        }
        $map = [
            'billing_first_name' => (string) $contact->first_name,
            'billing_last_name'  => (string) $contact->last_name,
            'billing_full_name'  => trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ),
            'billing_email'      => (string) $contact->email,
        ];
        foreach ( $map as $key => $value ) {
            if ( $value !== '' && isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) && empty( $fields[ $key ]['value'] ) ) {
                $fields[ $key ]['value'] = $value;
            }
        }
        return $fields;
    }

    /**
     * fluent_cart/checkout_renderer/billing_fields — address and phone from
     * the CRM contact when the cart holds nothing yet. Country and state are
     * matched against FluentCart's option lists (code or name).
     *
     * @param mixed $fields keyed country, address_1, address_2, state, city, postcode, phone …
     * @param mixed $data   ['checkout_renderer','cart']
     * @return mixed
     */
    public function prefill_billing_fields( $fields, $data = [] ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }
        $this->phone_script = true; // a checkout page: format its phone inputs

        $contact = self::current_contact( is_array( $data ) ? ( $data['cart'] ?? null ) : null );
        if ( ! $contact instanceof Subscriber ) {
            return $fields;
        }
        $map = [
            'address_1' => (string) $contact->address_line_1,
            'address_2' => (string) $contact->address_line_2,
            'city'      => (string) $contact->city,
            'postcode'  => (string) $contact->postal_code,
            'phone'     => My_IAPSNJ_Phone::display( (string) $contact->phone ),
            'country'   => (string) $contact->country,
            'state'     => (string) $contact->state,
        ];
        foreach ( $map as $key => $value ) {
            $value = trim( $value );
            if ( $value === '' || ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) || ! empty( $fields[ $key ]['value'] ) ) {
                continue;
            }
            $options = $fields[ $key ]['options'] ?? null;
            if ( is_array( $options ) && $options ) {
                $value = self::match_option( $options, $value );
                if ( $value === '' ) {
                    continue;
                }
                if ( $key === 'state' && ! self::option_exists( $options, $value ) ) {
                    // The states list was built for another (or no) country; add ours so it can be selected.
                    $fields[ $key ]['options'][] = [ 'name' => $value, 'value' => $value ];
                }
            }
            $fields[ $key ]['value'] = $value;
        }
        return $fields;
    }

    /**
     * Resolve a stored value ("US", "United States", "NJ", "New Jersey") to
     * an option value; the raw value when no option matches (text states).
     *
     * @param array<int,array> $options [['name','value'], …]
     */
    private static function match_option( array $options, string $value ): string {
        foreach ( $options as $opt ) {
            if ( ! is_array( $opt ) ) {
                continue;
            }
            if ( strcasecmp( (string) ( $opt['value'] ?? '' ), $value ) === 0 && (string) $opt['value'] !== '' ) {
                return (string) $opt['value'];
            }
        }
        foreach ( $options as $opt ) {
            if ( is_array( $opt ) && strcasecmp( (string) ( $opt['name'] ?? '' ), $value ) === 0 && (string) ( $opt['value'] ?? '' ) !== '' ) {
                return (string) $opt['value'];
            }
        }
        return $value;
    }

    private static function option_exists( array $options, string $value ): bool {
        foreach ( $options as $opt ) {
            if ( is_array( $opt ) && (string) ( $opt['value'] ?? '' ) === $value ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Values to prefill the application fields: the CRM contact behind this
     * checkout (renewals, members arriving from a CRM email), then whatever
     * the cart already holds for this session.
     *
     * @return array<string,string>
     */
    private function prefill_values( array $args, string $form_id ): array {
        $values = [];
        $config = self::input_fields( $form_id );

        try {
            $subscriber = self::current_contact( $args['cart'] ?? null );
            if ( $subscriber instanceof Subscriber ) {
                $custom = $subscriber->custom_fields();
                foreach ( $config as $key => $def ) {
                    if ( $def['crm_kind'] === 'custom' && ! empty( $custom[ $def['crm'] ] ) ) {
                        $v = $custom[ $def['crm'] ];
                        $values[ $key ] = is_array( $v ) ? (string) reset( $v ) : (string) $v;
                    } elseif ( $def['crm_kind'] === 'default' && ! empty( $subscriber->{ $def['crm'] } ) ) {
                        $values[ $key ] = (string) $subscriber->{ $def['crm'] };
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // prefill is best effort
        }

        $cart = $args['cart'] ?? null;
        if ( is_object( $cart ) ) {
            try {
                $cd = $cart->checkout_data;
                if ( is_array( $cd ) ) {
                    foreach ( $config as $key => $def ) {
                        $name = self::input_name( $key );
                        if ( isset( $cd[ $name ] ) && is_scalar( $cd[ $name ] ) && (string) $cd[ $name ] !== '' ) {
                            $values[ $key ] = (string) $cd[ $name ];
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                // ignore
            }
        }

        foreach ( $values as $key => $v ) {
            $values[ $key ] = self::sanitize_value( $config[ $key ], $v );
        }
        return $values;
    }

    /**
     * Keep typed values across FluentCart's client-side re-renders of the
     * checkout form (sessionStorage, cleared on the receipt page), and show
     * each conditional field only while its parent's answer matches (a
     * hidden one is disabled, so it is neither submitted nor
     * browser-validated; a section whose fields are all hidden is hidden).
     * validate() / collect() apply the same rule on the server.
     */
    public function print_footer_script(): void {
        if ( $this->rendered || $this->phone_script ) {
            self::print_phone_script();
            My_IAPSNJ_Capitalization::print_script();
        }
        if ( ! $this->rendered ) {
            return;
        }
        $prefix = wp_json_encode( self::PREFIX );
        echo '<script>(function(){var P=' . $prefix . ',K="my_iapsnj_application";' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded constant
            . 'function all(){return document.querySelectorAll(\'[name^="\'+P+\'"]\');}'
            . 'function read(){try{return JSON.parse(sessionStorage.getItem(K)||"{}")}catch(e){return {}}}'
            . 'function save(){var o=read();all().forEach(function(i){if(i.type==="radio"){if(i.checked){o[i.name]=i.value;}}else{o[i.name]=i.type==="checkbox"?(i.checked?"yes":""):i.value;}});try{sessionStorage.setItem(K,JSON.stringify(o))}catch(e){}}'
            . 'function restore(){var o=read();all().forEach(function(i){if(!(i.name in o)){return;}if(i.type==="checkbox"){if(!i.checked&&o[i.name]==="yes"){i.checked=true;}}else if(i.type==="radio"){if(i.value===o[i.name]){i.checked=true;}}else if(!i.value&&o[i.name]){i.value=o[i.name];}});}'
            . 'function val(n){var v="";document.querySelectorAll(\'[name="\'+n+\'"]\').forEach(function(i){if(i.type==="checkbox"){if(i.checked){v="yes";}}else if(i.type==="radio"){if(i.checked){v=i.value;}}else{v=String(i.value||"").trim();}});return v;}'
            . 'function cond(){document.querySelectorAll("[data-my-iapsnj-parent]").forEach(function(w){var s=[];try{s=JSON.parse(w.getAttribute("data-my-iapsnj-show-when")||"[]");}catch(e){}var v=val(w.getAttribute("data-my-iapsnj-parent")),on=v!==""&&(!s.length||s.indexOf(v)>-1);w.style.display=on?"":"none";w.querySelectorAll("input,select,textarea").forEach(function(i){i.disabled=!on;});});'
            . 'document.querySelectorAll("[data-my-iapsnj-section]").forEach(function(g){var any=false;g.querySelectorAll(".my-iapsnj-field").forEach(function(f){if(f.style.display!=="none"){any=true;}});g.style.display=any?"":"none";});}'
            . 'function watch(e){if(e.target&&e.target.name&&e.target.name.indexOf(P)===0){save();cond();}}'
            . 'document.addEventListener("change",watch,true);document.addEventListener("input",watch,true);'
            . 'restore();cond();var n=0,t=setInterval(function(){restore();cond();if(++n>120){clearInterval(t);}},1000);'
            . '})();</script>' . "\n";
    }

    /**
     * Format phone inputs as they are typed: FluentCart's billing / shipping
     * phone and the application's Phone fields. A US number (10 digits, or
     * 11 starting with 1) becomes "+1 908-415-2478"; a number starting with
     * + and another country code, or more than 10 digits, is left as typed
     * (never cut short, so validation can reject it). The server applies the
     * same rule (My_IAPSNJ_Phone), so this is only the typing experience.
     */
    private static function print_phone_script(): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script
        echo '<script>(function(){'
            . 'var S=\'input[name="billing_phone"],input[name="shipping_phone"],input.my-iapsnj-phone\';'
            . 'function fmt(v){var t=String(v||"").trim();if(!t){return"";}if(/[a-z]/i.test(t)){return v;}var d=t.replace(/\D/g,"");if(t.charAt(0)==="+"&&d.charAt(0)!=="1"){return v;}if(d.charAt(0)==="1"){d=d.slice(1);}if(d.length>10){return v;}if(!d){return t.charAt(0)==="+"?t:"";}var o="+1 "+d.slice(0,3);if(d.length>3){o+="-"+d.slice(3,6);}if(d.length>6){o+="-"+d.slice(6);}return o;}'
            . 'function apply(i){var v=i.value,n=fmt(v);if(n===v){return;}var c=i.selectionStart,end=c===null||c>=v.length;var ds=v.replace(/\D/g,""),k=v.slice(0,c||0).replace(/\D/g,"").length;if(ds.charAt(0)==="1"&&k>0){k--;}i.value=n;if(end||document.activeElement!==i){return;}var p=3,seen=0;while(p<n.length&&seen<k){if(/\d/.test(n.charAt(p))){seen++;}p++;}try{i.setSelectionRange(p,p);}catch(e){}}'
            . 'function on(e){var i=e.target;if(i&&i.matches&&i.matches(S)){apply(i);}}'
            . 'document.addEventListener("input",on,true);document.addEventListener("change",on,true);document.addEventListener("blur",on,true);'
            . 'function all(){document.querySelectorAll(S).forEach(apply);}all();var n=0,t=setInterval(function(){all();if(++n>20){clearInterval(t);}},1000);'
            . '})();</script>' . "\n";
    }

    /**
     * fluent_cart/after_receipt_first_time — the order exists; forget the draft.
     */
    public function print_clear_script( $data = null ): void {
        echo '<script>try{sessionStorage.removeItem("my_iapsnj_application");}catch(e){}</script>' . "\n";
    }

    // -----------------------------------------------------------------------
    // Validate
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/checkout/validate_data
     *
     * @param mixed $errors array field => [rule => message]
     * @param mixed $args   ['data' => posted checkout data, …]
     * @return array
     */
    public function validate( $errors, $args = [] ) {
        if ( ! is_array( $errors ) ) {
            $errors = [];
        }
        if ( self::$suppress ) {
            return $errors;
        }
        $form_id = self::form_for_cart( is_array( $args ) ? ( $args['cart'] ?? null ) : null );
        if ( $form_id === '' ) {
            return $errors; // no membership product: nothing of ours to require
        }
        $data   = is_array( $args ) && isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : [];
        $fields = self::input_fields( $form_id );
        if ( ! $fields ) {
            return $errors;
        }
        // The page printed another form (or none): the cart changed on the
        // checkout page (order bump, item added). Per-field errors would name
        // fields the member cannot see, so ask for a reload instead.
        $printed = sanitize_key( (string) ( $data[ self::FORM_INPUT ] ?? '' ) );
        if ( $printed !== $form_id ) {
            $errors[ self::FORM_INPUT ]['changed'] = __( 'Your cart changed and needs a different membership application. Please reload the checkout page and complete the form shown.', 'my-iapsnj' );
            return $errors;
        }
        // A conditional field that is not shown for these answers is not required.
        foreach ( self::visible_inputs( $form_id, $data ) as $key => $def ) {
            $name  = self::input_name( $key );
            $value = self::sanitize_value( $def, $data[ $name ] ?? null );
            $label = $def['type'] === 'checkbox' ? __( 'Certification', 'my-iapsnj' ) : $def['label'];
            if ( $value === '' ) {
                if ( ! empty( $def['required'] ) ) {
                    $errors[ $name ]['required'] = $def['type'] === 'checkbox'
                        ? sprintf( /* translators: checkbox label */ __( 'Please tick: %s', 'my-iapsnj' ), $def['label'] )
                        : sprintf( /* translators: field label */ __( '%s is required.', 'my-iapsnj' ), $label );
                }
                continue;
            }
            if ( $def['type'] === 'phone' && ! My_IAPSNJ_Phone::is_valid( $value ) ) {
                $errors[ $name ]['invalid'] = sprintf( /* translators: field label */ __( '%s: enter a 10-digit US phone number, or an international number starting with +.', 'my-iapsnj' ), $label );
            }
            if ( $def['type'] === 'date' && My_IAPSNJ_Dates::ymd( $value ) === '' ) {
                $errors[ $name ]['invalid'] = sprintf( /* translators: field label */ __( '%s is not a valid date.', 'my-iapsnj' ), $label );
            } elseif ( $def['type'] === 'date' && ( $def['crm'] ?? '' ) === 'date_of_birth' && $value > My_IAPSNJ_Dates::today() ) {
                $errors[ $name ]['invalid'] = sprintf( /* translators: field label */ __( '%s cannot be in the future.', 'my-iapsnj' ), $label );
            }
            if ( in_array( $def['type'], [ 'select', 'radio' ], true ) && ! empty( $def['options'] ) && ! in_array( $value, $def['options'], true ) ) {
                $errors[ $name ]['invalid'] = sprintf( /* translators: field label */ __( 'Please choose a valid %s.', 'my-iapsnj' ), $label );
            }
        }
        return $errors;
    }

    // -----------------------------------------------------------------------
    // Order created (before payment)
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/checkout/prepare_other_data — the draft order exists. Store
     * the application values on it and record / update the application row.
     *
     * @param mixed $args ['cart','order','prev_order','request_data','validated_data']
     */
    public function on_prepare_other_data( $args ): void {
        if ( self::$suppress || ! is_array( $args ) ) {
            return;
        }
        $order = $args['order'] ?? null;
        $cart  = $args['cart'] ?? null;
        if ( ! is_object( $order ) || empty( $order->id ) ) {
            return;
        }
        try {
            $request = [];
            foreach ( [ 'request_data', 'validated_data' ] as $k ) {
                if ( isset( $args[ $k ] ) && is_array( $args[ $k ] ) ) {
                    $request = array_merge( $args[ $k ], $request );
                }
            }
            // The form is the one the checkout page showed (decided by the
            // cart); a cart without a membership product has none.
            $form_id = self::form_for_cart( $cart );
            if ( $form_id === '' ) {
                // Application inputs posted but no mapped membership product:
                // most likely the products were recreated and the variation
                // ids in Membership Products are stale.
                $posted = array_filter( array_keys( $request ), function ( $k ) {
                    return is_string( $k ) && strpos( $k, self::PREFIX ) === 0;
                } );
                if ( $posted && method_exists( $order, 'addLog' ) ) {
                    $order->addLog(
                        'My IAPSNJ: application fields ignored (product not mapped)',
                        'The checkout posted application fields but no item is configured in My IAPSNJ → Membership Products, so nothing was stored and no membership will be applied. Items: ' . self::order_item_ids( $order ),
                        'warning',
                        'My IAPSNJ'
                    );
                }
                return;
            }
            $values = self::collect( $request, $form_id );
            $order->updateMeta( self::META_FORM, $form_id );
            if ( $values ) {
                $order->updateMeta( self::META_FIELDS, $values );
                $order->deleteMeta( self::META_APPLIED );
            }
            $plan = My_IAPSNJ_Membership::plan_for_order( $order );
            if ( ! $plan ) {
                if ( $values && method_exists( $order, 'addLog' ) ) {
                    $order->addLog(
                        'My IAPSNJ: application received (product not mapped)',
                        'The order carries application answers but none of its items is configured in My IAPSNJ → Membership Products, so no membership will be applied on payment. Items: ' . self::order_item_ids( $order ),
                        'warning',
                        'My IAPSNJ'
                    );
                }
                return;
            }

            $email = '';
            $first = '';
            $last  = '';
            if ( isset( $order->customer ) && is_object( $order->customer ) ) {
                $email = (string) $order->customer->email;
                $first = (string) $order->customer->first_name;
                $last  = (string) $order->customer->last_name;
            }
            if ( ! is_email( $email ) ) {
                $email = (string) ( $request['billing_email'] ?? '' );
            }
            // The First name / Last name the member typed win over the
            // FluentCart customer: FluentCart joins the two and splits them
            // again at the last space ("Mary" + "Van Dyke" → "Mary Van" /
            // "Dyke"). Kept on the order for the CRM contact on payment.
            $typed = self::typed_names( $request );
            if ( $typed ) {
                [ $first, $last ] = $typed;
                $order->updateMeta( self::META_NAME, [ 'first' => $first, 'last' => $last ] );
            } elseif ( $first === '' && $last === '' ) {
                [ $first, $last ] = self::names_from_checkout( $request );
            }
            $app = My_IAPSNJ_Applications::record_checkout( $cart, $order, $email, $first, $last, $values );

            if ( method_exists( $order, 'addLog' ) ) {
                $lines = [];
                foreach ( self::summary( $values, $form_id ) as $label => $value ) {
                    $lines[] = $label . ': ' . $value;
                }
                $order->addLog(
                    'My IAPSNJ: application received',
                    ( $app ? sprintf( 'Application #%d (%s). ', (int) $app->id, (string) $app->kind ) : '' )
                        . sprintf( 'Checkout form: %s. ', self::forms()[ $form_id ]['name'] )
                        . ( $lines ? implode( ' · ', $lines ) : 'No application fields submitted.' ),
                    'info',
                    'My IAPSNJ'
                );
            }
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: could not store application for order ' . (int) $order->id . ': ' . $e->getMessage() );
        }
    }

    // -----------------------------------------------------------------------
    // Email typed at checkout → contact + Checkout-Abandoned + application row
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/checkout/form_data_changed
     *
     * @param mixed $data ['cart' => Cart]
     */
    public function on_form_data_changed( $data ): void {
        if ( self::$suppress || ! is_array( $data ) ) {
            return;
        }
        $cart = $data['cart'] ?? null;
        if ( ! is_object( $cart ) ) {
            return;
        }
        try {
            $cd = $cart->checkout_data;
            if ( ! is_array( $cd ) ) {
                return;
            }
            $email = sanitize_email( (string) ( $cd['billing_email'] ?? '' ) );
            if ( ! is_email( $email ) ) {
                return;
            }
            if ( ! self::cart_has_membership( $cart ) ) {
                return;
            }
            [ $first, $last ] = self::names_from_checkout( $cd );
            My_IAPSNJ_Applications::record_checkout( $cart, null, $email, $first, $last, [] );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: form_data_changed handler failed: ' . $e->getMessage() );
        }
    }

    /**
     * "variation #12 (Regular Membership), …" for order logs.
     */
    public static function order_item_ids( $order ): string {
        $out = [];
        try {
            foreach ( $order->order_items ?? [] as $item ) {
                $out[] = 'variation #' . (int) ( $item->object_id ?? 0 ) . ' (' . trim( (string) ( $item->post_title ?? '' ) . ' ' . (string) ( $item->title ?? '' ) ) . ')';
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
        return $out ? implode( ', ', $out ) : '(none)';
    }

    /**
     * First / last name from FluentCart checkout data (several field layouts).
     *
     * @return array{0:string,1:string}
     */
    public static function names_from_checkout( array $cd ): array {
        $typed = self::typed_names( $cd );
        if ( $typed ) {
            return $typed;
        }
        $first = '';
        $last  = '';
        $full  = sanitize_text_field( (string) ( $cd['billing_full_name'] ?? ( $cd['full_name'] ?? '' ) ) );
        if ( $full !== '' ) {
            $parts = preg_split( '/\s+/', trim( My_IAPSNJ_Capitalization::name( $full ) ) );
            $first = (string) array_shift( $parts );
            $last  = (string) implode( ' ', $parts );
        }
        return [ $first, $last ];
    }

    /**
     * The separate First name / Last name posted by the checkout (FluentCart
     * → Settings → Checkout Fields → First / Last name), capitalised; null
     * when the checkout asked for one full name instead.
     *
     * @return array{0:string,1:string}|null
     */
    public static function typed_names( array $cd ): ?array {
        $first = sanitize_text_field( (string) ( $cd['billing_first_name'] ?? ( $cd['first_name'] ?? '' ) ) );
        $last  = sanitize_text_field( (string) ( $cd['billing_last_name'] ?? ( $cd['last_name'] ?? '' ) ) );
        if ( $first === '' && $last === '' ) {
            return null;
        }
        return [ My_IAPSNJ_Capitalization::name( $first ), My_IAPSNJ_Capitalization::name( $last ) ];
    }

    /**
     * Does the cart hold a product configured in Membership Products? False
     * when the item layout cannot be read: the store also sells events and
     * merchandise, which must never be treated as an application.
     */
    public static function cart_has_membership( $cart ): bool {
        $vids = self::cart_variation_ids( $cart );
        return $vids ? self::member_type_for_variations( $vids ) !== '' : false;
    }

    /**
     * Variation ids in a cart; null when the layout is not recognised.
     *
     * @return int[]|null
     */
    public static function cart_variation_ids( $cart ): ?array {
        if ( ! is_object( $cart ) ) {
            return null;
        }
        try {
            $items = $cart->cart_data;
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( ! is_array( $items ) ) {
            return null;
        }
        if ( ! $items ) {
            return [];
        }
        $out        = [];
        $recognised = false;
        foreach ( $items as $item ) {
            if ( is_object( $item ) ) {
                $item = (array) $item;
            }
            if ( ! is_array( $item ) ) {
                continue;
            }
            foreach ( [ 'object_id', 'variation_id', 'item_id' ] as $k ) {
                if ( isset( $item[ $k ] ) && is_numeric( $item[ $k ] ) ) {
                    $out[]      = (int) $item[ $k ];
                    $recognised = true;
                    break;
                }
            }
        }
        return $recognised ? array_values( array_unique( $out ) ) : null;
    }

    // -----------------------------------------------------------------------
    // Write to the CRM contact (called by My_IAPSNJ_Membership)
    // -----------------------------------------------------------------------

    /**
     * Copy the order's application values onto the CRM contact. Blank values
     * never erase existing data. Runs once per order (meta flag) so a later
     * manual edit in the CRM survives the check being marked paid.
     *
     * @param object $order FluentCart Order
     * @return string[] CRM keys written
     */
    public static function apply_to_contact( $order, Subscriber $subscriber ): array {
        if ( ! is_object( $order ) || $order->getMeta( self::META_APPLIED ) ) {
            return [];
        }
        $values = My_IAPSNJ_Membership::meta_array( $order, self::META_FIELDS );
        if ( ! is_array( $values ) || ! $values ) {
            return [];
        }
        $custom    = [];
        $defaults  = [];
        $system    = My_IAPSNJ_Schema::system_fields();
        $crm_defs  = self::crm_custom_fields();
        foreach ( self::field_defs( self::form_for_order( $order ) ) as $key => $def ) {
            if ( $def['type'] === 'section' ) {
                continue;
            }
            $value = isset( $values[ $key ] ) ? self::sanitize_value( $def, $values[ $key ] ) : '';
            if ( $value === '' || $def['crm'] === '' ) {
                continue;
            }
            if ( $def['crm_kind'] === 'custom' ) {
                if ( in_array( $def['crm'], $system, true ) ) {
                    continue; // member_type, paid_through … are set by payments only
                }
                if ( $def['type'] === 'checkbox' ) {
                    // The CRM field's own option text ("Yes" for the built-ins).
                    $crm_options = array_values( array_filter( array_map( 'strval', (array) ( $crm_defs[ $def['crm'] ]['options'] ?? [] ) ), 'strlen' ) );
                    $value       = $crm_options ? $crm_options[0] : 'Yes';
                }
                // FluentCRM stores checkbox / multi-select custom fields as
                // an array of chosen options.
                if ( in_array( My_IAPSNJ_Schema::custom_field_type( $def['crm'] ), [ 'checkbox', 'select-multi' ], true ) ) {
                    $value = [ $value ];
                }
                $custom[ $def['crm'] ] = $value;
            } elseif ( $def['crm_kind'] === 'default' && isset( self::DEFAULT_TARGETS[ $def['crm'] ] ) ) {
                $defaults[ $def['crm'] ] = $value;
            }
        }
        $written = [];
        if ( $custom ) {
            My_IAPSNJ_Schema::set_fields( $subscriber, $custom );
            $written = array_keys( $custom );
        }
        if ( $defaults ) {
            foreach ( $defaults as $column => $value ) {
                $subscriber->{ $column } = $value;
                $written[]              = $column;
            }
            $subscriber->save();
        }
        $order->updateMeta( self::META_APPLIED, My_IAPSNJ_Dates::now_utc() );
        return $written;
    }

    // -----------------------------------------------------------------------
    // Renewal link
    // -----------------------------------------------------------------------

    /**
     * Instant-checkout URL for the member's renewal product ('' when none
     * applies: comped member, or renewal products not configured).
     */
    public static function renewal_url_for_user( int $user_id ): string {
        $settings = My_IAPSNJ_Plugin::settings();
        $type     = '';
        if ( $user_id > 0 ) {
            try {
                $subscriber = My_IAPSNJ_Engine::find_linked_subscriber( $user_id );
                if ( $subscriber instanceof Subscriber ) {
                    $type = My_IAPSNJ_Schema::field( $subscriber, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE );
                }
            } catch ( \Throwable $e ) {
                $type = '';
            }
        }
        if ( My_IAPSNJ_Schema::is_comped_type( $type ) ) {
            return '';
        }
        $vid = $type === My_IAPSNJ_Schema::TYPE_ASSOCIATE
            ? (int) ( $settings['renewal_variation_associate'] ?? 0 )
            : (int) ( $settings['renewal_variation_regular'] ?? 0 );
        if ( $vid <= 0 || ! My_IAPSNJ_Membership::product_config( $vid ) ) {
            return '';
        }
        return My_IAPSNJ_Membership::checkout_url( $vid );
    }

    /**
     * [iapsnj_renew_link text="Renew my membership" class="button" join_text="Join IAPSNJ"]
     *
     * Logged-in Regular / Associate members get their renewal checkout link;
     * visitors get the Join page; Lifetime / Honorary members get nothing.
     *
     * @param mixed $atts
     */
    public function shortcode_renew_link( $atts ): string {
        $atts = shortcode_atts( [
            'text'      => __( 'Renew my membership', 'my-iapsnj' ),
            'join_text' => __( 'Join IAPSNJ', 'my-iapsnj' ),
            'class'     => 'button my-iapsnj-renew-link',
        ], (array) $atts, 'iapsnj_renew_link' );

        $user_id = get_current_user_id();
        $url     = $user_id > 0 ? self::renewal_url_for_user( $user_id ) : '';
        $text    = (string) $atts['text'];
        if ( $url === '' ) {
            if ( $user_id > 0 ) {
                return ''; // comped member or nothing configured
            }
            $url  = (string) ( My_IAPSNJ_Plugin::settings()['join_page_url'] ?? '' );
            $text = (string) $atts['join_text'];
            if ( $url === '' ) {
                return '';
            }
        }
        return '<a class="' . esc_attr( (string) $atts['class'] ) . '" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
    }
}
