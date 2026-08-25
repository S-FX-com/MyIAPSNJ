<?php
/**
 * GitHub Releases updater for My IAPSNJ.
 *
 * Hooks into the WordPress plugin update mechanism and checks
 * https://api.github.com/repos/S-FX-com/MyIAPSNJ/releases/latest
 * for a newer version.
 *
 * The release workflow (.github/workflows/release.yml) attaches a
 * `my-iapsnj.zip` asset whose single top-level directory is `my-iapsnj/`,
 * which is what WordPress needs to unpack the update in place. When that
 * asset is missing we fall back to GitHub's generated source zipball and
 * rename the extracted directory in post_install().
 *
 * @package My_IAPSNJ
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Github_Updater {

    /**
     * GitHub repository owner.
     *
     * Must match the repository exactly. GitHub is case-insensitive on owner
     * and repo names but not on punctuation: the previous value pair
     * (s-fx-com / my-iapsnj) is a different name from S-FX-com/MyIAPSNJ and
     * resolved to a 404, so every update check silently failed.
     */
    const GITHUB_USER = 'S-FX-com';

    /** GitHub repository name. */
    const GITHUB_REPO = 'MyIAPSNJ';

    /** Release asset that carries a correctly structured plugin zip. */
    const RELEASE_ASSET = 'my-iapsnj.zip';

    /** Transient key for caching the latest release data (6 hours). */
    const TRANSIENT_KEY = 'my_iapsnj_github_release';

    /** Transient key used to back off after a failed API call. */
    const FAILURE_KEY = 'my_iapsnj_github_release_fail';

    /** @var string plugin_basename() of the main plugin file. */
    private $plugin_slug;

    /** @var string Directory name of the plugin (the WordPress "slug"). */
    private $plugin_dir;

    /** @var string Absolute path to the main plugin file. */
    private $plugin_file;

    /** @var string Currently installed version. */
    private $current_version;

    public function __construct() {
        $this->plugin_file     = MY_IAPSNJ_FILE;
        $this->plugin_slug     = plugin_basename( MY_IAPSNJ_FILE );
        $this->plugin_dir      = dirname( $this->plugin_slug );
        $this->current_version = MY_IAPSNJ_VERSION;

        // Update-check hooks must run everywhere, not just in wp-admin: the
        // `wp_update_plugins` cron event that drives background and automatic
        // updates executes in a front-end request where is_admin() is false.
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection',             [ $this, 'rename_source' ], 10, 4 );
        add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );

        // Admin-only UI.
        if ( is_admin() ) {
            add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'action_links' ] );
            add_action( 'admin_init',    [ $this, 'handle_manual_check' ] );
            add_action( 'admin_notices', [ $this, 'show_check_notice' ] );
        }
    }

    // -------------------------------------------------------------------------
    // GitHub API
    // -------------------------------------------------------------------------

    private function api_url(): string {
        return sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_USER,
            self::GITHUB_REPO
        );
    }

    /**
     * Fetch the latest release straight from GitHub, bypassing the cache.
     *
     * @return array{code:int, data:array|null, error:string}
     */
    private function fetch_release(): array {
        $response = wp_remote_get( $this->api_url(), [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'code' => 0, 'data' => null, 'error' => $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return [
            'code'  => $code,
            'data'  => is_array( $data ) ? $data : null,
            'error' => '',
        ];
    }

    /**
     * Cached latest-release payload, or null when unavailable.
     */
    private function get_release_data(): ?array {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        // Back off after a failure so a 404 or a rate limit does not fire an
        // outbound request on every single update check.
        if ( get_transient( self::FAILURE_KEY ) ) {
            return null;
        }

        $result = $this->fetch_release();

        if ( 200 !== $result['code'] || empty( $result['data']['tag_name'] ) ) {
            set_transient( self::FAILURE_KEY, 1, 15 * MINUTE_IN_SECONDS );
            return null;
        }

        set_transient( self::TRANSIENT_KEY, $result['data'], 6 * HOUR_IN_SECONDS );
        delete_transient( self::FAILURE_KEY );

        return $result['data'];
    }

    private function tag_to_version( string $tag ): string {
        return ltrim( trim( $tag ), 'vV' );
    }

    /**
     * Preferred download URL for a release.
     *
     * The workflow-built asset unpacks to `my-iapsnj/`, so WordPress installs
     * it straight over the existing directory. The generated zipball unpacks
     * to `S-FX-com-MyIAPSNJ-<sha>/` and only works because of rename_source().
     */
    private function get_package_url( array $release ): string {
        foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
            if ( ( $asset['name'] ?? '' ) === self::RELEASE_ASSET
                && ! empty( $asset['browser_download_url'] )
            ) {
                return (string) $asset['browser_download_url'];
            }
        }

        return (string) ( $release['zipball_url'] ?? '' );
    }

    // -------------------------------------------------------------------------
    // WordPress update hooks
    // -------------------------------------------------------------------------

    /**
     * @param mixed $transient
     * @return mixed
     */
    public function check_for_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release_data();
        if ( null === $release ) {
            return $transient;
        }

        $remote_version = $this->tag_to_version( (string) $release['tag_name'] );
        if ( '' === $remote_version ) {
            return $transient;
        }

        $common = [
            // `id` and `slug` are what the auto-update UI keys off; without
            // them WordPress will not offer "Enable auto-updates" for a plugin
            // that is not hosted on wordpress.org.
            'id'            => self::GITHUB_USER . '/' . self::GITHUB_REPO,
            'slug'          => $this->plugin_dir,
            'plugin'        => $this->plugin_slug,
            'url'           => esc_url_raw( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => '6.9',
            'compatibility' => new stdClass(),
        ];

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            $package = $this->get_package_url( $release );
            if ( '' === $package ) {
                return $transient;
            }

            unset( $transient->no_update[ $this->plugin_slug ] );
            $transient->response[ $this->plugin_slug ] = (object) array_merge( $common, [
                'new_version' => $remote_version,
                'package'     => $package,
            ] );
        } else {
            unset( $transient->response[ $this->plugin_slug ] );
            $transient->no_update[ $this->plugin_slug ] = (object) array_merge( $common, [
                'new_version' => $this->current_version,
                'package'     => '',
            ] );
        }

        return $transient;
    }

    /**
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( empty( $args->slug ) || $args->slug !== $this->plugin_dir ) {
            return $result;
        }

        $release = $this->get_release_data();
        if ( null === $release ) {
            return $result;
        }

        return (object) [
            'name'          => 'My IAPSNJ',
            'slug'          => $this->plugin_dir,
            'version'       => $this->tag_to_version( (string) $release['tag_name'] ),
            'author'        => '<a href="https://github.com/' . esc_attr( self::GITHUB_USER ) . '">S-FX</a>',
            'homepage'      => esc_url_raw( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => '6.9',
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => [
                'description' => 'Member data sync and CRM tools for the IAPSNJ website.',
                'changelog'   => ! empty( $release['body'] )
                    ? wp_kses_post( $release['body'] )
                    : 'See GitHub releases for changelog.',
            ],
            'download_link' => $this->get_package_url( $release ),
        ];
    }

    /**
     * Normalise the extracted directory name before WordPress installs it.
     *
     * GitHub's generated zipball extracts to `S-FX-com-MyIAPSNJ-<sha>/`.
     * Renaming here — before the install — is more reliable than moving the
     * directory afterwards, and leaves post_install() with nothing to do in
     * the common case.
     *
     * @param string|WP_Error $source
     * @param string          $remote_source
     * @param object          $upgrader
     * @param array           $hook_extra
     * @return string|WP_Error
     */
    public function rename_source( $source, $remote_source, $upgrader = null, $hook_extra = [] ) {
        global $wp_filesystem;

        if ( is_wp_error( $source ) ) {
            return $source;
        }
        if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_slug ) {
            return $source;
        }
        if ( ! $wp_filesystem ) {
            return $source;
        }

        $desired = trailingslashit( $remote_source ) . $this->plugin_dir;

        if ( untrailingslashit( $source ) === $desired ) {
            return $source;
        }

        if ( $wp_filesystem->exists( $desired ) ) {
            $wp_filesystem->delete( $desired, true );
        }

        if ( ! $wp_filesystem->move( $source, $desired ) ) {
            // Not fatal — post_install() still corrects the destination.
            return $source;
        }

        return trailingslashit( $desired );
    }

    /**
     * @param mixed $response
     * @param array $hook_extra
     * @param array $result
     * @return mixed
     */
    public function post_install( $response, $hook_extra = [], $result = [] ) {
        global $wp_filesystem;

        if ( ! is_array( $result ) ) {
            return $result;
        }
        if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_slug ) {
            return $result;
        }

        $proper_destination = WP_PLUGIN_DIR . '/' . $this->plugin_dir;

        if ( $wp_filesystem
            && ! empty( $result['destination'] )
            && untrailingslashit( $result['destination'] ) !== untrailingslashit( $proper_destination )
        ) {
            $wp_filesystem->move( $result['destination'], $proper_destination, true );
            $result['destination'] = $proper_destination;
        }

        self::flush_cache();

        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $this->plugin_slug ) ) {
            activate_plugin( $this->plugin_slug );
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Plugins-page "Check for Updates" link
    // -------------------------------------------------------------------------

    /**
     * @param mixed $links
     * @return mixed
     */
    public function action_links( $links ) {
        if ( ! is_array( $links ) ) {
            return $links;
        }

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

        $fetched = $this->fetch_release();

        if ( 0 === $fetched['code'] ) {
            $result = 'network_error';
        } elseif ( 404 === $fetched['code'] || empty( $fetched['data']['tag_name'] ) ) {
            $result = 'no_releases';
        } elseif ( 200 !== $fetched['code'] ) {
            $result = 'api_error';
        } else {
            set_transient( self::TRANSIENT_KEY, $fetched['data'], 6 * HOUR_IN_SECONDS );

            $result = version_compare(
                $this->tag_to_version( (string) $fetched['data']['tag_name'] ),
                $this->current_version,
                '>'
            ) ? 'update_available' : 'up_to_date';

            // Repopulate the update transient so the row updates immediately.
            wp_update_plugins();
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

        $result = sanitize_key( wp_unslash( $_GET['my_iapsnj_update_result'] ) ); // phpcs:ignore

        switch ( $result ) {
            case 'update_available':
                $release = $this->get_release_data();
                $version = $release ? $this->tag_to_version( (string) $release['tag_name'] ) : '';
                $message = sprintf(
                    /* translators: %s = version number */
                    esc_html__( 'My IAPSNJ: version %s is available. Use the "Update now" link to install it.', 'my-iapsnj' ),
                    esc_html( $version )
                );
                $class = 'notice-warning';
                break;

            case 'up_to_date':
                $release        = $this->get_release_data();
                $github_version = $release ? $this->tag_to_version( (string) $release['tag_name'] ) : $this->current_version;
                $message = sprintf(
                    /* translators: 1: installed version, 2: latest released version */
                    esc_html__( 'My IAPSNJ: installed %1$s — latest GitHub release is %2$s. No update needed.', 'my-iapsnj' ),
                    esc_html( $this->current_version ),
                    esc_html( $github_version )
                );
                $class = 'notice-success';
                break;

            case 'no_releases':
                $message = sprintf(
                    /* translators: %s = owner/repo */
                    esc_html__( 'My IAPSNJ: no releases found for %s on GitHub.', 'my-iapsnj' ),
                    esc_html( self::GITHUB_USER . '/' . self::GITHUB_REPO )
                );
                $class = 'notice-info';
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
            $message // Already escaped above.
        );
    }

    public static function flush_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
        delete_transient( self::FAILURE_KEY );
    }
}
