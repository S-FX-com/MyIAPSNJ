<?php
/**
 * [iapsnj_edit_profile]: the member edits their own FluentCRM contact.
 *
 * The form is the membership application without the payment: the fields
 * of the Checkout Builder form for the member's level, printed with the
 * checkout's own renderer (My_IAPSNJ_Checkout_Fields::render_field()),
 * checked with its rules (field_errors()), and written to the contact the
 * way a payment writes the application (crm_custom_value()). Above them
 * sit the contact details FluentCart collects on the checkout: name, cell
 * phone and address.
 *
 * Differences from the checkout
 * - Saved answers are shown: every field, dropdowns included, starts with
 *   the value on the contact. A saved value that the list lacks stays
 *   selectable, so saving never blanks it.
 * - An emptied field is cleared on the contact (the checkout never erases).
 * - The certification tick box is left out, and email is shown, not edited
 *   (it links the contact to the WordPress account).
 * - Nothing here is membership state: member_type, paid_through, member
 *   number and join date are never read from or written by the form.
 *
 * The contact is always the logged-in user's linked contact. It is not taken
 * from a request, nor from FluentCRM's secure-link cookie.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

final class My_IAPSNJ_Profile {

    const SHORTCODE = 'iapsnj_edit_profile';
    const ACTION    = 'my_iapsnj_edit_profile';
    const HANDLE    = 'my-iapsnj-edit-profile';

    /** Input-name key prefix of the contact details above the application fields. */
    const BASE_PREFIX = 'profile_';

    /** @var self|null */
    private static $instance = null;

    /** @var bool the form was printed on this request */
    private $rendered = false;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'wp_footer', [ $this, 'print_footer_scripts' ], 99 );
        // Members only (no nopriv twin); not gated on manage_options, the
        // handler only ever changes the caller's own contact.
        add_action( 'wp_ajax_' . self::ACTION, [ $this, 'ajax_save' ] );
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    public function register_assets(): void {
        // checkout-fields.css holds the markup the shared renderer relies on
        // (sections, the "Other" box, conditional fields); edit-profile.css
        // adds the input look the FluentCart stylesheet gives on the checkout.
        wp_register_style( 'my-iapsnj-checkout', MY_IAPSNJ_URL . 'public/css/checkout-fields.css', [], MY_IAPSNJ_VERSION );
        wp_register_style( self::HANDLE, MY_IAPSNJ_URL . 'public/css/edit-profile.css', [ 'my-iapsnj-checkout' ], MY_IAPSNJ_VERSION );
        wp_register_script( self::HANDLE, MY_IAPSNJ_URL . 'public/js/edit-profile.js', [], MY_IAPSNJ_VERSION, true );

        $post = get_post();
        if ( $post instanceof WP_Post && is_singular() && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
            wp_enqueue_style( self::HANDLE );
        }
    }

    /** Phone and capitalisation formatting, the same scripts the checkout prints. */
    public function print_footer_scripts(): void {
        if ( ! $this->rendered ) {
            return;
        }
        My_IAPSNJ_Checkout_Fields::print_phone_script();
        My_IAPSNJ_Capitalization::print_script();
    }

    // -----------------------------------------------------------------------
    // The member's contact and the fields to show
    // -----------------------------------------------------------------------

    /** The logged-in user's linked contact; never one named by a request or a FluentCRM cookie. */
    private static function contact(): ?Subscriber {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return null;
        }
        try {
            return My_IAPSNJ_Engine::find_linked_subscriber( $user_id );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Contact details FluentCart asks for on the checkout, as field
     * definitions the shared renderer prints. 'column' is the contact column
     * each one writes to.
     *
     * @return array<string,array>
     */
    private static function base_fields(): array {
        $field = function ( string $label, string $column, string $type = 'text', array $extra = [] ): array {
            return array_merge( [
                'label'    => $label,
                'help'     => '',
                'type'     => $type,
                'options'  => [],
                'crm'      => $column,
                'crm_kind' => 'default',
                'column'   => $column,
                'enabled'  => true,
                'required' => true,
            ], $extra );
        };
        $countries = self::countries();
        $fields    = [
            self::BASE_PREFIX . 'first_name'     => $field( __( 'First name', 'my-iapsnj' ), 'first_name', 'text', [ 'case' => My_IAPSNJ_Capitalization::NAME ] ),
            self::BASE_PREFIX . 'last_name'      => $field( __( 'Last name', 'my-iapsnj' ), 'last_name', 'text', [ 'case' => My_IAPSNJ_Capitalization::NAME ] ),
            self::BASE_PREFIX . 'phone'          => $field( __( 'Cell phone', 'my-iapsnj' ), 'phone', 'phone' ),
            self::BASE_PREFIX . 'address_line_1' => $field( __( 'Address', 'my-iapsnj' ), 'address_line_1', 'text', [ 'case' => My_IAPSNJ_Capitalization::STREET ] ),
            self::BASE_PREFIX . 'address_line_2' => $field( __( 'Address line 2', 'my-iapsnj' ), 'address_line_2', 'text', [ 'required' => false, 'case' => My_IAPSNJ_Capitalization::STREET ] ),
            self::BASE_PREFIX . 'city'           => $field( __( 'City', 'my-iapsnj' ), 'city', 'text', [ 'case' => My_IAPSNJ_Capitalization::CITY ] ),
            // A dropdown of US states by full name (My_IAPSNJ_States); a province
            // or region elsewhere goes under "State not listed? Add it here".
            self::BASE_PREFIX . 'state'          => $field( __( 'State', 'my-iapsnj' ), 'state', 'select', [ 'options' => My_IAPSNJ_States::options(), 'other' => true ] ),
            self::BASE_PREFIX . 'postal_code'    => $field( __( 'ZIP / Postal code', 'my-iapsnj' ), 'postal_code' ),
            self::BASE_PREFIX . 'country'        => $field(
                __( 'Country', 'my-iapsnj' ),
                'country',
                $countries ? 'select' : 'text',
                [ 'options' => array_values( $countries ) ]
            ),
        ];
        return $fields;
    }

    /**
     * FluentCRM's country list: code => name ([] when it offers none).
     *
     * @return array<string,string>
     */
    private static function countries(): array {
        $out = [];
        foreach ( (array) apply_filters( 'fluent_crm/countries', [] ) as $row ) {
            if ( is_array( $row ) && ! empty( $row['code'] ) && ! empty( $row['title'] ) ) {
                $out[ (string) $row['code'] ] = (string) $row['title'];
            }
        }
        return $out;
    }

    /** A stored country (code or name) as the name the list shows. */
    private static function country_name( string $stored ): string {
        $stored = trim( $stored );
        foreach ( self::countries() as $code => $name ) {
            if ( strcasecmp( $code, $stored ) === 0 || strcasecmp( $name, $stored ) === 0 ) {
                return $name;
            }
        }
        return $stored;
    }

    /** The code of a listed country name ('' when it is not listed). */
    private static function country_code( string $name ): string {
        $code = array_search( $name, self::countries(), true );
        return $code === false ? '' : (string) $code;
    }

    /**
     * Everything the form and the save agree on, built from the contact so
     * both see the same fields, options and current answers.
     *
     * @return array{type:string,form_id:string,base:array,inputs:array,sections:array,values:array,base_values:array}
     */
    private static function context( Subscriber $contact ): array {
        $type    = My_IAPSNJ_Schema::field( $contact, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE );
        $form_id = My_IAPSNJ_Checkout_Fields::form_for_member_type( $type );
        if ( $form_id === '' || ! My_IAPSNJ_Checkout_Fields::form_exists( $form_id ) ) {
            $form_id = My_IAPSNJ_Checkout_Fields::default_form_id();
        }

        // What can be edited here: inputs stored on the contact. A tick box
        // that is only the parent of such a field ("I am retired" above
        // Retirement date) stays; the certification and anything stored
        // nowhere goes. Membership state is never offered.
        $system = My_IAPSNJ_Schema::system_fields();
        $all    = My_IAPSNJ_Checkout_Fields::input_fields( $form_id, $type );
        $keep   = [];
        foreach ( $all as $key => $def ) {
            $crm = (string) ( $def['crm'] ?? '' );
            if ( $crm !== '' && ( $def['crm_kind'] ?? '' ) !== 'none' && ! in_array( $crm, $system, true ) ) {
                $keep[ $key ] = true;
            }
        }
        foreach ( array_keys( $keep ) as $key ) {
            $parent = (string) ( $all[ $key ]['parent'] ?? '' );
            if ( $parent !== '' && isset( $all[ $parent ] ) ) {
                $keep[ $parent ] = true;
            }
        }
        $inputs = array_intersect_key( $all, $keep );

        // Current answers.
        $custom = $contact->custom_fields();
        $values = [];
        foreach ( $inputs as $key => $def ) {
            $raw = '';
            if ( $def['crm_kind'] === 'custom' && $def['crm'] === My_IAPSNJ_Schema::FIELD_DEPARTMENT ) {
                $raw = My_IAPSNJ_Schema::department_of( $contact ); // the primary company, else the field
            } elseif ( $def['crm_kind'] === 'custom' ) {
                $v   = $custom[ $def['crm'] ] ?? '';
                $raw = is_array( $v ) ? (string) reset( $v ) : (string) $v;
            } elseif ( $def['crm_kind'] === 'default' ) {
                $raw = (string) ( $contact->{ $def['crm'] } ?? '' );
            }
            $values[ $key ] = My_IAPSNJ_Checkout_Fields::sanitize_value( $def, $raw );
        }
        // A tick box stored nowhere is ticked when its dependants have answers.
        foreach ( $inputs as $key => $def ) {
            $parent = (string) ( $def['parent'] ?? '' );
            if ( $parent !== '' && isset( $inputs[ $parent ] ) && $inputs[ $parent ]['crm_kind'] === 'none' && ( $values[ $key ] ?? '' ) !== '' ) {
                $values[ $parent ] = 'yes';
            }
        }

        // Dropdown lists: never empty, and never missing the current answer.
        foreach ( $inputs as $key => $def ) {
            if ( ! in_array( $def['type'], [ 'select', 'radio' ], true ) ) {
                continue;
            }
            $options = array_values( array_filter( array_map( 'strval', (array) $def['options'] ), 'strlen' ) );
            if ( ! $options ) {
                $found   = My_IAPSNJ_Checkout_Fields::discover_options( $key, (string) $def['crm'] );
                $options = array_values( array_filter( array_map( 'strval', (array) ( $found['options'] ?? [] ) ), 'strlen' ) );
            }
            $current = $values[ $key ] ?? '';
            if ( $current !== '' && ! in_array( $current, $options, true ) ) {
                foreach ( $options as $opt ) {
                    if ( strcasecmp( $opt, $current ) === 0 ) {
                        $values[ $key ] = $opt; // the list's spelling
                        $current        = $opt;
                        break;
                    }
                }
                // A dropdown with no "Other" keeps what is on file as a choice.
                if ( $current !== '' && ! in_array( $current, $options, true ) && empty( $def['other'] ) ) {
                    $options[] = $current;
                }
            }
            $inputs[ $key ]['options'] = $options;
        }

        $sections = [];
        foreach ( My_IAPSNJ_Checkout_Fields::sections( $form_id, $type ) as $group ) {
            $group['fields'] = array_intersect_key( $inputs, $group['fields'] );
            $sections[]      = $group;
        }

        $base        = self::base_fields();
        $base_values = [
            self::BASE_PREFIX . 'first_name'     => (string) $contact->first_name,
            self::BASE_PREFIX . 'last_name'      => (string) $contact->last_name,
            self::BASE_PREFIX . 'phone'          => My_IAPSNJ_Phone::display( (string) $contact->phone ),
            self::BASE_PREFIX . 'address_line_1' => (string) $contact->address_line_1,
            self::BASE_PREFIX . 'address_line_2' => (string) $contact->address_line_2,
            self::BASE_PREFIX . 'city'           => (string) $contact->city,
            // "NJ" on file shows as "New Jersey"; a value that is not a US state is kept as it is.
            self::BASE_PREFIX . 'state'          => My_IAPSNJ_States::full_name( (string) $contact->state, (string) $contact->country ) ?: (string) $contact->state,
            self::BASE_PREFIX . 'postal_code'    => (string) $contact->postal_code,
            self::BASE_PREFIX . 'country'        => self::country_name( (string) $contact->country ),
        ];
        // The country on file may not be in the list: keep it selectable.
        $country_key = self::BASE_PREFIX . 'country';
        if ( $base[ $country_key ]['type'] === 'select' && $base_values[ $country_key ] !== '' && ! in_array( $base_values[ $country_key ], $base[ $country_key ]['options'], true ) ) {
            $base[ $country_key ]['options'][] = $base_values[ $country_key ];
        }

        return [
            'type'        => $type,
            'form_id'     => $form_id,
            'base'        => $base,
            'inputs'      => $inputs,
            'sections'    => $sections,
            'values'      => $values,
            'base_values' => $base_values,
        ];
    }

    // -----------------------------------------------------------------------
    // Shortcode
    // -----------------------------------------------------------------------

    /**
     * [iapsnj_edit_profile button="Save Changes"]
     *
     * @param array|string $atts
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'button' => __( 'Save Changes', 'my-iapsnj' ),
        ], (array) $atts, self::SHORTCODE );

        if ( ! is_user_logged_in() ) {
            $login = wp_login_url( (string) get_permalink() );
            return '<p class="my-iapsnj-ep-notice">'
                . sprintf(
                    /* translators: %s: link to the login page */
                    esc_html__( 'Please %s to edit your profile.', 'my-iapsnj' ),
                    '<a href="' . esc_url( $login ) . '">' . esc_html__( 'log in', 'my-iapsnj' ) . '</a>'
                )
                . '</p>';
        }
        $contact = self::contact();
        if ( ! $contact instanceof Subscriber ) {
            return '<p class="my-iapsnj-ep-notice">' . esc_html__( 'We could not find your membership profile. Please contact IAPSNJ so we can link your account.', 'my-iapsnj' ) . '</p>';
        }

        $this->rendered = true;
        wp_enqueue_style( self::HANDLE );
        wp_enqueue_script( self::HANDLE );
        wp_localize_script( self::HANDLE, 'myIapsnjEditProfile', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'action'     => self::ACTION,
            'nonce'      => wp_create_nonce( self::ACTION ),
            'prefix'     => My_IAPSNJ_Checkout_Fields::PREFIX,
            'otherValue' => My_IAPSNJ_Checkout_Fields::OTHER_VALUE,
            'maxBytes'   => My_IAPSNJ_Headshot::max_bytes(),
            'i18n'       => [
                'photoType' => __( 'Please choose a JPG, PNG or WebP photo.', 'my-iapsnj' ),
                /* translators: %s: size such as 10 MB */
                'photoSize' => sprintf( __( 'The photo is too large. Please use one under %s.', 'my-iapsnj' ), size_format( My_IAPSNJ_Headshot::max_bytes() ) ),
                'saving' => __( 'Saving…', 'my-iapsnj' ),
                'fix'    => __( 'Please correct the highlighted fields.', 'my-iapsnj' ),
                'error'  => __( 'Your profile could not be saved. Please try again.', 'my-iapsnj' ),
            ],
        ] );

        $ctx    = self::context( $contact );
        $inputs = $ctx['inputs'];
        $photo  = My_IAPSNJ_Headshot::is_ours( (string) $contact->avatar ) ? (string) $contact->avatar : '';

        ob_start();
        ?>
        <div class="my-iapsnj-application my-iapsnj-ep">
            <form class="my-iapsnj-ep-form" method="post" enctype="multipart/form-data">
                <p class="my-iapsnj-ep-message" role="alert" hidden></p>
                <div class="my-iapsnj-section" role="group" aria-labelledby="my-iapsnj-ep-contact">
                    <h3 class="my-iapsnj-section-title" id="my-iapsnj-ep-contact"><?php esc_html_e( 'Contact information', 'my-iapsnj' ); ?></h3>
                    <div class="fct_form_group my-iapsnj-field my-iapsnj-ep-photo">
                        <span class="my-iapsnj-ep-photo-preview">
                            <span class="my-iapsnj-ep-photo-placeholder" aria-hidden="true"><?php echo My_IAPSNJ_Headshot::placeholder_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
                            <?php if ( $photo !== '' ) : ?>
                                <img class="my-iapsnj-ep-photo-img" src="<?php echo esc_url( $photo ); ?>" alt="<?php esc_attr_e( 'Your photo', 'my-iapsnj' ); ?>" width="96" height="96">
                            <?php endif; ?>
                        </span>
                        <div class="my-iapsnj-ep-photo-controls">
                            <label class="fct_input_label" for="my-iapsnj-ep-photo"><?php esc_html_e( 'Photo', 'my-iapsnj' ); ?></label>
                            <input type="file" id="my-iapsnj-ep-photo" name="iapsnj_photo" accept="image/jpeg,image/png,image/webp">
                            <label class="my-iapsnj-checkbox my-iapsnj-ep-photo-remove"<?php echo $photo === '' ? ' hidden' : ''; ?>>
                                <input type="checkbox" name="iapsnj_photo_remove" value="1"> <span><?php esc_html_e( 'Remove my photo', 'my-iapsnj' ); ?></span>
                            </label>
                            <p class="fct_input_help my-iapsnj-help">
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: %s: size such as 10 MB */
                                    __( 'JPG, PNG or WebP, up to %s. We crop it to a square and shrink it for you. It appears in the member directory.', 'my-iapsnj' ),
                                    size_format( My_IAPSNJ_Headshot::max_bytes() )
                                ) );
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php
                    foreach ( $ctx['base'] as $key => $def ) {
                        My_IAPSNJ_Checkout_Fields::render_field( $key, $def, (string) $ctx['base_values'][ $key ] );
                    }
                    ?>
                    <div class="fct_form_group my-iapsnj-field">
                        <label class="fct_input_label" for="my-iapsnj-ep-email"><?php esc_html_e( 'Email', 'my-iapsnj' ); ?></label>
                        <input type="email" class="fct-input" id="my-iapsnj-ep-email" value="<?php echo esc_attr( (string) $contact->email ); ?>" readonly aria-readonly="true">
                        <p class="fct_input_help my-iapsnj-help"><?php esc_html_e( 'To change your email address, please contact IAPSNJ.', 'my-iapsnj' ); ?></p>
                    </div>
                </div>
                <?php
                // Conditional fields start shown or hidden for the answers on
                // file; edit-profile.js keeps them in step.
                $parents = [];
                foreach ( $inputs as $key => $def ) {
                    if ( (string) ( $def['parent'] ?? '' ) === '' ) {
                        $parents[ $key ] = (string) ( $ctx['values'][ $key ] ?? '' );
                    }
                }
                foreach ( $ctx['sections'] as $section ) {
                    if ( ! $section['fields'] ) {
                        continue;
                    }
                    $shown = [];
                    foreach ( $section['fields'] as $key => $def ) {
                        $shown[ $key ] = My_IAPSNJ_Checkout_Fields::condition_met( $def, $parents, $inputs );
                    }
                    $title_id = 'my-iapsnj-ep-section-' . sanitize_html_class( $section['key'] !== '' ? $section['key'] : 'details' );
                    ?>
                    <div class="my-iapsnj-section" role="group" data-my-iapsnj-section="<?php echo esc_attr( $section['key'] ); ?>"<?php echo $section['label'] !== '' ? ' aria-labelledby="' . esc_attr( $title_id ) . '"' : ''; ?><?php echo in_array( true, $shown, true ) ? '' : ' style="display:none"'; ?>>
                        <?php if ( $section['label'] !== '' ) : ?>
                            <h3 class="my-iapsnj-section-title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $section['label'] ); ?></h3>
                            <?php if ( $section['help'] !== '' ) : ?>
                                <p class="my-iapsnj-section-intro"><?php echo esc_html( $section['help'] ); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php
                        foreach ( $section['fields'] as $key => $def ) {
                            My_IAPSNJ_Checkout_Fields::render_field( $key, $def, (string) ( $ctx['values'][ $key ] ?? '' ), $shown[ $key ] );
                        }
                        ?>
                    </div>
                    <?php
                }
                ?>
                <button type="submit" class="my-iapsnj-ep-submit"><?php echo esc_html( (string) $atts['button'] ); ?></button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Save
    // -----------------------------------------------------------------------

    public function ajax_save(): void {
        check_ajax_referer( self::ACTION, 'nonce' );

        $contact = self::contact();
        if ( ! $contact instanceof Subscriber ) {
            wp_send_json_error( [ 'message' => __( 'We could not find your membership profile. Please contact IAPSNJ.', 'my-iapsnj' ) ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above; values are sanitised per field below
        $request = wp_unslash( $_POST );
        $request = is_array( $request ) ? $request : [];
        $ctx     = self::context( $contact );
        $inputs  = $ctx['inputs'];
        $type    = $ctx['type'];

        // ---- Validate ------------------------------------------------------
        $errors = [];
        $merge  = function ( array $found ) use ( &$errors ): void {
            foreach ( $found as $name => $rules ) {
                $errors[ $name ] = (string) reset( $rules );
            }
        };
        foreach ( $ctx['base'] as $key => $def ) {
            $merge( My_IAPSNJ_Checkout_Fields::field_errors( $key, $def, $request ) );
        }
        $visible = My_IAPSNJ_Checkout_Fields::visible_inputs( $ctx['form_id'], $request, $type, $inputs );
        foreach ( $visible as $key => $def ) {
            $merge( My_IAPSNJ_Checkout_Fields::field_errors( $key, $def, $request, $type ) );
        }
        // The photo, checked with the rest so every problem is reported at once.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
        $file   = isset( $_FILES['iapsnj_photo'] ) ? $_FILES['iapsnj_photo'] : null;
        $upload = My_IAPSNJ_Headshot::has_upload( $file );
        if ( $upload ) {
            $problem = My_IAPSNJ_Headshot::check( $file );
            if ( $problem !== '' ) {
                $errors['iapsnj_photo'] = $problem;
            }
        }
        if ( $errors ) {
            wp_send_json_error( [
                'message' => __( 'Please correct the highlighted fields.', 'my-iapsnj' ),
                'errors'  => $errors,
            ], 422 );
        }

        // Everything is valid: shrink and store the new photo before anything
        // is written, so a photo that cannot be processed changes nothing.
        $remove = ! $upload && ! empty( $request['iapsnj_photo_remove'] ) && My_IAPSNJ_Headshot::is_ours( (string) $contact->avatar );
        $stored = null;
        if ( $upload ) {
            $stored = My_IAPSNJ_Headshot::store( $file, (int) $contact->id, get_current_user_id() );
            if ( is_wp_error( $stored ) ) {
                wp_send_json_error( [
                    'message' => __( 'Please correct the highlighted fields.', 'my-iapsnj' ),
                    'errors'  => [ 'iapsnj_photo' => $stored->get_error_message() ],
                ], 422 );
            }
        }

        // ---- Write ---------------------------------------------------------
        try {
            $columns = [];
            if ( $stored ) {
                $columns['avatar'] = $stored['url']; // FluentCRM's contact photo
            } elseif ( $remove ) {
                $columns['avatar'] = null;
            }
            foreach ( $ctx['base'] as $key => $def ) {
                $value = My_IAPSNJ_Checkout_Fields::sanitize_value( $def, My_IAPSNJ_Checkout_Fields::posted_value( $key, $def, $request ) );
                switch ( $def['column'] ) {
                    case 'phone':
                        $value = My_IAPSNJ_Phone::e164( $value );
                        break;
                    case 'country':
                        // The list shows names; the contact keeps FluentCRM's country code.
                        $value = $def['type'] === 'select' ? self::country_code( $value ) : strtoupper( $value );
                        break;
                }
                $columns[ $def['column'] ] = $value;
            }
            // The full state name for a US address (typed codes included); other
            // countries keep what was typed. Needs the country, so it runs last.
            $columns['state'] = My_IAPSNJ_Capitalization::state( (string) $columns['state'], (string) $columns['country'] );
            if ( ! My_IAPSNJ_States::is_us( (string) $columns['country'] ) && strlen( (string) $columns['state'] ) > 3 ) {
                // A region typed in full ("new south wales") is capitalised like a city; codes (ON, NSW) are left as they are.
                $columns['state'] = My_IAPSNJ_Capitalization::city( (string) $columns['state'] );
            }

            // A field that is not shown for the answers (a retirement date
            // under an unticked "I am retired") is cleared, not kept.
            $crm_defs = My_IAPSNJ_Checkout_Fields::crm_custom_fields();
            $before   = $contact->custom_fields();
            $custom   = [];
            foreach ( $inputs as $key => $def ) {
                if ( $def['crm_kind'] === 'none' ) {
                    continue;
                }
                $value = isset( $visible[ $key ] )
                    ? My_IAPSNJ_Checkout_Fields::sanitize_value( $def, My_IAPSNJ_Checkout_Fields::posted_value( $key, $def, $request ) )
                    : '';
                if ( $def['crm_kind'] === 'custom' ) {
                    $new  = My_IAPSNJ_Checkout_Fields::crm_custom_value( $def, $value, $crm_defs );
                    $held = $before[ $def['crm'] ] ?? null;
                    // A multiple-answer CRM field (Veteran status: Army and Navy) is shown as a
                    // dropdown of one choice; leaving that choice alone keeps the other answers.
                    if ( is_array( $new ) && is_array( $held ) && count( $held ) > 1 && in_array( $value, $held, true ) ) {
                        $new = array_values( $held );
                    }
                    $custom[ $def['crm'] ] = $new;
                } elseif ( isset( My_IAPSNJ_Checkout_Fields::DEFAULT_TARGETS[ $def['crm'] ] ) ) {
                    $columns[ $def['crm'] ] = $value === '' ? null : $value;
                }
            }

            $contact->fill( $columns );
            $dirty = $contact->getDirty();
            if ( $dirty ) {
                $contact->save();
            }
            My_IAPSNJ_Schema::set_fields( $contact, $custom );
            // The department is the primary company (Companies module); the field above stays in step.
            $dept            = $custom[ My_IAPSNJ_Schema::FIELD_DEPARTMENT ] ?? '';
            $company_changed = is_string( $dept ) && $dept !== '' && My_IAPSNJ_Schema::set_department( $contact, $dept );
            $changed         = $dirty || $company_changed || $contact->custom_fields() != $before; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- arrays of the same shape
        } catch ( \Throwable $e ) {
            if ( $stored ) {
                wp_delete_attachment( $stored['id'], true ); // the contact was not changed: drop the new file
            }
            error_log( 'My IAPSNJ: profile save failed for contact #' . (int) $contact->id . ': ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => __( 'Your profile could not be saved. Please try again.', 'my-iapsnj' ) ], 500 );
        }

        // The same events FluentCRM fires for its own profile form: the
        // WordPress role (My_IAPSNJ_Engine) and automations follow.
        if ( $changed ) {
            do_action( 'fluentcrm_contact_updated', $contact, $dirty ); // phpcs:ignore WordPress.NamingConventions.ValidHookName
            do_action( 'fluent_crm/contact_updated', $contact, $dirty );
        }

        // The contact points at the new photo (or none): the old files go.
        if ( $stored ) {
            My_IAPSNJ_Headshot::delete_for_contact( (int) $contact->id, $stored['id'] );
        } elseif ( $remove ) {
            My_IAPSNJ_Headshot::delete_for_contact( (int) $contact->id );
        }

        $reply = [ 'message' => __( 'Your profile has been updated.', 'my-iapsnj' ) ];
        if ( $stored || $remove ) {
            $reply['photo'] = $stored ? $stored['url'] : '';
        }
        wp_send_json_success( $reply );
    }
}
