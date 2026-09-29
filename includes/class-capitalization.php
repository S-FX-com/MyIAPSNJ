<?php
/**
 * Capitalisation of names and mailing addresses: one rule for everything the
 * plugin stores, so mailing-label exports read "John McDonald, 12 Main St
 * Apt 4B, Mt Laurel, NJ" whatever the member typed.
 *
 * CSS text-transform only changes how a value looks, not what is saved, so
 * the value itself is rewritten: on the checkout when a field loses focus
 * (the member sees the stored form, and FluentCart stores it), on the server
 * before FluentCart saves the order (a member with scripts off), before the
 * CRM contact is written, and by the bulk tool for contacts already in the
 * CRM (Configurations → Names & addresses, `wp iapsnj capitalize`).
 *
 * Rules
 * - A word typed in lower case, or any word of a value typed entirely in
 *   capitals, is capitalised: "john smith", "JOHN SMITH" → "John Smith".
 * - A word typed in mixed case is kept as typed: McDonald, DeLuca, LaSalle.
 * - Mc and O' / D' / L' prefixes: "mcdonald" → "McDonald", "o'brien" →
 *   "O'Brien". "Mac" is not touched (Mack, Macy, Machado are not MacX).
 * - Every part of a hyphenated or dotted word starts with a capital:
 *   "smith-jones" → "Smith-Jones", "j.r." → "J.R.".
 * - Names: suffixes II, III, IV … in capitals after the first word, "jr" →
 *   "Jr"; two consonants are initials ("tj" → "TJ"), except Jr, Sr, St, Ng,
 *   Mc, Mr, Ms, Dr.
 * - Streets: "po box" / "p.o. box" → "PO Box"; letters next to digits are a
 *   unit ("4b" → "4B", "#12a" → "#12A") except ordinals ("1st", "22nd");
 *   PO, US, NJ, CR, RR, NE, NW, SE, SW stay in capitals.
 * - Streets and cities: "of", "the", "and" stay lower case after the first
 *   word ("Avenue of the Americas", "Township of Ocean").
 * - In a value typed in mixed case, a word of up to three capitals is kept
 *   in a street or city (an acronym: JFK Blvd, MLK Ave) unless it is a
 *   street word (APT, ST, AVE …); in a name it is fixed ("John SMITH").
 * - State (CRM only; FluentCart keeps its own state codes): two letters →
 *   capitals ("nj" → "NJ"), any other single token is kept (NSW), a
 *   spelled-out state is capitalised like a city.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Capitalization {

    const NAME   = 'name';
    const STREET = 'street';
    const CITY   = 'city';
    const STATE  = 'state';

    /** @var string[] name suffixes written in capitals */
    const NUMERALS = [ 'ii', 'iii', 'iv', 'vi', 'vii', 'viii' ];

    /** @var string[] two consonants that are a word, not initials */
    const NOT_INITIALS = [ 'jr', 'sr', 'st', 'ng', 'mc', 'mr', 'ms', 'dr' ];

    /** @var string[] kept in capitals in a street address */
    const STREET_UPPER = [ 'po', 'us', 'nj', 'ny', 'pa', 'cr', 'rr', 'ne', 'nw', 'se', 'sw', 'apo', 'fpo' ];

    /** @var string[] street words fixed even when typed in capitals in a mixed-case value */
    const STREET_WORDS = [ 'apt', 'ste', 'st', 'ave', 'av', 'rd', 'dr', 'ln', 'ct', 'pl', 'blvd', 'hwy', 'rte', 'rt', 'box', 'fl', 'flr', 'bldg', 'ter', 'cir', 'pkwy', 'way', 'sq', 'trl', 'unit', 'lot', 'rm', 'twp', 'mt', 'ft', 'pt' ];

    /** @var string[] lower case after the first word of a street or city */
    const SMALL_WORDS = [ 'of', 'the', 'and' ];

    public static function name( string $value ): string {
        return self::apply( $value, self::NAME );
    }

    public static function street( string $value ): string {
        return self::apply( $value, self::STREET );
    }

    public static function city( string $value ): string {
        return self::apply( $value, self::CITY );
    }

    public static function state( string $value ): string {
        return self::apply( $value, self::STATE );
    }

    /**
     * The value with the capitalisation rules of $kind applied; runs of
     * spaces become one. '' stays ''. Applying it again changes nothing.
     * A value that is not valid UTF-8 is returned unchanged.
     */
    public static function apply( string $value, string $kind ): string {
        if ( $value === '' || ! preg_match( '//u', $value ) ) {
            return $value;
        }
        // One pass decides "typed in mixed case" before it upper-cases unit
        // letters, initials and suffixes, so a second pass could see a value
        // in capitals; repeat until nothing changes (two passes in practice).
        $out = self::apply_once( $value, $kind );
        for ( $i = 0; $i < 3; $i++ ) {
            $next = self::apply_once( $out, $kind );
            if ( $next === $out ) {
                break;
            }
            $out = $next;
        }
        return $out;
    }

    private static function apply_once( string $value, string $kind ): string {
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        if ( $value === '' ) {
            return '';
        }
        if ( $kind === self::STATE ) {
            // FluentCart stores a state as a code from its list (NJ, NSW,
            // QLD, maagd …): two letters are upper-cased, any other single
            // token is kept; only a spelled-out name is capitalised.
            if ( preg_match( '/^[a-z]{2}$/i', $value ) ) {
                return strtoupper( $value );
            }
            if ( strpos( $value, ' ' ) === false ) {
                return $value;
            }
            $kind = self::CITY;
        }
        // A value typed all in capitals (or all in lower case) carries no
        // case information, so every word is rewritten. One typed in mixed
        // case keeps the words the member capitalised on purpose. Words the
        // rules always put in capitals (4B, PO, III) do not count; the
        // "PO Box" this pass writes is not the member's typing either.
        $letters = '';
        foreach ( explode( ' ', $value ) as $i => $word ) {
            $bare = trim( self::lower( $word ), '.,#' );
            if ( preg_match( '/\d/', $word )
                || ( $kind === self::STREET && in_array( $bare, self::STREET_UPPER, true ) )
                || ( $kind === self::NAME && $i > 0 && in_array( $bare, self::NUMERALS, true ) ) ) {
                continue;
            }
            $letters .= $word;
        }
        $mixed = (bool) preg_match( '/\p{Lu}/u', $letters ) && (bool) preg_match( '/\p{Ll}/u', $letters );
        if ( $kind === self::STREET ) {
            $value = (string) preg_replace( '/\bp\.?\s*o\.?\s*box\b/i', 'PO Box', $value );
        }
        $words = explode( ' ', $value );
        foreach ( $words as $i => $word ) {
            $words[ $i ] = self::word( $word, $kind, $i === 0, $mixed );
        }
        return implode( ' ', $words );
    }

    private static function word( string $word, string $kind, bool $first, bool $mixed ): string {
        if ( ! preg_match( '/\p{L}/u', $word ) ) {
            return $word; // "12", "#", "&"
        }
        if ( ! function_exists( 'mb_strtolower' ) && preg_match( '/[^\x00-\x7F]/', $word ) ) {
            return $word; // without mbstring, accented letters cannot be re-cased safely
        }
        if ( preg_match( '/\d/', $word ) ) {
            // "4b", "1st", "NJ-35": addresses only; a name with digits is left alone.
            return $kind === self::NAME ? $word : self::with_digits( self::lower( $word ) );
        }
        $lower = self::lower( $word );
        if ( $word === $lower ) {
            return self::fix( $lower, $kind, $first );
        }
        if ( $word !== self::upper( $word ) ) {
            return $word; // typed in mixed case: McDonald, DeLuca, O'Brien
        }
        // A word in capitals. In a value typed all in capitals it is just
        // caps lock; in a mixed-case street or city a short one is an acronym.
        if ( $mixed && $kind !== self::NAME ) {
            $bare = trim( $lower, '.,#' );
            if ( self::letter_count( $word ) <= 3 && ! in_array( $bare, self::STREET_WORDS, true ) ) {
                return $word;
            }
        }
        return self::fix( $lower, $kind, $first );
    }

    /**
     * @param string $w the word in lower case, without digits
     */
    private static function fix( string $w, string $kind, bool $first ): string {
        $bare = trim( $w, '.,#' );
        if ( $kind === self::NAME ) {
            // A suffix follows the name ("Smith III"); a first word "Vi" is a name.
            if ( ! $first && in_array( $bare, self::NUMERALS, true ) ) {
                return self::upper( $w );
            }
            if ( preg_match( '/^[bcdfghjklmnpqrstvwxz]{2}$/', $bare ) && ! in_array( $bare, self::NOT_INITIALS, true ) ) {
                return self::upper( $w ); // TJ, JD, CJ
            }
        } else {
            if ( $kind === self::STREET && in_array( $bare, self::STREET_UPPER, true ) ) {
                return self::upper( $w );
            }
            if ( ! $first && in_array( $w, self::SMALL_WORDS, true ) ) {
                return $w;
            }
        }
        // Each part after a hyphen, slash or period starts with a capital.
        $parts = preg_split( '/([\-\/.])/u', $w, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) {
            return self::ucfirst( $w );
        }
        foreach ( $parts as $i => $part ) {
            if ( $i % 2 === 0 ) {
                $parts[ $i ] = self::part( $part );
            }
        }
        return implode( '', $parts );
    }

    private static function part( string $p ): string {
        if ( $p === '' ) {
            return '';
        }
        // O'Brien, D'Angelo, L'Enfant: one letter, an apostrophe, the name.
        if ( preg_match( "/^(\p{L})(['\x{2019}])(\p{L}{2}.*)$/u", $p, $m ) ) {
            return self::upper( $m[1] ) . $m[2] . self::ucfirst( $m[3] );
        }
        // McDonald, McAfee.
        if ( preg_match( '/^mc(\p{L}{2}.*)$/u', $p, $m ) ) {
            return 'Mc' . self::ucfirst( $m[1] );
        }
        return self::ucfirst( $p );
    }

    /**
     * An address word with digits: ordinals keep a lower-case ending
     * ("1st", "22nd"), any other letters are a unit or route ("4B", "NJ-35").
     */
    private static function with_digits( string $w ): string {
        if ( preg_match( '/^\d+(st|nd|rd|th)[.,]?$/', $w ) ) {
            return $w;
        }
        return self::upper( $w );
    }

    private static function letter_count( string $word ): int {
        return (int) preg_match_all( '/\p{L}/u', $word );
    }

    private static function lower( string $s ): string {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
    }

    private static function upper( string $s ): string {
        return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
    }

    private static function ucfirst( string $s ): string {
        if ( $s === '' ) {
            return '';
        }
        if ( ! function_exists( 'mb_substr' ) ) {
            return ucfirst( $s );
        }
        return self::upper( mb_substr( $s, 0, 1, 'UTF-8' ) ) . mb_substr( $s, 1, null, 'UTF-8' );
    }

    // -----------------------------------------------------------------------
    // FluentCart records (customer, saved addresses, order addresses)
    // -----------------------------------------------------------------------

    /** @var array{0:string,1:string}|null First / Last name posted by this place-order request */
    private static ?array $posted_names = null;

    /**
     * FluentCart checkout inputs and the rule each one follows.
     *
     * @return array<string,string>
     */
    public static function checkout_inputs(): array {
        $out = [];
        foreach ( [ 'billing_', 'shipping_' ] as $prefix ) {
            $out[ $prefix . 'first_name' ] = self::NAME;
            $out[ $prefix . 'last_name' ]  = self::NAME;
            $out[ $prefix . 'full_name' ]  = self::NAME;
            $out[ $prefix . 'address_1' ]  = self::STREET;
            $out[ $prefix . 'address_2' ]  = self::STREET;
            $out[ $prefix . 'city' ]       = self::CITY;
        }
        return $out;
    }

    /**
     * Hooks that make FluentCart store the capitalised values. Called once
     * FluentCart is active.
     */
    public static function register_fluentcart_hooks(): void {
        // FluentCart copies the request when it loads, so the posted values
        // are fixed in its request object (not in $_POST) before the
        // place-order handler reads them.
        add_action( 'wp_ajax_fluent_cart_place_order',        [ __CLASS__, 'capitalize_request' ], 1 );
        add_action( 'wp_ajax_nopriv_fluent_cart_place_order', [ __CLASS__, 'capitalize_request' ], 1 );
        // Saved addresses of a logged-in customer replace the posted ones,
        // and FluentCart re-splits the name; the model events catch every
        // write (checkout, customer portal, admin edits).
        if ( did_action( 'fluentcart_loaded' ) ) {
            self::register_model_events();
        } else {
            add_action( 'fluentcart_loaded', [ __CLASS__, 'register_model_events' ] );
        }
    }

    /**
     * wp_ajax(_nopriv)_fluent_cart_place_order, priority 1.
     */
    public static function capitalize_request(): void {
        if ( ! class_exists( '\FluentCart\App\App' ) ) {
            return;
        }
        try {
            $request = \FluentCart\App\App::request();
            $all     = (array) $request->all();
            $fixed   = [];
            foreach ( self::checkout_inputs() as $name => $kind ) {
                if ( isset( $all[ $name ] ) && is_string( $all[ $name ] ) && trim( $all[ $name ] ) !== '' ) {
                    $to = self::apply( $all[ $name ], $kind );
                    if ( $to !== $all[ $name ] && $to !== '' ) {
                        $fixed[ $name ] = $to;
                    }
                }
            }
            if ( $fixed ) {
                $request->merge( $fixed );
            }
            $first = trim( (string) ( $fixed['billing_first_name'] ?? ( $all['billing_first_name'] ?? '' ) ) );
            $last  = trim( (string) ( $fixed['billing_last_name'] ?? ( $all['billing_last_name'] ?? '' ) ) );
            self::$posted_names = ( $first !== '' || $last !== '' ) ? [ $first, $last ] : null;
        } catch ( \Throwable $e ) {
            // leave the request as posted; the model events still apply
        }
    }

    /**
     * `saving` listeners on FluentCart's Customer, CustomerAddresses and
     * OrderAddress models: attributes changed there are the ones written.
     * A listener never returns false (that would cancel the save).
     */
    public static function register_model_events(): void {
        // No state here: FluentCart stores a code from its own list (NJ,
        // NSW, maagd …) and matches shipping zones and names on it.
        $models = [
            'FluentCart\App\Models\Customer'          => [ 'first_name' => self::NAME, 'last_name' => self::NAME, 'city' => self::CITY ],
            'FluentCart\App\Models\CustomerAddresses' => [ 'name' => self::NAME, 'address_1' => self::STREET, 'address_2' => self::STREET, 'city' => self::CITY ],
            'FluentCart\App\Models\OrderAddress'      => [ 'name' => self::NAME, 'address_1' => self::STREET, 'address_2' => self::STREET, 'city' => self::CITY ],
        ];
        foreach ( $models as $class => $columns ) {
            if ( ! class_exists( $class ) || ! method_exists( $class, 'saving' ) ) {
                continue;
            }
            $is_customer = $class === 'FluentCart\App\Models\Customer';
            $class::saving( function ( $model ) use ( $columns, $is_customer ) {
                try {
                    foreach ( $columns as $col => $kind ) {
                        $value = $model->getAttribute( $col );
                        if ( is_string( $value ) && trim( $value ) !== '' ) {
                            $to = self::apply( $value, $kind );
                            if ( $to !== $value && $to !== '' ) {
                                $model->setAttribute( $col, $to );
                            }
                        }
                    }
                    if ( $is_customer ) {
                        self::restore_typed_names( $model );
                    }
                } catch ( \Throwable $e ) {
                    // never block FluentCart's save
                }
            } );
        }
    }

    /**
     * FluentCart joins First + Last name and splits them again at the last
     * space ("Mary" + "Van Dyke" → "Mary Van" / "Dyke", "John" + "Smith Jr"
     * → "John Smith" / "Jr"). When the customer being saved carries the
     * same full name the member typed, put the typed split back.
     *
     * @param object $model FluentCart Customer
     */
    private static function restore_typed_names( $model ): void {
        if ( self::$posted_names === null ) {
            return;
        }
        [ $first, $last ] = self::$posted_names;
        $saved = trim( (string) $model->getAttribute( 'first_name' ) . ' ' . (string) $model->getAttribute( 'last_name' ) );
        $typed = trim( $first . ' ' . $last );
        $same  = strcasecmp( (string) preg_replace( '/\s+/', ' ', $saved ), (string) preg_replace( '/\s+/', ' ', $typed ) ) === 0;
        if ( $same && ( (string) $model->getAttribute( 'first_name' ) !== $first || (string) $model->getAttribute( 'last_name' ) !== $last ) ) {
            $model->setAttribute( 'first_name', $first );
            $model->setAttribute( 'last_name', $last );
        }
    }

    // -----------------------------------------------------------------------
    // Contacts already in the CRM
    // -----------------------------------------------------------------------

    /**
     * CRM contact columns and the rule each one follows.
     *
     * @return array<string,string>
     */
    public static function contact_columns(): array {
        return [
            'first_name'     => self::NAME,
            'last_name'      => self::NAME,
            'address_line_1' => self::STREET,
            'address_line_2' => self::STREET,
            'city'           => self::CITY,
            'state'          => self::STATE,
        ];
    }

    /**
     * Apply the rules to the names and addresses already in the CRM.
     *
     * Writes go straight to the FluentCRM table (no contact-updated hooks, so
     * no automations fire and the WordPress profiles are not re-mirrored).
     * Apply changes up to $limit contacts per call; the admin page repeats
     * until nothing is left.
     *
     * @return array{checked:int,to_change:int,changed:int,samples:string[]}
     */
    public static function normalize_contacts( bool $dry, int $limit = 500 ): array {
        global $wpdb;
        $subs    = $wpdb->prefix . 'fc_subscribers';
        $columns = self::contact_columns();
        $select  = 'id, email, ' . implode( ', ', array_keys( $columns ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = (array) $wpdb->get_results( "SELECT {$select} FROM {$subs}", ARRAY_A );

        $out = [ 'checked' => count( $rows ), 'to_change' => 0, 'changed' => 0, 'samples' => [] ];
        foreach ( $rows as $r ) {
            $update = [];
            $lines  = [];
            foreach ( $columns as $col => $kind ) {
                $from = (string) ( $r[ $col ] ?? '' );
                if ( trim( $from ) === '' ) {
                    continue;
                }
                $to = self::apply( $from, $kind );
                // Never blank a stored value.
                if ( $to !== $from && trim( $to ) !== '' ) {
                    $update[ $col ] = $to;
                    $lines[]        = $from . ' → ' . $to;
                }
            }
            if ( ! $update ) {
                continue;
            }
            $out['to_change']++;
            if ( count( $out['samples'] ) < 25 ) {
                $out['samples'][] = (string) $r['email'] . ' · ' . implode( ' · ', $lines );
            }
            if ( ! $dry && $out['changed'] < $limit ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( $wpdb->update( $subs, $update, [ 'id' => (int) $r['id'] ] ) !== false ) {
                    $out['changed']++;
                }
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Checkout: the same rules as the member leaves a field
    // -----------------------------------------------------------------------

    /**
     * Rewrite names and addresses on the checkout page when a field loses
     * focus, so the member sees (and FluentCart stores) the capitalised
     * value. Fields are matched by name: FluentCart's billing / shipping
     * name, street and city, and application inputs flagged with
     * data-my-iapsnj-case. Mirrors apply(); the server applies it again.
     */
    public static function print_script(): void {
        $fields = wp_json_encode( self::checkout_inputs() );
        $lists = wp_json_encode( [
            'num'   => self::NUMERALS,
            'noini' => self::NOT_INITIALS,
            'up'    => self::STREET_UPPER,
            'sw'    => self::STREET_WORDS,
            'small' => self::SMALL_WORDS,
        ] );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script, JSON-encoded constants
        echo '<script>(function(){var F=' . $fields . ',L=' . $lists . ';'
            . 'function has(a,v){return a.indexOf(v)>-1;}'
            . 'function uc(s){return s?s.charAt(0).toUpperCase()+s.slice(1):s;}'
            . 'function part(p){var m;if(!p){return p;}m=/^(\\p{L})([\'\\u2019])(\\p{L}{2}.*)$/u.exec(p);if(m){return m[1].toUpperCase()+m[2]+uc(m[3]);}m=/^mc(\\p{L}{2}.*)$/u.exec(p);if(m){return "Mc"+uc(m[1]);}return uc(p);}'
            . 'function fix(w,k,first){var b=w.replace(/^[.,#]+|[.,#]+$/g,"");if(k==="name"){if(!first&&has(L.num,b)){return w.toUpperCase();}if(/^[bcdfghjklmnpqrstvwxz]{2}$/.test(b)&&!has(L.noini,b)){return w.toUpperCase();}}else{if(k==="street"&&has(L.up,b)){return w.toUpperCase();}if(!first&&has(L.small,w)){return w;}}'
            . 'return w.split(/([\\-\\/.])/).map(function(p,i){return i%2?p:part(p);}).join("");}'
            . 'function word(w,k,first,mixed){if(!/\\p{L}/u.test(w)){return w;}if(/\\d/.test(w)){if(k==="name"){return w;}var l=w.toLowerCase();return /^\\d+(st|nd|rd|th)[.,]?$/.test(l)?l:w.toUpperCase();}'
            . 'var lo=w.toLowerCase();if(w===lo){return fix(lo,k,first);}if(w!==w.toUpperCase()){return w;}'
            . 'if(mixed&&k!=="name"){var b=lo.replace(/^[.,#]+|[.,#]+$/g,"");if((w.match(/\\p{L}/gu)||[]).length<=3&&!has(L.sw,b)){return w;}}return fix(lo,k,first);}'
            . 'function once(v,k){v=String(v||"").replace(/\\s+/g," ").trim();if(!v){return v;}'
            . 'var t=v.split(" ").filter(function(w,i){var b=w.toLowerCase().replace(/^[.,#]+|[.,#]+$/g,"");return !/\\d/.test(w)&&!(k==="street"&&has(L.up,b))&&!(k==="name"&&i>0&&has(L.num,b));}).join("");'
            . 'var mixed=/\\p{Lu}/u.test(t)&&/\\p{Ll}/u.test(t);'
            . 'if(k==="street"){v=v.replace(/\\bp\\.?\\s*o\\.?\\s*box\\b/ig,"PO Box");}return v.split(" ").map(function(w,i){return word(w,k,i===0,mixed);}).join(" ");}'
            // Repeat until stable, like apply() in PHP.
            . 'function apply(v,k){var o=once(v,k);for(var n=0;n<3;n++){var x=once(o,k);if(x===o){break;}o=x;}return o;}'
            . 'function kind(i){if(!i||!i.name||i.tagName!=="INPUT"){return "";}return i.getAttribute("data-my-iapsnj-case")||F[i.name]||"";}'
            . 'function on(e){var i=e.target,k=kind(i);if(!k){return;}var n=apply(i.value,k);if(n===i.value){return;}i.value=n;'
            . 'if(e.type==="blur"){try{i.dispatchEvent(new Event("change",{bubbles:true}));}catch(x){}}}'
            // change (capture) runs before FluentCart's own listeners read the value.
            . 'document.addEventListener("change",on,true);document.addEventListener("blur",on,true);'
            . '})();</script>' . "\n";
    }
}
