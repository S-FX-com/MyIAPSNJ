<?php
/**
 * My_IAPSNJ_Applications
 *
 * Tracks membership applications from the moment an email is typed at the
 * FluentCart checkout until the order is paid.
 *
 * Why this exists: a member who starts an application but never pays used
 * to vanish. Now every checkout with a membership product is a row in
 * {prefix}my_iapsnj_applications, the CRM contact is tagged
 * Checkout-Abandoned, and the row is resolved to "awaiting_check" / "paid" /
 * "refunded" as the order progresses. The pending rows are the follow-up
 * list; the Orphan report is built on them.
 *
 * Rows are written by My_IAPSNJ_Checkout_Fields (form_data_changed and
 * prepare_other_data) and resolved by My_IAPSNJ_Membership (order placed
 * offline, paid, refunded). The link between a row and its order is the
 * FluentCart cart hash, which FluentCart itself ties to the order.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Applications {

    const STATUS_PENDING        = 'pending';         // checkout started, no order yet (or card not completed)
    const STATUS_AWAITING_CHECK = 'awaiting_check';  // offline order placed
    const STATUS_PAID           = 'paid';
    const STATUS_REFUNDED       = 'refunded';

    const KIND_JOIN    = 'join';
    const KIND_RENEWAL = 'renewal';

    /**
     * Application tracking needs FluentCart (the checkout is the form).
     */
    public static function is_available(): bool {
        return My_IAPSNJ_Membership::is_available();
    }

    // -----------------------------------------------------------------------
    // Table
    // -----------------------------------------------------------------------

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'my_iapsnj_applications';
    }

    public static function table_exists(): bool {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * Create or upgrade the table (dbDelta adds new columns to existing
     * installs; form_id / submission_id / token remain from 4.0 and are unused).
     */
    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(20) NOT NULL DEFAULT 'join',
            form_id BIGINT UNSIGNED NULL,
            submission_id BIGINT UNSIGNED NULL,
            cart_hash VARCHAR(64) NOT NULL DEFAULT '',
            email VARCHAR(191) NOT NULL DEFAULT '',
            first_name VARCHAR(191) NOT NULL DEFAULT '',
            last_name VARCHAR(191) NOT NULL DEFAULT '',
            subscriber_id BIGINT UNSIGNED NULL,
            variation_id BIGINT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            token VARCHAR(64) NOT NULL DEFAULT '',
            fields LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY status (status),
            KEY order_id (order_id),
            KEY cart_hash (cart_hash)
        ) {$charset};";

        dbDelta( $sql );
    }

    // -----------------------------------------------------------------------
    // Recording (called from the checkout hooks)
    // -----------------------------------------------------------------------

    /**
     * Join or renewal? A contact with Paid-YYYY history, or a comped type,
     * is renewing; everyone else is joining.
     */
    public static function kind_for_contact( ?Subscriber $subscriber ): string {
        if ( ! $subscriber instanceof Subscriber ) {
            return self::KIND_JOIN;
        }
        if ( My_IAPSNJ_Schema::paid_years( $subscriber ) ) {
            return self::KIND_RENEWAL;
        }
        if ( My_IAPSNJ_Schema::is_comped_type( My_IAPSNJ_Schema::field( $subscriber, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ) ) ) {
            return self::KIND_RENEWAL;
        }
        return self::KIND_JOIN;
    }

    /**
     * Create or refresh the application row for a checkout. Also makes sure
     * the CRM contact exists and carries Checkout-Abandoned while no order is
     * paid. Membership state (member_type, paid_through, Paid-YYYY) is never
     * touched here.
     *
     * @param object|null           $cart   FluentCart Cart (may be null)
     * @param object|null           $order  FluentCart Order once it exists
     * @param string                $email
     * @param string                $first
     * @param string                $last
     * @param array<string,string>  $fields application values (key => value)
     */
    public static function record_checkout( $cart, $order, string $email, string $first, string $last, array $fields ): ?object {
        $email = strtolower( sanitize_email( $email ) );
        if ( ! is_email( $email ) || ! self::table_exists() ) {
            return null;
        }
        $first     = sanitize_text_field( $first );
        $last      = sanitize_text_field( $last );
        $cart_hash = is_object( $cart ) && ! empty( $cart->cart_hash ) ? (string) $cart->cart_hash : '';
        $order_id  = is_object( $order ) && ! empty( $order->id ) ? (int) $order->id : 0;

        $row = null;
        if ( $order_id ) {
            $row = self::get_by_order( $order_id );
        }
        if ( ! $row && $cart_hash !== '' ) {
            $row = self::get_by_cart( $cart_hash );
        }
        if ( ! $row ) {
            $row = self::find_open_by_email( $email );
        }
        if ( $row && $row->status === self::STATUS_PAID && ( ! $order_id || (int) $row->order_id !== $order_id ) ) {
            $row = null; // a new checkout by a paid member: fresh row
        }

        // ---- Contact (minimal; the checkout billing data fills the rest on payment)
        $subscriber = null;
        try {
            $existing = Subscriber::where( 'email', $email )->first();
            $data     = [ 'email' => $email ];
            if ( $existing instanceof Subscriber ) {
                $data['id'] = $existing->id;
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
                $data['source']     = 'iapsnj-checkout';
            }
            $subscriber = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
            if ( ! $subscriber instanceof Subscriber ) {
                $subscriber = $existing instanceof Subscriber ? $existing : null;
            }
            if ( $subscriber instanceof Subscriber && ( ! $row || in_array( $row->status, [ self::STATUS_PENDING ], true ) ) ) {
                $tags = My_IAPSNJ_Schema::tag_ids( [ My_IAPSNJ_Schema::TAG_ABANDONED ] );
                $subscriber->attachTags( array_values( $tags ) );
            }
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: contact upsert at checkout failed for ' . $email . ': ' . $e->getMessage() );
        }

        // ---- Variation
        $variation_id = 0;
        if ( is_object( $order ) ) {
            try {
                $plan = My_IAPSNJ_Membership::plan_for_order( $order );
                if ( $plan && ! empty( $plan['items'] ) ) {
                    $variation_id = (int) $plan['items'][0]['variation_id'];
                }
            } catch ( \Throwable $e ) {
                $variation_id = 0;
            }
        }
        if ( ! $variation_id && is_object( $cart ) ) {
            $config = My_IAPSNJ_Membership::products_config();
            foreach ( (array) My_IAPSNJ_Checkout_Fields::cart_variation_ids( $cart ) as $vid ) {
                if ( isset( $config[ $vid ] ) ) {
                    $variation_id = (int) $vid;
                    break;
                }
            }
        }

        $now  = My_IAPSNJ_Dates::now_utc();
        $data = [
            'email'      => $email,
            'updated_at' => $now,
        ];
        if ( $first !== '' ) {
            $data['first_name'] = $first;
        }
        if ( $last !== '' ) {
            $data['last_name'] = $last;
        }
        if ( $subscriber instanceof Subscriber ) {
            $data['subscriber_id'] = (int) $subscriber->id;
        }
        if ( $cart_hash !== '' ) {
            $data['cart_hash'] = $cart_hash;
        }
        if ( $variation_id ) {
            $data['variation_id'] = $variation_id;
        }
        if ( $order_id ) {
            $data['order_id'] = $order_id;
        }
        if ( $fields ) {
            $data['fields'] = wp_json_encode( $fields );
        }
        // The kind is decided while the application is still open.
        if ( ! $row || ( $row->status === self::STATUS_PENDING && ! (int) $row->order_id ) ) {
            $data['kind'] = self::kind_for_contact( $subscriber instanceof Subscriber ? $subscriber : null );
        }

        global $wpdb;
        if ( $row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( self::table(), $data, [ 'id' => (int) $row->id ] );
            return self::get( (int) $row->id );
        }
        $data['status']     = self::STATUS_PENDING;
        $data['created_at'] = $now;
        $data['first_name'] = $data['first_name'] ?? '';
        $data['last_name']  = $data['last_name'] ?? '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( self::table(), $data );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) {
            return null;
        }
        $app = self::get( $id );
        if ( $app ) {
            /**
             * A membership application has been opened at checkout.
             *
             * @param object $app  Application row
             * @param object $cart FluentCart Cart|null
             */
            do_action( 'my_iapsnj/application_recorded', $app, $cart );
        }
        return $app;
    }

    /**
     * Application values stored on a row (key => value).
     *
     * @return array<string,string>
     */
    public static function fields_of( object $row ): array {
        if ( empty( $row->fields ) ) {
            return [];
        }
        $decoded = json_decode( (string) $row->fields, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    // -----------------------------------------------------------------------
    // CRUD
    // -----------------------------------------------------------------------

    public static function get( int $id ): ?object {
        global $wpdb;
        if ( $id <= 0 || ! self::table_exists() ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
        return $row ?: null;
    }

    public static function get_by_cart( string $cart_hash ): ?object {
        global $wpdb;
        $cart_hash = trim( $cart_hash );
        if ( $cart_hash === '' || ! self::table_exists() ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE cart_hash = %s ORDER BY id DESC LIMIT 1', $cart_hash ) );
        return $row ?: null;
    }

    public static function get_by_order( int $order_id ): ?object {
        global $wpdb;
        if ( $order_id <= 0 || ! self::table_exists() ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1', $order_id ) );
        return $row ?: null;
    }

    /**
     * Most recent open (pending / awaiting check) application for an email.
     */
    public static function find_open_by_email( string $email, int $within_days = 180 ): ?object {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        if ( $email === '' || ! self::table_exists() ) {
            return null;
        }
        $since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $within_days ) * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE email = %s AND status IN (%s, %s) AND created_at >= %s ORDER BY id DESC LIMIT 1',
            $email,
            self::STATUS_PENDING,
            self::STATUS_AWAITING_CHECK,
            $since
        ) );
        return $row ?: null;
    }

    /**
     * Resolve the application behind a FluentCart order: by order id, then by
     * the cart FluentCart tied to the order, then the customer's open
     * application by email.
     *
     * @param object $order FluentCart Order model
     */
    public static function resolve_for_order( $order ): ?object {
        if ( ! is_object( $order ) || empty( $order->id ) ) {
            return null;
        }
        $existing = self::get_by_order( (int) $order->id );
        if ( $existing ) {
            return $existing;
        }

        $cart_hash = self::cart_hash_for_order( $order );
        if ( $cart_hash !== '' ) {
            $app = self::get_by_cart( $cart_hash );
            if ( $app && $app->status !== self::STATUS_PAID ) {
                return $app;
            }
        }

        $email = '';
        try {
            if ( isset( $order->customer ) && is_object( $order->customer ) ) {
                $email = (string) $order->customer->email;
            }
        } catch ( \Throwable $e ) {
            $email = '';
        }
        return $email !== '' ? self::find_open_by_email( $email ) : null;
    }

    /**
     * Cart hash FluentCart associated with an order ('' when none).
     */
    public static function cart_hash_for_order( $order ): string {
        if ( ! class_exists( '\FluentCart\App\Models\Cart' ) || ! is_object( $order ) ) {
            return '';
        }
        try {
            $cart = \FluentCart\App\Models\Cart::query()->where( 'order_id', (int) $order->id )->first();
            return $cart && ! empty( $cart->cart_hash ) ? (string) $cart->cart_hash : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    public static function update( int $id, array $data ): void {
        global $wpdb;
        if ( $id <= 0 ) {
            return;
        }
        $data['updated_at'] = My_IAPSNJ_Dates::now_utc();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( self::table(), $data, [ 'id' => $id ] );
    }

    public static function mark_awaiting_check( object $app, int $order_id ): void {
        if ( $app->status === self::STATUS_PAID ) {
            return;
        }
        self::update( (int) $app->id, [
            'status'   => self::STATUS_AWAITING_CHECK,
            'order_id' => $order_id,
        ] );
    }

    public static function mark_paid( object $app, int $order_id ): void {
        self::update( (int) $app->id, [
            'status'   => self::STATUS_PAID,
            'order_id' => $order_id,
            'paid_at'  => My_IAPSNJ_Dates::now_utc(),
        ] );
    }

    public static function mark_refunded_for_order( int $order_id ): void {
        $app = self::get_by_order( $order_id );
        if ( $app ) {
            self::update( (int) $app->id, [ 'status' => self::STATUS_REFUNDED ] );
        }
    }

    // -----------------------------------------------------------------------
    // Reporting queries
    // -----------------------------------------------------------------------

    /**
     * Applications with no paid order: the follow-up list.
     *
     * @return object[]
     */
    public static function open_applications( int $older_than_days = 0, int $limit = 500 ): array {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return [];
        }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $older_than_days ) * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE status IN (%s, %s) AND created_at <= %s ORDER BY created_at ASC LIMIT %d',
            self::STATUS_PENDING,
            self::STATUS_AWAITING_CHECK,
            $cutoff,
            max( 1, $limit )
        ) );
        return $rows ?: [];
    }

    /**
     * @return array<string,int>
     */
    public static function count_by_status(): array {
        global $wpdb;
        $out = [
            self::STATUS_PENDING        => 0,
            self::STATUS_AWAITING_CHECK => 0,
            self::STATUS_PAID           => 0,
            self::STATUS_REFUNDED       => 0,
        ];
        if ( ! self::table_exists() ) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status' );
        foreach ( (array) $rows as $r ) {
            $out[ (string) $r->status ] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Order ids that have an application row, for the orphan report.
     *
     * @param int[] $order_ids
     * @return int[]
     */
    public static function order_ids_with_application( array $order_ids ): array {
        global $wpdb;
        $order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
        if ( ! $order_ids || ! self::table_exists() ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT order_id FROM ' . self::table() . " WHERE order_id IN ({$placeholders})",
            ...$order_ids
        ) );
        return array_map( 'intval', (array) $found );
    }
}
