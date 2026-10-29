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
 *  - find_linked_subscriber() / remember_subscriber_link(): the contact of a
 *    user, by subscriber.user_id, the cached id in user meta, else email;
 *  - a new WordPress user is linked to the contact with the same email, and a
 *    deleted user unlinks its contact (the contact is never deleted);
 *  - apply_role(): the role mapped to the member type while the membership is
 *    active, the "expired" role once it has lapsed (Settings →
 *    Configurations → WordPress roles). Staff accounts are never touched.
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
    }

    /**
     * A new WordPress user: link to the existing CRM contact (by email) and
     * give the user the role of the contact's membership. No data flows to
     * the CRM.
     */
    public function on_user_register( int $user_id ): void {
        $sub = self::find_linked_subscriber( $user_id );
        if ( $sub instanceof Subscriber ) {
            if ( empty( $sub->user_id ) ) {
                $sub->user_id = $user_id;
                $sub->save();
                My_IAPSNJ_Member_Fields::sync( $sub ); // a member without a number gets their user id
            }
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
     * member_type => role slug from Settings → Configurations → WordPress roles ('' = leave the role alone).
     *
     * @return array<string,string>
     */
    public static function role_map(): array {
        $map = My_IAPSNJ_Plugin::settings()['role_map'] ?? [];
        return is_array( $map ) ? array_filter( array_map( 'strval', $map ), 'strlen' ) : [];
    }

    /**
     * Role an expired member drops to (Settings → Configurations → WordPress roles; default subscriber).
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
