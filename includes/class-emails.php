<?php
/**
 * My_IAPSNJ_Emails
 *
 * One look for every email the site sends. FluentCRM's campaign and
 * automation emails (Welcome, Happy Birthday …) already use the design set in
 * FluentCRM → Settings → Email Styling; the others went out in their own
 * dress: WordPress's plain-text account emails, this plugin's new-member
 * notice and FluentCart's receipts. They are wrapped in the same FluentCRM
 * design template (the filter FluentCRM's own double opt-in email renders
 * through), with the IAPSNJ logo on top and the business name and address
 * as the footer.
 *
 *   wp_new_user_notification_email         login details / set your password (member)
 *   wp_new_user_notification_email_admin   "New user registration" (admin)
 *   retrieve_password_notification_email   password reset (member)
 *   password_change_email, email_change_email   "your password / email was changed" (member)
 *   fluent_cart/email_notification/mailer  receipts, check instructions … (FluentCart)
 *   My_IAPSNJ_Membership                   new-member notice (calls wrap())
 *
 * Settings → Configurations → Email design: on / off, FluentCRM design,
 * logo, footer, Preview and Send test.
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Emails {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var string FluentCart release that added fluent_cart/email_notification/mailer */
    const FLUENTCART_MIN = '1.6.4';

    /** @var string[] WordPress core filters of plain-text account emails */
    const WP_FILTERS = [
        'wp_new_user_notification_email',
        'wp_new_user_notification_email_admin',
        'retrieve_password_notification_email',
        'password_change_email',
        'email_change_email',
    ];

    const PREVIEW_ACTION = 'my_iapsnj_email_preview';

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_post_' . self::PREVIEW_ACTION, [ $this, 'render_preview' ] );
        if ( ! self::enabled() ) {
            return;
        }
        foreach ( self::WP_FILTERS as $filter ) {
            add_filter( $filter, [ $this, 'brand_wp_email' ], 20, 1 );
        }
        add_filter( 'fluent_cart/email_notification/mailer', [ $this, 'brand_fluentcart_email' ], 20, 2 );
    }

    // -----------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------

    public static function enabled(): bool {
        return ! empty( My_IAPSNJ_Plugin::settings()['email_branding'] );
    }

    /**
     * FluentCRM design templates that wrap any HTML body: key => label (the
     * names FluentCRM shows in its email editor).
     *
     * @return array<string,string>
     */
    public static function designs(): array {
        return [
            'simple'  => __( 'Simple Boxed', 'my-iapsnj' ),
            'plain'   => __( 'Plain Centered', 'my-iapsnj' ),
            'classic' => __( 'Plain Left', 'my-iapsnj' ),
        ];
    }

    public static function design(): string {
        $design = (string) ( My_IAPSNJ_Plugin::settings()['email_design'] ?? 'simple' );
        return isset( self::designs()[ $design ] ) ? $design : 'simple';
    }

    /**
     * Can FluentCart's emails be restyled? Needs the mailer filter
     * (FluentCart 1.6.4+); older versions keep FluentCart's layout.
     */
    public static function fluentcart_supported(): bool {
        return defined( 'FLUENTCART_VERSION' ) && version_compare( (string) FLUENTCART_VERSION, self::FLUENTCART_MIN, '>=' );
    }

    /**
     * FluentCRM → Settings → Business Settings (business_name,
     * business_address, logo); [] without FluentCRM.
     */
    private static function business(): array {
        if ( ! function_exists( 'fluentcrmGetGlobalSettings' ) ) {
            return [];
        }
        $business = fluentcrmGetGlobalSettings( 'business_settings', [] );
        return is_array( $business ) ? $business : [];
    }

    public static function brand_name(): string {
        $name = trim( (string) ( self::business()['business_name'] ?? '' ) );
        return $name !== '' ? $name : (string) get_bloginfo( 'name' );
    }

    /**
     * Logo shown on top: the one typed in Configurations, else FluentCRM's
     * business logo, else the theme's custom logo, else the site icon; ''
     * when there is none.
     */
    public static function logo_url(): string {
        $url = trim( (string) ( My_IAPSNJ_Plugin::settings()['email_logo_url'] ?? '' ) );
        return $url !== '' ? esc_url_raw( $url ) : self::default_logo_url();
    }

    /**
     * The logo used when none is typed in Configurations.
     */
    public static function default_logo_url(): string {
        $url = trim( (string) ( self::business()['logo'] ?? '' ) );
        if ( $url === '' ) {
            $logo_id = (int) get_theme_mod( 'custom_logo' );
            $url     = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        }
        if ( $url === '' ) {
            $url = (string) get_site_icon_url( 192 );
        }
        return esc_url_raw( $url );
    }

    /**
     * "Italian American Police Society of NJ, P.O. Box 576 Rockaway NJ 07866":
     * FluentCRM's business name and address. No unsubscribe link: these are
     * account and payment emails, not marketing.
     */
    public static function default_footer(): string {
        $address = trim( (string) ( self::business()['business_address'] ?? '' ) );
        return implode( ', ', array_filter( [ self::brand_name(), $address ], 'strlen' ) );
    }

    public static function footer_html(): string {
        $text = trim( (string) ( My_IAPSNJ_Plugin::settings()['email_footer'] ?? '' ) );
        if ( $text === '' ) {
            $text = self::default_footer();
        }
        return nl2br( esc_html( $text ) );
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * A complete HTML email: $content (HTML) under the logo, in the FluentCRM
     * design with the footer. Falls back to a plain boxed layout of the same
     * shape when FluentCRM's template cannot render.
     */
    public static function wrap( string $content, string $preheader = '' ): string {
        $body = '';
        $logo = self::logo_url();
        if ( $logo !== '' ) {
            $body .= '<p style="margin:0 0 24px;"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( self::brand_name() ) . '" width="80" style="display:block;width:80px;max-width:100%;height:auto;border:0;"></p>';
        }
        $body  .= $content;
        $footer = self::footer_html();
        $design = self::design();
        $filter = 'fluent_crm/email-design-template-' . $design;

        if ( has_filter( $filter ) && class_exists( '\FluentCrm\App\Services\Helper' ) ) {
            try {
                // The global email styles (colours, fonts, widths) the
                // campaign and automation emails use.
                $config = \FluentCrm\App\Services\Helper::getTemplateConfig( $design, true );
                $config = is_array( $config ) ? $config : [];
                $config['design_template'] = $design;
                $html = apply_filters( $filter, $body, [
                    'preHeader'   => $preheader,
                    'email_body'  => $body,
                    'footer_text' => $footer,
                    'config'      => $config,
                ], false, null );
                if ( is_string( $html ) && stripos( $html, '<html' ) !== false ) {
                    return $html;
                }
            } catch ( \Throwable $e ) {
                error_log( 'My IAPSNJ: FluentCRM email template failed, using the built-in layout: ' . $e->getMessage() );
            }
        }
        return self::fallback_template( $body, $footer, $preheader );
    }

    private static function fallback_template( string $body, string $footer, string $preheader ): string {
        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
            . '<body style="margin:0;padding:0;background:#FAFAFA;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#202020;">'
            . ( $preheader !== '' ? '<span style="display:none;font-size:0;line-height:0;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . esc_html( $preheader ) . '</span>' : '' )
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAFAFA;"><tr><td align="center" style="padding:30px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:700px;background:#ffffff;"><tr><td style="padding:24px 20px;font-size:16px;line-height:160%;">' . $body . '</td></tr></table>'
            . ( $footer !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:700px;"><tr><td style="padding:20px;font-size:13px;line-height:150%;text-align:center;color:#202020;">' . $footer . '</td></tr></table>' : '' )
            . '</td></tr></table></body></html>';
    }

    /**
     * Plain text (WordPress core messages) as HTML paragraphs, with links.
     * WordPress's "###USERNAME###"-style placeholders, filled in after the
     * filter, are kept as they are.
     */
    public static function text_to_html( string $text ): string {
        $text = str_replace( [ "\r\n", "\r" ], "\n", trim( $text ) );
        $text = (string) preg_replace( '#<(https?://[^>\s]+)>#i', '$1', $text ); // "<https://…>" in older core messages
        $out  = '';
        foreach ( (array) preg_split( "/\n{2,}/", $text ) as $para ) {
            $para = trim( (string) $para );
            if ( $para !== '' ) {
                $out .= '<p>' . nl2br( self::linkify( $para ) ) . "</p>\n";
            }
        }
        return $out;
    }

    /**
     * Escape a text and turn its http(s) URLs into links. Done by hand, not
     * with make_clickable(): an escaped "&amp;" would cut the password-reset
     * link short.
     */
    private static function linkify( string $text ): string {
        $parts = preg_split( '#(https?://[^\s<>"\']+)#i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        $html  = '';
        foreach ( (array) $parts as $i => $part ) {
            $part = (string) $part;
            if ( $i % 2 === 0 ) {
                $html .= esc_html( $part );
                continue;
            }
            $trail = '';
            if ( preg_match( '/[.,;:!?)\]]+$/', $part, $m ) ) {
                $trail = $m[0];
                $part  = substr( $part, 0, -strlen( $trail ) );
            }
            $html .= '<a href="' . esc_url( $part ) . '">' . esc_html( $part ) . '</a>' . esc_html( $trail );
        }
        return $html;
    }

    /**
     * Headers with a single "Content-Type: text/html".
     *
     * @param string|string[] $headers
     * @return string[]
     */
    public static function html_headers( $headers ): array {
        $lines = is_array( $headers ) ? $headers : preg_split( "/\r\n|\r|\n/", (string) $headers );
        $out   = [];
        foreach ( (array) $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line !== '' && stripos( $line, 'content-type:' ) !== 0 ) {
                $out[] = $line;
            }
        }
        $out[] = 'Content-Type: text/html; charset=UTF-8';
        return $out;
    }

    /**
     * @param string|string[] $headers
     */
    private static function is_html( $headers ): bool {
        $text = is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers;
        return (bool) preg_match( '#content-type:\s*text/html#i', $text );
    }

    // -----------------------------------------------------------------------
    // WordPress account emails
    // -----------------------------------------------------------------------

    /**
     * One of WordPress's plain-text account emails (WP_FILTERS) in the design.
     * An email another plugin already made HTML (by header, or by writing
     * tags into the message) is left alone.
     *
     * @param mixed $email ['to','subject','message','headers']
     * @return mixed
     */
    public function brand_wp_email( $email ) {
        if ( ! is_array( $email ) || ! isset( $email['message'] ) || self::is_html( $email['headers'] ?? '' ) ) {
            return $email;
        }
        // Core's older "<https://…>" links are text, not tags.
        $probe = (string) preg_replace( '#<(https?://[^>\s]+)>#i', '$1', (string) $email['message'] );
        if ( $probe !== wp_strip_all_tags( $probe ) ) {
            return $email;
        }
        try {
            $message          = self::wrap( self::text_to_html( (string) $email['message'] ) );
            $email['headers'] = self::html_headers( $email['headers'] ?? '' );
            $email['message'] = $message;
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: could not apply the email design to a WordPress email: ' . $e->getMessage() );
        }
        return $email;
    }

    // -----------------------------------------------------------------------
    // FluentCart emails
    // -----------------------------------------------------------------------

    /**
     * fluent_cart/email_notification/mailer — FluentCart has built the email
     * in its own layout (EmailNotificationMailer::parseEmailContent). Build
     * the same content again (order header + notification body, smartcodes
     * resolved) inside the design instead. FluentCart Pro block emails keep
     * their block template; anything unexpected leaves FluentCart's email
     * as it was.
     *
     * @param mixed $mailer  \FluentCart\App\Services\Email\Mailer
     * @param mixed $context ['event','mail_name','recipient','notification','data']
     * @return mixed
     */
    public function brand_fluentcart_email( $mailer, $context = [] ) {
        $builder = '\FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder';
        if ( ! is_object( $mailer ) || ! method_exists( $mailer, 'body' ) || ! is_array( $context ) || ! class_exists( $builder ) || ! class_exists( '\FluentCart\App\App' ) ) {
            return $mailer;
        }
        try {
            $notification = is_array( $context['notification'] ?? null ) ? $context['notification'] : [];
            $data         = is_array( $context['data'] ?? null ) ? $context['data'] : [];
            $raw          = (string) ( $notification['body'] ?? '' );
            if ( trim( $raw ) === '' ) {
                return $mailer;
            }
            if ( ! empty( $notification['is_custom'] ) && (string) apply_filters( 'fluent_cart/parse_email_block_content', '', $raw, $data ) !== '' ) {
                return $mailer;
            }
            $order  = $data['order'] ?? null;
            $header = ( $order instanceof \FluentCart\App\Models\Order )
                ? (string) \FluentCart\App\App::make( 'view' )->make( 'emails.parts.order_header', $data )
                : '';
            // The order header opens with FluentCart's store brand (its store
            // logo when one is set): the logo is already on top, so the header
            // gets the name as text — one logo per email.
            $header = (string) preg_replace( '/\{\{\s*settings\.store_brand\s*\}\}/', esc_html( self::brand_name() ), $header );
            $content   = (string) $builder::make( $header . $raw, $data );
            $preheader = (string) $builder::make( (string) ( $notification['pre_header'] ?? '' ), $data );
            if ( trim( wp_strip_all_tags( $content ) ) === '' ) {
                return $mailer;
            }
            $mailer->body( self::wrap( $content, $preheader ) );
        } catch ( \Throwable $e ) {
            error_log( 'My IAPSNJ: could not apply the email design to a FluentCart email: ' . $e->getMessage() );
        }
        return $mailer;
    }

    // -----------------------------------------------------------------------
    // Preview / test (Settings → Configurations → Email design)
    // -----------------------------------------------------------------------

    /**
     * A sample of the member's "login details" email, as it will be sent.
     *
     * @return array{subject:string,html:string}
     */
    public static function sample(): array {
        $site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
        $message = sprintf( __( 'Username: %s', 'my-iapsnj' ), 'member' ) . "\r\n\r\n"
            . __( 'To set your password, visit the following address:', 'my-iapsnj' ) . "\r\n\r\n"
            . network_site_url( 'wp-login.php?login=member&key=SAMPLE&action=rp', 'login' ) . "\r\n\r\n"
            . wp_login_url() . "\r\n";
        return [
            /* translators: %s: site name */
            'subject' => sprintf( __( '[%s] Login Details', 'my-iapsnj' ), $site ),
            'html'    => self::wrap( self::text_to_html( $message ), __( 'Your IAPSNJ account is ready.', 'my-iapsnj' ) ),
        ];
    }

    /**
     * admin-post.php?action=my_iapsnj_email_preview — the sample, full page.
     */
    public function render_preview(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'my-iapsnj' ), 403 );
        }
        check_admin_referer( self::PREVIEW_ACTION );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );
        echo self::sample()['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by wrap(), every value escaped
        exit;
    }

    public static function preview_url(): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::PREVIEW_ACTION ), self::PREVIEW_ACTION );
    }

    /**
     * Send the sample to $to (Configurations → Send test).
     */
    public static function send_test( string $to ): bool {
        $sample = self::sample();
        return (bool) wp_mail( $to, $sample['subject'], $sample['html'], [ 'Content-Type: text/html; charset=UTF-8' ] );
    }
}
