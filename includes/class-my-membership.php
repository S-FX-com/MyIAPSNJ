<?php
/**
 * [iapsnj_my_membership]: a "My Membership" bar for the logged-in member.
 *
 * Membership role, member since, expiration, status and member number, all
 * read from the member's FluentCRM contact (member_type, join_date,
 * paid_through, member_number). Read only: nothing is written.
 *
 * - Membership Role is the member_type, followed by the account's staff /
 *   admin WordPress role when it has one (see staff_role_labels()).
 * - Status uses My_IAPSNJ_Schema::is_active_state(), the test the daily
 *   expiry job and the member lists use (grace period included).
 * - Lifetime reads LIFETIME; Honorary, which never expires either, reads
 *   NO EXPIRATION. Both are always Active and never see Renew Now.
 * - Renew Now shows when the membership has lapsed, or once the renewal
 *   season has started (Dues → Membership Products → "Renewal season
 *   starts") and a payment today would carry the membership further than
 *   it already goes. It links to the product for the member's type: the
 *   shortcode's regular_url / associate_url, else the renewal products set
 *   in Dues → Membership Products.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

final class My_IAPSNJ_My_Membership {

    const SHORTCODE = 'iapsnj_my_membership';
    const HANDLE    = 'my-iapsnj-my-membership';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    public function register_assets(): void {
        wp_register_style( self::HANDLE, MY_IAPSNJ_URL . 'public/css/my-membership.css', [], MY_IAPSNJ_VERSION );
        $post = get_post();
        if ( $post instanceof WP_Post && is_singular() && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
            wp_enqueue_style( self::HANDLE );
        }
    }

    /**
     * Whole days from today to a Y-m-d date (negative once it has passed).
     * Both are calendar dates, so they are compared as dates, not instants.
     */
    public static function days_until( string $ymd ): int {
        $to   = strtotime( $ymd . ' 00:00:00 UTC' );
        $from = strtotime( My_IAPSNJ_Dates::today() . ' 00:00:00 UTC' );
        return (int) round( ( (int) $to - (int) $from ) / DAY_IN_SECONDS );
    }

    /**
     * Would a payment made today carry the membership past $paid_through?
     * That is what "the renewal season has started" means for a member in
     * good standing: a payment buys through Dec 31 of this year until the
     * season starts (Dues → Membership Products → Renewal season starts,
     * default Oct 1) and through Dec 31 of the next year from then on, so a
     * member paid through Dec 31 of this year may renew from the start date,
     * and one who renewed after it has to wait for next year's.
     *
     * @param string $paid_through Y-m-d on the contact
     */
    public static function renewal_season_open( string $paid_through ): bool {
        $paid_through = My_IAPSNJ_Dates::ymd( $paid_through );
        if ( $paid_through === '' ) {
            return false;
        }
        $term = My_IAPSNJ_Dates::membership_term( My_IAPSNJ_Dates::today(), My_IAPSNJ_Membership::renewal_cutover() );
        return strcmp( $term['paid_through'], $paid_through ) > 0;
    }

    /**
     * Names of the user's WordPress roles that are not membership roles:
     * Administrator, Editor, board roles … Subscriber and the roles that
     * Settings → Configurations → WordPress roles gives members (per type,
     * and the expired role) are what the membership type already says, so
     * they are left out.
     *
     * @return string[]
     */
    public static function staff_role_labels( $user ): array {
        if ( ! $user instanceof WP_User ) {
            return [];
        }
        $managed = array_merge( [ 'subscriber', My_IAPSNJ_Engine::expired_role() ], array_values( My_IAPSNJ_Engine::role_map() ) );
        $names   = wp_roles()->get_names();
        $out     = [];
        foreach ( (array) $user->roles as $slug ) {
            $slug = (string) $slug;
            if ( $slug === '' || in_array( $slug, $managed, true ) ) {
                continue;
            }
            $out[] = translate_user_role( (string) ( $names[ $slug ] ?? $slug ) );
        }
        return $out;
    }

    /**
     * [iapsnj_my_membership button="Renew Now" regular_url="" associate_url=""]
     *
     * @param array|string $atts
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'button'        => __( 'Renew Now', 'my-iapsnj' ),
            'regular_url'   => '',
            'associate_url' => '',
        ], (array) $atts, self::SHORTCODE );

        if ( ! is_user_logged_in() ) {
            $login = wp_login_url( (string) get_permalink() );
            return '<p class="my-iapsnj-mm-notice">'
                . sprintf(
                    /* translators: %s: link to the login page */
                    esc_html__( 'Please %s to see your membership.', 'my-iapsnj' ),
                    '<a href="' . esc_url( $login ) . '">' . esc_html__( 'log in', 'my-iapsnj' ) . '</a>'
                )
                . '</p>';
        }

        $contact = null;
        try {
            $contact = My_IAPSNJ_Engine::find_linked_subscriber( get_current_user_id() );
        } catch ( \Throwable $e ) {
            $contact = null;
        }
        if ( ! $contact instanceof Subscriber ) {
            return '<p class="my-iapsnj-mm-notice">' . esc_html__( 'We could not find your membership record. Please contact IAPSNJ so we can link your account.', 'my-iapsnj' ) . '</p>';
        }

        $type    = My_IAPSNJ_Schema::field( $contact, My_IAPSNJ_Schema::FIELD_MEMBER_TYPE );
        $paid    = My_IAPSNJ_Dates::ymd( My_IAPSNJ_Schema::field( $contact, My_IAPSNJ_Schema::FIELD_PAID_THROUGH ) );
        $since   = My_IAPSNJ_Dates::ymd( My_IAPSNJ_Schema::field( $contact, My_IAPSNJ_Schema::FIELD_JOIN_DATE ) );
        $number  = trim( My_IAPSNJ_Schema::field( $contact, My_IAPSNJ_Schema::FIELD_MEMBER_NUMBER ) );
        $comped  = My_IAPSNJ_Schema::is_comped_type( $type );
        $active  = My_IAPSNJ_Schema::is_active_state( $type, $paid );
        $dash    = "\u{2014}";

        // Expiration text, and how far away it is (shown under the date).
        $note = '';
        if ( $type === My_IAPSNJ_Schema::TYPE_LIFETIME ) {
            $expires = __( 'LIFETIME', 'my-iapsnj' );
        } elseif ( $comped ) {
            $expires = __( 'NO EXPIRATION', 'my-iapsnj' );
        } elseif ( $paid === '' ) {
            $expires = $dash;
        } else {
            $expires = My_IAPSNJ_Dates::ymd_display( $paid );
            $days    = self::days_until( $paid );
            if ( $days > 0 ) {
                /* translators: %d: days left */
                $note = sprintf( _n( 'in %d day', 'in %d days', $days, 'my-iapsnj' ), $days );
            } elseif ( $days === 0 ) {
                $note = __( 'today', 'my-iapsnj' );
            } else {
                /* translators: %d: days since the membership ended */
                $note = sprintf( _n( '%d day ago', '%d days ago', -$days, 'my-iapsnj' ), -$days );
            }
        }

        // Renew Now: lapsed, or the renewal season has started. Never for Lifetime / Honorary.
        $renew = '';
        if ( ! $comped && ( ! $active || self::renewal_season_open( $paid ) ) ) {
            $renew = $type === My_IAPSNJ_Schema::TYPE_ASSOCIATE ? (string) $atts['associate_url'] : (string) $atts['regular_url'];
            if ( $renew === '' ) {
                $renew = My_IAPSNJ_Checkout_Fields::renewal_url_for_user( get_current_user_id() );
            }
        }

        // The membership type, plus a staff / admin WordPress role when the
        // account has one ("Regular · Administrator"; "Administrator" alone
        // for an account with no member type).
        $role = implode( ' · ', array_filter( array_merge( [ $type ], self::staff_role_labels( wp_get_current_user() ) ), 'strlen' ) );

        wp_enqueue_style( self::HANDLE );

        $rows = [
            __( 'Membership Role', 'my-iapsnj' ) => $role !== '' ? $role : $dash,
            __( 'Member Since', 'my-iapsnj' )    => $since !== '' ? My_IAPSNJ_Dates::ymd_display( $since ) : $dash,
        ];

        ob_start();
        ?>
        <div class="my-iapsnj-mm">
            <dl class="my-iapsnj-mm-list">
                <?php foreach ( $rows as $label => $value ) : ?>
                    <div class="my-iapsnj-mm-row">
                        <dt><?php echo esc_html( (string) $label ); ?></dt>
                        <dd><?php echo esc_html( (string) $value ); ?></dd>
                    </div>
                <?php endforeach; ?>
                <div class="my-iapsnj-mm-row">
                    <dt><?php esc_html_e( 'Expiration Date', 'my-iapsnj' ); ?></dt>
                    <dd>
                        <?php echo esc_html( $expires ); ?>
                        <?php if ( $note !== '' ) : ?>
                            <span class="my-iapsnj-mm-note">(<?php echo esc_html( $note ); ?>)</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="my-iapsnj-mm-row">
                    <dt><?php esc_html_e( 'Status', 'my-iapsnj' ); ?></dt>
                    <dd>
                        <span class="my-iapsnj-mm-badge <?php echo $active ? 'is-active' : 'is-inactive'; ?>">
                            <?php echo $active ? esc_html__( 'Active', 'my-iapsnj' ) : esc_html__( 'Inactive', 'my-iapsnj' ); ?>
                        </span>
                    </dd>
                </div>
                <div class="my-iapsnj-mm-row">
                    <dt><?php esc_html_e( 'Member Number', 'my-iapsnj' ); ?></dt>
                    <dd><?php echo esc_html( $number !== '' ? $number : $dash ); ?></dd>
                </div>
            </dl>
            <?php if ( $renew !== '' ) : ?>
                <p class="my-iapsnj-mm-action">
                    <a class="my-iapsnj-mm-renew" href="<?php echo esc_url( $renew ); ?>"><?php echo esc_html( (string) $atts['button'] ); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
