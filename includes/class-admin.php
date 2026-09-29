<?php
/**
 * My_IAPSNJ_Admin
 *
 * Admin menu, screens and AJAX handlers. Menu (groups are headings):
 *
 *  Dashboard              counts, members by type (active / lapsed), environment checks
 *  Dues
 *    Membership Products  product → membership mapping; term rule, renewal products, Join page
 *    Pending Checks       unpaid check orders, batch mark paid, record a check
 *    Checkout Builder     checkout forms per level; billing address → CRM; Pay by Check label
 *  Members
 *    Active Membership    members in good standing (My_IAPSNJ_Members)
 *    Lapsed Members       lapsed members, data checks; grace period, expiry Preview / Apply
 *    Notes Search         FluentCRM notes search with inline tagging
 *  Reports                open applications, orphan orders, aging checks, WP↔CRM orphans; report settings
 *  Settings
 *    Configurations       new-member notification, email design, CRM schema, phones, names & addresses (slug my-iapsnj-sync)
 *    Profile Mirror       CRM → WP field map
 *    Profile Sync         mirror triggers, mirror all now, WordPress role per member type
 *    Migrate PMPro        PMPro → FluentCRM toolkit (only while PMPro tables exist)
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_Admin {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var My_IAPSNJ_Field_Mapper */
    private My_IAPSNJ_Field_Mapper $mapper;

    const CAP        = 'manage_options';
    const MENU_GROUP = 'my-iapsnj-menu-group';
    const MENU_CHILD = 'my-iapsnj-menu-child';

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
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_menu_assets' ] );
        add_action( 'admin_init',            [ $this, 'redirect_legacy_slugs' ] );
        add_action( 'admin_notices',         [ $this, 'environment_notices' ] );

        // Place My IAPSNJ right under Dashboard / FluentHub; nothing else moves.
        add_filter( 'custom_menu_order', '__return_true' );
        add_filter( 'menu_order',        [ $this, 'reorder_admin_menu' ] );

        $ajax = [
            'save_mappings', 'save_settings', 'save_checkout_fields', 'import_field_options', 'bulk_sync', 'search_users', 'sample_data',
            'checkout_form_create', 'checkout_form_duplicate', 'checkout_form_delete', 'checkout_forms_assign',
            'refresh_field_list',
            'search_notes', 'get_tags', 'assign_tag',
            'checks_list', 'checks_mark_paid', 'search_members', 'record_check',
            'save_products', 'apply_offline_labels', 'ensure_schema', 'run_expiry', 'normalize_phones', 'normalize_names', 'send_test_email',
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
        add_menu_page( esc_html__( 'My IAPSNJ', 'my-iapsnj' ), esc_html__( 'My IAPSNJ', 'my-iapsnj' ), self::CAP, 'my-iapsnj', [ $this, 'render_dashboard_page' ], 'dashicons-shield', 56 );

        // WordPress draws two menu levels. Groups are heading entries (a
        // "#…" slug with no page: never a link target, never "current")
        // followed by their pages, indented by the MENU_CHILD class. The
        // slugs of existing screens are unchanged, so bookmarks keep working.
        $settings = [
            [ 'my-iapsnj-sync',         esc_html__( 'Configurations', 'my-iapsnj' ), 'render_sync_page' ],
            [ 'my-iapsnj-mapping',      esc_html__( 'Profile Mirror', 'my-iapsnj' ), 'render_field_mapping_page' ],
            [ 'my-iapsnj-profile-sync', esc_html__( 'Profile Sync', 'my-iapsnj' ),   'render_profile_sync_page' ],
        ];
        if ( My_IAPSNJ_Migration::tables_exist() ) {
            $settings[] = [ 'my-iapsnj-migration', esc_html__( 'Migrate PMPro', 'my-iapsnj' ), 'render_migration_page' ];
        }
        $groups = [
            [ '', '', [
                [ 'my-iapsnj', esc_html__( 'Dashboard', 'my-iapsnj' ), 'render_dashboard_page' ],
            ] ],
            [ 'dues', esc_html__( 'Dues', 'my-iapsnj' ), [
                [ 'my-iapsnj-products', esc_html__( 'Membership Products', 'my-iapsnj' ), 'render_products_page' ],
                [ 'my-iapsnj-checks',   esc_html__( 'Pending Checks', 'my-iapsnj' ),      'render_checks_page' ],
                [ 'my-iapsnj-checkout', esc_html__( 'Checkout Builder', 'my-iapsnj' ),    'render_checkout_builder_page' ],
            ] ],
            [ 'members', esc_html__( 'Members', 'my-iapsnj' ), [
                [ My_IAPSNJ_Members::page_slug( My_IAPSNJ_Members::STATE_ACTIVE ), esc_html__( 'Active Membership', 'my-iapsnj' ), 'render_members_active_page' ],
                [ My_IAPSNJ_Members::page_slug( My_IAPSNJ_Members::STATE_LAPSED ), esc_html__( 'Lapsed Members', 'my-iapsnj' ),    'render_members_lapsed_page' ],
                [ 'my-iapsnj-notes-search', esc_html__( 'Notes Search', 'my-iapsnj' ), 'render_notes_search_page' ],
            ] ],
            [ '', '', [
                [ 'my-iapsnj-reports', esc_html__( 'Reports', 'my-iapsnj' ), 'render_reports_page' ],
            ] ],
            [ 'settings', esc_html__( 'Settings', 'my-iapsnj' ), $settings ],
        ];

        $classes = [];
        foreach ( $groups as [ $group, $label, $pages ] ) {
            if ( $group !== '' ) {
                $slug = '#my-iapsnj-' . $group;
                add_submenu_page( 'my-iapsnj', $label, $label, self::CAP, $slug ); // no callback: a heading, not a page
                $classes[ $slug ] = self::MENU_GROUP;
            }
            foreach ( $pages as [ $slug, $title, $method ] ) {
                add_submenu_page( 'my-iapsnj', $title, $title, self::CAP, $slug, [ $this, $method ] );
                if ( $group !== '' ) {
                    $classes[ $slug ] = self::MENU_CHILD;
                }
            }
        }
        // Index 4 of a submenu entry is its CSS class (core prints it on the <li> and <a>).
        global $submenu;
        foreach ( (array) ( $submenu['my-iapsnj'] ?? [] ) as $i => $item ) {
            if ( isset( $classes[ $item[2] ] ) ) {
                $submenu['my-iapsnj'][ $i ][4] = $classes[ $item[2] ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            }
        }
    }

    /**
     * Group headings and indented children in the My IAPSNJ submenu. The
     * menu shows on every admin screen, so this runs everywhere: inline on
     * core handles (no extra request; FluentCRM's no-conflict mode keeps
     * core scripts). Scoped with two ids so folded, mobile and colour-scheme
     * rules never win. The script turns headings into plain text (no href:
     * not focusable, not announced as links).
     */
    public function enqueue_menu_assets(): void {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }
        $m   = '#adminmenu #toplevel_page_my-iapsnj .wp-submenu';
        $css = $m . ' li.' . self::MENU_GROUP . '{margin-top:6px}'
            . $m . ' li.' . self::MENU_GROUP . '>a{cursor:default;pointer-events:none;box-shadow:none;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding-top:6px;padding-bottom:2px}'
            . $m . ' li.' . self::MENU_CHILD . '{padding-inline-start:10px}'
            . '@media screen and (max-width:782px){' . $m . ' li.' . self::MENU_GROUP . '>a{font-size:13px;padding-top:12px;padding-bottom:4px}' . $m . ' li.' . self::MENU_CHILD . '{padding-inline-start:14px}}';
        wp_add_inline_style( 'admin-menu', $css );
        wp_add_inline_script( 'common', '(function(){var a=document.querySelectorAll("#toplevel_page_my-iapsnj li.' . self::MENU_GROUP . ' > a");for(var i=0;i<a.length;i++){a[i].removeAttribute("href");}}());' );
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
     * menu_order — move My IAPSNJ to just below FluentHub (the Fluent
     * products hub) when it is a top-level menu, else just below Dashboard.
     * Every other item keeps the position WordPress gave it.
     *
     * @param mixed $menu_order
     * @return mixed
     */
    public function reorder_admin_menu( $menu_order ) {
        if ( ! is_array( $menu_order ) || ! in_array( 'my-iapsnj', $menu_order, true ) ) {
            return $menu_order;
        }
        $order  = array_values( array_diff( $menu_order, [ 'my-iapsnj' ] ) );
        $anchor = self::fluenthub_menu_slug( $order );
        if ( $anchor === '' && in_array( 'index.php', $order, true ) ) {
            $anchor = 'index.php';
        }
        if ( $anchor === '' ) {
            return $menu_order;
        }
        $at = (int) array_search( $anchor, $order, true );
        array_splice( $order, $at + 1, 0, [ 'my-iapsnj' ] );
        return $order;
    }

    /**
     * Slug of the FluentHub top-level menu ('' when absent), found by its
     * title or slug so it does not depend on FluentHub's internal slug.
     *
     * @param string[] $menu_order
     */
    private static function fluenthub_menu_slug( array $menu_order ): string {
        global $menu;
        foreach ( (array) $menu as $item ) {
            $slug  = (string) ( $item[2] ?? '' );
            $title = wp_strip_all_tags( (string) ( $item[0] ?? '' ) );
            if ( $slug === '' || ! in_array( $slug, $menu_order, true ) ) {
                continue;
            }
            if ( stripos( $title, 'FluentHub' ) === 0 || preg_match( '/fluent[-_]?hub/i', $slug ) ) {
                return $slug;
            }
        }
        return '';
    }

    public function environment_notices(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( (string) $screen->id, 'my-iapsnj' ) === false ) {
            return;
        }
        if ( ! My_IAPSNJ_Membership::is_available() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'FluentCart is not active. Membership state, the checkout application, Pending Checks and Record a Check are unavailable until it is.', 'my-iapsnj' ) . '</p></div>';
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
        // jquery-ui-sortable (bundled with WordPress) drives the Checkout
        // Builder's drag and drop; no other screen needs it.
        $deps = strpos( $hook, 'my-iapsnj-checkout' ) !== false ? [ 'jquery', 'jquery-ui-sortable' ] : [ 'jquery' ];
        wp_enqueue_script( 'my-iapsnj-admin', MY_IAPSNJ_URL . 'admin/js/admin.js', $deps, MY_IAPSNJ_VERSION, true );

        wp_localize_script( 'my-iapsnj-admin', 'myIapsnj', [
            'agingDays'  => (int) My_IAPSNJ_Plugin::settings()['aging_days'],
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
                'formName'      => __( 'Name of the new checkout form:', 'my-iapsnj' ),
                'confirmDuplicate'  => __( 'Duplicate the saved version of this form? Unsaved changes on this page are not copied.', 'my-iapsnj' ),
                'confirmDeleteForm' => __( 'Delete the checkout form "%s"? This cannot be undone.', 'my-iapsnj' ),
                'condIs'        => __( 'is', 'my-iapsnj' ),
                'condTicked'    => __( 'is ticked', 'my-iapsnj' ),
                'condAnswered'  => __( 'has an answer', 'my-iapsnj' ),
                /* translators: %s: labels of the conditional fields */
                'condLost'      => __( 'Not saved: pick the answers that show %s again — the ones chosen before are no longer options of the field above.', 'my-iapsnj' ),
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
    public static function run_bulk_mirror( int $per_page, int $offset, array $user_ids = [], int $total = 0 ): array {
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
        // Counted on the first page; later pages pass it back.
        if ( $total <= 0 || $offset === 0 ) {
            $total = (int) \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )->where( 'user_id', '>', 0 )->count();
        }
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

        // Active / lapsed per type, by the same rule as the daily expiry job.
        $counts     = My_IAPSNJ_Members::counts();
        $active_url = admin_url( 'admin.php?page=' . My_IAPSNJ_Members::page_slug( My_IAPSNJ_Members::STATE_ACTIVE ) );
        $lapsed_url = admin_url( 'admin.php?page=' . My_IAPSNJ_Members::page_slug( My_IAPSNJ_Members::STATE_LAPSED ) );
        echo '<div class="fcrm-section"><h2>' . esc_html__( 'Members by type', 'my-iapsnj' ) . '</h2><table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__( 'Type', 'my-iapsnj' ) . '</th><th style="text-align:right">' . esc_html__( 'Active', 'my-iapsnj' ) . '</th><th style="text-align:right">' . esc_html__( 'Lapsed', 'my-iapsnj' ) . '</th></tr></thead><tbody>';
        foreach ( $counts as $type => $c ) {
            if ( $type === '_total' ) {
                continue;
            }
            $type_arg = [ 'type' => $type ];
            echo '<tr><td>' . esc_html( (string) $type ) . '</td>'
                . '<td style="text-align:right"><a href="' . esc_url( add_query_arg( $type_arg, $active_url ) ) . '">' . esc_html( number_format_i18n( (int) ( $c['active'] ?? 0 ) ) ) . '</a></td>'
                . '<td style="text-align:right"><a href="' . esc_url( add_query_arg( $type_arg, $lapsed_url ) ) . '">' . esc_html( number_format_i18n( (int) ( $c['lapsed'] ?? 0 ) ) ) . '</a></td></tr>';
        }
        $total = $counts['_total'] ?? [ 'active' => 0, 'lapsed' => 0 ];
        echo '<tr><th>' . esc_html__( 'All members', 'my-iapsnj' ) . '</th>'
            . '<th style="text-align:right"><a href="' . esc_url( $active_url ) . '">' . esc_html( number_format_i18n( (int) $total['active'] ) ) . '</a></th>'
            . '<th style="text-align:right"><a href="' . esc_url( $lapsed_url ) . '">' . esc_html( number_format_i18n( (int) $total['lapsed'] ) ) . '</a></th></tr>';
        echo '</tbody></table></div>';

        echo '<div class="fcrm-section"><h2>' . esc_html__( 'Paid by year (tags)', 'my-iapsnj' ) . '</h2><table class="widefat striped"><tbody>';
        if ( $s['paid_years'] ) {
            foreach ( $s['paid_years'] as $slug => $n ) {
                echo '<tr><td>' . esc_html( My_IAPSNJ_Schema::paid_tag_title( My_IAPSNJ_Schema::year_from_paid_slug( $slug ) ) ) . '</td><td style="text-align:right">' . esc_html( (string) $n ) . '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="2">' . esc_html__( 'No Paid-YYYY tags yet. Create the CRM schema in Settings → Configurations, then run the migration.', 'my-iapsnj' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '</div>';

        // Environment checklist.
        $form_names     = wp_list_pluck( My_IAPSNJ_Checkout_Fields::forms(), 'name' );
        $level_forms    = [];
        $levels_ok      = true;
        foreach ( My_IAPSNJ_Checkout_Fields::assignments() as $level => $form_id ) {
            $n             = count( My_IAPSNJ_Checkout_Fields::input_fields( $form_id ) );
            $levels_ok     = $levels_ok && $n > 0;
            $level_forms[] = sprintf( '%1$s → %2$s (%3$d)', $level, $form_names[ $form_id ] ?? $form_id, $n );
        }
        $variations     = My_IAPSNJ_Membership::is_available() ? My_IAPSNJ_Membership::all_variations() : [];
        $stale          = array_diff_key( $products, $variations );
        $checks = [
            [ My_IAPSNJ_Membership::is_available(), __( 'FluentCart active', 'my-iapsnj' ), '' ],
            [ count( $products ) > 0, sprintf( __( 'Membership products configured (%d)', 'my-iapsnj' ), count( $products ) ), admin_url( 'admin.php?page=my-iapsnj-products' ) ],
            [ ! $stale, $stale ? sprintf( __( 'Mapped variations no longer exist in FluentCart: #%s — re-map after recreating products', 'my-iapsnj' ), implode( ', #', array_keys( $stale ) ) ) : __( 'Every mapped variation exists in FluentCart', 'my-iapsnj' ), admin_url( 'admin.php?page=my-iapsnj-products' ) ],
            [ $levels_ok, sprintf( __( 'Checkout form per level (fields shown): %s', 'my-iapsnj' ), implode( ' · ', $level_forms ) ), admin_url( 'admin.php?page=my-iapsnj-checkout' ) ],
            [ (int) $settings['renewal_variation_regular'] > 0, __( 'Renewal product set for Regular members', 'my-iapsnj' ), admin_url( 'admin.php?page=my-iapsnj-products#join-renew' ) ],
            [ (int) $settings['renewal_variation_associate'] > 0, __( 'Renewal product set for Associate members', 'my-iapsnj' ), admin_url( 'admin.php?page=my-iapsnj-products#join-renew' ) ],
            [ $offline['configured'] && $offline['active'] && stripos( $offline['label'], 'check' ) !== false, sprintf( __( 'Offline payment method active and labelled "%s"', 'my-iapsnj' ), $offline['label'] !== '' ? $offline['label'] : 'Cash' ), admin_url( 'admin.php?page=my-iapsnj-checkout#pay-by-check' ) ],
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
                <label title="<?php esc_attr_e( 'Also decides the membership term: before the renewal-season cutover the check covers this year, on/after it the next year.', 'my-iapsnj' ); ?>"><?php esc_html_e( 'Deposit date', 'my-iapsnj' ); ?> <input type="date" id="fcrm-deposit-date" value="<?php echo esc_attr( My_IAPSNJ_Dates::today() ); ?>"></label>
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
                                <option value="<?php echo esc_attr( (string) $vid ); ?>"><?php echo esc_html( ( $cfg['label'] ?: ( 'Variation #' . $vid ) ) . ' — ' . My_IAPSNJ_Membership::product_grant_label( $cfg ) ); ?></option>
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
                        <p class="description"><?php esc_html_e( 'The deposit date decides the term: before the renewal-season cutover it covers this year, on/after it the next year.', 'my-iapsnj' ); ?></p>
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
        $this->page_header( __( 'Membership Products', 'my-iapsnj' ), __( 'Map each FluentCart product (one-time or subscription) to the member type it grants (each payment covers one year), then set the renewal products and the Join page. Honorary is never a product: set member_type Honorary on the contact in FluentCRM (the Honorary tag alone does not count).', 'my-iapsnj' ) );
        $variations = My_IAPSNJ_Membership::all_variations();
        $raw        = get_option( My_IAPSNJ_Membership::OPTION_PRODUCTS, [] );
        $raw        = is_array( $raw ) ? $raw : [];
        $settings   = My_IAPSNJ_Plugin::settings();
        $products   = My_IAPSNJ_Membership::products_config();
        $cutover    = My_IAPSNJ_Membership::renewal_cutover();
        $today      = My_IAPSNJ_Dates::today();
        $example    = My_IAPSNJ_Dates::membership_term( $today, $cutover );
        ?>
        <div id="fcrm-products-notice" class="fcrm-notice" style="display:none"></div>
        <?php if ( ! $variations ) : ?>
            <div class="fcrm-section"><p><?php esc_html_e( 'No FluentCart products found yet. Create the products in FluentCart first (Regular Membership, Associate Membership, Lifetime Membership), then return here.', 'my-iapsnj' ); ?></p></div></div>
            <?php return; ?>
        <?php endif; ?>
        <form id="fcrm-products-form">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Products', 'my-iapsnj' ); ?></h2>
            <table class="widefat fcrm-products-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Enabled', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'FluentCart product / variation', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Price', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Member type', 'my-iapsnj' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $variations as $vid => $v ) :
                    $cfg  = is_array( $raw[ $vid ] ?? null ) ? $raw[ $vid ] : [];
                    $type = (string) ( $cfg['member_type'] ?? '' );
                ?>
                    <tr class="<?php echo ! empty( $cfg['enabled'] ) ? 'enabled' : ''; ?>">
                        <td style="text-align:center"><input type="checkbox" name="products[<?php echo esc_attr( (string) $vid ); ?>][enabled]" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>>
                            <input type="hidden" name="products[<?php echo esc_attr( (string) $vid ); ?>][label]" value="<?php echo esc_attr( $v['title'] ); ?>"></td>
                        <td><strong><?php echo esc_html( $v['title'] ); ?></strong><br><small class="fcrm-muted">variation #<?php echo (int) $vid; ?> · <?php echo esc_html( $v['payment_type'] === 'subscription' ? __( 'subscription (auto-renews; each renewal payment extends the term)', 'my-iapsnj' ) : __( 'one-time', 'my-iapsnj' ) ); ?></small></td>
                        <td><?php echo esc_html( My_IAPSNJ_Membership::format_money( $v['price_cents'] ) ); ?></td>
                        <td><select name="products[<?php echo esc_attr( (string) $vid ); ?>][member_type]">
                            <option value=""><?php esc_html_e( '—', 'my-iapsnj' ); ?></option>
                            <?php foreach ( [ My_IAPSNJ_Schema::TYPE_REGULAR, My_IAPSNJ_Schema::TYPE_ASSOCIATE, My_IAPSNJ_Schema::TYPE_LIFETIME ] as $t ) : ?>
                                <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save products', 'my-iapsnj' ); ?></button></p>
        </div>
        </form>

        <form class="fcrm-settings-form" id="join-renew">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Membership term, renewals & Join page', 'my-iapsnj' ); ?></h2>
            <div class="fcrm-notice fcrm-form-notice" style="display:none"></div>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Renewal season starts', 'my-iapsnj' ); ?></th><td>
                    <input type="text" name="renewal_cutover" value="<?php echo esc_attr( $cutover ); ?>" class="small-text" style="width:80px" placeholder="10-01" pattern="\d{2}-\d{2}"> <span class="fcrm-muted">MM-DD</span>
                    <p class="description"><?php echo esc_html( sprintf(
                        /* translators: 1: cutover MM-DD, 2: today, 3: paid_through of a payment today, 4: cutover date this year */
                        __( 'Each payment covers one year and the expiration date comes from the payment date, not the product: paid before %1$s → through Dec 31 of that year, on or after it → through Dec 31 of the next year (card payments, subscription renewals and checks by deposit date). A payment today (%2$s) covers through %3$s; from %4$s it covers the following year. The Paid-YYYY tag follows the same year. Lifetime → member_type Lifetime, paid_through deleted, Lifetime tag.', 'my-iapsnj' ),
                        $cutover,
                        My_IAPSNJ_Dates::ymd_display( $today ),
                        My_IAPSNJ_Dates::ymd_display( $example['paid_through'] ),
                        My_IAPSNJ_Dates::ymd_display( substr( $today, 0, 4 ) . '-' . $cutover )
                    ) ); ?></p>
                </td></tr>
                <tr><th><?php esc_html_e( 'Renewal product', 'my-iapsnj' ); ?></th><td>
                    <?php foreach ( [ 'renewal_variation_regular' => My_IAPSNJ_Schema::TYPE_REGULAR, 'renewal_variation_associate' => My_IAPSNJ_Schema::TYPE_ASSOCIATE ] as $key => $type ) : ?>
                        <label style="display:block;margin-bottom:6px"><span style="display:inline-block;min-width:90px"><?php echo esc_html( $type ); ?></span>
                        <select name="<?php echo esc_attr( $key ); ?>">
                            <option value="0"><?php esc_html_e( '— none —', 'my-iapsnj' ); ?></option>
                            <?php foreach ( $products as $vid => $cfg ) : ?>
                                <?php if ( $cfg['member_type'] === $type ) : ?>
                                    <option value="<?php echo esc_attr( (string) $vid ); ?>" <?php selected( (int) $settings[ $key ], (int) $vid ); ?>><?php echo esc_html( ( $cfg['label'] ?: 'Variation #' . $vid ) . ' — ' . My_IAPSNJ_Membership::product_grant_label( $cfg ) ); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select></label>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e( 'Where [iapsnj_renew_link] (member area, dues-reminder emails) sends a logged-in member of each type; only saved products of that type are listed. Lifetime and Honorary members get no link.', 'my-iapsnj' ); ?></p>
                </td></tr>
                <tr><th><?php esc_html_e( 'Join page URL', 'my-iapsnj' ); ?></th><td><input type="url" name="join_page_url" value="<?php echo esc_attr( (string) $settings['join_page_url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/join/' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'The page with the membership buttons (the checkout links below). [iapsnj_renew_link] sends visitors who are not logged in there.', 'my-iapsnj' ); ?></p></td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save term & renewals', 'my-iapsnj' ); ?></button></p>

            <h3><?php esc_html_e( 'Checkout links (Join page buttons)', 'my-iapsnj' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Each link opens the FluentCart checkout with that product; the application fields are collected on the checkout page itself (Dues → Checkout Builder).', 'my-iapsnj' ); ?></p>
            <table class="widefat striped"><tbody>
            <?php foreach ( $products as $vid => $cfg ) : ?>
                <tr><td><?php echo esc_html( $cfg['label'] ?: ( 'Variation #' . $vid ) ); ?></td><td><code><?php echo esc_html( My_IAPSNJ_Membership::checkout_url( (int) $vid ) ); ?></code></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        </form>
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
            <p class="description"><?php esc_html_e( 'Members who entered their email at checkout but have not paid. Their CRM contact carries the Checkout-Abandoned (or Payment-Pending-Check) tag.', 'my-iapsnj' ); ?></p>
            <div class="fcrm-toolbar"><label><?php esc_html_e( 'Older than', 'my-iapsnj' ); ?> <input type="number" class="small-text" id="fcrm-rep-open-days" value="0" min="0"> <?php esc_html_e( 'days', 'my-iapsnj' ); ?></label>
                <button class="button fcrm-report-load" data-report="open-applications" data-target="#fcrm-rep-open" data-days="#fcrm-rep-open-days"><?php esc_html_e( 'Load', 'my-iapsnj' ); ?></button></div>
            <div id="fcrm-rep-open"></div>
        </div>

        <div class="fcrm-section" id="orders-without-application">
            <h2><?php esc_html_e( 'Paid orders with no application', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Membership orders paid without an application row (an order created by hand in FluentCart, or a checkout the plugin did not see). Checks recorded through Record a Check are excluded.', 'my-iapsnj' ); ?></p>
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

        <form class="fcrm-settings-form" id="report-settings">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Report settings', 'my-iapsnj' ); ?></h2>
            <div class="fcrm-notice fcrm-form-notice" style="display:none"></div>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Aging threshold', 'my-iapsnj' ); ?></th><td><input type="number" name="aging_days" value="<?php echo (int) $settings['aging_days']; ?>" min="1" class="small-text"> <?php esc_html_e( 'days', 'my-iapsnj' ); ?>
                    <p class="description"><?php esc_html_e( 'A pending check this old counts as aging: the default of the Aging checks report above, the Dashboard card and the highlight on Pending Checks.', 'my-iapsnj' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Go-live date', 'my-iapsnj' ); ?></th><td><input type="date" name="cutover_date" value="<?php echo esc_attr( (string) $settings['cutover_date'] ); ?>">
                    <p class="description"><?php esc_html_e( 'The day the FluentCart checkout went live. "Paid orders with no application" ignores orders before it (they predate the checkout application).', 'my-iapsnj' ); ?></p></td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save report settings', 'my-iapsnj' ); ?></button></p>
        </div>
        </form>
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
        <p><?php
            printf(
                /* translators: %s: link to Profile Sync */
                esc_html__( 'When the mirror runs, "Mirror all contacts → users" and the WordPress role per member type are on %s.', 'my-iapsnj' ),
                '<a href="' . esc_url( admin_url( 'admin.php?page=my-iapsnj-profile-sync' ) ) . '">' . esc_html__( 'Profile Sync', 'my-iapsnj' ) . '</a>'
            );
        ?></p>
        <div id="fcrm-mapping-notice" class="fcrm-notice" style="display:none"></div>
        <div class="fcrm-mapping-toolbar">
            <button id="fcrm-add-row" class="button button-secondary">+ <?php esc_html_e( 'Add Row', 'my-iapsnj' ); ?></button>
            <button id="fcrm-save-mappings" class="button button-primary"><?php esc_html_e( 'Save Mappings', 'my-iapsnj' ); ?></button>
            <button id="fcrm-refresh-field-list" class="button" title="<?php esc_attr_e( 'The list of WordPress profile fields (user meta keys, ACF fields) is cached for 12 hours. Refresh it after adding a field.', 'my-iapsnj' ); ?>"><?php esc_html_e( 'Refresh field list', 'my-iapsnj' ); ?></button>
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
    // Pages: Settings → Configurations, Profile Sync; Members → Active, Lapsed
    // -----------------------------------------------------------------------

    /**
     * Settings → Configurations (slug my-iapsnj-sync, formerly "Sync &
     * Settings"): what belongs to no other screen.
     */
    public function render_sync_page(): void {
        $this->guard();
        $settings = My_IAPSNJ_Plugin::settings();
        $moved    = [
            'roles'       => [ admin_url( 'admin.php?page=my-iapsnj-profile-sync#roles' ), __( 'Profile Sync', 'my-iapsnj' ), __( 'Mirror now, mirror triggers, WordPress role per member type', 'my-iapsnj' ) ],
            'application' => [ admin_url( 'admin.php?page=my-iapsnj-products#join-renew' ), __( 'Membership Products', 'my-iapsnj' ), __( 'Renewal season, renewal products, Join page URL', 'my-iapsnj' ) ],
            'checkout'    => [ admin_url( 'admin.php?page=my-iapsnj-checkout#checkout-settings' ), __( 'Checkout Builder', 'my-iapsnj' ), __( 'Billing address → CRM, Pay by Check label & instructions', 'my-iapsnj' ) ],
            'expiry'      => [ admin_url( 'admin.php?page=' . My_IAPSNJ_Members::page_slug( My_IAPSNJ_Members::STATE_LAPSED ) . '#expiry' ), __( 'Lapsed Members', 'my-iapsnj' ), __( 'Expirations (Preview / Apply now), grace period', 'my-iapsnj' ) ],
            'reports'     => [ admin_url( 'admin.php?page=my-iapsnj-reports#report-settings' ), __( 'Reports', 'my-iapsnj' ), __( 'Aging threshold, go-live date', 'my-iapsnj' ) ],
        ];
        $this->page_header( __( 'Configurations', 'my-iapsnj' ) );
        ?>
        <form class="fcrm-settings-form" id="notifications">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'New-member notification (certificate trigger)', 'my-iapsnj' ); ?></h2>
            <div class="fcrm-notice fcrm-form-notice" style="display:none"></div>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Send', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="notify_new_member" value="1" <?php checked( ! empty( $settings['notify_new_member'] ) ); ?>> <?php esc_html_e( 'Email the admins when a NEW member\'s payment is confirmed (never on application submitted)', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'Recipients', 'my-iapsnj' ); ?></th><td><input type="text" name="notify_emails" value="<?php echo esc_attr( (string) $settings['notify_emails'] ); ?>" class="large-text"> <p class="description"><?php esc_html_e( 'Comma-separated. Includes name, full mailing address, email, phone, department, rank, member number, product, payment method and order links. FluentCart\'s own "order paid" admin email is separate.', 'my-iapsnj' ); ?></p></td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'my-iapsnj' ); ?></button></p>
        </div>
        </form>

        <?php
        $logo_default   = My_IAPSNJ_Emails::default_logo_url();
        $footer_default = My_IAPSNJ_Emails::default_footer();
        $logo_now       = My_IAPSNJ_Emails::logo_url();
        ?>
        <form class="fcrm-settings-form" id="email-design">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Email design', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'FluentCRM emails (automations, campaigns) use the design in FluentCRM → Settings → Email Styling. With this on, the other site emails get the same design, the logo on top and the footer below: WordPress account emails (login details / set password, password reset, password or email changed, "New user registration" to the admin), FluentCart emails (receipts, Pay by Check instructions, admin order emails) and the new-member notification. FluentCart\'s own email preview still shows FluentCart\'s layout; use Preview / Send test here.', 'my-iapsnj' ); ?></p>
            <div class="fcrm-notice fcrm-form-notice" style="display:none"></div>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Apply', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="email_branding" value="1" <?php checked( ! empty( $settings['email_branding'] ) ); ?>> <?php esc_html_e( 'Send these emails in the FluentCRM design', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'FluentCRM design', 'my-iapsnj' ); ?></th><td>
                    <select name="email_design">
                        <?php foreach ( My_IAPSNJ_Emails::designs() as $design => $label ) : ?>
                            <option value="<?php echo esc_attr( $design ); ?>" <?php selected( My_IAPSNJ_Emails::design(), $design ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Pick the design your FluentCRM emails use (Simple Boxed = grey page, white box).', 'my-iapsnj' ); ?></p>
                </td></tr>
                <tr><th><?php esc_html_e( 'Logo URL', 'my-iapsnj' ); ?></th><td>
                    <input type="url" name="email_logo_url" value="<?php echo esc_attr( (string) $settings['email_logo_url'] ); ?>" class="large-text" placeholder="<?php echo esc_attr( $logo_default ); ?>">
                    <?php if ( $logo_now !== '' ) : ?><p><img src="<?php echo esc_url( $logo_now ); ?>" alt="" style="max-width:80px;height:auto;border:1px solid #dcdcde;background:#fff;padding:4px"></p><?php endif; ?>
                    <p class="description"><?php esc_html_e( 'Empty = the business logo in FluentCRM → Settings → Business Settings, else the site logo. Use a PNG or JPG; many email programs do not show SVG or WebP.', 'my-iapsnj' ); ?></p>
                </td></tr>
                <tr><th><?php esc_html_e( 'Footer', 'my-iapsnj' ); ?></th><td>
                    <textarea name="email_footer" rows="2" class="large-text" placeholder="<?php echo esc_attr( $footer_default ); ?>"><?php echo esc_textarea( (string) $settings['email_footer'] ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Plain text. Empty = the business name and address from FluentCRM → Settings → Business Settings. No unsubscribe link: these are account and payment emails.', 'my-iapsnj' ); ?></p>
                </td></tr>
            </table>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'my-iapsnj' ); ?></button>
                <a class="button" href="<?php echo esc_url( My_IAPSNJ_Emails::preview_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'my-iapsnj' ); ?></a>
                <button type="button" class="button fcrm-send-test-email"><?php echo esc_html( sprintf( /* translators: %s: email address */ __( 'Send test to %s', 'my-iapsnj' ), wp_get_current_user()->user_email ) ); ?></button>
            </p>
            <p class="description"><?php esc_html_e( 'Preview and Send test show the saved design, logo and footer (save first), even while Apply is off. The sample is the "Login details" email a new member receives. On staging, sending is simulated: find the test in FluentSMTP → Email Logs.', 'my-iapsnj' ); ?></p>
            <?php if ( My_IAPSNJ_Membership::is_available() && ! My_IAPSNJ_Emails::fluentcart_supported() ) : ?>
                <p class="description" style="color:#b32d2e"><?php echo esc_html( sprintf(
                    /* translators: 1: installed FluentCart version, 2: required version */
                    __( 'FluentCart %1$s is installed: its emails keep FluentCart\'s own layout until FluentCart is updated to %2$s or later. WordPress emails and the new-member notification already use this design.', 'my-iapsnj' ),
                    defined( 'FLUENTCART_VERSION' ) ? (string) FLUENTCART_VERSION : '?',
                    My_IAPSNJ_Emails::FLUENTCART_MIN
                ) ); ?></p>
            <?php endif; ?>
        </div>
        </form>

        <div class="fcrm-section" id="schema">
            <h2><?php esc_html_e( 'CRM schema', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php echo esc_html( sprintf(
                /* translators: %s: comma-separated custom field slugs */
                __( 'Creates any missing tags (Paid-YYYY, Member-Active, Payment-Pending-Check, Checkout-Abandoned, Honorary, Lifetime) and custom fields (%s). Existing tags and fields are never modified.', 'my-iapsnj' ),
                implode( ', ', array_column( My_IAPSNJ_Schema::required_fields(), 'slug' ) )
            ) ); ?></p>
            <p><label><?php esc_html_e( 'Paid-YYYY years', 'my-iapsnj' ); ?> <input type="text" id="fcrm-schema-years" value="<?php echo esc_attr( '2024-' . ( (int) wp_date( 'Y' ) + 5 ) ); ?>" class="small-text" style="width:110px"></label>
               <button id="fcrm-ensure-schema" class="button"><?php esc_html_e( 'Create missing tags & fields', 'my-iapsnj' ); ?></button></p>
            <div id="fcrm-schema-result"></div>
        </div>

        <div class="fcrm-section" id="phones">
            <h2><?php esc_html_e( 'Phone numbers', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'New numbers are formatted at checkout: the contact Phone gets the US country code (+19084152478, which FluentCRM shows as +1 908-415-2478), and Work phone / Alternate phone, which are plain text fields, are stored as +1 908-415-2478. This brings the numbers already in the CRM to the same format. Numbers that cannot be read (too few digits, extensions, letters) are listed and left as they are. No automations fire.', 'my-iapsnj' ); ?></p>
            <p><button class="button fcrm-normalize-phones" data-dry="1"><?php esc_html_e( 'Preview', 'my-iapsnj' ); ?></button>
               <button class="button button-primary fcrm-normalize-phones" data-dry="0"><?php esc_html_e( 'Apply now', 'my-iapsnj' ); ?></button></p>
            <div id="fcrm-phones-result"></div>
        </div>

        <div class="fcrm-section" id="names">
            <h2><?php esc_html_e( 'Names & addresses', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'New names and addresses are capitalised at checkout, so mailing labels read "John McDonald, 12 Main St Apt 4B, Mt Laurel, NJ": words typed in lower case or all in capitals get a capital letter; words typed in mixed case (McDonald, DeLuca) are kept; Mc and O\' names, PO Box, unit letters (4B), suffixes (III, Jr) and NJ / US / CR are handled. This applies the same rule to the first name, last name, street, city and state already in the CRM. No automations fire and the WordPress profiles are not changed.', 'my-iapsnj' ); ?></p>
            <p><button class="button fcrm-normalize-names" data-dry="1"><?php esc_html_e( 'Preview', 'my-iapsnj' ); ?></button>
               <button class="button button-primary fcrm-normalize-names" data-dry="0"><?php esc_html_e( 'Apply now', 'my-iapsnj' ); ?></button></p>
            <div id="fcrm-names-result"></div>
        </div>

        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Looking for another setting?', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Settings now live next to the screen they affect:', 'my-iapsnj' ); ?></p>
            <ul class="fcrm-moved-list">
                <?php foreach ( $moved as [ $url, $screen, $what ] ) : ?>
                    <li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $screen ); ?></a> — <?php echo esc_html( $what ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        </div>
        <?php
        // Old bookmarks to a section that moved (…my-iapsnj-sync#roles) go to its new screen.
        $redirects = [];
        foreach ( $moved as $anchor => $row ) {
            $redirects[ $anchor ] = $row[0];
        }
        $redirects['expiry-grace'] = $redirects['expiry'];
        echo '<script>(function(){var m=' . wp_json_encode( $redirects ) . ',h=(location.hash||"").replace("#","");if(h&&m[h]){location.replace(m[h]);}}());</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded admin URLs
    }

    /**
     * Settings → Profile Sync: when and how the CRM → WordPress mirror runs,
     * and the WordPress role it gives each member type.
     */
    public function render_profile_sync_page(): void {
        $this->guard();
        $settings  = My_IAPSNJ_Plugin::settings();
        $last_sync = get_option( 'my_iapsnj_last_bulk_sync', '' );
        $roles     = My_IAPSNJ_Engine::assignable_roles();
        $role_map  = My_IAPSNJ_Engine::role_map();
        $this->page_header( __( 'Profile Sync', 'my-iapsnj' ), __( 'The one-way mirror from FluentCRM contacts to their WordPress users: which fields are copied is set in Profile Mirror; this screen sets when it runs and the WordPress role it applies.', 'my-iapsnj' ) );
        ?>
        <div class="fcrm-section" id="mirror-now">
            <h2><?php esc_html_e( 'Mirror all contacts → users now', 'my-iapsnj' ); ?></h2>
            <p><?php esc_html_e( 'Copies every enabled Profile Mirror field from each CRM contact onto its linked WordPress user and re-applies the role below. Takes a while on a large list; leave the page open until it finishes.', 'my-iapsnj' ); ?>
               <?php if ( $last_sync ) : ?><span class="fcrm-muted"><?php echo esc_html( sprintf( __( 'Last complete run: %s', 'my-iapsnj' ), $last_sync ) ); ?></span><?php endif; ?></p>
            <button id="fcrm-bulk-fcrm-to-wp" class="button button-primary"><?php esc_html_e( 'Mirror all contacts → users', 'my-iapsnj' ); ?></button>
            <div id="fcrm-bulk-progress" style="display:none; margin-top:16px">
                <div class="fcrm-progress-bar-wrap"><div id="fcrm-progress-bar" class="fcrm-progress-bar" style="width:0%"></div></div>
                <p id="fcrm-bulk-status"></p>
            </div>
        </div>

        <form class="fcrm-settings-form" id="roles">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Mirror triggers', 'my-iapsnj' ); ?></h2>
            <div class="fcrm-notice fcrm-form-notice" style="display:none"></div>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'On CRM contact update', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="sync_on_fcrm_update" value="1" <?php checked( ! empty( $settings['sync_on_fcrm_update'] ) ); ?>> <?php esc_html_e( 'Mirror the contact onto its WordPress user whenever it changes (also after a paid, check-placed or refunded order)', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'On user register', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="link_on_user_register" value="1" <?php checked( ! empty( $settings['link_on_user_register'] ) ); ?>> <?php esc_html_e( 'Link a new WordPress user to the existing CRM contact with the same email (nothing is pushed to the CRM)', 'my-iapsnj' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'On user delete', 'my-iapsnj' ); ?></th><td><label><input type="checkbox" name="sync_on_user_delete" value="1" <?php checked( ! empty( $settings['sync_on_user_delete'] ) ); ?>> <?php esc_html_e( 'Unlink the CRM contact (never delete it)', 'my-iapsnj' ); ?></label></td></tr>
            </table>

            <h2><?php esc_html_e( 'WordPress role per member type', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Applied by every mirror. Only users whose current role is one of the roles chosen here (or Subscriber) are changed; administrators, editors and any other staff role are never touched. Leave a type on "— leave unchanged —" to skip it. A new member\'s WordPress user is created as Subscriber and gets the mapped role in the same request; to create a dedicated role (e.g. "Member"), use any roles plugin, then pick it here.', 'my-iapsnj' ); ?></p>
            <table class="form-table">
                <?php foreach ( My_IAPSNJ_Schema::member_types() as $type ) : ?>
                <tr><th><?php echo esc_html( $type ); ?></th><td>
                    <select name="role_map[<?php echo esc_attr( $type ); ?>]">
                        <option value=""><?php esc_html_e( '— leave unchanged —', 'my-iapsnj' ); ?></option>
                        <?php foreach ( $roles as $slug => $name ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $role_map[ $type ] ?? '', $slug ); ?>><?php echo esc_html( $name . ' (' . $slug . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <?php endforeach; ?>
                <tr><th><?php esc_html_e( 'When expired', 'my-iapsnj' ); ?></th><td>
                    <select name="role_expired">
                        <option value=""><?php esc_html_e( '— leave the role —', 'my-iapsnj' ); ?></option>
                        <?php foreach ( $roles as $slug => $name ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) ( $settings['role_expired'] ?? '' ), $slug ); ?>><?php echo esc_html( $name . ' (' . $slug . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php
                        printf(
                            /* translators: %s: link to Lapsed Members */
                            esc_html__( 'The role a member drops to when the membership lapses (the daily expiry job; grace period and Preview / Apply on %s). A payment puts the mapped role back.', 'my-iapsnj' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=' . My_IAPSNJ_Members::page_slug( My_IAPSNJ_Members::STATE_LAPSED ) . '#expiry' ) ) . '">' . esc_html__( 'Lapsed Members', 'my-iapsnj' ) . '</a>'
                        );
                    ?></p>
                </td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'my-iapsnj' ); ?></button></p>
        </div>
        </form>
        </div>
        <?php
    }

    /**
     * Members → Active Membership.
     */
    public function render_members_active_page(): void {
        $this->guard();
        $this->page_header( __( 'Active Membership', 'my-iapsnj' ), __( 'Members in good standing: Lifetime and Honorary, and everyone whose paid_through (plus the grace period) is today or later — the same rule as the daily expiry job.', 'my-iapsnj' ) );
        My_IAPSNJ_Members::render_list( My_IAPSNJ_Members::STATE_ACTIVE );
        echo '</div>';
    }

    /**
     * Members → Lapsed Members: the list, plus the grace period and the
     * expiry job (Preview / Apply now) that act on it.
     */
    public function render_members_lapsed_page(): void {
        $this->guard();
        $settings    = My_IAPSNJ_Plugin::settings();
        $last_expiry = get_option( 'my_iapsnj_last_expiry_run', [] );
        $next_expiry = My_IAPSNJ_Membership::is_available() ? wp_next_scheduled( My_IAPSNJ_Membership::CRON_HOOK ) : false;
        $this->page_header( __( 'Lapsed Members', 'my-iapsnj' ), __( 'Contacts with a member type whose paid_through (plus the grace period) is past, or missing. Lifetime and Honorary never lapse.', 'my-iapsnj' ) );
        ?>
        <div class="fcrm-section" id="expiry">
            <h2><?php esc_html_e( 'Expirations (Member-Active tag + role)', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php
                printf(
                    /* translators: %s: link to Profile Sync */
                    esc_html__( 'Runs every day at 00:30 site time: lapsed members lose the Member-Active tag and drop to the "when expired" role (%s); members in good standing without the tag (migrated members, manual CRM edits) get it and their role. Paid-YYYY tags are history and are never removed. Preview lists what would change; Apply does it now, in batches.', 'my-iapsnj' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=my-iapsnj-profile-sync#roles' ) ) . '">' . esc_html__( 'Profile Sync', 'my-iapsnj' ) . '</a>'
                );
            ?>
                <?php if ( is_array( $last_expiry ) && ! empty( $last_expiry['at'] ) ) : ?><br><span class="fcrm-muted"><?php echo esc_html( sprintf( __( 'Last run: %1$s UTC — %2$d expired, %3$d activated, %4$d roles changed.', 'my-iapsnj' ), $last_expiry['at'], (int) ( $last_expiry['report']['expired_now'] ?? 0 ), (int) ( $last_expiry['report']['activated'] ?? 0 ), (int) ( $last_expiry['report']['roles_changed'] ?? 0 ) ) ); ?></span><?php endif; ?>
                <?php if ( $next_expiry ) : ?><br><span class="fcrm-muted"><?php echo esc_html( sprintf( __( 'Next scheduled run: %s', 'my-iapsnj' ), wp_date( 'Y-m-d H:i', $next_expiry ) ) ); ?></span><?php endif; ?>
            </p>
            <form class="fcrm-settings-form fcrm-inline-form" id="expiry-grace">
                <div class="fcrm-notice fcrm-form-notice" style="display:none"></div>
                <label><?php esc_html_e( 'Grace period', 'my-iapsnj' ); ?> <input type="number" name="expiry_grace_days" value="<?php echo (int) ( $settings['expiry_grace_days'] ?? 0 ); ?>" min="0" max="365" class="small-text"> <?php esc_html_e( 'days after paid_through before a membership counts as lapsed', 'my-iapsnj' ); ?></label>
                <button type="submit" class="button"><?php esc_html_e( 'Save', 'my-iapsnj' ); ?></button>
            </form>
            <p><button class="button fcrm-run-expiry" data-dry="1"><?php esc_html_e( 'Preview', 'my-iapsnj' ); ?></button>
               <button class="button button-primary fcrm-run-expiry" data-dry="0"><?php esc_html_e( 'Apply now', 'my-iapsnj' ); ?></button></p>
            <div id="fcrm-expiry-result"></div>
        </div>
        <?php
        My_IAPSNJ_Members::render_list( My_IAPSNJ_Members::STATE_LAPSED );
        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // Page: Checkout Builder
    // -----------------------------------------------------------------------

    public function render_checkout_builder_page(): void {
        $this->guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $form_id = isset( $_GET['form'] ) ? sanitize_key( wp_unslash( $_GET['form'] ) ) : '';
        if ( $form_id !== '' && My_IAPSNJ_Checkout_Fields::form_exists( $form_id ) ) {
            $this->render_checkout_form_editor( $form_id );
            return;
        }

        $forms    = My_IAPSNJ_Checkout_Fields::forms();
        $assign   = My_IAPSNJ_Checkout_Fields::assignments();
        $levels   = My_IAPSNJ_Checkout_Fields::levels();
        $settings = My_IAPSNJ_Plugin::settings();
        $offline  = My_IAPSNJ_Membership::offline_labels();
        $this->page_header( __( 'Checkout Builder', 'my-iapsnj' ), __( 'The membership application is collected on the FluentCart checkout page. Each membership level uses one checkout form; the form is picked from the membership product in the cart (Membership Products → member type). Products that are not membership products (event registrations, merchandise) show no application fields, only FluentCart\'s own checkout fields.', 'my-iapsnj' ) );
        ?>
        <div id="fcrm-settings-notice" class="fcrm-notice" style="display:none"></div>

        <form id="fcrm-checkout-assign-form">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Form used by each membership level', 'my-iapsnj' ); ?></h2>
            <table class="form-table">
                <?php foreach ( $levels as $level => $label ) : ?>
                <tr><th><?php echo esc_html( $label ); ?></th><td>
                    <select name="assign[<?php echo esc_attr( $level ); ?>]">
                        <?php foreach ( $forms as $id => $form ) : ?>
                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $assign[ $level ] ?? '', $id ); ?>><?php echo esc_html( $form['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <?php endforeach; ?>
            </table>
            <p class="description"><?php esc_html_e( 'Lifetime is a Regular membership without an expiry, so a Lifetime product uses the Regular form. Renewals use the same form as joining; fields the member already has in the CRM are prefilled.', 'my-iapsnj' ); ?></p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save assignments', 'my-iapsnj' ); ?></button>
        </div>
        </form>

        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Checkout forms', 'my-iapsnj' ); ?></h2>
            <table class="widefat striped fcrm-checkout-forms-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Form', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Used by', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Fields shown', 'my-iapsnj' ); ?></th>
                    <th><?php esc_html_e( 'Required', 'my-iapsnj' ); ?></th>
                    <th style="width:360px"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $forms as $id => $form ) :
                    $enabled  = My_IAPSNJ_Checkout_Fields::input_fields( $id );
                    $required = array_filter( $enabled, function ( $def ) {
                        return ! empty( $def['required'] );
                    } );
                    $used_by  = My_IAPSNJ_Checkout_Fields::levels_for_form( $id );
                    $edit_url = admin_url( 'admin.php?page=my-iapsnj-checkout&form=' . rawurlencode( $id ) );
                    $view_url = My_IAPSNJ_Checkout_Fields::preview_url( $id );
                ?>
                    <tr data-form="<?php echo esc_attr( $id ); ?>">
                        <td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $form['name'] ); ?></a></strong>
                            <?php if ( $form['heading'] !== '' ) : ?><br><small class="fcrm-muted"><?php echo esc_html( sprintf( __( 'Heading: %s', 'my-iapsnj' ), $form['heading'] ) ); ?></small><?php endif; ?></td>
                        <td><?php echo $used_by ? esc_html( implode( ', ', array_map( function ( $level ) {
                            return $level === My_IAPSNJ_Schema::TYPE_REGULAR ? __( 'Regular + Lifetime', 'my-iapsnj' ) : $level;
                        }, $used_by ) ) ) : '<span class="fcrm-muted">' . esc_html__( 'not used', 'my-iapsnj' ) . '</span>'; ?></td>
                        <td><?php echo (int) count( $enabled ); ?></td>
                        <td><?php echo (int) count( $required ); ?></td>
                        <td style="text-align:right">
                            <a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'my-iapsnj' ); ?></a>
                            <?php $this->render_view_checkout_button( $view_url ); ?>
                            <button type="button" class="button fcrm-form-duplicate" data-form="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Duplicate', 'my-iapsnj' ); ?></button>
                            <button type="button" class="button fcrm-form-delete" data-form="<?php echo esc_attr( $id ); ?>" data-name="<?php echo esc_attr( $form['name'] ); ?>" <?php disabled( $used_by || count( $forms ) < 2 ); ?> title="<?php echo esc_attr( $used_by ? __( 'In use by a membership level: assign another form first.', 'my-iapsnj' ) : '' ); ?>"><?php esc_html_e( 'Delete', 'my-iapsnj' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:10px"><button type="button" id="fcrm-form-create" class="button">+ <?php esc_html_e( 'New form (built-in fields)', 'my-iapsnj' ); ?></button></p>
            <p class="description"><?php esc_html_e( 'Tip: to give Associate members their own form, Duplicate the current one, edit the copy (e.g. hide Department / Rank, require Employer), then assign it to Associate above.', 'my-iapsnj' ); ?></p>
        </div>

        <div class="fcrm-section" id="checkout-settings">
            <h2><?php esc_html_e( 'Checkout settings', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Name, email, phone, billing and shipping address are FluentCart fields, set once for the whole store in FluentCart → Settings → Checkout Fields. Keep them light (name + email required) so event and merchandise checkouts stay short; the shipping address appears automatically only for physical products.', 'my-iapsnj' ); ?></p>
            <?php
            $full_name_only = false;
            try {
                $full_name_only = class_exists( '\FluentCart\App\Services\Renderer\CheckoutFieldsSchema' )
                    && \FluentCart\App\Services\Renderer\CheckoutFieldsSchema::isFullNameRequired();
            } catch ( \Throwable $e ) {
                $full_name_only = false;
            }
            if ( $full_name_only ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'The checkout asks for one "Name" field, so the CRM has to guess where the first name ends. Turn on First Name and Last Name in FluentCart → Settings → Store Settings → Checkout Fields; the plugin then stores exactly what the member typed in each (FluentCart itself re-splits the name at the last space, e.g. "Mary Van" / "Dyke").', 'my-iapsnj' ); ?></p></div>
            <?php endif; ?>
            <form class="fcrm-settings-form">
                <div class="fcrm-notice fcrm-form-notice" style="display:none"></div>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Billing address → CRM', 'my-iapsnj' ); ?></th><td>
                        <select name="checkout_fill_address">
                            <option value="empty_only" <?php selected( $settings['checkout_fill_address'], 'empty_only' ); ?>><?php esc_html_e( 'Fill empty CRM address fields only (default)', 'my-iapsnj' ); ?></option>
                            <option value="overwrite" <?php selected( $settings['checkout_fill_address'], 'overwrite' ); ?>><?php esc_html_e( 'Overwrite the CRM address with the checkout billing address', 'my-iapsnj' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Applied when a membership order is paid or placed by check. A FluentCart → FluentCRM integration feed, if one is set up, writes the address on its own.', 'my-iapsnj' ); ?></p>
                    </td></tr>
                    <tr><th><?php esc_html_e( 'Note under Discount Code', 'my-iapsnj' ); ?></th><td>
                        <textarea name="checkout_discount_note" rows="2" class="large-text"><?php echo esc_textarea( My_IAPSNJ_Checkout_Page::note( 'checkout_discount_note' ) ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Shown under the Discount Code field on membership checkouts, while FluentCart shows that field (FluentCart → Settings → Store Settings → hide coupon field must be off). Links are allowed, e.g. <a href="/contact/">contact us</a>. Empty = no note.', 'my-iapsnj' ); ?></p>
                    </td></tr>
                    <tr><th><?php esc_html_e( 'Note under Pay by Check', 'my-iapsnj' ); ?></th><td>
                        <textarea name="check_delay_note" rows="2" class="large-text"><?php echo esc_textarea( My_IAPSNJ_Checkout_Page::note( 'check_delay_note' ) ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Shown right under the Pay by Check option on membership checkouts, before the member picks it. On those checkouts Pay by Check is always listed second, after the card, which stays preselected. The mailing instructions (Pay by Check below) still show once it is picked. Empty = no note.', 'my-iapsnj' ); ?></p>
                    </td></tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'my-iapsnj' ); ?></button></p>
            </form>

            <h3 id="pay-by-check"><?php esc_html_e( 'Pay by Check (FluentCart\'s offline method)', 'my-iapsnj' ); ?></h3>
            <?php if ( ! My_IAPSNJ_Membership::is_available() ) : ?>
                <p class="fcrm-muted"><?php esc_html_e( 'FluentCart is not active.', 'my-iapsnj' ); ?></p>
            <?php else : ?>
                <p class="description"><?php echo esc_html( $offline['configured']
                    ? sprintf( __( 'Current label: "%1$s" · method %2$s. This edits the same label and instructions as FluentCart → Settings → Payments → Cash on Delivery → Manage.', 'my-iapsnj' ), $offline['label'] !== '' ? $offline['label'] : 'Cash', $offline['active'] ? __( 'active', 'my-iapsnj' ) : __( 'NOT active', 'my-iapsnj' ) )
                    : __( 'The offline method has never been saved in FluentCart. Enable it once in FluentCart → Settings → Payments → Cash on Delivery → Manage, then come back.', 'my-iapsnj' ) ); ?></p>
                <p><input type="text" id="fcrm-offline-label" class="regular-text" value="<?php echo esc_attr( $offline['label'] !== '' && stripos( $offline['label'], 'cash' ) === false ? $offline['label'] : 'Pay by Check' ); ?>"></p>
                <p><textarea id="fcrm-offline-instructions" class="large-text" rows="4"><?php echo esc_textarea( $offline['instructions'] !== '' ? $offline['instructions'] : "Mail your check payable to IAPSNJ to:\nIAPSNJ, P.O. Box ____, ____, NJ _____\nWrite your member number on the memo line. Your membership is activated when the check is deposited." ); ?></textarea></p>
                <button id="fcrm-apply-offline-labels" class="button" <?php disabled( ! $offline['configured'] ); ?>><?php esc_html_e( 'Apply label & instructions', 'my-iapsnj' ); ?></button>
            <?php endif; ?>
        </div>
        </div>
        <?php
    }

    /**
     * Checkout Builder: one form's settings and field list.
     */
    private function render_checkout_form_editor( string $form_id ): void {
        $form     = My_IAPSNJ_Checkout_Fields::forms()[ $form_id ];
        $used_by  = My_IAPSNJ_Checkout_Fields::levels_for_form( $form_id );
        $levels   = My_IAPSNJ_Checkout_Fields::levels();
        $list_url = admin_url( 'admin.php?page=my-iapsnj-checkout' );
        $this->page_header( sprintf( /* translators: form name */ __( 'Checkout Builder: %s', 'my-iapsnj' ), $form['name'] ) );
        ?>
        <p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All checkout forms', 'my-iapsnj' ); ?></a></p>
        <div id="fcrm-settings-notice" class="fcrm-notice" style="display:none"></div>

        <form id="fcrm-checkout-fields-form" data-form="<?php echo esc_attr( $form_id ); ?>">
        <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
        <div class="fcrm-section">
            <h2><?php esc_html_e( 'Form', 'my-iapsnj' ); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Form name (admin only)', 'my-iapsnj' ); ?></th><td><input type="text" name="form_name" value="<?php echo esc_attr( $form['name'] ); ?>" class="regular-text" required></td></tr>
                <tr><th><?php esc_html_e( 'Application heading', 'my-iapsnj' ); ?></th><td><input type="text" name="form_heading" value="<?php echo esc_attr( $form['heading'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Membership Application', 'my-iapsnj' ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Intro text', 'my-iapsnj' ); ?></th><td><input type="text" name="form_intro" value="<?php echo esc_attr( $form['intro'] ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Optional sentence shown above the fields.', 'my-iapsnj' ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Used by', 'my-iapsnj' ); ?></th><td>
                    <?php if ( $used_by ) : ?>
                        <?php echo esc_html( implode( ' · ', array_map( function ( $level ) use ( $levels ) {
                            return $levels[ $level ] ?? $level;
                        }, $used_by ) ) ); ?>
                    <?php else : ?>
                        <span class="fcrm-muted"><?php esc_html_e( 'No membership level uses this form yet.', 'my-iapsnj' ); ?></span>
                    <?php endif; ?>
                    <p class="description"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Change which form each level uses', 'my-iapsnj' ); ?></a></p>
                </td></tr>
            </table>
        </div>

        <div class="fcrm-section" id="application-fields">
            <h2><?php esc_html_e( 'Application fields', 'my-iapsnj' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Active fields are shown on the checkout page above the payment methods, in this order. Drag a row by its ☰ handle to reorder it, or drag it between Active and Inactive to show or hide it (▲▼ move one step). Drag a field to the right under another field (or use ▶) to make it conditional: it is shown only when the field above it is ticked, answered, or has one of the answers you pick; ◀ or dragging left makes it always shown again. Use Section headings to break the form into groups (a heading with no active field under it is not shown). A hidden field is never required. Pick where each answer is stored in FluentCRM (an existing custom field, a contact field, a new custom field created on save, or nowhere). Dropdown / radio options: one per line. Blank answers never erase existing CRM data. Built-in fields can be hidden but not removed.', 'my-iapsnj' ); ?></p>
            <?php
            $targets  = My_IAPSNJ_Checkout_Fields::crm_targets();
            $active   = [];
            $inactive = [];
            foreach ( My_IAPSNJ_Checkout_Fields::config( $form_id ) as $key => $def ) {
                if ( ! empty( $def['enabled'] ) ) {
                    $active[ $key ] = $def;
                } else {
                    $inactive[ $key ] = $def;
                }
            }
            // All CRM fields; the ones a row already writes to start hidden and
            // reappear as soon as no row writes to them (admin.js).
            $candidates = My_IAPSNJ_Checkout_Fields::crm_candidates( $form_id, true );
            uasort( $candidates, function ( $a, $b ) {
                return strcasecmp( (string) $a['label'], (string) $b['label'] );
            } );
            ?>
            <table class="widefat fcrm-products-table fcrm-fields-table" id="fcrm-fields-table">
                <?php $this->render_checkout_fields_head(); ?>
                <tbody id="fcrm-fields-rows" class="fcrm-fields-active">
                <?php
                foreach ( $active as $key => $def ) {
                    $this->render_checkout_field_row( $key, $def, $targets );
                }
                ?>
                <tr class="fcrm-fields-empty"<?php echo $active ? ' style="display:none"' : ''; ?>><td colspan="9" class="fcrm-muted"><?php esc_html_e( 'No active fields: the checkout shows no application for this form.', 'my-iapsnj' ); ?></td></tr>
                </tbody>
            </table>
            <p style="margin-top:10px">
                <button type="button" id="fcrm-add-field" class="button">+ <?php esc_html_e( 'Add field', 'my-iapsnj' ); ?></button>
                <button type="button" id="fcrm-add-section" class="button">+ <?php esc_html_e( 'Add section heading', 'my-iapsnj' ); ?></button>
            </p>

            <h3 style="margin-top:28px"><?php esc_html_e( 'Inactive', 'my-iapsnj' ); ?> <span class="fcrm-muted" style="font-weight:400;font-size:13px"><?php esc_html_e( '— not shown at checkout. Drag a field up into the active list (or tick Show) to add it to the form.', 'my-iapsnj' ); ?></span></h3>
            <table class="widefat fcrm-products-table fcrm-fields-table" id="fcrm-fields-inactive-table">
                <?php $this->render_checkout_fields_head(); ?>
                <tbody id="fcrm-fields-inactive" class="fcrm-fields-inactive">
                <?php
                foreach ( $inactive as $key => $def ) {
                    $this->render_checkout_field_row( $key, $def, $targets );
                }
                ?>
                <?php if ( $candidates ) : ?>
                <tr class="fcrm-fields-subhead"><td colspan="9"><strong><?php esc_html_e( 'Other FluentCRM fields', 'my-iapsnj' ); ?></strong> <span class="fcrm-muted"><?php esc_html_e( 'Every CRM contact field this form does not use yet (membership fields such as member type and paid through are set by payments and never offered).', 'my-iapsnj' ); ?></span></td></tr>
                <?php
                foreach ( $candidates as $key => $def ) {
                    $this->render_checkout_field_row( $key, $def, $targets );
                }
                ?>
                <?php endif; ?>
                </tbody>
            </table>
            <template id="fcrm-field-row-template"><?php $this->render_checkout_field_row( '__TEMPLATE__', [ 'label' => '', 'help' => '', 'type' => 'text', 'options' => [], 'crm' => '', 'crm_kind' => 'none', 'enabled' => true, 'required' => false, 'builtin' => false ], $targets, true ); ?></template>
            <p style="margin-top:10px">
                <button type="button" id="fcrm-import-field-options" class="button" data-form="<?php echo esc_attr( $form_id ); ?>" title="<?php esc_attr_e( 'Fills every empty dropdown / radio list of this form from the ACF field choices (the old onboarding form), else the values already stored in the CRM, else the built-in list. Lists you have filled in are left alone. Works on the saved form: save your other changes first, the page reloads.', 'my-iapsnj' ); ?>"><?php esc_html_e( 'Fill empty dropdown options', 'my-iapsnj' ); ?></button>
                <button type="button" class="button fcrm-form-duplicate" data-form="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Duplicate this form', 'my-iapsnj' ); ?></button>
                <?php $this->render_view_checkout_button( My_IAPSNJ_Checkout_Fields::preview_url( $form_id ), __( 'View checkout (saved version)', 'my-iapsnj' ) ); ?>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save form', 'my-iapsnj' ); ?></button>
            </p>
            <p class="description"><?php esc_html_e( 'Dropdown options come from the ACF field choices of the old onboarding form when ACF is still active, otherwise from the values already stored in the CRM; edit the list freely. Tip: FluentCart\'s own "Agree to terms" checkbox (Settings → Checkout Fields → Legal) can replace the certification checkbox if you prefer a single legal line.', 'my-iapsnj' ); ?></p>
        </div>
        </form>
        </div>
        <?php
    }

    /**
     * "View checkout": the front-end checkout with a membership product in the
     * cart, showing this form (administrators only), in a new tab. Disabled
     * when no membership product is mapped.
     */
    private function render_view_checkout_button( string $url, string $label = '' ): void {
        $label = $label !== '' ? $label : __( 'View', 'my-iapsnj' );
        if ( $url === '' ) {
            echo '<button type="button" class="button" disabled title="' . esc_attr__( 'Map a membership product in Membership Products first.', 'my-iapsnj' ) . '">' . esc_html( $label ) . '</button>';
            return;
        }
        echo '<a class="button fcrm-view-checkout" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Opens the real checkout page in a new tab with a membership product in your cart, showing this form. Nothing is charged unless you place the order.', 'my-iapsnj' ) . '">' . esc_html( $label ) . ' <span class="dashicons dashicons-external" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom"></span></a>';
    }

    /**
     * Column headings of the active / inactive field tables.
     */
    private function render_checkout_fields_head(): void {
        echo '<thead><tr>';
        echo '<th style="width:56px"><span class="screen-reader-text">' . esc_html__( 'Order', 'my-iapsnj' ) . '</span></th>';
        echo '<th>' . esc_html__( 'Show', 'my-iapsnj' ) . '</th>';
        echo '<th>' . esc_html__( 'Required', 'my-iapsnj' ) . '</th>';
        echo '<th>' . esc_html__( 'Label shown to the member', 'my-iapsnj' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'my-iapsnj' ) . '</th>';
        echo '<th>' . esc_html__( 'Options (one per line)', 'my-iapsnj' ) . '</th>';
        echo '<th>' . esc_html__( 'Help text', 'my-iapsnj' ) . '</th>';
        echo '<th>' . esc_html__( 'Stored in FluentCRM as', 'my-iapsnj' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead>';
    }

    /**
     * One row of the application-fields builder (also the JS template).
     * Built-in rows cannot be removed and keep their type; rows offered from
     * FluentCRM ('auto') keep the CRM field's type and are only saved once
     * shown; section rows are headings (no input, never required).
     *
     * @param array<string,string> $targets CRM target picker options
     */
    private function render_checkout_field_row( string $key, array $def, array $targets, bool $is_template = false ): void {
        static $position = 0;
        $position++;
        $n        = 'fields[' . $key . ']';
        $builtin  = ! empty( $def['builtin'] );
        $auto     = ! empty( $def['auto'] );
        $enabled  = ! empty( $def['enabled'] );
        $section  = $def['type'] === 'section';
        $target   = My_IAPSNJ_Checkout_Fields::target_value( $def );
        $types    = [
            'text'     => __( 'Text', 'my-iapsnj' ),
            'phone'    => __( 'Phone (auto-formatted)', 'my-iapsnj' ),
            'textarea' => __( 'Paragraph', 'my-iapsnj' ),
            'select'   => __( 'Dropdown', 'my-iapsnj' ),
            'radio'    => __( 'Radio buttons', 'my-iapsnj' ),
            'date'     => __( 'Date', 'my-iapsnj' ),
            'checkbox' => __( 'Checkbox (yes / no)', 'my-iapsnj' ),
            'section'  => __( 'Section heading', 'my-iapsnj' ),
        ];
        if ( $builtin || $auto ) {
            unset( $types['section'] );
        }
        $has_options = in_array( $def['type'], [ 'select', 'radio' ], true );
        $parent      = $enabled && ! $section ? (string) ( $def['parent'] ?? '' ) : '';
        $classes     = 'fcrm-field-row' . ( $enabled ? ' enabled' : '' ) . ( $section ? ' fcrm-field-section' : '' ) . ( $auto ? ' fcrm-field-auto' : '' ) . ( $parent !== '' ? ' fcrm-field-child' : '' );
        $used        = $auto && ! empty( $def['used'] );

        echo '<tr class="' . esc_attr( $classes . ( $used ? ' fcrm-field-crm-used' : '' ) ) . '" data-key="' . esc_attr( $key ) . '"' . ( $used ? ' style="display:none"' : '' ) . '>';
        echo '<td class="fcrm-field-move"><span class="fcrm-drag-handle dashicons dashicons-menu" title="' . esc_attr__( 'Drag to reorder, or drag between Active and Inactive', 'my-iapsnj' ) . '" aria-hidden="true"></span>';
        echo '<input type="hidden" name="' . esc_attr( $n ) . '[order]" value="' . esc_attr( (string) ( $is_template ? 99 : $position ) ) . '" class="fcrm-field-order">';
        echo '<input type="hidden" name="' . esc_attr( $n ) . '[key]" value="' . esc_attr( $is_template ? '' : $key ) . '">';
        if ( $auto ) {
            echo '<input type="hidden" name="' . esc_attr( $n ) . '[auto]" value="1">';
        }
        echo '<input type="hidden" name="' . esc_attr( $n ) . '[parent]" value="' . esc_attr( $parent ) . '" class="fcrm-field-parent">';
        echo '<span class="fcrm-field-arrows"><button type="button" class="button-link fcrm-field-up" title="' . esc_attr__( 'Move up', 'my-iapsnj' ) . '" aria-label="' . esc_attr__( 'Move up', 'my-iapsnj' ) . '">&#9650;</button><button type="button" class="button-link fcrm-field-down" title="' . esc_attr__( 'Move down', 'my-iapsnj' ) . '" aria-label="' . esc_attr__( 'Move down', 'my-iapsnj' ) . '">&#9660;</button></span>';
        echo '<span class="fcrm-field-arrows"><button type="button" class="button-link fcrm-field-indent" title="' . esc_attr__( 'Indent: show only depending on the field above', 'my-iapsnj' ) . '" aria-label="' . esc_attr__( 'Indent (conditional)', 'my-iapsnj' ) . '">&#9654;</button><button type="button" class="button-link fcrm-field-outdent" title="' . esc_attr__( 'Outdent: always shown', 'my-iapsnj' ) . '" aria-label="' . esc_attr__( 'Outdent', 'my-iapsnj' ) . '">&#9664;</button></span></td>';
        echo '<td style="text-align:center"><input type="checkbox" class="fcrm-field-enabled" name="' . esc_attr( $n ) . '[enabled]" value="1"' . checked( $enabled, true, false ) . '></td>';
        echo '<td style="text-align:center"><input type="checkbox" class="fcrm-field-required" name="' . esc_attr( $n ) . '[required]" value="1"' . checked( $enabled && ! $section && ! empty( $def['required'] ), true, false ) . disabled( ! $enabled || $section, true, false ) . '></td>';
        echo '<td><input type="text" name="' . esc_attr( $n ) . '[label]" value="' . esc_attr( (string) $def['label'] ) . '" class="regular-text fcrm-field-label" style="width:100%" placeholder="' . esc_attr( $section ? __( 'Section heading', 'my-iapsnj' ) : __( 'Label', 'my-iapsnj' ) ) . '" data-placeholder-field="' . esc_attr__( 'Label', 'my-iapsnj' ) . '" data-placeholder-section="' . esc_attr__( 'Section heading', 'my-iapsnj' ) . '">';
        if ( $builtin ) {
            echo '<br><small class="fcrm-muted">' . esc_html__( 'built-in', 'my-iapsnj' ) . ' · ' . esc_html( $key ) . '</small>';
        } elseif ( $auto ) {
            echo '<br><small class="fcrm-muted">' . esc_html__( 'FluentCRM field', 'my-iapsnj' ) . ' · ' . esc_html( $def['crm'] ) . '</small>';
        } elseif ( $section && ! $is_template ) {
            echo '<br><small class="fcrm-muted">' . esc_html__( 'section heading', 'my-iapsnj' ) . '</small>';
        }
        // Conditional display: filled in by admin.js from the parent row above.
        echo '<div class="fcrm-field-condition"' . ( $parent !== '' ? '' : ' style="display:none"' ) . '>';
        echo '<span class="fcrm-cond-arrow" aria-hidden="true">&#8627;</span> ' . esc_html__( 'Show only when', 'my-iapsnj' ) . ' <strong class="fcrm-cond-parent"></strong> <span class="fcrm-cond-rule"></span>';
        echo '<select multiple class="fcrm-cond-values" name="' . esc_attr( $n ) . '[show_when]" size="4" data-selected="' . esc_attr( (string) wp_json_encode( array_values( (array) ( $def['show_when'] ?? [] ) ), JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT ) ) . '" aria-label="' . esc_attr__( 'Answers that show this field', 'my-iapsnj' ) . '"></select>';
        echo '<small class="fcrm-muted fcrm-cond-hint">' . esc_html__( 'Ctrl / ⌘-click for several; none selected = any answer.', 'my-iapsnj' ) . '</small>';
        echo '</div>';
        echo '</td>';
        echo '<td><select name="' . esc_attr( $n ) . '[type]" class="fcrm-field-type"' . ( $builtin || $auto ? ' disabled' : '' ) . '>';
        foreach ( $types as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $def['type'], $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td>';
        echo '<td><textarea name="' . esc_attr( $n ) . '[options]" rows="3" class="fcrm-field-options" style="width:100%;min-width:140px' . ( $has_options ? '' : ';display:none' ) . '" placeholder="' . esc_attr__( 'One option per line', 'my-iapsnj' ) . '">' . esc_textarea( implode( "\n", (array) $def['options'] ) ) . '</textarea>'
            . '<span class="fcrm-muted fcrm-field-no-options"' . ( $has_options ? ' style="display:none"' : '' ) . '>—</span></td>';
        echo '<td><input type="text" name="' . esc_attr( $n ) . '[help]" value="' . esc_attr( (string) $def['help'] ) . '" class="regular-text fcrm-field-help" style="width:100%" placeholder="' . esc_attr( $section ? __( 'Optional text under the heading', 'my-iapsnj' ) : '' ) . '" data-placeholder-section="' . esc_attr__( 'Optional text under the heading', 'my-iapsnj' ) . '"></td>';
        echo '<td><select name="' . esc_attr( $n ) . '[crm_target]" class="fcrm-field-target" style="max-width:220px' . ( $section ? ';display:none' : '' ) . '">';
        if ( $target !== 'none' && ! isset( $targets[ $target ] ) ) {
            echo '<option value="' . esc_attr( $target ) . '" selected>' . esc_html( $def['crm'] ) . ' ' . esc_html__( '(missing in CRM)', 'my-iapsnj' ) . '</option>';
        }
        foreach ( $targets as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $target, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><span class="fcrm-muted fcrm-field-no-target"' . ( $section ? '' : ' style="display:none"' ) . '>—</span></td>';
        echo '<td style="text-align:center">' . ( $builtin || $auto ? '' : '<button type="button" class="button fcrm-field-remove" title="' . esc_attr__( 'Remove', 'my-iapsnj' ) . '">&#10005;</button>' ) . '</td>';
        echo '</tr>';
    }

    // -----------------------------------------------------------------------
    // Page: Migration
    // -----------------------------------------------------------------------

    public function render_migration_page(): void {
        $this->guard();
        $this->page_header( __( 'Migrate PMPro', 'my-iapsnj' ), __( 'Reconstructs membership state from PMPro\'s tables. Every step runs as a dry run first and prints a reviewable report; Apply writes to FluentCRM only. PMPro can be deactivated — the tables are all that is read. Never delete the PMPro tables.', 'my-iapsnj' ) );
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
        My_IAPSNJ_Field_Mapper::flush_field_cache();
        wp_send_json_success( [ 'count' => count( $clean ) ] );
    }

    /**
     * Profile Mirror: forget the cached list of WordPress profile fields.
     */
    public function ajax_refresh_field_list(): void {
        $this->ajax_guard();
        My_IAPSNJ_Field_Mapper::flush_field_cache();
        wp_send_json_success();
    }

    public function ajax_save_settings(): void {
        $this->ajax_guard();
        $settings = My_IAPSNJ_Plugin::settings();
        $post     = wp_unslash( $_POST ); // phpcs:ignore

        foreach ( [ 'sync_on_fcrm_update', 'link_on_user_register', 'sync_on_user_delete', 'notify_new_member', 'email_branding' ] as $key ) {
            if ( array_key_exists( $key, $post ) ) {
                $settings[ $key ] = ! empty( $post[ $key ] );
            }
        }
        if ( array_key_exists( 'aging_days', $post ) ) {
            $settings['aging_days'] = max( 0, (int) $post['aging_days'] );
        }
        // A renewal product must be a saved product of that member type.
        $products = My_IAPSNJ_Membership::products_config();
        foreach ( [ 'renewal_variation_regular' => My_IAPSNJ_Schema::TYPE_REGULAR, 'renewal_variation_associate' => My_IAPSNJ_Schema::TYPE_ASSOCIATE ] as $key => $type ) {
            if ( array_key_exists( $key, $post ) ) {
                $vid              = max( 0, (int) $post[ $key ] );
                $settings[ $key ] = ( $vid > 0 && ( $products[ $vid ]['member_type'] ?? '' ) === $type ) ? $vid : 0;
            }
        }
        if ( array_key_exists( 'join_page_url', $post ) ) {
            $settings['join_page_url'] = esc_url_raw( (string) $post['join_page_url'] );
        }
        if ( array_key_exists( 'role_map', $post ) ) {
            $allowed  = My_IAPSNJ_Engine::assignable_roles();
            $role_map = [];
            foreach ( (array) $post['role_map'] as $type => $role ) {
                $type = sanitize_text_field( (string) $type );
                $role = sanitize_key( (string) $role );
                if ( in_array( $type, My_IAPSNJ_Schema::member_types(), true ) && $role !== '' && isset( $allowed[ $role ] ) ) {
                    $role_map[ $type ] = $role;
                }
            }
            $settings['role_map'] = $role_map;
        }
        if ( array_key_exists( 'role_expired', $post ) ) {
            $role = sanitize_key( (string) $post['role_expired'] );
            $settings['role_expired'] = ( $role !== '' && isset( My_IAPSNJ_Engine::assignable_roles()[ $role ] ) ) ? $role : '';
        }
        if ( array_key_exists( 'expiry_grace_days', $post ) ) {
            $settings['expiry_grace_days'] = min( 365, max( 0, (int) $post['expiry_grace_days'] ) );
        }
        if ( array_key_exists( 'renewal_cutover', $post ) ) {
            $settings['renewal_cutover'] = My_IAPSNJ_Dates::month_day( (string) $post['renewal_cutover'] );
        }
        if ( array_key_exists( 'notify_emails', $post ) ) {
            $emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $post['notify_emails'] ) ) );
            $settings['notify_emails'] = implode( ', ', $emails );
        }
        if ( array_key_exists( 'email_design', $post ) ) {
            $design                   = sanitize_key( (string) $post['email_design'] );
            $settings['email_design'] = isset( My_IAPSNJ_Emails::designs()[ $design ] ) ? $design : 'simple';
        }
        if ( array_key_exists( 'email_logo_url', $post ) ) {
            $settings['email_logo_url'] = esc_url_raw( trim( (string) $post['email_logo_url'] ) );
        }
        if ( array_key_exists( 'email_footer', $post ) ) {
            $settings['email_footer'] = sanitize_textarea_field( (string) $post['email_footer'] );
        }
        if ( array_key_exists( 'checkout_fill_address', $post ) ) {
            $settings['checkout_fill_address'] = $post['checkout_fill_address'] === 'overwrite' ? 'overwrite' : 'empty_only';
        }
        // Checkout notes: short text, links allowed; empty = no note.
        foreach ( [ 'checkout_discount_note', 'check_delay_note' ] as $key ) {
            if ( array_key_exists( $key, $post ) ) {
                $settings[ $key ] = trim( wp_kses( (string) $post[ $key ], My_IAPSNJ_Checkout_Page::note_tags() ) );
            }
        }
        if ( array_key_exists( 'cutover_date', $post ) ) {
            $settings['cutover_date'] = My_IAPSNJ_Dates::ymd( $post['cutover_date'] );
        }
        if ( (int) $settings['aging_days'] <= 0 ) {
            $settings['aging_days'] = 30;
        }
        update_option( 'my_iapsnj_settings', $settings );
        My_IAPSNJ_Reports::flush_summary(); // aging threshold / grace feed the Dashboard numbers
        wp_send_json_success();
    }

    public function ajax_save_checkout_fields(): void {
        $this->ajax_guard();
        $post    = wp_unslash( $_POST ); // phpcs:ignore
        $form_id = sanitize_key( (string) ( $post['form_id'] ?? '' ) );
        if ( ! My_IAPSNJ_Checkout_Fields::form_exists( $form_id ) ) {
            wp_send_json_error( [ 'message' => __( 'That checkout form no longer exists.', 'my-iapsnj' ) ] );
        }
        $raw = isset( $post['fields'] ) && is_array( $post['fields'] ) ? $post['fields'] : [];
        $saved = My_IAPSNJ_Checkout_Fields::save_config( $raw, $form_id );
        if ( is_wp_error( $saved ) ) {
            wp_send_json_error( [ 'message' => $saved->get_error_message() ] );
        }
        My_IAPSNJ_Checkout_Fields::save_form_meta(
            $form_id,
            (string) ( $post['form_name'] ?? '' ),
            (string) ( $post['form_heading'] ?? '' ),
            (string) ( $post['form_intro'] ?? '' )
        );
        wp_send_json_success( [ 'count' => count( My_IAPSNJ_Checkout_Fields::input_fields( $form_id ) ) ] );
    }

    /**
     * Checkout Builder: a new form with the built-in fields.
     */
    public function ajax_checkout_form_create(): void {
        $this->ajax_guard();
        $name = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ); // phpcs:ignore
        $id   = My_IAPSNJ_Checkout_Fields::create_form( $name );
        wp_send_json_success( [ 'form' => $id, 'url' => admin_url( 'admin.php?page=my-iapsnj-checkout&form=' . rawurlencode( $id ) ) ] );
    }

    public function ajax_checkout_form_duplicate(): void {
        $this->ajax_guard();
        $source = sanitize_key( wp_unslash( (string) ( $_POST['form'] ?? '' ) ) ); // phpcs:ignore
        $id     = My_IAPSNJ_Checkout_Fields::duplicate_form( $source );
        if ( $id === '' ) {
            wp_send_json_error( [ 'message' => __( 'That checkout form no longer exists.', 'my-iapsnj' ) ] );
        }
        wp_send_json_success( [ 'form' => $id, 'url' => admin_url( 'admin.php?page=my-iapsnj-checkout&form=' . rawurlencode( $id ) ) ] );
    }

    public function ajax_checkout_form_delete(): void {
        $this->ajax_guard();
        $id     = sanitize_key( wp_unslash( (string) ( $_POST['form'] ?? '' ) ) ); // phpcs:ignore
        $result = My_IAPSNJ_Checkout_Fields::delete_form( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success();
    }

    public function ajax_checkout_forms_assign(): void {
        $this->ajax_guard();
        $assign = isset( $_POST['assign'] ) && is_array( $_POST['assign'] ) ? wp_unslash( $_POST['assign'] ) : []; // phpcs:ignore
        My_IAPSNJ_Checkout_Fields::save_assignments( array_map( 'strval', $assign ) );
        wp_send_json_success();
    }

    /**
     * Fill empty dropdown / radio option lists from ACF choices, CRM values
     * or the built-in lists (the saved configuration, not the unsaved form).
     */
    public function ajax_import_field_options(): void {
        $this->ajax_guard();
        $form_id = sanitize_key( wp_unslash( (string) ( $_POST['form'] ?? '' ) ) ); // phpcs:ignore
        if ( ! My_IAPSNJ_Checkout_Fields::form_exists( $form_id ) ) {
            wp_send_json_error( [ 'message' => __( 'That checkout form no longer exists.', 'my-iapsnj' ) ] );
        }
        try {
            $report = My_IAPSNJ_Checkout_Fields::import_options( true, $form_id );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
        $config  = My_IAPSNJ_Checkout_Fields::config( $form_id );
        $sources = [
            'acf'      => __( 'ACF field choices', 'my-iapsnj' ),
            'crm'      => __( 'values already in the CRM', 'my-iapsnj' ),
            'usermeta' => __( 'values in the old WordPress profiles', 'my-iapsnj' ),
            'builtin'  => __( 'built-in list', 'my-iapsnj' ),
        ];
        $lines = [];
        foreach ( $report as $key => $r ) {
            $lines[] = sprintf(
                /* translators: 1: field label, 2: number of options, 3: where they came from */
                __( '%1$s: %2$d options from %3$s', 'my-iapsnj' ),
                (string) ( $config[ $key ]['label'] ?? $key ),
                (int) $r['count'],
                $sources[ $r['source'] ] ?? $r['source']
            );
        }
        wp_send_json_success( [
            'count'   => count( $report ),
            'message' => $lines
                ? implode( ' · ', $lines )
                : __( 'Nothing to fill: every dropdown already has options, or no source (ACF choices, CRM values, built-in list) has any.', 'my-iapsnj' ),
        ] );
    }

    public function ajax_bulk_sync(): void {
        $this->ajax_guard();
        $per_page = min( 200, max( 1, (int) ( $_POST['per_page'] ?? 100 ) ) ); // phpcs:ignore
        $offset   = max( 0, (int) ( $_POST['offset'] ?? 0 ) );                // phpcs:ignore
        $total    = max( 0, (int) ( $_POST['total'] ?? 0 ) );                 // phpcs:ignore
        wp_send_json_success( self::run_bulk_mirror( $per_page, $offset, [], $total ) );
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
        $result = My_IAPSNJ_Checks::mark_paid(
            $order_ids,
            sanitize_text_field( wp_unslash( $_POST['deposit_date'] ?? '' ) ), // phpcs:ignore
            $numbers,
            sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) // phpcs:ignore
        );
        My_IAPSNJ_Reports::flush_summary();
        wp_send_json_success( $result );
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
        My_IAPSNJ_Reports::flush_summary();
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
                'label'       => (string) ( $cfg['label'] ?? '' ),
                'enabled'     => ! empty( $cfg['enabled'] ),
                'member_type' => (string) ( $cfg['member_type'] ?? '' ),
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

    public function ajax_run_expiry(): void {
        $this->ajax_guard();
        if ( ! My_IAPSNJ_Membership::is_available() ) {
            wp_send_json_error( [ 'message' => __( 'FluentCart is not active.', 'my-iapsnj' ) ] );
        }
        $dry = ! empty( $_POST['dry'] ); // phpcs:ignore
        // Apply runs in batches (the page loops until nothing is left), so a
        // large backlog cannot hit max_execution_time; Preview changes nothing.
        $limit = $dry ? 0 : min( 500, max( 1, (int) ( $_POST['limit'] ?? 200 ) ) ); // phpcs:ignore
        try {
            $report = My_IAPSNJ_Membership::run_expirations( $dry, $limit );
            if ( ! $dry ) {
                My_IAPSNJ_Reports::flush_summary();
            }
            wp_send_json_success( $report );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    /**
     * Configurations → Email design → Send test: the sample email to the
     * current admin, with the saved settings.
     */
    public function ajax_send_test_email(): void {
        $this->ajax_guard();
        $to = (string) wp_get_current_user()->user_email;
        if ( ! is_email( $to ) ) {
            wp_send_json_error( [ 'message' => __( 'Your WordPress user has no valid email address.', 'my-iapsnj' ) ] );
        }
        if ( ! My_IAPSNJ_Emails::send_test( $to ) ) {
            wp_send_json_error( [ 'message' => __( 'WordPress could not send the email (see the mail log).', 'my-iapsnj' ) ] );
        }
        /* translators: %s: email address */
        wp_send_json_success( [ 'message' => sprintf( __( 'Test sent to %s. On staging, sending is simulated: open it in FluentSMTP → Email Logs.', 'my-iapsnj' ), $to ) ] );
    }

    public function ajax_normalize_phones(): void {
        $this->ajax_guard();
        $dry   = ! empty( $_POST['dry'] ); // phpcs:ignore
        $limit = min( 1000, max( 1, (int) ( $_POST['limit'] ?? 500 ) ) ); // phpcs:ignore
        try {
            wp_send_json_success( My_IAPSNJ_Phone::normalize_contacts( $dry, $limit ) + [ 'dry' => $dry ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    public function ajax_normalize_names(): void {
        $this->ajax_guard();
        $dry   = ! empty( $_POST['dry'] ); // phpcs:ignore
        $limit = min( 1000, max( 1, (int) ( $_POST['limit'] ?? 500 ) ) ); // phpcs:ignore
        try {
            wp_send_json_success( My_IAPSNJ_Capitalization::normalize_contacts( $dry, $limit ) + [ 'dry' => $dry ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
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
            'from_year'               => ( (int) ( $p['from_year'] ?? 0 ) ) ?: 2024,
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
