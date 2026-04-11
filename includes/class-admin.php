<?php
/**
 * My_IAPSNJ_Admin
 *
 * Registers the WordPress admin menu and renders three sub-pages:
 *  1. Field Mapping   – build the WP ↔ FluentCRM field map.
 *  2. Sync            – bulk-sync and view live status.
 *  3. Mismatches      – compare records side-by-side and resolve conflicts.
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Admin {

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

        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers
        add_action( 'wp_ajax_my_iapsnj_save_mappings',    [ $this, 'ajax_save_mappings' ] );
        add_action( 'wp_ajax_my_iapsnj_save_settings',    [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_my_iapsnj_get_fields',       [ $this, 'ajax_get_fields' ] );
        add_action( 'wp_ajax_my_iapsnj_bulk_sync',        [ $this, 'ajax_bulk_sync' ] );
        add_action( 'wp_ajax_my_iapsnj_resolve_mismatch', [ $this, 'ajax_resolve_mismatch' ] );
        add_action( 'wp_ajax_my_iapsnj_get_mismatches',   [ $this, 'ajax_get_mismatches' ] );
        add_action( 'wp_ajax_my_iapsnj_sync_all_empty',   [ $this, 'ajax_sync_all_empty' ] );
        add_action( 'wp_ajax_my_iapsnj_save_pmp_settings', [ $this, 'ajax_save_pmp_settings' ] );
        add_action( 'wp_ajax_my_iapsnj_search_users',     [ $this, 'ajax_search_users' ] );
        add_action( 'wp_ajax_my_iapsnj_sample_data',      [ $this, 'ajax_sample_data' ] );
        add_action( 'wp_ajax_my_iapsnj_pmp_setup_expiry_mapping', [ $this, 'ajax_pmp_setup_expiry_mapping' ] );
        add_action( 'wp_ajax_my_iapsnj_pmp_bulk_expiry_sync',     [ $this, 'ajax_pmp_bulk_expiry_sync' ] );
        add_action( 'wp_ajax_my_iapsnj_pmp_save_expiry_cron',     [ $this, 'ajax_pmp_save_expiry_cron' ] );
    }

    // -----------------------------------------------------------------------
    // Admin menu
    // -----------------------------------------------------------------------

    public function register_menu(): void {
        add_menu_page(
            __( 'My IAPSNJ', 'my-iapsnj' ),
            __( 'My IAPSNJ', 'my-iapsnj' ),
            'manage_options',
            'my-iapsnj',
            [ $this, 'render_field_mapping_page' ],
            'dashicons-shield',
            56
        );

        add_submenu_page(
            'my-iapsnj',
            __( 'Field Mapping', 'my-iapsnj' ),
            __( 'Field Mapping', 'my-iapsnj' ),
            'manage_options',
            'my-iapsnj',
            [ $this, 'render_field_mapping_page' ]
        );

        add_submenu_page(
            'my-iapsnj',
            __( 'Sync & Settings', 'my-iapsnj' ),
            __( 'Sync & Settings', 'my-iapsnj' ),
            'manage_options',
            'my-iapsnj-sync',
            [ $this, 'render_sync_page' ]
        );

        add_submenu_page(
            'my-iapsnj',
            __( 'Mismatch Resolver', 'my-iapsnj' ),
            __( 'Mismatch Resolver', 'my-iapsnj' ),
            'manage_options',
            'my-iapsnj-mismatches',
            [ $this, 'render_mismatches_page' ]
        );

        // Only show the Memberships page when PMPro is active.
        if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            add_submenu_page(
                'my-iapsnj',
                __( 'Memberships', 'my-iapsnj' ),
                __( 'Memberships', 'my-iapsnj' ),
                'manage_options',
                'my-iapsnj-pmp',
                [ $this, 'render_pmp_page' ]
            );
        }

        add_submenu_page(
            'my-iapsnj',
            __( 'CRM Assistant', 'my-iapsnj' ),
            __( 'CRM Assistant', 'my-iapsnj' ),
            'manage_options',
            'my-iapsnj-crm-assistant',
            [ $this, 'render_crm_assistant_page' ]
        );
    }

    // -----------------------------------------------------------------------
    // Asset enqueuing
    // -----------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        $pages = [
            'toplevel_page_my-iapsnj',
            'my-iapsnj_page_my-iapsnj-sync',
            'my-iapsnj_page_my-iapsnj-mismatches',
            'my-iapsnj_page_my-iapsnj-pmp',
            'my-iapsnj_page_my-iapsnj-crm-assistant',
        ];
        if ( ! in_array( $hook, $pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'my-iapsnj-admin',
            MY_IAPSNJ_URL . 'admin/css/admin.css',
            [],
            MY_IAPSNJ_VERSION
        );

        wp_enqueue_script(
            'my-iapsnj-admin',
            MY_IAPSNJ_URL . 'admin/js/admin.js',
            [ 'jquery', 'wp-util' ],
            MY_IAPSNJ_VERSION,
            true
        );

        wp_localize_script( 'my-iapsnj-admin', 'myIapsnj', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'my_iapsnj_nonce' ),
            'restUrl'   => rest_url( 'my-iapsnj/v1' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'dateFormat' => get_option( 'date_format', 'm/d/Y' ),
            'i18n'      => [
                'saving'           => __( 'Saving…', 'my-iapsnj' ),
                'saved'            => __( 'Saved!', 'my-iapsnj' ),
                'error'            => __( 'Error. Please try again.', 'my-iapsnj' ),
                'syncing'          => __( 'Syncing…', 'my-iapsnj' ),
                'syncDone'         => __( 'Sync complete.', 'my-iapsnj' ),
                'resolving'        => __( 'Resolving…', 'my-iapsnj' ),
                'resolved'         => __( 'Resolved!', 'my-iapsnj' ),
                'confirmDelete'    => __( 'Remove this mapping row?', 'my-iapsnj' ),
                'loading'          => __( 'Loading…', 'my-iapsnj' ),
                'noMappings'       => __( 'No active mappings to preview.', 'my-iapsnj' ),
                'noFluentCRM'      => __( 'No linked FluentCRM contact found for this user.', 'my-iapsnj' ),
                'previewWpField'   => __( 'WordPress Field', 'my-iapsnj' ),
                'previewWpVal'     => __( 'WP Value', 'my-iapsnj' ),
                'previewFcrmField' => __( 'FluentCRM Field', 'my-iapsnj' ),
                'previewFcrmVal'   => __( 'FCRM Value', 'my-iapsnj' ),
                'previewMatch'     => __( 'Match?', 'my-iapsnj' ),
                'setupMapping'     => __( 'Setting up\u2026', 'my-iapsnj' ),
                'mappingCreated'   => __( 'Mapping created successfully.', 'my-iapsnj' ),
                'mappingExists'    => __( 'Mapping already exists and is configured.', 'my-iapsnj' ),
                'fieldNotFound'    => __( 'FluentCRM expiration_date field not found. Create it in FluentCRM \u2192 Settings \u2192 Custom Fields first.', 'my-iapsnj' ),
                'syncingExpiry'    => __( 'Syncing expiration dates\u2026', 'my-iapsnj' ),
                'syncExpiryDone'   => __( 'Expiration date sync complete.', 'my-iapsnj' ),
                'chatSending'      => __( 'Thinking…', 'my-iapsnj' ),
                'chatError'        => __( 'Error communicating with AI provider.', 'my-iapsnj' ),
                'chatPlaceholder'  => __( 'Ask about your contacts, tags, or member data…', 'my-iapsnj' ),
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Page: Field Mapping
    // -----------------------------------------------------------------------

    public function render_field_mapping_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ) );
        }

        $wp_fields   = $this->mapper->get_wp_fields();
        $fcrm_fields = $this->mapper->get_fcrm_fields();
        $mappings    = $this->mapper->get_saved_mappings();

        // Sort WP fields for display; keep FCRM fields in natural FluentCRM order
        // (default fields first, then custom), sorted by label within each group.
        uasort( $wp_fields, fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );

        // Stable sort: default fields before custom, then alphabetical within each group.
        uasort( $fcrm_fields, function ( $a, $b ) {
            $ga = $a['source'] === 'default' ? 0 : 1;
            $gb = $b['source'] === 'default' ? 0 : 1;
            if ( $ga !== $gb ) {
                return $ga - $gb;
            }
            return strcmp( $a['label'], $b['label'] );
        } );

        // Index saved mappings by fcrm uid so we can pre-fill rows.
        $saved_by_fcrm = [];
        foreach ( $mappings as $m ) {
            $uid = ( $m['fcrm_field_source'] ?? '' ) . '__' . ( $m['fcrm_field_key'] ?? '' );
            $saved_by_fcrm[ $uid ][] = $m;
        }

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'My IAPSNJ – Field Mapping', 'my-iapsnj' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Every FluentCRM field is listed below. Choose a WordPress field to map it to, or leave "— Don\'t map —" to skip. Field Type is set automatically from the FluentCRM field. Use "+ Add Row" for extra custom pairings.', 'my-iapsnj' ); ?>
            </p>

            <div id="fcrm-mapping-notice" class="fcrm-notice" style="display:none"></div>

            <div class="fcrm-mapping-toolbar">
                <button id="fcrm-add-row" class="button button-secondary">
                    + <?php esc_html_e( 'Add Row', 'my-iapsnj' ); ?>
                </button>
                <button id="fcrm-save-mappings" class="button button-primary">
                    <?php esc_html_e( 'Save Mappings', 'my-iapsnj' ); ?>
                </button>
            </div>

            <div class="fcrm-mapping-table-wrap">
                <table class="widefat fcrm-mapping-table" id="fcrm-mapping-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'FluentCRM Field', 'my-iapsnj' ); ?></th>
                            <th><?php esc_html_e( 'WordPress Field', 'my-iapsnj' ); ?></th>
                            <th><?php esc_html_e( 'Field Type', 'my-iapsnj' ); ?></th>
                            <th><?php esc_html_e( 'Sync Direction', 'my-iapsnj' ); ?></th>
                            <th><?php esc_html_e( 'Enabled', 'my-iapsnj' ); ?></th>
                            <th><?php esc_html_e( 'Remove', 'my-iapsnj' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="fcrm-mapping-rows">
                        <?php
                        $rendered_fcrm_uids = [];

                        // One row per FluentCRM field, pre-filled with any saved WP mapping.
                        foreach ( $fcrm_fields as $uid => $fcrm_field ) {
                            $rendered_fcrm_uids[] = $uid;
                            if ( isset( $saved_by_fcrm[ $uid ] ) ) {
                                foreach ( $saved_by_fcrm[ $uid ] as $m ) {
                                    $this->render_mapping_row( $m, $wp_fields, $fcrm_fields );
                                }
                            } else {
                                // Synthetic mapping: FCRM side pre-set, WP side blank.
                                $this->render_mapping_row(
                                    [
                                        'fcrm_field_key'    => $fcrm_field['key'],
                                        'fcrm_field_source' => $fcrm_field['source'],
                                        'fcrm_field_label'  => $fcrm_field['label'],
                                        'field_type'        => $fcrm_field['type'],
                                        'sync_direction'    => 'both',
                                        'enabled'           => false,
                                    ],
                                    $wp_fields,
                                    $fcrm_fields
                                );
                            }
                        }

                        // Orphaned saved mappings whose FCRM field no longer exists.
                        foreach ( $mappings as $m ) {
                            $fcrm_uid = ( $m['fcrm_field_source'] ?? '' ) . '__' . ( $m['fcrm_field_key'] ?? '' );
                            if ( ! in_array( $fcrm_uid, $rendered_fcrm_uids, true ) ) {
                                $this->render_mapping_row( $m, $wp_fields, $fcrm_fields );
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Hidden row template (cloned by JS for "+ Add Row") -->
            <template id="fcrm-row-template">
                <?php $this->render_mapping_row( [], $wp_fields, $fcrm_fields, true ); ?>
            </template>

            <!-- Serialised field data passed to JS -->
            <script id="fcrm-wp-fields-data" type="application/json">
                <?php echo wp_json_encode( array_values( $wp_fields ) ); ?>
            </script>
            <script id="fcrm-fcrm-fields-data" type="application/json">
                <?php echo wp_json_encode( array_values( $fcrm_fields ) ); ?>
            </script>

            <!-- Sample Data Preview -->
            <div class="fcrm-section" id="fcrm-preview-section" style="margin-top:28px">
                <h2><?php esc_html_e( 'Sample Data Preview', 'my-iapsnj' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Select a WordPress user to preview how their data currently sits in both WordPress and FluentCRM, side by side.', 'my-iapsnj' ); ?>
                </p>

                <div class="fcrm-preview-search">
                    <div class="fcrm-user-search-wrap">
                        <input type="text"
                               id="fcrm-preview-user-input"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Search by name, email or username…', 'my-iapsnj' ); ?>"
                               autocomplete="off">
                        <div id="fcrm-user-suggestions" class="fcrm-user-suggestions" style="display:none"></div>
                    </div>
                    <button id="fcrm-preview-load" class="button button-primary" disabled>
                        <?php esc_html_e( 'Preview Data', 'my-iapsnj' ); ?>
                    </button>
                </div>

                <div id="fcrm-preview-results" style="display:none; margin-top:16px"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single mapping table row (or a blank template row).
     *
     * Column order: FluentCRM Field | WordPress Field | Field Type | Direction | Enabled | Remove
     */
    private function render_mapping_row( array $mapping, array $wp_fields, array $fcrm_fields, bool $is_template = false ): void {
        $id           = $mapping['id']                 ?? '';
        $wp_key       = $mapping['wp_field_key']       ?? '';
        $wp_src       = $mapping['wp_field_source']    ?? '';
        $fcrm_key     = $mapping['fcrm_field_key']     ?? '';
        $fcrm_src     = $mapping['fcrm_field_source']  ?? '';
        $field_type   = $mapping['field_type']         ?? 'text';
        $direction    = $mapping['sync_direction']     ?? 'both';
        $enabled      = ! empty( $mapping['enabled'] );
        $date_fmt_wp  = $mapping['date_format_wp']     ?? 'm/d/Y';
        $value_map    = $mapping['value_map']          ?? [];

        $row_id = $is_template ? '__TEMPLATE__' : ( $id ?: My_IAPSNJ_Field_Mapper::generate_id() );

        // Human-readable labels used for hint text beneath each dropdown.
        $wp_source_labels = [
            'user' => 'WordPress User',
            'meta' => 'User Meta',
            'acf'  => 'ACF',
            'pmp'  => 'Paid Memberships Pro',
        ];
        $acf_type_labels  = [
            'date_picker'      => 'Date Picker',
            'date_time_picker' => 'Date & Time',
            'time_picker'      => 'Time Picker',
            'checkbox'         => 'Checkbox',
            'radio'            => 'Radio Button',
            'select'           => 'Dropdown',
            'number'           => 'Number',
            'email'            => 'Email',
            'textarea'         => 'Textarea',
            'wysiwyg'          => 'Rich Text',
            'url'              => 'URL',
            'text'             => 'Text',
        ];
        $sync_type_labels = [
            'text'     => 'Text',
            'email'    => 'Email',
            'date'     => 'Date',
            'number'   => 'Number',
            'select'   => 'Dropdown',
            'checkbox' => 'Checkbox',
            'textarea' => 'Textarea',
        ];

        // Helper: type-label string for a WP field entry.
        $wp_type_label = function ( array $f ) use ( $acf_type_labels, $sync_type_labels ): string {
            if ( $f['source'] === 'acf' && ! empty( $f['acf_field_type'] ) ) {
                return $acf_type_labels[ $f['acf_field_type'] ] ?? ucfirst( str_replace( '_', ' ', $f['acf_field_type'] ) );
            }
            return $sync_type_labels[ $f['type'] ] ?? ucfirst( $f['type'] );
        };

        // Helper: type-label string for a FCRM field entry.
        $fcrm_type_label = function ( array $f ) use ( $sync_type_labels ): string {
            return $sync_type_labels[ $f['type'] ] ?? ucfirst( $f['type'] );
        };

        echo '<tr class="fcrm-mapping-row" data-id="' . esc_attr( $row_id ) . '">';

        // --- Column 1: FluentCRM Field ---
        // Compute the initial hint text (JS will keep it live on change).
        $selected_fcrm_uid = $fcrm_src . '__' . $fcrm_key;
        $sel_fcrm_f        = $fcrm_fields[ $selected_fcrm_uid ] ?? null;
        $fcrm_hint_text    = '';
        if ( $sel_fcrm_f ) {
            $src_lbl        = $sel_fcrm_f['source'] === 'custom' ? 'FluentCRM Custom' : 'FluentCRM';
            $fcrm_hint_text = $src_lbl . ': ' . $fcrm_type_label( $sel_fcrm_f );
        }

        echo '<td>';
        echo '<select class="fcrm-fcrm-field" name="mappings[' . esc_attr( $row_id ) . '][fcrm_uid]">';
        echo '<option value="">' . esc_html__( '— Select FluentCRM field —', 'my-iapsnj' ) . '</option>';
        foreach ( $fcrm_fields as $uid => $f ) {
            $selected     = ( $f['key'] === $fcrm_key && $f['source'] === $fcrm_src ) ? ' selected' : '';
            $options_json = wp_json_encode( $f['options'] ?? [] );
            $src_lbl      = $f['source'] === 'custom' ? 'FluentCRM Custom' : 'FluentCRM';
            $t_lbl        = $fcrm_type_label( $f );
            printf(
                '<option value="%s" data-type="%s" data-label="%s" data-options="%s" data-source-label="%s" data-type-label="%s"%s>%s</option>',
                esc_attr( $uid ),
                esc_attr( $f['type'] ),
                esc_attr( $f['label'] ),
                esc_attr( $options_json ),
                esc_attr( $src_lbl ),
                esc_attr( $t_lbl ),
                $selected,
                esc_html( $f['label'] )
            );
        }
        echo '</select>';
        echo '<p class="fcrm-field-hint fcrm-fcrm-hint">' . esc_html( $fcrm_hint_text ) . '</p>';
        echo '</td>';

        // --- Column 2: WordPress Field ---
        // Find the currently selected WP field for hint text + readonly detection.
        $wp_uid_selected = '';
        foreach ( $wp_fields as $uid => $f ) {
            if ( $f['key'] === $wp_key && $f['source'] === $wp_src ) {
                $wp_uid_selected = $uid;
                break;
            }
        }
        $sel_wp_f     = $wp_fields[ $wp_uid_selected ] ?? null;
        $wp_hint_text = '';
        if ( $sel_wp_f ) {
            $src_lbl      = $wp_source_labels[ $sel_wp_f['source'] ] ?? $sel_wp_f['source'];
            $wp_hint_text = $src_lbl . ': ' . $wp_type_label( $sel_wp_f );
        }

        $dir_is_locked = $sel_wp_f && ! empty( $sel_wp_f['readonly'] );
        if ( $dir_is_locked ) {
            $direction = 'wp_to_fcrm';
        }

        echo '<td>';
        echo '<select class="fcrm-wp-field" name="mappings[' . esc_attr( $row_id ) . '][wp_uid]">';
        echo '<option value="">' . esc_html__( '— Don\'t map —', 'my-iapsnj' ) . '</option>';
        foreach ( $wp_fields as $uid => $f ) {
            $selected      = ( $f['key'] === $wp_key && $f['source'] === $wp_src ) ? ' selected' : '';
            $is_readonly   = ! empty( $f['readonly'] ) ? 1 : 0;
            $options_json  = wp_json_encode( $f['options'] ?? [] );
            $date_fmt_attr = esc_attr( $f['date_format_wp'] ?? '' );
            $src_lbl       = $wp_source_labels[ $f['source'] ] ?? $f['source'];
            $t_lbl         = $wp_type_label( $f );
            printf(
                '<option value="%s" data-type="%s" data-label="%s" data-readonly="%d" data-options="%s" data-date-format="%s" data-source-label="%s" data-type-label="%s"%s>%s</option>',
                esc_attr( $uid ),
                esc_attr( $f['type'] ),
                esc_attr( $f['label'] ),
                $is_readonly,
                esc_attr( $options_json ),
                $date_fmt_attr,
                esc_attr( $src_lbl ),
                esc_attr( $t_lbl ),
                $selected,
                esc_html( $f['label'] )
            );
        }
        echo '</select>';
        echo '<p class="fcrm-field-hint fcrm-wp-hint">' . esc_html( $wp_hint_text ) . '</p>';
        echo '</td>';

        // --- Column 3: Field Type ---
        $types = [
            'text'     => __( 'Text', 'my-iapsnj' ),
            'select'   => __( 'Select / Radio', 'my-iapsnj' ),
            'date'     => __( 'Date', 'my-iapsnj' ),
            'checkbox' => __( 'Checkbox / Multi-select', 'my-iapsnj' ),
            'number'   => __( 'Number', 'my-iapsnj' ),
            'email'    => __( 'Email', 'my-iapsnj' ),
            'textarea' => __( 'Textarea', 'my-iapsnj' ),
        ];
        echo '<td>';
        echo '<select class="fcrm-field-type" name="mappings[' . esc_attr( $row_id ) . '][field_type]">';
        foreach ( $types as $val => $label ) {
            $sel = selected( $field_type, $val, false );
            echo "<option value=\"{$val}\"{$sel}>{$label}</option>";
        }
        echo '</select>';
        // Date format input (shown/hidden via JS when type === 'date')
        echo '<div class="fcrm-date-format-wrap" style="margin-top:4px">';
        echo '<small>' . esc_html__( 'WP date format:', 'my-iapsnj' ) . ' </small>';
        echo '<input type="text" class="fcrm-date-format-wp small-text" value="' . esc_attr( $date_fmt_wp ) . '" placeholder="m/d/Y" name="mappings[' . esc_attr( $row_id ) . '][date_format_wp]">';
        echo '</div>';
        // Hidden input carries the saved value_map JSON for JS to read on page-load
        echo '<input type="hidden" class="fcrm-value-map-json" value="' . esc_attr( wp_json_encode( $value_map ) ) . '">';
        echo '</td>';

        // --- Column 4: Sync Direction ---
        $directions = [
            'both'       => __( '⇄ Both', 'my-iapsnj' ),
            'wp_to_fcrm' => __( '→ WP → FluentCRM', 'my-iapsnj' ),
            'fcrm_to_wp' => __( '← FluentCRM → WP', 'my-iapsnj' ),
        ];
        echo '<td>';
        $dir_disabled = $dir_is_locked ? ' disabled' : '';
        echo '<select class="fcrm-sync-direction" name="mappings[' . esc_attr( $row_id ) . '][sync_direction]"' . $dir_disabled . '>';
        foreach ( $directions as $val => $label ) {
            $sel = selected( $direction, $val, false );
            echo "<option value=\"{$val}\"{$sel}>{$label}</option>";
        }
        echo '</select>';
        if ( $dir_is_locked ) {
            echo '<small style="display:block;color:#888">' . esc_html__( 'Read-only field: WP→FluentCRM only', 'my-iapsnj' ) . '</small>';
        }
        echo '</td>';

        // --- Column 5: Enabled toggle ---
        $chk = $enabled ? ' checked' : '';
        echo '<td style="text-align:center">';
        echo '<input type="checkbox" class="fcrm-enabled" name="mappings[' . esc_attr( $row_id ) . '][enabled]" value="1"' . $chk . '>';
        echo '</td>';

        // --- Column 6: Remove button ---
        echo '<td style="text-align:center">';
        echo '<button type="button" class="button fcrm-remove-row" title="' . esc_attr__( 'Remove', 'my-iapsnj' ) . '">✕</button>';
        echo '</td>';

        echo '</tr>';
    }

    // -----------------------------------------------------------------------
    // Page: Sync & Settings
    // -----------------------------------------------------------------------

    public function render_sync_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ) );
        }

        $settings       = get_option( 'my_iapsnj_settings', [] );
        $last_sync      = get_option( 'my_iapsnj_last_bulk_sync', '' );
        $total_users    = count_users()['total_users'];
        $total_fcrm     = class_exists( '\FluentCrm\App\Models\Subscriber' )
            ? \FluentCrm\App\Models\Subscriber::count()
            : 0;
        $active_mappings = $this->mapper->get_active_mappings();

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'My IAPSNJ – Sync & Settings', 'my-iapsnj' ); ?></h1>

            <!-- Status cards -->
            <div class="fcrm-status-cards">
                <div class="fcrm-card">
                    <span class="fcrm-card-number"><?php echo esc_html( $total_users ); ?></span>
                    <span class="fcrm-card-label"><?php esc_html_e( 'WordPress Users', 'my-iapsnj' ); ?></span>
                </div>
                <div class="fcrm-card">
                    <span class="fcrm-card-number"><?php echo esc_html( $total_fcrm ); ?></span>
                    <span class="fcrm-card-label"><?php esc_html_e( 'FluentCRM Contacts', 'my-iapsnj' ); ?></span>
                </div>
                <?php if ( $last_sync ) : ?>
                <div class="fcrm-card">
                    <span class="fcrm-card-number" style="font-size:14px"><?php echo esc_html( $last_sync ); ?></span>
                    <span class="fcrm-card-label"><?php esc_html_e( 'Last Bulk Sync', 'my-iapsnj' ); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bulk sync controls -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Bulk Sync', 'my-iapsnj' ); ?></h2>
                <p><?php esc_html_e( 'Sync all records in batch. Large sites may take several minutes. The operation runs in pages to avoid timeouts.', 'my-iapsnj' ); ?></p>

                <?php if ( ! empty( $active_mappings ) ) : ?>
                <div class="fcrm-field-selection">
                    <div class="fcrm-field-selection-header">
                        <strong><?php esc_html_e( 'Fields to sync', 'my-iapsnj' ); ?></strong>
                        <span class="fcrm-field-sel-toggle">
                            <a href="#" id="fcrm-field-sel-all"><?php esc_html_e( 'All', 'my-iapsnj' ); ?></a>
                            &nbsp;/&nbsp;
                            <a href="#" id="fcrm-field-sel-none"><?php esc_html_e( 'None', 'my-iapsnj' ); ?></a>
                        </span>
                    </div>
                    <div class="fcrm-field-selection-list">
                        <?php foreach ( $active_mappings as $mapping ) : ?>
                            <?php
                            $map_id    = esc_attr( $mapping['id'] );
                            $wp_label  = esc_html( $mapping['wp_field_label']   ?? $mapping['wp_field_key'] );
                            $crm_label = esc_html( $mapping['fcrm_field_label'] ?? $mapping['fcrm_field_key'] );
                            $dir_map   = [
                                'both'       => '↔',
                                'wp_to_fcrm' => '→',
                                'fcrm_to_wp' => '←',
                            ];
                            $dir_icon = $dir_map[ $mapping['sync_direction'] ?? 'both' ] ?? '↔';
                            ?>
                            <label class="fcrm-field-sel-item">
                                <input type="checkbox"
                                       class="fcrm-field-sel-cb"
                                       name="field_ids[]"
                                       value="<?php echo $map_id; ?>"
                                       checked>
                                <span class="fcrm-field-sel-dir"><?php echo esc_html( $dir_icon ); ?></span>
                                <?php echo $wp_label; ?> &rarr; <?php echo $crm_label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="fcrm-bulk-controls">
                    <button id="fcrm-bulk-wp-to-fcrm" class="button button-primary">
                        <?php esc_html_e( 'Sync WP → FluentCRM', 'my-iapsnj' ); ?>
                    </button>
                    <button id="fcrm-bulk-fcrm-to-wp" class="button button-secondary">
                        <?php esc_html_e( 'Sync FluentCRM → WP', 'my-iapsnj' ); ?>
                    </button>
                </div>

                <div id="fcrm-bulk-progress" style="display:none; margin-top:16px">
                    <div class="fcrm-progress-bar-wrap">
                        <div id="fcrm-progress-bar" class="fcrm-progress-bar" style="width:0%"></div>
                    </div>
                    <p id="fcrm-bulk-status"></p>
                </div>
            </div>

            <!-- Settings -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Sync Settings', 'my-iapsnj' ); ?></h2>
                <form id="fcrm-settings-form">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'On User Register', 'my-iapsnj' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_user_register" value="1"
                                        <?php checked( ! empty( $settings['sync_on_user_register'] ) ); ?>>
                                    <?php esc_html_e( 'Sync new WP user to FluentCRM', 'my-iapsnj' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'On Profile Update', 'my-iapsnj' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_profile_update" value="1"
                                        <?php checked( ! empty( $settings['sync_on_profile_update'] ) ); ?>>
                                    <?php esc_html_e( 'Sync WP user changes to FluentCRM', 'my-iapsnj' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'On User Delete', 'my-iapsnj' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_user_delete" value="1"
                                        <?php checked( ! empty( $settings['sync_on_user_delete'] ) ); ?>>
                                    <?php esc_html_e( 'Unlink subscriber when WP user is deleted', 'my-iapsnj' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'On FluentCRM Update', 'my-iapsnj' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_fcrm_update" value="1"
                                        <?php checked( ! empty( $settings['sync_on_fcrm_update'] ) ); ?>>
                                    <?php esc_html_e( 'Sync FluentCRM contact changes to WP user', 'my-iapsnj' ); ?>
                                </label>
                            </td>
                        </tr>
                        <?php if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'On PMP Membership Change', 'my-iapsnj' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_pmp_change" value="1"
                                        <?php checked( ! empty( $settings['sync_on_pmp_change'] ) ); ?>>
                                    <?php esc_html_e( 'Sync WP user to FluentCRM when their PMPro membership level changes', 'my-iapsnj' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Pushes PMP date fields (join date, expiration date) to any mapped FluentCRM fields on every membership change.', 'my-iapsnj' ); ?>
                                </p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <div id="fcrm-settings-notice" class="fcrm-notice" style="display:none"></div>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Settings', 'my-iapsnj' ); ?>
                    </button>
                </form>
            </div>

            <!-- AI Provider Settings -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'AI CRM Assistant Settings', 'my-iapsnj' ); ?></h2>
                <form id="my-iapsnj-ai-settings-form">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'AI Provider', 'my-iapsnj' ); ?></th>
                            <td>
                                <select name="ai_provider" id="my-iapsnj-ai-provider">
                                    <option value="anthropic" <?php selected( $settings['ai_provider'] ?? 'anthropic', 'anthropic' ); ?>>
                                        <?php esc_html_e( 'Anthropic (Claude)', 'my-iapsnj' ); ?>
                                    </option>
                                    <option value="openai" <?php selected( $settings['ai_provider'] ?? '', 'openai' ); ?>>
                                        <?php esc_html_e( 'OpenAI (GPT-4o)', 'my-iapsnj' ); ?>
                                    </option>
                                    <option value="gemini" <?php selected( $settings['ai_provider'] ?? '', 'gemini' ); ?>>
                                        <?php esc_html_e( 'Google Gemini', 'my-iapsnj' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Anthropic API Key', 'my-iapsnj' ); ?></th>
                            <td>
                                <input type="password" name="anthropic_api_key" class="regular-text"
                                       value="<?php echo esc_attr( $settings['anthropic_api_key'] ?? '' ); ?>"
                                       placeholder="sk-ant-...">
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'OpenAI API Key', 'my-iapsnj' ); ?></th>
                            <td>
                                <input type="password" name="openai_api_key" class="regular-text"
                                       value="<?php echo esc_attr( $settings['openai_api_key'] ?? '' ); ?>"
                                       placeholder="sk-...">
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Gemini API Key', 'my-iapsnj' ); ?></th>
                            <td>
                                <input type="password" name="gemini_api_key" class="regular-text"
                                       value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>"
                                       placeholder="AIza...">
                            </td>
                        </tr>
                    </table>

                    <div id="my-iapsnj-ai-settings-notice" class="fcrm-notice" style="display:none"></div>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save AI Settings', 'my-iapsnj' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Page: Mismatch Resolver
    // -----------------------------------------------------------------------

    public function render_mismatches_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ) );
        }

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'My IAPSNJ – Mismatch Resolver', 'my-iapsnj' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Records below have at least one field where WP and FluentCRM values differ. Choose which value to keep, or skip.', 'my-iapsnj' ); ?>
            </p>

            <div class="fcrm-mismatch-controls">
                <button id="fcrm-scan-mismatches" class="button button-primary">
                    <?php esc_html_e( 'Scan for Mismatches', 'my-iapsnj' ); ?>
                </button>
                <button id="fcrm-sync-all-empty-global" class="button button-secondary" style="margin-left:8px">
                    <?php esc_html_e( 'Sync All Empty Fields (All Records)', 'my-iapsnj' ); ?>
                </button>
                <span id="fcrm-scan-status" style="margin-left:12px"></span>
            </div>

            <div id="fcrm-resolve-notice" class="fcrm-notice" style="display:none; margin-top:12px"></div>

            <div id="fcrm-mismatches-container" style="margin-top:20px">
                <p class="fcrm-placeholder"><?php esc_html_e( 'Click "Scan for Mismatches" to begin.', 'my-iapsnj' ); ?></p>
            </div>

            <div id="fcrm-mismatch-pagination" style="display:none; margin-top:12px">
                <button id="fcrm-prev-page" class="button">&laquo; <?php esc_html_e( 'Previous', 'my-iapsnj' ); ?></button>
                <span id="fcrm-page-info" style="margin:0 8px"></span>
                <button id="fcrm-next-page" class="button"><?php esc_html_e( 'Next', 'my-iapsnj' ); ?> &raquo;</button>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // AJAX handlers
    // -----------------------------------------------------------------------

    public function ajax_save_mappings(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $raw      = isset( $_POST['mappings'] ) ? (array) $_POST['mappings'] : []; // phpcs:ignore
        $wp_fields   = $this->mapper->get_wp_fields();
        $fcrm_fields = $this->mapper->get_fcrm_fields();

        $clean = [];
        foreach ( $raw as $row_id => $row ) {
            $wp_uid   = sanitize_text_field( $row['wp_uid']   ?? '' );
            $fcrm_uid = sanitize_text_field( $row['fcrm_uid'] ?? '' );

            if ( ! $wp_uid || ! $fcrm_uid ) {
                continue;
            }

            $wp_f   = $wp_fields[ $wp_uid ]   ?? null;
            $fcrm_f = $fcrm_fields[ $fcrm_uid ] ?? null;

            if ( ! $wp_f || ! $fcrm_f ) {
                continue;
            }

            // Readonly fields (e.g. User ID) may only sync WP → FluentCRM.
            $field_type   = sanitize_text_field( $row['field_type'] ?? 'text' );
            $direction    = sanitize_text_field( $row['sync_direction'] ?? 'both' );
            if ( ! empty( $wp_f['readonly'] ) ) {
                $direction = 'wp_to_fcrm';
            }

            // Sanitise and store value_map for select/radio fields.
            $value_map = [];
            if ( $field_type === 'select' && ! empty( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
                foreach ( $row['value_map'] as $wp_val => $fcrm_val ) {
                    $wp_val   = sanitize_text_field( $wp_val );
                    $fcrm_val = sanitize_text_field( $fcrm_val );
                    if ( $wp_val !== '' && $fcrm_val !== '' ) {
                        $value_map[ $wp_val ] = $fcrm_val;
                    }
                }
            }

            $clean[] = [
                'id'               => sanitize_text_field( $row_id ),
                'wp_field_key'     => $wp_f['key'],
                'wp_field_source'  => $wp_f['source'],
                'wp_field_label'   => $wp_f['label'],
                'fcrm_field_key'   => $fcrm_f['key'],
                'fcrm_field_source'=> $fcrm_f['source'],
                'fcrm_field_label' => $fcrm_f['label'],
                'field_type'       => $field_type,
                'sync_direction'   => $direction,
                'enabled'          => ! empty( $row['enabled'] ),
                'date_format_wp'   => sanitize_text_field( $row['date_format_wp'] ?? 'm/d/Y' ),
                'date_format_fcrm' => 'Y-m-d',
                // Carry ACF-specific date format through
                'acf_field_type'   => $wp_f['acf_field_type'] ?? '',
                // Select/radio value translation map
                'value_map'        => $value_map,
            ];
        }

        $this->mapper->save_mappings( $clean );
        wp_send_json_success( [ 'count' => count( $clean ) ] );
    }

    public function ajax_save_settings(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $fields = [
            'sync_on_user_register',
            'sync_on_profile_update',
            'sync_on_user_delete',
            'sync_on_fcrm_update',
            'sync_on_pmp_change',
        ];

        $settings = [];
        foreach ( $fields as $key ) {
            $settings[ $key ] = ! empty( $_POST[ $key ] ); // phpcs:ignore
        }

        // AI settings (string values, not booleans).
        $ai_keys  = [ 'ai_provider', 'anthropic_api_key', 'openai_api_key', 'gemini_api_key' ];
        $existing = get_option( 'my_iapsnj_settings', [] );
        foreach ( $ai_keys as $key ) {
            if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore
                $settings[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore
            } elseif ( isset( $existing[ $key ] ) ) {
                $settings[ $key ] = $existing[ $key ];
            }
        }

        update_option( 'my_iapsnj_settings', $settings );
        wp_send_json_success();
    }

    public function ajax_get_fields(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        wp_send_json_success( [
            'wp'   => array_values( $this->mapper->get_wp_fields() ),
            'fcrm' => array_values( $this->mapper->get_fcrm_fields() ),
        ] );
    }

    public function ajax_bulk_sync(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $direction = sanitize_text_field( $_POST['direction'] ?? 'wp_to_fcrm' ); // phpcs:ignore
        $per_page  = max( 1, (int) ( $_POST['per_page'] ?? 50 ) );               // phpcs:ignore
        $offset    = max( 0, (int) ( $_POST['offset']   ?? 0  ) );               // phpcs:ignore

        // Optional field-ID filter: empty array means "sync all fields".
        $raw_ids   = isset( $_POST['field_ids'] ) && is_array( $_POST['field_ids'] ) // phpcs:ignore
            ? $_POST['field_ids']  // phpcs:ignore
            : [];
        $field_ids = array_map( 'sanitize_text_field', $raw_ids );

        $engine    = My_IAPSNJ_Engine::get_instance();
        $success   = [];
        $errors    = [];

        if ( $direction === 'wp_to_fcrm' ) {
            $users = get_users( [
                'number'  => $per_page,
                'offset'  => $offset,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ] );
            foreach ( $users as $user ) {
                try {
                    $engine->sync_wp_to_fcrm( $user->ID, $field_ids );
                    $success[] = $user->ID;
                } catch ( \Throwable $e ) {
                    $errors[] = [ 'id' => $user->ID, 'error' => $e->getMessage() ];
                }
            }
        } else {
            // fcrm_to_wp: iterate FluentCRM contacts with a linked WP user
            $contacts = \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )
                ->skip( $offset )
                ->take( $per_page )
                ->get();
            foreach ( $contacts as $contact ) {
                try {
                    $engine->sync_fcrm_to_wp( $contact, $field_ids );
                    $success[] = $contact->user_id;
                } catch ( \Throwable $e ) {
                    $errors[] = [ 'id' => $contact->id, 'error' => $e->getMessage() ];
                }
            }
        }

        $total_users = count_users()['total_users'];
        $has_more    = ( $offset + $per_page ) < $total_users;

        if ( ! $has_more ) {
            update_option( 'my_iapsnj_last_bulk_sync', current_time( 'mysql' ) );
        }

        wp_send_json_success( [
            'success'     => count( $success ),
            'errors'      => $errors,
            'offset'      => $offset,
            'per_page'    => $per_page,
            'total_users' => $total_users,
            'has_more'    => $has_more,
            'next_offset' => $offset + $per_page,
        ] );
    }

    public function ajax_get_mismatches(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $page     = max( 1, (int) ( $_GET['page']     ?? 1  ) );  // phpcs:ignore
        $per_page = max( 1, (int) ( $_GET['per_page'] ?? 20 ) );  // phpcs:ignore

        $result = $this->detector->get_mismatches( $page, $per_page );
        wp_send_json_success( $result );
    }

    public function ajax_resolve_mismatch(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $user_id    = (int) ( $_POST['user_id']    ?? 0 );                              // phpcs:ignore
        $direction  = sanitize_text_field( $_POST['direction']  ?? 'use_wp' );          // phpcs:ignore
        $mapping_id = sanitize_text_field( $_POST['mapping_id'] ?? '' );               // phpcs:ignore
        $scope      = sanitize_text_field( $_POST['scope']      ?? 'field' );           // phpcs:ignore

        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Invalid user ID.' ] );
        }

        try {
            if ( $scope === 'all' ) {
                $ok    = $this->detector->resolve_user( $user_id, $direction );
                $steps = [];
            } elseif ( $scope === 'empty' ) {
                $ok    = $this->detector->resolve_user_empty_fields( $user_id );
                $steps = [];
            } else {
                // resolve_field() returns detailed step log for the UI.
                $result = $this->detector->resolve_field( $user_id, $mapping_id, $direction );
                $ok     = $result['ok']    ?? false;
                $steps  = $result['steps'] ?? [];
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( [
                'message' => $e->getMessage(),
                'steps'   => [ [ 'text' => 'Exception: ' . $e->getMessage(), 'status' => 'error' ] ],
            ] );
        }

        if ( $ok ) {
            wp_send_json_success( [ 'steps' => $steps ] );
        } else {
            $msg = 'Could not resolve: no linked FluentCRM subscriber found for this user.';
            // If steps contain a more specific error, use the last error step.
            foreach ( array_reverse( $steps ) as $step ) {
                if ( ( $step['status'] ?? '' ) === 'error' ) {
                    $msg = $step['text'];
                    break;
                }
            }
            wp_send_json_error( [ 'message' => $msg, 'steps' => $steps ] );
        }
    }

    // -----------------------------------------------------------------------
    // AJAX: Global Sync All Empty
    // -----------------------------------------------------------------------

    public function ajax_sync_all_empty(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        try {
            $count = $this->detector->resolve_all_empty_globally();
            /* translators: %d = number of contacts updated */
            $msg = sprintf(
                _n(
                    'Empty fields filled for %d contact.',
                    'Empty fields filled for %d contacts.',
                    $count,
                    'my-iapsnj'
                ),
                $count
            );
            wp_send_json_success( [ 'message' => $msg, 'count' => $count ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // -----------------------------------------------------------------------
    // Page: PMP Integration
    // -----------------------------------------------------------------------

    public function render_pmp_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ) );
        }

        $settings     = get_option( 'my_iapsnj_settings', [] );
        $tag_mappings = get_option( 'my_iapsnj_pmp_tag_mappings', [] );
        $pmp_levels   = My_IAPSNJ_PMP_Integration::get_all_levels();

        // Expiration date sync status.
        $expiry_cron_enabled = (bool) get_option( 'my_iapsnj_pmp_expiry_cron_enabled', false );
        $expiry_last_sync    = get_option( 'my_iapsnj_pmp_expiry_last_sync', '' );

        $expiry_field_exists = false;
        $custom_field_defs   = function_exists( 'fluentcrm_get_option' )
            ? (array) fluentcrm_get_option( 'contact_custom_fields', [] )
            : [];
        foreach ( $custom_field_defs as $cf ) {
            if ( ( $cf['slug'] ?? '' ) === 'expiration_date' ) {
                $expiry_field_exists = true;
                break;
            }
        }

        $expiry_mapping_exists = false;
        foreach ( $this->mapper->get_saved_mappings() as $m ) {
            if ( ( $m['wp_field_key'] ?? '' ) === 'expiration_date'
                && ( $m['wp_field_source'] ?? '' ) === 'pmp'
            ) {
                $expiry_mapping_exists = true;
                break;
            }
        }

        // Collect FluentCRM tags.
        $fcrm_tags = [];
        if ( function_exists( 'FluentCrmApi' ) ) {
            $tags_collection = FluentCrmApi( 'tags' )->all();
            foreach ( $tags_collection as $tag ) {
                $fcrm_tags[] = [ 'id' => (int) $tag->id, 'title' => $tag->title ];
            }
        }

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'My IAPSNJ – PMP Integration', 'my-iapsnj' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Configure how Paid Memberships Pro membership data syncs with FluentCRM.', 'my-iapsnj' ); ?>
            </p>

            <div id="fcrm-pmp-notice" class="fcrm-notice" style="display:none"></div>

            <!-- ── Field mapping reminder ─────────────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Date & Level Field Mapping', 'my-iapsnj' ); ?></h2>
                <p>
                    <?php esc_html_e( 'The following PMPro membership fields are available in the Field Mapping screen. These are read-only and sync WP → FluentCRM only:', 'my-iapsnj' ); ?>
                </p>
                <ul style="list-style:disc; margin-left:1.5em; line-height:1.8">
                    <li><strong><?php esc_html_e( 'PMPro Join Date', 'my-iapsnj' ); ?></strong> – <?php esc_html_e( "The date the user's current membership level started.", 'my-iapsnj' ); ?></li>
                    <li><strong><?php esc_html_e( 'PMPro Expiration / Renewal Date', 'my-iapsnj' ); ?></strong> – <?php esc_html_e( 'The date the membership expires or renews. Empty for non-expiring memberships.', 'my-iapsnj' ); ?></li>
                    <li><strong><?php esc_html_e( 'PMPro Level Name', 'my-iapsnj' ); ?></strong> – <?php esc_html_e( 'The name of the active membership level.', 'my-iapsnj' ); ?></li>
                    <li><strong><?php esc_html_e( 'PMPro Level ID', 'my-iapsnj' ); ?></strong> – <?php esc_html_e( 'The numeric ID of the active membership level.', 'my-iapsnj' ); ?></li>
                </ul>
            </div>

            <!-- ── Billing address field mapping ─────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Billing Address Field Mapping', 'my-iapsnj' ); ?></h2>
                <p>
                    <?php esc_html_e( 'PMPro stores billing address information in WordPress user meta. These fields can be mapped bidirectionally to FluentCRM address fields:', 'my-iapsnj' ); ?>
                </p>
                <table class="widefat" style="max-width:700px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'PMPro Field (WP meta key)', 'my-iapsnj' ); ?></th>
                            <th><?php esc_html_e( 'Suggested FluentCRM Field', 'my-iapsnj' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $addr_suggestions = [
                            'PMPro Billing Address Line 1' => 'Address Line 1',
                            'PMPro Billing Address Line 2' => 'Address Line 2',
                            'PMPro Billing City'           => 'City',
                            'PMPro Billing State'          => 'State',
                            'PMPro Billing Postal Code'    => 'Postal Code',
                            'PMPro Billing Country'        => 'Country',
                            'PMPro Billing Phone'          => 'Phone',
                        ];
                        foreach ( $addr_suggestions as $wp_lbl => $fcrm_lbl ) :
                        ?>
                        <tr>
                            <td><?php echo esc_html( $wp_lbl ); ?></td>
                            <td><?php echo esc_html( $fcrm_lbl ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:8px">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=my-iapsnj' ) ); ?>" class="button">
                        <?php esc_html_e( 'Go to Field Mapping', 'my-iapsnj' ); ?>
                    </a>
                </p>
            </div>

            <!-- ── Sync trigger ───────────────────────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Sync Trigger', 'my-iapsnj' ); ?></h2>
                <p><?php esc_html_e( 'Enable automatic syncing to FluentCRM when a membership level changes.', 'my-iapsnj' ); ?></p>
                <label>
                    <input type="checkbox" id="fcrm-pmp-sync-on-change" value="1"
                        <?php checked( ! empty( $settings['sync_on_pmp_change'] ) ); ?>>
                    <?php esc_html_e( 'Sync WP → FluentCRM on every membership level change', 'my-iapsnj' ); ?>
                </label>
            </div>

            <!-- ── Tag mappings ───────────────────────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Tag Mappings', 'my-iapsnj' ); ?></h2>
                <p>
                    <?php esc_html_e( 'Select which FluentCRM tags to apply when a user belongs to each membership level. Tags assigned by this mapping will be removed automatically when the user\'s level changes.', 'my-iapsnj' ); ?>
                </p>

                <?php if ( empty( $pmp_levels ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No membership levels found. Create levels in PMPro first.', 'my-iapsnj' ); ?></p>
                <?php elseif ( empty( $fcrm_tags ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No FluentCRM tags found. Create tags in FluentCRM first.', 'my-iapsnj' ); ?></p>
                <?php else : ?>
                    <table class="widefat fcrm-pmp-tag-table" style="max-width:800px">
                        <thead>
                            <tr>
                                <th style="width:30%"><?php esc_html_e( 'Membership Level', 'my-iapsnj' ); ?></th>
                                <th><?php esc_html_e( 'FluentCRM Tags to Apply', 'my-iapsnj' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $pmp_levels as $level ) :
                                $level_id    = (int) ( $level->id ?? $level->ID );
                                $level_name  = esc_html( $level->name );
                                $saved_tags  = isset( $tag_mappings[ $level_id ] ) ? array_map( 'intval', (array) $tag_mappings[ $level_id ] ) : [];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo $level_name; ?></strong>
                                    <br><small><?php echo esc_html( sprintf( __( 'Level ID: %d', 'my-iapsnj' ), $level_id ) ); ?></small>
                                </td>
                                <td>
                                    <select multiple
                                        name="pmp_tag_mappings[<?php echo esc_attr( $level_id ); ?>][]"
                                        class="fcrm-pmp-tag-select"
                                        data-level-id="<?php echo esc_attr( $level_id ); ?>"
                                        style="min-width:300px; min-height:80px">
                                        <?php foreach ( $fcrm_tags as $tag ) : ?>
                                            <option value="<?php echo esc_attr( $tag['id'] ); ?>"
                                                <?php selected( in_array( $tag['id'], $saved_tags, true ) ); ?>>
                                                <?php echo esc_html( $tag['title'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description" style="margin-top:4px">
                                        <?php esc_html_e( 'Hold Ctrl / Cmd to select multiple tags.', 'my-iapsnj' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- ── Expiration Date Sync ───────────────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Expiration Date Sync', 'my-iapsnj' ); ?></h2>
                <p>
                    <?php esc_html_e( 'Automatically sync PMPro membership expiration dates to the FluentCRM custom field "expiration_date". Works for both non-recurring members (uses their fixed end date) and recurring members with no fixed end date (falls back to the next scheduled renewal/billing date).', 'my-iapsnj' ); ?>
                </p>

                <!-- Status cards -->
                <div class="fcrm-status-cards" style="margin-bottom:16px">
                    <div class="fcrm-card">
                        <span class="fcrm-card-number" style="font-size:14px">
                            <?php if ( $expiry_field_exists ) : ?>
                                <span style="color:#46b450">&#10003; <?php esc_html_e( 'Found', 'my-iapsnj' ); ?></span>
                            <?php else : ?>
                                <span style="color:#dc3232">&#10007; <?php esc_html_e( 'Not Found', 'my-iapsnj' ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="fcrm-card-label"><?php esc_html_e( 'FluentCRM expiration_date field', 'my-iapsnj' ); ?></span>
                    </div>
                    <div class="fcrm-card">
                        <span class="fcrm-card-number" style="font-size:14px">
                            <?php if ( $expiry_mapping_exists ) : ?>
                                <span style="color:#46b450">&#10003; <?php esc_html_e( 'Configured', 'my-iapsnj' ); ?></span>
                            <?php else : ?>
                                <span style="color:#dc3232">&#10007; <?php esc_html_e( 'Not Set', 'my-iapsnj' ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="fcrm-card-label"><?php esc_html_e( 'Field Mapping', 'my-iapsnj' ); ?></span>
                    </div>
                    <?php if ( $expiry_last_sync ) : ?>
                    <div class="fcrm-card">
                        <span class="fcrm-card-number" style="font-size:12px"><?php echo esc_html( $expiry_last_sync ); ?></span>
                        <span class="fcrm-card-label"><?php esc_html_e( 'Last Expiry Sync', 'my-iapsnj' ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="fcrm-expiry-notice" class="fcrm-notice" style="display:none"></div>

                <?php if ( ! $expiry_field_exists ) : ?>
                <p class="description" style="color:#b71c1c; margin-bottom:12px">
                    <?php esc_html_e( 'You must first create a custom field with slug "expiration_date" and type "Date" in FluentCRM (FluentCRM \u2192 Settings \u2192 Custom Fields) before using this tool.', 'my-iapsnj' ); ?>
                </p>
                <?php endif; ?>

                <button id="fcrm-pmp-setup-expiry-mapping" class="button button-secondary"
                    <?php echo $expiry_field_exists ? '' : 'disabled'; ?>>
                    <?php esc_html_e( 'Auto-Setup Mapping', 'my-iapsnj' ); ?>
                </button>
                <span style="margin-left:8px; color:#555; font-size:12px">
                    <?php esc_html_e( 'Creates the PMPro Smart Expiration Date \u2192 expiration_date field mapping automatically.', 'my-iapsnj' ); ?>
                </span>

                <hr style="margin:20px 0">

                <h3 style="margin-top:0"><?php esc_html_e( 'Sync Expiration Dates Now', 'my-iapsnj' ); ?></h3>
                <p><?php esc_html_e( 'Push expiration dates to FluentCRM for all active PMPro members. Processes in batches of 50 to avoid timeouts.', 'my-iapsnj' ); ?></p>

                <button id="fcrm-pmp-bulk-expiry-sync" class="button button-primary"
                    <?php echo $expiry_mapping_exists ? '' : 'disabled'; ?>>
                    <?php esc_html_e( 'Sync Expiration Dates Now', 'my-iapsnj' ); ?>
                </button>

                <div id="fcrm-expiry-progress" style="display:none; margin-top:12px">
                    <div style="background:#e0e0e0; border-radius:4px; height:16px; max-width:400px">
                        <div id="fcrm-expiry-progress-bar"
                            style="background:#2271b1; height:16px; border-radius:4px; width:0%; transition:width .3s"></div>
                    </div>
                    <p id="fcrm-expiry-sync-status" style="margin-top:6px; font-size:13px"></p>
                </div>

                <hr style="margin:20px 0">

                <h3 style="margin-top:0"><?php esc_html_e( 'Automatic Daily Sync', 'my-iapsnj' ); ?></h3>
                <p><?php esc_html_e( 'Enable a daily WP-Cron task to automatically sync expiration dates for all active members. The cron runs once per day using the WordPress cron scheduler.', 'my-iapsnj' ); ?></p>
                <label>
                    <input type="checkbox" id="fcrm-pmp-expiry-cron-enabled" value="1"
                        <?php checked( $expiry_cron_enabled ); ?>>
                    <?php esc_html_e( 'Enable daily automatic expiration date sync', 'my-iapsnj' ); ?>
                </label>
                <br><br>
                <button id="fcrm-pmp-save-expiry-cron" class="button button-secondary">
                    <?php esc_html_e( 'Save Cron Setting', 'my-iapsnj' ); ?>
                </button>
            </div>

            <button id="fcrm-save-pmp-settings" class="button button-primary">
                <?php esc_html_e( 'Save PMP Settings', 'my-iapsnj' ); ?>
            </button>

        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // AJAX: Save PMP settings
    // -----------------------------------------------------------------------

    public function ajax_save_pmp_settings(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        // 1. Update the sync_on_pmp_change toggle within the main settings array.
        $settings                       = get_option( 'my_iapsnj_settings', [] );
        $settings['sync_on_pmp_change'] = ! empty( $_POST['sync_on_pmp_change'] ); // phpcs:ignore
        update_option( 'my_iapsnj_settings', $settings );

        // 2. Build and save tag mappings: [ level_id (int) => [ tag_id (int), ... ] ]
        $raw_mappings  = isset( $_POST['pmp_tag_mappings'] ) ? (array) $_POST['pmp_tag_mappings'] : []; // phpcs:ignore
        $clean_mappings = [];

        foreach ( $raw_mappings as $level_id => $tag_ids ) {
            $lid = (int) $level_id;
            if ( $lid <= 0 ) {
                continue;
            }
            $clean_tags = [];
            foreach ( (array) $tag_ids as $tid ) {
                $t = (int) $tid;
                if ( $t > 0 ) {
                    $clean_tags[] = $t;
                }
            }
            $clean_mappings[ $lid ] = $clean_tags;
        }

        update_option( 'my_iapsnj_pmp_tag_mappings', $clean_mappings );

        wp_send_json_success( [ 'levels' => count( $clean_mappings ) ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: PMPro expiration date — auto-setup mapping
    // -----------------------------------------------------------------------

    /**
     * Find the FluentCRM expiration_date custom field and create (or confirm) the
     * pmp__expiration_date → expiration_date mapping record.
     */
    public function ajax_pmp_setup_expiry_mapping(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        // 1. Verify the FluentCRM custom field with slug 'expiration_date' exists.
        $custom_fields = function_exists( 'fluentcrm_get_option' )
            ? (array) fluentcrm_get_option( 'contact_custom_fields', [] )
            : [];

        $fcrm_field = null;
        foreach ( $custom_fields as $cf ) {
            if ( ( $cf['slug'] ?? '' ) === 'expiration_date' ) {
                $fcrm_field = $cf;
                break;
            }
        }

        if ( ! $fcrm_field ) {
            wp_send_json_error( [
                'message' => esc_html__( 'FluentCRM custom field "expiration_date" not found. Create it in FluentCRM → Settings → Custom Fields first.', 'my-iapsnj' ),
            ] );
        }

        // 2. Check whether the mapping already exists.
        $mappings = $this->mapper->get_saved_mappings();
        foreach ( $mappings as $m ) {
            if ( ( $m['wp_field_key'] ?? '' ) === 'expiration_date'
                && ( $m['wp_field_source'] ?? '' ) === 'pmp'
                && ( $m['fcrm_field_key'] ?? '' ) === 'expiration_date'
            ) {
                wp_send_json_success( [
                    'already_existed' => true,
                    'message'         => esc_html__( 'Mapping already exists and is configured.', 'my-iapsnj' ),
                ] );
            }
        }

        // 3. Create the new mapping record.
        $new_mapping = [
            'id'                => My_IAPSNJ_Field_Mapper::generate_id(),
            'wp_field_key'      => 'expiration_date',
            'wp_field_source'   => 'pmp',
            'wp_field_label'    => esc_html__( 'PMPro Smart Expiration Date', 'my-iapsnj' ),
            'fcrm_field_key'    => 'expiration_date',
            'fcrm_field_source' => 'custom',
            'fcrm_field_label'  => ( $fcrm_field['label'] ?? 'expiration_date' ) . ' (custom)',
            'field_type'        => 'date',
            'sync_direction'    => 'wp_to_fcrm',
            'enabled'           => true,
            'date_format_wp'    => 'Y-m-d',
            'date_format_fcrm'  => 'Y-m-d',
            'value_map'         => [],
        ];

        $mappings[] = $new_mapping;
        $this->mapper->save_mappings( $mappings );

        wp_send_json_success( [
            'already_existed' => false,
            'message'         => esc_html__( 'Mapping created successfully. You can now sync expiration dates.', 'my-iapsnj' ),
            'mapping_id'      => $new_mapping['id'],
        ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: PMPro expiration date — paginated bulk sync
    // -----------------------------------------------------------------------

    /**
     * Paginated bulk sync of PMPro expiration dates for all active members.
     *
     * POST params: per_page (default 50), offset (default 0)
     */
    public function ajax_pmp_bulk_expiry_sync(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $per_page = max( 1, (int) ( $_POST['per_page'] ?? 50 ) ); // phpcs:ignore
        $offset   = max( 0, (int) ( $_POST['offset']   ?? 0  ) ); // phpcs:ignore

        // Locate the active expiry mapping ID(s).
        $expiry_mapping_ids = [];
        foreach ( $this->mapper->get_active_mappings() as $m ) {
            if ( ( $m['wp_field_key'] ?? '' ) === 'expiration_date'
                && ( $m['wp_field_source'] ?? '' ) === 'pmp'
            ) {
                $expiry_mapping_ids[] = $m['id'];
            }
        }

        if ( empty( $expiry_mapping_ids ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'No expiration date mapping found. Run Auto-Setup Mapping first.', 'my-iapsnj' ),
            ] );
        }

        global $wpdb;

        // Total active PMPro members (for progress calculation).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}pmpro_memberships_users WHERE status = 'active'"
        );

        // Paginated active member user IDs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}pmpro_memberships_users
             WHERE status = 'active'
             ORDER BY user_id ASC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        $engine  = My_IAPSNJ_Engine::get_instance();
        $success = [];
        $errors  = [];

        foreach ( $user_ids as $user_id ) {
            try {
                $engine->sync_wp_to_fcrm( (int) $user_id, $expiry_mapping_ids );
                $success[] = (int) $user_id;
            } catch ( \Throwable $e ) {
                $errors[] = [ 'id' => (int) $user_id, 'error' => $e->getMessage() ];
            }
        }

        $has_more = ( $offset + $per_page ) < $total;

        if ( ! $has_more ) {
            update_option( 'my_iapsnj_pmp_expiry_last_sync', current_time( 'mysql' ) );
        }

        wp_send_json_success( [
            'success'     => count( $success ),
            'errors'      => $errors,
            'offset'      => $offset,
            'per_page'    => $per_page,
            'total'       => $total,
            'has_more'    => $has_more,
            'next_offset' => $offset + $per_page,
        ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: PMPro expiration date — toggle daily cron
    // -----------------------------------------------------------------------

    /**
     * Enable or disable the daily WP-Cron expiration date sync.
     *
     * POST params: enabled (1 or 0)
     */
    public function ajax_pmp_save_expiry_cron(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $enabled = ! empty( $_POST['enabled'] ); // phpcs:ignore
        update_option( 'my_iapsnj_pmp_expiry_cron_enabled', $enabled );

        if ( $enabled ) {
            if ( ! wp_next_scheduled( 'my_iapsnj_pmp_expiry_cron' ) ) {
                wp_schedule_event( time(), 'daily', 'my_iapsnj_pmp_expiry_cron' );
            }
        } else {
            wp_clear_scheduled_hook( 'my_iapsnj_pmp_expiry_cron' );
        }

        $next_run = wp_next_scheduled( 'my_iapsnj_pmp_expiry_cron' );

        wp_send_json_success( [
            'enabled'  => $enabled,
            'next_run' => $next_run ? date( 'Y-m-d H:i:s', $next_run ) : '',
            'message'  => $enabled
                ? esc_html__( 'Daily cron enabled.', 'my-iapsnj' )
                : esc_html__( 'Daily cron disabled.', 'my-iapsnj' ),
        ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: user search autocomplete (for Sample Data Preview)
    // -----------------------------------------------------------------------

    public function ajax_search_users(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $query = sanitize_text_field( $_POST['query'] ?? '' ); // phpcs:ignore
        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( [] );
        }

        $users = get_users( [
            'search'         => '*' . $query . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 10,
            'fields'         => [ 'ID', 'user_login', 'user_email', 'display_name' ],
        ] );

        $result = [];
        foreach ( $users as $u ) {
            $result[] = [
                'id'    => (int) $u->ID,
                'label' => $u->display_name . ' (' . $u->user_email . ')',
                'email' => $u->user_email,
            ];
        }

        wp_send_json_success( $result );
    }

    // -----------------------------------------------------------------------
    // AJAX: sample data preview
    // -----------------------------------------------------------------------

    public function ajax_sample_data(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $user_id = (int) ( $_POST['user_id'] ?? 0 ); // phpcs:ignore
        if ( ! $user_id ) {
            wp_send_json_error( __( 'Invalid user ID.', 'my-iapsnj' ) );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json_error( __( 'User not found.', 'my-iapsnj' ) );
        }

        $engine = My_IAPSNJ_Engine::get_instance();
        $rows   = $engine->get_field_values_for_user( $user_id );

        wp_send_json_success( [
            'user' => [
                'id'           => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
            ],
            'rows' => $rows,
        ] );
    }

    // -----------------------------------------------------------------------
    // Page: CRM Assistant
    // -----------------------------------------------------------------------

    public function render_crm_assistant_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ) );
        }

        $settings = get_option( 'my_iapsnj_settings', [] );
        $provider = $settings['ai_provider'] ?? 'anthropic';
        $provider_labels = [
            'anthropic' => 'Anthropic (Claude)',
            'openai'    => 'OpenAI (GPT-4o)',
            'gemini'    => 'Google Gemini',
        ];
        $provider_label = $provider_labels[ $provider ] ?? $provider;

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'My IAPSNJ – CRM Assistant', 'my-iapsnj' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Chat with your AI-powered CRM assistant. It can search contacts, view details, update records, and manage tags in FluentCRM.', 'my-iapsnj' ); ?>
            </p>

            <div class="fcrm-section">
                <span class="my-iapsnj-provider-badge">
                    <?php echo esc_html( $provider_label ); ?>
                </span>

                <div id="my-iapsnj-chat-wrap" class="my-iapsnj-chat-wrap">
                    <div id="my-iapsnj-chat-history" class="my-iapsnj-chat-history"></div>

                    <div class="my-iapsnj-chat-input-wrap">
                        <textarea id="my-iapsnj-chat-input"
                                  class="my-iapsnj-chat-input"
                                  rows="2"
                                  placeholder="<?php esc_attr_e( 'Ask about your contacts, tags, or member data…', 'my-iapsnj' ); ?>"></textarea>
                        <button id="my-iapsnj-chat-send" class="button button-primary">
                            <?php esc_html_e( 'Send', 'my-iapsnj' ); ?>
                        </button>
                    </div>
                </div>

                <details id="my-iapsnj-tool-log" class="my-iapsnj-tool-log" style="margin-top:12px">
                    <summary><?php esc_html_e( 'Tool Call Log', 'my-iapsnj' ); ?></summary>
                    <div id="my-iapsnj-tool-log-content"></div>
                </details>
            </div>
        </div>
        <?php
    }
}
