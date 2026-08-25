<?php
/**
 * My_IAPSNJ_Mismatch_Detector
 *
 * Compares WordPress user field values against FluentCRM contact field values
 * (for all active mappings) and reports any discrepancies.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class My_IAPSNJ_Mismatch_Detector {

    /** @var My_IAPSNJ_Field_Mapper */
    private My_IAPSNJ_Field_Mapper $mapper;

    /** @var My_IAPSNJ_Engine */
    private My_IAPSNJ_Engine $engine;

    public function __construct() {
        $this->mapper = new My_IAPSNJ_Field_Mapper();
        $this->engine = My_IAPSNJ_Engine::get_instance();
    }

    // -----------------------------------------------------------------------
    // Detection
    // -----------------------------------------------------------------------

    /**
     * How many WordPress users to pull from the DB per scan batch.
     */
    const USER_BATCH = 200;

    /**
     * Transient holding page -> starting user offset, so paging forward does
     * not rescan from user 1 every time.
     */
    const CURSOR_TRANSIENT = 'my_iapsnj_mismatch_cursors';

    /**
     * Per-request cache of resolved subscribers, keyed by WP user ID.
     *
     * @var array<int, Subscriber|null>
     */
    private array $subscriber_cache = [];

    /**
     * Return an array of mismatch records for a paginated slice of WP users
     * that have a linked FluentCRM contact.
     *
     * Users are pulled from the database in batches and scanning stops as soon
     * as the requested page is full. The previous implementation loaded every
     * user, compared every mapping for each, and only then sliced for
     * pagination — roughly 4,000 members x 32 mappings of work to render ten
     * rows, with a Subscriber lookup and custom_fields() call per user.
     *
     * Because the total is not known without a full scan, `total` reports what
     * has been found up to the end of the current page and `total_is_exact`
     * says whether scanning reached the end of the user list.
     *
     * @param int $page      1-based page number
     * @param int $per_page
     * @return array{items: array, total: int, pages: int, total_is_exact: bool, has_more: bool}
     */
    public function get_mismatches( int $page = 1, int $per_page = 10 ): array {
        $page     = max( 1, $page );
        $per_page = max( 1, $per_page );

        $empty = [
            'items'          => [],
            'total'          => 0,
            'pages'          => 0,
            'total_is_exact' => true,
            'has_more'       => false,
        ];

        $mappings = $this->mapper->get_active_mappings();
        if ( empty( $mappings ) ) {
            return $empty;
        }

        // Only mappings that sync both ways can ever produce a mismatch.
        $mappings = array_values( array_filter(
            $mappings,
            fn( $m ) => ( $m['sync_direction'] ?? 'both' ) === 'both'
        ) );
        if ( empty( $mappings ) ) {
            return $empty;
        }

        $cursors     = $this->get_cursors();
        $user_offset = (int) ( $cursors[ $page ] ?? 0 );
        // Without a cached cursor we must replay from the start of the list and
        // discard the earlier pages' worth of matches.
        $to_skip     = isset( $cursors[ $page ] ) ? 0 : ( $page - 1 ) * $per_page;

        $items      = [];
        $has_more   = false;
        $exhausted  = false;

        while ( true ) {
            $users = get_users( [
                'fields'  => 'all',
                'number'  => self::USER_BATCH,
                'offset'  => $user_offset,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ] );

            if ( empty( $users ) ) {
                $exhausted = true;
                break;
            }

            // Release the previous batch first: paging deep into the list can
            // walk thousands of users, and holding every hydrated Subscriber
            // would reintroduce the memory problem this rewrite removes.
            $this->subscriber_cache = [];

            // A few queries per batch instead of one query per user.
            $this->prime_subscriber_cache( $users );

            foreach ( $users as $index => $wp_user ) {
                $subscriber = $this->find_subscriber_for_user( $wp_user );
                if ( ! $subscriber ) {
                    continue;
                }

                $field_mismatches = $this->compare_fields( $wp_user->ID, $wp_user, $subscriber, $mappings );
                if ( empty( $field_mismatches ) ) {
                    continue;
                }

                if ( $to_skip > 0 ) {
                    $to_skip--;
                    continue;
                }

                if ( count( $items ) >= $per_page ) {
                    // One more exists — enough to know the Next button is live.
                    $has_more = true;
                    // Resume the next page at this user.
                    $cursors[ $page + 1 ] = $user_offset + $index;
                    $this->save_cursors( $cursors );
                    break 2;
                }

                $items[] = $this->build_item( $wp_user, $subscriber, $field_mismatches );
            }

            $user_offset += count( $users );

            if ( count( $users ) < self::USER_BATCH ) {
                $exhausted = true;
                break;
            }
        }

        if ( $exhausted ) {
            $cursors[ $page + 1 ] = $user_offset;
            $this->save_cursors( $cursors );
        }

        $offset_items = ( $page - 1 ) * $per_page;
        $total        = $offset_items + count( $items );
        $pages        = $has_more ? $page + 1 : max( 1, (int) ceil( max( $total, 1 ) / $per_page ) );

        return [
            'items'          => $items,
            'total'          => $total,
            'pages'          => empty( $items ) && $page === 1 ? 0 : $pages,
            'total_is_exact' => ! $has_more,
            'has_more'       => $has_more,
        ];
    }

    /**
     * Shape one mismatch row for the UI.
     */
    private function build_item( \WP_User $wp_user, Subscriber $subscriber, array $field_mismatches ): array {
        $sub_email     = $subscriber->email ?? '';
        $emails_differ = ( strtolower( $sub_email ) !== strtolower( $wp_user->user_email ) );

        return [
            'user_id'                   => $wp_user->ID,
            'user_email'                => $wp_user->user_email,
            'user_display'              => $wp_user->display_name,
            'subscriber_id'             => $subscriber->id,
            'subscriber_email'          => $sub_email,
            'subscriber_email_mismatch' => $emails_differ,
            'wp_edit_url'               => admin_url( 'user-edit.php?user_id=' . $wp_user->ID ),
            'fcrm_contact_url'          => admin_url( 'admin.php?page=fluentcrm-admin&route=contacts&id=' . $subscriber->id ),
            'fields'                    => $field_mismatches,
        ];
    }

    /**
     * Resolve subscribers for a whole batch of users in two queries.
     *
     * @param \WP_User[] $users
     */
    private function prime_subscriber_cache( array $users ): void {
        $pending_ids    = [];
        $pending_emails = [];

        foreach ( $users as $wp_user ) {
            $uid = (int) $wp_user->ID;
            if ( array_key_exists( $uid, $this->subscriber_cache ) ) {
                continue;
            }
            $pending_ids[ $uid ] = $wp_user;
            if ( $wp_user->user_email ) {
                $pending_emails[ strtolower( $wp_user->user_email ) ] = $uid;
            }
        }

        if ( empty( $pending_ids ) ) {
            return;
        }

        $by_user_id = [];
        foreach ( Subscriber::whereIn( 'user_id', array_keys( $pending_ids ) )->get() as $sub ) {
            $by_user_id[ (int) $sub->user_id ] = $sub;
        }

        // Second tier: the cached pointer written by the sync engine. get_users()
        // primed the user-meta cache for this batch, so these reads are free.
        $cached_ids = [];
        foreach ( $pending_ids as $uid => $wp_user ) {
            if ( isset( $by_user_id[ $uid ] ) ) {
                continue;
            }
            $sid = (int) get_user_meta( $uid, My_IAPSNJ_Engine::LINK_META_KEY, true );
            if ( $sid > 0 ) {
                $cached_ids[ $uid ] = $sid;
            }
        }

        $by_cached_id = [];
        if ( ! empty( $cached_ids ) ) {
            foreach ( Subscriber::whereIn( 'id', array_values( array_unique( $cached_ids ) ) )->get() as $sub ) {
                $by_cached_id[ (int) $sub->id ] = $sub;
            }
        }

        // Last tier: email, for contacts that were never linked at all.
        $emails = [];
        foreach ( $pending_emails as $email => $uid ) {
            if ( ! isset( $by_user_id[ $uid ] ) && ! isset( $cached_ids[ $uid ] ) ) {
                $emails[] = $email;
            }
        }

        $by_email = [];
        if ( ! empty( $emails ) ) {
            foreach ( Subscriber::whereIn( 'email', $emails )->get() as $sub ) {
                $by_email[ strtolower( (string) $sub->email ) ] = $sub;
            }
        }

        foreach ( $pending_ids as $uid => $wp_user ) {
            if ( isset( $by_user_id[ $uid ] ) ) {
                $this->subscriber_cache[ $uid ] = $by_user_id[ $uid ];
                continue;
            }

            $sub = null;
            if ( isset( $cached_ids[ $uid ] ) ) {
                $sub = $by_cached_id[ $cached_ids[ $uid ] ] ?? null;
            }
            if ( ! $sub instanceof Subscriber ) {
                $sub = $by_email[ strtolower( (string) $wp_user->user_email ) ] ?? null;
            }

            // Never claim a contact that already belongs to a different user.
            if ( $sub instanceof Subscriber && ! empty( $sub->user_id ) && (int) $sub->user_id !== $uid ) {
                $sub = null;
            }

            $this->subscriber_cache[ $uid ] = $sub;
        }
    }

    /**
     * @return array<int, int>
     */
    private function get_cursors(): array {
        $cursors = get_transient( self::CURSOR_TRANSIENT );
        return is_array( $cursors ) ? $cursors : [];
    }

    private function save_cursors( array $cursors ): void {
        set_transient( self::CURSOR_TRANSIENT, $cursors, HOUR_IN_SECONDS );
    }

    /**
     * Drop cached scan cursors. Called whenever mappings or member data change.
     */
    public static function flush_cache(): void {
        delete_transient( self::CURSOR_TRANSIENT );
    }

    /**
     * Compare all mapped fields for one user↔subscriber pair.
     *
     * @return array  Array of mismatch detail rows (empty = fully in sync).
     */
    private function compare_fields( int $user_id, \WP_User $wp_user, Subscriber $subscriber, array $mappings ): array {
        $mismatches    = [];
        $custom_fields = $subscriber->custom_fields();

        foreach ( $mappings as $mapping ) {
            if ( ( $mapping['sync_direction'] ?? 'both' ) !== 'both' ) {
                continue;
            }

            $wp_raw = $this->engine->get_wp_field_value( $user_id, $wp_user, $mapping );

            $fcrm_key  = $mapping['fcrm_field_key'];
            $fcrm_src  = $mapping['fcrm_field_source'] ?? 'default';
            $fcrm_raw  = ( $fcrm_src === 'custom' )
                ? ( $custom_fields[ $fcrm_key ] ?? null )
                : ( $subscriber->{ $fcrm_key } ?? null );

            $wp_norm   = $this->normalise( $wp_raw,   $mapping );
            $fcrm_norm = $this->normalise( $fcrm_raw, $mapping );

            if ( $this->values_differ( $wp_norm, $fcrm_norm ) ) {
                $mismatches[] = [
                    'mapping_id'  => $mapping['id'] ?? '',
                    'field_label' => $this->get_label( $mapping ),
                    'field_type'  => $mapping['field_type'] ?? 'text',
                    'wp_value'    => $this->display_value( $wp_raw,   $mapping['field_type'] ?? 'text', $mapping ),
                    'fcrm_value'  => $this->display_value( $fcrm_raw, $mapping['field_type'] ?? 'text', $mapping ),
                ];
            }
        }

        return $mismatches;
    }

    // -----------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------

    /**
     * Resolve ALL mismatches for a single user by syncing in the chosen direction.
     */
    public function resolve_user( int $user_id, string $direction ): bool {
        if ( $direction === 'use_wp' ) {
            $this->engine->sync_wp_to_fcrm( $user_id );
            self::flush_cache();
            return true;
        }

        if ( $direction === 'use_fcrm' ) {
            $wp_user    = get_userdata( $user_id );
            $subscriber = $wp_user ? $this->find_subscriber_for_user( $wp_user ) : null;
            if ( $subscriber ) {
                $this->engine->sync_fcrm_to_wp( $subscriber );
                self::flush_cache();
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve only the "empty-side" mismatches for a single user.
     */
    public function resolve_user_empty_fields( int $user_id, ?Subscriber $subscriber = null ): bool {
        $wp_user = get_userdata( $user_id );
        if ( ! $wp_user ) {
            return false;
        }

        if ( ! $subscriber instanceof Subscriber ) {
            $subscriber = $this->find_subscriber_for_user( $wp_user );
        }
        if ( ! $subscriber ) {
            return false;
        }

        $mappings      = $this->mapper->get_active_mappings();
        $custom_fields = $subscriber->custom_fields();

        foreach ( $mappings as $mapping ) {
            if ( ( $mapping['sync_direction'] ?? 'both' ) !== 'both' ) {
                continue;
            }

            $mapping_id = $mapping['id'] ?? '';
            if ( ! $mapping_id ) {
                continue;
            }

            $wp_raw = $this->engine->get_wp_field_value( $user_id, $wp_user, $mapping );

            $fcrm_key = $mapping['fcrm_field_key'];
            $fcrm_src = $mapping['fcrm_field_source'] ?? 'default';
            $fcrm_raw = ( $fcrm_src === 'custom' )
                ? ( $custom_fields[ $fcrm_key ] ?? null )
                : ( $subscriber->{ $fcrm_key } ?? null );

            $wp_empty   = ( $wp_raw   === null || $wp_raw   === '' );
            $fcrm_empty = ( $fcrm_raw === null || $fcrm_raw === '' );

            if ( $wp_empty && ! $fcrm_empty ) {
                $this->resolve_field( $user_id, $mapping_id, 'use_fcrm' );
            } elseif ( ! $wp_empty && $fcrm_empty ) {
                $this->resolve_field( $user_id, $mapping_id, 'use_wp' );
            }
        }

        return true;
    }

    /**
     * Resolve empty-side mismatches across ALL users in a single pass.
     */
    public function resolve_all_empty_globally(): int {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( 300 );

        $synced = 0;
        $offset = 0;

        // Batched: `number => -1` hydrated every WP_User at once, which on a
        // 4,000-member site exhausts memory before any work is done.
        do {
            $users = get_users( [
                'fields'  => 'all',
                'number'  => self::USER_BATCH,
                'offset'  => $offset,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ] );

            if ( empty( $users ) ) {
                break;
            }

            $this->prime_subscriber_cache( $users );

            foreach ( $users as $wp_user ) {
                $subscriber = $this->find_subscriber_for_user( $wp_user );
                if ( ! $subscriber ) {
                    continue;
                }
                if ( $this->resolve_user_empty_fields( $wp_user->ID, $subscriber ) ) {
                    $synced++;
                }
            }

            $offset += count( $users );
            // Release the batch before hydrating the next one.
            $this->subscriber_cache = [];
        } while ( count( $users ) === self::USER_BATCH );

        self::flush_cache();

        return $synced;
    }

    /**
     * Resolve a single field mismatch.
     *
     * @return array{ ok: bool, steps: array<array{ text: string, status: string }> }
     */
    public function resolve_field( int $user_id, string $mapping_id, string $direction ): array {
        $mappings = $this->mapper->get_saved_mappings();
        $mapping  = null;
        foreach ( $mappings as $m ) {
            if ( ( $m['id'] ?? '' ) === $mapping_id ) {
                $mapping = $m;
                break;
            }
        }
        if ( ! $mapping ) {
            return [ 'ok' => false, 'steps' => [ [ 'text' => 'Mapping not found.', 'status' => 'error' ] ] ];
        }

        $wp_user = get_userdata( $user_id );
        if ( ! $wp_user ) {
            return [ 'ok' => false, 'steps' => [ [ 'text' => 'WordPress user not found.', 'status' => 'error' ] ] ];
        }

        $this->engine->set_syncing_to_fcrm( true );
        $this->engine->set_syncing_to_wp( true );

        try {
            return $this->do_resolve_field( $user_id, $wp_user, $mapping, $direction );
        } finally {
            $this->engine->set_syncing_to_fcrm( false );
            $this->engine->set_syncing_to_wp( false );
            // The scan cursors describe a result set this write just changed.
            self::flush_cache();
        }
    }

    /**
     * Internal implementation of single-field resolution.
     *
     * @return array{ ok: bool, steps: array<array{ text: string, status: string }> }
     */
    private function do_resolve_field( int $user_id, \WP_User $wp_user, array $mapping, string $direction ): array {
        $steps = [];
        $field_label = $mapping['wp_field_label'] ?? $mapping['wp_field_key'] ?? '?';

        if ( $direction === 'use_wp' ) {
            $raw   = $this->engine->get_wp_field_value( $user_id, $wp_user, $mapping );
            $steps[] = [
                'text'   => sprintf( 'Read WP value for "%s": %s', $field_label, $this->describe_value( $raw ) ),
                'status' => ( $raw !== null && $raw !== '' ) ? 'ok' : 'warn',
            ];

            $value = $this->engine->format_value(
                $raw,
                $mapping['field_type'] ?? 'text',
                'to_fcrm',
                $mapping
            );
            $steps[] = [
                'text'   => sprintf( 'Formatted for FluentCRM: %s', $this->describe_value( $value ) ),
                'status' => 'ok',
            ];

            $fcrm_key     = $mapping['fcrm_field_key'];
            $subscriber   = $this->find_subscriber_for_user( $wp_user );
            $lookup_email = $subscriber ? $subscriber->email : $wp_user->user_email;

            if ( ! $subscriber ) {
                $steps[] = [ 'text' => 'No linked FluentCRM subscriber found.', 'status' => 'error' ];
                return [ 'ok' => false, 'steps' => $steps ];
            }
            $steps[] = [
                'text'   => sprintf( 'Found FluentCRM contact: %s', $subscriber->email ),
                'status' => 'ok',
            ];

            if ( ( $mapping['fcrm_field_source'] ?? 'default' ) === 'custom' ) {
                $existing = $subscriber->custom_fields();
                $existing[ $fcrm_key ] = $value;
                $subscriber->custom_fields = $existing;
                $subscriber->save();
                $steps[] = [ 'text' => 'Wrote custom field to FluentCRM subscriber.', 'status' => 'ok' ];
            } else {
                if ( $fcrm_key === 'email' && $subscriber ) {
                    $conflict = Subscriber::where( 'email', $value )
                        ->where( 'id', '!=', $subscriber->id )
                        ->first();
                    if ( $conflict instanceof Subscriber ) {
                        $steps[] = [ 'text' => sprintf( 'Email "%s" already used by another contact — skipped.', $value ), 'status' => 'error' ];
                        return [ 'ok' => false, 'steps' => $steps ];
                    }
                    $subscriber->email = $value;
                    $subscriber->save();
                    $steps[] = [ 'text' => 'Updated subscriber email.', 'status' => 'ok' ];
                    return [ 'ok' => true, 'steps' => $steps ];
                }
                $data = [ 'email' => $lookup_email, $fcrm_key => $value ];
                FluentCrmApi( 'contacts' )->createOrUpdate( $data );
                $steps[] = [ 'text' => 'Wrote standard field to FluentCRM.', 'status' => 'ok' ];
            }

            $subscriber_fresh = Subscriber::where( 'id', $subscriber->id )->first();
            if ( $subscriber_fresh instanceof Subscriber ) {
                $fresh_custom = $subscriber_fresh->custom_fields();
                $fcrm_src     = $mapping['fcrm_field_source'] ?? 'default';
                $stored       = ( $fcrm_src === 'custom' )
                    ? ( $fresh_custom[ $fcrm_key ] ?? null )
                    : ( $subscriber_fresh->{ $fcrm_key } ?? null );
                $match = ( (string) $stored === (string) $value );
                $steps[] = [
                    'text'   => $match
                        ? sprintf( 'Verified: FluentCRM now stores %s', $this->describe_value( $stored ) )
                        : sprintf( 'Verification FAILED — expected %s but found %s', $this->describe_value( $value ), $this->describe_value( $stored ) ),
                    'status' => $match ? 'ok' : 'error',
                ];
                if ( ! $match ) {
                    return [ 'ok' => false, 'steps' => $steps ];
                }
            }

            return [ 'ok' => true, 'steps' => $steps ];
        }

        if ( $direction === 'use_fcrm' ) {
            $subscriber = $this->find_subscriber_for_user( $wp_user );
            if ( ! $subscriber ) {
                $steps[] = [ 'text' => 'No linked FluentCRM subscriber found.', 'status' => 'error' ];
                return [ 'ok' => false, 'steps' => $steps ];
            }
            $steps[] = [
                'text'   => sprintf( 'Found FluentCRM contact: %s', $subscriber->email ),
                'status' => 'ok',
            ];

            $fcrm_key      = $mapping['fcrm_field_key'];
            $custom_fields = $subscriber->custom_fields();
            $raw = ( ( $mapping['fcrm_field_source'] ?? 'default' ) === 'custom' )
                ? ( $custom_fields[ $fcrm_key ] ?? null )
                : ( $subscriber->{ $fcrm_key } ?? null );
            $steps[] = [
                'text'   => sprintf( 'Read FluentCRM value for "%s": %s', $field_label, $this->describe_value( $raw ) ),
                'status' => ( $raw !== null && $raw !== '' ) ? 'ok' : 'warn',
            ];

            $value = $this->engine->format_value(
                $raw,
                $mapping['field_type'] ?? 'text',
                'to_wp',
                $mapping
            );
            $steps[] = [
                'text'   => sprintf( 'Formatted for WordPress: %s', $this->describe_value( $value ) ),
                'status' => 'ok',
            ];

            $dummy_wp_user_data = [];
            $this->engine->set_wp_field_value( $user_id, $mapping, $value, $dummy_wp_user_data );
            if ( ! empty( $dummy_wp_user_data ) ) {
                $dummy_wp_user_data['ID'] = $user_id;
                wp_update_user( $dummy_wp_user_data );
                $steps[] = [ 'text' => 'Updated WordPress user object field.', 'status' => 'ok' ];
            } else {
                $steps[] = [ 'text' => 'Wrote value to WordPress user meta.', 'status' => 'ok' ];
            }

            $wp_user_fresh = get_userdata( $user_id );
            $stored        = $this->engine->get_wp_field_value( $user_id, $wp_user_fresh, $mapping );
            $stored_norm   = $this->engine->normalize_date_if_date( (string) ( $stored ?? '' ), $mapping );
            $value_norm    = $this->engine->normalize_date_if_date( (string) $value, $mapping );
            $match         = ( $stored_norm === $value_norm ) || ( (string) $stored === (string) $value );
            $steps[] = [
                'text'   => $match
                    ? sprintf( 'Verified: WordPress now stores %s', $this->describe_value( $stored ) )
                    : sprintf( 'Verification FAILED — expected %s but read back %s', $this->describe_value( $value ), $this->describe_value( $stored ) ),
                'status' => $match ? 'ok' : 'error',
            ];
            if ( ! $match ) {
                return [ 'ok' => false, 'steps' => $steps ];
            }

            return [ 'ok' => true, 'steps' => $steps ];
        }

        $steps[] = [ 'text' => 'Unknown direction: ' . $direction, 'status' => 'error' ];
        return [ 'ok' => false, 'steps' => $steps ];
    }

    private function describe_value( $val ): string {
        if ( $val === null || $val === '' ) {
            return '(empty)';
        }
        $str = is_array( $val ) ? wp_json_encode( $val ) : (string) $val;
        return '"' . ( strlen( $str ) > 80 ? substr( $str, 0, 77 ) . '…' : $str ) . '"';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function find_subscriber_for_user( \WP_User $wp_user ): ?Subscriber {
        $uid = (int) $wp_user->ID;

        if ( array_key_exists( $uid, $this->subscriber_cache ) ) {
            return $this->subscriber_cache[ $uid ];
        }

        $sub = My_IAPSNJ_Engine::find_linked_subscriber( $uid, $wp_user );
        $this->subscriber_cache[ $uid ] = $sub;

        return $sub;
    }

    private function normalise( $value, array $mapping ): string {
        if ( $value === null || $value === '' || $value === false ) {
            return '';
        }

        $type = $mapping['field_type'] ?? 'text';

        switch ( $type ) {
            case 'date':
                $normalised = $this->engine->normalize_date( (string) $value, $mapping );
                return $normalised !== '' ? $normalised : (string) $value;

            case 'checkbox':
                if ( is_string( $value ) ) {
                    $decoded = json_decode( $value, true );
                    if ( $decoded !== null ) {
                        $value = $decoded;
                    } else {
                        $value = maybe_unserialize( $value );
                    }
                }
                if ( is_array( $value ) ) {
                    sort( $value );
                    return implode( '|', $value );
                }
                return (string) $value;

            case 'number':
                return is_numeric( $value ) ? (string) (float) $value : (string) $value;

            default:
                return strtolower( trim( (string) $value ) );
        }
    }

    private function values_differ( string $a, string $b ): bool {
        return $a !== $b;
    }

    private function display_value( $value, string $type, array $mapping = [] ): string {
        if ( $value === null || $value === '' ) {
            return '(empty)';
        }

        if ( $type === 'date' ) {
            $canonical = $this->engine->normalize_date( (string) $value, $mapping );
            if ( $canonical !== '' ) {
                // gmdate(), not wp_date(): $canonical is a bare Y-m-d parsed by
                // strtotime() in UTC, so only a UTC format round-trips the same
                // calendar day back out.
                $ts = strtotime( $canonical );
                return $ts !== false ? gmdate( 'M j, Y', $ts ) : (string) $value;
            }
            return (string) $value;
        }

        if ( $type === 'checkbox' && is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                return implode( ', ', $decoded );
            }
        }
        if ( is_array( $value ) ) {
            return implode( ', ', $value );
        }
        return (string) $value;
    }

    private function get_label( array $mapping ): string {
        $wp_label   = $mapping['wp_field_label']  ?? $mapping['wp_field_key']   ?? '?';
        $fcrm_label = $mapping['fcrm_field_label'] ?? $mapping['fcrm_field_key'] ?? '?';
        return "{$wp_label} \u{2194} {$fcrm_label}";
    }
}
