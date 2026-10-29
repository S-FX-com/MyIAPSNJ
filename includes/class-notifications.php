<?php
/**
 * My_IAPSNJ_Notifications
 *
 * The emails a membership payment sets off, in one place (Settings → Emails):
 *
 *  1. To the member who joins or renews
 *     - FluentCart's own emails (purchase receipt, subscription renewal,
 *       Pay by Check instructions): read from FluentCart and linked, and
 *       switchable *for membership purchases only* here (FluentCart's own
 *       switch is store-wide, so it would silence event / merchandise
 *       receipts too);
 *     - this plugin's own Welcome (join) and Renewal emails, off until
 *       switched on, with an editable subject and message: the "override"
 *       for a site that turns FluentCart's receipt off;
 *     - FluentCRM automations are listed and linked, never touched.
 *  2. To IAPSNJ staff: the President and the Membership Committee, each with
 *     recipients and "send on join / on renewal". They get the same email: one
 *     message for a new member and one for a renewal (a subject and a message
 *     written in WordPress's editor), each topped by a New member / Renewal
 *     label. The single recipient list of ≤ 4.35 (Configurations → New-member
 *     notification) became the Committee's (data v19).
 *
 * Messages are HTML and may use {tokens}. The one token {application} prints the
 * member's whole application as a styled table (membership, contact, every
 * answer of the checkout form for their level and, for staff, the CRM / order
 * links), so no field is ever missed.
 *
 * Everything is sent once per order and only after the payment is applied to
 * the CRM (My_IAPSNJ_Membership::apply_paid_order), never for an unpaid
 * application. Settings live in my_iapsnj_settings: staff_mail (the groups and
 * their 'message'), member_mail.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

final class My_IAPSNJ_Notifications {

    const PREVIEW_ACTION = 'my_iapsnj_notification_preview';

    /** Order meta: group => UTC time the staff notice went out. */
    const META_STAFF  = '_my_iapsnj_staff_notified';
    /** Order meta: UTC time the member's Welcome / Renewal email went out. */
    const META_MEMBER = '_my_iapsnj_member_mailed';
    /** Order meta of ≤ 4.35: one notice to the admins; counts as the Committee's. */
    const META_LEGACY = '_my_iapsnj_notified';
    /** User meta: where this administrator's test emails go (typed on Settings → Emails). */
    const META_TEST_TO = 'my_iapsnj_test_email';

    const SUBJECT_MAX = 200;
    const BODY_MAX    = 20000;

    public static function register_hooks(): void {
        add_filter( 'fluent_cart/should_send_email_notification', [ __CLASS__, 'filter_fluentcart_email' ], 10, 2 );
        add_action( 'admin_post_' . self::PREVIEW_ACTION, [ __CLASS__, 'render_preview' ] );
    }

    // -----------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------

    /**
     * The staff notices: slug => label.
     *
     * @return array<string,string>
     */
    public static function staff_groups(): array {
        return [
            'president' => __( 'President', 'my-iapsnj' ),
            'committee' => __( 'Membership Committee', 'my-iapsnj' ),
        ];
    }

    /**
     * The staff defaults: the Committee carries on what the single notice did
     * (new members, full details, to the site admin until set); the President
     * is off until an address is given. Nothing here is translated, so it is
     * safe to read before init.
     *
     * @return array<string,array{enabled:bool,to:string,on_join:bool,on_renewal:bool}>
     */
    public static function staff_defaults(): array {
        return [
            'president' => [ 'enabled' => false, 'to' => '', 'on_join' => true, 'on_renewal' => true ],
            'committee' => [ 'enabled' => true, 'to' => (string) get_option( 'admin_email' ), 'on_join' => true, 'on_renewal' => false ],
        ];
    }

    /**
     * The staff email's subject and message for a new member and for a renewal
     * (translated: read after init only).
     *
     * @return array{join:array{subject:string,body:string},renewal:array{subject:string,body:string}}
     */
    public static function staff_message_defaults(): array {
        $closing = __( 'This notice is sent only after payment is confirmed; it never fires for an unpaid application.', 'my-iapsnj' );
        return [
            'join'    => [
                'subject' => __( '[IAPSNJ] New member paid: {full_name} ({member_type})', 'my-iapsnj' ),
                'body'    => '<p>' . esc_html__( 'A new IAPSNJ member has joined and paid (order {order_number}).', 'my-iapsnj' ) . '</p><p>{application}</p><p><small>' . esc_html( $closing ) . '</small></p>',
            ],
            'renewal' => [
                'subject' => __( '[IAPSNJ] Membership renewed: {full_name} ({member_type})', 'my-iapsnj' ),
                'body'    => '<p>' . esc_html__( 'An IAPSNJ member has renewed and paid (order {order_number}).', 'my-iapsnj' ) . '</p><p>{application}</p><p><small>' . esc_html( $closing ) . '</small></p>',
            ],
        ];
    }

    /**
     * The member-email defaults (translated: read after init only).
     *
     * @return array{fluentcart:array<string,bool>,join:array,renewal:array}
     */
    public static function member_defaults(): array {
        $fluentcart = [];
        foreach ( array_keys( self::fluentcart_emails() ) as $key ) {
            $fluentcart[ $key ] = true;
        }
        return [
            'fluentcart' => $fluentcart,
            'join'       => [
                'enabled' => false,
                'subject' => __( 'Welcome to {organization}', 'my-iapsnj' ),
                'body'    => __( "Hello {first_name},\n\nWelcome to {organization}! Your {member_type} membership is active through {paid_through}.\n\nYour member number is {member_number}.\n\nThank you for joining.", 'my-iapsnj' ),
            ],
            'renewal'    => [
                'enabled' => false,
                'subject' => __( 'Thank you for renewing your membership', 'my-iapsnj' ),
                'body'    => __( "Hello {first_name},\n\nThank you for renewing your {member_type} membership with {organization}. It is now active through {paid_through}.\n\nYour member number is {member_number}.", 'my-iapsnj' ),
            ],
        ];
    }

    /**
     * One staff notice, saved values over the defaults.
     *
     * @return array{enabled:bool,to:string,on_join:bool,on_renewal:bool}
     */
    public static function staff( string $group ): array {
        $defaults = self::staff_defaults()[ $group ] ?? [ 'enabled' => false, 'to' => '', 'on_join' => true, 'on_renewal' => false ];
        $saved    = My_IAPSNJ_Plugin::settings()['staff_mail'][ $group ] ?? [];
        // A 'detail' (Summary / Full) saved by 4.36 is ignored: every notice carries the whole application.
        return array_intersect_key( array_merge( $defaults, is_array( $saved ) ? $saved : [] ), $defaults );
    }

    /**
     * The staff email, saved values over the defaults (an empty subject or
     * message reads as the default text).
     *
     * @return array{join:array{subject:string,body:string},renewal:array{subject:string,body:string}}
     */
    public static function staff_template(): array {
        $defaults = self::staff_message_defaults();
        $saved    = My_IAPSNJ_Plugin::settings()['staff_mail']['message'] ?? [];
        $saved    = is_array( $saved ) ? $saved : [];
        $out      = [];
        foreach ( [ 'join', 'renewal' ] as $kind ) {
            $row = array_merge( $defaults[ $kind ], is_array( $saved[ $kind ] ?? null ) ? $saved[ $kind ] : [] );
            foreach ( [ 'subject', 'body' ] as $part ) {
                if ( trim( (string) $row[ $part ] ) === '' ) {
                    $row[ $part ] = $defaults[ $kind ][ $part ];
                }
            }
            $out[ $kind ] = $row;
        }
        return $out;
    }

    /**
     * The member emails, saved values over the defaults (an empty subject or
     * message reads as the default text).
     *
     * @return array{fluentcart:array<string,bool>,join:array,renewal:array}
     */
    public static function member(): array {
        $defaults = self::member_defaults();
        $saved    = My_IAPSNJ_Plugin::settings()['member_mail'] ?? [];
        $saved    = is_array( $saved ) ? $saved : [];
        $out      = [ 'fluentcart' => array_merge( $defaults['fluentcart'], is_array( $saved['fluentcart'] ?? null ) ? $saved['fluentcart'] : [] ) ];
        foreach ( [ 'join', 'renewal' ] as $kind ) {
            $row = array_merge( $defaults[ $kind ], is_array( $saved[ $kind ] ?? null ) ? $saved[ $kind ] : [] );
            if ( trim( (string) $row['subject'] ) === '' ) {
                $row['subject'] = $defaults[ $kind ]['subject'];
            }
            if ( trim( (string) $row['body'] ) === '' ) {
                $row['body'] = $defaults[ $kind ]['body'];
            }
            $out[ $kind ] = $row;
        }
        return $out;
    }

    /**
     * Valid, distinct addresses of a "a@x.org, b@y.org" list.
     *
     * @return string[]
     */
    public static function emails( string $list ): array {
        $out = [];
        foreach ( (array) preg_split( '/[\s,;]+/', $list ) as $address ) {
            $address = sanitize_email( (string) $address );
            if ( $address !== '' && is_email( $address ) && ! in_array( strtolower( $address ), array_map( 'strtolower', $out ), true ) ) {
                $out[] = $address;
            }
        }
        return $out;
    }

    /**
     * Posted staff settings → the saved shape (a group or message that was not
     * posted keeps what it has).
     *
     * @param array<string,mixed> $raw
     * @return array<string,array>
     */
    public static function sanitize_staff( array $raw ): array {
        $out = [];
        foreach ( array_keys( self::staff_groups() ) as $group ) {
            $row = self::staff( $group );
            $in  = $raw[ $group ] ?? null;
            if ( is_array( $in ) ) {
                $row['enabled']    = ! empty( $in['enabled'] );
                $row['to']         = implode( ', ', self::emails( (string) ( $in['to'] ?? '' ) ) );
                $row['on_join']    = ! empty( $in['on_join'] );
                $row['on_renewal'] = ! empty( $in['on_renewal'] );
            }
            $out[ $group ] = $row;
        }
        $template = self::staff_template();
        foreach ( [ 'join', 'renewal' ] as $kind ) {
            $in = $raw['message'][ $kind ] ?? null;
            if ( is_array( $in ) ) {
                $template[ $kind ]['subject'] = trim( mb_substr( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ), 0, self::SUBJECT_MAX ) );
                $template[ $kind ]['body']    = trim( mb_substr( wp_kses_post( (string) ( $in['body'] ?? '' ) ), 0, self::BODY_MAX ) );
            }
        }
        $out['message'] = $template;
        return $out;
    }

    /**
     * Posted member-email settings → the saved shape.
     *
     * @param array<string,mixed> $raw
     * @return array<string,array>
     */
    public static function sanitize_member( array $raw ): array {
        $saved = self::member();
        $out   = [ 'fluentcart' => $saved['fluentcart'] ];
        if ( is_array( $raw['fluentcart'] ?? null ) ) {
            // The customer rows and the store-admin rows are on different forms:
            // only the ones posted change.
            foreach ( array_keys( self::fluentcart_emails() ) as $key ) {
                if ( array_key_exists( $key, $raw['fluentcart'] ) ) {
                    $out['fluentcart'][ $key ] = ! empty( $raw['fluentcart'][ $key ] );
                }
            }
        }
        foreach ( [ 'join', 'renewal' ] as $kind ) {
            $row = $saved[ $kind ];
            $in  = $raw[ $kind ] ?? null;
            if ( is_array( $in ) ) {
                $row['enabled'] = ! empty( $in['enabled'] );
                $row['subject'] = trim( mb_substr( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ), 0, self::SUBJECT_MAX ) );
                $row['body']    = trim( mb_substr( wp_kses_post( (string) ( $in['body'] ?? '' ) ), 0, self::BODY_MAX ) );
            }
            $out[ $kind ] = $row;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // FluentCart's emails (read, linked, switchable for membership orders)
    // -----------------------------------------------------------------------

    /**
     * FluentCart notifications a membership purchase sets off.
     *
     * to: 'member' | 'admin'; always: sent whenever the event happens (FluentCart
     * ignores its Active switch for Pay by Check).
     *
     * @return array<string,array{title:string,to:string,when:string,always:bool}>
     */
    public static function fluentcart_emails(): array {
        return [
            'order_paid_customer'          => [ 'title' => __( 'Purchase receipt', 'my-iapsnj' ), 'to' => 'member', 'when' => __( 'A membership payment is made: a join, a card renewal, a check marked paid.', 'my-iapsnj' ), 'always' => false ],
            'subscription_renewal_customer' => [ 'title' => __( 'Subscription renewal confirmation', 'my-iapsnj' ), 'to' => 'member', 'when' => __( 'A yearly subscription renews by card.', 'my-iapsnj' ), 'always' => false ],
            'order_placed_customer'        => [ 'title' => __( 'Pay by Check instructions', 'my-iapsnj' ), 'to' => 'member', 'when' => __( 'A member chooses Pay by Check (before any money arrives).', 'my-iapsnj' ), 'always' => true ],
            'order_paid_admin'             => [ 'title' => __( 'New order paid ("New Sales")', 'my-iapsnj' ), 'to' => 'admin', 'when' => __( 'A payment is made. Goes to the store admin address set in FluentCart.', 'my-iapsnj' ), 'always' => false ],
            'subscription_renewal_admin'   => [ 'title' => __( 'Subscription renewed', 'my-iapsnj' ), 'to' => 'admin', 'when' => __( 'A yearly subscription renews by card. Goes to the store admin address.', 'my-iapsnj' ), 'always' => false ],
            'order_placed_admin'           => [ 'title' => __( 'Pay by Check order placed', 'my-iapsnj' ), 'to' => 'admin', 'when' => __( 'A member chooses Pay by Check. Goes to the store admin address.', 'my-iapsnj' ), 'always' => true ],
        ];
    }

    public static function fluentcart_settings_url(): string {
        return admin_url( 'admin.php?page=fluent-cart#/settings/email_notifications' );
    }

    public static function fluentcrm_automations_url(): string {
        return admin_url( 'admin.php?page=fluentcrm-admin#/funnels' );
    }

    /**
     * What FluentCart has for one notification: whether it is on and its subject.
     *
     * @return array{known:bool,active:bool,subject:string}
     */
    public static function fluentcart_state( string $key ): array {
        $out   = [ 'known' => false, 'active' => false, 'subject' => '' ];
        $class = '\FluentCart\App\Services\Email\EmailNotifications';
        if ( ! class_exists( $class ) ) {
            return $out;
        }
        try {
            $all = $class::getNotifications();
            $n   = is_array( $all[ $key ] ?? null ) ? $all[ $key ] : null;
            if ( $n ) {
                $out['known']   = true;
                $out['active']  = ( (string) ( $n['settings']['active'] ?? '' ) ) === 'yes';
                $out['subject'] = (string) ( $n['settings']['subject'] ?? '' );
            }
        } catch ( \Throwable $e ) {
            // leave "unknown"
        }
        return $out;
    }

    /**
     * Does this FluentCart let a plugin stop one of its emails for one order
     * (the fluent_cart/should_send_email_notification filter)? Found by
     * reading the mailer's source, so it holds on any version, and cached.
     */
    public static function can_veto_fluentcart(): bool {
        static $memo = null;
        if ( $memo !== null ) {
            return $memo;
        }
        $cached = get_transient( 'my_iapsnj_fc_veto' );
        if ( $cached === 'yes' || $cached === 'no' ) {
            return $memo = ( $cached === 'yes' );
        }
        $found = false;
        try {
            $class = '\FluentCart\App\Services\Email\EmailNotificationMailer';
            if ( class_exists( $class ) ) {
                $file  = ( new \ReflectionClass( $class ) )->getFileName();
                $found = is_string( $file ) && is_readable( $file ) && strpos( (string) file_get_contents( $file ), 'fluent_cart/should_send_email_notification' ) !== false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            }
        } catch ( \Throwable $e ) {
            $found = false;
        }
        set_transient( 'my_iapsnj_fc_veto', $found ? 'yes' : 'no', 12 * HOUR_IN_SECONDS );
        return $memo = $found;
    }

    /**
     * fluent_cart/should_send_email_notification: a FluentCart email that is
     * switched off here is not sent for a membership order. Orders for other
     * products (events, merchandise) are never touched.
     *
     * @param mixed $send
     * @param mixed $context ['event','mail_name','order']
     * @return mixed
     */
    public static function filter_fluentcart_email( $send, $context = [] ) {
        if ( ! $send || ! is_array( $context ) ) {
            return $send;
        }
        $key = (string) ( $context['mail_name'] ?? '' );
        if ( ! isset( self::fluentcart_emails()[ $key ] ) || ! empty( self::member()['fluentcart'][ $key ] ) ) {
            return $send;
        }
        $order = $context['order'] ?? null;
        try {
            if ( is_object( $order ) && My_IAPSNJ_Membership::plan_for_order( $order ) ) {
                return false;
            }
        } catch ( \Throwable $e ) {
            return $send;
        }
        return $send;
    }

    /**
     * FluentCRM automations for the screen: published ones by title, and how
     * many there are in all. Empty without FluentCRM's model.
     *
     * @return array{titles:string[],total:int}
     */
    public static function automations(): array {
        $out   = [ 'titles' => [], 'total' => 0 ];
        $model = '\FluentCrm\App\Models\Funnel';
        if ( ! class_exists( $model ) ) {
            return $out;
        }
        try {
            $out['total'] = (int) $model::count();
            foreach ( $model::where( 'status', 'published' )->orderBy( 'title' )->limit( 15 )->get() as $funnel ) {
                $out['titles'][] = (string) $funnel->title;
            }
        } catch ( \Throwable $e ) {
            // the list is a courtesy
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // After a payment
    // -----------------------------------------------------------------------

    /**
     * The payment has been applied to the CRM: send the member's Welcome /
     * Renewal email and the staff notices that are switched on. Never throws
     * into the payment path.
     *
     * @param Subscriber $subscriber
     * @param object     $order      FluentCart Order
     * @param array      $applied    from apply_paid_order()
     */
    public static function after_payment( $subscriber, $order, array $applied ): void {
        try {
            $facts = self::facts( $subscriber, $order, $applied );
            self::send_staff( $order, $facts, $subscriber, $applied );
            self::send_member( $order, $facts );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: payment emails failed for order #' . (int) ( $order->id ?? 0 ) . ': ' . $e->getMessage() );
        }
    }

    /**
     * What an email may say about a payment, read once. 'answers' is the
     * application of the checkout form (My_IAPSNJ_Checkout_Fields::application_answers()).
     *
     * @return array<string,mixed>
     */
    private static function facts( $subscriber, $order, array $applied ): array {
        $subscriber = Subscriber::where( 'id', $subscriber->id )->first() ?: $subscriber;
        $custom     = $subscriber->custom_fields();
        $items      = array_map( function ( $i ) {
            return $i['title'] . ( $i['quantity'] > 1 ? ' ×' . $i['quantity'] : '' );
        }, (array) $applied['items'] );
        $address    = array_filter( [
            (string) $subscriber->address_line_1,
            (string) $subscriber->address_line_2,
            trim( implode( ' ', array_filter( [ (string) $subscriber->city . ( $subscriber->city ? ',' : '' ), (string) $subscriber->state, (string) $subscriber->postal_code ] ) ) ),
            (string) $subscriber->country,
        ] );
        $money      = My_IAPSNJ_Membership::format_money( (int) $order->total_amount, (string) $order->currency );
        $kind       = (string) ( $applied['kind'] ?? 'join' ) === 'renewal' ? 'renewal' : 'join';
        return [
            'kind'          => $kind,
            'kind_label'    => self::kind_label( $kind ),
            'answers'       => My_IAPSNJ_Checkout_Fields::application_answers( $order, $subscriber, (string) $applied['member_type'] ),
            'order_id'      => (int) $order->id,
            'first_name'    => (string) $subscriber->first_name,
            'last_name'     => (string) $subscriber->last_name,
            'name'          => trim( (string) $subscriber->first_name . ' ' . (string) $subscriber->last_name ),
            'email'         => (string) $subscriber->email,
            'phone'         => My_IAPSNJ_Phone::display( (string) $subscriber->phone ),
            'address'       => implode( "\n", $address ),
            'member_type'   => (string) $applied['member_type'],
            'paid_through'  => (string) $applied['paid_through'],
            'product'       => implode( '; ', $items ),
            'payment'       => ( ( $applied['payment'] ?? '' ) === 'check' ? __( 'Check', 'my-iapsnj' ) : __( 'Card', 'my-iapsnj' ) ) . ' — ' . $money,
            'amount'        => $money,
            'order'         => '#' . (int) $order->id . ( $order->receipt_number ? ' (' . $order->receipt_number . ')' : '' ),
            'department'    => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_DEPARTMENT ] ?? '' ),
            'rank'          => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_RANK ] ?? '' ),
            'member_number' => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] ?? '' ),
            'crm_url'       => admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $subscriber->id ),
            'order_url'     => My_IAPSNJ_Membership::order_admin_url( $order ),
        ];
    }

    /**
     * A made-up payment for Preview and Send test.
     *
     * @return array<string,mixed>
     */
    public static function sample_facts( string $kind ): array {
        $kind = $kind === 'renewal' ? 'renewal' : 'join';
        return [
            'kind'          => $kind,
            'kind_label'    => self::kind_label( $kind ),
            'answers'       => My_IAPSNJ_Checkout_Fields::sample_application_answers( My_IAPSNJ_Schema::TYPE_REGULAR ),
            'order_id'      => 1234,
            'first_name'    => 'Sample',
            'last_name'     => 'Member',
            'name'          => 'Sample Member',
            'email'         => 'sample.member@example.com',
            'phone'         => '+1 908-555-0100',
            'address'       => "12 Main St\nMt Laurel, New Jersey 08054\nUS",
            'member_type'   => My_IAPSNJ_Schema::TYPE_REGULAR,
            'paid_through'  => ( (int) wp_date( 'Y' ) + 1 ) . '-12-31',
            'product'       => 'Regular Membership',
            'payment'       => __( 'Card', 'my-iapsnj' ) . ' — $30.00',
            'amount'        => '$30.00',
            'order'         => '#1234',
            'department'    => 'Sample Township PD',
            'rank'          => 'Sergeant',
            'member_number' => '1234',
            'crm_url'       => admin_url( 'admin.php?page=fluentcrm-admin' ),
            'order_url'     => admin_url( 'admin.php?page=fluent-cart' ),
        ];
    }

    // -----------------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------------

    /**
     * @param object     $order
     * @param Subscriber $subscriber
     */
    private static function send_staff( $order, array $facts, $subscriber, array $applied ): void {
        $log     = self::staff_log( $order );
        $changed = false;
        foreach ( array_keys( self::staff_groups() ) as $group ) {
            $cfg = self::staff( $group );
            if ( empty( $cfg['enabled'] ) || isset( $log[ $group ] ) ) {
                continue;
            }
            if ( $facts['kind'] === 'join' ? empty( $cfg['on_join'] ) : empty( $cfg['on_renewal'] ) ) {
                continue;
            }
            $to = self::emails( (string) $cfg['to'] );
            if ( ! $to ) {
                continue;
            }
            $mail = self::staff_message( $facts, $to );

            if ( $facts['kind'] === 'join' ) {
                /**
                 * Filter a staff notice about a NEW member before it is sent
                 * (kept from ≤ 4.35, when there was one notice).
                 *
                 * @param array $mail ['to' => string[], 'subject' => string, 'body' => string (HTML), 'headers' => string[]]
                 */
                $mail = apply_filters( 'my_iapsnj/new_member_notification', $mail, $subscriber, $order, $applied );
            }
            /**
             * Filter any staff notice (join or renewal) before it is sent.
             *
             * @param array  $mail       as above
             * @param string $group      president | committee
             * @param array  $facts      what the notice says
             * @param object $order      FluentCart Order
             */
            $mail = apply_filters( 'my_iapsnj/staff_notification', $mail, $group, $facts, $order );

            if ( ! empty( $mail['to'] ) ) {
                wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] );
            }
            $log[ $group ] = My_IAPSNJ_Dates::now_utc();
            $changed       = true;
        }
        if ( $changed ) {
            $order->updateMeta( self::META_STAFF, $log );
        }
    }

    /**
     * Which staff groups already have their notice for this order.
     *
     * @return array<string,string>
     */
    private static function staff_log( $order ): array {
        $log = My_IAPSNJ_Membership::meta_array( $order, self::META_STAFF ) ?: [];
        if ( ! isset( $log['committee'] ) && $order->getMeta( self::META_LEGACY ) ) {
            $log['committee'] = (string) $order->getMeta( self::META_LEGACY );
        }
        return $log;
    }

    /**
     * A staff notice ready to send: the staff message for a new member or a
     * renewal, under its New member / Renewal label. The President and the
     * Committee get the same email; only the recipients differ.
     *
     * @param string[]            $to
     * @param array<string,mixed> $facts
     * @param array<string,string> $draft unsaved subject / body to use instead of the saved ones (Preview, Send test)
     * @return array{to:string[],subject:string,body:string,headers:string[]}
     */
    public static function staff_message( array $facts, array $to, array $draft = [] ): array {
        $kind    = $facts['kind'] === 'renewal' ? 'renewal' : 'join';
        $cfg     = array_merge( self::staff_template()[ $kind ], $draft );
        $values  = self::token_values( $facts );
        $subject = self::subject_text( (string) $cfg['subject'], $values, self::staff_message_defaults()[ $kind ]['subject'] );
        // The application is always in a staff notice: put at the end when the message has no {application}.
        $content = self::kind_badge( $kind ) . self::message_html( (string) $cfg['body'], $values, $facts, true, true );
        return [
            'to'      => array_values( $to ),
            'subject' => $subject,
            'body'    => self::html_email( $content, $subject ),
            'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
        ];
    }

    public static function kind_label( string $kind ): string {
        return $kind === 'renewal' ? __( 'Renewal', 'my-iapsnj' ) : __( 'New member', 'my-iapsnj' );
    }

    /** The coloured label on top of a staff notice: New member (green) or Renewal (blue). */
    private static function kind_badge( string $kind ): string {
        return '<p style="margin:0 0 16px;"><span style="display:inline-block;padding:4px 14px;border-radius:999px;background:' . ( $kind === 'renewal' ? '#0b5cad' : '#1a7f37' ) . ';color:#ffffff;font-size:12px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">'
            . esc_html( self::kind_label( $kind ) ) . '</span></p>';
    }

    /**
     * The {application} table: what a person typed on the application and what
     * we hold on them, in sections. Staff also get the payment, the order and
     * the CRM / order links; a member's copy never carries those.
     *
     * @param array<string,mixed> $facts
     * @return array<int,array<string,string>> items: ['heading' => …] or ['label' => …, 'value' => …]
     */
    public static function application_rows( array $facts, bool $staff ): array {
        $paid_through = (string) $facts['paid_through'] !== '' ? My_IAPSNJ_Dates::ymd_display( (string) $facts['paid_through'] ) : __( 'n/a (no expiration)', 'my-iapsnj' );
        $rows         = [
            [ 'heading' => __( 'Membership', 'my-iapsnj' ) ],
            [ 'label' => __( 'Application type', 'my-iapsnj' ), 'value' => (string) $facts['kind_label'] ],
            [ 'label' => __( 'Member type', 'my-iapsnj' ), 'value' => (string) $facts['member_type'] ],
            [ 'label' => __( 'Paid through', 'my-iapsnj' ), 'value' => $paid_through ],
        ];
        if ( (string) $facts['member_number'] !== '' ) {
            $rows[] = [ 'label' => __( 'Member number', 'my-iapsnj' ), 'value' => (string) $facts['member_number'] ];
        }
        if ( $staff ) {
            $rows[] = [ 'label' => __( 'Product', 'my-iapsnj' ), 'value' => (string) $facts['product'] ];
            $rows[] = [ 'label' => __( 'Payment', 'my-iapsnj' ), 'value' => (string) $facts['payment'] ];
            $rows[] = [ 'label' => __( 'Order', 'my-iapsnj' ), 'value' => (string) $facts['order'] ];
        }
        $rows[] = [ 'heading' => __( 'Contact', 'my-iapsnj' ) ];
        $rows[] = [ 'label' => __( 'Name', 'my-iapsnj' ), 'value' => (string) $facts['name'] ];
        $rows[] = [ 'label' => __( 'Email', 'my-iapsnj' ), 'value' => (string) $facts['email'] ];
        $rows[] = [ 'label' => __( 'Cell phone', 'my-iapsnj' ), 'value' => (string) $facts['phone'] ];
        $rows[] = [ 'label' => __( 'Mailing address', 'my-iapsnj' ), 'value' => (string) $facts['address'] ];
        $answers = array_values( array_filter( (array) ( $facts['answers'] ?? [] ), 'is_array' ) );
        if ( $answers && ! isset( $answers[0]['heading'] ) ) {
            // A form with no section headings: its answers get one of their own, apart from the contact rows.
            $rows[] = [ 'heading' => __( 'Application', 'my-iapsnj' ) ];
        }
        $rows = array_merge( $rows, $answers );
        if ( $staff ) {
            $rows[] = [ 'heading' => __( 'Links', 'my-iapsnj' ) ];
            $rows[] = [ 'label' => __( 'CRM contact', 'my-iapsnj' ), 'value' => (string) $facts['crm_url'] ];
            $rows[] = [ 'label' => __( 'FluentCart order', 'my-iapsnj' ), 'value' => (string) $facts['order_url'] ];
        }
        return $rows;
    }

    /**
     * The rows as an email table: a heading bar per section, label / value
     * rows (an unanswered row reads —, a bare http(s) address becomes an
     * "Open" link, line breaks are kept). Inline styles only, as email programs
     * need; every value is escaped.
     *
     * @param array<int,array<string,string>> $rows from application_rows()
     */
    public static function application_html( array $rows ): string {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 16px;">';
        foreach ( $rows as $row ) {
            if ( isset( $row['heading'] ) ) {
                $html .= '<tr><td colspan="2" style="padding:16px 0 6px;border-bottom:2px solid #0c3d54;font-size:12px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#0c3d54;">' . esc_html( (string) $row['heading'] ) . '</td></tr>';
                continue;
            }
            $value = (string) ( $row['value'] ?? '' );
            if ( $value === '' ) {
                $cell = '—';
            } elseif ( preg_match( '#^https?://\S+$#', $value ) ) {
                $cell = '<a href="' . esc_url( $value ) . '">' . esc_html__( 'Open', 'my-iapsnj' ) . '</a>';
            } else {
                $cell = nl2br( esc_html( $value ) );
            }
            $html .= '<tr><td width="38%" style="padding:6px 12px 6px 0;border-bottom:1px solid #eeeeee;vertical-align:top;font-weight:600;">' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #eeeeee;vertical-align:top;">' . $cell . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * A message (the editor's HTML, or plain text from before the editor) as
     * email HTML: its {tokens} filled in and its {application} replaced by the
     * application table. The message is cleaned (wp_kses_post) before anything
     * is put into it; the table and the values are escaped as they are built.
     *
     * @param array<string,string> $values token values (token_values())
     * @param array<string,mixed>  $facts
     * @param bool                 $staff  the table carries the payment and the links
     * @param bool                 $always put the table at the end when the message has no {application}
     */
    private static function message_html( string $raw, array $values, array $facts, bool $staff, bool $always ): string {
        $raw  = trim( $raw );
        $html = $raw !== wp_strip_all_tags( $raw ) ? wp_kses_post( $raw ) : My_IAPSNJ_Emails::text_to_html( $raw );
        $html = self::fill( $html, $values, true );
        $rows = null;
        $block = static function () use ( &$rows, $facts, $staff ): string {
            $rows = $rows ?? My_IAPSNJ_Notifications::application_html( My_IAPSNJ_Notifications::application_rows( $facts, $staff ) );
            return $rows;
        };
        if ( preg_match( '#<p[^>]*>\s*\{application\}\s*</p>#i', $html ) ) {
            // A table cannot sit inside a paragraph: the editor wraps a line of its own in one.
            $html = (string) preg_replace_callback( '#<p[^>]*>\s*\{application\}\s*</p>#i', $block, $html );
        } elseif ( strpos( $html, '{application}' ) !== false ) {
            $html = str_replace( '{application}', $block(), $html );
        } elseif ( $always ) {
            $html .= $block();
        }
        return $html;
    }

    /**
     * A subject: its {tokens} filled in, tags and line breaks removed
     * ({application} makes no sense here and is dropped); $fallback when
     * nothing is left.
     *
     * @param array<string,string> $values
     */
    private static function subject_text( string $template, array $values, string $fallback ): string {
        $text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::fill( str_replace( '{application}', '', $template ), $values, false ) ) ) );
        return $text !== '' ? $text : trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::fill( $fallback, $values, false ) ) ) );
    }

    /**
     * The finished HTML email: in the FluentCRM design with the logo and
     * footer when Settings → Emails → Email design is on, else the message
     * alone in a plain container. Either way it is HTML: the messages are
     * written in the editor.
     */
    private static function html_email( string $content, string $preheader ): string {
        if ( My_IAPSNJ_Emails::enabled() ) {
            return My_IAPSNJ_Emails::wrap( $content, $preheader );
        }
        return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#202020;max-width:700px;">' . $content . '</div>';
    }

    // -----------------------------------------------------------------------
    // The member's own Welcome / Renewal email
    // -----------------------------------------------------------------------

    /**
     * The tokens a subject or message may use: token => what it is.
     *
     * @return array<string,string>
     */
    public static function placeholders(): array {
        return [
            'first_name'    => __( 'First name', 'my-iapsnj' ),
            'last_name'     => __( 'Last name', 'my-iapsnj' ),
            'full_name'     => __( 'Full name', 'my-iapsnj' ),
            'email'         => __( 'Email address', 'my-iapsnj' ),
            'phone'         => __( 'Cell phone', 'my-iapsnj' ),
            'member_type'   => __( 'Regular, Associate, Lifetime …', 'my-iapsnj' ),
            'kind'          => __( 'New member or Renewal', 'my-iapsnj' ),
            'paid_through'  => __( 'The date the membership runs through (or "no expiration")', 'my-iapsnj' ),
            'member_number' => __( 'Member number', 'my-iapsnj' ),
            'order_number'  => __( 'Order number', 'my-iapsnj' ),
            'amount'        => __( 'Amount paid', 'my-iapsnj' ),
            'organization'  => __( 'The business name set in FluentCRM → Settings → Business Settings', 'my-iapsnj' ),
            'application'   => __( 'The whole application as a styled table: membership, contact details and every answer on the application form (in a staff email also the payment, order and CRM links)', 'my-iapsnj' ),
        ];
    }

    /**
     * Token => value for one payment ({application} is built apart: message_html()).
     *
     * @param array<string,mixed> $facts
     * @return array<string,string>
     */
    private static function token_values( array $facts ): array {
        return [
            'first_name'    => (string) $facts['first_name'],
            'last_name'     => (string) $facts['last_name'],
            'full_name'     => (string) $facts['name'],
            'email'         => (string) $facts['email'],
            'phone'         => (string) $facts['phone'] !== '' ? (string) $facts['phone'] : '—',
            'member_type'   => (string) $facts['member_type'],
            'kind'          => (string) $facts['kind_label'],
            'paid_through'  => (string) $facts['paid_through'] !== '' ? My_IAPSNJ_Dates::ymd_display( (string) $facts['paid_through'] ) : __( 'no expiration', 'my-iapsnj' ),
            'member_number' => (string) $facts['member_number'] !== '' ? (string) $facts['member_number'] : '—',
            'order_number'  => (string) $facts['order'],
            'amount'        => (string) $facts['amount'],
            'organization'  => My_IAPSNJ_Emails::brand_name(),
        ];
    }

    /**
     * $text with its {tokens} filled in; values are escaped when the text is
     * HTML, left as they are otherwise.
     *
     * @param array<string,string> $values
     */
    private static function fill( string $text, array $values, bool $html ): string {
        $map = [];
        foreach ( $values as $token => $value ) {
            $map[ '{' . $token . '}' ] = $html ? esc_html( $value ) : $value;
        }
        return strtr( $text, $map );
    }

    /**
     * The member's email for a payment, ready to send (HTML: the message is
     * written in the editor). {application} here is the member's own copy:
     * no payment, order or CRM rows.
     *
     * @param array<string,mixed>  $facts
     * @param array<string,string> $draft unsaved subject / body to use instead of the saved ones (Preview, Send test)
     * @return array{to:string,subject:string,body:string,headers:string[]}
     */
    public static function member_message( string $kind, array $facts, array $draft = [] ): array {
        $kind    = $kind === 'renewal' ? 'renewal' : 'join';
        $cfg     = array_merge( self::member()[ $kind ], $draft );
        $values  = self::token_values( $facts );
        $subject = self::subject_text( (string) $cfg['subject'], $values, self::member_defaults()[ $kind ]['subject'] );
        $content = self::message_html( (string) $cfg['body'], $values, $facts, false, false );
        return [
            'to'      => (string) $facts['email'],
            'subject' => $subject,
            'body'    => self::html_email( $content, $subject ),
            'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
        ];
    }

    /**
     * @param object              $order
     * @param array<string,mixed> $facts
     */
    private static function send_member( $order, array $facts ): void {
        $kind = (string) $facts['kind'];
        $cfg  = self::member()[ $kind ];
        if ( empty( $cfg['enabled'] ) || $order->getMeta( self::META_MEMBER ) ) {
            return;
        }
        $mail = self::member_message( $kind, $facts );
        if ( ! is_email( $mail['to'] ) ) {
            return;
        }
        /**
         * Filter the member's Welcome / Renewal email before it is sent.
         *
         * @param array  $mail  ['to' => string, 'subject' => string, 'body' => string, 'headers' => string[]]
         * @param string $kind  join | renewal
         * @param array  $facts what the email says
         * @param object $order FluentCart Order
         */
        $mail = apply_filters( 'my_iapsnj/member_notification', $mail, $kind, $facts, $order );
        if ( ! empty( $mail['to'] ) ) {
            wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] );
        }
        $order->updateMeta( self::META_MEMBER, My_IAPSNJ_Dates::now_utc() );
    }

    // -----------------------------------------------------------------------
    // Preview / test (Settings → Emails)
    // -----------------------------------------------------------------------

    /**
     * WordPress's own editor (Visual and Text tabs) for an email message: HTML,
     * no media button, a short toolbar. The editor keeps the paragraph tags
     * (wpautop off) so what is saved is the HTML that is sent; a message saved
     * as plain text (≤ 4.36) is shown as paragraphs.
     */
    public static function editor( string $id, string $name, string $content, int $rows = 9 ): void {
        wp_editor( wpautop( $content ), $id, [
            'textarea_name' => $name,
            'textarea_rows' => $rows,
            'media_buttons' => false,
            'teeny'         => true,
            'wpautop'       => false,
            'quicktags'     => [ 'buttons' => 'strong,em,link,ul,ol,li' ],
            'tinymce'       => [
                'toolbar1' => 'bold,italic,underline,|,bullist,numlist,|,link,unlink,|,undo,redo',
                'toolbar2' => '',
            ],
        ] );
    }

    /**
     * kind: member:join | member:renewal | staff:join | staff:renewal
     *
     * @param array<string,string> $draft unsaved subject / body from the screen (see sanitize_draft())
     * @return array{to:string|string[],subject:string,body:string,headers:string[]}|null
     */
    public static function sample_message( string $kind, array $draft = [] ): ?array {
        $parts = explode( ':', $kind );
        $last  = (string) end( $parts );
        if ( ! in_array( $last, [ 'join', 'renewal' ], true ) ) {
            return null;
        }
        if ( $parts[0] === 'member' ) {
            return self::member_message( $last, self::sample_facts( $last ), $draft );
        }
        if ( $parts[0] === 'staff' ) {
            return self::staff_message( self::sample_facts( $last ), [ 'sample@example.com' ], $draft );
        }
        return null;
    }

    /**
     * A subject and message typed on Settings → Emails but not saved yet,
     * cleaned the way a save cleans them, so Preview and Send test show what is
     * on the screen. A part that is empty or missing is left out (the saved
     * text is used).
     *
     * @param array<string,mixed> $raw unslashed 'subject' / 'body'
     * @return array<string,string>
     */
    public static function sanitize_draft( array $raw ): array {
        $out = [];
        if ( isset( $raw['subject'] ) && is_scalar( $raw['subject'] ) ) {
            $subject = trim( mb_substr( sanitize_text_field( (string) $raw['subject'] ), 0, self::SUBJECT_MAX ) );
            if ( $subject !== '' ) {
                $out['subject'] = $subject;
            }
        }
        if ( isset( $raw['body'] ) && is_scalar( $raw['body'] ) ) {
            $body = trim( mb_substr( wp_kses_post( (string) $raw['body'] ), 0, self::BODY_MAX ) );
            if ( $body !== '' ) {
                $out['body'] = $body;
            }
        }
        return $out;
    }

    /**
     * Where this administrator's tests go: the addresses typed on Settings →
     * Emails (remembered), else their own address. At most five.
     *
     * @return string[] empty when there is nothing valid
     */
    public static function test_recipients( ?string $typed = null ): array {
        $user = wp_get_current_user();
        if ( $typed === null ) {
            $typed = (string) get_user_meta( $user->ID, self::META_TEST_TO, true );
        }
        $list = self::emails( $typed );
        if ( $list ) {
            return array_slice( $list, 0, 5 );
        }
        return is_email( (string) $user->user_email ) ? [ (string) $user->user_email ] : [];
    }

    /**
     * The addresses typed in the test box, remembered for this administrator
     * (blank forgets them: tests go to their own address again).
     *
     * @return string[]|null the addresses to use, or null when the text holds none that is valid
     */
    public static function remember_test_recipients( string $typed ): ?array {
        $typed = trim( $typed );
        $user  = wp_get_current_user();
        if ( $typed === '' ) {
            delete_user_meta( $user->ID, self::META_TEST_TO );
            return self::test_recipients( '' );
        }
        $list = self::emails( $typed );
        if ( ! $list ) {
            return null;
        }
        $list = array_slice( $list, 0, 5 );
        update_user_meta( $user->ID, self::META_TEST_TO, implode( ', ', $list ) );
        return $list;
    }

    /**
     * Send the sample for $kind to $to (never to the real recipients).
     *
     * @param string|string[]      $to
     * @param array<string,string> $draft unsaved subject / body from the screen
     */
    public static function send_test( string $kind, $to, array $draft = [] ): bool {
        $mail = self::sample_message( $kind, $draft );
        $to   = array_values( array_filter( (array) $to, 'is_email' ) );
        if ( ! $mail || ! $to ) {
            return false;
        }
        return (bool) wp_mail( $to, '[' . __( 'TEST', 'my-iapsnj' ) . '] ' . $mail['subject'], $mail['body'], $mail['headers'] );
    }

    public static function preview_url( string $kind ): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::PREVIEW_ACTION . '&kind=' . rawurlencode( $kind ) ), self::PREVIEW_ACTION );
    }

    /**
     * admin-post.php?action=my_iapsnj_notification_preview&kind=… — the sample
     * as the recipient sees it (GET: as saved; POST with subject / body: as on
     * the screen).
     */
    public static function render_preview(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'my-iapsnj' ), 403 );
        }
        check_admin_referer( self::PREVIEW_ACTION );
        // The link opens the saved email; the Preview button of Settings → Emails posts what is on the screen too.
        // phpcs:disable WordPress.Security.NonceVerification -- verified above
        $draft = isset( $_POST['subject'] ) || isset( $_POST['body'] ) ? self::sanitize_draft( wp_unslash( [ 'subject' => $_POST['subject'] ?? '', 'body' => $_POST['body'] ?? '' ] ) ) : [];
        $mail  = self::sample_message( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['kind'] ?? '' ) ) ), $draft );
        // phpcs:enable
        if ( ! $mail ) {
            wp_die( esc_html__( 'Unknown email.', 'my-iapsnj' ), 404 );
        }
        nocache_headers();
        if ( stripos( implode( "\n", $mail['headers'] ), 'text/html' ) !== false ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            echo $mail['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by wrap(), every value escaped
        } else {
            header( 'Content-Type: text/plain; charset=UTF-8' );
            echo esc_html( $mail['subject'] ) . "\n\n" . esc_html( $mail['body'] );
        }
        exit;
    }
}
