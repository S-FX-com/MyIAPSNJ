<?php
/**
 * [iapsnj_change_password]: a "Change My Password" form for a logged-in member.
 *
 * Two fields (new password, repeated), a strength meter and a show / hide
 * toggle on each field. The browser checks the match and the length before
 * it sends anything; the server checks them again and writes the password
 * with wp_update_user(), which keeps the member signed in on this device
 * (it re-issues the auth cookie), ends their other sessions and sends the
 * "your password was changed" email (My_IAPSNJ_Emails styles it).
 *
 * This only touches the WordPress account. The CRM is not involved: the
 * password is not a contact field and nothing is written to it.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Change_Password {

    const SHORTCODE = 'iapsnj_change_password';
    const ACTION    = 'my_iapsnj_change_password';
    const HANDLE    = 'my-iapsnj-change-password';

    /** Shortest password the form accepts (WordPress itself sets no minimum). */
    const MIN_LENGTH = 8;

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
        // Members only: there is deliberately no wp_ajax_nopriv_ twin, and
        // the handler is not gated on manage_options (it changes the
        // caller's own password, nobody else's).
        add_action( 'wp_ajax_' . self::ACTION, [ $this, 'ajax_change' ] );
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    /**
     * Register the assets, and print the stylesheet in the page head when
     * the page's own content holds the shortcode (a late enqueue from the
     * shortcode still works, but the form would draw unstyled first).
     */
    public function register_assets(): void {
        wp_register_style( self::HANDLE, MY_IAPSNJ_URL . 'public/css/change-password.css', [], MY_IAPSNJ_VERSION );
        // password-strength-meter is WordPress's own zxcvbn wrapper (the
        // one the profile screen uses); it loads zxcvbn on demand.
        wp_register_script(
            self::HANDLE,
            MY_IAPSNJ_URL . 'public/js/change-password.js',
            [ 'jquery', 'password-strength-meter' ],
            MY_IAPSNJ_VERSION,
            true
        );

        $post = get_post();
        if ( $post instanceof WP_Post && is_singular() && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
            wp_enqueue_style( self::HANDLE );
        }
    }

    // -----------------------------------------------------------------------
    // Shortcode
    // -----------------------------------------------------------------------

    /**
     * [iapsnj_change_password button="Update Password"]
     *
     * @param array|string $atts
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'button' => __( 'Update Password', 'my-iapsnj' ),
        ], (array) $atts, self::SHORTCODE );

        if ( ! is_user_logged_in() ) {
            $login = wp_login_url( (string) get_permalink() );
            return '<p class="my-iapsnj-cp-notice">'
                . sprintf(
                    /* translators: %s: link to the login page */
                    esc_html__( 'Please %s to change your password.', 'my-iapsnj' ),
                    '<a href="' . esc_url( $login ) . '">' . esc_html__( 'log in', 'my-iapsnj' ) . '</a>'
                )
                . '</p>';
        }

        wp_enqueue_style( self::HANDLE );
        wp_enqueue_script( self::HANDLE );
        wp_localize_script( self::HANDLE, 'myIapsnjChangePassword', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'action'     => self::ACTION,
            'nonce'      => wp_create_nonce( self::ACTION ),
            'minLength'  => self::MIN_LENGTH,
            'disallowed' => self::disallowed_words( wp_get_current_user() ),
            'i18n'       => [
                'show'     => __( 'Show password', 'my-iapsnj' ),
                'hide'     => __( 'Hide password', 'my-iapsnj' ),
                'strength' => __( 'Strength:', 'my-iapsnj' ),
                'levels'   => [
                    __( 'Very weak', 'my-iapsnj' ),
                    __( 'Weak', 'my-iapsnj' ),
                    __( 'Medium', 'my-iapsnj' ),
                    __( 'Strong', 'my-iapsnj' ),
                ],
                'required' => __( 'Please enter your new password twice.', 'my-iapsnj' ),
                'match'    => __( 'Passwords match.', 'my-iapsnj' ),
                'mismatch' => __( 'Passwords do not match.', 'my-iapsnj' ),
                /* translators: %d: minimum number of characters */
                'tooShort' => sprintf( __( 'Use at least %d characters.', 'my-iapsnj' ), self::MIN_LENGTH ),
                'saving'   => __( 'Saving…', 'my-iapsnj' ),
                'error'    => __( 'Your password could not be changed. Please try again.', 'my-iapsnj' ),
            ],
        ] );

        ob_start();
        ?>
        <div class="my-iapsnj-cp">
            <form class="my-iapsnj-cp-form" method="post" novalidate>
                <div class="my-iapsnj-cp-row">
                    <div class="my-iapsnj-cp-field">
                        <label for="my-iapsnj-cp-new">
                            <?php esc_html_e( 'Enter Your New Password', 'my-iapsnj' ); ?>
                            <span class="my-iapsnj-cp-required" aria-hidden="true">*</span>
                        </label>
                        <div class="my-iapsnj-cp-input">
                            <input type="password" id="my-iapsnj-cp-new" name="new_password"
                                   autocomplete="new-password" aria-required="true"
                                   aria-describedby="my-iapsnj-cp-strength">
                            <?php $this->toggle_button( 'my-iapsnj-cp-new' ); ?>
                        </div>
                        <div class="my-iapsnj-cp-strength" id="my-iapsnj-cp-strength" aria-live="polite" hidden>
                            <span class="my-iapsnj-cp-bar"><span class="my-iapsnj-cp-bar-fill"></span></span>
                            <span class="my-iapsnj-cp-strength-text"></span>
                        </div>
                    </div>
                    <div class="my-iapsnj-cp-field">
                        <label for="my-iapsnj-cp-repeat">
                            <?php esc_html_e( 'Repeat Your Password', 'my-iapsnj' ); ?>
                            <span class="my-iapsnj-cp-required" aria-hidden="true">*</span>
                        </label>
                        <div class="my-iapsnj-cp-input">
                            <input type="password" id="my-iapsnj-cp-repeat" name="repeat_password"
                                   autocomplete="new-password" aria-required="true"
                                   aria-describedby="my-iapsnj-cp-match">
                            <?php $this->toggle_button( 'my-iapsnj-cp-repeat' ); ?>
                        </div>
                        <p class="my-iapsnj-cp-match" id="my-iapsnj-cp-match" aria-live="polite"></p>
                    </div>
                </div>
                <p class="my-iapsnj-cp-message" role="alert" hidden></p>
                <button type="submit" class="my-iapsnj-cp-submit"><?php echo esc_html( (string) $atts['button'] ); ?></button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** The eye button that reveals / hides one password field. */
    private function toggle_button( string $input_id ): void {
        ?>
        <button type="button" class="my-iapsnj-cp-toggle" aria-controls="<?php echo esc_attr( $input_id ); ?>"
                aria-pressed="false" aria-label="<?php esc_attr_e( 'Show password', 'my-iapsnj' ); ?>">
            <svg class="my-iapsnj-cp-eye" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
                <path d="M12 5C6.5 5 2.7 9.1 1.3 11.4a1.3 1.3 0 0 0 0 1.2C2.7 14.9 6.5 19 12 19s9.3-4.1 10.7-6.4a1.3 1.3 0 0 0 0-1.2C21.3 9.1 17.5 5 12 5zm0 12c-4 0-7.1-2.9-8.6-5 1.5-2.1 4.6-5 8.6-5s7.1 2.9 8.6 5c-1.5 2.1-4.6 5-8.6 5zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/>
            </svg>
            <svg class="my-iapsnj-cp-eye-off" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
                <path d="M3.3 2 2 3.3l3.2 3.2C3.6 7.8 2.3 9.5 1.3 11.4a1.3 1.3 0 0 0 0 1.2C2.7 14.9 6.5 19 12 19c1.8 0 3.4-.4 4.8-1.1l3.9 3.9 1.3-1.3L3.3 2zm8.7 15c-4 0-7.1-2.9-8.6-5 .8-1.1 1.8-2.2 3.1-3.1l2.2 2.2a3 3 0 0 0 4.1 4.1l1.6 1.6c-.7.1-1.5.2-2.4.2zm0-10c4 0 7.1 2.9 8.6 5-.5.7-1.2 1.5-2 2.2l1.4 1.4c1.2-1.1 2.1-2.3 2.7-3.2a1.3 1.3 0 0 0 0-1.2C21.3 9.1 17.5 5 12 5c-1.1 0-2.1.2-3 .5l1.6 1.6c.4-.1.9-.1 1.4-.1z"/>
            </svg>
        </button>
        <?php
    }

    /**
     * Words the strength meter should treat as guessable for this member:
     * their login, name and email name, and the site name.
     *
     * @return string[]
     */
    private static function disallowed_words( WP_User $user ): array {
        $email = (string) $user->user_email;
        $words = [
            $user->user_login,
            $user->first_name,
            $user->last_name,
            strstr( $email, '@', true ) ?: $email,
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        ];
        $words = array_filter( array_map( 'trim', array_map( 'strval', $words ) ), function ( $w ) {
            return strlen( $w ) >= 3;
        } );
        return array_values( array_unique( $words ) );
    }

    // -----------------------------------------------------------------------
    // Save
    // -----------------------------------------------------------------------

    public function ajax_change(): void {
        check_ajax_referer( self::ACTION, 'nonce' );

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Please log in to change your password.', 'my-iapsnj' ) ], 403 );
        }

        // Passwords are taken exactly as typed: no sanitising, no trimming.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $new    = isset( $_POST['new_password'] ) ? wp_unslash( (string) $_POST['new_password'] ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $repeat = isset( $_POST['repeat_password'] ) ? wp_unslash( (string) $_POST['repeat_password'] ) : '';

        $error = self::validate( $new, $repeat, get_userdata( $user_id ) );
        if ( $error !== '' ) {
            wp_send_json_error( [ 'message' => $error ], 400 );
        }

        $result = wp_update_user( [ 'ID' => $user_id, 'user_pass' => $new ] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        wp_send_json_success( [
            'message' => __( 'Your password has been updated.', 'my-iapsnj' ),
        ] );
    }

    /**
     * @return string error message, '' when the password is acceptable
     */
    private static function validate( string $new, string $repeat, $user ): string {
        if ( $new === '' || $repeat === '' ) {
            return __( 'Please enter your new password twice.', 'my-iapsnj' );
        }
        if ( $new !== $repeat ) {
            return __( 'The two passwords do not match.', 'my-iapsnj' );
        }
        if ( strlen( $new ) < self::MIN_LENGTH ) {
            /* translators: %d: minimum number of characters */
            return sprintf( __( 'Your password must be at least %d characters long.', 'my-iapsnj' ), self::MIN_LENGTH );
        }
        if ( $user instanceof WP_User && wp_check_password( $new, $user->user_pass, $user->ID ) ) {
            return __( 'Your new password must be different from your current one.', 'my-iapsnj' );
        }
        return '';
    }
}
