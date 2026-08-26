<?php
/**
 * My_IAPSNJ_Membership
 *
 * FluentCart → FluentCRM membership state. This is the single code path for
 * "a member paid", whatever the payment method:
 *
 *   card at checkout          → Stripe/PayPal webhook → fluent_cart/order_paid
 *   check, marked paid later  → Pending Checks screen  → fluent_cart/order_paid
 *   check without the website → Record a Check         → fluent_cart/order_paid
 *
 * On fluent_cart/order_paid the handler applies Paid-YYYY tags, sets
 * member_type and paid_through, removes the pending tags, creates the
 * WordPress user if none exists, resolves the application, and sends the
 * new-member admin notification (name, full mailing address, email, phone,
 * department — the certificate trigger, and it fires on paid only).
 *
 * fluent_cart/order_paid is dispatched synchronously by FluentCart's
 * StatusHelper::syncOrderStatuses() for every path above (verified against
 * FluentCart 1.6.3 source). fluent_cart/order_paid_done is the async variant;
 * it is not used here because the Pending Checks batch needs the result
 * immediately.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Membership {

    /** @var self|null */
    private static ?self $instance = null;

    // Order meta keys (fct_order_meta).
    const META_APPLIED      = '_my_iapsnj_applied';
    const META_SNAPSHOT     = '_my_iapsnj_snapshot';
    const META_SKIPPED      = '_my_iapsnj_skipped';
    const META_PENDING      = '_my_iapsnj_pending_check';
    const META_REFUNDED     = '_my_iapsnj_refunded';
    const META_SOURCE       = '_my_iapsnj_source';       // 'checkout' | 'manual_check'
    const META_CHECK_NUMBER = '_my_iapsnj_check_number';
    const META_DEPOSIT_DATE = '_my_iapsnj_deposit_date';
    const META_NOTIFIED     = '_my_iapsnj_notified';

    // Cart checkout_data key and public query parameter carrying the token.
    const CART_TOKEN_KEY = '__iapsnj_app';
    const QUERY_TOKEN    = 'iapsnj_app';
    const COOKIE_TOKEN   = 'my_iapsnj_app';

    // FluentCart's built-in offline method (labelled "Cash" until renamed).
    const OFFLINE_METHOD = 'offline_payment';
    const OFFLINE_SETTINGS_KEY = 'fluent_cart_payment_settings_offline_payment';

    const OPTION_PRODUCTS = 'my_iapsnj_products';

    /** @var bool Suppress FluentCart's "order placed (offline)" mail while Record a Check runs. */
    private static bool $suppress_offline_mail = false;

    /** @var string Email to lock at checkout (set during the fields filter, printed in wp_footer). */
    private string $lock_email = '';

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'fluent_cart/order_paid',            [ $this, 'on_order_paid' ], 10, 1 );
        add_action( 'fluent_cart/order_placed_offline',  [ $this, 'on_order_placed_offline' ], 10, 1 );
        add_action( 'fluent_cart/order_fully_refunded',  [ $this, 'on_order_fully_refunded' ], 10, 1 );

        // Application token → cart, email lock at checkout.
        add_action( 'init', [ $this, 'capture_token_cookie' ] );
        add_filter( 'fluent_cart/checkout_page_name_fields_schema', [ $this, 'filter_checkout_name_fields' ], 20, 2 );
        add_action( 'wp_footer', [ $this, 'print_email_lock_script' ], 99 );

        add_filter( 'fluent_cart/should_send_email_notification', [ $this, 'filter_email_notification' ], 10, 2 );
    }

    /**
     * FluentCart present?
     */
    public static function is_available(): bool {
        return class_exists( '\FluentCart\App\Models\Order' )
            && class_exists( '\FluentCart\App\Helpers\StatusHelper' );
    }

    // -----------------------------------------------------------------------
    // Product configuration
    // -----------------------------------------------------------------------

    /**
     * variation id → [ member_type, paid_through, years[] ]
     *
     * @return array<int,array>
     */
    public static function products_config(): array {
        $raw = get_option( self::OPTION_PRODUCTS, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $vid => $cfg ) {
            $vid = (int) $vid;
            if ( $vid <= 0 || ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
                continue;
            }
            $type = (string) ( $cfg['member_type'] ?? '' );
            if ( ! in_array( $type, My_IAPSNJ_Schema::member_types(), true ) ) {
                continue;
            }
            $years = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $cfg['years'] ?? [] ) ) ) ) );
            sort( $years );
            $out[ $vid ] = [
                'label'        => (string) ( $cfg['label'] ?? '' ),
                'member_type'  => $type,
                'paid_through' => $type === My_IAPSNJ_Schema::TYPE_LIFETIME ? '' : My_IAPSNJ_Dates::ymd( $cfg['paid_through'] ?? '' ),
                'years'        => $years,
            ];
        }
        return $out;
    }

    public static function product_config( int $variation_id ): ?array {
        return self::products_config()[ $variation_id ] ?? null;
    }

    /**
     * Persist the product configuration (admin screen). Unknown keys dropped.
     *
     * @param array<int,array> $config
     */
    public static function save_products_config( array $config ): void {
        $clean = [];
        foreach ( $config as $vid => $cfg ) {
            $vid = (int) $vid;
            if ( $vid <= 0 || ! is_array( $cfg ) ) {
                continue;
            }
            $years = [];
            foreach ( (array) ( $cfg['years'] ?? [] ) as $y ) {
                $y = (int) $y;
                if ( $y >= 2000 && $y <= 2100 ) {
                    $years[] = $y;
                }
            }
            $clean[ $vid ] = [
                'label'        => sanitize_text_field( (string) ( $cfg['label'] ?? '' ) ),
                'enabled'      => ! empty( $cfg['enabled'] ),
                'member_type'  => sanitize_text_field( (string) ( $cfg['member_type'] ?? '' ) ),
                'paid_through' => My_IAPSNJ_Dates::ymd( $cfg['paid_through'] ?? '' ),
                'years'        => array_values( array_unique( $years ) ),
            ];
        }
        update_option( self::OPTION_PRODUCTS, $clean );
    }

    /**
     * Every FluentCart product variation, for the configuration screen.
     *
     * @return array<int,array{id:int,post_id:int,title:string,price_cents:int,payment_type:string,status:string}>
     */
    public static function all_variations(): array {
        if ( ! class_exists( '\FluentCart\App\Models\ProductVariation' ) ) {
            return [];
        }
        $out = [];
        try {
            $variations = \FluentCart\App\Models\ProductVariation::query()->with( 'product' )->orderBy( 'post_id' )->get();
            foreach ( $variations as $v ) {
                $product_title = '';
                if ( isset( $v->product ) && is_object( $v->product ) ) {
                    $product_title = (string) ( $v->product->post_title ?? '' );
                }
                $title = trim( $product_title . ( $v->variation_title ? ' — ' . $v->variation_title : '' ) );
                $out[ (int) $v->id ] = [
                    'id'           => (int) $v->id,
                    'post_id'      => (int) $v->post_id,
                    'title'        => $title !== '' ? $title : ( 'Variation #' . (int) $v->id ),
                    'price_cents'  => (int) $v->item_price,
                    'payment_type' => (string) $v->payment_type,
                    'status'       => (string) ( $v->item_status ?? '' ),
                ];
            }
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: could not list FluentCart variations: ' . $e->getMessage() );
        }
        return $out;
    }

    /**
     * Instant-checkout URL for a variation (FluentCart 1.6 format, verified in
     * WebRoutes::registerRoutes). Extra query args survive the redirect.
     */
    public static function checkout_url( int $variation_id, array $extra = [] ): string {
        $url = site_url( '?fluent-cart=instant_checkout&item_id=' . $variation_id . '&quantity=1' );
        return $extra ? add_query_arg( $extra, $url ) : $url;
    }

    // -----------------------------------------------------------------------
    // Order → membership plan
    // -----------------------------------------------------------------------

    /**
     * Combine the configured items of an order into one membership outcome.
     *
     * @param object $order FluentCart Order (order_items loaded)
     * @return array{member_type:string,paid_through:string,years:int[],items:array,lifetime:bool}|null
     */
    public static function plan_for_order( $order ): ?array {
        $config = self::products_config();
        if ( ! $config ) {
            return null;
        }
        $items = [];
        try {
            $items = $order->order_items ?? [];
        } catch ( \Throwable $e ) {
            $items = [];
        }

        $plan = [
            'member_type'  => '',
            'paid_through' => '',
            'years'        => [],
            'items'        => [],
            'lifetime'     => false,
        ];
        foreach ( $items as $item ) {
            $vid = (int) ( $item->object_id ?? 0 );
            if ( ! isset( $config[ $vid ] ) ) {
                continue;
            }
            $cfg = $config[ $vid ];
            $plan['items'][] = [
                'variation_id' => $vid,
                'title'        => trim( (string) ( $item->post_title ?? '' ) . ' ' . (string) ( $item->title ?? '' ) ),
                'quantity'     => (int) ( $item->quantity ?? 1 ),
                'member_type'  => $cfg['member_type'],
                'paid_through' => $cfg['paid_through'],
                'years'        => $cfg['years'],
            ];
            if ( My_IAPSNJ_Schema::member_type_rank( $cfg['member_type'] ) > My_IAPSNJ_Schema::member_type_rank( $plan['member_type'] ) ) {
                $plan['member_type'] = $cfg['member_type'];
            }
            if ( $cfg['member_type'] === My_IAPSNJ_Schema::TYPE_LIFETIME ) {
                $plan['lifetime'] = true;
            }
            $plan['paid_through'] = My_IAPSNJ_Dates::ymd_max( $plan['paid_through'], $cfg['paid_through'] );
            $plan['years']        = array_merge( $plan['years'], $cfg['years'] );
        }
        if ( ! $plan['items'] ) {
            return null;
        }
        $plan['years'] = array_values( array_unique( $plan['years'] ) );
        sort( $plan['years'] );
        if ( $plan['lifetime'] ) {
            $plan['paid_through'] = '';
        }
        return $plan;
    }

    // -----------------------------------------------------------------------
    // Hook: order paid
    // -----------------------------------------------------------------------

    /**
     * @param array $data ['order' => Order, 'customer' => Customer|null, 'transaction' => OrderTransaction|null]
     */
    public function on_order_paid( $data ): void {
        $order = is_array( $data ) ? ( $data['order'] ?? null ) : null;
        if ( ! is_object( $order ) || empty( $order->id ) ) {
            return;
        }
        try {
            $this->apply_paid_order( $order );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: order_paid handler failed for order ' . (int) $order->id . ': ' . $e->getMessage() );
            $this->order_log( $order, 'My IAPSNJ: membership update FAILED', $e->getMessage(), 'error' );
        }
    }

    /**
     * Apply membership state for a paid order. Idempotent per order.
     *
     * @param object $order FluentCart Order
     * @return array|null What was applied, or null when skipped.
     */
    public function apply_paid_order( $order ): ?array {
        if ( (string) $order->payment_status !== 'paid' ) {
            return null;
        }
        if ( in_array( (string) $order->type, [ 'renewal' ], true ) ) {
            return null; // store-managed subscription renewals do not exist in this setup
        }
        if ( $order->getMeta( self::META_APPLIED ) ) {
            return null;
        }

        $order->load( [ 'customer', 'order_items', 'billing_address' ] );

        $plan = self::plan_for_order( $order );
        if ( ! $plan ) {
            $order->updateMeta( self::META_SKIPPED, 'no_configured_membership_product' );
            return null;
        }

        $email = $this->order_email( $order );
        if ( ! is_email( $email ) ) {
            $order->updateMeta( self::META_SKIPPED, 'no_customer_email' );
            $this->order_log( $order, 'My IAPSNJ: membership not applied', 'Order has no customer email.', 'error' );
            return null;
        }

        // ---- Snapshot before we touch anything (used by refunds) ----------
        $existing = $this->find_subscriber( $email, $order );
        $snapshot = [
            'subscriber_id' => $existing ? (int) $existing->id : 0,
            'member_type'   => $existing ? My_IAPSNJ_Schema::field( $existing, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ) : '',
            'paid_through'  => $existing ? My_IAPSNJ_Schema::field( $existing, My_IAPSNJ_Schema::FIELD_PAID_THROUGH ) : '',
            'tags'          => $existing ? My_IAPSNJ_Schema::managed_tag_slugs( $existing ) : [],
        ];
        // OrderMeta json-encodes arrays on write and decodes on read; store the
        // array itself and read it back through meta_array().
        $order->updateMeta( self::META_SNAPSHOT, $snapshot );

        // ---- Upsert contact (email, names, address) ------------------------
        $subscriber = $this->upsert_contact_from_order( $order, $existing );
        if ( ! $subscriber instanceof Subscriber ) {
            $order->updateMeta( self::META_SKIPPED, 'contact_upsert_failed' );
            $this->order_log( $order, 'My IAPSNJ: membership not applied', 'FluentCRM contact could not be created or updated.', 'error' );
            return null;
        }

        // ---- member_type / paid_through -----------------------------------
        $new_type = $plan['member_type'];
        $old_type = $snapshot['member_type'];
        // A purchase never lowers a member's type (Lifetime / Honorary stay).
        if ( My_IAPSNJ_Schema::member_type_rank( $old_type ) > My_IAPSNJ_Schema::member_type_rank( $new_type ) ) {
            $new_type = $old_type;
        }
        $fields = [ My_IAPSNJ_Schema::FIELD_MEMBER_TYPE => $new_type ];
        if ( My_IAPSNJ_Schema::is_comped_type( $new_type ) ) {
            // Never a far-future date: null (deleted) is the contract.
            $fields[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ] = '';
        } else {
            $fields[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ] = My_IAPSNJ_Dates::ymd_max( $snapshot['paid_through'], $plan['paid_through'] );
        }
        My_IAPSNJ_Schema::set_fields( $subscriber, $fields );

        // ---- Tags ----------------------------------------------------------
        $add_slugs = [];
        foreach ( $plan['years'] as $y ) {
            $add_slugs[] = My_IAPSNJ_Schema::paid_tag_slug( (int) $y );
        }
        if ( $new_type === My_IAPSNJ_Schema::TYPE_LIFETIME ) {
            $add_slugs[] = My_IAPSNJ_Schema::TAG_LIFETIME;
        }
        $tags_added = array_values( array_diff( $add_slugs, $snapshot['tags'] ) );
        if ( $add_slugs ) {
            $subscriber->attachTags( array_values( My_IAPSNJ_Schema::tag_ids( $add_slugs ) ) );
        }
        $remove_ids = My_IAPSNJ_Schema::tag_ids( [ My_IAPSNJ_Schema::TAG_PENDING_CHECK, My_IAPSNJ_Schema::TAG_ABANDONED ] );
        $subscriber->detachTags( array_values( $remove_ids ) );

        // ---- WordPress login -----------------------------------------------
        $user_id = $this->ensure_wp_user( $subscriber, $order );

        // ---- Application ---------------------------------------------------
        $app = My_IAPSNJ_Applications::resolve_for_order( $order );
        if ( $app ) {
            My_IAPSNJ_Applications::mark_paid( $app, (int) $order->id );
        }

        // ---- New member? ---------------------------------------------------
        $had_paid_years = false;
        foreach ( $snapshot['tags'] as $slug ) {
            if ( My_IAPSNJ_Schema::year_from_paid_slug( $slug ) ) {
                $had_paid_years = true;
                break;
            }
        }
        $is_new = ( $app && $app->kind === My_IAPSNJ_Applications::KIND_JOIN )
            || ( ! $had_paid_years && ! My_IAPSNJ_Schema::is_comped_type( $old_type ) && ! $existing );
        if ( ! $is_new && ! $had_paid_years && ! My_IAPSNJ_Schema::is_comped_type( $old_type ) && ! $app ) {
            // Existing contact with no payment history and no application on
            // file: first payment we have seen for them. Treat as new so the
            // certificate notification is not lost.
            $is_new = true;
        }

        $applied = [
            'subscriber_id' => (int) $subscriber->id,
            'user_id'       => $user_id,
            'member_type'   => $new_type,
            'paid_through'  => $fields[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ],
            'years'         => $plan['years'],
            'tags_added'    => $tags_added,
            'items'         => $plan['items'],
            'application'   => $app ? (int) $app->id : 0,
            'kind'          => $is_new ? 'join' : 'renewal',
            'source'        => (string) ( $order->getMeta( self::META_SOURCE ) ?: 'checkout' ),
            'payment'       => (string) $order->payment_method === self::OFFLINE_METHOD ? 'check' : 'card',
            'applied_at'    => My_IAPSNJ_Dates::now_utc(),
        ];
        $order->updateMeta( self::META_APPLIED, $applied );
        $order->deleteMeta( self::META_PENDING );

        $this->order_log(
            $order,
            'My IAPSNJ: membership updated',
            sprintf(
                'Contact #%d: member_type=%s, paid_through=%s, tags added: %s',
                (int) $subscriber->id,
                $new_type,
                $applied['paid_through'] !== '' ? $applied['paid_through'] : 'null',
                $tags_added ? implode( ', ', $tags_added ) : '(none)'
            )
        );

        /**
         * A member's payment has been applied to the CRM.
         *
         * @param Subscriber $subscriber
         * @param object     $order   FluentCart Order
         * @param array      $applied What changed (member_type, paid_through, years, kind …)
         */
        do_action( 'my_iapsnj/membership_paid', $subscriber, $order, $applied );

        if ( $is_new ) {
            $this->send_new_member_notification( $subscriber, $order, $applied );
            do_action( 'my_iapsnj/new_member', $subscriber, $order, $applied );
        }

        return $applied;
    }

    // -----------------------------------------------------------------------
    // Hook: offline order placed (check selected at checkout)
    // -----------------------------------------------------------------------

    /**
     * @param array $data ['order' => Order, 'customer' => Customer, 'transaction' => OrderTransaction]
     */
    public function on_order_placed_offline( $data ): void {
        $order = is_array( $data ) ? ( $data['order'] ?? null ) : null;
        if ( ! is_object( $order ) || empty( $order->id ) ) {
            return;
        }
        try {
            if ( ! self::plan_for_order( $order ) ) {
                return; // not a membership product
            }
            $email = $this->order_email( $order );
            if ( ! is_email( $email ) ) {
                return;
            }
            $subscriber = $this->upsert_contact_from_order( $order, $this->find_subscriber( $email, $order ) );
            if ( $subscriber instanceof Subscriber ) {
                $ids = My_IAPSNJ_Schema::tag_ids( [ My_IAPSNJ_Schema::TAG_PENDING_CHECK, My_IAPSNJ_Schema::TAG_ABANDONED ] );
                $subscriber->attachTags( [ $ids[ My_IAPSNJ_Schema::TAG_PENDING_CHECK ] ] );
                $subscriber->detachTags( [ $ids[ My_IAPSNJ_Schema::TAG_ABANDONED ] ] );
            }
            $app = My_IAPSNJ_Applications::resolve_for_order( $order );
            if ( $app ) {
                My_IAPSNJ_Applications::mark_awaiting_check( $app, (int) $order->id );
            }
            $order->updateMeta( self::META_PENDING, '1' );
            if ( ! $order->getMeta( self::META_SOURCE ) ) {
                $order->updateMeta( self::META_SOURCE, 'checkout' );
            }
            $this->order_log( $order, 'My IAPSNJ: check payment pending', 'Contact tagged Payment-Pending-Check. Mark the order paid from My IAPSNJ → Pending Checks when the check clears.' );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: order_placed_offline handler failed for order ' . (int) $order->id . ': ' . $e->getMessage() );
        }
    }

    // -----------------------------------------------------------------------
    // Hook: full refund
    // -----------------------------------------------------------------------

    /**
     * Reverse exactly what this order applied: the tags it added and the
     * member_type / paid_through values it replaced.
     */
    public function on_order_fully_refunded( $data ): void {
        $order = is_array( $data ) ? ( $data['order'] ?? null ) : null;
        if ( ! is_object( $order ) || empty( $order->id ) ) {
            return;
        }
        try {
            $applied  = self::meta_array( $order, self::META_APPLIED );
            $snapshot = self::meta_array( $order, self::META_SNAPSHOT );
            if ( ! is_array( $applied ) || $order->getMeta( self::META_REFUNDED ) ) {
                return;
            }
            $superseded = [];
            $subscriber = Subscriber::where( 'id', (int) ( $applied['subscriber_id'] ?? 0 ) )->first();
            if ( $subscriber instanceof Subscriber ) {
                $added = (array) ( $applied['tags_added'] ?? [] );
                if ( $added ) {
                    $subscriber->detachTags( array_values( My_IAPSNJ_Schema::tag_ids( $added ) ) );
                }
                // Restore a field only if it still holds what this order set.
                // A later, unrelated payment may have moved it since; that
                // order's effect must survive the refund of this one.
                $restore = [];
                if ( is_array( $snapshot ) ) {
                    $current_type = My_IAPSNJ_Schema::field( $subscriber, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE );
                    $current_pt   = My_IAPSNJ_Dates::ymd( My_IAPSNJ_Schema::field( $subscriber, My_IAPSNJ_Schema::FIELD_PAID_THROUGH ) );
                    if ( $current_type === (string) ( $applied['member_type'] ?? '' ) ) {
                        $restore[ My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ] = (string) ( $snapshot['member_type'] ?? '' );
                    } else {
                        $superseded[] = My_IAPSNJ_Schema::FIELD_MEMBER_TYPE;
                    }
                    if ( $current_pt === My_IAPSNJ_Dates::ymd( $applied['paid_through'] ?? '' ) ) {
                        $restore[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ] = (string) ( $snapshot['paid_through'] ?? '' );
                    } else {
                        $superseded[] = My_IAPSNJ_Schema::FIELD_PAID_THROUGH;
                    }
                }
                if ( $restore ) {
                    My_IAPSNJ_Schema::set_fields( $subscriber, $restore );
                }
            }
            My_IAPSNJ_Applications::mark_refunded_for_order( (int) $order->id );
            $order->updateMeta( self::META_REFUNDED, My_IAPSNJ_Dates::now_utc() );
            $this->order_log(
                $order,
                'My IAPSNJ: membership reverted',
                'Full refund: tags removed and member_type / paid_through restored from the pre-payment snapshot.'
                . ( $superseded ? ' Left unchanged because a later order superseded them: ' . implode( ', ', $superseded ) . '.' : '' )
            );

            do_action( 'my_iapsnj/membership_refunded', $subscriber, $order, $applied, $snapshot );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: refund handler failed for order ' . (int) $order->id . ': ' . $e->getMessage() );
        }
    }

    // -----------------------------------------------------------------------
    // Checkout: token capture + email lock
    // -----------------------------------------------------------------------

    /**
     * The Fluent Forms redirect lands on ?fluent-cart=instant_checkout&…&iapsnj_app=TOKEN,
     * which FluentCart forwards to the checkout page with the token intact.
     * Remember it in a short-lived cookie for the rest of the checkout.
     */
    public function capture_token_cookie(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = isset( $_GET[ self::QUERY_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_TOKEN ] ) ) : '';
        if ( $token === '' || ! My_IAPSNJ_Applications::get_by_token( $token ) ) {
            return;
        }
        if ( ! headers_sent() ) {
            setcookie( self::COOKIE_TOKEN, $token, time() + 2 * HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
        }
        $_COOKIE[ self::COOKIE_TOKEN ] = $token;
    }

    /**
     * Token for the current front-end request (query string, then cookie).
     */
    public static function current_token(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = isset( $_GET[ self::QUERY_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_TOKEN ] ) ) : '';
        if ( $token === '' && isset( $_COOKIE[ self::COOKIE_TOKEN ] ) ) {
            $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_TOKEN ] ) );
        }
        return $token;
    }

    /**
     * fluent_cart/checkout_page_name_fields_schema — store the token on the
     * cart and prefill + lock the email so the order cannot orphan.
     *
     * @param array $fields
     * @param array $data ['cart' => Cart|null, 'scope' => 'render'|…]
     * @return array
     */
    public function filter_checkout_name_fields( $fields, $data = [] ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }
        $token = self::current_token();
        if ( $token === '' ) {
            return $fields;
        }
        $app = My_IAPSNJ_Applications::get_by_token( $token );
        if ( ! $app ) {
            return $fields;
        }

        $cart = is_array( $data ) ? ( $data['cart'] ?? null ) : null;
        if ( is_object( $cart ) ) {
            try {
                $cd = $cart->checkout_data;
                if ( ! is_array( $cd ) ) {
                    $cd = [];
                }
                if ( ( $cd[ self::CART_TOKEN_KEY ] ?? '' ) !== $token ) {
                    $cd[ self::CART_TOKEN_KEY ] = $token;
                    $cart->checkout_data        = $cd;
                    $cart->save();
                }
            } catch ( \Throwable $e ) {
                error_log( 'My IAPSNJ: could not store application token on cart: ' . $e->getMessage() );
            }
        }

        if ( isset( $fields['billing_email'] ) && is_array( $fields['billing_email'] ) ) {
            $fields['billing_email']['value']    = (string) $app->email;
            $fields['billing_email']['readonly'] = 'readonly';
            $this->lock_email                    = (string) $app->email;
        }
        $full = trim( (string) $app->first_name . ' ' . (string) $app->last_name );
        if ( $full !== '' && isset( $fields['billing_full_name'] ) && empty( $fields['billing_full_name']['value'] ) ) {
            $fields['billing_full_name']['value'] = $full;
        }
        if ( isset( $fields['billing_first_name'] ) && empty( $fields['billing_first_name']['value'] ) ) {
            $fields['billing_first_name']['value'] = (string) $app->first_name;
        }
        if ( isset( $fields['billing_last_name'] ) && empty( $fields['billing_last_name']['value'] ) ) {
            $fields['billing_last_name']['value'] = (string) $app->last_name;
        }
        return $fields;
    }

    /**
     * Belt and braces for the readonly attribute: FluentCart re-renders the
     * checkout form client-side, so enforce the lock in the browser too.
     */
    public function print_email_lock_script(): void {
        if ( $this->lock_email === '' ) {
            return;
        }
        $email = wp_json_encode( $this->lock_email );
        echo '<script>(function(){var e=' . $email . ';function lock(){var i=document.querySelector(\'input[name="billing_email"]\');if(i){if(!i.value){i.value=e;}i.readOnly=true;i.setAttribute("aria-readonly","true");}}lock();var t=setInterval(lock,800);setTimeout(function(){clearInterval(t);},60000);})();</script>' . "\n";
    }

    /**
     * Token stored on the cart that produced an order ('' when none).
     */
    public static function token_for_order( $order ): string {
        if ( ! class_exists( '\FluentCart\App\Models\Cart' ) || ! is_object( $order ) ) {
            return '';
        }
        try {
            $cart = \FluentCart\App\Models\Cart::query()->where( 'order_id', (int) $order->id )->first();
            if ( $cart ) {
                $cd = $cart->checkout_data;
                if ( is_array( $cd ) && ! empty( $cd[ self::CART_TOKEN_KEY ] ) ) {
                    return (string) $cd[ self::CART_TOKEN_KEY ];
                }
            }
        } catch ( \Throwable $e ) {
            // fall through
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // Email notification gate
    // -----------------------------------------------------------------------

    /**
     * Record a Check creates an offline order and marks it paid in the same
     * request. The member mailed the check already; FluentCart's "order placed,
     * please pay" email would be nonsense, so it is suppressed. The receipt
     * (order_paid_customer) still goes out.
     */
    public function filter_email_notification( $should, $args ) {
        if ( self::$suppress_offline_mail && is_array( $args ) && ( $args['event'] ?? '' ) === 'order_placed_offline' ) {
            return false;
        }
        return $should;
    }

    public static function suppress_offline_mail( bool $state ): void {
        self::$suppress_offline_mail = $state;
    }

    // -----------------------------------------------------------------------
    // Offline method label ("Cash" → "Pay by Check")
    // -----------------------------------------------------------------------

    /**
     * FluentCart honours `checkout_label` and `checkout_instructions` in a
     * gateway's saved settings (AbstractPaymentGateway::getMeta). Write them
     * for the offline method. Returns WP_Error when the method has never been
     * saved (enable it once in FluentCart → Settings → Payments first).
     *
     * @return true|WP_Error
     */
    public static function apply_offline_labels( string $label, string $instructions ) {
        if ( ! class_exists( '\FluentCart\App\Models\Meta' ) ) {
            return new WP_Error( 'no_fluentcart', __( 'FluentCart is not active.', 'my-iapsnj' ) );
        }
        try {
            $meta = \FluentCart\App\Models\Meta::query()->where( 'meta_key', self::OFFLINE_SETTINGS_KEY )->first();
            if ( ! $meta ) {
                return new WP_Error( 'not_configured', __( 'The offline payment method has not been saved in FluentCart yet. Open FluentCart → Settings → Payments → Cash on Delivery → Manage, enable it and save once, then retry.', 'my-iapsnj' ) );
            }
            $settings = $meta->meta_value;
            if ( ! is_array( $settings ) ) {
                $settings = [];
            }
            $settings['checkout_label']        = sanitize_text_field( $label );
            $settings['checkout_instructions'] = wp_kses_post( $instructions );
            $meta->meta_value                  = $settings;
            $meta->save();
            return true;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'save_failed', $e->getMessage() );
        }
    }

    /**
     * Current offline method label/instructions (for the settings screen).
     *
     * @return array{label:string,instructions:string,active:bool,configured:bool}
     */
    public static function offline_labels(): array {
        $out = [ 'label' => '', 'instructions' => '', 'active' => false, 'configured' => false ];
        if ( ! class_exists( '\FluentCart\App\Models\Meta' ) ) {
            return $out;
        }
        try {
            $meta = \FluentCart\App\Models\Meta::query()->where( 'meta_key', self::OFFLINE_SETTINGS_KEY )->first();
            if ( $meta && is_array( $meta->meta_value ) ) {
                $out['configured']   = true;
                $out['label']        = (string) ( $meta->meta_value['checkout_label'] ?? '' );
                $out['instructions'] = (string) ( $meta->meta_value['checkout_instructions'] ?? '' );
                $out['active']       = ( $meta->meta_value['is_active'] ?? 'no' ) === 'yes';
            }
        } catch ( \Throwable $e ) {
            // leave defaults
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Contact helpers
    // -----------------------------------------------------------------------

    private function order_email( $order ): string {
        try {
            if ( isset( $order->customer ) && is_object( $order->customer ) && ! empty( $order->customer->email ) ) {
                return sanitize_email( (string) $order->customer->email );
            }
        } catch ( \Throwable $e ) {
            // fall through
        }
        return '';
    }

    private function find_subscriber( string $email, $order ): ?Subscriber {
        $sub = Subscriber::where( 'email', $email )->first();
        if ( $sub instanceof Subscriber ) {
            return $sub;
        }
        try {
            $uid = isset( $order->customer ) && is_object( $order->customer ) ? (int) $order->customer->user_id : 0;
            if ( $uid > 0 ) {
                $sub = Subscriber::where( 'user_id', $uid )->first();
                if ( $sub instanceof Subscriber ) {
                    return $sub;
                }
            }
        } catch ( \Throwable $e ) {
            // fall through
        }
        return null;
    }

    /**
     * Create or update the CRM contact from the order's customer and billing
     * address. Address fields fill blanks by default; set
     * settings[checkout_fill_address] = 'overwrite' to prefer the checkout
     * address every time.
     */
    private function upsert_contact_from_order( $order, ?Subscriber $existing ): ?Subscriber {
        $email = $this->order_email( $order );
        if ( ! is_email( $email ) ) {
            return null;
        }
        $customer = isset( $order->customer ) && is_object( $order->customer ) ? $order->customer : null;
        $billing  = null;
        try {
            $billing = $order->billing_address ?? null;
        } catch ( \Throwable $e ) {
            $billing = null;
        }

        $first = $customer ? (string) $customer->first_name : '';
        $last  = $customer ? (string) $customer->last_name : '';
        if ( ( $first === '' || $last === '' ) && is_object( $billing ) && ! empty( $billing->name ) ) {
            $parts = preg_split( '/\s+/', trim( (string) $billing->name ) );
            if ( $first === '' ) {
                $first = (string) array_shift( $parts );
            }
            if ( $last === '' && $parts ) {
                $last = (string) implode( ' ', $parts );
            }
        }

        $data = [ 'email' => $email ];
        if ( $existing instanceof Subscriber ) {
            $data['id'] = $existing->id;
            if ( $existing->email !== $email ) {
                // createOrUpdate looks up by email; keep the existing contact.
                $data['email'] = $existing->email;
            }
            if ( $first !== '' && trim( (string) $existing->first_name ) === '' ) {
                $data['first_name'] = $first;
            }
            if ( $last !== '' && trim( (string) $existing->last_name ) === '' ) {
                $data['last_name'] = $last;
            }
        } else {
            $data['first_name'] = $first;
            $data['last_name']  = $last;
            $data['status']     = 'subscribed';
            $data['source']     = 'fluentcart';
        }
        if ( $customer && ! empty( $customer->user_id ) ) {
            $data['user_id'] = (int) $customer->user_id;
        }

        $mode    = (string) ( My_IAPSNJ_Plugin::settings()['checkout_fill_address'] ?? 'empty_only' );
        $address = [];
        if ( is_object( $billing ) ) {
            $address = [
                'address_line_1' => (string) ( $billing->address_1 ?? '' ),
                'address_line_2' => (string) ( $billing->address_2 ?? '' ),
                'city'           => (string) ( $billing->city ?? '' ),
                'state'          => (string) ( $billing->state ?? '' ),
                'postal_code'    => (string) ( $billing->postcode ?? '' ),
                'country'        => (string) ( $billing->country ?? '' ),
            ];
            try {
                $phone = (string) ( $billing->phone ?? '' );
            } catch ( \Throwable $e ) {
                $phone = '';
            }
            if ( $phone !== '' ) {
                $address['phone'] = $phone;
            }
        }
        foreach ( $address as $key => $value ) {
            $value = trim( $value );
            if ( $value === '' ) {
                continue;
            }
            if ( $existing instanceof Subscriber && $mode !== 'overwrite' && trim( (string) $existing->{ $key } ) !== '' ) {
                continue;
            }
            $data[ $key ] = $value;
        }

        $subscriber = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
        if ( ! $subscriber instanceof Subscriber ) {
            return null;
        }
        return Subscriber::where( 'id', $subscriber->id )->first() ?: $subscriber;
    }

    /**
     * Make sure the member can log in: link or create the WordPress user and
     * point the FluentCart customer and the CRM contact at it.
     */
    private function ensure_wp_user( Subscriber $subscriber, $order ): int {
        $user = null;
        if ( ! empty( $subscriber->user_id ) ) {
            $user = get_userdata( (int) $subscriber->user_id ) ?: null;
        }
        if ( ! $user ) {
            $user = get_user_by( 'email', $subscriber->email ) ?: null;
        }
        $customer = isset( $order->customer ) && is_object( $order->customer ) ? $order->customer : null;
        if ( ! $user && $customer && ! empty( $customer->user_id ) ) {
            $user = get_userdata( (int) $customer->user_id ) ?: null;
        }

        if ( ! $user ) {
            $login   = self::unique_login( (string) $subscriber->email, (string) $subscriber->first_name, (string) $subscriber->last_name );
            $user_id = wp_insert_user( [
                'user_login'   => $login,
                'user_email'   => $subscriber->email,
                'user_pass'    => wp_generate_password( 24, true, true ),
                'first_name'   => (string) $subscriber->first_name,
                'last_name'    => (string) $subscriber->last_name,
                'display_name' => trim( (string) $subscriber->first_name . ' ' . (string) $subscriber->last_name ) ?: $login,
                'role'         => 'subscriber',
            ] );
            if ( is_wp_error( $user_id ) ) {
                error_log( 'My IAPSNJ: could not create WP user for ' . $subscriber->email . ': ' . $user_id->get_error_message() );
                return 0;
            }
            // Password-reset link, not the password itself.
            wp_new_user_notification( (int) $user_id, null, 'user' );
            $user = get_userdata( (int) $user_id );
            $this->order_log( $order, 'My IAPSNJ: WordPress user created', sprintf( 'User #%d (%s) created for %s.', (int) $user_id, $login, $subscriber->email ) );
        }
        if ( ! $user ) {
            return 0;
        }

        if ( (int) $subscriber->user_id !== (int) $user->ID ) {
            $subscriber->user_id = (int) $user->ID;
            $subscriber->save();
        }
        My_IAPSNJ_Engine::remember_subscriber_link( (int) $user->ID, (int) $subscriber->id );

        if ( $customer && empty( $customer->user_id ) ) {
            try {
                $customer->user_id = (int) $user->ID;
                $customer->save();
            } catch ( \Throwable $e ) {
                // non-fatal
            }
        }
        return (int) $user->ID;
    }

    /**
     * first.last, then the email local part, then numbered suffixes.
     */
    public static function unique_login( string $email, string $first = '', string $last = '' ): string {
        $candidates = [];
        $fl = strtolower( trim( $first . '.' . $last, '.' ) );
        if ( $fl !== '' && $fl !== '.' ) {
            $candidates[] = $fl;
        }
        $local = strtolower( (string) strstr( $email, '@', true ) );
        if ( $local !== '' ) {
            $candidates[] = $local;
        }
        $candidates[] = 'member';

        foreach ( $candidates as $base ) {
            $base = sanitize_user( $base, true );
            if ( $base === '' ) {
                continue;
            }
            if ( ! username_exists( $base ) ) {
                return $base;
            }
            for ( $i = 2; $i < 1000; $i++ ) {
                if ( ! username_exists( $base . $i ) ) {
                    return $base . $i;
                }
            }
        }
        return 'member' . wp_generate_password( 6, false );
    }

    // -----------------------------------------------------------------------
    // New-member admin notification (the certificate trigger)
    // -----------------------------------------------------------------------

    private function send_new_member_notification( Subscriber $subscriber, $order, array $applied ): void {
        $settings = My_IAPSNJ_Plugin::settings();
        if ( empty( $settings['notify_new_member'] ) ) {
            return;
        }
        if ( $order->getMeta( self::META_NOTIFIED ) ) {
            return;
        }
        $recipients = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $settings['notify_emails'] ) ) );
        if ( ! $recipients ) {
            return;
        }

        $subscriber = Subscriber::where( 'id', $subscriber->id )->first() ?: $subscriber;
        $custom     = $subscriber->custom_fields();
        $name       = trim( (string) $subscriber->first_name . ' ' . (string) $subscriber->last_name );

        $address_lines = array_filter( [
            (string) $subscriber->address_line_1,
            (string) $subscriber->address_line_2,
            trim( implode( ' ', array_filter( [ (string) $subscriber->city . ( $subscriber->city ? ',' : '' ), (string) $subscriber->state, (string) $subscriber->postal_code ] ) ) ),
            (string) $subscriber->country,
        ] );

        $items = array_map( function ( $i ) {
            return $i['title'] . ( $i['quantity'] > 1 ? ' ×' . $i['quantity'] : '' );
        }, $applied['items'] );

        $rows = [
            __( 'Name', 'my-iapsnj' )            => $name,
            __( 'Member type', 'my-iapsnj' )     => $applied['member_type'],
            __( 'Paid through', 'my-iapsnj' )    => $applied['paid_through'] !== '' ? My_IAPSNJ_Dates::ymd_display( $applied['paid_through'] ) : __( 'n/a (no expiration)', 'my-iapsnj' ),
            __( 'Product', 'my-iapsnj' )         => implode( '; ', $items ),
            __( 'Payment', 'my-iapsnj' )         => ( $applied['payment'] === 'check' ? __( 'Check', 'my-iapsnj' ) : __( 'Card', 'my-iapsnj' ) ) . ' — ' . self::format_money( (int) $order->total_amount, (string) $order->currency ),
            __( 'Order', 'my-iapsnj' )           => '#' . (int) $order->id . ( $order->receipt_number ? ' (' . $order->receipt_number . ')' : '' ),
            __( 'Email', 'my-iapsnj' )           => (string) $subscriber->email,
            __( 'Phone', 'my-iapsnj' )           => (string) $subscriber->phone,
            __( 'Mailing address', 'my-iapsnj' ) => implode( "\n", $address_lines ),
            __( 'Department', 'my-iapsnj' )      => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_DEPARTMENT ] ?? '' ),
            __( 'Rank', 'my-iapsnj' )            => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_RANK ] ?? '' ),
            __( 'Member number', 'my-iapsnj' )   => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] ?? '' ),
            __( 'CRM contact', 'my-iapsnj' )     => admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $subscriber->id ),
            __( 'FluentCart order', 'my-iapsnj' ) => self::order_admin_url( $order ),
        ];

        $body = sprintf( __( 'A new IAPSNJ member has PAID (order #%d). Details below are from the CRM after payment.', 'my-iapsnj' ), (int) $order->id ) . "\n\n";
        foreach ( $rows as $label => $value ) {
            $value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
            if ( strpos( $value, "\n" ) !== false ) {
                $body .= $label . ":\n    " . str_replace( "\n", "\n    ", $value ) . "\n";
            } else {
                $body .= $label . ': ' . ( $value !== '' ? $value : '—' ) . "\n";
            }
        }
        $body .= "\n" . __( 'This notification is sent only after payment is confirmed; it never fires for an unpaid application.', 'my-iapsnj' ) . "\n";

        $subject = sprintf( __( '[IAPSNJ] New member paid: %s (%s)', 'my-iapsnj' ), $name !== '' ? $name : $subscriber->email, $applied['member_type'] );

        /**
         * Filter the new-member notification before it is sent.
         *
         * @param array $mail ['to' => string[], 'subject' => string, 'body' => string, 'headers' => string[]]
         */
        $mail = apply_filters( 'my_iapsnj/new_member_notification', [
            'to'      => array_values( $recipients ),
            'subject' => $subject,
            'body'    => $body,
            'headers' => [ 'Content-Type: text/plain; charset=UTF-8' ],
        ], $subscriber, $order, $applied );

        if ( ! empty( $mail['to'] ) ) {
            wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] );
        }
        $order->updateMeta( self::META_NOTIFIED, My_IAPSNJ_Dates::now_utc() );
    }

    // -----------------------------------------------------------------------
    // Misc helpers shared with Checks / Reports
    // -----------------------------------------------------------------------

    /**
     * Cents → "$30.00". Uses FluentCart's formatter when present.
     */
    public static function format_money( int $cents, string $currency = '' ): string {
        if ( class_exists( '\FluentCart\App\Helpers\Helper' ) && method_exists( '\FluentCart\App\Helpers\Helper', 'toDecimal' ) ) {
            try {
                return (string) \FluentCart\App\Helpers\Helper::toDecimal( $cents, true, $currency ?: null );
            } catch ( \Throwable $e ) {
                // fall through
            }
        }
        $symbol = ( $currency === '' || strtoupper( $currency ) === 'USD' ) ? '$' : strtoupper( $currency ) . ' ';
        return $symbol . number_format( $cents / 100, 2 );
    }

    /**
     * Read an array stored in FluentCart order meta. OrderMeta decodes JSON on
     * read, so the value is usually already an array; a raw JSON string (older
     * rows, other writers) is decoded here.
     */
    public static function meta_array( $order, string $key ): ?array {
        if ( ! is_object( $order ) || ! method_exists( $order, 'getMeta' ) ) {
            return null;
        }
        $raw = $order->getMeta( $key, null );
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : null;
        }
        return null;
    }

    public static function order_admin_url( $order ): string {
        if ( is_object( $order ) && method_exists( $order, 'getViewUrl' ) ) {
            try {
                return (string) $order->getViewUrl( 'admin' );
            } catch ( \Throwable $e ) {
                // fall through
            }
        }
        return admin_url( 'admin.php?page=fluent-cart#/orders/' . (int) ( $order->id ?? 0 ) . '/view' );
    }

    private function order_log( $order, string $title, string $description = '', string $type = 'info' ): void {
        if ( is_object( $order ) && method_exists( $order, 'addLog' ) ) {
            try {
                $order->addLog( $title, $description, $type, 'My IAPSNJ' );
            } catch ( \Throwable $e ) {
                // logging must never break the payment path
            }
        }
    }
}
