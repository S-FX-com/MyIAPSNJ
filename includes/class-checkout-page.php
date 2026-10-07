<?php
/**
 * My_IAPSNJ_Checkout_Page
 *
 * How the FluentCart checkout page reads for a membership. The application
 * fields themselves are My_IAPSNJ_Checkout_Fields; this class changes
 * FluentCart's own page around them (FluentCart 1.6.5 source):
 *
 *  - Page title "Regular Member Application", "Associate Member
 *    Application" … (the member type in the cart)
 *    instead of "Checkout" while the cart holds a membership product. The
 *    page is shared with event and merchandise checkouts, which keep
 *    FluentCart's title.
 *  - Order summary below the application on every screen width: the page
 *    becomes one column and the summary moves between the application and
 *    the payment methods. FluentCart puts it beside the form, or above it
 *    when the checkout is narrower than 767px (a container query, so on a
 *    narrow theme column it is on top on desktop too). FluentCart refreshes
 *    only the summary's contents by AJAX, so the moved summary keeps working.
 *  - "Coupon" reads "Discount Code" on the storefront: the toggle, field,
 *    placeholder and every message (gettext on the fluent-cart domain).
 *    FluentCart's admin screens keep their own wording.
 *  - A yearly membership subscription line reads "$30/year, billed
 *    automatically" instead of "$30.00 per year until cancel".
 *  - A note under the Discount Code field (family members of a regular
 *    member ask for a code), from Checkout Builder → Checkout settings.
 *  - Pay by Check second in the payment list with a note giving the
 *    mailing address for dues and warning of mail delays.
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Checkout_Page {

    /** @var self|null */
    private static ?self $instance = null;

    /** Class on FluentCart's checkout wrapper for a membership cart. */
    const WRAPPER_CLASS = 'my-iapsnj-membership-checkout';

    /** FluentCart's offline payment method (relabelled "Pay by Check"). */
    const OFFLINE_METHOD = 'offline_payment';

    /** @var array<string,string> FluentCart strings replaced as they are, before the generic Coupon → Discount Code pass */
    const STRINGS = [
        'Applied Successfully'                    => 'Discount code applied',
        'Coupon removed!'                         => 'Discount code removed',
        'Coupon cannot be applied'                => 'This discount code cannot be applied',
        'Coupon can not be applied.'              => 'This discount code cannot be applied.',
        'Apply Here'                              => 'Enter discount code',
        'Have a promotional code?'                => 'Have a discount code?',
        'Enter promotion code'                    => 'Enter discount code',
        'Coupon discount'                         => 'Discount code',
        'Coupon Discount'                         => 'Discount Code',
        'No matching coupon found for this code.' => 'No matching discount code found.',
        'Invalid Coupon Code'                     => 'Invalid discount code',
    ];

    /** @var array<string,string> longest first (strtr picks the longest match anyway) */
    const COUPON_WORDS = [
        'Coupon Codes' => 'Discount Codes',
        'Coupon codes' => 'Discount codes',
        'coupon codes' => 'discount codes',
        'Coupon Code'  => 'Discount Code',
        'Coupon code'  => 'Discount code',
        'coupon code'  => 'discount code',
        'COUPONS'      => 'DISCOUNT CODES',
        'COUPON'       => 'DISCOUNT CODE',
        'Coupons'      => 'Discount Codes',
        'coupons'      => 'discount codes',
        'Coupon'       => 'Discount Code',
        'coupon'       => 'discount code',
    ];

    /** @var bool a yearly membership line item is being printed */
    private bool $billing_line = false;

    /** @var int output-buffer level before the line item's buffer, -1 = none */
    private int $billing_buffer = -1;

    /** @var array<string,bool> per-request cache: is this a membership checkout page */
    private array $page_cache = [];

    /** @var string|null per-request cache: the page title of a membership checkout */
    private ?string $title = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'the_title',                                [ $this, 'filter_title' ], 10, 2 );
        add_filter( 'document_title_parts',                     [ $this, 'filter_document_title' ] );
        add_filter( 'fluent_cart/checkout_page_css_classes',    [ $this, 'filter_wrapper_classes' ], 10, 2 );
        add_action( 'wp_enqueue_scripts',                       [ $this, 'enqueue_style' ] );
        add_action( 'wp_footer',                                [ $this, 'print_footer_script' ], 100 );

        add_filter( 'gettext_fluent-cart',                      [ $this, 'filter_gettext' ], 10, 3 );
        add_filter( 'gettext_with_context_fluent-cart',         [ $this, 'filter_gettext_with_context' ], 10, 4 );
        add_filter( 'ngettext_fluent-cart',                     [ $this, 'filter_ngettext' ], 10, 5 );

        add_action( 'fluent_cart/cart/line_item/before_main_title', [ $this, 'line_item_start' ], 9999, 1 );
        add_action( 'fluent_cart/cart/line_item/after_main_title',  [ $this, 'line_item_end' ], 1, 1 );
        add_action( 'fluent_cart/checkout/before_summary_total',    [ $this, 'render_discount_note' ], 10, 1 );

        add_filter( 'fluent_cart/checkout_active_payment_methods',  [ $this, 'order_payment_methods' ], 20, 2 );
    }

    // -----------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------

    /** Default note under the Discount Code field. */
    public static function default_discount_note(): string {
        return __( 'If you are a family member of a regular member, contact us for a discount code.', 'my-iapsnj' );
    }

    /**
     * Where dues checks are mailed (iapsnj.org/address/ "For Dues or Ticket
     * Payments"). Not FluentCRM's business address: that P.O. Box in
     * Rockaway is for all other correspondence.
     */
    const CHECK_ADDRESS = 'The Italian American Police Society of New Jersey, PO Box 352, Lyndhurst, NJ 07071';

    /** The 4.16 default note, which had no address (replaced by data v12). */
    const CHECK_NOTE_4_16 = 'Please note: mailing a check will considerably delay your application because of USPS mail delays. Pay by card to be approved sooner.';

    /** The mailing line that opens the note under Pay by Check. */
    public static function check_address_line(): string {
        /* translators: %s: postal address for dues checks */
        return sprintf( __( 'Mail your check to: <strong>%s</strong>', 'my-iapsnj' ), esc_html( self::CHECK_ADDRESS ) );
    }

    /** Default note under Pay by Check. */
    public static function default_check_note(): string {
        return self::check_address_line() . '<br>'
            . __( 'Please note: mailing a check will considerably delay your application because of USPS mail delays. Pay by card to be approved sooner.', 'my-iapsnj' );
    }

    /**
     * Data migration (v12): a saved note under Pay by Check gains the
     * mailing address; the 4.16 default becomes the new default. An
     * emptied note (no note) is left empty. Idempotent.
     */
    public static function add_address_to_check_note(): void {
        $settings = get_option( 'my_iapsnj_settings', [] );
        if ( ! is_array( $settings ) || ! isset( $settings['check_delay_note'] ) ) {
            return; // never saved: the default already carries the address
        }
        $note = trim( (string) $settings['check_delay_note'] );
        if ( $note === '' || strpos( $note, '07071' ) !== false ) {
            return;
        }
        $settings['check_delay_note'] = $note === self::CHECK_NOTE_4_16 ? null : self::check_address_line() . '<br>' . $note;
        update_option( 'my_iapsnj_settings', $settings );
    }

    /** Tags allowed in the two checkout notes (a link to the contact page). */
    public static function note_tags(): array {
        return [
            'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
            'strong' => [],
            'em'     => [],
            'br'     => [],
        ];
    }

    /**
     * A checkout note: the saved text, the built-in text when none was ever
     * saved, '' when an admin emptied it (no note).
     *
     * @param string $key checkout_discount_note | check_delay_note
     */
    public static function note( string $key ): string {
        $raw = My_IAPSNJ_Plugin::settings()[ $key ] ?? null;
        if ( $raw === null ) {
            return $key === 'check_delay_note' ? self::default_check_note() : self::default_discount_note();
        }
        return trim( (string) $raw );
    }

    // -----------------------------------------------------------------------
    // Which checkout this is
    // -----------------------------------------------------------------------

    /** FluentCart's checkout page (Settings → Pages), 0 when unknown. */
    public static function checkout_page_id(): int {
        static $id = null;
        if ( $id === null ) {
            $id = 0;
            try {
                if ( class_exists( '\FluentCart\Api\StoreSettings' ) ) {
                    $id = (int) ( new \FluentCart\Api\StoreSettings() )->getCheckoutPageId();
                }
            } catch ( \Throwable $e ) {
                $id = 0;
            }
        }
        return $id;
    }

    /**
     * The FluentCart cart of this visitor (null when none can be read).
     *
     * @return object|null
     */
    private static function current_cart() {
        try {
            if ( class_exists( '\FluentCart\App\Helpers\CartHelper' ) ) {
                $cart = \FluentCart\App\Helpers\CartHelper::getCart();
                return is_object( $cart ) ? $cart : null;
            }
        } catch ( \Throwable $e ) {
            // no cart
        }
        return null;
    }

    /**
     * Is the current front-end page FluentCart's checkout page showing a
     * membership application (a membership product in the cart, or an
     * administrator's "View checkout" preview)?
     */
    public function is_membership_checkout_page(): bool {
        if ( is_admin() || ! did_action( 'wp' ) ) {
            return false;
        }
        $page_id = self::checkout_page_id();
        if ( $page_id <= 0 || ! is_page( $page_id ) ) {
            return false;
        }
        $key = (string) $page_id;
        if ( ! isset( $this->page_cache[ $key ] ) ) {
            $this->page_cache[ $key ] = My_IAPSNJ_Checkout_Fields::preview_form_id() !== ''
                || My_IAPSNJ_Checkout_Fields::form_for_cart( self::current_cart() ) !== '';
        }
        return $this->page_cache[ $key ];
    }

    // -----------------------------------------------------------------------
    // Page title
    // -----------------------------------------------------------------------

    /**
     * the_title — the application's title for the checkout page itself.
     *
     * @param mixed $title
     * @param mixed $post_id
     * @return mixed
     */
    public function filter_title( $title, $post_id = 0 ) {
        if ( (int) $post_id <= 0 || (int) $post_id !== self::checkout_page_id() ) {
            return $title;
        }
        return $this->is_membership_checkout_page() ? $this->application_title() : $title;
    }

    /**
     * "Regular Member Application", "Associate Member Application" … for
     * this cart, the same text as the heading above the fields.
     */
    private function application_title(): string {
        if ( $this->title === null ) {
            $cart        = self::current_cart();
            $preview     = My_IAPSNJ_Checkout_Fields::preview_form_id();
            $form_id     = $preview !== '' ? $preview : My_IAPSNJ_Checkout_Fields::form_for_cart( $cart );
            $this->title = My_IAPSNJ_Checkout_Fields::application_title( $form_id, My_IAPSNJ_Checkout_Fields::member_type_for_checkout( $cart ) );
        }
        return $this->title;
    }

    /**
     * document_title_parts — the browser tab reads the same.
     *
     * @param mixed $parts
     * @return mixed
     */
    public function filter_document_title( $parts ) {
        if ( is_array( $parts ) && $this->is_membership_checkout_page() ) {
            $parts['title'] = $this->application_title();
        }
        return $parts;
    }

    // -----------------------------------------------------------------------
    // Layout: one column, summary below the application
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/checkout_page_css_classes — mark a membership checkout so
     * the stylesheet can make it one column.
     *
     * @param mixed $classes
     * @param mixed $args ['cart']
     * @return mixed
     */
    public function filter_wrapper_classes( $classes, $args = [] ) {
        $cart = is_array( $args ) ? ( $args['cart'] ?? null ) : null;
        if ( My_IAPSNJ_Checkout_Fields::preview_form_id() === '' && My_IAPSNJ_Checkout_Fields::form_for_cart( $cart ) === '' ) {
            return $classes;
        }
        if ( is_array( $classes ) ) {
            $classes[] = self::WRAPPER_CLASS;
        } elseif ( is_string( $classes ) ) {
            $classes = trim( $classes . ' ' . self::WRAPPER_CLASS );
        }
        return $classes;
    }

    /**
     * The application stylesheet (which also holds the one-column layout)
     * in the page head of the checkout page, so the page does not first draw
     * FluentCart's two columns and then change.
     */
    public function enqueue_style(): void {
        $page_id = self::checkout_page_id();
        if ( $page_id > 0 && is_page( $page_id ) ) {
            wp_enqueue_style( 'my-iapsnj-checkout', MY_IAPSNJ_URL . 'public/css/checkout-fields.css', [], MY_IAPSNJ_VERSION );
        }
    }

    /**
     * Move FluentCart's summary column to just after the application, before
     * the payment methods. Repeated after FluentCart's AJAX refreshes and for
     * a few seconds (the checkout block renders late). Without scripts the
     * one-column stylesheet still puts the summary below the form.
     */
    public function print_footer_script(): void {
        if ( ! My_IAPSNJ_Checkout_Fields::get_instance()->was_rendered() ) {
            return;
        }
        $this->print_check_note();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script
        echo '<script>(function(){'
            . 'function place(){var a=document.getElementById("my-iapsnj-application"),s=document.querySelector(".fct_checkout_summary");'
            . 'if(!a||!s||!a.parentNode||s.contains(a)){return;}if(a.nextElementSibling!==s){a.parentNode.insertBefore(s,a.nextSibling);}'
            . 'if(s.className.indexOf("my-iapsnj-summary")<0){s.className+=" my-iapsnj-summary";}}'
            . 'place();window.addEventListener("fluentCartFragmentsReplaced",place);'
            . 'var n=0,t=setInterval(function(){place();if(++n>20){clearInterval(t);}},500);'
            . '})();</script>' . "\n";
    }

    // -----------------------------------------------------------------------
    // Wording: Coupon → Discount Code, yearly billing line
    // -----------------------------------------------------------------------

    /**
     * Is this string shown to a shopper? FluentCart's admin screens (admin
     * pages and its REST API) keep "Coupon"; the storefront — pages, the
     * admin-ajax calls the checkout makes, emails — reads "Discount Code".
     */
    private static function storefront(): bool {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }
        if ( wp_doing_ajax() ) {
            $referer = (string) wp_get_raw_referer();
            return $referer === '' || strpos( $referer, admin_url() ) !== 0;
        }
        return ! is_admin();
    }

    /**
     * @param mixed $translation
     * @param mixed $text
     * @return mixed
     */
    private function reword( $translation, $text ) {
        if ( ! is_string( $translation ) || ! is_string( $text ) ) {
            return $translation;
        }
        if ( $this->billing_line ) {
            // Helper::generateSubscriptionInfo(): "%1$s %2$s %3$s" with
            // "per %s" and "until cancel" → "$30.00/year, billed automatically".
            switch ( $text ) {
                case 'per %s':
                    return '/%s';
                case '%1$s %2$s %3$s':
                    return '%1$s%2$s, %3$s';
                case 'until cancel':
                    return __( 'billed automatically', 'my-iapsnj' );
            }
        }
        $exact = isset( self::STRINGS[ $text ] );
        if ( ! $exact && stripos( $translation, 'coupon' ) === false ) {
            return $translation;
        }
        if ( ! self::storefront() ) {
            return $translation;
        }
        return $exact ? self::STRINGS[ $text ] : strtr( $translation, self::COUPON_WORDS );
    }

    /**
     * gettext_fluent-cart
     *
     * @param mixed $translation
     * @param mixed $text
     * @param mixed $domain
     * @return mixed
     */
    public function filter_gettext( $translation, $text = '', $domain = '' ) {
        return $this->reword( $translation, $text );
    }

    /**
     * gettext_with_context_fluent-cart
     *
     * @param mixed $translation
     * @param mixed $text
     * @param mixed $context
     * @param mixed $domain
     * @return mixed
     */
    public function filter_gettext_with_context( $translation, $text = '', $context = '', $domain = '' ) {
        return $this->reword( $translation, $text );
    }

    /**
     * ngettext_fluent-cart
     *
     * @param mixed $translation
     * @param mixed $single
     * @param mixed $plural
     * @param mixed $number
     * @param mixed $domain
     * @return mixed
     */
    public function filter_ngettext( $translation, $single = '', $plural = '', $number = 1, $domain = '' ) {
        return $this->reword( $translation, (int) $number === 1 ? $single : $plural );
    }

    /**
     * Is a cart line a yearly membership subscription with no end date?
     *
     * @param mixed $item cart item (array)
     */
    private static function is_yearly_membership( $item ): bool {
        if ( is_object( $item ) ) {
            $item = (array) $item;
        }
        if ( ! is_array( $item ) ) {
            return false;
        }
        $other = $item['other_info'] ?? [];
        if ( is_object( $other ) ) {
            $other = (array) $other;
        }
        if ( ! is_array( $other ) || (string) ( $other['payment_type'] ?? '' ) !== 'subscription' ) {
            return false;
        }
        if ( ! in_array( (string) ( $other['repeat_interval'] ?? '' ), [ 'yearly', 'year' ], true ) || (int) ( $other['times'] ?? 0 ) > 0 ) {
            return false;
        }
        $vid = (int) ( $item['object_id'] ?? ( $item['variation_id'] ?? 0 ) );
        return $vid > 0 && My_IAPSNJ_Membership::product_config( $vid ) !== null;
    }

    /**
     * fluent_cart/cart/line_item/before_main_title — a yearly membership
     * line: reword FluentCart's billing text until the title is printed.
     *
     * @param mixed $info ['item','cart','product','variant']
     */
    public function line_item_start( $info = [] ): void {
        $this->line_item_close();
        if ( ! self::is_yearly_membership( is_array( $info ) ? ( $info['item'] ?? null ) : null ) ) {
            return;
        }
        $this->billing_line   = true;
        $this->billing_buffer = ob_get_level();
        ob_start();
    }

    /**
     * fluent_cart/cart/line_item/after_main_title — print the title with
     * whole-dollar amounts in the billing line ("$30/year").
     *
     * @param mixed $info
     */
    public function line_item_end( $info = [] ): void {
        $this->line_item_close();
    }

    private function line_item_close(): void {
        $this->billing_line = false;
        if ( $this->billing_buffer < 0 ) {
            return;
        }
        $html = '';
        while ( ob_get_level() > $this->billing_buffer ) {
            $html = (string) ob_get_clean() . $html;
        }
        $this->billing_buffer = -1;
        $html = (string) preg_replace_callback(
            '#(<div class="fct_item_payment_info">)(.*?)(</div>)#s',
            function ( $m ) {
                return $m[1] . preg_replace( '/(\d)[.,]00(?!\d)/', '$1', $m[2] ) . $m[3];
            },
            $html
        );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FluentCart's own escaped markup
    }

    // -----------------------------------------------------------------------
    // Note under the Discount Code field
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/checkout/before_summary_total — prints right after the
     * Discount Code field, inside the summary list; a membership cart only,
     * and only while the field is shown.
     *
     * @param mixed $args ['cart']
     */
    public function render_discount_note( $args = [] ): void {
        $cart = is_array( $args ) ? ( $args['cart'] ?? null ) : null;
        if ( ! is_object( $cart ) || My_IAPSNJ_Checkout_Fields::form_for_cart( $cart ) === '' ) {
            return;
        }
        $note = self::note( 'checkout_discount_note' );
        if ( $note === '' || self::coupon_field_hidden( $cart ) ) {
            return;
        }
        echo '<li class="my-iapsnj-discount-note"><p>' . wp_kses( $note, self::note_tags() ) . '</p></li>';
    }

    /** Same test as FluentCart's CartSummaryRender::renderItemsFooter(). */
    private static function coupon_field_hidden( $cart ): bool {
        try {
            if ( class_exists( '\FluentCart\Api\StoreSettings' ) && ( new \FluentCart\Api\StoreSettings() )->get( 'hide_coupon_field' ) === 'yes' ) {
                return true;
            }
            $cd = $cart->checkout_data;
            return is_array( $cd ) && ( $cd['disable_coupons'] ?? 'no' ) === 'yes';
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Pay by Check: second in the list, note on mail delays
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/checkout_active_payment_methods — on a membership checkout
     * Pay by Check is second, after the first card method, whatever order
     * FluentCart → Settings → Payments has (option
     * fluent_cart_payment_methods_order). The first method is the one
     * selected by default, so the card stays preselected.
     *
     * @param mixed $methods list of FluentCart gateway objects
     * @param mixed $args    ['cart']
     * @return mixed
     */
    public function order_payment_methods( $methods, $args = [] ) {
        if ( ! is_array( $methods ) || count( $methods ) < 2 ) {
            return $methods;
        }
        $cart = is_array( $args ) ? ( $args['cart'] ?? null ) : null;
        if ( My_IAPSNJ_Checkout_Fields::form_for_cart( $cart ) === '' ) {
            return $methods; // event / merchandise checkouts keep FluentCart's order
        }
        $list    = array_values( $methods );
        $offline = null;
        foreach ( $list as $i => $method ) {
            if ( self::method_route( $method ) === self::OFFLINE_METHOD ) {
                $offline = $i;
                break;
            }
        }
        if ( $offline === null || $offline === 1 ) {
            return $methods;
        }
        $check = $list[ $offline ];
        array_splice( $list, $offline, 1 );
        array_splice( $list, 1, 0, [ $check ] );
        return $list;
    }

    /**
     * @param mixed $method FluentCart gateway (AbstractPaymentGateway)
     */
    private static function method_route( $method ): string {
        try {
            if ( is_object( $method ) && method_exists( $method, 'getMeta' ) ) {
                return (string) $method->getMeta( 'route' );
            }
        } catch ( \Throwable $e ) {
            // unknown shape
        }
        return '';
    }

    /**
     * The note on mail delays, printed once as a template; the footer
     * script puts it right under the Pay by Check option and again after
     * FluentCart refreshes the payment methods. FluentCart's own hook for a
     * method prints inside its label, before the title, so it is not used.
     * Only on a membership checkout (the note speaks of the application).
     */
    private function print_check_note(): void {
        $note = self::note( 'check_delay_note' );
        if ( $note === '' ) {
            return;
        }
        echo '<template id="my-iapsnj-check-note"><p class="my-iapsnj-check-note">' . wp_kses( $note, self::note_tags() ) . '</p></template>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script
        echo '<script>(function(){var M=' . wp_json_encode( self::OFFLINE_METHOD ) . ';'
            . 'function put(){var t=document.getElementById("my-iapsnj-check-note");if(!t||!t.content){return;}'
            . 'document.querySelectorAll(".fct_payment_method_"+M).forEach(function(w){var n=w.nextElementSibling;'
            . 'if(n&&n.classList&&n.classList.contains("my-iapsnj-check-note")){return;}'
            . 'w.parentNode.insertBefore(t.content.firstElementChild.cloneNode(true),w.nextSibling);});}'
            . 'put();window.addEventListener("fluentCartFragmentsReplaced",put);'
            . 'var n=0,i=setInterval(function(){put();if(++n>20){clearInterval(i);}},500);'
            . '})();</script>' . "\n";
    }
}
