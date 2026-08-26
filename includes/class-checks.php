<?php
/**
 * My_IAPSNJ_Checks
 *
 * Check payments inside FluentCart, for a volunteer treasurer:
 *
 *  - pending()      every unpaid offline ("Pay by Check") order in one list
 *  - mark_paid()    batch "mark paid" with a deposit date and check numbers;
 *                   the batch total is returned so it can be matched to the
 *                   deposit slip
 *  - record_check() a member mailed a check without ever using the website:
 *                   pick the member, pick the product, record the check —
 *                   the FluentCart order is created and paid behind the
 *                   scenes and the standard order_paid automation fires
 *
 * Marking paid mirrors FluentCart's own OrderController::markAsPaid()
 * (verified against 1.6.3): reuse/create the succeeded transaction, then
 * StatusHelper::syncOrderStatuses(), which dispatches fluent_cart/order_paid.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Checks {

    public static function is_available(): bool {
        return My_IAPSNJ_Membership::is_available()
            && class_exists( '\FluentCart\App\Models\OrderTransaction' );
    }

    // -----------------------------------------------------------------------
    // Pending list
    // -----------------------------------------------------------------------

    /**
     * Unpaid offline orders, oldest first.
     *
     * @param array $args ['min_age_days' => int, 'membership_only' => bool]
     * @return array<int,array>
     */
    public static function pending( array $args = [] ): array {
        if ( ! self::is_available() ) {
            return [];
        }
        $min_age         = (int) ( $args['min_age_days'] ?? 0 );
        $membership_only = ! empty( $args['membership_only'] );

        $orders = \FluentCart\App\Models\Order::query()
            ->where( 'payment_method', My_IAPSNJ_Membership::OFFLINE_METHOD )
            ->where( 'payment_status', 'pending' )
            ->whereNotIn( 'status', [ 'canceled', 'failed' ] )
            ->with( [ 'customer', 'order_items' ] )
            ->orderBy( 'created_at', 'asc' )
            ->get();

        $rows   = [];
        $emails = [];
        foreach ( $orders as $order ) {
            $email = self::order_email( $order );
            if ( $email !== '' ) {
                $emails[] = strtolower( $email );
            }
        }
        $subs_by_email = [];
        if ( $emails ) {
            foreach ( Subscriber::whereIn( 'email', array_unique( $emails ) )->get() as $s ) {
                $subs_by_email[ strtolower( (string) $s->email ) ] = $s;
            }
        }

        foreach ( $orders as $order ) {
            $plan = My_IAPSNJ_Membership::plan_for_order( $order );
            if ( $membership_only && ! $plan ) {
                continue;
            }
            $age = My_IAPSNJ_Dates::days_since_utc( (string) $order->created_at );
            if ( $age < $min_age ) {
                continue;
            }
            $email = self::order_email( $order );
            $sub   = $subs_by_email[ strtolower( $email ) ] ?? null;
            $items = [];
            foreach ( $order->order_items ?? [] as $item ) {
                $items[] = trim( (string) ( $item->post_title ?? '' ) . ' ' . (string) ( $item->title ?? '' ) );
            }
            $customer = is_object( $order->customer ?? null ) ? $order->customer : null;
            $app      = My_IAPSNJ_Applications::get_by_order( (int) $order->id );

            $rows[] = [
                'id'             => (int) $order->id,
                'uuid'           => (string) $order->uuid,
                'created_at'     => (string) $order->created_at,
                'date'           => My_IAPSNJ_Dates::mysql_utc_display( (string) $order->created_at ),
                'age_days'       => $age,
                'customer_name'  => $customer ? trim( (string) $customer->first_name . ' ' . (string) $customer->last_name ) : '',
                'email'          => $email,
                'items'          => $items,
                'is_membership'  => (bool) $plan,
                'member_type'    => $plan ? $plan['member_type'] : '',
                'total_cents'    => (int) $order->total_amount,
                'total'          => My_IAPSNJ_Membership::format_money( (int) $order->total_amount, (string) $order->currency ),
                'currency'       => (string) $order->currency,
                'member_number'  => $sub instanceof Subscriber ? My_IAPSNJ_Schema::field( $sub, My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ) : '',
                'subscriber_id'  => $sub instanceof Subscriber ? (int) $sub->id : 0,
                'application_id' => $app ? (int) $app->id : 0,
                'source'         => (string) ( $order->getMeta( My_IAPSNJ_Membership::META_SOURCE ) ?: 'checkout' ),
                'admin_url'      => My_IAPSNJ_Membership::order_admin_url( $order ),
                'crm_url'        => $sub instanceof Subscriber ? admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $sub->id ) : '',
            ];
        }
        return $rows;
    }

    // -----------------------------------------------------------------------
    // Batch mark paid
    // -----------------------------------------------------------------------

    /**
     * Mark offline orders as paid.
     *
     * @param int[]             $order_ids
     * @param string            $deposit_date  Y-m-d (bank deposit date)
     * @param array<int,string> $check_numbers order id → check number
     * @param string            $note
     * @return array{results: array, batch_total_cents: int, batch_total: string, paid: int, failed: int}
     */
    public static function mark_paid( array $order_ids, string $deposit_date = '', array $check_numbers = [], string $note = '' ): array {
        $out = [ 'results' => [], 'batch_total_cents' => 0, 'batch_total' => '', 'paid' => 0, 'failed' => 0 ];
        if ( ! self::is_available() ) {
            $out['results'][] = [ 'order_id' => 0, 'ok' => false, 'message' => __( 'FluentCart is not active.', 'my-iapsnj' ) ];
            $out['failed']    = 1;
            return $out;
        }
        $deposit_date = My_IAPSNJ_Dates::ymd( $deposit_date );
        $currency     = '';

        foreach ( array_unique( array_map( 'intval', $order_ids ) ) as $order_id ) {
            if ( $order_id <= 0 ) {
                continue;
            }
            $check_number = sanitize_text_field( (string) ( $check_numbers[ $order_id ] ?? '' ) );
            $result       = self::mark_paid_one( $order_id, $deposit_date, $check_number, $note );
            $out['results'][] = $result;
            if ( $result['ok'] ) {
                $out['paid']++;
                $out['batch_total_cents'] += (int) $result['total_cents'];
                $currency                  = $currency ?: (string) $result['currency'];
            } else {
                $out['failed']++;
            }
        }
        $out['batch_total'] = My_IAPSNJ_Membership::format_money( $out['batch_total_cents'], $currency );
        return $out;
    }

    /**
     * @return array{order_id:int, ok:bool, message:string, total_cents:int, currency:string}
     */
    public static function mark_paid_one( int $order_id, string $deposit_date = '', string $check_number = '', string $note = '' ): array {
        $res = [ 'order_id' => $order_id, 'ok' => false, 'message' => '', 'total_cents' => 0, 'currency' => '' ];
        try {
            $order = \FluentCart\App\Models\Order::query()->with( [ 'transactions', 'customer' ] )->find( $order_id );
            if ( ! $order ) {
                $res['message'] = __( 'Order not found.', 'my-iapsnj' );
                return $res;
            }
            $res['currency'] = (string) $order->currency;
            $due             = (int) $order->total_amount - (int) $order->total_paid;
            if ( (string) $order->payment_status === 'paid' || $due <= 0 ) {
                $res['message'] = __( 'Order is already paid.', 'my-iapsnj' );
                return $res;
            }
            if ( (string) $order->status === 'canceled' ) {
                $res['message'] = __( 'Order is canceled.', 'my-iapsnj' );
                return $res;
            }

            $succeeded = class_exists( '\FluentCart\App\Helpers\Status' ) ? \FluentCart\App\Helpers\Status::TRANSACTION_SUCCEEDED : 'succeeded';
            $pending   = class_exists( '\FluentCart\App\Helpers\Status' ) ? \FluentCart\App\Helpers\Status::TRANSACTION_PENDING : 'pending';
            $charge    = class_exists( '\FluentCart\App\Helpers\Status' ) ? \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE : 'charge';

            // Reuse the pending placeholder transaction FluentCart created at
            // checkout (no vendor_charge_id), exactly as its own mark-as-paid does.
            $transaction = null;
            foreach ( $order->transactions ?? [] as $t ) {
                if ( (string) $t->status === $pending && empty( $t->vendor_charge_id ) ) {
                    $transaction = $t;
                    break;
                }
            }
            $vendor_id = $check_number !== '' ? 'CHECK-' . $check_number : 'CHECK-' . $order_id;
            $tx_data   = [
                'total'               => $due,
                'status'              => $succeeded,
                'payment_method'      => My_IAPSNJ_Membership::OFFLINE_METHOD,
                'vendor_charge_id'    => $vendor_id,
                'payment_mode'        => (string) $order->mode,
                'payment_method_type' => 'check',
                'order_type'          => (string) $order->type,
                'currency'            => (string) $order->currency,
            ];
            if ( $transaction ) {
                $transaction->update( $tx_data );
            } else {
                $transaction = \FluentCart\App\Models\OrderTransaction::query()->create( array_merge( $tx_data, [
                    'order_id'         => $order->id,
                    'transaction_type' => $charge,
                ] ) );
            }

            if ( $check_number !== '' ) {
                $order->updateMeta( My_IAPSNJ_Membership::META_CHECK_NUMBER, $check_number );
            }
            if ( $deposit_date !== '' ) {
                $order->updateMeta( My_IAPSNJ_Membership::META_DEPOSIT_DATE, $deposit_date );
            }
            $log = sprintf(
                /* translators: 1: check number, 2: deposit date, 3: user */
                __( 'Check %1$s marked paid (deposit %2$s) by %3$s.', 'my-iapsnj' ),
                $check_number !== '' ? '#' . $check_number : __( '(no number)', 'my-iapsnj' ),
                $deposit_date !== '' ? My_IAPSNJ_Dates::ymd_display( $deposit_date ) : __( 'n/a', 'my-iapsnj' ),
                wp_get_current_user()->user_login ?: 'system'
            );
            if ( $note !== '' ) {
                $log .= ' ' . sanitize_text_field( $note );
            }
            if ( method_exists( $order, 'addLog' ) ) {
                $order->addLog( __( 'My IAPSNJ: check received', 'my-iapsnj' ), $log, 'info', 'My IAPSNJ' );
            }

            // Fires fluent_cart/order_paid synchronously → membership applied.
            ( new \FluentCart\App\Helpers\StatusHelper( $order ) )->syncOrderStatuses( $transaction );

            $fresh = \FluentCart\App\Models\Order::query()->find( $order_id );
            if ( ! $fresh || (string) $fresh->payment_status !== 'paid' ) {
                $res['message'] = __( 'FluentCart did not report the order as paid after the transaction was recorded.', 'my-iapsnj' );
                return $res;
            }
            $res['ok']          = true;
            $res['total_cents'] = $due;
            $applied            = My_IAPSNJ_Membership::meta_array( $fresh, My_IAPSNJ_Membership::META_APPLIED );
            $res['message']     = is_array( $applied )
                ? sprintf( __( 'Paid. Member type %1$s, paid through %2$s.', 'my-iapsnj' ), $applied['member_type'], $applied['paid_through'] !== '' ? My_IAPSNJ_Dates::ymd_display( $applied['paid_through'] ) : '—' )
                : __( 'Paid. (Order has no configured membership product; CRM not changed.)', 'my-iapsnj' );
            return $res;
        } catch ( \Throwable $e ) {
            $res['message'] = $e->getMessage();
            return $res;
        }
    }

    // -----------------------------------------------------------------------
    // Record a check for a member who never used the website
    // -----------------------------------------------------------------------

    /**
     * @param array $args {
     *   @type int    $subscriber_id  CRM contact (preferred) …
     *   @type int    $user_id        … or WP user …
     *   @type string $email          … or email (new contact allowed with first/last name)
     *   @type string $first_name
     *   @type string $last_name
     *   @type int    $variation_id   FluentCart variation (must be a configured membership product)
     *   @type string $check_number
     *   @type string $deposit_date   Y-m-d
     *   @type string $received_date  Y-m-d
     *   @type string $note
     * }
     * @return array|WP_Error
     */
    public static function record_check( array $args ) {
        if ( ! self::is_available() ) {
            return new WP_Error( 'no_fluentcart', __( 'FluentCart is not active.', 'my-iapsnj' ) );
        }
        if ( ! class_exists( '\FluentCart\Api\Resource\OrderResource' ) ) {
            return new WP_Error( 'no_resource', __( 'FluentCart OrderResource is unavailable in this FluentCart version.', 'my-iapsnj' ) );
        }

        // ---- Member --------------------------------------------------------
        $subscriber = null;
        if ( ! empty( $args['subscriber_id'] ) ) {
            $subscriber = Subscriber::where( 'id', (int) $args['subscriber_id'] )->first();
        }
        $user = null;
        if ( ! $subscriber && ! empty( $args['user_id'] ) ) {
            $user       = get_userdata( (int) $args['user_id'] ) ?: null;
            $subscriber = $user ? My_IAPSNJ_Engine::find_linked_subscriber( (int) $user->ID, $user ) : null;
        }
        // `?:` not `??`: the AJAX handler always posts the keys, possibly empty.
        $email = $subscriber ? (string) $subscriber->email : sanitize_email( (string) ( ( $args['email'] ?? '' ) ?: ( $user ? $user->user_email : '' ) ) );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'no_member', __( 'Select a member (CRM contact or WordPress user) or provide a valid email.', 'my-iapsnj' ) );
        }
        if ( ! $subscriber ) {
            $subscriber = Subscriber::where( 'email', $email )->first();
        }
        $first = $subscriber ? (string) $subscriber->first_name : sanitize_text_field( (string) ( ( $args['first_name'] ?? '' ) ?: ( $user ? $user->first_name : '' ) ) );
        $last  = $subscriber ? (string) $subscriber->last_name : sanitize_text_field( (string) ( ( $args['last_name'] ?? '' ) ?: ( $user ? $user->last_name : '' ) ) );

        // ---- Product -------------------------------------------------------
        $variation_id = (int) ( $args['variation_id'] ?? 0 );
        $cfg          = My_IAPSNJ_Membership::product_config( $variation_id );
        if ( ! $cfg ) {
            return new WP_Error( 'not_membership_product', __( 'That product is not configured as a membership product (My IAPSNJ → Membership Products).', 'my-iapsnj' ) );
        }
        $variation = \FluentCart\App\Models\ProductVariation::query()->with( 'product' )->find( $variation_id );
        if ( ! $variation ) {
            return new WP_Error( 'no_variation', __( 'FluentCart product variation not found.', 'my-iapsnj' ) );
        }
        $product_title = is_object( $variation->product ?? null ) ? (string) $variation->product->post_title : '';

        $check_number  = sanitize_text_field( (string) ( $args['check_number'] ?? '' ) );
        $deposit_date  = My_IAPSNJ_Dates::ymd( $args['deposit_date'] ?? '' );
        $received_date = My_IAPSNJ_Dates::ymd( $args['received_date'] ?? '' );
        $note          = sanitize_text_field( (string) ( $args['note'] ?? '' ) );

        // ---- FluentCart customer ------------------------------------------
        try {
            $customer = \FluentCart\App\Models\Customer::query()->where( 'email', $email )->first();
            if ( ! $customer ) {
                $wp_user_id = $subscriber && ! empty( $subscriber->user_id ) ? (int) $subscriber->user_id : ( $user ? (int) $user->ID : 0 );
                if ( ! $wp_user_id ) {
                    $u = get_user_by( 'email', $email );
                    $wp_user_id = $u ? (int) $u->ID : 0;
                }
                $customer = \FluentCart\App\Models\Customer::query()->create( [
                    'email'          => $email,
                    'first_name'     => $first,
                    'last_name'      => $last,
                    'user_id'        => $wp_user_id ?: null,
                    'status'         => 'active',
                    'purchase_value' => [],
                ] );
            }
            if ( ! $customer || empty( $customer->id ) ) {
                return new WP_Error( 'customer_failed', __( 'Could not create the FluentCart customer.', 'my-iapsnj' ) );
            }
        } catch ( \Throwable $e ) {
            return new WP_Error( 'customer_failed', $e->getMessage() );
        }

        // ---- Order (offline, then paid) -----------------------------------
        $by = wp_get_current_user()->user_login ?: 'system';
        $order_note = trim( sprintf(
            /* translators: 1: check number, 2: received date, 3: admin user */
            __( 'Check %1$s received %2$s, recorded by %3$s via My IAPSNJ.', 'my-iapsnj' ),
            $check_number !== '' ? '#' . $check_number : __( '(no number)', 'my-iapsnj' ),
            $received_date !== '' ? My_IAPSNJ_Dates::ymd_display( $received_date ) : __( '(date not given)', 'my-iapsnj' ),
            $by
        ) . ( $note !== '' ? ' ' . $note : '' ) );

        // updatedPlaceOrder() forwards only customer_id + order_items to the
        // processor; the note is written onto the order afterwards.
        $data = [
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $variation->post_id,
                    'object_id'        => (int) $variation->id,
                    'quantity'         => 1,
                    'unit_price'       => (int) $variation->item_price,
                    'post_title'       => $product_title,
                    'title'            => (string) $variation->variation_title,
                    'payment_type'     => 'onetime',
                    'fulfillment_type' => (string) ( $variation->fulfillment_type ?: 'digital' ),
                    'other_info'       => [ 'payment_type' => 'onetime' ],
                ],
            ],
        ];

        My_IAPSNJ_Membership::suppress_offline_mail( true );
        try {
            $order = \FluentCart\Api\Resource\OrderResource::updatedPlaceOrder( $data );
            if ( is_wp_error( $order ) ) {
                return $order;
            }
            if ( ! is_object( $order ) || empty( $order->id ) ) {
                return new WP_Error( 'order_failed', __( 'FluentCart did not return an order.', 'my-iapsnj' ) );
            }
            $order->note = $order_note;
            $order->save();
            $order->updateMeta( My_IAPSNJ_Membership::META_SOURCE, 'manual_check' );
            if ( $received_date !== '' ) {
                $order->updateMeta( '_my_iapsnj_received_date', $received_date );
            }
            if ( class_exists( '\FluentCart\App\Events\Order\OrderCreated' ) ) {
                try {
                    ( new \FluentCart\App\Events\Order\OrderCreated( $order, null, $order->customer ) )->dispatch();
                } catch ( \Throwable $e ) {
                    // activity log only
                }
            }
        } catch ( \Throwable $e ) {
            My_IAPSNJ_Membership::suppress_offline_mail( false );
            return new WP_Error( 'order_failed', $e->getMessage() );
        } finally {
            My_IAPSNJ_Membership::suppress_offline_mail( false );
        }

        $paid = self::mark_paid_one( (int) $order->id, $deposit_date, $check_number, '' );
        if ( ! $paid['ok'] ) {
            return new WP_Error( 'mark_paid_failed', sprintf( __( 'Order #%1$d was created but could not be marked paid: %2$s', 'my-iapsnj' ), (int) $order->id, $paid['message'] ) );
        }

        $fresh   = \FluentCart\App\Models\Order::query()->find( (int) $order->id );
        $applied = $fresh ? My_IAPSNJ_Membership::meta_array( $fresh, My_IAPSNJ_Membership::META_APPLIED ) : null;

        return [
            'order_id'  => (int) $order->id,
            'order_url' => My_IAPSNJ_Membership::order_admin_url( $fresh ?: $order ),
            'email'     => $email,
            'total'     => My_IAPSNJ_Membership::format_money( (int) $order->total_amount, (string) $order->currency ),
            'applied'   => is_array( $applied ) ? $applied : null,
            'message'   => $paid['message'],
        ];
    }

    // -----------------------------------------------------------------------
    // Member search (Record a Check UI)
    // -----------------------------------------------------------------------

    /**
     * @return array<int,array>
     */
    public static function search_members( string $query, int $limit = 15 ): array {
        $query = trim( $query );
        if ( strlen( $query ) < 2 ) {
            return [];
        }
        $like = '%' . $query . '%';
        $subs = Subscriber::where( function ( $q ) use ( $like ) {
                $q->where( 'email', 'LIKE', $like )
                  ->orWhere( 'first_name', 'LIKE', $like )
                  ->orWhere( 'last_name', 'LIKE', $like );
            } )
            ->orderBy( 'last_name' )
            ->limit( $limit )
            ->get();

        $out = [];
        foreach ( $subs as $s ) {
            $custom = $s->custom_fields();
            $out[]  = [
                'subscriber_id' => (int) $s->id,
                'user_id'       => (int) $s->user_id,
                'name'          => trim( (string) $s->first_name . ' ' . (string) $s->last_name ),
                'email'         => (string) $s->email,
                'member_number' => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] ?? '' ),
                'member_type'   => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ] ?? '' ),
                'paid_through'  => (string) ( $custom[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ] ?? '' ),
                'label'         => trim( (string) $s->first_name . ' ' . (string) $s->last_name ) . ' <' . $s->email . '>'
                    . ( ! empty( $custom[ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] ) ? ' #' . $custom[ My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ] : '' ),
            ];
        }

        // Also WordPress users with no CRM contact (should be rare after migration).
        if ( count( $out ) < $limit ) {
            $users = get_users( [
                'search'         => '*' . $query . '*',
                'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
                'number'         => $limit,
                'fields'         => [ 'ID', 'user_email', 'display_name' ],
            ] );
            $seen = array_map( 'strtolower', array_column( $out, 'email' ) );
            foreach ( $users as $u ) {
                if ( in_array( strtolower( $u->user_email ), $seen, true ) ) {
                    continue;
                }
                $out[] = [
                    'subscriber_id' => 0,
                    'user_id'       => (int) $u->ID,
                    'name'          => (string) $u->display_name,
                    'email'         => (string) $u->user_email,
                    'member_number' => '',
                    'member_type'   => '',
                    'paid_through'  => '',
                    'label'         => $u->display_name . ' <' . $u->user_email . '> ' . __( '(WP user, no CRM contact)', 'my-iapsnj' ),
                ];
                if ( count( $out ) >= $limit ) {
                    break;
                }
            }
        }
        return $out;
    }

    private static function order_email( $order ): string {
        try {
            if ( is_object( $order->customer ?? null ) && ! empty( $order->customer->email ) ) {
                return (string) $order->customer->email;
            }
        } catch ( \Throwable $e ) {
            // fall through
        }
        return '';
    }
}
