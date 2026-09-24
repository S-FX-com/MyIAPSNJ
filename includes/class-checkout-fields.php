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
 * The field list is defined by the admin in Sync & Settings → Application
 * fields: built-in fields (department, rank …) can be toggled, required,
 * relabelled and given options; new fields can be added with a type, options
 * and a FluentCRM target (an existing custom field, the contact's date of
 * birth, a brand-new custom field created on save, or "not stored").
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

    const OPTION       = 'my_iapsnj_checkout_fields';
    const META_FIELDS  = '_my_iapsnj_application';          // order meta: key => value
    const META_APPLIED = '_my_iapsnj_application_applied';  // order meta: UTC datetime
    const PREFIX       = 'iapsnj_';                          // input name prefix
    const NEW_TARGET   = '__new__';                          // "create a CRM custom field" picker value

    /** @var string[] */
    const TYPES = [ 'text', 'textarea', 'select', 'radio', 'date', 'checkbox' ];

    /** @var array<string,string> FluentCRM contact columns offered as targets */
    const DEFAULT_TARGETS = [
        'date_of_birth' => 'Date of birth',
        'prefix'        => 'Prefix (Mr / Mrs …)',
    ];

    /** @var bool Record a Check places an admin order; it is not an application. */
    private static bool $suppress = false;

    /** @var bool The fields were printed on this request. */
    private bool $rendered = false;

    /** @var array<string,array>|null per-request cache */
    private static ?array $config_cache = null;

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

    // -----------------------------------------------------------------------
    // Built-in fields
    // -----------------------------------------------------------------------

    /**
     * Built-in application fields: the seed for a fresh install and the
     * fallback definition when a built-in key is missing from the saved list.
     *
     * crm_kind: 'custom' (FluentCRM custom field slug), 'default' (contact
     * column), 'none' (not stored on the contact).
     *
     * @return array<string,array>
     */
    public static function definitions(): array {
        return [
            'department' => [
                'label'    => __( 'Department', 'my-iapsnj' ),
                'help'     => __( 'Your law-enforcement agency.', 'my-iapsnj' ),
                'type'     => 'select',
                'options'  => [],
                'crm'      => My_IAPSNJ_Schema::FIELD_DEPARTMENT,
                'crm_kind' => 'custom',
                'enabled'  => true,
                'required' => true,
            ],
            'rank_level' => [
                'label'    => __( 'Rank', 'my-iapsnj' ),
                'help'     => '',
                'type'     => 'select',
                'options'  => [],
                'crm'      => My_IAPSNJ_Schema::FIELD_RANK,
                'crm_kind' => 'custom',
                'enabled'  => true,
                'required' => true,
            ],
            'retirement_date' => [
                'label'    => __( 'Retirement date (if retired)', 'my-iapsnj' ),
                'help'     => '',
                'type'     => 'date',
                'options'  => [],
                'crm'      => My_IAPSNJ_Schema::FIELD_RETIREMENT_DATE,
                'crm_kind' => 'custom',
                'enabled'  => true,
                'required' => false,
            ],
            'date_of_birth' => [
                'label'    => __( 'Date of birth', 'my-iapsnj' ),
                'help'     => '',
                'type'     => 'date',
                'options'  => [],
                'crm'      => 'date_of_birth',
                'crm_kind' => 'default',
                'enabled'  => true,
                'required' => false,
            ],
            'referred_by' => [
                'label'    => __( 'Referred by', 'my-iapsnj' ),
                'help'     => __( 'Name of the member who referred you (optional).', 'my-iapsnj' ),
                'type'     => 'text',
                'options'  => [],
                'crm'      => My_IAPSNJ_Schema::FIELD_REFERRED_BY,
                'crm_kind' => 'custom',
                'enabled'  => true,
                'required' => false,
            ],
            'union_affiliation' => [
                'label'    => __( 'Union affiliation', 'my-iapsnj' ),
                'help'     => '',
                'type'     => 'text',
                'options'  => [],
                'crm'      => 'union_affiliation',
                'crm_kind' => 'custom',
                'enabled'  => false,
                'required' => false,
            ],
            'union_position' => [
                'label'    => __( 'Union position', 'my-iapsnj' ),
                'help'     => '',
                'type'     => 'text',
                'options'  => [],
                'crm'      => 'union_position',
                'crm_kind' => 'custom',
                'enabled'  => false,
                'required' => false,
            ],
            'certify' => [
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
    }

    // -----------------------------------------------------------------------
    // Configuration (ordered field list)
    // -----------------------------------------------------------------------

    /**
     * The configured fields in display order: key => definition
     * (label, help, type, options, crm, crm_kind, enabled, required, builtin).
     *
     * @return array<string,array>
     */
    public static function config(): array {
        if ( self::$config_cache !== null ) {
            return self::$config_cache;
        }
        $saved = get_option( self::OPTION, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
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
            if ( isset( $row['type'] ) && in_array( $row['type'], self::TYPES, true ) ) {
                $def['type'] = $row['type'];
            }
            if ( array_key_exists( 'crm_kind', $row ) && in_array( $row['crm_kind'], [ 'custom', 'default', 'none' ], true ) ) {
                $def['crm_kind'] = $row['crm_kind'];
                $def['crm']      = $row['crm_kind'] === 'none' ? '' : sanitize_key( (string) ( $row['crm'] ?? '' ) );
            }
            if ( $def['label'] === '' ) {
                continue;
            }
            $def['builtin'] = isset( $builtins[ $key ] );
            $out[ $key ]    = $def;
        }

        // Built-ins missing from the saved list are kept, disabled, so they
        // can be switched on again from the screen.
        foreach ( $builtins as $key => $def ) {
            if ( ! isset( $out[ $key ] ) ) {
                $def['enabled'] = $saved ? false : $def['enabled'];
                $def['builtin'] = true;
                $out[ $key ]    = $def;
            }
        }

        // Fresh install (nothing saved): built-in order and defaults.
        self::$config_cache = $out;
        return $out;
    }

    /**
     * @return array<string,array>
     */
    public static function enabled_fields(): array {
        return array_filter( self::config(), function ( $def ) {
            return ! empty( $def['enabled'] );
        } );
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
        $custom = fluentcrm_get_option( 'contact_custom_fields', [] );
        if ( is_array( $custom ) ) {
            foreach ( $custom as $cf ) {
                if ( empty( $cf['slug'] ) ) {
                    continue;
                }
                $slug = sanitize_key( (string) $cf['slug'] );
                $out[ 'custom:' . $slug ] = (string) ( $cf['label'] ?? $slug ) . ' (' . $slug . ')';
            }
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
     * Persist the admin configuration.
     *
     * @param array<string|int,array> $rows Posted rows: ['key','label','help','type','options'(string|array),'crm_target','enabled','required','order']
     */
    public static function save_config( array $rows ): void {
        $builtins = self::definitions();
        $existing = self::config();
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

        $clean = [];
        $used  = [];
        foreach ( $ordered as [ , , $row_id, $row ] ) {
            $label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            $key   = sanitize_key( (string) ( $row['key'] ?? '' ) );
            $is_builtin = $key !== '' && isset( $builtins[ $key ] );
            if ( $label === '' ) {
                if ( $is_builtin ) {
                    $label = $builtins[ $key ]['label'];
                } else {
                    continue; // a custom field needs a label
                }
            }
            if ( $key === '' ) {
                $key = 'app_' . sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
                $key = substr( $key, 0, 40 ) ?: 'app_field';
            }
            $base_key = $key;
            $n        = 2;
            while ( isset( $used[ $key ] ) ) {
                $key = $base_key . '_' . $n++;
            }
            $used[ $key ] = true;

            $type = $is_builtin ? $builtins[ $key ]['type'] : (string) ( $row['type'] ?? 'text' );
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
            if ( $target === self::NEW_TARGET ) {
                $slug = self::create_crm_field( $key, $label, $type, $options );
                if ( $slug !== '' ) {
                    $crm_kind = 'custom';
                    $crm      = $slug;
                }
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
            if ( $crm === '' ) {
                $crm_kind = 'none';
            }

            $clean[ $key ] = [
                'key'      => $key,
                'label'    => $label,
                'help'     => sanitize_text_field( (string) ( $row['help'] ?? '' ) ),
                'type'     => $type,
                'options'  => in_array( $type, [ 'select', 'radio' ], true ) ? $options : [],
                'crm'      => $crm,
                'crm_kind' => $crm_kind,
                'enabled'  => ! empty( $row['enabled'] ),
                'required' => ! empty( $row['required'] ),
            ];
        }
        update_option( self::OPTION, $clean );
        self::$config_cache = null;
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
     * Seed the option on first install (idempotent).
     */
    public static function seed_defaults(): void {
        if ( get_option( self::OPTION ) === false ) {
            $seed = [];
            foreach ( self::definitions() as $key => $def ) {
                $seed[ $key ] = array_merge( [ 'key' => $key ], $def );
            }
            add_option( self::OPTION, $seed );
        }
    }

    /**
     * Data migration (v7): rewrite the 4.1.0 per-key override format as the
     * ordered full-row format. Safe to run repeatedly.
     */
    public static function upgrade_config(): void {
        $saved = get_option( self::OPTION, [] );
        if ( ! is_array( $saved ) || ! $saved ) {
            self::seed_defaults();
            return;
        }
        self::$config_cache = null;
        $rows = [];
        foreach ( self::config() as $key => $def ) {
            unset( $def['builtin'] );
            $rows[ $key ] = array_merge( [ 'key' => $key ], $def );
        }
        update_option( self::OPTION, $rows );
        self::$config_cache = null;
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
            default:
                return sanitize_text_field( $raw );
        }
    }

    /**
     * Enabled-field values found in a request payload: key => value (non-empty only).
     *
     * @return array<string,string>
     */
    public static function collect( array $request ): array {
        $out = [];
        foreach ( self::enabled_fields() as $key => $def ) {
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
        return is_array( $values ) ? self::summary( $values ) : [];
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    public static function summary( array $values ): array {
        $out    = [];
        $config = self::config();
        foreach ( $values as $key => $value ) {
            if ( ! isset( $config[ $key ] ) || $value === '' ) {
                continue;
            }
            $def = $config[ $key ];
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
        $fields = self::enabled_fields();
        if ( ! $fields ) {
            return;
        }
        $this->rendered = true;
        wp_enqueue_style( 'my-iapsnj-checkout', MY_IAPSNJ_URL . 'public/css/checkout-fields.css', [], MY_IAPSNJ_VERSION );

        $values   = $this->prefill_values( is_array( $args ) ? $args : [] );
        $settings = My_IAPSNJ_Plugin::settings();
        $heading  = (string) ( $settings['application_heading'] ?? '' );
        $intro    = (string) ( $settings['application_intro'] ?? '' );

        echo '<div class="fct-checkout-section my-iapsnj-application" id="my-iapsnj-application">';
        echo '<h3 class="fct-section-title my-iapsnj-application-title">' . esc_html( $heading !== '' ? $heading : __( 'Membership application', 'my-iapsnj' ) ) . '</h3>';
        if ( $intro !== '' ) {
            echo '<p class="my-iapsnj-application-intro">' . esc_html( $intro ) . '</p>';
        }
        foreach ( $fields as $key => $def ) {
            $this->render_field( $key, $def, (string) ( $values[ $key ] ?? '' ) );
        }
        echo '</div>';
    }

    private function render_field( string $key, array $def, string $value ): void {
        $name     = self::input_name( $key );
        $id       = 'my-iapsnj-' . sanitize_html_class( $key );
        $required = ! empty( $def['required'] );
        $req_attr = $required ? ' required aria-required="true"' : '';
        $star     = $required ? ' <span class="fct_required my-iapsnj-required" aria-hidden="true">*</span>' : '';
        $help     = (string) ( $def['help'] ?? '' );
        $type     = (string) $def['type'];
        $options  = (array) ( $def['options'] ?? [] );

        echo '<div class="fct_form_group my-iapsnj-field my-iapsnj-field-' . esc_attr( $type ) . '" data-my-iapsnj-field="' . esc_attr( $key ) . '">';

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
                echo '<input type="radio" class="fct-input" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt ) . '"' . checked( $value, $opt, false ) . ( $required && $i === 1 ? ' required' : '' ) . '> ';
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
            } else {
                $input_type = $type === 'date' ? 'date' : 'text';
                echo '<input type="' . esc_attr( $input_type ) . '" class="fct-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req_attr . ( $input_type === 'text' ? ' autocomplete="off"' : '' ) . '>';
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
        $contact = self::current_contact( is_array( $data ) ? ( $data['cart'] ?? null ) : null );
        if ( ! $contact instanceof Subscriber ) {
            return $fields;
        }
        $map = [
            'address_1' => (string) $contact->address_line_1,
            'address_2' => (string) $contact->address_line_2,
            'city'      => (string) $contact->city,
            'postcode'  => (string) $contact->postal_code,
            'phone'     => (string) $contact->phone,
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
    private function prefill_values( array $args ): array {
        $values = [];
        $config = self::enabled_fields();

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
     * checkout form (sessionStorage, cleared on the receipt page).
     */
    public function print_footer_script(): void {
        if ( ! $this->rendered ) {
            return;
        }
        $prefix = wp_json_encode( self::PREFIX );
        echo '<script>(function(){var P=' . $prefix . ',K="my_iapsnj_application";' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded constant
            . 'function all(){return document.querySelectorAll(\'[name^="\'+P+\'"]\');}'
            . 'function read(){try{return JSON.parse(sessionStorage.getItem(K)||"{}")}catch(e){return {}}}'
            . 'function save(){var o=read();all().forEach(function(i){if(i.type==="radio"){if(i.checked){o[i.name]=i.value;}}else{o[i.name]=i.type==="checkbox"?(i.checked?"yes":""):i.value;}});try{sessionStorage.setItem(K,JSON.stringify(o))}catch(e){}}'
            . 'function restore(){var o=read();all().forEach(function(i){if(!(i.name in o)){return;}if(i.type==="checkbox"){if(!i.checked&&o[i.name]==="yes"){i.checked=true;}}else if(i.type==="radio"){if(i.value===o[i.name]){i.checked=true;}}else if(!i.value&&o[i.name]){i.value=o[i.name];}});}'
            . 'function watch(e){if(e.target&&e.target.name&&e.target.name.indexOf(P)===0){save();}}'
            . 'document.addEventListener("change",watch,true);document.addEventListener("input",watch,true);'
            . 'restore();var n=0,t=setInterval(function(){restore();if(++n>120){clearInterval(t);}},1000);'
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
        $data = is_array( $args ) && isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : [];
        foreach ( self::enabled_fields() as $key => $def ) {
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
            if ( $def['type'] === 'date' && My_IAPSNJ_Dates::ymd( $value ) === '' ) {
                $errors[ $name ]['invalid'] = sprintf( /* translators: field label */ __( '%s is not a valid date.', 'my-iapsnj' ), $label );
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
            // The answers are kept on the order whatever the product mapping
            // says, so a mapping mistake never loses what the member typed.
            $values = self::collect( $request );
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
            if ( $first === '' && $last === '' ) {
                [ $first, $last ] = self::names_from_checkout( $request );
            }
            $app = My_IAPSNJ_Applications::record_checkout( $cart, $order, $email, $first, $last, $values );

            if ( method_exists( $order, 'addLog' ) ) {
                $lines = [];
                foreach ( self::summary( $values ) as $label => $value ) {
                    $lines[] = $label . ': ' . $value;
                }
                $order->addLog(
                    'My IAPSNJ: application received',
                    ( $app ? sprintf( 'Application #%d (%s). ', (int) $app->id, (string) $app->kind ) : '' ) . ( $lines ? implode( ' · ', $lines ) : 'No application fields submitted.' ),
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
        $first = sanitize_text_field( (string) ( $cd['billing_first_name'] ?? ( $cd['first_name'] ?? '' ) ) );
        $last  = sanitize_text_field( (string) ( $cd['billing_last_name'] ?? ( $cd['last_name'] ?? '' ) ) );
        if ( $first === '' && $last === '' ) {
            $full = sanitize_text_field( (string) ( $cd['billing_full_name'] ?? ( $cd['full_name'] ?? '' ) ) );
            if ( $full !== '' ) {
                $parts = preg_split( '/\s+/', trim( $full ) );
                $first = (string) array_shift( $parts );
                $last  = (string) implode( ' ', $parts );
            }
        }
        return [ $first, $last ];
    }

    /**
     * Does the cart hold a configured membership product? Lenient when the
     * item layout cannot be read: the store sells memberships only.
     */
    public static function cart_has_membership( $cart ): bool {
        $vids = self::cart_variation_ids( $cart );
        if ( $vids === null ) {
            return true;
        }
        if ( ! $vids ) {
            return false;
        }
        $config = My_IAPSNJ_Membership::products_config();
        foreach ( $vids as $vid ) {
            if ( isset( $config[ $vid ] ) ) {
                return true;
            }
        }
        return false;
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
        $custom   = [];
        $defaults = [];
        foreach ( self::config() as $key => $def ) {
            $value = isset( $values[ $key ] ) ? self::sanitize_value( $def, $values[ $key ] ) : '';
            if ( $value === '' || $def['crm'] === '' ) {
                continue;
            }
            if ( $def['crm_kind'] === 'custom' ) {
                $custom[ $def['crm'] ] = $def['type'] === 'checkbox' ? 'Yes' : $value;
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
