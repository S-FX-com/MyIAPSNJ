<?php
/**
 * GitHub Releases updater for My IAPSNJ.
 *
 * Hooks into the WordPress plugin update mechanism and checks
 * https://api.github.com/repos/s-fx-com/my-iapsnj/releases/latest
 * for a newer version.
 *
 * @package My_IAPSNJ
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Github_Updater {

    /** GitHub repository owner. */
    const GITHUB_USER = 's-fx-com';

    /** GitHub repository name. */
    const GITHUB_REPO = 'my-iapsnj';

    /** Transient key for caching the latest release data (6 hours). */
    const TRANSIENT_KEY = 'my_iapsnj_github_release';

    /** @var string plugin_basename() of the main plugin file. */
    private $plugin_slug;

    /** @var string Absolute path to the main plugin file. */
    private $plugin_file;

    /** @var string Currently installed version. */
    private $current_version;

    public function __construct() {
        $this->plugin_file     = MY_IAPSNJ_FILE;
        $this->plugin_slug     = plugin_basename( MY_IAPSNJ_FILE );
        $this->current_version = MY_IAPSNJ_VERSION;

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );
        add_filter( 'plugin_action_links_' . plugin_basename( MY_IAPSNJ_FILE ), [ $this, 'action_links' ] );
        add_action( 'admin_init',                            [ $this, 'handle_manual_check' ] );
        add_action( 'admin_notices',                         [ $this, 'show_check_notice' ] );
    }

    // -------------------------------------------------------------------------
    // GitHub API
    // -------------------------------------------------------------------------

    private function get_release_data(): ?array {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_USER,
            self::GITHUB_REPO
        );

        $response = wp_remote_get( $api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) {
            return null;
        }

        set_transient( self::TRANSIENT_KEY, $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    private function tag_to_version( string $tag ): string {
        return ltrim( $tag, 'vV' );
    }

    // -------------------------------------------------------------------------
    // WordPress update hooks
    // -------------------------------------------------------------------------

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release_data();
        if ( null === $release ) {
            return $transient;
        }

        $remote_version = $this->tag_to_version( $release['tag_name'] );

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $remote_version,
                'url'         => esc_url( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
                'package'     => $release['zipball_url'],
                'requires'    => '5.8',
                'tested'      => '6.7',
            ];
        } else {
            $transient->no_update[ $this->plugin_slug ] = (object) [
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $this->current_version,
                'url'         => esc_url( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
                'package'     => '',
                'requires'    => '5.8',
                'tested'      => '6.7',
            ];
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( dirname( $this->plugin_slug ) !== $args->slug ) {
            return $result;
        }

        $release = $this->get_release_data();
        if ( null === $release ) {
            return $result;
        }

        $remote_version = $this->tag_to_version( $release['tag_name'] );

        return (object) [
            'name'          => 'My IAPSNJ',
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $remote_version,
            'author'        => '<a href="https://github.com/' . esc_attr( self::GITHUB_USER ) . '">S-FX</a>',
            'homepage'      => esc_url( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
            'requires'      => '5.8',
            'tested'        => '6.7',
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => [
                'description' => 'Member data sync and CRM tools for the IAPSNJ website.',
                'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( $release['body'] ) : 'See GitHub releases for changelog.',
            ],
            'download_link' => $release['zipball_url'],
        ];
    }

    public function post_install( $response, array $hook_extra, array $result ): array {
        global $wp_filesystem;

        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $result;
        }

        $proper_destination = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );
        $wp_filesystem->move( $result['destination'], $proper_destination, true );
        $result['destination'] = $proper_destination;

        if ( is_plugin_active( $this->plugin_slug ) ) {
            activate_plugin( $this->plugin_slug );
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Plugins-page "Check for Updates" link
    // -------------------------------------------------------------------------

    public function action_links( array $links ): array {
        $url = wp_nonce_url(
            add_query_arg( 'my_iapsnj_check_update', '1', self_admin_url( 'plugins.php' ) ),
            'my_iapsnj_check_update'
        );
        $links[] = '<a href="' . esc_url( $url ) . '">'
            . esc_html__( 'Check for Updates', 'my-iapsnj' )
            . '</a>';
        return $links;
    }

    public function handle_manual_check(): void {
        if ( empty( $_GET['my_iapsnj_check_update'] ) ) { // phpcs:ignore
            return;
        }

        check_admin_referer( 'my_iapsnj_check_update' );

        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'my-iapsnj' ) );
        }

        self::flush_cache();
        delete_site_transient( 'update_plugins' );

        $api_url  = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_USER,
            self::GITHUB_REPO
        );
        $response = wp_remote_get( $api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            $result = 'network_error';
        } else {
            $code = (int) wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 404 === $code || empty( $data['tag_name'] ) ) {
                $result = 'no_releases';
            } elseif ( 200 !== $code ) {
                $result = 'api_error';
            } elseif ( version_compare( $this->tag_to_version( $data['tag_name'] ), $this->current_version, '>' ) ) {
                set_transient( self::TRANSIENT_KEY, $data, 6 * HOUR_IN_SECONDS );
                $result = 'update_available';
            } else {
                set_transient( self::TRANSIENT_KEY, $data, 6 * HOUR_IN_SECONDS );
                $result = 'up_to_date';
            }
        }

        wp_safe_redirect(
            add_query_arg(
                [ 'my_iapsnj_update_result' => $result ],
                self_admin_url( 'plugins.php' )
            )
        );
        exit;
    }

    public function show_check_notice(): void {
        if ( empty( $_GET['my_iapsnj_update_result'] ) ) { // phpcs:ignore
            return;
        }

        $result = sanitize_key( $_GET['my_iapsnj_update_result'] ); // phpcs:ignore

        switch ( $result ) {
            case 'update_available':
                $release = $this->get_release_data();
                $version = $release ? $this->tag_to_version( $release['tag_name'] ) : '';
                $message = sprintf(
                    esc_html__( 'My IAPSNJ: version %s is available. Use the "Update now" link to install it.', 'my-iapsnj' ),
                    esc_html( $version )
                );
                $class = 'notice-warning';
                break;

            case 'up_to_date':
                $release        = $this->get_release_data();
                $github_version = $release ? $this->tag_to_version( $release['tag_name'] ) : $this->current_version;
                $message = sprintf(
                    esc_html__( 'My IAPSNJ: installed %1$s — latest GitHub release is %2$s. No update needed.', 'my-iapsnj' ),
                    esc_html( $this->current_version ),
                    esc_html( $github_version )
                );
                $class = 'notice-success';
                break;

            case 'no_releases':
                $message = esc_html__( 'My IAPSNJ: no releases have been published on GitHub yet.', 'my-iapsnj' );
                $class   = 'notice-info';
                break;

            case 'network_error':
                $message = esc_html__( 'My IAPSNJ: could not connect to GitHub. Please check that your server allows outbound HTTPS requests and try again.', 'my-iapsnj' );
                $class   = 'notice-error';
                break;

            default:
                $message = esc_html__( 'My IAPSNJ: GitHub returned an unexpected response. Please try again later.', 'my-iapsnj' );
                $class   = 'notice-error';
                break;
        }

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr( $class ),
            $message
        );
    }

    public static function flush_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
    }
}
