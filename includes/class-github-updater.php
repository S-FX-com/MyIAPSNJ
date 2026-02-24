<?php
/**
 * GitHub Releases updater.
 *
 * Hooks into the WordPress plugin update mechanism and checks
 * https://api.github.com/repos/S-FX-com/WP-FluentCRM-Sync/releases/latest
 * for a newer version. When a newer release tag is found, WordPress will
 * display the standard "update available" notice and handle the download.
 *
 * @package FCRM_WP_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class FCRM_WP_Sync_Github_Updater
 */
class FCRM_WP_Sync_Github_Updater {

	/** GitHub repository owner. */
	const GITHUB_USER = 'S-FX-com';

	/** GitHub repository name. */
	const GITHUB_REPO = 'WP-FluentCRM-Sync';

	/** Transient key for caching the latest release data (6 hours). */
	const TRANSIENT_KEY = 'fcrm_wp_sync_github_release';

	/** @var string plugin_basename() of the main plugin file. */
	private $plugin_slug;

	/** @var string Absolute path to the main plugin file. */
	private $plugin_file;

	/** @var string Currently installed version. */
	private $current_version;

	/**
	 * Constructor — registers all WordPress hooks.
	 */
	public function __construct() {
		$this->plugin_file     = FCRM_WP_SYNC_FILE;
		$this->plugin_slug     = plugin_basename( FCRM_WP_SYNC_FILE );
		$this->current_version = FCRM_WP_SYNC_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );
	}

	// -------------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------------

	/**
	 * Fetch the latest release data from the GitHub API.
	 *
	 * Results are cached in a site transient for 6 hours to avoid hammering
	 * the API on every admin page load.
	 *
	 * @return array|null Decoded release object, or null on failure.
	 */
	private function get_release_data(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$api_url  = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);

		$response = wp_remote_get(
			$api_url,
			[
				'headers' => [
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				],
				'timeout' => 10,
			]
		);

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

	/**
	 * Strip a leading "v" from a tag name so it can be compared with semver.
	 *
	 * @param string $tag e.g. "v1.2.0" or "1.2.0".
	 * @return string e.g. "1.2.0".
	 */
	private function tag_to_version( string $tag ): string {
		return ltrim( $tag, 'vV' );
	}

	// -------------------------------------------------------------------------
	// WordPress update hooks
	// -------------------------------------------------------------------------

	/**
	 * Inject update data into the WordPress update transient when a newer
	 * release is available on GitHub.
	 *
	 * Hooked to: pre_set_site_transient_update_plugins
	 *
	 * @param object $transient The update_plugins transient value.
	 * @return object Possibly modified transient.
	 */
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
		}

		return $transient;
	}

	/**
	 * Populate the plugin information modal (Plugins → "View version x.x.x details").
	 *
	 * Hooked to: plugins_api
	 *
	 * @param false|object|array $result  Current result (false = not handled yet).
	 * @param string             $action  API action name.
	 * @param object             $args    Request arguments.
	 * @return false|object Modified result or original if not our plugin.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		// $args->slug is the folder name, not the full basename.
		if ( dirname( $this->plugin_slug ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_release_data();
		if ( null === $release ) {
			return $result;
		}

		$remote_version = $this->tag_to_version( $release['tag_name'] );

		return (object) [
			'name'          => 'FluentCRM WordPress Sync',
			'slug'          => dirname( $this->plugin_slug ),
			'version'       => $remote_version,
			'author'        => '<a href="https://github.com/' . esc_attr( self::GITHUB_USER ) . '">S-FX</a>',
			'homepage'      => esc_url( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
			'requires'      => '5.8',
			'tested'        => '6.7',
			'last_updated'  => $release['published_at'] ?? '',
			'sections'      => [
				'description' => 'Bidirectional sync between FluentCRM contacts and WordPress users with configurable field mapping, ACF support, and mismatch resolution.',
				'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( $release['body'] ) : 'See GitHub releases for changelog.',
			],
			'download_link' => $release['zipball_url'],
		];
	}

	/**
	 * After WordPress installs the update, rename the unpacked directory from
	 * GitHub's hashed folder name (e.g. "S-FX-com-WP-FluentCRM-Sync-a1b2c3d")
	 * back to the expected plugin folder name.
	 *
	 * Hooked to: upgrader_post_install
	 *
	 * @param bool  $response   Install response.
	 * @param array $hook_extra Extra data about what was installed.
	 * @param array $result     Result data including destination path.
	 * @return array Modified result.
	 */
	public function post_install( $response, array $hook_extra, array $result ): array {
		global $wp_filesystem;

		// Only act on our own plugin.
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $result;
		}

		$proper_destination = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );

		$wp_filesystem->move( $result['destination'], $proper_destination, true );
		$result['destination'] = $proper_destination;

		// Re-activate if it was active before the update.
		if ( is_plugin_active( $this->plugin_slug ) ) {
			activate_plugin( $this->plugin_slug );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Cache management
	// -------------------------------------------------------------------------

	/**
	 * Delete the cached release transient so the next update check fetches
	 * fresh data from the GitHub API.
	 */
	public static function flush_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
