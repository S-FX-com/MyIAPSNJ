<?php
/**
 * My_IAPSNJ_Checkout_Fields
 *
 * The membership application lives inside the FluentCart checkout page.
 * There is no separate form: the member picks a product on the Join page
 * (each option is an instant-checkout link), lands on checkout, and fills
 * FluentCart's own name / email / phone / billing-address fields plus the
 * application fields this class injects (department, rank, retirement date,
 * date of birth, referred by, certification …).
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

    /** @var bool Record a Check places an admin order; it is not an application. */
    private static bool $suppress = false;

    /** @var bool The fields were printed on this request. */
    private bool $rendered = false;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'fluent_cart/before_payment_methods',      [ $this, 'render' ], 10, 1 );
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
    // Field catalogue
    // -----------------------------------------------------------------------

    /**
     * Built-in application fields. Admins toggle, require, relabel and set
     * the option lists from Sync & Settings; the keys and CRM targets are
     * fixed here so the CRM schema stays consistent.
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

    /**
     * Definitions merged with the saved settings.
     *
     * @return array<string,array>
     */
    public static function config(): array {
        $saved = get_option( self::OPTION, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        $out = [];
        foreach ( self::definitions() as $key => $def ) {
            $s = is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : [];
            if ( array_key_exists( 'enabled', $s ) ) {
                $def['enabled'] = ! empty( $s['enabled'] );
            }
            if ( array_key_exists( 'required', $s ) ) {
                $def['required'] = ! empty( $s['required'] );
            }
            if ( isset( $s['label'] ) && trim( (string) $s['label'] ) !== '' ) {
                $def['label'] = (string) $s['label'];
            }
            if ( isset( $s['help'] ) ) {
                $def['help'] = (string) $s['help'];
            }
            if ( isset( $s['options'] ) && is_array( $s['options'] ) ) {
                $def['options'] = array_values( array_filter( array_map( 'trim', array_map( 'strval', $s['options'] ) ), 'strlen' ) );
            }
            $out[ $key ] = $def;
        }
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
     * Persist the admin configuration. Unknown keys are dropped.
     *
     * @param array<string,array> $raw key => ['enabled','required','label','help','options' (string, one per line, or array)]
     */
    public static function save_config( array $raw ): void {
        $clean = [];
        foreach ( self::definitions() as $key => $def ) {
            $s = is_array( $raw[ $key ] ?? null ) ? $raw[ $key ] : [];
            $options = $s['options'] ?? [];
            if ( is_string( $options ) ) {
                $options = preg_split( '/\r\n|\r|\n/', $options );
            }
            $options = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $options ) ), 'strlen' ) );
            $clean[ $key ] = [
                'enabled'  => ! empty( $s['enabled'] ),
                'required' => ! empty( $s['required'] ),
                'label'    => sanitize_text_field( (string) ( $s['label'] ?? '' ) ),
                'help'     => sanitize_text_field( (string) ( $s['help'] ?? '' ) ),
                'options'  => $def['type'] === 'select' ? $options : [],
            ];
        }
        update_option( self::OPTION, $clean );
    }

    /**
     * Seed the option on first install / upgrade (idempotent).
     */
    public static function seed_defaults(): void {
        if ( get_option( self::OPTION ) === false ) {
            $seed = [];
            foreach ( self::definitions() as $key => $def ) {
                $seed[ $key ] = [
                    'enabled'  => $def['enabled'],
                    'required' => $def['required'],
                    'label'    => '',
                    'help'     => '',
                    'options'  => [],
                ];
            }
            add_option( self::OPTION, $seed );
        }
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

        echo '<div class="fct_form_group my-iapsnj-field my-iapsnj-field-' . esc_attr( $def['type'] ) . '" data-my-iapsnj-field="' . esc_attr( $key ) . '">';

        if ( $def['type'] === 'checkbox' ) {
            echo '<label class="fct_input_label fct_input_label_checkbox my-iapsnj-checkbox" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" class="fct-input fct-input-checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="yes"' . checked( $value, 'yes', false ) . $req_attr . '> ';
            echo '<span>' . esc_html( $def['label'] ) . '</span>' . $star; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $star is static markup
            echo '</label>';
        } else {
            echo '<label class="fct_input_label" for="' . esc_attr( $id ) . '">' . esc_html( $def['label'] ) . $star . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ( $def['type'] === 'select' && ! empty( $def['options'] ) ) {
                echo '<select class="fct-input fct-select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req_attr . '>';
                echo '<option value="">' . esc_html__( '— Select —', 'my-iapsnj' ) . '</option>';
                foreach ( $def['options'] as $opt ) {
                    echo '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
                }
                echo '</select>';
            } elseif ( $def['type'] === 'textarea' ) {
                echo '<textarea class="fct-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . $req_attr . '>' . esc_textarea( $value ) . '</textarea>';
            } else {
                $type = $def['type'] === 'date' ? 'date' : 'text';
                echo '<input type="' . esc_attr( $type ) . '" class="fct-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req_attr . ( $type === 'text' ? ' autocomplete="off"' : '' ) . '>';
            }
        }
        if ( $help !== '' ) {
            echo '<p class="fct_input_help my-iapsnj-help">' . esc_html( $help ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Values to prefill: what the cart already holds for this checkout, then
     * the logged-in member's CRM profile (renewals).
     *
     * @return array<string,string>
     */
    private function prefill_values( array $args ): array {
        $values = [];
        $config = self::enabled_fields();

        // Logged-in member → CRM profile.
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            try {
                $subscriber = My_IAPSNJ_Engine::find_linked_subscriber( $user_id );
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
        }

        // Values FluentCart may already have stored on the cart for this session.
        $cart = $args['cart'] ?? null;
        if ( is_object( $cart ) ) {
            try {
                $cd = $cart->checkout_data;
                if ( is_array( $cd ) ) {
                    foreach ( $config as $key => $def ) {
                        $name = self::input_name( $key );
                        if ( isset( $cd[ $name ] ) && is_scalar( $cd[ $name ] ) && (string) $cd[ $name ] !== '' ) {
                            $values[ $key ] = self::sanitize_value( $def, $cd[ $name ] );
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
            . 'function save(){var o=read();all().forEach(function(i){o[i.name]=i.type==="checkbox"?(i.checked?"yes":""):i.value;});try{sessionStorage.setItem(K,JSON.stringify(o))}catch(e){}}'
            . 'function restore(){var o=read();all().forEach(function(i){if(!(i.name in o)){return;}if(i.type==="checkbox"){if(!i.checked&&o[i.name]==="yes"){i.checked=true;}}else if(!i.value&&o[i.name]){i.value=o[i.name];}});}'
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
                        ? __( 'Please confirm the certification statement.', 'my-iapsnj' )
                        : sprintf( /* translators: field label */ __( '%s is required.', 'my-iapsnj' ), $label );
                }
                continue;
            }
            if ( $def['type'] === 'date' && My_IAPSNJ_Dates::ymd( $value ) === '' ) {
                $errors[ $name ]['invalid'] = sprintf( /* translators: field label */ __( '%s is not a valid date.', 'my-iapsnj' ), $label );
            }
            if ( $def['type'] === 'select' && ! empty( $def['options'] ) && ! in_array( $value, $def['options'], true ) ) {
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
            if ( ! My_IAPSNJ_Membership::plan_for_order( $order ) ) {
                return; // not a membership order
            }
            $request = [];
            foreach ( [ 'request_data', 'validated_data' ] as $k ) {
                if ( isset( $args[ $k ] ) && is_array( $args[ $k ] ) ) {
                    $request = array_merge( $args[ $k ], $request );
                }
            }
            $values = self::collect( $request );
            if ( $values ) {
                $order->updateMeta( self::META_FIELDS, $values );
                $order->deleteMeta( self::META_APPLIED );
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
        foreach ( self::definitions() as $key => $def ) {
            $value = isset( $values[ $key ] ) ? self::sanitize_value( $def, $values[ $key ] ) : '';
            if ( $value === '' || $def['crm'] === '' ) {
                continue;
            }
            if ( $def['crm_kind'] === 'custom' ) {
                $custom[ $def['crm'] ] = $value;
            } elseif ( $def['crm_kind'] === 'default' ) {
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
