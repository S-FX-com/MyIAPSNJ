<?php
/**
 * My_IAPSNJ_REST_API
 *
 * Namespace my-iapsnj/v1. Every route requires manage_options.
 *
 *  GET  /status                – counts, plugin version, settings
 *  GET  /summary               – dashboard summary (members by type, pending checks …)
 *  GET  /fields                – WP + FluentCRM field lists
 *  GET  /mappings              – saved CRM → WP mirror map
 *  POST /mappings              – replace the mirror map
 *  POST /bulk-sync             – paginated CRM → WP mirror
 *  GET  /pending-checks        – unpaid check orders
 *  POST /checks/mark-paid      – batch mark paid
 *  POST /checks/record         – record a mailed check (creates + pays the order)
 *  GET  /reports/{type}        – open-applications | orders-without-application | aging | users-without-contact | contacts-missing-user
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_REST_API {

    private const NS = 'my-iapsnj/v1';

    /** @var self|null */
    private static ?self $instance = null;

    /** @var My_IAPSNJ_Field_Mapper */
    private My_IAPSNJ_Field_Mapper $mapper;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->mapper = new My_IAPSNJ_Field_Mapper();
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        $auth = [ $this, 'permissions_check' ];

        register_rest_route( self::NS, '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( self::NS, '/summary', [
            'methods'             => 'GET',
            'callback'            => fn() => rest_ensure_response( My_IAPSNJ_Reports::summary() ),
            'permission_callback' => $auth,
        ] );

        register_rest_route( self::NS, '/fields', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_fields' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( self::NS, '/mappings', [
            [
                'methods'             => 'GET',
                'callback'            => fn() => rest_ensure_response( $this->mapper->get_saved_mappings() ),
                'permission_callback' => $auth,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_mappings' ],
                'permission_callback' => $auth,
            ],
        ] );

        register_rest_route( self::NS, '/bulk-sync', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'bulk_sync' ],
            'permission_callback' => $auth,
            'args'                => [
                'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
                'offset'   => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
                'user_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
            ],
        ] );

        register_rest_route( self::NS, '/pending-checks', [
            'methods'             => 'GET',
            'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( My_IAPSNJ_Checks::pending( [ 'min_age_days' => (int) $r->get_param( 'min_age_days' ) ] ) ),
            'permission_callback' => $auth,
            'args'                => [ 'min_age_days' => [ 'type' => 'integer', 'default' => 0 ] ],
        ] );

        register_rest_route( self::NS, '/checks/mark-paid', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_paid' ],
            'permission_callback' => $auth,
            'args'                => [
                'order_ids'     => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
                'deposit_date'  => [ 'type' => 'string', 'default' => '' ],
                'check_numbers' => [ 'type' => 'object', 'default' => [] ],
                'note'          => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );

        register_rest_route( self::NS, '/checks/record', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'record_check' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( self::NS, '/reports/(?P<type>[a-z-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'report' ],
            'permission_callback' => $auth,
            'args'                => [
                'offset' => [ 'type' => 'integer', 'default' => 0 ],
                'days'   => [ 'type' => 'integer', 'default' => 0 ],
            ],
        ] );
    }

    public function permissions_check( \WP_REST_Request $request ): bool {
        return current_user_can( 'manage_options' );
    }

    // -----------------------------------------------------------------------
    // Callbacks
    // -----------------------------------------------------------------------

    public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
        return rest_ensure_response( [
            'total_wp_users'           => (int) count_users()['total_users'],
            'total_fluentcrm_contacts' => (int) \FluentCrm\App\Models\Subscriber::count(),
            'active_mappings'          => count( $this->mapper->get_active_mappings() ),
            'last_bulk_sync'           => get_option( 'my_iapsnj_last_bulk_sync', '' ),
            'plugin_version'           => MY_IAPSNJ_VERSION,
            'fluentcart'               => My_IAPSNJ_Membership::is_available(),
            'fluentforms'              => My_IAPSNJ_Applications::is_available(),
            'settings'                 => My_IAPSNJ_Plugin::settings(),
        ] );
    }

    public function get_fields( \WP_REST_Request $request ): \WP_REST_Response {
        return rest_ensure_response( [
            'wp_fields'   => array_values( $this->mapper->get_wp_fields() ),
            'fcrm_fields' => array_values( $this->mapper->get_fcrm_fields() ),
        ] );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function save_mappings( \WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'invalid_body', 'Body must be a JSON array of mapping objects.', [ 'status' => 400 ] );
        }
        $clean = My_IAPSNJ_Admin::sanitize_mapping_rows( $body, $this->mapper );
        $this->mapper->save_mappings( $clean );
        return rest_ensure_response( [ 'saved' => count( $clean ), 'mappings' => $clean ] );
    }

    public function bulk_sync( \WP_REST_Request $request ): \WP_REST_Response {
        $per_page = (int) $request->get_param( 'per_page' );
        $offset   = (int) $request->get_param( 'offset' );
        $user_ids = $request->get_param( 'user_ids' );
        return rest_ensure_response( My_IAPSNJ_Admin::run_bulk_mirror( $per_page, $offset, is_array( $user_ids ) ? $user_ids : [] ) );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function mark_paid( \WP_REST_Request $request ) {
        if ( ! My_IAPSNJ_Checks::is_available() ) {
            return new \WP_Error( 'no_fluentcart', 'FluentCart is not active.', [ 'status' => 409 ] );
        }
        $numbers = [];
        foreach ( (array) $request->get_param( 'check_numbers' ) as $k => $v ) {
            $numbers[ (int) $k ] = sanitize_text_field( (string) $v );
        }
        return rest_ensure_response( My_IAPSNJ_Checks::mark_paid(
            array_map( 'intval', (array) $request->get_param( 'order_ids' ) ),
            (string) $request->get_param( 'deposit_date' ),
            $numbers,
            (string) $request->get_param( 'note' )
        ) );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function record_check( \WP_REST_Request $request ) {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_params();
        }
        $result = My_IAPSNJ_Checks::record_check( (array) $params );
        if ( is_wp_error( $result ) ) {
            $result->add_data( [ 'status' => 400 ] );
            return $result;
        }
        return rest_ensure_response( $result );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function report( \WP_REST_Request $request ) {
        $type   = (string) $request->get_param( 'type' );
        $offset = (int) $request->get_param( 'offset' );
        $days   = (int) $request->get_param( 'days' );
        switch ( $type ) {
            case 'open-applications':
                return rest_ensure_response( My_IAPSNJ_Reports::applications_without_order( $days ) );
            case 'orders-without-application':
                return rest_ensure_response( My_IAPSNJ_Reports::orders_without_application( $days > 0 ? $days : 400 ) );
            case 'aging':
                return rest_ensure_response( My_IAPSNJ_Reports::aging_checks( $days ) );
            case 'users-without-contact':
                return rest_ensure_response( My_IAPSNJ_Reports::users_without_contact( $offset, 200 ) );
            case 'contacts-missing-user':
                return rest_ensure_response( My_IAPSNJ_Reports::contacts_with_missing_user( $offset, 500 ) );
        }
        return new \WP_Error( 'unknown_report', 'Unknown report type.', [ 'status' => 404 ] );
    }
}
