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
 *     recipients, "send on join / on renewal" and a Summary or Full layout.
 *     The single recipient list of ≤ 4.35 (Configurations → New-member
 *     notification) became the Committee's (data v19).
 *
 * Everything is sent once per order and only after the payment is applied to
 * the CRM (My_IAPSNJ_Membership::apply_paid_order), never for an unpaid
 * application. Settings live in my_iapsnj_settings: staff_mail, member_mail.
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
     * @return array<string,array{enabled:bool,to:string,on_join:bool,on_renewal:bool,detail:string}>
     */
    public static function staff_defaults(): array {
        return [
            'president' => [ 'enabled' => false, 'to' => '', 'on_join' => true, 'on_renewal' => true, 'detail' => 'summary' ],
            'committee' => [ 'enabled' => true, 'to' => (string) get_option( 'admin_email' ), 'on_join' => true, 'on_renewal' => false, 'detail' => 'full' ],
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
     * @return array{enabled:bool,to:string,on_join:bool,on_renewal:bool,detail:string}
     */
    public static function staff( string $group ): array {
        $defaults = self::staff_defaults()[ $group ] ?? [ 'enabled' => false, 'to' => '', 'on_join' => true, 'on_renewal' => false, 'detail' => 'full' ];
        $saved    = My_IAPSNJ_Plugin::settings()['staff_mail'][ $group ] ?? [];
        $out      = array_merge( $defaults, is_array( $saved ) ? $saved : [] );
        $out['detail'] = $out['detail'] === 'summary' ? 'summary' : 'full';
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
     * Posted staff settings → the saved shape (a group that was not posted keeps
     * what it has).
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
                $row['detail']     = ( $in['detail'] ?? '' ) === 'summary' ? 'summary' : 'full';
            }
            $out[ $group ] = $row;
        }
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
     * What an email may say about a payment, read once.
     *
     * @return array<string,string|int>
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
        return [
            'kind'          => (string) ( $applied['kind'] ?? 'join' ) === 'renewal' ? 'renewal' : 'join',
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
     * @return array<string,string|int>
     */
    public static function sample_facts( string $kind ): array {
        return [
            'kind'          => $kind === 'renewal' ? 'renewal' : 'join',
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
            $mail = self::staff_message( $group, $facts, $to );

            if ( $facts['kind'] === 'join' ) {
                /**
                 * Filter a staff notice about a NEW member before it is sent
                 * (kept from ≤ 4.35, when there was one notice).
                 *
                 * @param array $mail ['to' => string[], 'subject' => string, 'body' => string (HTML when the email design is on, else plain text), 'headers' => string[]]
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
     * The rows a staff notice lists: Summary (who, what, until when, the CRM
     * link) or Full (adds email, phone, address, payment and order links).
     *
     * @param array<string,string|int> $facts
     * @return array<string,string>
     */
    public static function staff_rows( array $facts, string $detail ): array {
        $rows = [
            __( 'Name', 'my-iapsnj' )          => (string) $facts['name'],
            __( 'Member type', 'my-iapsnj' )   => (string) $facts['member_type'],
            __( 'Paid through', 'my-iapsnj' )  => (string) $facts['paid_through'] !== '' ? My_IAPSNJ_Dates::ymd_display( (string) $facts['paid_through'] ) : __( 'n/a (no expiration)', 'my-iapsnj' ),
        ];
        if ( $detail === 'full' ) {
            $rows += [
                __( 'Product', 'my-iapsnj' )         => (string) $facts['product'],
                __( 'Payment', 'my-iapsnj' )         => (string) $facts['payment'],
                __( 'Order', 'my-iapsnj' )           => (string) $facts['order'],
                __( 'Email', 'my-iapsnj' )           => (string) $facts['email'],
                __( 'Cell phone', 'my-iapsnj' )      => (string) $facts['phone'],
                __( 'Mailing address', 'my-iapsnj' ) => (string) $facts['address'],
            ];
        }
        $rows += [
            __( 'Department', 'my-iapsnj' )    => (string) $facts['department'],
            __( 'Rank', 'my-iapsnj' )          => (string) $facts['rank'],
            __( 'Member number', 'my-iapsnj' ) => (string) $facts['member_number'],
            __( 'CRM contact', 'my-iapsnj' )   => (string) $facts['crm_url'],
        ];
        if ( $detail === 'full' ) {
            $rows[ __( 'FluentCart order', 'my-iapsnj' ) ] = (string) $facts['order_url'];
        }
        return $rows;
    }

    /**
     * A staff notice ready to send.
     *
     * @param string[]                 $to
     * @param array<string,string|int> $facts
     * @return array{to:string[],subject:string,body:string,headers:string[]}
     */
    public static function staff_message( string $group, array $facts, array $to ): array {
        $cfg    = self::staff( $group );
        $rows   = self::staff_rows( $facts, (string) $cfg['detail'] );
        $name   = (string) $facts['name'] !== '' ? (string) $facts['name'] : (string) $facts['email'];
        $join   = $facts['kind'] === 'join';
        $intro  = $join
            /* translators: %d: order number */
            ? sprintf( __( 'A new IAPSNJ member has PAID (order #%d). Details below are from the CRM after payment.', 'my-iapsnj' ), (int) $facts['order_id'] )
            /* translators: %d: order number */
            : sprintf( __( 'An IAPSNJ member has RENEWED (order #%d). Details below are from the CRM after payment.', 'my-iapsnj' ), (int) $facts['order_id'] );
        $note   = __( 'This notification is sent only after payment is confirmed; it never fires for an unpaid application.', 'my-iapsnj' );
        $subject = $join
            ? sprintf( __( '[IAPSNJ] New member paid: %1$s (%2$s)', 'my-iapsnj' ), $name, (string) $facts['member_type'] )
            : sprintf( __( '[IAPSNJ] Membership renewed: %1$s (%2$s)', 'my-iapsnj' ), $name, (string) $facts['member_type'] );

        if ( My_IAPSNJ_Emails::enabled() ) {
            // Same design as every other site email (Settings → Emails → Email design).
            $body    = My_IAPSNJ_Emails::wrap( self::notification_html( $intro, $rows, $note ), $intro );
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        } else {
            $body = $intro . "\n\n";
            foreach ( $rows as $label => $value ) {
                if ( strpos( $value, "\n" ) !== false ) {
                    $body .= $label . ":\n    " . str_replace( "\n", "\n    ", $value ) . "\n";
                } else {
                    $body .= $label . ': ' . ( $value !== '' ? $value : '—' ) . "\n";
                }
            }
            $body   .= "\n" . $note . "\n";
            $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
        }
        return [
            'to'      => array_values( $to ),
            'subject' => $subject,
            'body'    => $body,
            'headers' => $headers,
        ];
    }

    /**
     * Intro, a label / value table (admin URLs as links, line breaks kept),
     * closing note.
     *
     * @param array<string,string> $rows
     */
    private static function notification_html( string $intro, array $rows, string $note ): string {
        $html = '<p>' . esc_html( $intro ) . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 16px;">';
        foreach ( $rows as $label => $value ) {
            $value = (string) $value;
            if ( $value === '' ) {
                $cell = '—';
            } elseif ( preg_match( '#^https?://\S+$#', $value ) ) {
                $cell = '<a href="' . esc_url( $value ) . '">' . esc_html__( 'Open', 'my-iapsnj' ) . '</a>';
            } else {
                $cell = nl2br( esc_html( $value ) );
            }
            $html .= '<tr><td style="padding:6px 12px 6px 0;border-bottom:1px solid #eeeeee;vertical-align:top;white-space:nowrap;font-weight:600;">' . esc_html( (string) $label ) . '</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #eeeeee;vertical-align:top;">' . $cell . '</td></tr>';
        }
        return $html . '</table><p style="font-size:13px;color:#666666;">' . esc_html( $note ) . '</p>';
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
            'member_type'   => __( 'Regular, Associate, Lifetime …', 'my-iapsnj' ),
            'paid_through'  => __( 'The date the membership runs through (or "no expiration")', 'my-iapsnj' ),
            'member_number' => __( 'Member number', 'my-iapsnj' ),
            'order_number'  => __( 'Order number', 'my-iapsnj' ),
            'amount'        => __( 'Amount paid', 'my-iapsnj' ),
            'organization'  => __( 'The business name set in FluentCRM → Settings → Business Settings', 'my-iapsnj' ),
        ];
    }

    /**
     * Token => value for one payment.
     *
     * @param array<string,string|int> $facts
     * @return array<string,string>
     */
    private static function token_values( array $facts ): array {
        return [
            'first_name'    => (string) $facts['first_name'],
            'last_name'     => (string) $facts['last_name'],
            'full_name'     => (string) $facts['name'],
            'email'         => (string) $facts['email'],
            'member_type'   => (string) $facts['member_type'],
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
     * The member's email for a payment, ready to send.
     *
     * @param array<string,string|int> $facts
     * @return array{to:string,subject:string,body:string,headers:string[]}
     */
    public static function member_message( string $kind, array $facts ): array {
        $cfg     = self::member()[ $kind === 'renewal' ? 'renewal' : 'join' ];
        $values  = self::token_values( $facts );
        $subject = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::fill( (string) $cfg['subject'], $values, false ) ) ) );
        $raw     = trim( (string) $cfg['body'] );
        $is_html = $raw !== wp_strip_all_tags( $raw );
        $content = $is_html
            ? wp_kses_post( self::fill( $raw, $values, true ) )
            : My_IAPSNJ_Emails::text_to_html( self::fill( $raw, $values, false ) );

        if ( My_IAPSNJ_Emails::enabled() ) {
            $body    = My_IAPSNJ_Emails::wrap( $content, $subject );
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        } else {
            $body    = trim( wp_strip_all_tags( $is_html ? $content : self::fill( $raw, $values, false ) ) );
            $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
        }
        return [
            'to'      => (string) $facts['email'],
            'subject' => $subject,
            'body'    => $body,
            'headers' => $headers,
        ];
    }

    /**
     * @param object                   $order
     * @param array<string,string|int> $facts
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
     * kind: member:join | member:renewal | staff:president:join |
     * staff:president:renewal | staff:committee:join | staff:committee:renewal
     *
     * @return array{to:string|string[],subject:string,body:string,headers:string[]}|null
     */
    public static function sample_message( string $kind ): ?array {
        $parts = explode( ':', $kind );
        if ( $parts[0] === 'member' && in_array( $parts[1] ?? '', [ 'join', 'renewal' ], true ) ) {
            return self::member_message( $parts[1], self::sample_facts( $parts[1] ) );
        }
        if ( $parts[0] === 'staff' && isset( self::staff_groups()[ $parts[1] ?? '' ] ) && in_array( $parts[2] ?? '', [ 'join', 'renewal' ], true ) ) {
            return self::staff_message( $parts[1], self::sample_facts( $parts[2] ), [ 'sample@example.com' ] );
        }
        return null;
    }

    /**
     * Send the sample for $kind to $to (never to the real recipients).
     */
    public static function send_test( string $kind, string $to ): bool {
        $mail = self::sample_message( $kind );
        if ( ! $mail || ! is_email( $to ) ) {
            return false;
        }
        return (bool) wp_mail( $to, '[' . __( 'TEST', 'my-iapsnj' ) . '] ' . $mail['subject'], $mail['body'], $mail['headers'] );
    }

    public static function preview_url( string $kind ): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::PREVIEW_ACTION . '&kind=' . rawurlencode( $kind ) ), self::PREVIEW_ACTION );
    }

    /**
     * admin-post.php?action=my_iapsnj_notification_preview&kind=… — the sample
     * as the recipient sees it.
     */
    public static function render_preview(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'my-iapsnj' ), 403 );
        }
        check_admin_referer( self::PREVIEW_ACTION );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
        $mail = self::sample_message( sanitize_text_field( wp_unslash( (string) ( $_GET['kind'] ?? '' ) ) ) );
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
