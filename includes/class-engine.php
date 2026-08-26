<?php
/**
 * My_IAPSNJ_Engine
 *
 * One-directional mirror: FluentCRM contact → WordPress user.
 *
 * FluentCRM is the single source of truth for member data. WordPress users
 * exist for login only, plus a copy of a few profile fields (kept in user
 * meta so the profile-edit screen and any theme template still read them).
 * Nothing is ever written from WordPress back to the CRM by this class; the
 * FluentCart and Fluent Forms integrations are the only writers.
 *
 * The pre-4.0 bidirectional sync engine (WP → CRM, PMPro sources, mismatch
 * detection) is gone by design — see docs/migration-runbook.md.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Engine {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var My_IAPSNJ_Field_Mapper */
    private My_IAPSNJ_Field_Mapper $mapper;

    /** Re-entrancy guard: our own wp_update_user() must not re-trigger a mirror. */
    private bool $syncing_to_wp = false;

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
        $this->mapper = new My_IAPSNJ_Field_Mapper();
        $this->register_hooks();
    }

    public function set_syncing_to_wp( bool $state ): void {
        $this->syncing_to_wp = $state;
    }

    // -----------------------------------------------------------------------
    // Hooks
    // -----------------------------------------------------------------------

    private function register_hooks(): void {
        $settings = My_IAPSNJ_Plugin::settings();

        if ( ! empty( $settings['sync_on_fcrm_update'] ) ) {
            add_action( 'fluent_crm/contact_created', [ $this, 'on_fcrm_contact_saved' ], 20 );
            add_action( 'fluent_crm/contact_updated', [ $this, 'on_fcrm_contact_saved' ], 20 );
            // Legacy hook names fired by older FluentCRM UI paths.
            add_action( 'fluentcrm_contact_created', [ $this, 'on_fcrm_contact_saved' ], 20 );
            add_action( 'fluentcrm_contact_updated', [ $this, 'on_fcrm_contact_saved' ], 20 );
        }
        if ( ! empty( $settings['link_on_user_register'] ) ) {
            add_action( 'user_register', [ $this, 'on_user_register' ], 20 );
        }
        if ( ! empty( $settings['sync_on_user_delete'] ) ) {
            add_action( 'delete_user', [ $this, 'on_user_delete' ], 10 );
        }
    }

    /**
     * A new WordPress user: link to the existing CRM contact (by email) and
     * mirror the contact's profile onto the user. No data flows to the CRM.
     */
    public function on_user_register( int $user_id ): void {
        if ( $this->syncing_to_wp ) {
            return;
        }
        $sub = self::find_linked_subscriber( $user_id );
        if ( $sub instanceof Subscriber ) {
            if ( empty( $sub->user_id ) ) {
                $sub->user_id = $user_id;
                $sub->save();
            }
            $this->sync_fcrm_to_wp( $sub );
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
        if ( $this->syncing_to_wp ) {
            return;
        }
        // Deduplicate: legacy + new hooks may fire for the same save.
        static $processed = [];
        if ( ! empty( $processed[ $subscriber->id ] ) ) {
            return;
        }
        $processed[ $subscriber->id ] = true;
        $this->sync_fcrm_to_wp( $subscriber );
    }

    // -----------------------------------------------------------------------
    // Core: FluentCRM → WP
    // -----------------------------------------------------------------------

    /**
     * Mirror a contact onto its linked WordPress user.
     *
     * @param Subscriber $subscriber
     * @param string[]   $field_ids Restrict to these mapping ids (empty = all).
     */
    public function sync_fcrm_to_wp( Subscriber $subscriber, array $field_ids = [] ): void {
        $this->syncing_to_wp = true;
        try {
            $user_id = (int) $subscriber->user_id;
            if ( ! $user_id || ! get_userdata( $user_id ) ) {
                return;
            }

            $mappings = $this->mapper->get_active_mappings();
            if ( ! empty( $field_ids ) ) {
                $mappings = array_filter( $mappings, fn( $m ) => in_array( $m['id'] ?? '', $field_ids, true ) );
            }
            $custom_fields = $subscriber->custom_fields();
            $wp_user_data  = [];

            foreach ( $mappings as $mapping ) {
                $fcrm_key = $mapping['fcrm_field_key'];
                $source   = $mapping['fcrm_field_source'] ?? 'default';

                if ( $source === 'custom' ) {
                    $raw_value = $custom_fields[ $fcrm_key ] ?? null;
                } else {
                    $raw_value = $subscriber->{ $fcrm_key } ?? null;
                }

                // Explicit emptiness check (audit P3-6): a legitimate 0 / '0'
                // (member number 0, a select whose stored value is '0') must
                // still be mirrored; only null and '' mean "nothing to copy".
                if ( $raw_value === null || $raw_value === '' ) {
                    continue;
                }

                $formatted = $this->format_value(
                    $raw_value,
                    $mapping['field_type'] ?? 'text',
                    $mapping
                );

                $this->set_wp_field_value( $user_id, $mapping, $formatted, $wp_user_data );
            }

            if ( ! empty( $wp_user_data ) ) {
                $wp_user_data['ID'] = $user_id;
                // Mirroring the CRM email must not make WordPress mail the member
                // an "your email was changed" notice — a bulk mirror after the
                // migration would otherwise email thousands of people.
                add_filter( 'send_email_change_email', '__return_false' );
                add_filter( 'send_password_change_email', '__return_false' );
                try {
                    wp_update_user( $wp_user_data );
                } finally {
                    remove_filter( 'send_email_change_email', '__return_false' );
                    remove_filter( 'send_password_change_email', '__return_false' );
                }
            }
        } finally {
            $this->syncing_to_wp = false;
        }
    }

    // -----------------------------------------------------------------------
    // Preview: current values on both sides for one user
    // -----------------------------------------------------------------------

    /**
     * @return array[]
     */
    public function get_field_values_for_user( int $user_id ): array {
        $user_info = get_userdata( $user_id );
        if ( ! $user_info ) {
            return [];
        }
        $mappings = $this->mapper->get_active_mappings();
        if ( empty( $mappings ) ) {
            return [];
        }

        $contact       = self::find_linked_subscriber( $user_id, $user_info );
        $custom_fields = $contact ? ( $contact->custom_fields() ?: [] ) : [];

        $rows = [];
        foreach ( $mappings as $mapping ) {
            $wp_raw   = $this->get_wp_field_value( $user_id, $user_info, $mapping );
            $fcrm_raw = null;
            if ( $contact ) {
                $fcrm_key = $mapping['fcrm_field_key'];
                if ( ( $mapping['fcrm_field_source'] ?? 'default' ) === 'custom' ) {
                    $fcrm_raw = $custom_fields[ $fcrm_key ] ?? null;
                } else {
                    $fcrm_raw = $contact->{ $fcrm_key } ?? null;
                }
            }

            $wp_display   = is_array( $wp_raw ) ? implode( ', ', $wp_raw ) : (string) ( $wp_raw ?? '' );
            $fcrm_display = is_array( $fcrm_raw ) ? implode( ', ', $fcrm_raw ) : (string) ( $fcrm_raw ?? '' );

            if ( ( $mapping['field_type'] ?? 'text' ) === 'date' && ( $wp_display !== '' || $fcrm_display !== '' ) ) {
                $match = $this->normalize_date( $wp_display, $mapping ) === $this->normalize_date( $fcrm_display, $mapping );
            } else {
                $match = $wp_display === $fcrm_display;
            }

            $rows[] = [
                'id'         => $mapping['id'] ?? '',
                'wp_label'   => $mapping['wp_field_label'] ?? $mapping['wp_field_key'],
                'fcrm_label' => $mapping['fcrm_field_label'] ?? $mapping['fcrm_field_key'],
                'wp_value'   => $wp_display,
                'fcrm_value' => $fcrm_display,
                'match'      => $match,
            ];
        }
        return $rows;
    }

    // -----------------------------------------------------------------------
    // WP field access
    // -----------------------------------------------------------------------

    /**
     * Read the WordPress side of a mapping (for preview / verification).
     *
     * @return mixed
     */
    public function get_wp_field_value( int $user_id, \WP_User $user_info, array $mapping ) {
        $key    = $mapping['wp_field_key'];
        $source = $mapping['wp_field_source'] ?? 'meta';

        if ( $source === 'user' ) {
            return $user_info->{ $key } ?? null;
        }

        // 'acf' and 'meta' both live in user meta; ACF just uses a different
        // date storage format. Read raw meta so ACF need not be installed.
        $val = get_user_meta( $user_id, $key, true );
        if ( $val === '' || $val === false ) {
            return null;
        }
        if ( $source === 'acf' && ( $mapping['field_type'] ?? 'text' ) === 'date' ) {
            $canonical = $this->normalize_date( (string) $val, $mapping );
            return $canonical !== '' ? $canonical : $val;
        }
        return $val;
    }

    /**
     * Write the WordPress side of a mapping.
     *
     * @param mixed $value
     * @param array &$wp_user_data Accumulator for wp_update_user() fields
     */
    public function set_wp_field_value( int $user_id, array $mapping, $value, array &$wp_user_data ): void {
        $key    = $mapping['wp_field_key'];
        $source = $mapping['wp_field_source'] ?? 'meta';

        switch ( $source ) {
            case 'user':
                // ID and login are immutable; everything else on WP_User goes
                // through wp_update_user() so WordPress runs its own checks.
                if ( in_array( $key, [ 'ID', 'user_login', 'user_pass', 'user_registered' ], true ) ) {
                    return;
                }
                if ( $key === 'user_email' ) {
                    // Refuse an email another user already owns — that would
                    // fail in wp_update_user() and abort every other field.
                    $owner = get_user_by( 'email', (string) $value );
                    if ( $owner && (int) $owner->ID !== $user_id ) {
                        return;
                    }
                }
                $wp_user_data[ $key ] = $value;
                break;

            case 'acf':
                // ACF date pickers store dates internally as Ymd.
                if ( ( $mapping['field_type'] ?? 'text' ) === 'date' && $value !== '' && $value !== null ) {
                    $canonical = $this->normalize_date( (string) $value, $mapping );
                    if ( $canonical !== '' ) {
                        $dt = \DateTime::createFromFormat( 'Y-m-d', $canonical );
                        if ( $dt ) {
                            $value = $dt->format( 'Ymd' );
                        }
                    }
                }
                update_user_meta( $user_id, $key, $value );
                break;

            case 'meta':
            default:
                update_user_meta( $user_id, $key, $value );
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Value formatting (CRM → WP)
    // -----------------------------------------------------------------------

    /**
     * @param mixed $value
     * @return mixed
     */
    public function format_value( $value, string $type, array $mapping = [] ) {
        switch ( $type ) {
            case 'date':
                return $this->format_date( $value, $mapping );

            case 'checkbox':
                return $this->format_checkbox( $value );

            case 'select':
                return $this->format_select( $value, $mapping );

            case 'number':
                return is_numeric( $value ) ? (float) $value : $value;

            case 'email':
                return sanitize_email( (string) $value );

            case 'textarea':
            case 'text':
            default:
                return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
        }
    }

    private function format_date( $value, array $mapping ): string {
        if ( $value === null || $value === '' ) {
            return '';
        }
        $canonical = $this->normalize_date( (string) $value, $mapping );
        if ( $canonical === '' ) {
            return (string) $value; // unparseable — pass through unchanged
        }
        $fmt  = $mapping['date_format_wp'] ?? 'Y-m-d';
        $date = \DateTime::createFromFormat( 'Y-m-d', $canonical );
        return $date ? $date->format( $fmt ) : $canonical;
    }

    /**
     * Parse any supported date string to canonical Y-m-d.
     *
     * Date *strings* are round-tripped through UTC (strtotime + gmdate): they
     * have no timezone, and formatting them in the site zone would shift a
     * 12/31 expiration to 12/30 (audit P1-4).
     */
    public function normalize_date( string $value, array $mapping ): string {
        if ( $value === '' ) {
            return '';
        }

        // 1. Compact YYYYMMDD (ACF raw storage format)
        if ( is_numeric( $value ) && strlen( $value ) === 8 ) {
            $iso = substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
            $ts  = strtotime( $iso . ' UTC' );
            return $ts !== false ? gmdate( 'Y-m-d', $ts ) : '';
        }

        // 2. Canonical Y-m-d
        $date = \DateTime::createFromFormat( 'Y-m-d', $value );
        if ( $date && $date->format( 'Y-m-d' ) === $value ) {
            return $value;
        }

        // 3. The configured WP format
        $wp_fmt = $mapping['date_format_wp'] ?? 'Y-m-d';
        if ( $wp_fmt !== 'Y-m-d' ) {
            $date = \DateTime::createFromFormat( $wp_fmt, $value );
            if ( $date && $date->format( $wp_fmt ) === $value ) {
                return $date->format( 'Y-m-d' );
            }
        }

        // 4. strtotime() fallback, UTC in and out
        $ts = strtotime( $value . ' UTC' );
        if ( $ts === false ) {
            $ts = strtotime( $value );
        }
        return $ts !== false ? gmdate( 'Y-m-d', $ts ) : '';
    }

    private function format_checkbox( $value ) {
        if ( is_array( $value ) ) {
            return array_values( $value );
        }
        if ( is_string( $value ) && $value !== '' ) {
            $decoded = json_decode( $value, true );
            if ( $decoded !== null ) {
                return array_values( (array) $decoded );
            }
            $unserialized = maybe_unserialize( $value );
            if ( is_array( $unserialized ) ) {
                return array_values( $unserialized );
            }
            return [ $value ];
        }
        return [];
    }

    /**
     * Select / radio: translate CRM option value → WP option value via the
     * mapping's value_map (stored as WP value => CRM value).
     */
    private function format_select( $value, array $mapping ): string {
        $str_value = is_array( $value ) ? (string) reset( $value ) : (string) $value;
        $value_map = $mapping['value_map'] ?? [];
        if ( ! is_array( $value_map ) || ! $value_map ) {
            return $str_value;
        }
        $reverse_map = [];
        foreach ( $value_map as $wp_val => $fcrm_val ) {
            $reverse_map[ (string) $fcrm_val ] = (string) $wp_val;
        }
        // array_key_exists, not isset/??: a WP value of '0' is a real value.
        return array_key_exists( $str_value, $reverse_map ) ? $reverse_map[ $str_value ] : $str_value;
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

    public function get_mapper(): My_IAPSNJ_Field_Mapper {
        return $this->mapper;
    }

    public function is_syncing(): bool {
        return $this->syncing_to_wp;
    }
}
