<?php
/**
 * My_IAPSNJ_Applications
 *
 * Tracks Fluent Forms join / renewal submissions from the moment they are
 * submitted until the matching FluentCart order is paid.
 *
 * Why this exists: a member who completes the application but never pays
 * used to vanish. Now every submission is a row in {prefix}my_iapsnj_applications,
 * the CRM contact is tagged Checkout-Abandoned, and the row is resolved to
 * "awaiting_check" / "paid" / "refunded" as the order progresses. The
 * pending rows are the follow-up list; the Orphan report is built on them.
 *
 * Handoff integrity: each row carries a token that is appended to the
 * Fluent Forms redirect URL (fluentform/redirect_url_value). FluentCart's
 * instant-checkout route preserves unknown query parameters, so the token
 * reaches the checkout page, where My_IAPSNJ_Membership stores it on the
 * cart and locks the email field. Orders therefore reconcile back to their
 * application by token first, and by email only as a fallback.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Applications {

    /** @var self|null */
    private static ?self $instance = null;

    const STATUS_PENDING        = 'pending';         // form submitted, no order yet
    const STATUS_AWAITING_CHECK = 'awaiting_check';  // offline order placed
    const STATUS_PAID           = 'paid';
    const STATUS_REFUNDED       = 'refunded';

    const KIND_JOIN    = 'join';
    const KIND_RENEWAL = 'renewal';

    /** @var array<int,string> submission id → token, within one request */
    private static array $token_by_submission = [];

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'fluentform/submission_inserted', [ $this, 'on_submission_inserted' ], 10, 3 );
        add_filter( 'fluentform/redirect_url_value', [ $this, 'on_redirect_url' ], 10, 4 );
        add_action( 'fluent_crm/contact_updated_by_fluentform', [ $this, 'on_crm_contact_from_form' ], 10, 4 );
    }

    /**
     * Fluent Forms present?
     */
    public static function is_available(): bool {
        return defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' );
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
            email VARCHAR(191) NOT NULL DEFAULT '',
            first_name VARCHAR(191) NOT NULL DEFAULT '',
            last_name VARCHAR(191) NOT NULL DEFAULT '',
            subscriber_id BIGINT UNSIGNED NULL,
            variation_id BIGINT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            token VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY status (status),
            KEY order_id (order_id),
            KEY submission_id (submission_id),
            KEY token (token)
        ) {$charset};";

        dbDelta( $sql );
    }

    // -----------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------

    /**
     * Configured Fluent Forms: form id → kind.
     *
     * @return array<int,string>
     */
    public static function configured_forms(): array {
        $s   = My_IAPSNJ_Plugin::settings();
        $out = [];
        if ( (int) ( $s['join_form_id'] ?? 0 ) > 0 ) {
            $out[ (int) $s['join_form_id'] ] = self::KIND_JOIN;
        }
        if ( (int) ( $s['renewal_form_id'] ?? 0 ) > 0 ) {
            $out[ (int) $s['renewal_form_id'] ] = self::KIND_RENEWAL;
        }
        return $out;
    }

    public static function kind_for_form( int $form_id ): string {
        return self::configured_forms()[ $form_id ] ?? '';
    }

    // -----------------------------------------------------------------------
    // Fluent Forms hooks
    // -----------------------------------------------------------------------

    /**
     * fluentform/submission_inserted — record the application and tag the
     * contact Checkout-Abandoned. The tag is removed when the order is paid.
     *
     * @param int    $insert_id
     * @param array  $form_data
     * @param object $form
     */
    public function on_submission_inserted( $insert_id, $form_data, $form ): void {
        $form_id = (int) ( $form->id ?? 0 );
        $kind    = self::kind_for_form( $form_id );
        if ( $kind === '' || ! is_array( $form_data ) ) {
            return;
        }

        try {
            $email = self::extract_email( $form_data );
            if ( ! is_email( $email ) ) {
                error_log( sprintf( 'My IAPSNJ: form %d submission %d has no usable email; application not recorded.', $form_id, (int) $insert_id ) );
                return;
            }
            $names        = self::extract_names( $form_data );
            $variation_id = self::extract_variation_id( $form_data );

            global $wpdb;
            $now = My_IAPSNJ_Dates::now_utc();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( self::table(), [
                'kind'          => $kind,
                'form_id'       => $form_id,
                'submission_id' => (int) $insert_id,
                'email'         => strtolower( $email ),
                'first_name'    => $names['first_name'],
                'last_name'     => $names['last_name'],
                'variation_id'  => $variation_id ?: null,
                'status'        => self::STATUS_PENDING,
                'token'         => '',
                'created_at'    => $now,
                'updated_at'    => $now,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( ! $id ) {
                return;
            }
            $token = self::make_token( $id, $email );
            self::update( $id, [ 'token' => $token ] );
            self::$token_by_submission[ (int) $insert_id ] = $token;

            // Create or update the CRM contact and tag it. The FluentCRM feed on
            // the same form fills in the full profile; this guarantees the
            // contact and the tag exist even if that feed is misconfigured.
            $contact_data = [
                'email'      => $email,
                'first_name' => $names['first_name'],
                'last_name'  => $names['last_name'],
            ];
            $existing = Subscriber::where( 'email', $email )->first();
            if ( ! $existing instanceof Subscriber ) {
                $contact_data['status'] = 'subscribed';
                $contact_data['source'] = 'iapsnj-application';
            }
            $subscriber = FluentCrmApi( 'contacts' )->createOrUpdate( $contact_data );
            if ( $subscriber instanceof Subscriber ) {
                $tags = My_IAPSNJ_Schema::tag_ids( [ My_IAPSNJ_Schema::TAG_ABANDONED ] );
                $subscriber->attachTags( array_values( $tags ) );
                self::update( $id, [ 'subscriber_id' => (int) $subscriber->id ] );
            }

            do_action( 'my_iapsnj/application_recorded', self::get( $id ), $form_data, $form );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: application record failed: ' . $e->getMessage() );
        }
    }

    /**
     * fluentform/redirect_url_value — append the application token to the
     * checkout redirect so the order can be reconciled to this submission.
     *
     * @param string $url
     * @param int    $insert_id
     * @param object $form
     * @param array  $form_data
     * @return string
     */
    public function on_redirect_url( $url, $insert_id, $form, $form_data ) {
        if ( ! is_string( $url ) || $url === '' ) {
            return $url;
        }
        $kind = self::kind_for_form( (int) ( $form->id ?? 0 ) );
        if ( $kind === '' ) {
            return $url;
        }
        $token = self::$token_by_submission[ (int) $insert_id ] ?? '';
        if ( $token === '' ) {
            $row   = self::get_by_submission( (int) $insert_id );
            $token = $row ? (string) $row->token : '';
        }
        if ( $token === '' ) {
            return $url;
        }
        return add_query_arg( [ My_IAPSNJ_Membership::QUERY_TOKEN => $token ], $url );
    }

    /**
     * fluent_crm/contact_updated_by_fluentform — the FluentCRM feed has run
     * for this submission; remember the contact id and make sure the
     * Checkout-Abandoned tag survived the feed's own tag handling.
     */
    public function on_crm_contact_from_form( $subscriber, $entry, $form, $feed ): void {
        if ( ! $subscriber instanceof Subscriber ) {
            return;
        }
        if ( self::kind_for_form( (int) ( $form->id ?? 0 ) ) === '' ) {
            return;
        }
        $submission_id = (int) ( is_object( $entry ) ? ( $entry->id ?? 0 ) : ( $entry['id'] ?? 0 ) );
        if ( ! $submission_id ) {
            return;
        }
        $row = self::get_by_submission( $submission_id );
        if ( ! $row ) {
            return;
        }
        if ( (int) $row->subscriber_id !== (int) $subscriber->id ) {
            self::update( (int) $row->id, [ 'subscriber_id' => (int) $subscriber->id ] );
        }
        if ( $row->status === self::STATUS_PENDING ) {
            $tags = My_IAPSNJ_Schema::tag_ids( [ My_IAPSNJ_Schema::TAG_ABANDONED ] );
            $subscriber->attachTags( array_values( $tags ) );
        }
    }

    // -----------------------------------------------------------------------
    // Extraction helpers
    // -----------------------------------------------------------------------

    private static function extract_email( array $form_data ): string {
        $field = (string) ( My_IAPSNJ_Plugin::settings()['form_email_field'] ?? 'email' );
        if ( $field !== '' && isset( $form_data[ $field ] ) && is_string( $form_data[ $field ] ) && is_email( $form_data[ $field ] ) ) {
            return sanitize_email( $form_data[ $field ] );
        }
        foreach ( $form_data as $value ) {
            if ( is_string( $value ) && is_email( $value ) ) {
                return sanitize_email( $value );
            }
        }
        return '';
    }

    /**
     * @return array{first_name:string,last_name:string}
     */
    private static function extract_names( array $form_data ): array {
        $first = '';
        $last  = '';
        if ( isset( $form_data['names'] ) && is_array( $form_data['names'] ) ) {
            $first = (string) ( $form_data['names']['first_name'] ?? '' );
            $last  = (string) ( $form_data['names']['last_name'] ?? '' );
        }
        if ( $first === '' && isset( $form_data['first_name'] ) && is_string( $form_data['first_name'] ) ) {
            $first = $form_data['first_name'];
        }
        if ( $last === '' && isset( $form_data['last_name'] ) && is_string( $form_data['last_name'] ) ) {
            $last = $form_data['last_name'];
        }
        return [
            'first_name' => sanitize_text_field( $first ),
            'last_name'  => sanitize_text_field( $last ),
        ];
    }

    /**
     * The form field that carries the chosen FluentCart variation id.
     * Default field name: membership_product. Configurable via
     * my_iapsnj_settings[form_product_field].
     */
    private static function extract_variation_id( array $form_data ): int {
        $field = (string) ( My_IAPSNJ_Plugin::settings()['form_product_field'] ?? 'membership_product' );
        foreach ( array_unique( [ $field, 'membership_product', 'membership_variation', 'variation_id', 'item_id' ] ) as $key ) {
            if ( $key !== '' && isset( $form_data[ $key ] ) && is_scalar( $form_data[ $key ] ) && is_numeric( $form_data[ $key ] ) ) {
                return (int) $form_data[ $key ];
            }
        }
        return 0;
    }

    // -----------------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------------

    public static function make_token( int $id, string $email ): string {
        return $id . '-' . substr( wp_hash( $id . '|' . strtolower( trim( $email ) ) ), 0, 16 );
    }

    public static function get_by_token( string $token ): ?object {
        $token = trim( $token );
        if ( ! preg_match( '/^(\d+)-([a-f0-9]{16})$/', $token, $m ) ) {
            return null;
        }
        $row = self::get( (int) $m[1] );
        if ( ! $row || ! hash_equals( self::make_token( (int) $row->id, (string) $row->email ), $token ) ) {
            return null;
        }
        return $row;
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

    public static function get_by_submission( int $submission_id ): ?object {
        global $wpdb;
        if ( $submission_id <= 0 || ! self::table_exists() ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE submission_id = %d ORDER BY id DESC LIMIT 1', $submission_id ) );
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
            'SELECT * FROM ' . self::table() . " WHERE email = %s AND status IN (%s, %s) AND created_at >= %s ORDER BY id DESC LIMIT 1",
            $email,
            self::STATUS_PENDING,
            self::STATUS_AWAITING_CHECK,
            $since
        ) );
        return $row ?: null;
    }

    /**
     * Resolve the application behind a FluentCart order: token stored on the
     * cart first, then the customer's open application by email.
     *
     * @param object $order FluentCart Order model
     */
    public static function resolve_for_order( $order ): ?object {
        if ( ! is_object( $order ) ) {
            return null;
        }
        $existing = self::get_by_order( (int) $order->id );
        if ( $existing ) {
            return $existing;
        }

        $token = My_IAPSNJ_Membership::token_for_order( $order );
        if ( $token !== '' ) {
            $app = self::get_by_token( $token );
            if ( $app ) {
                return $app;
            }
        }

        $email = '';
        if ( isset( $order->customer ) && is_object( $order->customer ) ) {
            $email = (string) $order->customer->email;
        }
        return $email !== '' ? self::find_open_by_email( $email ) : null;
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
