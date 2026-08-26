<?php
/**
 * My_IAPSNJ_Admin
 *
 * Admin menu, screens and AJAX handlers:
 *
 *  Dashboard            counts + environment checks
 *  Pending Checks       unpaid check orders, batch mark paid, record a check
 *  Membership Products  which FluentCart products set which membership state
 *  Reports              open applications, orphan orders, aging checks, WP↔CRM orphans
 *  Profile Mirror       CRM → WP field map
 *  Sync & Settings      triggers, forms, notifications, checkout label, CRM schema
 *  Migration            PMPro → FluentCRM toolkit (only while PMPro tables exist)
 *  Notes Search         FluentCRM notes search with inline tagging
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Admin {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var My_IAPSNJ_Field_Mapper */
    private My_IAPSNJ_Field_Mapper $mapper;

    const CAP = 'manage_options';

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->mapper = new My_IAPSNJ_Field_Mapper();

        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'redirect_legacy_slugs' ] );
        add_action( 'admin_notices',         [ $this, 'environment_notices' ] );

        // Pin CRM / Users / FluentCart / Fluent Forms to the top of the sidebar (audit P4-9).
        add_filter( 'custom_menu_order', '__return_true' );
        add_filter( 'menu_order',        [ $this, 'reorder_admin_menu' ] );

        $ajax = [
            'save_mappings', 'save_settings', 'bulk_sync', 'search_users', 'sample_data',
            'search_notes', 'get_tags', 'assign_tag',
            'checks_list', 'checks_mark_paid', 'search_members', 'record_check',
            'save_products', 'apply_offline_labels', 'ensure_schema',
            'migration_run', 'export_orders', 'download_export', 'report',
        ];
        foreach ( $ajax as $action ) {
            add_action( 'wp_ajax_my_iapsnj_' . $action, [ $this, 'ajax_' . $action ] );
        }
    }

    // -----------------------------------------------------------------------
    // Menu
    // -----------------------------------------------------------------------

    public function register_menu(): void {
        add_menu_page( __( 'My IAPSNJ', 'my-iapsnj' ), __( 'My IAPSNJ', 'my-iapsnj' ), self::CAP, 'my-iapsnj', [ $this, 'render_dashboard_page' ], 'dashicons-shield', 56 );

        $pages = [
            [ 'my-iapsnj',              __( 'Dashboard', 'my-iapsnj' ),           'render_dashboard_page' ],
            [ 'my-iapsnj-checks',       __( 'Pending Checks', 'my-iapsnj' ),      'render_checks_page' ],
            [ 'my-iapsnj-products',     __( 'Membership Products', 'my-iapsnj' ), 'render_products_page' ],
            [ 'my-iapsnj-reports',      __( 'Reports', 'my-iapsnj' ),             'render_reports_page' ],
            [ 'my-iapsnj-mapping',      __( 'Profile Mirror', 'my-iapsnj' ),      'render_field_mapping_page' ],
            [ 'my-iapsnj-sync',         __( 'Sync & Settings', 'my-iapsnj' ),     'render_sync_page' ],
        ];
        if ( My_IAPSNJ_Migration::tables_exist() ) {
            $pages[] = [ 'my-iapsnj-migration', __( 'Migration (PMPro → CRM)', 'my-iapsnj' ), 'render_migration_page' ];
        }
        $pages[] = [ 'my-iapsnj-notes-search', __( 'Notes Search', 'my-iapsnj' ), 'render_notes_search_page' ];

        foreach ( $pages as [ $slug, $title, $method ] ) {
            add_submenu_page( 'my-iapsnj', $title, $title, self::CAP, $slug, [ $this, $method ] );
        }
    }

    /**
     * Slugs from the fcrm-wp-sync era and from the removed 3.x screens (audit P4-8).
     */
    private static array $legacy_slugs = [
        'fcrm-wp-sync'              => 'my-iapsnj-mapping',
        'fcrm-wp-sync-sync'         => 'my-iapsnj-sync',
        'fcrm-wp-sync-mismatches'   => 'my-iapsnj-reports',
        'fcrm-wp-sync-pmp'          => 'my-iapsnj-migration',
        'fcrm-wp-sync-notes-search' => 'my-iapsnj-notes-search',
        'my-iapsnj-mismatches'      => 'my-iapsnj-reports',
        'my-iapsnj-pmp'             => 'my-iapsnj-migration',
    ];

    public function redirect_legacy_slugs(): void {
        if ( wp_doing_ajax() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( '' === $page || ! isset( self::$legacy_slugs[ $page ] ) ) {
            return;
        }
        $target = self::$legacy_slugs[ $page ];
        if ( $target === 'my-iapsnj-migration' && ! My_IAPSNJ_Migration::tables_exist() ) {
            $target = 'my-iapsnj';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw  = wp_unslash( $_GET );
        $args = [];
        foreach ( (array) $raw as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $args[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
            }
        }
        $args['page'] = $target;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 301 );
        exit;
    }

    /**
     * @param mixed $menu_order
     * @return mixed
     */
    public function reorder_admin_menu( $menu_order ) {
        if ( ! is_array( $menu_order ) ) {
            return $menu_order;
        }
        $preferred = apply_filters( 'my_iapsnj_top_menu_slugs', [
            'fluentcrm-admin', // CRM
            'users.php',       // Users
            'fluent-cart',     // FluentCart
            'fluent_forms',    // Fluent Forms
            'my-iapsnj',       // this plugin
        ] );
        $top = [];
        foreach ( $preferred as $slug ) {
            if ( in_array( $slug, $menu_order, true ) ) {
                $top[] = $slug;
            }
        }
        if ( ! $top ) {
            return $menu_order;
        }
        return array_merge( $top, array_values( array_diff( $menu_order, $top ) ) );
    }

    public function environment_notices(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( (string) $screen->id, 'my-iapsnj' ) === false ) {
            return;
        }
        if ( ! My_IAPSNJ_Membership::is_available() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'FluentCart is not active. Membership state, Pending Checks and Record a Check are unavailable until it is.', 'my-iapsnj' ) . '</p></div>';
        }
        if ( ! My_IAPSNJ_Applications::is_available() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Fluent Forms is not active. Application tracking (Checkout-Abandoned, orphan report) is unavailable until it is.', 'my-iapsnj' ) . '</p></div>';
        }
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'my-iapsnj' ) === false ) {
            return;
        }
        wp_enqueue_style( 'my-iapsnj-admin', MY_IAPSNJ_URL . 'admin/css/admin.css', [], MY_IAPSNJ_VERSION );
        wp_enqueue_script( 'my-iapsnj-admin', MY_IAPSNJ_URL . 'admin/js/admin.js', [ 'jquery' ], MY_IAPSNJ_VERSION, true );

        wp_localize_script( 'my-iapsnj-admin', 'myIapsnj', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'my_iapsnj_nonce' ),
            'dateFormat' => get_option( 'date_format', 'm/d/Y' ),
            'today'      => My_IAPSNJ_Dates::today(),
            'i18n'       => [
                'saving'     => __( 'Saving…', 'my-iapsnj' ),
                'saved'      => __( 'Saved!', 'my-iapsnj' ),
                'error'      => __( 'Error. Please try again.', 'my-iapsnj' ),
                'loading'    => __( 'Loading…', 'my-iapsnj' ),
                'syncing'    => __( 'Mirroring…', 'my-iapsnj' ),
                'syncDone'   => __( 'Mirror complete.', 'my-iapsnj' ),
                'confirmDelete' => __( 'Remove this mapping row?', 'my-iapsnj' ),
                'noRows'     => __( 'Nothing to show.', 'my-iapsnj' ),
                'confirmPaid' => __( 'Mark the selected orders as PAID? This applies Paid-YYYY tags and sends receipts. It cannot be undone from here (use a FluentCart refund).', 'my-iapsnj' ),
                'confirmRecord' => __( 'Create and pay a FluentCart order for this member?', 'my-iapsnj' ),
                'confirmApply'  => __( 'APPLY this step? Changes will be written to FluentCRM. Run a dry run first.', 'my-iapsnj' ),
                'selectMember'  => __( 'Pick a member from the search results first.', 'my-iapsnj' ),
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    private function guard(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'my-iapsnj' ) );
        }
    }

    private function ajax_guard(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
        }
    }

    /**
     * Normalise posted mapping rows (shared with the REST API).
     */
    public static function sanitize_mapping_rows( array $raw, My_IAPSNJ_Field_Mapper $mapper ): array {
        $wp_fields   = $mapper->get_wp_fields();
        $fcrm_fields = $mapper->get_fcrm_fields();
        $allowed     = [ 'text', 'select', 'date', 'checkbox', 'number', 'email', 'textarea' ];
        $clean       = [];
        foreach ( $raw as $row_id => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $wp_uid   = sanitize_text_field( (string) ( $row['wp_uid'] ?? '' ) );
            $fcrm_uid = sanitize_text_field( (string) ( $row['fcrm_uid'] ?? '' ) );
            if ( $wp_uid === '' || $fcrm_uid === '' ) {
                continue;
            }
            $wp_f   = $wp_fields[ $wp_uid ] ?? null;
            $fcrm_f = $fcrm_fields[ $fcrm_uid ] ?? null;
            if ( ! $wp_f || ! $fcrm_f ) {
                continue;
            }
            $type      = in_array( $row['field_type'] ?? '', $allowed, true ) ? $row['field_type'] : 'text';
            $value_map = [];
            if ( $type === 'select' && ! empty( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
                foreach ( $row['value_map'] as $wp_val => $fcrm_val ) {
                    $wp_val   = sanitize_text_field( (string) $wp_val );
                    $fcrm_val = sanitize_text_field( (string) $fcrm_val );
                    if ( $wp_val !== '' && $fcrm_val !== '' ) {
                        $value_map[ $wp_val ] = $fcrm_val;
                    }
                }
            }
            $id = sanitize_text_field( (string) ( $row['id'] ?? $row_id ) );
            $clean[] = [
                'id'                => $id !== '' && ! is_numeric( $id ) ? $id : My_IAPSNJ_Field_Mapper::generate_id(),
                'wp_field_key'      => $wp_f['key'],
                'wp_field_source'   => $wp_f['source'],
                'wp_field_label'    => $wp_f['label'],
                'fcrm_field_key'    => $fcrm_f['key'],
                'fcrm_field_source' => $fcrm_f['source'],
                'fcrm_field_label'  => $fcrm_f['label'],
                'field_type'        => $type,
                'sync_direction'    => 'fcrm_to_wp',
                'enabled'           => ! empty( $row['enabled'] ),
                'date_format_wp'    => sanitize_text_field( (string) ( $row['date_format_wp'] ?? 'm/d/Y' ) ) ?: 'm/d/Y',
                'date_format_fcrm'  => 'Y-m-d',
                'acf_field_type'    => $wp_f['acf_field_type'] ?? '',
                'value_map'         => $value_map,
            ];
        }
        return $clean;
    }

    /**
     * One page of the CRM → WP mirror (shared with the REST API).
     */
    public static function run_bulk_mirror( int $per_page, int $offset, array $user_ids = [] ): array {
        $engine  = My_IAPSNJ_Engine::get_instance();
        $success = 0;
        $errors  = [];
        $query   = \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )->where( 'user_id', '>', 0 )->orderBy( 'id' );
        if ( $user_ids ) {
            $query = \FluentCrm\App\Models\Subscriber::whereIn( 'user_id', array_map( 'intval', $user_ids ) );
        } else {
            $query = $query->skip( $offset )->take( $per_page );
        }
        foreach ( $query->get() as $contact ) {
            try {
                $engine->sync_fcrm_to_wp( $contact );
                $success++;
            } catch ( \Throwable $e ) {
                $errors[] = [ 'id' => (int) $contact->id, 'error' => $e->getMessage() ];
            }
        }
        $total    = (int) \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )->where( 'user_id', '>', 0 )->count();
        $has_more = $user_ids ? false : ( ( $offset + $per_page ) < $total );
        if ( ! $has_more ) {
            update_option( 'my_iapsnj_last_bulk_sync', current_time( 'mysql' ) );
        }
        return [
            'success'     => $success,
            'errors'      => $errors,
            'offset'      => $offset,
            'per_page'    => $per_page,
            'total'       => $total,
            'has_more'    => $has_more,
            'next_offset' => $offset + $per_page,
        ];
    }

    private function page_header( string $title, string $description = '' ): void {
        echo '<div class="wrap fcrm-sync-wrap">';
        echo '<h1>' . esc_html( 'My IAPSNJ – ' . $title ) . '</h1>';
        if ( $description !== '' ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
    }

    // -----------------------------------------------------------------------
    // Page: Dashboard
    // -----------------------------------------------------------------------

    public function render_dashboard_page(): void {
        $this->guard();
        $s        = My_IAPSNJ_Reports::summary();
        $settings = My_IAPSNJ_Plugin::settings();
        $products = My_IAPSNJ_Membership::products_config();
        $offline  = My_IAPSNJ_Membership::offline_labels();

        $this->page_header( __( 'Dashboard', 'my-iapsnj' ), __( 'FluentCRM is the source of truth. FluentCart payments set membership state; this plugin runs the operations around them.', 'my-iapsnj' ) );

        echo '<div class="fcrm-status-cards">';
        $this->card( (string) $s['crm_contacts'], __( 'CRM contacts', 'my-iapsnj' ) );
        $this->card( (string) $s['wp_users'], __( 'WordPress users', 'my-iapsnj' ) );
        $this->card( (string) $s['pending_checks'] . ( $s['pending_total'] ? ' · ' . $s['pending_total'] : '' ), __( 'Checks pending', 'my-iapsnj' ), admin_url( 'admin.php?page=my-iapsnj-checks' ) );
        $this->card( (string) $s['aging_checks'], sprintf( __( 'Checks pending %d+ days', 'my-iapsnj' ), (int) $settings['aging_days'] ), admin_url( 'admin.php?page=my-iapsnj-reports#aging' ) );
        $this->card( (string) $s['open_applications'], __( 'Applications awaiting payment', 'my-iapsnj' ), admin_url( 'admin.php?page=my-iapsnj-reports#open-applications' ) );
        echo '</div>';

        echo '<div class="fcrm-two-col">';

        echo '<div class="fcrm-section"><h2>' . esc_html__( 'Members by type', 'my-iapsnj' ) . '</h2><table class="widefat striped"><tbody>';
        foreach ( My_IAPSNJ_Schema::member_types() as $type ) {
            echo '<tr><td>' . esc_html( $type ) . '</td><td style="text-align:right">' . esc_html( (string) ( $s['members_by_type'][ $type ] ?? 0 ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="fcrm-section"><h2>' . esc_html__( 'Paid by year (tags)', 'my-iapsnj' ) . '</h2><table class="widefat striped"><tbody>';
        if ( $s['paid_years'] ) {
            foreach ( $s['paid_years'] as $slug => $n ) {
                echo '<tr><td>' . esc_html( My_IAPSNJ_Schema::paid_tag_title( My_IAPSNJ_Schema::year_from_paid_slug( $slug ) ) ) . '</td><td style="text-align:right">' . esc_html( (string) $n ) . '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="2">' . esc_html__( 'No Paid-YYYY tags yet. Create the CRM schema from Sync & Settings, then run the migration.', 'my-iapsnj' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '</div>';

        // Environment checklist.
        $checks = [
            [ My_IAPSNJ_Membership::is_available(), __( 'FluentCart active', 'my-iapsnj' ), '' ],
            [ My_IAPSNJ_Applications::is_available(), __( 'Fluent Forms active', 'my-iapsnj' ), '' ],
            [ count( $products ) > 0, sprintf( __( 'Membership products configured (%d)', 'my-iapsnj' ), count( $products ) ), admin_url( 'admin.php?page=my-iapsnj-products' ) ],
            [ (int) $settings['join_form_id'] > 0 && (int) $settings['renewal_form_id'] > 0, __( 'Join and renewal forms configured', 'my-iapsnj' ), admin_url( 'admin.php?page=my-iapsnj-sync#forms' ) ],
            [ $offline['configured'] && $offline['active'] && stripos( $offline['label'], 'check' ) !== false, sprintf( __( 'Offline payment method active and labelled "%s"', 'my-iapsnj' ), $offline['label'] !== '' ? $offline['label'] : 'Cash' ), admin_url( 'admin.php?page=my-iapsnj-sync#checkout' ) ],
            [ ! empty( $settings['notify_new_member'] ) && ! empty( $settings['notify_emails'] ), __( 'New-member notification recipients set', 'my-iapsnj' ), admin_url( 'admin.php?page=my-iapsnj-sync#notifications' ) ],
            [ My_IAPSNJ_Applications::table_exists(), __( 'Applications table present', 'my-iapsnj' ), '' ],
            [ ! My_IAPSNJ_Migration::tables_exist() || ! function_exists( 'pmpro_getMembershipLevelForUser' ), __( 'Paid Memberships Pro deactivated (tables may remain)', 'my-iapsnj' ), '' ],
        ];
        echo '<div class="fcrm-section"><h2>' . esc_html__( 'Environment', 'my-iapsnj' ) . '</h2><ul class="fcrm-checklist">';
        foreach ( $checks as [ $ok, $label, $link ] ) {
            echo '<li class="' . ( $ok ? 'ok' : 'warn' ) . '">' . ( $ok ? '&#10003;' : '&#9888;' ) . ' ';
            if ( $link ) {
                echo '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
            } else {
                echo esc_html( $label );
            }
            echo '</li>';
        }
        echo '</ul></div>';

        echo '</div>';
    }

    private function card( string $number, string $label, string $link = '' ): void {
        echo '<div class="fcrm-card">';
        if ( $link ) {
            echo '<a href="' . esc_url( $link ) . '" class="fcrm-card-link">';
        }
        echo '<span class="fcrm-card-number">' . esc_html( $number ) . '</span>';
        echo '<span class="fcrm-card-label">' . esc_html( $label ) . '</span>';
        if ( $link ) {
            echo '</a>';
        }
        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // Page: Pending Checks
    // -----------------------------------------------------------------------

    public function render_checks_page(): void {
        $this->guard();
        $this->page_header( __( 'Pending Checks', 'my-iapsnj' ), __( 'Every unpaid "Pay by Check" order in one list. Tick the checks in a deposit, enter the deposit date, confirm the total matches the deposit slip, and mark them paid. Marking paid applies the Paid-YYYY tag, sets paid_through and sends the FluentCart receipt.', 'my-iapsnj' ) );
        $products = My_IAPSNJ_Membership::products_config();
        ?>
        <div id="fcrm-checks-notice" class="fcrm-notice" style="display:none"></div>

        <div class="fcrm-section" id="fcrm-checks-section">
            <div class="fcrm-toolbar">
                <label><input type="checkbox" id="fcrm-checks-membership-only" checked> <?php esc_html_e( 'Membership orders only', 'my-iapsnj' ); ?></label>
                <button id="fcrm-checks-reload" class="button"><?php esc_html_e( 'Reload', 'my-iapsnj' ); ?></button>
                <span id="fcrm-checks-status" class="fcrm-muted"></span>
            </div>
            <div id="fcrm-checks-table"><p class="fcrm-placeholder"><?php esc_html_e( 'Loading pending checks…', 'my-iapsnj' ); ?></p></div>

            <div class="fcrm-deposit-bar">
                <label><?php esc_html_e( 'Deposit date', 'my-iapsnj' ); ?> <input type="date" id="fcrm-deposit-date" value="<?php echo esc_attr( My_IAPSNJ_Dates::today() ); ?>"></label>
                <label><?php esc_html_e( 'Deposit slip total', 'my-iapsnj' ); ?> <input type="text" id="fcrm-deposit-expected" class="small-text" placeholder="0.00" style="width:90px"></label>
                <span class="fcrm-deposit-total"><?php esc_html_e( 'Selected:', 'my-iapsnj' ); ?> <strong id="fcrm-selected-count">0</strong> · <strong id="fcrm-selected-total">$0.00</strong> <span id="fcrm-deposit-match"></span></span>
                <button id="fcrm-mark-paid" class="button button-primary" disabled><?php esc_html_e( 'Mark selected as paid', 'my-iapsnj' ); ?></button>
            </div>
            <div id="fcrm-mark-paid-results"></div>
        </div>

        <div class="fcrm-section" id="fcrm-record-check">
            <h2><?php esc_html_e( 'Record a check for a member who did not use the website', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Creates the FluentCart order for the member, marks it paid by check, and fires the same membership automation as an online payment. The member receives the FluentCart receipt.', 'my-iapsnj' ); ?></p>
            <?php if ( ! $products ) : ?>
                <p class="fcrm-error"><?php esc_html_e( 'Configure at least one membership product first (My IAPSNJ → Membership Products).', 'my-iapsnj' ); ?></p>
            <?php endif; ?>
            <table class="form-table fcrm-form-compact">
                <tr>
                    <th><?php esc_html_e( 'Member', 'my-iapsnj' ); ?></th>
                    <td>
                        <div class="fcrm-user-search-wrap">
                            <input type="text" id="fcrm-rc-member-input" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name, email or member number…', 'my-iapsnj' ); ?>" autocomplete="off">
                            <div id="fcrm-rc-suggestions" class="fcrm-user-suggestions" style="display:none"></div>
                        </div>
                        <input type="hidden" id="fcrm-rc-subscriber-id" value="0">
                        <input type="hidden" id="fcrm-rc-user-id" value="0">
                        <p id="fcrm-rc-member-summary" class="fcrm-muted"></p>
                        <details class="fcrm-details"><summary><?php esc_html_e( 'Member is not in the CRM yet', 'my-iapsnj' ); ?></summary>
                            <p><input type="email" id="fcrm-rc-email" class="regular-text" placeholder="<?php esc_attr_e( 'email@example.com', 'my-iapsnj' ); ?>">
                               <input type="text" id="fcrm-rc-first" placeholder="<?php esc_attr_e( 'First name', 'my-iapsnj' ); ?>">
                               <input type="text" id="fcrm-rc-last" placeholder="<?php esc_attr_e( 'Last name', 'my-iapsnj' ); ?>"></p>
                            <p class="description"><?php esc_html_e( 'A CRM contact, a FluentCart customer and a WordPress login will be created. Prefer adding the full profile in FluentCRM first.', 'my-iapsnj' ); ?></p>
                        </details>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Product', 'my-iapsnj' ); ?></th>
                    <td>
                        <select id="fcrm-rc-variation">
                            <option value=""><?php esc_html_e( '— Select membership product —', 'my-iapsnj' ); ?></option>
                            <?php foreach ( $products as $vid => $cfg ) : ?>
                                <option value="<?php echo esc_attr( (string) $vid ); ?>"><?php echo esc_html( ( $cfg['label'] ?: ( 'Variation #' . $vid ) ) . ' — ' . $cfg['member_type'] . ( $cfg['paid_through'] ? ' → ' . My_IAPSNJ_Dates::ymd_display( $cfg['paid_through'] ) : '' ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Check', 'my-iapsnj' ); ?></th>
                    <td>
                        <input type="text" id="fcrm-rc-check-number" placeholder="<?php esc_attr_e( 'Check #', 'my-iapsnj' ); ?>" class="small-text" style="width:120px">
                        <label><?php esc_html_e( 'Received', 'my-iapsnj' ); ?> <input type="date" id="fcrm-rc-received" value="<?php echo esc_attr( My_IAPSNJ_Dates::today() ); ?>"></label>
                        <label><?php esc_html_e( 'Deposited', 'my-iapsnj' ); ?> <input type="date" id="fcrm-rc-deposit" value="<?php echo esc_attr( My_IAPSNJ_Dates::today() ); ?>"></label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Note', 'my-iapsnj' ); ?></th>
                    <td><input type="text" id="fcrm-rc-note" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'my-iapsnj' ); ?>"></td>
                </tr>
            </table>
            <button id="fcrm-rc-submit" class="button button-primary" <?php disabled( ! $products ); ?>><?php esc_html_e( 'Record check & mark paid', 'my-iapsnj' ); ?></button>
            <div id="fcrm-rc-result" style="margin-top:12px"></div>
        </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Page: Membership Products
    // -----------------------------------------------------------------------

    public function render_products_page(): void {
        $this->guard();
        $this->page_header( __( 'Membership Products', 'my-iapsnj' ), __( 'Map each FluentCart product to the membership state a payment grants. Honorary is never a product — it is assigned by tag in FluentCRM. All products are one-time purchases with fixed calendar-year expiration.', 'my-iapsnj' ) );
        $variations = My_IAPSNJ_Membership::all_variations();
        $raw        = get_option( My_IAPSNJ_Membership::OPTION_PRODUCTS, [] );
        $raw        = is_array( $raw ) ? $raw : [];
        $this_year  = (int) wp_date( 'Y' );
        ?>
        <div id="fcrm-products-notice" class="fcrm-notice" style="display:none"></div>
        <?php if ( ! My_IAPSNJ_Membership::is_available() ) : ?>
            <div class="fcrm-section"><p class="fcrm-error"><?php esc_html_e( 'FluentCart is not active.', 'my-iapsnj' ); ?></p></div></div>
            <?php return; ?>
        <?php endif; ?>
        <?php if ( ! $variations ) : ?>
            <div class="fcrm-section"><p><?php esc_html_e( 'No FluentCart products found yet. Create the products in FluentCart first (Regular Member 2027, Associate Member 2027, Lifetime Member, Multi-Year 2027–2031, 2026 Catch-Up + 2027), then return here.', 'my-iapsnj' ); ?></p></div></div>
            <?php return; ?>
        <?php endif; ?>
        <form id="fcrm-products-form">
        <div class="fcrm-section">
            <table class="widefat fcrm-products-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Enabled', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'FluentCart product / variation', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Price', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Member type', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Sets paid_through', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Paid-YYYY tags (comma-separated years)', 'my-iapsnj' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $variations as $vid => $v ) :
                    $cfg = is_array( $raw[ $vid ] ?? null ) ? $raw[ $vid ] : [];
                    $years = implode( ', ', array_map( 'intval', (array) ( $cfg['years'] ?? [] ) ) );
                    $type  = (string) ( $cfg['member_type'] ?? '' );
                ?>
                    <tr class="<?php echo ! empty( $cfg['enabled'] ) ? 'enabled' : ''; ?>">
                        <td style="text-align:center"><input type="checkbox" name="products[<?php echo esc_attr( (string) $vid ); ?>][enabled]" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>>
                            <input type="hidden" name="products[<?php echo esc_attr( (string) $vid ); ?>][label]" value="<?php echo esc_attr( $v['title'] ); ?>"></td>
                        <td><strong><?php echo esc_html( $v['title'] ); ?></strong><br><small class="fcrm-muted">variation #<?php echo (int) $vid; ?> · <?php echo esc_html( $v['payment_type'] ?: 'onetime' ); ?><?php echo $v['payment_type'] === 'subscription' ? ' — <span class="fcrm-error">' . esc_html__( 'subscription products are not supported', 'my-iapsnj' ) . '</span>' : ''; ?></small></td>
                        <td><?php echo esc_html( My_IAPSNJ_Membership::format_money( $v['price_cents'] ) ); ?></td>
                        <td><select name="products[<?php echo esc_attr( (string) $vid ); ?>][member_type]">
                            <option value=""><?php esc_html_e( '—', 'my-iapsnj' ); ?></option>
                            <?php foreach ( [ My_IAPSNJ_Schema::TYPE_REGULAR, My_IAPSNJ_Schema::TYPE_ASSOCIATE, My_IAPSNJ_Schema::TYPE_LIFETIME ] as $t ) : ?>
                                <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><input type="date" name="products[<?php echo esc_attr( (string) $vid ); ?>][paid_through]" value="<?php echo esc_attr( (string) ( $cfg['paid_through'] ?? '' ) ); ?>" <?php echo $type === My_IAPSNJ_Schema::TYPE_LIFETIME ? 'disabled' : ''; ?>>
                            <br><small class="fcrm-muted"><?php esc_html_e( 'Lifetime: left null on purpose.', 'my-iapsnj' ); ?></small></td>
                        <td><input type="text" name="products[<?php echo esc_attr( (string) $vid ); ?>][years]" value="<?php echo esc_attr( $years ); ?>" placeholder="<?php echo esc_attr( (string) ( $this_year + 1 ) ); ?>" class="regular-text" style="width:220px"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e( 'Examples: Regular Member 2027 → Regular, 2027-12-31, 2027. Multi-Year 2027–2031 → Regular, 2031-12-31, 2027, 2028, 2029, 2030, 2031. 2026 Catch-Up + 2027 → Regular, 2027-12-31, 2026, 2027. Lifetime Member → Lifetime, (no date), (no years).', 'my-iapsnj' ); ?></p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save products', 'my-iapsnj' ); ?></button>
        </div>
        </form>
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Checkout links', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Use these as the Fluent Forms redirect URL (Settings → Confirmation → Redirect to custom URL). The plugin appends the application token automatically; FluentCart forwards extra parameters to the checkout page.', 'my-iapsnj' ); ?></p>
            <table class="widefat striped"><tbody>
            <?php foreach ( My_IAPSNJ_Membership::products_config() as $vid => $cfg ) : ?>
                <tr><td><?php echo esc_html( $cfg['label'] ?: ( 'Variation #' . $vid ) ); ?></td><td><code><?php echo esc_html( My_IAPSNJ_Membership::checkout_url( (int) $vid ) ); ?></code></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Page: Reports
    // -----------------------------------------------------------------------

    public function render_reports_page(): void {
        $this->guard();
        $settings = My_IAPSNJ_Plugin::settings();
        $this->page_header( __( 'Reports', 'my-iapsnj' ) );
        ?>
        <div class="fcrm-section" id="open-applications">
            <h2><?php esc_html_e( 'Applications awaiting payment (follow-up list)', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Members who completed the join or renewal form but have not paid. Their CRM contact carries the Checkout-Abandoned (or Payment-Pending-Check) tag.', 'my-iapsnj' ); ?></p>
            <div class="fcrm-toolbar"><label><?php esc_html_e( 'Older than', 'my-iapsnj' ); ?> <input type="number" class="small-text" id="fcrm-rep-open-days" value="0" min="0"> <?php esc_html_e( 'days', 'my-iapsnj' ); ?></label>
                <button class="button fcrm-report-load" data-report="open-applications" data-target="#fcrm-rep-open" data-days="#fcrm-rep-open-days"><?php esc_html_e( 'Load', 'my-iapsnj' ); ?></button></div>
            <div id="fcrm-rep-open"></div>
        </div>

        <div class="fcrm-section" id="orders-without-application">
            <h2><?php esc_html_e( 'Paid orders with no application', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Membership orders that were paid at checkout without a matching form submission (someone bought directly, or the token did not survive). Checks recorded through Record a Check are excluded.', 'my-iapsnj' ); ?></p>
            <div class="fcrm-toolbar"><label><?php esc_html_e( 'Look back', 'my-iapsnj' ); ?> <input type="number" class="small-text" id="fcrm-rep-orders-days" value="400" min="1"> <?php esc_html_e( 'days', 'my-iapsnj' ); ?></label>
                <button class="button fcrm-report-load" data-report="orders-without-application" data-target="#fcrm-rep-orders" data-days="#fcrm-rep-orders-days"><?php esc_html_e( 'Load', 'my-iapsnj' ); ?></button></div>
            <div id="fcrm-rep-orders"></div>
        </div>

        <div class="fcrm-section" id="aging">
            <h2><?php esc_html_e( 'Aging checks', 'my-iapsnj' ); ?></h2>
            <div class="fcrm-toolbar"><label><?php esc_html_e( 'Pending', 'my-iapsnj' ); ?> <input type="number" class="small-text" id="fcrm-rep-aging-days" value="<?php echo (int) $settings['aging_days']; ?>" min="1"> <?php esc_html_e( '+ days', 'my-iapsnj' ); ?></label>
                <button class="button fcrm-report-load" data-report="aging" data-target="#fcrm-rep-aging" data-days="#fcrm-rep-aging-days"><?php esc_html_e( 'Load', 'my-iapsnj' ); ?></button></div>
            <div id="fcrm-rep-aging"></div>
        </div>

        <div class="fcrm-section" id="orphans">
            <h2><?php esc_html_e( 'WordPress ↔ CRM orphans', 'my-iapsnj' ); ?></h2>
            <div class="fcrm-toolbar">
                <button class="button fcrm-report-load" data-report="users-without-contact" data-target="#fcrm-rep-users" data-paged="1"><?php esc_html_e( 'WordPress users with no CRM contact', 'my-iapsnj' ); ?></button>
                <button class="button fcrm-report-load" data-report="contacts-missing-user" data-target="#fcrm-rep-contacts" data-paged="1"><?php esc_html_e( 'CRM contacts pointing at a deleted user', 'my-iapsnj' ); ?></button>
            </div>
            <div id="fcrm-rep-users"></div>
            <div id="fcrm-rep-contacts"></div>
        </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Page: Profile Mirror (field mapping)
    // -----------------------------------------------------------------------

    public function render_field_mapping_page(): void {
        $this->guard();
        $wp_fields   = $this->mapper->get_wp_fields();
        $fcrm_fields = $this->mapper->get_fcrm_fields();
        $mappings    = $this->mapper->get_saved_mappings();

        uasort( $wp_fields, fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
        uasort( $fcrm_fields, function ( $a, $b ) {
            $ga = $a['source'] === 'default' ? 0 : 1;
            $gb = $b['source'] === 'default' ? 0 : 1;
            return $ga !== $gb ? $ga - $gb : strcmp( $a['label'], $b['label'] );
        } );

        $saved_by_fcrm = [];
        foreach ( $mappings as $m ) {
            $saved_by_fcrm[ ( $m['fcrm_field_source'] ?? '' ) . '__' . ( $m['fcrm_field_key'] ?? '' ) ][] = $m;
        }

        $this->page_header( __( 'Profile Mirror (CRM → WordPress)', 'my-iapsnj' ), __( 'Which FluentCRM contact fields are copied onto the linked WordPress user. One direction only: the CRM is the source of truth and nothing is written back from WordPress.', 'my-iapsnj' ) );
        ?>
        <div id="fcrm-mapping-notice" class="fcrm-notice" style="display:none"></div>
        <div class="fcrm-mapping-toolbar">
            <button id="fcrm-add-row" class="button button-secondary">+ <?php esc_html_e( 'Add Row', 'my-iapsnj' ); ?></button>
            <button id="fcrm-save-mappings" class="button button-primary"><?php esc_html_e( 'Save Mappings', 'my-iapsnj' ); ?></button>
        </div>
        <div class="fcrm-mapping-table-wrap">
            <table class="widefat fcrm-mapping-table" id="fcrm-mapping-table">
                <thead><tr>
                    <th><?php esc_html_e( 'FluentCRM Field (source)', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'WordPress Field (target)', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Field Type', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Enabled', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Remove', 'my-iapsnj' ); ?></th>
                </tr></thead>
                <tbody id="fcrm-mapping-rows">
                <?php
                $rendered = [];
                foreach ( $fcrm_fields as $uid => $fcrm_field ) {
                    $rendered[] = $uid;
                    if ( isset( $saved_by_fcrm[ $uid ] ) ) {
                        foreach ( $saved_by_fcrm[ $uid ] as $m ) {
                            $this->render_mapping_row( $m, $wp_fields, $fcrm_fields );
                        }
                    } else {
                        $rec_uid = $this->mapper->get_recommended_wp_field( $fcrm_field['key'], $fcrm_field['type'], $wp_fields );
                        $rec     = $rec_uid ? ( $wp_fields[ $rec_uid ] ?? null ) : null;
                        $this->render_mapping_row( [
                            'fcrm_field_key'    => $fcrm_field['key'],
                            'fcrm_field_source' => $fcrm_field['source'],
                            'fcrm_field_label'  => $fcrm_field['label'],
                            'wp_field_key'      => $rec ? $rec['key'] : '',
                            'wp_field_source'   => $rec ? $rec['source'] : '',
                            'field_type'        => $fcrm_field['type'],
                            'enabled'           => false,
                            'is_recommendation' => ! empty( $rec_uid ),
                        ], $wp_fields, $fcrm_fields );
                    }
                }
                foreach ( $mappings as $m ) {
                    $fcrm_uid = ( $m['fcrm_field_source'] ?? '' ) . '__' . ( $m['fcrm_field_key'] ?? '' );
                    if ( ! in_array( $fcrm_uid, $rendered, true ) ) {
                        $this->render_mapping_row( $m, $wp_fields, $fcrm_fields );
                    }
                }
                ?>
                </tbody>
            </table>
        </div>
        <template id="fcrm-row-template"><?php $this->render_mapping_row( [], $wp_fields, $fcrm_fields, true ); ?></template>

        <div class="fcrm-section" id="fcrm-preview-section" style="margin-top:28px">
            <h2><?php esc_html_e( 'Sample Data Preview', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Pick a WordPress user to see the CRM value and the mirrored WordPress value side by side.', 'my-iapsnj' ); ?></p>
            <div class="fcrm-preview-search">
                <div class="fcrm-user-search-wrap">
                    <input type="text" id="fcrm-preview-user-input" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name, email or username…', 'my-iapsnj' ); ?>" autocomplete="off">
                    <div id="fcrm-user-suggestions" class="fcrm-user-suggestions" style="display:none"></div>
                </div>
                <button id="fcrm-preview-load" class="button button-primary" disabled><?php esc_html_e( 'Preview Data', 'my-iapsnj' ); ?></button>
            </div>
            <div id="fcrm-preview-results" style="display:none; margin-top:16px"></div>
        </div>
        </div>
        <?php
    }

    private function render_mapping_row( array $mapping, array $wp_fields, array $fcrm_fields, bool $is_template = false ): void {
        $id          = $mapping['id'] ?? '';
        $wp_key      = $mapping['wp_field_key'] ?? '';
        $wp_src      = $mapping['wp_field_source'] ?? '';
        $fcrm_key    = $mapping['fcrm_field_key'] ?? '';
        $fcrm_src    = $mapping['fcrm_field_source'] ?? '';
        $field_type  = $mapping['field_type'] ?? 'text';
        $enabled     = ! empty( $mapping['enabled'] );
        $date_fmt_wp = $mapping['date_format_wp'] ?? 'm/d/Y';
        $value_map   = $mapping['value_map'] ?? [];
        $is_rec      = ! empty( $mapping['is_recommendation'] );
        $row_id      = $is_template ? '__TEMPLATE__' : ( $id ?: My_IAPSNJ_Field_Mapper::generate_id() );

        $src_labels  = [ 'user' => 'WordPress User', 'meta' => 'User Meta', 'acf' => 'ACF (user meta)' ];
        $type_labels = [ 'text' => 'Text', 'email' => 'Email', 'date' => 'Date', 'number' => 'Number', 'select' => 'Dropdown', 'checkbox' => 'Checkbox', 'textarea' => 'Textarea' ];

        echo '<tr class="fcrm-mapping-row' . ( $is_rec ? ' fcrm-row-suggested' : '' ) . '" data-id="' . esc_attr( $row_id ) . '"' . ( $is_rec ? ' data-suggested="1"' : '' ) . '>';

        // CRM field
        echo '<td><select class="fcrm-fcrm-field" name="mappings[' . esc_attr( $row_id ) . '][fcrm_uid]">';
        echo '<option value="">' . esc_html__( '— Select FluentCRM field —', 'my-iapsnj' ) . '</option>';
        foreach ( $fcrm_fields as $uid => $f ) {
            printf(
                '<option value="%s" data-type="%s" data-options="%s" data-source-label="%s" data-type-label="%s"%s>%s</option>',
                esc_attr( $uid ),
                esc_attr( $f['type'] ),
                esc_attr( wp_json_encode( $f['options'] ?? [] ) ),
                esc_attr( $f['source'] === 'custom' ? 'FluentCRM Custom' : 'FluentCRM' ),
                esc_attr( $type_labels[ $f['type'] ] ?? ucfirst( $f['type'] ) ),
                selected( $f['key'] === $fcrm_key && $f['source'] === $fcrm_src, true, false ),
                esc_html( $f['label'] )
            );
        }
        echo '</select><p class="fcrm-field-hint fcrm-fcrm-hint"></p></td>';

        // WP field
        echo '<td>';
        if ( $is_rec && $wp_key !== '' ) {
            echo '<span class="fcrm-suggested-badge">' . esc_html__( 'Suggested', 'my-iapsnj' ) . '</span>';
        }
        echo '<select class="fcrm-wp-field" name="mappings[' . esc_attr( $row_id ) . '][wp_uid]">';
        echo '<option value="">' . esc_html__( '— Don\'t mirror —', 'my-iapsnj' ) . '</option>';
        foreach ( $wp_fields as $uid => $f ) {
            printf(
                '<option value="%s" data-type="%s" data-options="%s" data-date-format="%s" data-source-label="%s" data-type-label="%s"%s>%s</option>',
                esc_attr( $uid ),
                esc_attr( $f['type'] ),
                esc_attr( wp_json_encode( $f['options'] ?? [] ) ),
                esc_attr( $f['date_format_wp'] ?? '' ),
                esc_attr( $src_labels[ $f['source'] ] ?? $f['source'] ),
                esc_attr( $type_labels[ $f['type'] ] ?? ucfirst( $f['type'] ) ),
                selected( $f['key'] === $wp_key && $f['source'] === $wp_src, true, false ),
                esc_html( $f['label'] )
            );
        }
        echo '</select><p class="fcrm-field-hint fcrm-wp-hint"></p></td>';

        // Type
        echo '<td><select class="fcrm-field-type" name="mappings[' . esc_attr( $row_id ) . '][field_type]">';
        foreach ( $type_labels as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $field_type, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<div class="fcrm-date-format-wrap" style="margin-top:4px"><small>' . esc_html__( 'WP date format:', 'my-iapsnj' ) . ' </small>';
        echo '<input type="text" class="fcrm-date-format-wp small-text" value="' . esc_attr( $date_fmt_wp ) . '" placeholder="m/d/Y" name="mappings[' . esc_attr( $row_id ) . '][date_format_wp]"></div>';
        echo '<input type="hidden" class="fcrm-value-map-json" value="' . esc_attr( wp_json_encode( $value_map ) ) . '"></td>';

        echo '<td style="text-align:center"><input type="checkbox" class="fcrm-enabled" name="mappings[' . esc_attr( $row_id ) . '][enabled]" value="1"' . checked( $enabled, true, false ) . '></td>';
        echo '<td style="text-align:center"><button type="button" class="button fcrm-remove-row" title="' . esc_attr__( 'Remove', 'my-iapsnj' ) . '">&#10005;</button></td>';
        echo '</tr>';
    }

    // -----------------------------------------------------------------------
    // Page: Sync & Settings
    // -----------------------------------------------------------------------

    public function render_sync_page(): void {
        $this->guard();
        $settings  = My_IAPSNJ_Plugin::settings();
        $last_sync = get_option( 'my_iapsnj_last_bulk_sync', '' );
        $forms     = $this->fluent_forms_list();
        $offline   = My_IAPSNJ_Membership::offline_labels();
        $this->page_header( __( 'Sync & Settings', 'my-iapsnj' ) );
        ?>
        <div id="fcrm-settings-notice" class="fcrm-notice" style="display:none"></div>

        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Mirror CRM → WordPress now', 'my-iapsnj' ); ?></h2>
            <p><?php esc_html_e( 'Copies every enabled Profile Mirror field from each CRM contact onto its linked WordPress user, in pages of 50.', 'my-iapsnj' ); ?>
               <?php if ( $last_sync ) : ?><span class="fcrm-muted"><?php echo esc_html( sprintf( __( 'Last run: %s', 'my-iapsnj' ), $last_sync ) ); ?></span><?php endif; ?></p>
            <button id="fcrm-bulk-fcrm-to-wp" class="button button-primary"><?php esc_html_e( 'Mirror all contacts → users', 'my-iapsnj' ); ?></button>
            <div id="fcrm-bulk-progress" style="display:none; margin-top:16px">
                <div class="fcrm-progress-bar-wrap"><div id="fcrm-progress-bar" class="fcrm-progress-bar" style="width:0%"></div></div>
                <p id="fcrm-bulk-status"></p>
            </div>
        </div>

        <form id="fcrm-settings-form">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Mirror triggers', 'my-iapsnj' ); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'On CRM contact update', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="sync_on_fcrm_update" value="1" <?php checked( ! empty( $settings['sync_on_fcrm_update'] ) ); ?>> <?php esc_html_e( 'Mirror the contact onto its WordPress user whenever it changes', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'On user register', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="link_on_user_register" value="1" <?php checked( ! empty( $settings['link_on_user_register'] ) ); ?>> <?php esc_html_e( 'Link a new WordPress user to the existing CRM contact with the same email (nothing is pushed to the CRM)', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'On user delete', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="sync_on_user_delete" value="1" <?php checked( ! empty( $settings['sync_on_user_delete'] ) ); ?>> <?php esc_html_e( 'Unlink the CRM contact (never delete it)', 'my-iapsnj' ); ?></label></td></tr>
            </table>
        </div>

        <div class="fcrm-section" id="forms">
            <h2><?php esc_html_e( 'Fluent Forms', 'my-iapsnj' ); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Join form', 'my-iapsnj' ); ?></th><td><?php $this->form_select( 'join_form_id', (int) $settings['join_form_id'], $forms ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Renewal form', 'my-iapsnj' ); ?></th><td><?php $this->form_select( 'renewal_form_id', (int) $settings['renewal_form_id'], $forms ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Email field name', 'my-iapsnj' ); ?></th><td><input type="text" name="form_email_field" value="<?php echo esc_attr( (string) $settings['form_email_field'] ); ?>" class="regular-text"> <p class="description"><?php esc_html_e( 'The Fluent Forms field name that holds the applicant\'s email (default: email).', 'my-iapsnj' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Product field name', 'my-iapsnj' ); ?></th><td><input type="text" name="form_product_field" value="<?php echo esc_attr( (string) ( $settings['form_product_field'] ?? 'membership_product' ) ); ?>" class="regular-text"> <p class="description"><?php esc_html_e( 'Optional: a form field whose value is the FluentCart variation id the applicant chose.', 'my-iapsnj' ); ?></p></td></tr>
            </table>
            <p class="description"><?php esc_html_e( 'Set each form\'s Confirmation to "Redirect to custom URL" using a checkout link from Membership Products. The application token is appended automatically and the email is locked at checkout.', 'my-iapsnj' ); ?></p>
        </div>

        <div class="fcrm-section" id="notifications">
            <h2><?php esc_html_e( 'New-member notification (certificate trigger)', 'my-iapsnj' ); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Send', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="notify_new_member" value="1" <?php checked( ! empty( $settings['notify_new_member'] ) ); ?>> <?php esc_html_e( 'Email the admins when a NEW member\'s payment is confirmed (never on application submitted)', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'Recipients', 'my-iapsnj' ); ?></th><td><input type="text" name="notify_emails" value="<?php echo esc_attr( (string) $settings['notify_emails'] ); ?>" class="large-text"> <p class="description"><?php esc_html_e( 'Comma-separated. Includes name, full mailing address, email, phone, department, rank, member number, product, payment method and order links.', 'my-iapsnj' ); ?></p></td></tr>
            </table>
        </div>

        <div class="fcrm-section" id="checkout">
            <h2><?php esc_html_e( 'Checkout', 'my-iapsnj' ); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Billing address → CRM', 'my-iapsnj' ); ?></th><td>
                    <select name="checkout_fill_address">
                        <option value="empty_only" <?php selected( $settings['checkout_fill_address'], 'empty_only' ); ?>><?php esc_html_e( 'Fill empty CRM address fields only (default)', 'my-iapsnj' ); ?></option>
                        <option value="overwrite" <?php selected( $settings['checkout_fill_address'], 'overwrite' ); ?>><?php esc_html_e( 'Overwrite the CRM address with the checkout billing address', 'my-iapsnj' ); ?></option>
                    </select></td></tr>
                <tr><th><?php esc_html_e( 'Cutover date', 'my-iapsnj' ); ?></th><td><input type="date" name="cutover_date" value="<?php echo esc_attr( (string) $settings['cutover_date'] ); ?>"> <p class="description"><?php esc_html_e( 'Orders before this date are ignored by the orphan report.', 'my-iapsnj' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Aging threshold', 'my-iapsnj' ); ?></th><td><input type="number" name="aging_days" value="<?php echo (int) $settings['aging_days']; ?>" min="1" class="small-text"> <?php esc_html_e( 'days', 'my-iapsnj' ); ?></td></tr>
            </table>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'my-iapsnj' ); ?></button>
        </div>
        </form>

        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Offline payment method label ("Cash" → "Pay by Check")', 'my-iapsnj' ); ?></h2>
            <?php if ( ! My_IAPSNJ_Membership::is_available() ) : ?>
                <p class="fcrm-muted"><?php esc_html_e( 'FluentCart is not active.', 'my-iapsnj' ); ?></p>
            <?php else : ?>
                <p class="description"><?php echo esc_html( $offline['configured']
                    ? sprintf( __( 'Current label: "%1$s" · method %2$s.', 'my-iapsnj' ), $offline['label'] !== '' ? $offline['label'] : 'Cash', $offline['active'] ? __( 'active', 'my-iapsnj' ) : __( 'NOT active', 'my-iapsnj' ) )
                    : __( 'The offline method has never been saved in FluentCart. Enable it once in FluentCart → Settings → Payments → Cash on Delivery → Manage, then come back.', 'my-iapsnj' ) ); ?></p>
                <p><input type="text" id="fcrm-offline-label" class="regular-text" value="<?php echo esc_attr( $offline['label'] !== '' && stripos( $offline['label'], 'cash' ) === false ? $offline['label'] : 'Pay by Check' ); ?>"></p>
                <p><textarea id="fcrm-offline-instructions" class="large-text" rows="4"><?php echo esc_textarea( $offline['instructions'] !== '' ? $offline['instructions'] : "Mail your check payable to IAPSNJ to:\nIAPSNJ, P.O. Box ____, ____, NJ _____\nWrite your member number on the memo line. Your membership is activated when the check is deposited." ); ?></textarea></p>
                <button id="fcrm-apply-offline-labels" class="button" <?php disabled( ! $offline['configured'] ); ?>><?php esc_html_e( 'Apply label & instructions', 'my-iapsnj' ); ?></button>
            <?php endif; ?>
        </div>

        <div class="fcrm-section">
            <h2><?php esc_html_e( 'CRM schema', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Creates any missing tags (Paid-YYYY, Payment-Pending-Check, Checkout-Abandoned, Honorary, Lifetime) and custom fields (member_type, paid_through, member_number, department, rank_level, join_date, legacy_pmpro_level). Existing fields are never modified.', 'my-iapsnj' ); ?></p>
            <p><label><?php esc_html_e( 'Paid-YYYY years', 'my-iapsnj' ); ?> <input type="text" id="fcrm-schema-years" value="<?php echo esc_attr( '2024-' . ( (int) wp_date( 'Y' ) + 5 ) ); ?>" class="small-text" style="width:110px"></label>
               <button id="fcrm-ensure-schema" class="button"><?php esc_html_e( 'Create missing tags & fields', 'my-iapsnj' ); ?></button></p>
            <div id="fcrm-schema-result"></div>
        </div>
        </div>
        <?php
    }

    private function form_select( string $name, int $selected, array $forms ): void {
        if ( ! $forms ) {
            echo '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $selected ) . '" class="small-text" min="0"> <span class="fcrm-muted">' . esc_html__( '(Fluent Forms form ID)', 'my-iapsnj' ) . '</span>';
            return;
        }
        echo '<select name="' . esc_attr( $name ) . '"><option value="0">' . esc_html__( '— none —', 'my-iapsnj' ) . '</option>';
        foreach ( $forms as $id => $title ) {
            echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $selected, $id, false ) . '>' . esc_html( '#' . $id . ' ' . $title ) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @return array<int,string>
     */
    private function fluent_forms_list(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_forms';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT id, title FROM `{$table}` ORDER BY title" );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = (string) $r->title;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Page: Migration
    // -----------------------------------------------------------------------

    public function render_migration_page(): void {
        $this->guard();
        $this->page_header( __( 'Migration (PMPro → FluentCRM)', 'my-iapsnj' ), __( 'Reconstructs membership state from PMPro\'s tables. Every step runs as a dry run first and prints a reviewable report; Apply writes to FluentCRM only. PMPro can be deactivated — the tables are all that is read. Never delete the PMPro tables.', 'my-iapsnj' ) );
        $levels = My_IAPSNJ_Migration::levels();
        $map    = My_IAPSNJ_Migration::level_map( [] );
        $spec   = [];
        foreach ( $map as $id => $type ) {
            $spec[] = $id . ':' . $type;
        }
        ?>
        <div id="fcrm-migration-notice" class="fcrm-notice" style="display:none"></div>
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Options', 'my-iapsnj' ); ?></h2>
            <table class="form-table fcrm-form-compact">
                <tr><th><?php esc_html_e( 'Level map', 'my-iapsnj' ); ?></th><td>
                    <input type="text" id="fcrm-mig-level-map" class="large-text" value="<?php echo esc_attr( implode( ',', $spec ) ); ?>">
                    <p class="description"><?php esc_html_e( 'PMPro level id → member type. Guessed from level names:', 'my-iapsnj' ); ?>
                    <?php foreach ( $levels as $id => $name ) : ?><code><?php echo esc_html( $id . ' = ' . $name ); ?></code> <?php endforeach; ?>
                    <?php esc_html_e( 'Deleted (orphan) level ids are reported by the census.', 'my-iapsnj' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Paid-YYYY from year', 'my-iapsnj' ); ?></th><td><input type="number" id="fcrm-mig-from-year" value="2024" class="small-text"></td></tr>
                <tr><th><?php esc_html_e( 'Order statuses that count as paid', 'my-iapsnj' ); ?></th><td><input type="text" id="fcrm-mig-order-statuses" value="success" class="regular-text"> <span class="fcrm-muted">success[,cancelled]</span></td></tr>
                <tr><th><?php esc_html_e( 'PMPro datetimes are stored in', 'my-iapsnj' ); ?></th><td><select id="fcrm-mig-order-tz"><option value="utc">UTC (PMPro 2.x+, default)</option><option value="site">Site timezone (very old PMPro)</option></select></td></tr>
                <tr><th><?php esc_html_e( 'Address source', 'my-iapsnj' ); ?></th><td><select id="fcrm-mig-address-mode">
                    <option value="prefer_recent"><?php esc_html_e( 'Prefer the more recently modified (PMPro billing if a successful order is within N days, else ACF profile)', 'my-iapsnj' ); ?></option>
                    <option value="prefer_acf"><?php esc_html_e( 'Prefer ACF profile', 'my-iapsnj' ); ?></option>
                    <option value="prefer_pmpro"><?php esc_html_e( 'Prefer PMPro billing', 'my-iapsnj' ); ?></option>
                    <option value="fill_empty"><?php esc_html_e( 'Only fill empty CRM fields', 'my-iapsnj' ); ?></option>
                </select> <label><?php esc_html_e( 'N =', 'my-iapsnj' ); ?> <input type="number" id="fcrm-mig-fresh-days" value="365" class="small-text"></label></td></tr>
                <tr><th><?php esc_html_e( 'Contacts', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" id="fcrm-mig-create-contacts" checked> <?php esc_html_e( 'Create a CRM contact for members who have none', 'my-iapsnj' ); ?></label>
                    &nbsp; <label><input type="checkbox" id="fcrm-mig-include-zero"> <?php esc_html_e( 'Count $0 orders as paid (not recommended)', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'Expected user count', 'my-iapsnj' ); ?></th><td><input type="number" id="fcrm-mig-expected" value="" class="small-text" placeholder="4000"> <span class="fcrm-muted"><?php esc_html_e( 'for Verify logins', 'my-iapsnj' ); ?></span></td></tr>
            </table>
        </div>

        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Steps (run in order)', 'my-iapsnj' ); ?></h2>
            <table class="widefat fcrm-steps-table"><tbody>
            <?php
            $steps = [
                'census'                => [ __( '1. Census (Phase 1)', 'my-iapsnj' ), __( 'Levels, orphaned level IDs, Honorary / Lifetime counts and whether they have orders.', 'my-iapsnj' ), false ],
                'link_subscribers'      => [ __( '2. Link subscribers (P3-7)', 'my-iapsnj' ), __( 'Write the canonical subscriber_id to user meta and contact.user_id for every matched user.', 'my-iapsnj' ), true ],
                'consolidate_addresses' => [ __( '3. Consolidate addresses (P1-3)', 'my-iapsnj' ), __( 'Merge ACF address and PMPro billing meta into the CRM address. Never writes to pmpro_b*.', 'my-iapsnj' ), true ],
                'backfill_year_tags'    => [ __( '4. Backfill Paid-YYYY tags', 'my-iapsnj' ), __( 'From pmpro_membership_orders, year of the order timestamp. Orphan levels are tagged and recorded in legacy_pmpro_level.', 'my-iapsnj' ), true ],
                'migrate_comped'        => [ __( '5. Honorary & Lifetime', 'my-iapsnj' ), __( 'From pmpro_memberships_users (they have no orders). Tag, set member_type, paid_through = null.', 'my-iapsnj' ), true ],
                'set_member_state'      => [ __( '6. member_type & paid_through', 'my-iapsnj' ), __( 'For active Regular / Associate members from their current level and end date.', 'my-iapsnj' ), true ],
                'verify_logins'         => [ __( '7. Verify logins', 'my-iapsnj' ), __( 'Every user keeps user_login, user_email and a hashed password.', 'my-iapsnj' ), false ],
                'reconciliation'        => [ __( '8. Reconciliation report', 'my-iapsnj' ), __( 'Totals before / after: per level, per type, addresses, emails, duplicates.', 'my-iapsnj' ), false ],
            ];
            foreach ( $steps as $key => [ $title, $desc, $writes ] ) :
            ?>
                <tr data-step="<?php echo esc_attr( $key ); ?>">
                    <td style="width:26%"><strong><?php echo esc_html( $title ); ?></strong><br><small class="fcrm-muted"><?php echo esc_html( $desc ); ?></small></td>
                    <td style="width:22%">
                        <button class="button fcrm-mig-run" data-step="<?php echo esc_attr( $key ); ?>" data-dry="1"><?php echo esc_html( $writes ? __( 'Dry run', 'my-iapsnj' ) : __( 'Run', 'my-iapsnj' ) ); ?></button>
                        <?php if ( $writes ) : ?><button class="button button-primary fcrm-mig-run" data-step="<?php echo esc_attr( $key ); ?>" data-dry="0"><?php esc_html_e( 'Apply', 'my-iapsnj' ); ?></button><?php endif; ?>
                    </td>
                    <td><div class="fcrm-mig-progress fcrm-muted"></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <div id="fcrm-mig-report"></div>
        </div>

        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Export PMPro order history (treasurer\'s record)', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Writes every PMPro order (card numbers excluded) to a CSV in a protected folder and gives you a one-time download link. Keep this file outside the database.', 'my-iapsnj' ); ?></p>
            <button id="fcrm-export-orders" class="button"><?php esc_html_e( 'Export orders to CSV', 'my-iapsnj' ); ?></button>
            <div id="fcrm-export-result" style="margin-top:8px"></div>
        </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Page: Notes Search
    // -----------------------------------------------------------------------

    public function render_notes_search_page(): void {
        $this->guard();
        $this->page_header( __( 'Notes Search', 'my-iapsnj' ), __( 'Search all FluentCRM contact notes. Assign tags to contacts directly from the results.', 'my-iapsnj' ) );
        ?>
        <div id="my-iapsnj-notes-notice" class="fcrm-notice" style="display:none"></div>
        <div class="fcrm-section">
            <form id="my-iapsnj-notes-search-form" class="notes-search-bar">
                <input type="text" id="my-iapsnj-notes-query" class="regular-text" placeholder="<?php esc_attr_e( 'Search notes…', 'my-iapsnj' ); ?>" />
                <button type="submit" id="my-iapsnj-notes-search-btn" class="button button-primary"><?php esc_html_e( 'Search', 'my-iapsnj' ); ?></button>
            </form>
        </div>
        <div id="my-iapsnj-notes-results"></div>
        <button id="my-iapsnj-notes-load-more" class="button button-secondary" style="display:none"><?php esc_html_e( 'Load More', 'my-iapsnj' ); ?></button>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // AJAX: mapping / settings / mirror
    // -----------------------------------------------------------------------

    public function ajax_save_mappings(): void {
        $this->ajax_guard();
        $raw   = isset( $_POST['mappings'] ) && is_array( $_POST['mappings'] ) ? wp_unslash( $_POST['mappings'] ) : []; // phpcs:ignore
        $clean = self::sanitize_mapping_rows( $raw, $this->mapper );
        $this->mapper->save_mappings( $clean );
        wp_send_json_success( [ 'count' => count( $clean ) ] );
    }

    public function ajax_save_settings(): void {
        $this->ajax_guard();
        $settings = My_IAPSNJ_Plugin::settings();
        $post     = wp_unslash( $_POST ); // phpcs:ignore

        foreach ( [ 'sync_on_fcrm_update', 'link_on_user_register', 'sync_on_user_delete', 'notify_new_member' ] as $key ) {
            if ( array_key_exists( $key, $post ) ) {
                $settings[ $key ] = ! empty( $post[ $key ] );
            }
        }
        foreach ( [ 'join_form_id', 'renewal_form_id', 'aging_days' ] as $key ) {
            if ( array_key_exists( $key, $post ) ) {
                $settings[ $key ] = max( 0, (int) $post[ $key ] );
            }
        }
        if ( array_key_exists( 'form_email_field', $post ) ) {
            $settings['form_email_field'] = sanitize_key( (string) $post['form_email_field'] ) ?: 'email';
        }
        if ( array_key_exists( 'form_product_field', $post ) ) {
            $settings['form_product_field'] = sanitize_key( (string) $post['form_product_field'] );
        }
        if ( array_key_exists( 'notify_emails', $post ) ) {
            $emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $post['notify_emails'] ) ) );
            $settings['notify_emails'] = implode( ', ', $emails );
        }
        if ( array_key_exists( 'checkout_fill_address', $post ) ) {
            $settings['checkout_fill_address'] = $post['checkout_fill_address'] === 'overwrite' ? 'overwrite' : 'empty_only';
        }
        if ( array_key_exists( 'cutover_date', $post ) ) {
            $settings['cutover_date'] = My_IAPSNJ_Dates::ymd( $post['cutover_date'] );
        }
        if ( (int) $settings['aging_days'] <= 0 ) {
            $settings['aging_days'] = 30;
        }
        update_option( 'my_iapsnj_settings', $settings );
        wp_send_json_success();
    }

    public function ajax_bulk_sync(): void {
        $this->ajax_guard();
        $per_page = min( 200, max( 1, (int) ( $_POST['per_page'] ?? 50 ) ) ); // phpcs:ignore
        $offset   = max( 0, (int) ( $_POST['offset'] ?? 0 ) );               // phpcs:ignore
        wp_send_json_success( self::run_bulk_mirror( $per_page, $offset ) );
    }

    public function ajax_search_users(): void {
        $this->ajax_guard();
        $query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ); // phpcs:ignore
        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( [] );
        }
        $users  = get_users( [
            'search'         => '*' . $query . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 10,
            'fields'         => [ 'ID', 'user_login', 'user_email', 'display_name' ],
        ] );
        $result = [];
        foreach ( $users as $u ) {
            $result[] = [ 'id' => (int) $u->ID, 'label' => $u->display_name . ' (' . $u->user_email . ')', 'email' => $u->user_email ];
        }
        wp_send_json_success( $result );
    }

    public function ajax_sample_data(): void {
        $this->ajax_guard();
        $user_id = (int) ( $_POST['user_id'] ?? 0 ); // phpcs:ignore
        $user    = $user_id ? get_userdata( $user_id ) : false;
        if ( ! $user ) {
            wp_send_json_error( [ 'message' => __( 'User not found.', 'my-iapsnj' ) ] );
        }
        wp_send_json_success( [
            'user' => [ 'id' => $user->ID, 'display_name' => $user->display_name, 'email' => $user->user_email ],
            'rows' => My_IAPSNJ_Engine::get_instance()->get_field_values_for_user( $user_id ),
        ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: checks
    // -----------------------------------------------------------------------

    public function ajax_checks_list(): void {
        $this->ajax_guard();
        wp_send_json_success( My_IAPSNJ_Checks::pending( [
            'membership_only' => ! empty( $_GET['membership_only'] ), // phpcs:ignore
        ] ) );
    }

    public function ajax_checks_mark_paid(): void {
        $this->ajax_guard();
        $order_ids = array_map( 'intval', (array) ( $_POST['order_ids'] ?? [] ) ); // phpcs:ignore
        $numbers   = [];
        foreach ( (array) ( $_POST['check_numbers'] ?? [] ) as $k => $v ) { // phpcs:ignore
            $numbers[ (int) $k ] = sanitize_text_field( wp_unslash( (string) $v ) );
        }
        if ( ! $order_ids ) {
            wp_send_json_error( [ 'message' => __( 'Select at least one order.', 'my-iapsnj' ) ] );
        }
        wp_send_json_success( My_IAPSNJ_Checks::mark_paid(
            $order_ids,
            sanitize_text_field( wp_unslash( $_POST['deposit_date'] ?? '' ) ), // phpcs:ignore
            $numbers,
            sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) // phpcs:ignore
        ) );
    }

    public function ajax_search_members(): void {
        $this->ajax_guard();
        wp_send_json_success( My_IAPSNJ_Checks::search_members( sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ) ) ); // phpcs:ignore
    }

    public function ajax_record_check(): void {
        $this->ajax_guard();
        $p = wp_unslash( $_POST ); // phpcs:ignore
        $result = My_IAPSNJ_Checks::record_check( [
            'subscriber_id' => (int) ( $p['subscriber_id'] ?? 0 ),
            'user_id'       => (int) ( $p['user_id'] ?? 0 ),
            'email'         => sanitize_email( (string) ( $p['email'] ?? '' ) ),
            'first_name'    => sanitize_text_field( (string) ( $p['first_name'] ?? '' ) ),
            'last_name'     => sanitize_text_field( (string) ( $p['last_name'] ?? '' ) ),
            'variation_id'  => (int) ( $p['variation_id'] ?? 0 ),
            'check_number'  => sanitize_text_field( (string) ( $p['check_number'] ?? '' ) ),
            'deposit_date'  => sanitize_text_field( (string) ( $p['deposit_date'] ?? '' ) ),
            'received_date' => sanitize_text_field( (string) ( $p['received_date'] ?? '' ) ),
            'note'          => sanitize_text_field( (string) ( $p['note'] ?? '' ) ),
        ] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( $result );
    }

    // -----------------------------------------------------------------------
    // AJAX: products / labels / schema
    // -----------------------------------------------------------------------

    public function ajax_save_products(): void {
        $this->ajax_guard();
        $raw = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : []; // phpcs:ignore
        $config = [];
        foreach ( $raw as $vid => $cfg ) {
            if ( ! is_array( $cfg ) ) {
                continue;
            }
            $config[ (int) $vid ] = [
                'label'        => (string) ( $cfg['label'] ?? '' ),
                'enabled'      => ! empty( $cfg['enabled'] ),
                'member_type'  => (string) ( $cfg['member_type'] ?? '' ),
                'paid_through' => (string) ( $cfg['paid_through'] ?? '' ),
                'years'        => array_filter( array_map( 'trim', explode( ',', (string) ( $cfg['years'] ?? '' ) ) ) ),
            ];
        }
        My_IAPSNJ_Membership::save_products_config( $config );
        $errors = [];
        foreach ( $config as $vid => $cfg ) {
            if ( ! $cfg['enabled'] ) {
                continue;
            }
            if ( $cfg['member_type'] === '' ) {
                $errors[] = sprintf( __( 'Variation #%d is enabled but has no member type; it will be ignored.', 'my-iapsnj' ), $vid );
            } elseif ( $cfg['member_type'] !== My_IAPSNJ_Schema::TYPE_LIFETIME && My_IAPSNJ_Dates::ymd( $cfg['paid_through'] ) === '' ) {
                $errors[] = sprintf( __( 'Variation #%d has no paid_through date.', 'my-iapsnj' ), $vid );
            }
        }
        wp_send_json_success( [ 'count' => count( My_IAPSNJ_Membership::products_config() ), 'warnings' => $errors ] );
    }

    public function ajax_apply_offline_labels(): void {
        $this->ajax_guard();
        $r = My_IAPSNJ_Membership::apply_offline_labels(
            sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),          // phpcs:ignore
            wp_kses_post( wp_unslash( $_POST['instructions'] ?? '' ) )         // phpcs:ignore
        );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( [ 'message' => $r->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => __( 'FluentCart offline method updated.', 'my-iapsnj' ) ] );
    }

    public function ajax_ensure_schema(): void {
        $this->ajax_guard();
        $range = sanitize_text_field( wp_unslash( $_POST['years'] ?? '' ) ); // phpcs:ignore
        $years = [];
        if ( preg_match( '/^(\d{4})\s*-\s*(\d{4})$/', $range, $m ) ) {
            $years = range( (int) $m[1], (int) $m[2] );
        } elseif ( $range !== '' ) {
            $years = array_filter( array_map( 'intval', explode( ',', $range ) ) );
        }
        try {
            wp_send_json_success( My_IAPSNJ_Schema::ensure_crm_schema( array_slice( $years, 0, 30 ) ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // -----------------------------------------------------------------------
    // AJAX: migration
    // -----------------------------------------------------------------------

    public function ajax_migration_run(): void {
        $this->ajax_guard();
        $p    = wp_unslash( $_POST ); // phpcs:ignore
        $step = sanitize_key( (string) ( $p['step'] ?? '' ) );
        $dry  = ! empty( $p['dry_run'] );
        $args = [
            'level_map'               => My_IAPSNJ_Migration::parse_level_map( (string) ( $p['level_map'] ?? '' ) ),
            'from_year'               => (int) ( $p['from_year'] ?? 2024 ),
            'order_statuses'          => array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $p['order_statuses'] ?? 'success' ) ) ) ),
            'order_tz'                => ( $p['order_tz'] ?? 'utc' ) === 'site' ? 'site' : 'utc',
            'address_mode'            => in_array( $p['address_mode'] ?? '', [ 'prefer_recent', 'prefer_acf', 'prefer_pmpro', 'fill_empty' ], true ) ? $p['address_mode'] : 'prefer_recent',
            'pmpro_fresh_days'        => max( 1, (int) ( $p['pmpro_fresh_days'] ?? 365 ) ),
            'include_zero'            => ! empty( $p['include_zero'] ),
            'create_missing_contacts' => ! empty( $p['create_contacts'] ),
            'expected'                => (int) ( $p['expected'] ?? 0 ),
        ];
        @set_time_limit( 120 ); // phpcs:ignore
        $report = My_IAPSNJ_Migration::run( $step, $dry, max( 0, (int) ( $p['offset'] ?? 0 ) ), min( 500, max( 1, (int) ( $p['limit'] ?? 100 ) ) ), $args );
        if ( ! empty( $report['fatal'] ) ) {
            wp_send_json_error( [ 'message' => $report['fatal'], 'report' => $report ] );
        }
        wp_send_json_success( $report );
    }

    private static function export_dir(): string {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'my-iapsnj-exports';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! file_exists( $dir . '/.htaccess' ) ) {
            file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        if ( ! file_exists( $dir . '/index.html' ) ) {
            file_put_contents( $dir . '/index.html', '' );
        }
        return $dir;
    }

    public function ajax_export_orders(): void {
        $this->ajax_guard();
        @set_time_limit( 300 ); // phpcs:ignore
        $file = self::export_dir() . '/pmpro-orders-' . wp_date( 'Ymd-His' ) . '-' . wp_generate_password( 12, false ) . '.csv';
        $r    = My_IAPSNJ_Migration::export_orders_csv( $file );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( [ 'message' => $r->get_error_message() ] );
        }
        wp_send_json_success( [
            'rows' => $r['rows'],
            'url'  => add_query_arg( [
                'action' => 'my_iapsnj_download_export',
                'nonce'  => wp_create_nonce( 'my_iapsnj_nonce' ),
                'file'   => basename( $file ),
            ], admin_url( 'admin-ajax.php' ) ),
        ] );
    }

    public function ajax_download_export(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Forbidden', 403 );
        }
        $name = basename( sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) ) ); // phpcs:ignore
        $path = self::export_dir() . '/' . $name;
        if ( $name === '' || ! preg_match( '/^pmpro-orders-[\w-]+\.csv$/', $name ) || ! file_exists( $path ) ) {
            wp_die( 'Not found', 404 );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path ); // phpcs:ignore
        exit;
    }

    // -----------------------------------------------------------------------
    // AJAX: reports
    // -----------------------------------------------------------------------

    public function ajax_report(): void {
        $this->ajax_guard();
        $type   = sanitize_key( wp_unslash( $_GET['report'] ?? '' ) ); // phpcs:ignore
        $offset = max( 0, (int) ( $_GET['offset'] ?? 0 ) );            // phpcs:ignore
        $days   = max( 0, (int) ( $_GET['days'] ?? 0 ) );              // phpcs:ignore
        switch ( $type ) {
            case 'open-applications':
                wp_send_json_success( [ 'items' => My_IAPSNJ_Reports::applications_without_order( $days ) ] );
            case 'orders-without-application':
                wp_send_json_success( [ 'items' => My_IAPSNJ_Reports::orders_without_application( $days > 0 ? $days : 400 ) ] );
            case 'aging':
                wp_send_json_success( [ 'items' => My_IAPSNJ_Reports::aging_checks( $days ) ] );
            case 'users-without-contact':
                wp_send_json_success( My_IAPSNJ_Reports::users_without_contact( $offset, 200 ) );
            case 'contacts-missing-user':
                wp_send_json_success( My_IAPSNJ_Reports::contacts_with_missing_user( $offset, 500 ) );
        }
        wp_send_json_error( [ 'message' => 'Unknown report.' ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: notes search (unchanged from 3.x)
    // -----------------------------------------------------------------------

    public function ajax_search_notes(): void {
        $this->ajax_guard();
        global $wpdb;

        $query    = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ); // phpcs:ignore
        $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );                    // phpcs:ignore
        $per_page = 20;
        $offset   = ( $page - 1 ) * $per_page;

        if ( '' === $query ) {
            wp_send_json_error( 'query is required.' );
        }
        $notes_table = $wpdb->prefix . 'fc_subscriber_notes';
        $subs_table  = $wpdb->prefix . 'fc_subscribers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notes_table ) ) !== $notes_table ) {
            wp_send_json_error( 'FluentCRM subscriber notes table not found.' );
        }
        $like = '%' . $wpdb->esc_like( $query ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$notes_table}` n INNER JOIN `{$subs_table}` s ON n.subscriber_id = s.id
             WHERE (n.status IS NULL OR n.status NOT IN ('_company_note_','_system_log_')) AND (n.title LIKE %s OR n.description LIKE %s)",
            $like,
            $like
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT n.id, n.subscriber_id, n.title, n.description, n.created_at, s.first_name, s.last_name, s.email
             FROM `{$notes_table}` n INNER JOIN `{$subs_table}` s ON n.subscriber_id = s.id
             WHERE (n.status IS NULL OR n.status NOT IN ('_company_note_','_system_log_')) AND (n.title LIKE %s OR n.description LIKE %s)
             ORDER BY n.created_at DESC LIMIT %d OFFSET %d",
            $like,
            $like,
            $per_page,
            $offset
        ) );
        $results = array_map( function ( $row ) {
            return [
                'note_id'       => (int) $row->id,
                'subscriber_id' => (int) $row->subscriber_id,
                'contact_name'  => trim( $row->first_name . ' ' . $row->last_name ),
                'email'         => $row->email,
                'note_title'    => $row->title,
                'note_content'  => $row->description,
                'note_date'     => $row->created_at,
            ];
        }, $rows ?: [] );
        wp_send_json_success( [ 'results' => $results, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'has_more' => ( $offset + $per_page ) < $total ] );
    }

    public function ajax_get_tags(): void {
        $this->ajax_guard();
        $tags = \FluentCrm\App\Models\Tag::orderBy( 'title' )->get();
        wp_send_json_success( $tags->map( fn( $t ) => [ 'id' => (int) $t->id, 'title' => $t->title ] )->values()->toArray() );
    }

    public function ajax_assign_tag(): void {
        $this->ajax_guard();
        $subscriber_id = (int) ( $_POST['subscriber_id'] ?? 0 ); // phpcs:ignore
        $tag_id        = (int) ( $_POST['tag_id'] ?? 0 );        // phpcs:ignore
        if ( ! $subscriber_id || ! $tag_id ) {
            wp_send_json_error( 'subscriber_id and tag_id are required.' );
        }
        $subscriber = \FluentCrm\App\Models\Subscriber::find( $subscriber_id );
        if ( ! $subscriber ) {
            wp_send_json_error( 'Contact not found.' );
        }
        $subscriber->attachTags( [ $tag_id ] );
        wp_send_json_success( [
            'message' => __( 'Tag assigned.', 'my-iapsnj' ),
            'tags'    => $subscriber->tags()->get()->map( fn( $t ) => [ 'id' => (int) $t->id, 'title' => $t->title ] )->values()->toArray(),
        ] );
    }
}
