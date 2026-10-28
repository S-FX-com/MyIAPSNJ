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
    // Status tag: present while the membership is in good standing (paid
    // through today or later, or comped). Added on payment, removed by the
    // daily expiry job. Paid-YYYY tags are history and are never removed.
    const TAG_ACTIVE           = 'member-active';

    // ---- Custom fields (slug) --------------------------------------------
    const FIELD_MEMBER_TYPE    = 'member_type';
    const FIELD_PAID_THROUGH   = 'paid_through';
    const FIELD_MEMBER_NUMBER  = 'member_number';
    const FIELD_DEPARTMENT     = 'department';
    // The CRM's own Rank dropdown (select-one); earlier plugin versions wrote a
    // duplicate text field, rank_level (see legacy_slugs()).
    const FIELD_RANK           = 'iapsnj_rank';
    const FIELD_JOIN_DATE      = 'join_date';
    const FIELD_LEGACY_LEVEL   = 'legacy_pmpro_level';
    const FIELD_RETIREMENT_DATE = 'retirement_date';
    const FIELD_REFERRED_BY    = 'referred_by';
    // Profile fields collected by the checkout application (ACF-era slugs,
    // already populated in the CRM by the pre-4.0 sync for existing members).
    const FIELD_UNION_AFFILIATION = 'union_affiliation';
    const FIELD_UNION_POSITION = 'union_position';
    const FIELD_MARITAL_STATUS = 'marital_status';
    const FIELD_SPOUSE_NAME    = 'spouse';
    const FIELD_ARMED_SERVICE  = 'armed_services'; // "Veteran Status"
    const FIELD_ANCESTRY       = 'explain_your_ancestry';
    const FIELD_ADDITIONAL_INFO = 'additional_information';
    const FIELD_COMPANY_NAME   = 'company_name';
    const FIELD_COMPANY_TITLE  = 'company_title';
    const FIELD_COMPANY_TYPE   = 'company_type';
    const FIELD_ELO_TITLE      = 'elo_title';

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
            self::TAG_ACTIVE        => 'Member-Active',
        ];
    }

    /**
     * First paid_through date (Y-m-d) that still counts as active today:
     * today (site timezone) minus the grace period from the settings. A
     * non-comped membership is active while paid_through >= this date, the
     * same test as "paid_through + grace days is not before today". The
     * member lists compare against it in SQL, so they use exactly the same
     * boundary as the expiry job and the role code.
     */
    public static function active_cutoff(): string {
        $today = My_IAPSNJ_Dates::today();
        $grace = max( 0, (int) ( My_IAPSNJ_Plugin::settings()['expiry_grace_days'] ?? 0 ) );
        if ( $grace === 0 ) {
            return $today;
        }
        $ts = strtotime( $today . ' 00:00:00 UTC' );
        return $ts !== false ? gmdate( 'Y-m-d', $ts - $grace * DAY_IN_SECONDS ) : $today;
    }

    /**
     * Is a membership in good standing? Comped types always; otherwise
     * paid_through must be on or after active_cutoff() (today minus the
     * grace period). '' / invalid paid_through = not active.
     */
    public static function is_active_state( string $type, string $paid_through ): bool {
        if ( self::is_comped_type( $type ) ) {
            return true;
        }
        $ymd = My_IAPSNJ_Dates::ymd( $paid_through );
        return $ymd !== '' && strcmp( $ymd, self::active_cutoff() ) >= 0;
    }

    /**
     * Membership-state custom fields: set by payments and the migration only,
     * never offered on (or written from) the checkout application.
     *
     * @return string[]
     */
    public static function system_fields(): array {
        return [
            self::FIELD_MEMBER_TYPE,
            self::FIELD_PAID_THROUGH,
            self::FIELD_MEMBER_NUMBER,
            self::FIELD_JOIN_DATE,
            self::FIELD_LEGACY_LEVEL,
        ];
    }

    /**
     * Custom fields the checkout no longer collects (4.18, client request:
     * the contact's Phone, asked as "Cell phone", is the one primary
     * number). The values already stored stay on the contacts; the fields
     * are never offered on, or written from, a checkout form.
     *
     * @return string[]
     */
    public static function retired_fields(): array {
        // Work phone and Alternate phone: dropped altogether (4.29). The
        // plugin neither collects, formats nor creates them; the slugs are
        // listed only so a CRM that still has the fields never offers them.
        return [ 'phone_work', 'phone2' ];
    }

    /**
     * CRM slugs earlier plugin versions wrote to that duplicated a field the
     * CRM already had: old slug => the slug to use. The upgrade copies the
     * values over (My_IAPSNJ_Plugin::maybe_upgrade, v15); the old fields can
     * then be deleted in FluentCRM.
     *
     * @return array<string,string>
     */
    public static function legacy_slugs(): array {
        return [
            'rank_level'  => self::FIELD_RANK,
            'spouse_name' => self::FIELD_SPOUSE_NAME,
            'armed_service' => self::FIELD_ARMED_SERVICE,
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
            [
                'slug'  => self::FIELD_RETIREMENT_DATE,
                'label' => 'Retirement Date',
                'type'  => 'date',
            ],
            [
                'slug'  => self::FIELD_REFERRED_BY,
                'label' => 'Referred By',
                'type'  => 'text',
            ],
            [
                'slug'  => self::FIELD_UNION_AFFILIATION,
                'label' => 'Union Affiliation',
                'type'  => 'text',
            ],
            [
                'slug'  => self::FIELD_UNION_POSITION,
                'label' => 'Union Position',
                'type'  => 'text',
            ],
            [
                'slug'    => self::FIELD_MARITAL_STATUS,
                'label'   => 'Marital Status',
                'type'    => 'select-one',
                'options' => self::marital_status_options(),
            ],
            [
                'slug'  => self::FIELD_SPOUSE_NAME,
                'label' => 'Spouse',
                'type'  => 'text',
            ],
            [
                'slug'    => self::FIELD_ARMED_SERVICE,
                'label'   => 'Veteran Status',
                'type'    => 'checkbox',
                'options' => [ 'Yes' ],
            ],
            [
                'slug'  => self::FIELD_ANCESTRY,
                'label' => 'Explain Your Ancestry',
                'type'  => 'textarea',
            ],
            [
                'slug'  => self::FIELD_ADDITIONAL_INFO,
                'label' => 'Additional Information',
                'type'  => 'textarea',
            ],
            [
                'slug'  => self::FIELD_COMPANY_NAME,
                'label' => 'Company Name',
                'type'  => 'text',
            ],
            [
                'slug'  => self::FIELD_COMPANY_TITLE,
                'label' => 'Company Title',
                'type'  => 'text',
            ],
            [
                'slug'  => self::FIELD_COMPANY_TYPE,
                'label' => 'Company Type',
                'type'  => 'text',
            ],
            [
                'slug'  => self::FIELD_ELO_TITLE,
                'label' => 'ELO Title',
                'type'  => 'text',
            ],
        ];
    }

    /**
     * Marital status choices offered on the checkout and in the CRM field.
     *
     * @return string[]
     */
    public static function marital_status_options(): array {
        return [ 'Single', 'Married', 'Divorced', 'Widowed', 'Separated' ];
    }

    /**
     * Fallback rank list for the checkout dropdown when neither the ACF
     * field nor the CRM data supplies one (see
     * My_IAPSNJ_Checkout_Fields::discover_options()).
     *
     * @return string[]
     */
    public static function default_rank_options(): array {
        return [
            'Police Officer / Patrolman',
            'Detective',
            'Corporal',
            'Sergeant',
            'Lieutenant',
            'Captain',
            'Deputy Chief',
            'Chief',
            'Investigator',
            'Sheriff\'s Officer',
            'Undersheriff',
            'Sheriff',
            'Corrections Officer',
            'State Trooper',
            'Retired',
            'Civilian',
        ];
    }

    /**
     * Type of a FluentCRM custom field by slug ('' when the field does not
     * exist): text, textarea, number, date, select-one, select-multi, radio,
     * checkbox …
     */
    public static function custom_field_type( string $slug ): string {
        foreach ( (array) fluentcrm_get_option( 'contact_custom_fields', [] ) as $f ) {
            if ( is_array( $f ) && ( $f['slug'] ?? '' ) === $slug ) {
                return (string) ( $f['type'] ?? 'text' );
            }
        }
        return '';
    }

    /**
     * Required custom fields the CRM does not have yet (all of them, or only
     * the slugs in $only). FluentCRM drops a value written to a field that
     * does not exist without saying so.
     *
     * @param string[] $only
     * @return string[] slugs
     */
    public static function missing_custom_fields( array $only = [] ): array {
        $fields = fluentcrm_get_option( 'contact_custom_fields', [] );
        $have   = [];
        foreach ( is_array( $fields ) ? $fields : [] as $f ) {
            if ( ! empty( $f['slug'] ) ) {
                $have[ $f['slug'] ] = true;
            }
        }
        $missing = [];
        foreach ( self::required_fields() as $def ) {
            if ( ( $only && ! in_array( $def['slug'], $only, true ) ) || isset( $have[ $def['slug'] ] ) ) {
                continue;
            }
            $missing[] = $def['slug'];
        }
        return $missing;
    }

    /**
     * Create the custom fields the plugin depends on (no tags). Idempotent;
     * existing fields are never modified.
     *
     * @return string[] slugs created
     */
    public static function ensure_custom_fields(): array {
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
        $created = [];
        foreach ( self::required_fields() as $def ) {
            if ( isset( $have[ $def['slug'] ] ) ) {
                continue;
            }
            $fields[]  = array_merge( [ 'group' => 'default' ], $def );
            $created[] = $def['slug'];
        }
        if ( $created ) {
            fluentcrm_update_option( 'contact_custom_fields', array_values( $fields ) );
        }
        return $created;
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
        $report['fields_created'] = self::ensure_custom_fields();

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

    // -----------------------------------------------------------------------
    // Department = FluentCRM's Companies module
    // -----------------------------------------------------------------------

    /**
     * Is FluentCRM's Companies module on? The site uses it for departments
     * (the CRM labels it "Departments"): a contact's primary company is their
     * department. The `department` custom field is kept in step with it.
     */
    public static function company_enabled(): bool {
        try {
            return class_exists( '\FluentCrm\App\Models\Company' )
                && class_exists( '\FluentCrm\App\Services\Helper' )
                && \FluentCrm\App\Services\Helper::isCompanyEnabled();
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Names of all companies (departments), alphabetical; [] when the module
     * is off or has none.
     *
     * @return string[]
     */
    public static function company_names(): array {
        if ( ! self::company_enabled() ) {
            return [];
        }
        try {
            $names = \FluentCrm\App\Models\Company::orderBy( 'name', 'asc' )->pluck( 'name' )->toArray();
        } catch ( \Throwable $e ) {
            return [];
        }
        return array_values( array_filter( array_map( 'trim', array_map( 'strval', $names ) ), 'strlen' ) );
    }

    /**
     * A contact's department: the name of their primary company when the
     * module is on and they have one, else the `department` custom field.
     */
    public static function department_of( Subscriber $subscriber ): string {
        if ( self::company_enabled() ) {
            try {
                $company = $subscriber->company;
                $name    = is_object( $company ) ? trim( (string) $company->name ) : '';
                if ( $name !== '' ) {
                    return $name;
                }
            } catch ( \Throwable $e ) {
                // fall back to the field
            }
        }
        return self::field( $subscriber, self::FIELD_DEPARTMENT );
    }

    /**
     * Make the company called $name the contact's primary company (their
     * department), replacing the previous primary one. A name that is not a
     * company yet is created: the member looked the department up in the list
     * before choosing "Other (not listed)". An existing company is found
     * regardless of capitals ("newark pd" is "Newark PD"), so a typed variant
     * of a listed name reuses it.
     *
     * @return bool whether the contact's company changed
     */
    public static function set_department( Subscriber $subscriber, string $name ): bool {
        $name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
        if ( $name === '' || ! self::company_enabled() ) {
            return false;
        }
        try {
            $company = \FluentCrm\App\Models\Company::where( 'name', $name )->first();
            if ( ! $company ) {
                $company = \FluentCrm\App\Models\Company::create( [ 'name' => $name ] );
            }
            $new = $company ? (int) $company->id : 0;
            if ( $new < 1 ) {
                return false;
            }
            // The custom field carries the company's own spelling, so the two never differ.
            self::set_fields( $subscriber, [ self::FIELD_DEPARTMENT => (string) $company->name ] );
            $old = (int) $subscriber->company_id;
            if ( $old === $new ) {
                $subscriber->attachCompanies( [ $new ] ); // the pivot row, if it was missing
                return false;
            }
            $subscriber->company_id = $new;
            $subscriber->save();
            $subscriber->attachCompanies( [ $new ] );
            if ( $old > 0 ) {
                $subscriber->detachCompanies( [ $old ] );
            }
            return true;
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: could not set the department company for contact #' . (int) $subscriber->id . ': ' . $e->getMessage() );
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Values written under an old slug
    // -----------------------------------------------------------------------

    /**
     * Copy values stored under a legacy slug to the slug the CRM already had
     * (legacy_slugs()), for the contacts that have nothing there yet. The
     * legacy values are left in place. Direct table writes, no automations.
     *
     * @return int rows copied or filled in
     */
    public static function copy_legacy_values(): int {
        global $wpdb;
        $meta  = $wpdb->prefix . 'fc_subscriber_meta';
        $total = 0;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- identifiers are the plugin's own, values are prepared
        foreach ( self::legacy_slugs() as $old => $new ) {
            // Contacts whose new field exists but is empty.
            $filled = $wpdb->query( $wpdb->prepare(
                "UPDATE {$meta} n INNER JOIN {$meta} o ON o.subscriber_id = n.subscriber_id AND o.object_type = 'custom_field' AND o.`key` = %s AND TRIM(COALESCE(o.`value`, '')) <> ''
                 SET n.`value` = o.`value`
                 WHERE n.object_type = 'custom_field' AND n.`key` = %s AND TRIM(COALESCE(n.`value`, '')) = ''",
                $old,
                $new
            ) );
            // Contacts with no row for the new field at all.
            $added = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$meta} (subscriber_id, created_by, object_type, `key`, `value`, created_at, updated_at)
                 SELECT o.subscriber_id, 0, 'custom_field', %s, MAX(o.`value`), NOW(), NOW()
                 FROM {$meta} o
                 WHERE o.object_type = 'custom_field' AND o.`key` = %s AND TRIM(COALESCE(o.`value`, '')) <> ''
                   AND NOT EXISTS (SELECT 1 FROM {$meta} n WHERE n.subscriber_id = o.subscriber_id AND n.object_type = 'custom_field' AND n.`key` = %s)
                 GROUP BY o.subscriber_id",
                $new,
                $old,
                $new
            ) );
            $total += max( 0, (int) $filled ) + max( 0, (int) $added );
        }
        // phpcs:enable
        return $total;
    }

    /**
     * US states written as codes ("NJ") become full names ("New Jersey") on
     * contacts whose country is the US or empty. Idempotent; direct table
     * write, no automations (like the Names & addresses tool).
     *
     * @return int contacts changed
     */
    public static function expand_state_codes(): int {
        global $wpdb;
        $subs  = $wpdb->prefix . 'fc_subscribers';
        $cases = '';
        $codes = [];
        foreach ( My_IAPSNJ_States::names() as $code => $name ) {
            $cases  .= $wpdb->prepare( ' WHEN %s THEN %s', $code, $name );
            $codes[] = $code;
        }
        $in  = implode( ', ', array_fill( 0, count( $codes ), '%s' ) );
        $sql = "UPDATE {$subs} SET state = CASE UPPER(TRIM(state)){$cases} ELSE state END
                WHERE UPPER(TRIM(state)) IN ({$in})
                  AND (country IS NULL OR TRIM(country) = '' OR UPPER(TRIM(country)) IN ('US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'))";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the CASE values and the IN list are prepared
        $changed = $wpdb->query( $wpdb->prepare( $sql, $codes ) );
        return (int) $changed;
    }
}
