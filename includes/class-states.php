<?php
/**
 * US states for the CRM: the full name is what is stored and shown
 * ("New Jersey", not "NJ"). FluentCart keeps its own two-letter codes in its
 * records; every write to the CRM contact goes through full_name().
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_States {

    /**
     * Code => name: the 50 states, DC, the territories and the three military
     * "states" (APO / FPO / DPO addresses).
     *
     * @return array<string,string>
     */
    public static function names(): array {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
            'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida',
            'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana',
            'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
            'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota',
            'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin',
            'WY' => 'Wyoming',
            'AS' => 'American Samoa', 'GU' => 'Guam', 'MP' => 'Northern Mariana Islands', 'PR' => 'Puerto Rico', 'VI' => 'U.S. Virgin Islands',
            'AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific',
        ];
    }

    /** The list a state dropdown shows, in alphabetical order of the names. */
    public static function options(): array {
        $names = array_values( self::names() );
        sort( $names, SORT_STRING );
        return $names;
    }

    /** Is this country value the United States (or empty, which the checkout treats as the US)? */
    public static function is_us( string $country ): bool {
        $c = strtoupper( trim( $country ) );
        return in_array( $c, [ '', 'US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA' ], true );
    }

    /**
     * A US state written as a code or a name, as its full name; '' when it is
     * not a US state or the contact's country is another country.
     */
    public static function full_name( string $value, string $country = '' ): string {
        $value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
        if ( $value === '' || ! self::is_us( $country ) ) {
            return '';
        }
        $names = self::names();
        $up    = strtoupper( $value );
        if ( isset( $names[ $up ] ) ) {
            return $names[ $up ];
        }
        // "N.J." and "N J" style codes.
        $letters = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $value ) );
        if ( strlen( $letters ) === 2 && isset( $names[ $letters ] ) && strlen( $value ) <= 4 ) {
            return $names[ $letters ];
        }
        foreach ( $names as $name ) {
            if ( strcasecmp( $name, $value ) === 0 ) {
                return $name;
            }
        }
        return '';
    }
}
