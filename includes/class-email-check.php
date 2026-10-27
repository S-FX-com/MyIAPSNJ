<?php
/**
 * Email check on the membership checkout: as a visitor types or leaves the
 * email field, ask whether a WordPress account already uses it and, if so,
 * show a "log in instead" prompt right under the name / email box, before the
 * rest of the application is filled in. Only a prompt: nothing blocks the
 * checkout, and a logged-in member never sees it.
 *
 * Privacy: the lookup tells an anonymous visitor whether an email has an
 * account, which is what WooCommerce-style checkouts do at submit time too.
 * It answers yes / no only, needs the page's nonce and is rate limited per IP
 * (LIMIT checks per WINDOW seconds); over the limit it answers "no", so the
 * prompt just does not appear.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Email_Check {

    const ACTION = 'my_iapsnj_email_check';

    /** Checks one IP address may make per window. */
    const LIMIT  = 30;
    const WINDOW = 600;

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Visitors are logged out; the logged-in twin only answers "no".
        add_action( 'wp_ajax_nopriv_' . self::ACTION, [ $this, 'ajax_check' ] );
        add_action( 'wp_ajax_' . self::ACTION, [ $this, 'ajax_check' ] );
        add_action( 'wp_footer', [ $this, 'print_footer_script' ], 101 );
    }

    /**
     * Over this IP's limit? Counts the call otherwise (fixed window).
     */
    private static function rate_limited(): bool {
        $ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
        $key   = 'my_iapsnj_ec_' . md5( $ip );
        $now   = time();
        $state = get_transient( $key );
        if ( ! is_array( $state ) || ! isset( $state['n'], $state['t'] ) || $now - (int) $state['t'] >= self::WINDOW ) {
            $state = [ 'n' => 0, 't' => $now ];
        }
        if ( (int) $state['n'] >= self::LIMIT ) {
            return true;
        }
        $state['n'] = (int) $state['n'] + 1;
        set_transient( $key, $state, max( 1, self::WINDOW - ( $now - (int) $state['t'] ) ) );
        return false;
    }

    public function ajax_check(): void {
        check_ajax_referer( self::ACTION, 'nonce' );

        $registered = false;
        if ( ! is_user_logged_in() && ! self::rate_limited() ) {
            $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
            if ( is_email( $email ) ) {
                $registered = (bool) email_exists( $email );
            }
        }
        wp_send_json_success( [ 'registered' => $registered ] );
    }

    /**
     * Print the checker on a membership checkout page shown to a visitor.
     * It reads FluentCart's email input (name="billing_email"), which the
     * checkout renders and re-renders on its own, so it is found by
     * delegated events and a short retry loop, like the other checkout scripts.
     */
    public function print_footer_script(): void {
        if ( is_user_logged_in() || ! My_IAPSNJ_Checkout_Fields::get_instance()->was_rendered() ) {
            return;
        }
        $cfg = [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce( self::ACTION ),
            'login'   => wp_login_url(),
            'lost'    => wp_lostpassword_url(),
            'i18n'    => [
                'title' => __( 'This email address is already registered.', 'my-iapsnj' ),
                'body'  => __( 'Please log in to renew or update your membership instead of applying again.', 'my-iapsnj' ),
                'login' => __( 'Log in', 'my-iapsnj' ),
                'lost'  => __( 'Forgot your password?', 'my-iapsnj' ),
            ],
        ];
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded config and a static script
        echo '<script>(function(){var C=' . wp_json_encode( $cfg ) . ';'
            . 'var seen={},state={email:"",registered:false},timer=null;'
            . 'function input(){return document.querySelector(\'input[name="billing_email"]\');}'
            . 'function ok(v){return /^[^@\\s]+@[^@\\s]+\\.[^@\\s]{2,}$/.test(v);}'
            . 'function url(base){try{var u=new URL(base,location.href);u.searchParams.set("redirect_to",location.href);return u.toString();}catch(e){return base;}}'
            . 'function anchor(i){return i.closest(".fct_checkout_input_group")||i.closest("[data-fluent-cart-checkout-page-form-input-wrapper]")||i.parentNode;}'
            . 'function drop(){document.querySelectorAll(".my-iapsnj-email-notice").forEach(function(n){n.remove();});}'
            . 'function place(){var i=input();if(!i){return;}var on=state.registered&&i.value.trim().toLowerCase()===state.email;var n=document.querySelector(".my-iapsnj-email-notice");'
            . 'if(!on){if(n){n.remove();}return;}if(n){return;}var a=anchor(i);if(!a||!a.parentNode){return;}'
            . 'n=document.createElement("div");n.className="my-iapsnj-email-notice";n.setAttribute("role","status");'
            . 'var t=document.createElement("strong");t.textContent=C.i18n.title;n.appendChild(t);n.appendChild(document.createTextNode(" "+C.i18n.body+" "));'
            . 'var l=document.createElement("a");l.className="my-iapsnj-email-login";l.href=url(C.login);l.textContent=C.i18n.login;n.appendChild(l);'
            . 'n.appendChild(document.createTextNode(" \\u00b7 "));var f=document.createElement("a");f.href=C.lost;f.textContent=C.i18n.lost;n.appendChild(f);'
            . 'a.parentNode.insertBefore(n,a.nextSibling);}'
            . 'function apply(email,reg){seen[email]=reg;var i=input();if(i&&i.value.trim().toLowerCase()===email){state={email:email,registered:reg};place();}}'
            . 'function check(){var i=input();if(!i){return;}var v=i.value.trim().toLowerCase();if(!ok(v)){state={email:"",registered:false};place();return;}'
            . 'if(v in seen){state={email:v,registered:seen[v]};place();return;}seen[v]=false;'
            . 'var b=new FormData();b.append("action",C.action);b.append("nonce",C.nonce);b.append("email",v);'
            . 'fetch(C.ajaxUrl,{method:"POST",body:b,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){apply(v,!!(j&&j.success&&j.data&&j.data.registered));}).catch(function(){delete seen[v];});}'
            . 'function later(){clearTimeout(timer);timer=setTimeout(check,700);}'
            . 'function on(e){var t=e.target;if(!t||t.name!=="billing_email"){return;}if(e.type==="input"){place();later();}else{clearTimeout(timer);check();}}'
            . 'document.addEventListener("input",on,true);document.addEventListener("change",on,true);document.addEventListener("blur",on,true);'
            // FluentCart redraws parts of the checkout: put the prompt back, and check an email that was filled in at load (a returning visitor).
            . 'window.addEventListener("fluentCartFragmentsReplaced",place);'
            . 'var n=0,t=setInterval(function(){check();place();if(++n>20){clearInterval(t);}},500);'
            . '})();</script>' . "\n";
    }
}
