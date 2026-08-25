<?php
/**
 * My_IAPSNJ_REST_API
 *
 * Registers REST API endpoints for programmatic / external access.
 *
 * Base namespace: my-iapsnj/v1
 *
 * Endpoints
 * ---------
 *  GET  /status              – counts and last-sync timestamp
 *  GET  /fields              – all discoverable WP + FCRM field lists
 *  GET  /mappings            – current saved field mappings
 *  POST /mappings            – save field mappings (JSON body)
 *  POST /bulk-sync           – bulk sync (paginated)
 *  GET  /mismatches          – paginated mismatch list
 *  POST /mismatches/resolve  – resolve one field or entire user record
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_REST_API {

    private const NS = 'my-iapsnj/v1';

    /** @var self|null */
    private static ?self $instance = null;

    /** @var My_IAPSNJ_Field_Mapper */
    private My_IAPSNJ_Field_Mapper $mapper;

    /** @var My_IAPSNJ_Mismatch_Detector */
    private My_IAPSNJ_Mismatch_Detector $detector;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->mapper   = new My_IAPSNJ_Field_Mapper();
        $this->detector = new My_IAPSNJ_Mismatch_Detector();
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    // -----------------------------------------------------------------------
    // Route registration
    // -----------------------------------------------------------------------

    public function register_routes(): void {
        $auth = [ $this, 'permissions_check' ];

        register_rest_route( self::NS, '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
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
                'callback'            => [ $this, 'get_mappings' ],
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
                'direction' => [
                    'type'    => 'string',
                    'enum'    => [ 'wp_to_fcrm', 'fcrm_to_wp' ],
                    'default' => 'wp_to_fcrm',
                ],
                'per_page' => [
                    'type'    => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 200,
                ],
                'offset' => [
                    'type'    => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                ],
                'user_ids' => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'integer' ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/mismatches', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_mismatches' ],
            'permission_callback' => $auth,
            'args'                => [
                'page'     => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );

        register_rest_route( self::NS, '/mismatches/resolve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'resolve_mismatch' ],
            'permission_callback' => $auth,
            'args'                => [
                'user_id'    => [ 'type' => 'integer', 'required' => true ],
                'direction'  => [
                    'type'    => 'string',
                    'enum'    => [ 'use_wp', 'use_fcrm' ],
                    'default' => 'use_wp',
                ],
                'scope'      => [
                    'type'    => 'string',
                    'enum'    => [ 'field', 'all', 'empty' ],
                    'default' => 'field',
                ],
                'mapping_id' => [ 'type' => 'string' ],
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Permission check
    // -----------------------------------------------------------------------

    public function permissions_check( \WP_REST_Request $request ): bool {
        return current_user_can( 'manage_options' );
    }

    // -----------------------------------------------------------------------
    // Callbacks
    // -----------------------------------------------------------------------

    public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
        $total_users = count_users()['total_users'];
        $total_fcrm  = class_exists( '\FluentCrm\App\Models\Subscriber' )
            ? \FluentCrm\App\Models\Subscriber::count()
            : 0;
        $mappings    = $this->mapper->get_active_mappings();
        $last_sync   = get_option( 'my_iapsnj_last_bulk_sync', '' );

        return rest_ensure_response( [
            'total_wp_users'           => $total_users,
            'total_fluentcrm_contacts' => $total_fcrm,
            'active_mappings'          => count( $mappings ),
            'last_bulk_sync'           => $last_sync,
            'plugin_version'           => MY_IAPSNJ_VERSION,
            'settings'                 => get_option( 'my_iapsnj_settings', [] ),
        ] );
    }

    public function get_fields( \WP_REST_Request $request ): \WP_REST_Response {
        return rest_ensure_response( [
            'wp_fields'   => array_values( $this->mapper->get_wp_fields() ),
            'fcrm_fields' => array_values( $this->mapper->get_fcrm_fields() ),
        ] );
    }

    public function get_mappings( \WP_REST_Request $request ): \WP_REST_Response {
        return rest_ensure_response( $this->mapper->get_saved_mappings() );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function save_mappings( \WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'invalid_body', 'Body must be a JSON array of mapping objects.', [ 'status' => 400 ] );
        }

        $wp_fields   = $this->mapper->get_wp_fields();
        $fcrm_fields = $this->mapper->get_fcrm_fields();
        $clean       = [];

        foreach ( $body as $row ) {
            $wp_uid   = $row['wp_uid']   ?? '';
            $fcrm_uid = $row['fcrm_uid'] ?? '';

            if ( ! $wp_uid || ! $fcrm_uid ) {
                continue;
            }

            $wp_f   = $wp_fields[ $wp_uid ]     ?? null;
            $fcrm_f = $fcrm_fields[ $fcrm_uid ] ?? null;

            if ( ! $wp_f || ! $fcrm_f ) {
                continue;
            }

            // 'select' MUST be here: it is a first-class field type used by the
            // seeded member_status / state / department / rank mappings. Leaving
            // it out silently downgraded every one of them to 'text' on save,
            // discarding their value translation.
            $allowed_types      = [ 'text', 'select', 'date', 'checkbox', 'number', 'email', 'textarea' ];
            $allowed_directions = [ 'both', 'wp_to_fcrm', 'fcrm_to_wp' ];

            $field_type = in_array( $row['field_type'] ?? '', $allowed_types, true )
                ? $row['field_type']
                : 'text';

            $direction = in_array( $row['sync_direction'] ?? '', $allowed_directions, true )
                ? $row['sync_direction']
                : 'both';

            // Read-only WP fields (User ID, username, PMPro data) can only push.
            if ( ! empty( $wp_f['readonly'] ) ) {
                $direction = 'wp_to_fcrm';
            }

            $value_map = [];
            if ( $field_type === 'select' && ! empty( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
                foreach ( $row['value_map'] as $wp_val => $fcrm_val ) {
                    $wp_val   = sanitize_text_field( (string) $wp_val );
                    $fcrm_val = sanitize_text_field( (string) $fcrm_val );
                    if ( $wp_val !== '' && $fcrm_val !== '' ) {
                        $value_map[ $wp_val ] = $fcrm_val;
                    }
                }
            }

            $clean[] = [
                'id'                => sanitize_text_field( $row['id'] ?? My_IAPSNJ_Field_Mapper::generate_id() ),
                'wp_field_key'      => $wp_f['key'],
                'wp_field_source'   => $wp_f['source'],
                'wp_field_label'    => $wp_f['label'],
                'fcrm_field_key'    => $fcrm_f['key'],
                'fcrm_field_source' => $fcrm_f['source'],
                'fcrm_field_label'  => $fcrm_f['label'],
                'field_type'        => $field_type,
                'sync_direction'    => $direction,
                'enabled'           => (bool) ( $row['enabled'] ?? true ),
                'date_format_wp'    => sanitize_text_field( $row['date_format_wp'] ?? 'm/d/Y' ),
                'date_format_fcrm'  => 'Y-m-d',
                'acf_field_type'    => $wp_f['acf_field_type'] ?? '',
                'value_map'         => $value_map,
            ];
        }

        $this->mapper->save_mappings( $clean );

        return rest_ensure_response( [
            'saved'    => count( $clean ),
            'mappings' => $clean,
        ] );
    }

    public function bulk_sync( \WP_REST_Request $request ): \WP_REST_Response {
        $direction = $request->get_param( 'direction' );
        $per_page  = (int) $request->get_param( 'per_page' );
        $offset    = (int) $request->get_param( 'offset' );
        $user_ids  = $request->get_param( 'user_ids' );

        $engine  = My_IAPSNJ_Engine::get_instance();
        $success = [];
        $errors  = [];

        if ( $direction === 'wp_to_fcrm' ) {
            $args = [
                'number'  => $per_page,
                'offset'  => $offset,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ];
            if ( ! empty( $user_ids ) ) {
                $args['include'] = array_map( 'intval', (array) $user_ids );
                unset( $args['number'], $args['offset'] );
            }
            $users = get_users( $args );

            foreach ( $users as $user ) {
                try {
                    $engine->sync_wp_to_fcrm( $user->ID );
                    $success[] = [ 'user_id' => $user->ID, 'email' => $user->user_email ];
                } catch ( \Throwable $e ) {
                    $errors[] = [ 'user_id' => $user->ID, 'error' => $e->getMessage() ];
                }
            }
        } else {
            $query = \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )
                ->skip( $offset )
                ->take( $per_page );
            if ( ! empty( $user_ids ) ) {
                $query = \FluentCrm\App\Models\Subscriber::whereIn( 'user_id', array_map( 'intval', (array) $user_ids ) );
            }
            $contacts = $query->get();

            foreach ( $contacts as $contact ) {
                try {
                    $engine->sync_fcrm_to_wp( $contact );
                    $success[] = [ 'user_id' => $contact->user_id, 'email' => $contact->email ];
                } catch ( \Throwable $e ) {
                    $errors[] = [ 'subscriber_id' => $contact->id, 'error' => $e->getMessage() ];
                }
            }
        }

        // Count the side actually being paged: a WP-user total says nothing
        // about how many FluentCRM contacts remain in the fcrm_to_wp pass.
        $total = ( $direction === 'wp_to_fcrm' )
            ? (int) count_users()['total_users']
            : (int) \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )->count();

        $has_more = ! empty( $user_ids ) ? false : ( ( $offset + $per_page ) < $total );

        if ( ! $has_more ) {
            update_option( 'my_iapsnj_last_bulk_sync', current_time( 'mysql' ) );
        }

        return rest_ensure_response( [
            'status'      => 'completed',
            'synced'      => count( $success ),
            'errors'      => $errors,
            'has_more'    => $has_more,
            'next_offset' => $offset + $per_page,
            'total'       => $total,
        ] );
    }

    public function get_mismatches( \WP_REST_Request $request ): \WP_REST_Response {
        $page     = (int) $request->get_param( 'page' );
        $per_page = (int) $request->get_param( 'per_page' );

        $result = $this->detector->get_mismatches( $page, $per_page );
        return rest_ensure_response( $result );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function resolve_mismatch( \WP_REST_Request $request ) {
        $user_id    = (int) $request->get_param( 'user_id' );
        $direction  = $request->get_param( 'direction' );
        $scope      = $request->get_param( 'scope' );
        $mapping_id = (string) ( $request->get_param( 'mapping_id' ) ?? '' );

        $steps = [];

        try {
            if ( $scope === 'all' ) {
                $ok = $this->detector->resolve_user( $user_id, $direction );
            } elseif ( $scope === 'empty' ) {
                $ok = $this->detector->resolve_user_empty_fields( $user_id );
            } else {
                if ( $mapping_id === '' ) {
                    return new \WP_Error(
                        'missing_mapping_id',
                        'mapping_id is required when scope is "field".',
                        [ 'status' => 400 ]
                    );
                }
                // resolve_field() returns [ ok, steps ]. Testing the array
                // itself is always true, so a failed resolve reported success.
                $result = $this->detector->resolve_field( $user_id, $mapping_id, $direction );
                $ok     = ! empty( $result['ok'] );
                $steps  = $result['steps'] ?? [];
            }
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'resolve_failed', $e->getMessage(), [ 'status' => 500 ] );
        }

        if ( $ok ) {
            return rest_ensure_response( [ 'resolved' => true, 'steps' => $steps ] );
        }

        $message = 'Could not resolve mismatch.';
        foreach ( array_reverse( $steps ) as $step ) {
            if ( ( $step['status'] ?? '' ) === 'error' ) {
                $message = $step['text'];
                break;
            }
        }

        return new \WP_Error( 'resolve_failed', $message, [ 'status' => 500, 'steps' => $steps ] );
    }
}
