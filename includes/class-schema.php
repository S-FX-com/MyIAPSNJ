<?php
/**
 * My_IAPSNJ_Schema
 *
 * The FluentCRM membership schema (docs/crm-schema.md) as code: tag slugs,
 * custom-field slugs, member types, and an idempotent ensure_crm_schema()
 * that creates whatever is missing. Everything else in the plugin refers to
 * these constants rather than repeating string literals.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;

final class My_IAPSNJ_Schema {

    // ---- Tags (slug) ------------------------------------------------------
    const TAG_PAID_PREFIX      = 'paid-';               // paid-2027 → "Paid-2027"
    const TAG_PENDING_CHECK    = 'payment-pending-check';
    const TAG_ABANDONED        = 'checkout-abandoned';
    const TAG_HONORARY         = 'honorary';
    const TAG_LIFETIME         = 'lifetime';

    // ---- Custom fields (slug) --------------------------------------------
    const FIELD_MEMBER_TYPE    = 'member_type';
    const FIELD_PAID_THROUGH   = 'paid_through';
    const FIELD_MEMBER_NUMBER  = 'member_number';
    const FIELD_DEPARTMENT     = 'department';
    const FIELD_RANK           = 'rank_level';
    const FIELD_JOIN_DATE      = 'join_date';
    const FIELD_LEGACY_LEVEL   = 'legacy_pmpro_level';

    // ---- member_type values (stored as displayed) ------------------------
    const TYPE_REGULAR   = 'Regular';
    const TYPE_ASSOCIATE = 'Associate';
    const TYPE_LIFETIME  = 'Lifetime';
    const TYPE_HONORARY  = 'Honorary';

    /** @var array<string,int> slug → tag id, per request */
    private static array $tag_cache = [];

    /**
     * All member types, in ascending "rank" order. A purchase never lowers a
     * member's type: Honorary and Lifetime stay what they are.
     */
    public static function member_types(): array {
        return [ self::TYPE_REGULAR, self::TYPE_ASSOCIATE, self::TYPE_LIFETIME, self::TYPE_HONORARY ];
    }

    public static function member_type_rank( string $type ): int {
        $rank = array_search( $type, self::member_types(), true );
        return $rank === false ? -1 : (int) $rank;
    }

    /**
     * Types that must be excluded from every dues automation.
     */
    public static function comped_types(): array {
        return [ self::TYPE_LIFETIME, self::TYPE_HONORARY ];
    }

    public static function is_comped_type( $type ): bool {
        return in_array( (string) $type, self::comped_types(), true );
    }

    public static function paid_tag_slug( int $year ): string {
        return self::TAG_PAID_PREFIX . $year;
    }

    public static function paid_tag_title( int $year ): string {
        return 'Paid-' . $year;
    }

    /**
     * Year encoded in a paid-YYYY slug, or 0.
     */
    public static function year_from_paid_slug( string $slug ): int {
        if ( preg_match( '/^' . preg_quote( self::TAG_PAID_PREFIX, '/' ) . '(\d{4})$/', $slug, $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Static (non-year) tags: slug → title.
     */
    public static function static_tags(): array {
        return [
            self::TAG_PENDING_CHECK => 'Payment-Pending-Check',
            self::TAG_ABANDONED     => 'Checkout-Abandoned',
            self::TAG_HONORARY      => 'Honorary',
            self::TAG_LIFETIME      => 'Lifetime',
        ];
    }

    /**
     * Custom fields the plugin depends on. Anything already present in
     * FluentCRM is left untouched (type/options are not rewritten).
     */
    public static function required_fields(): array {
        return [
            [
                'slug'    => self::FIELD_MEMBER_TYPE,
                'label'   => 'Member Type',
                'type'    => 'select-one',
                'options' => self::member_types(),
            ],
            [
                'slug'  => self::FIELD_PAID_THROUGH,
                'label' => 'Paid Through',
                'type'  => 'date',
            ],
            [
                'slug'  => self::FIELD_MEMBER_NUMBER,
                'label' => 'Member Number',
                'type'  => 'number',
            ],
            [
                'slug'  => self::FIELD_DEPARTMENT,
                'label' => 'Department',
                'type'  => 'text',
            ],
            [
                'slug'  => self::FIELD_RANK,
                'label' => 'Rank',
                'type'  => 'text',
            ],
            [
                'slug'  => self::FIELD_JOIN_DATE,
                'label' => 'Join Date',
                'type'  => 'date',
            ],
            [
                'slug'  => self::FIELD_LEGACY_LEVEL,
                'label' => 'Legacy PMPro Level',
                'type'  => 'text',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Ensure
    // -----------------------------------------------------------------------

    /**
     * Create missing tags and custom fields. Idempotent.
     *
     * @param int[] $years Paid-YYYY tags to guarantee (e.g. range(2024, 2027)).
     * @return array{tags_created: string[], fields_created: string[]}
     */
    public static function ensure_crm_schema( array $years = [] ): array {
        $report = [ 'tags_created' => [], 'fields_created' => [] ];

        // Tags.
        $wanted = self::static_tags();
        foreach ( $years as $y ) {
            $y = (int) $y;
            if ( $y > 1900 ) {
                $wanted[ self::paid_tag_slug( $y ) ] = self::paid_tag_title( $y );
            }
        }
        $existing = [];
        foreach ( Tag::whereIn( 'slug', array_keys( $wanted ) )->get() as $t ) {
            $existing[ $t->slug ] = (int) $t->id;
        }
        $to_create = [];
        foreach ( $wanted as $slug => $title ) {
            if ( ! isset( $existing[ $slug ] ) ) {
                $to_create[] = [ 'slug' => $slug, 'title' => $title ];
            }
        }
        if ( $to_create ) {
            FluentCrmApi( 'tags' )->importBulk( $to_create );
            $report['tags_created'] = array_column( $to_create, 'slug' );
        }
        self::$tag_cache = [];

        // Custom fields.
        $fields = fluentcrm_get_option( 'contact_custom_fields', [] );
        if ( ! is_array( $fields ) ) {
            $fields = [];
        }
        $have = [];
        foreach ( $fields as $f ) {
            if ( ! empty( $f['slug'] ) ) {
                $have[ $f['slug'] ] = true;
            }
        }
        $added = false;
        foreach ( self::required_fields() as $def ) {
            if ( isset( $have[ $def['slug'] ] ) ) {
                continue;
            }
            $fields[] = array_merge( [ 'group' => 'default' ], $def );
            $report['fields_created'][] = $def['slug'];
            $added = true;
        }
        if ( $added ) {
            fluentcrm_update_option( 'contact_custom_fields', array_values( $fields ) );
        }

        return $report;
    }

    /**
     * Resolve tag slugs to IDs, creating missing tags on the way.
     *
     * @param string[] $slugs
     * @return array<string,int>
     */
    public static function tag_ids( array $slugs ): array {
        $slugs  = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
        $result = [];
        $missing = [];
        foreach ( $slugs as $slug ) {
            if ( isset( self::$tag_cache[ $slug ] ) ) {
                $result[ $slug ] = self::$tag_cache[ $slug ];
            } else {
                $missing[] = $slug;
            }
        }
        if ( $missing ) {
            foreach ( Tag::whereIn( 'slug', $missing )->get() as $t ) {
                self::$tag_cache[ $t->slug ] = (int) $t->id;
                $result[ $t->slug ]          = (int) $t->id;
            }
            $create = [];
            foreach ( $missing as $slug ) {
                if ( isset( $result[ $slug ] ) ) {
                    continue;
                }
                $year    = self::year_from_paid_slug( $slug );
                $statics = self::static_tags();
                $title   = $year ? self::paid_tag_title( $year ) : ( $statics[ $slug ] ?? ucwords( str_replace( '-', ' ', $slug ) ) );
                $create[] = [ 'slug' => $slug, 'title' => $title ];
            }
            if ( $create ) {
                foreach ( FluentCrmApi( 'tags' )->importBulk( $create ) as $t ) {
                    self::$tag_cache[ $t->slug ] = (int) $t->id;
                    $result[ $t->slug ]          = (int) $t->id;
                }
            }
        }
        return $result;
    }

    /**
     * Slugs of the plugin-managed tags currently on a contact.
     *
     * @return string[]
     */
    public static function managed_tag_slugs( Subscriber $subscriber ): array {
        $out = [];
        foreach ( $subscriber->tags()->get() as $tag ) {
            $slug = (string) $tag->slug;
            if ( self::year_from_paid_slug( $slug ) || isset( self::static_tags()[ $slug ] ) ) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /**
     * Years for which the contact carries a Paid-YYYY tag, ascending.
     *
     * @return int[]
     */
    public static function paid_years( Subscriber $subscriber ): array {
        $years = [];
        foreach ( self::managed_tag_slugs( $subscriber ) as $slug ) {
            $y = self::year_from_paid_slug( $slug );
            if ( $y ) {
                $years[] = $y;
            }
        }
        sort( $years );
        return $years;
    }

    /**
     * Read one custom field value from a contact ('' when unset).
     */
    public static function field( Subscriber $subscriber, string $slug ): string {
        $values = $subscriber->custom_fields();
        $v      = $values[ $slug ] ?? '';
        if ( is_array( $v ) ) {
            $v = implode( ', ', $v );
        }
        return (string) $v;
    }

    /**
     * Write custom field values. Empty string deletes the value (this is how
     * paid_through is set to null for Lifetime / Honorary members).
     *
     * @param array<string,string> $values
     */
    public static function set_fields( Subscriber $subscriber, array $values ): void {
        if ( empty( $values ) ) {
            return;
        }
        $subscriber->syncCustomFieldValues( $values, true );
    }
}
