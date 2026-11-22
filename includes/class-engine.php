<?php
/**
 * My_IAPSNJ_Engine
 *
 * Links WordPress users to their FluentCRM contacts and gives each user the
 * WordPress role that matches their membership.
 *
 * FluentCRM is the single source of truth for member data, and members edit
 * it directly (Edit Profile, the checkout application). WordPress users exist
 * for login only: nothing about a member is copied onto the user any more
 * (the "Profile Mirror" / "Profile Sync" copy of CRM fields into user meta and
 * ACF fields was retired in 4.35), and nothing is ever written from WordPress
 * back to the CRM.
 *
 * What is left:
 *  - the contact's member_number custom field is its WordPress user ID: set
 *    when a contact is linked to a user, restored whenever it is edited, and
 *    backfilled for every linked contact (see sync_member_number() /
 *    backfill_member_numbers()); it is never entered by hand;
 *  - find_linked_subscriber() / remember_subscriber_link(): the contact of a
 *    user, by subscriber.user_id, the cached id in user meta, else email;
 *  - a new WordPress user is linked to the contact with the same email, and a
 *    deleted user unlinks its contact (the contact is never deleted);
 *  - apply_role(): the role mapped to the member type while the membership is
 *    active, the "expired" role once it has lapsed (Settings →
 *    Settings → Config → Roles). Staff accounts are never touched.
 *    It runs when a contact is saved, when a payment is applied and in the
 *    daily expiry job.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Engine {

    /** @var self|null */
    private static ?self $instance = null;

    /**
     * user_meta key that caches the resolved FluentCRM subscriber ID for a
     * WordPress user. Without it, every lookup falls back to email, which
     * stops matching as soon as the member changes their email (audit P3-7).
     */
    const LINK_META_KEY = '_my_iapsnj_subscriber_id';

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    // -----------------------------------------------------------------------
    // Hooks
    // -----------------------------------------------------------------------

    private function register_hooks(): void {
        add_action( 'fluent_crm/contact_created', [ $this, 'on_fcrm_contact_saved' ], 20 );
        add_action( 'fluent_crm/contact_updated', [ $this, 'on_fcrm_contact_saved' ], 20 );
        // Legacy hook names fired by older FluentCRM UI paths.
        add_action( 'fluentcrm_contact_created', [ $this, 'on_fcrm_contact_saved' ], 20 );
        add_action( 'fluentcrm_contact_updated', [ $this, 'on_fcrm_contact_saved' ], 20 );
        add_action( 'user_register', [ $this, 'on_user_register' ], 20 );
        add_action( 'delete_user', [ $this, 'on_user_delete' ], 10 );

        // member_number is the WordPress user ID and is not editable: put it
        // back whenever a contact is created or saved, or its custom fields
        // are written (admin edit, REST, import). Hook arguments differ by
        // event, so the handler looks for the contact among them.
        foreach ( [
            'fluent_crm/contact_created',
            'fluent_crm/contact_updated',
            'fluent_crm/contact_custom_data_updated',
            'fluentcrm_contact_created',
            'fluentcrm_contact_updated',
            'fluentcrm_contact_custom_data_updated',
        ] as $hook ) {
            add_action( $hook, [ $this, 'enforce_member_number' ], 30, 3 );
        }
        add_action( 'admin_footer', [ $this, 'lock_member_number_field' ] );
    }

    /**
     * A new WordPress user: link to the existing CRM contact (by email) and
     * give the user the role of the contact's membership. The only thing that
     * reaches the CRM is the user ID, as the contact's member_number.
     */
    public function on_user_register( int $user_id ): void {
        $sub = self::find_linked_subscriber( $user_id );
        if ( $sub instanceof Subscriber ) {
            if ( empty( $sub->user_id ) ) {
                $sub->user_id = $user_id;
                $sub->save();
            }
            self::sync_member_number( (int) $sub->id, $user_id );
            self::apply_role( $sub, $user_id );
        }
    }

    public function on_user_delete( int $user_id ): void {
        $subscriber = Subscriber::where( 'user_id', $user_id )->first();
        if ( $subscriber ) {
            // Unlink rather than delete the contact — the CRM record is the
            // member's history and must survive a WordPress user deletion.
            $subscriber->user_id = null;
            $subscriber->save();
        }
        delete_user_meta( $user_id, self::LINK_META_KEY );
    }

    public function on_fcrm_contact_saved( $subscriber ): void {
        if ( ! $subscriber instanceof Subscriber ) {
            return;
        }
        // Deduplicate: legacy + new hooks may fire for the same save.
        static $processed = [];
        if ( ! empty( $processed[ $subscriber->id ] ) ) {
            return;
        }
        $processed[ $subscriber->id ] = true;
        $this->sync_role( $subscriber );
    }

    /**
     * Give the contact's linked WordPress user the role its membership calls
     * for (no-op when the contact has no user yet). Best effort: a failure is
     * logged, never thrown into a payment or a contact save.
     */
    public function sync_role( Subscriber $subscriber ): string {
        $user_id = (int) $subscriber->user_id;
        if ( $user_id <= 0 ) {
            return '';
        }
        try {
            return self::apply_role( $subscriber, $user_id );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: could not set the WordPress role for contact #' . (int) $subscriber->id . ': ' . $e->getMessage() );
            return '';
        }
    }

    // -----------------------------------------------------------------------
    // member_number = WordPress user ID
    // -----------------------------------------------------------------------

    /** Option holding the member numbers that were replaced by a user ID: contact id => previous value. */
    const LEGACY_NUMBERS_OPTION = 'my_iapsnj_member_number_legacy';

    /**
     * Restore the member_number of the contact an event is about. The
     * handler is registered on several FluentCRM events whose arguments
     * differ, so the contact is whichever argument is a Subscriber.
     *
     * @param mixed ...$args
     */
    public function enforce_member_number( ...$args ): void {
        foreach ( $args as $arg ) {
            if ( $arg instanceof Subscriber ) {
                if ( (int) $arg->user_id > 0 ) {
                    try {
                        self::sync_member_number( (int) $arg->id, (int) $arg->user_id );
                    } catch ( \Throwable $e ) {
                        error_log( 'My IAPSNJ: could not set the member number of contact #' . (int) $arg->id . ': ' . $e->getMessage() );
                    }
                }
                return;
            }
        }
    }

    /**
     * Make a contact's member_number its WordPress user ID. A direct write
     * (no FluentCRM events, so no loop and no automations). Idempotent.
     *
     * @return bool whether anything was written
     */
    public static function sync_member_number( int $subscriber_id, int $user_id ): bool {
        global $wpdb;
        if ( $subscriber_id <= 0 || $user_id <= 0 ) {
            return false;
        }
        $meta = $wpdb->prefix . 'fc_subscriber_meta';
        $want = (string) $user_id;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- identifiers are the plugin's own, values are prepared
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, `value` FROM {$meta} WHERE subscriber_id = %d AND object_type = 'custom_field' AND `key` = %s ORDER BY id ASC",
            $subscriber_id,
            My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER
        ) );
        if ( count( $rows ) === 1 && trim( (string) $rows[0]->value ) === $want ) {
            return false;
        }
        if ( ! $rows ) {
            $wpdb->insert( $meta, [
                'subscriber_id' => $subscriber_id,
                'created_by'    => 0,
                'object_type'   => 'custom_field',
                'key'           => My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER,
                'value'         => $want,
                'created_at'    => current_time( 'mysql', true ),
                'updated_at'    => current_time( 'mysql', true ),
            ] );
            return true;
        }
        $old = trim( (string) $rows[0]->value );
        if ( $old !== '' && $old !== $want ) {
            self::remember_legacy_numbers( [ $subscriber_id => $old ] );
        }
        $wpdb->update( $meta, [ 'value' => $want, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $rows[0]->id ] );
        foreach ( array_slice( $rows, 1 ) as $dupe ) {
            $wpdb->delete( $meta, [ 'id' => (int) $dupe->id ] );
        }
        // phpcs:enable
        return true;
    }

    /**
     * Give every contact linked to a WordPress user the user's ID as its
     * member_number: fills the empty ones and corrects the ones that differ
     * (a number typed by hand, or one carried over from the old system; the
     * replaced value is kept in LEGACY_NUMBERS_OPTION). Contacts with no
     * linked user have nothing to match and are left alone (counted in
     * 'unlinked'; the migration's link_subscribers step links them).
     * Idempotent; direct table writes, no automations.
     *
     * @param bool $dry Count only.
     * @return array{filled:int,corrected:int,unlinked:int,dry:bool}
     */
    public static function backfill_member_numbers( bool $dry = false ): array {
        global $wpdb;
        $subs  = $wpdb->prefix . 'fc_subscribers';
        $meta  = $wpdb->prefix . 'fc_subscriber_meta';
        $users = $wpdb->users;
        $slug  = My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER;
        $now   = current_time( 'mysql', true );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- identifiers are the plugin's own, values are prepared
        $has_row   = "EXISTS (SELECT 1 FROM {$meta} m WHERE m.subscriber_id = s.id AND m.object_type = 'custom_field' AND m.`key` = %s)";
        $filled    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$subs} s INNER JOIN {$users} u ON u.ID = s.user_id
             WHERE s.user_id > 0 AND NOT EXISTS (SELECT 1 FROM {$meta} m WHERE m.subscriber_id = s.id AND m.object_type = 'custom_field' AND m.`key` = %s AND TRIM(COALESCE(m.`value`, '')) <> '')",
            $slug
        ) );
        $differing = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id AS id, MAX(TRIM(m.`value`)) AS old FROM {$subs} s
             INNER JOIN {$users} u ON u.ID = s.user_id
             INNER JOIN {$meta} m ON m.subscriber_id = s.id AND m.object_type = 'custom_field' AND m.`key` = %s
             WHERE s.user_id > 0 AND TRIM(COALESCE(m.`value`, '')) <> '' AND TRIM(m.`value`) <> CAST(s.user_id AS CHAR)
               AND NOT EXISTS (SELECT 1 FROM {$meta} ok WHERE ok.subscriber_id = s.id AND ok.object_type = 'custom_field' AND ok.`key` = %s AND TRIM(ok.`value`) = CAST(s.user_id AS CHAR))
             GROUP BY s.id",
            $slug,
            $slug
        ) );
        $unlinked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE user_id IS NULL OR user_id = 0" );
        $report   = [ 'filled' => $filled, 'corrected' => count( $differing ), 'unlinked' => $unlinked, 'dry' => $dry ];
        if ( $dry || ( ! $filled && ! $differing ) ) {
            return $report;
        }

        // The field has to exist for the value to show on the contact.
        My_IAPSNJ_Schema::ensure_custom_fields();

        $legacy = [];
        foreach ( $differing as $row ) {
            $legacy[ (int) $row->id ] = (string) $row->old;
        }
        self::remember_legacy_numbers( $legacy );

        // Rows that exist but are empty or different: set to the user ID.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$meta} m INNER JOIN {$subs} s ON s.id = m.subscriber_id INNER JOIN {$users} u ON u.ID = s.user_id
             SET m.`value` = CAST(s.user_id AS CHAR), m.updated_at = %s
             WHERE m.object_type = 'custom_field' AND m.`key` = %s AND s.user_id > 0 AND TRIM(COALESCE(m.`value`, '')) <> CAST(s.user_id AS CHAR)",
            $now,
            $slug
        ) );
        // Contacts with no row at all.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$meta} (subscriber_id, created_by, object_type, `key`, `value`, created_at, updated_at)
             SELECT s.id, 0, 'custom_field', %s, CAST(s.user_id AS CHAR), %s, %s
             FROM {$subs} s INNER JOIN {$users} u ON u.ID = s.user_id
             WHERE s.user_id > 0 AND NOT {$has_row}",
            $slug,
            $now,
            $now,
            $slug
        ) );
        // phpcs:enable
        return $report;
    }

    /**
     * Keep the member numbers a user ID replaced (first value seen per
     * contact wins), so a number from the old system is never lost.
     *
     * @param array<int,string> $numbers contact id => previous value
     */
    private static function remember_legacy_numbers( array $numbers ): void {
        if ( ! $numbers ) {
            return;
        }
        $kept = get_option( self::LEGACY_NUMBERS_OPTION, [] );
        $kept = is_array( $kept ) ? $kept : [];
        $new  = array_diff_key( $numbers, $kept );
        if ( $new ) {
            update_option( self::LEGACY_NUMBERS_OPTION, $kept + $new, false );
        }
    }

    /**
     * Member Number cannot be typed over in FluentCRM's contact editor: the
     * input is disabled. (A save is put right by enforce_member_number()
     * whatever the page does; this only stops the edit being offered.)
     */
    public function lock_member_number_field(): void {
        if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'fluentcrm-admin' ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $label = 'Member Number';
        foreach ( (array) fluentcrm_get_option( 'contact_custom_fields', [] ) as $f ) {
            if ( is_array( $f ) && ( $f['slug'] ?? '' ) === My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER && ! empty( $f['label'] ) ) {
                $label = (string) $f['label'];
            }
        }
        $note = __( 'Always the WordPress user ID.', 'my-iapsnj' );
        ?>
        <script>
        (function () {
            var label = <?php echo wp_json_encode( $label ); ?>;
            var note = <?php echo wp_json_encode( $note ); ?>;
            var queued = false;
            function lock() {
                queued = false;
                document.querySelectorAll('.el-form-item').forEach(function (item) {
                    var l = item.querySelector('.el-form-item__label');
                    if (!l || l.textContent.replace(/[:*\s]+$/, '').replace(/^[*\s]+/, '') !== label) {
                        return;
                    }
                    item.title = note;
                    item.querySelectorAll('input, textarea').forEach(function (i) {
                        i.readOnly = true;
                        i.disabled = true;
                    });
                    item.querySelectorAll('.el-input-number__increase, .el-input-number__decrease').forEach(function (b) {
                        b.style.display = 'none';
                    });
                });
            }
            new MutationObserver(function () {
                if (!queued) {
                    queued = true;
                    window.requestAnimationFrame(lock);
                }
            }).observe(document.body, { childList: true, subtree: true });
            lock();
        })();
        </script>
        <?php
    }

    // -----------------------------------------------------------------------
    // WordPress role from member_type
    // -----------------------------------------------------------------------

    /**
     * Roles the mapping is allowed to hand out: every role except
     * administrator (a CRM edit must never grant admin).
     *
     * @return array<string,string> slug => display name
     */
    public static function assignable_roles(): array {
        $out = [];
        foreach ( wp_roles()->get_names() as $slug => $name ) {
            if ( $slug !== 'administrator' ) {
                $out[ $slug ] = translate_user_role( $name );
            }
        }
        return $out;
    }

    /**
     * member_type => role slug from Settings → Config → Roles ('' = leave the role alone).
     *
     * @return array<string,string>
     */
    public static function role_map(): array {
        $map = My_IAPSNJ_Plugin::settings()['role_map'] ?? [];
        return is_array( $map ) ? array_filter( array_map( 'strval', $map ), 'strlen' ) : [];
    }

    /**
     * Role an expired member drops to (Settings → Config → Roles; default subscriber).
     */
    public static function expired_role(): string {
        $role = sanitize_key( (string) ( My_IAPSNJ_Plugin::settings()['role_expired'] ?? 'subscriber' ) );
        return $role === 'administrator' ? '' : $role;
    }

    /**
     * Give the user the role that matches the contact's membership state:
     * the role mapped to its member_type while active (paid through today
     * or later, or comped), the "expired" role once paid_through is past.
     *
     * Only users whose current roles are all "managed" (a mapped role, the
     * expired role, or subscriber) are touched: an administrator, editor or
     * any other staff account keeps its role whatever the CRM says. A
     * contact whose member_type has no mapping is left alone.
     *
     * @param array<string,mixed>|null $custom_fields pre-loaded contact custom fields
     * @return string the role set, '' when nothing changed
     */
    public static function apply_role( Subscriber $subscriber, int $user_id, ?array $custom_fields = null ): string {
        $map = self::role_map();
        if ( ! $map ) {
            return '';
        }
        if ( $custom_fields === null ) {
            $custom_fields = $subscriber->custom_fields();
        }
        $type = $custom_fields[ My_IAPSNJ_Schema::FIELD_MEMBER_TYPE ] ?? '';
        $type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
        $pt   = $custom_fields[ My_IAPSNJ_Schema::FIELD_PAID_THROUGH ] ?? '';
        $pt   = is_array( $pt ) ? (string) reset( $pt ) : (string) $pt;
        if ( $type === '' || ! isset( $map[ $type ] ) ) {
            return '';
        }
        $expired = self::expired_role();
        $role    = My_IAPSNJ_Schema::is_active_state( $type, $pt ) ? $map[ $type ] : $expired;
        if ( $role === '' || $role === 'administrator' || ! get_role( $role ) ) {
            return '';
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return '';
        }
        $current = (array) $user->roles;
        if ( $current === [ $role ] ) {
            return '';
        }
        $managed = array_unique( array_filter( array_merge( array_values( $map ), [ 'subscriber', $expired ] ), 'strlen' ) );
        foreach ( $current as $r ) {
            if ( ! in_array( $r, $managed, true ) ) {
                return ''; // staff account: never touched
            }
        }
        $user->set_role( $role );
        return $role;
    }

    // -----------------------------------------------------------------------
    // Subscriber linking
    // -----------------------------------------------------------------------

    /**
     * Resolve the FluentCRM contact for a WordPress user.
     *
     * Lookup order:
     *   1. subscriber.user_id — the authoritative link.
     *   2. The cached subscriber ID in user_meta — survives an email change.
     *   3. Email — first-time linking only.
     *
     * A match found by (2) or (3) is only accepted when the contact is
     * unlinked or already linked to this same user, so we never hijack another
     * member's contact. Every successful match is written back to user_meta.
     */
    public static function find_linked_subscriber( int $user_id, ?\WP_User $user_info = null ): ?Subscriber {
        if ( $user_id <= 0 ) {
            return null;
        }

        $sub = Subscriber::where( 'user_id', $user_id )->first();
        if ( $sub instanceof Subscriber ) {
            self::remember_subscriber_link( $user_id, (int) $sub->id );
            return $sub;
        }

        $cached_id = (int) get_user_meta( $user_id, self::LINK_META_KEY, true );
        if ( $cached_id > 0 ) {
            $sub = Subscriber::where( 'id', $cached_id )->first();
            if ( $sub instanceof Subscriber && self::link_is_free( $sub, $user_id ) ) {
                return $sub;
            }
            // Stale pointer (contact deleted or re-assigned) — drop it.
            delete_user_meta( $user_id, self::LINK_META_KEY );
        }

        if ( ! $user_info instanceof \WP_User ) {
            $user_info = get_userdata( $user_id ) ?: null;
        }
        if ( ! $user_info instanceof \WP_User || ! $user_info->user_email ) {
            return null;
        }

        $sub = Subscriber::where( 'email', $user_info->user_email )->first();
        if ( $sub instanceof Subscriber && self::link_is_free( $sub, $user_id ) ) {
            self::remember_subscriber_link( $user_id, (int) $sub->id );
            return $sub;
        }

        return null;
    }

    private static function link_is_free( Subscriber $sub, int $user_id ): bool {
        return empty( $sub->user_id ) || (int) $sub->user_id === $user_id;
    }

    /**
     * Cache the resolved subscriber ID on the WP user.
     */
    public static function remember_subscriber_link( int $user_id, int $subscriber_id ): void {
        if ( $user_id <= 0 || $subscriber_id <= 0 ) {
            return;
        }
        if ( (int) get_user_meta( $user_id, self::LINK_META_KEY, true ) === $subscriber_id ) {
            return;
        }
        update_user_meta( $user_id, self::LINK_META_KEY, $subscriber_id );
    }
}
