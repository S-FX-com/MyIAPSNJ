<?php
/**
 * "Did you mean …?" for a dropdown answer typed under "Other (not listed)":
 * finds the listed options that closely match what was typed, so the member is
 * offered the existing one before a near-duplicate (a Department, a Rank) is
 * created.
 *
 * Matching ignores capitals, punctuation and filler words (police, department,
 * dept, pd, the, of, and), so "Newark P.D." is "Newark PD" and "Newark Police
 * Department" is close to "Newark PD". An option is proposed when its name is
 * the same apart from that, one name contains the other, or the two differ by
 * a letter or two. public/js/similar-options.js applies the same rules as the
 * member types; the server applies them again on submit.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Similar {

    /** Words that do not tell one name from another. */
    const STOP_WORDS = [ 'police', 'department', 'dept', 'pd', 'the', 'of', 'and' ];

    /** Lowest score proposed. */
    const THRESHOLD = 0.82;

    /** Most options proposed. */
    const MAX = 3;

    /** Shortest name (after the filler words) compared by containment or spelling. */
    const MIN_LENGTH = 4;

    const HANDLE = 'my-iapsnj-similar';

    /** @var bool the script was queued on this request */
    private static $queued = false;

    /** Lower case, punctuation gone, one space between words. */
    public static function normalize( string $s ): string {
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
        $s = (string) preg_replace( "/[.'\u{2019}`]/u", '', $s );
        $s = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
        return trim( $s );
    }

    /** normalize() without the filler words ('' when nothing else is left). */
    public static function core( string $s ): string {
        $words = array_filter( explode( ' ', self::normalize( $s ) ), static function ( $w ) {
            return $w !== '' && ! in_array( $w, self::STOP_WORDS, true );
        } );
        return implode( ' ', $words );
    }

    /** Do the two read the same once capitals and punctuation are ignored? */
    public static function same( string $a, string $b ): bool {
        $a = self::normalize( $a );
        return $a !== '' && $a === self::normalize( $b );
    }

    /** 0–1: how alike two names are. */
    public static function score( string $a, string $b ): float {
        $na = self::normalize( $a );
        $nb = self::normalize( $b );
        if ( $na === '' || $nb === '' ) {
            return 0.0;
        }
        if ( $na === $nb ) {
            return 1.0;
        }
        $ca = self::core( $a );
        $cb = self::core( $b );
        $ca = $ca !== '' ? $ca : $na;
        $cb = $cb !== '' ? $cb : $nb;
        if ( $ca === $cb ) {
            return 0.95;
        }
        $short = strlen( $ca ) <= strlen( $cb ) ? $ca : $cb;
        $long  = $short === $ca ? $cb : $ca;
        if ( strlen( $short ) >= self::MIN_LENGTH && strpos( ' ' . $long . ' ', ' ' . $short . ' ' ) !== false ) {
            return 0.85;
        }
        $max = max( strlen( $ca ), strlen( $cb ) );
        if ( $max >= self::MIN_LENGTH && $max <= 255 ) {
            return 1 - self::distance( $ca, $cb ) / $max;
        }
        return 0.0;
    }

    /** Edits (insert, delete, change, or swap two neighbours) between two strings. */
    public static function distance( string $a, string $b ): int {
        $la = strlen( $a );
        $lb = strlen( $b );
        $d  = [];
        for ( $i = 0; $i <= $la; $i++ ) {
            $d[ $i ][0] = $i;
        }
        for ( $j = 0; $j <= $lb; $j++ ) {
            $d[0][ $j ] = $j;
        }
        for ( $i = 1; $i <= $la; $i++ ) {
            for ( $j = 1; $j <= $lb; $j++ ) {
                $cost         = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
                $d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
                if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
                    $d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
                }
            }
        }
        return $d[ $la ][ $lb ];
    }

    /**
     * The listed options that closely match what was typed, closest first.
     *
     * @param string[] $options
     * @return string[]
     */
    public static function matches( string $typed, array $options ): array {
        $found = [];
        foreach ( $options as $option ) {
            $option = (string) $option;
            $score  = self::score( $typed, $option );
            if ( $score >= self::THRESHOLD ) {
                $found[ $option ] = $score;
            }
        }
        arsort( $found );
        return array_slice( array_keys( $found ), 0, self::MAX );
    }

    /** The listed spelling when the typed answer is the same as an option (apart from capitals / punctuation); '' otherwise. */
    public static function exact( string $typed, array $options ): string {
        foreach ( $options as $option ) {
            if ( self::same( $typed, (string) $option ) ) {
                return (string) $option;
            }
        }
        return '';
    }

    /** Queue the script that shows the proposal while the member types. Once per request. */
    public static function enqueue(): void {
        if ( self::$queued ) {
            return;
        }
        self::$queued = true;
        wp_enqueue_script( self::HANDLE, MY_IAPSNJ_URL . 'public/js/similar-options.js', [], MY_IAPSNJ_VERSION, true );
        wp_localize_script( self::HANDLE, 'myIapsnjSimilar', [
            'stop'      => self::STOP_WORDS,
            'threshold' => self::THRESHOLD,
            'max'       => self::MAX,
            'minLength' => self::MIN_LENGTH,
            'i18n'      => [
                'did'      => __( 'Did you mean one of these?', 'my-iapsnj' ),
                'use'      => __( 'Use "%s"', 'my-iapsnj' ),
                'different' => __( 'No, mine is different', 'my-iapsnj' ),
                'kept'     => __( 'OK, we will add it as typed.', 'my-iapsnj' ),
            ],
        ] );
    }
}
