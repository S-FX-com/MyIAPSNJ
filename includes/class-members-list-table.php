<?php
/**
 * My_IAPSNJ_Members_List_Table
 *
 * WP_List_Table for the Active Membership / Lapsed Members screens. Data
 * comes from My_IAPSNJ_Members::query(); this class only renders views,
 * filters, columns and paging. Read-only: no bulk actions.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class My_IAPSNJ_Members_List_Table extends WP_List_Table {

    /** @var string My_IAPSNJ_Members::STATE_* */
    private string $state;

    /** @var array normalised list arguments */
    private array $args;

    /** @var array counts, years, export_url, base_url, view_urls, next_year */
    private array $ctx;

    /**
     * @param array $args normalised arguments (see My_IAPSNJ_Members::query())
     * @param array $ctx  counts, years, export_url, base_url, view_urls, next_year
     */
    public function __construct( string $state, array $args, array $ctx ) {
        $this->state = $state === My_IAPSNJ_Members::STATE_LAPSED ? My_IAPSNJ_Members::STATE_LAPSED : My_IAPSNJ_Members::STATE_ACTIVE;
        $this->args  = $args;
        $this->ctx   = $ctx;
        parent::__construct(
            [
                'singular' => 'member',
                'plural'   => 'members',
                'ajax'     => false,
                // The admin page's own screen when there is one (so the
                // views_{screen id} filter uses the real id).
                'screen'   => ( function_exists( 'get_current_screen' ) && get_current_screen() ) ? get_current_screen() : My_IAPSNJ_Members::page_slug( $this->state ),
            ]
        );
    }

    public function prepare_items(): void {
        $result      = My_IAPSNJ_Members::query( $this->state, $this->args );
        $this->items = $result['rows'];
        $per_page    = (int) $this->args['per_page'];
        $this->set_pagination_args(
            [
                'total_items' => (int) $result['total'],
                'per_page'    => $per_page,
                'total_pages' => max( 1, (int) ceil( (int) $result['total'] / max( 1, $per_page ) ) ),
            ]
        );
        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'name' ];
    }

    public function get_columns(): array {
        $cols = [
            'name'         => esc_html__( 'Name', 'my-iapsnj' ),
            'email'        => esc_html__( 'Email', 'my-iapsnj' ),
            'member_type'  => esc_html__( 'Member type', 'my-iapsnj' ),
            'paid_through' => esc_html__( 'Paid through', 'my-iapsnj' ),
        ];
        if ( $this->state === My_IAPSNJ_Members::STATE_LAPSED ) {
            $cols['lapsed_on'] = esc_html__( 'Lapsed on', 'my-iapsnj' );
        }
        return array_merge(
            $cols,
            [
                'member_number'  => esc_html__( 'Member #', 'my-iapsnj' ),
                'department'     => esc_html__( 'Department', 'my-iapsnj' ),
                'last_paid_year' => esc_html__( 'Last paid year', 'my-iapsnj' ),
                'last_order'     => esc_html__( 'Last order', 'my-iapsnj' ),
                'flags'          => esc_html__( 'Flags', 'my-iapsnj' ),
            ]
        );
    }

    protected function get_sortable_columns(): array {
        $lapsed = $this->state === My_IAPSNJ_Members::STATE_LAPSED;
        // [ orderby, desc first, abbr, orderby text, initial order ]
        return [
            'name'         => [ 'name', false, '', '', $lapsed ? '' : 'asc' ],
            'member_type'  => [ 'member_type', false ],
            'paid_through' => [ 'paid_through', $lapsed, '', '', $lapsed ? 'desc' : '' ],
        ];
    }

    protected function get_primary_column_name(): string {
        return 'name';
    }

    public function no_items(): void {
        if ( $this->args['s'] !== '' ) {
            esc_html_e( 'No members match the search.', 'my-iapsnj' );
        } elseif ( $this->state === My_IAPSNJ_Members::STATE_LAPSED ) {
            esc_html_e( 'No lapsed members found.', 'my-iapsnj' );
        } else {
            esc_html_e( 'No active members found.', 'my-iapsnj' );
        }
    }

    // -----------------------------------------------------------------------
    // Views (member type) and filters
    // -----------------------------------------------------------------------

    protected function get_views(): array {
        $counts  = (array) ( $this->ctx['counts'] ?? [] );
        $urls    = (array) ( $this->ctx['view_urls'] ?? [] );
        $key     = $this->state === My_IAPSNJ_Members::STATE_LAPSED ? 'lapsed' : 'active';
        $current = (string) $this->args['type'];
        $views   = [];
        $types   = array_merge( [ '' ], My_IAPSNJ_Schema::member_types(), [ My_IAPSNJ_Members::TYPE_OTHER ] );
        foreach ( $types as $type ) {
            $n = $type === ''
                ? (int) ( $counts['_total'][ $key ] ?? 0 )
                : (int) ( $counts[ $type ][ $key ] ?? 0 );
            if ( $type !== '' && $n === 0 && $type !== $current ) {
                continue;
            }
            $label  = $type === '' ? __( 'All', 'my-iapsnj' ) : ( $type === My_IAPSNJ_Members::TYPE_OTHER ? __( 'Other types', 'my-iapsnj' ) : $type );
            $is_cur = $type === $current && $this->args['check'] === '';
            $views[ $type === '' ? 'all' : sanitize_key( $type ) ] = sprintf(
                '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
                esc_url( (string) ( $urls[ $type ] ?? '' ) ),
                $is_cur ? ' class="current" aria-current="page"' : '',
                esc_html( $label ),
                esc_html( number_format_i18n( $n ) )
            );
        }
        return $views;
    }

    /**
     * Tablenav without the bulk-action nonce (the list is a GET form and
     * read-only), so bookmarked URLs stay clean.
     *
     * @param string $which top | bottom
     */
    protected function display_tablenav( $which ) {
        if ( 'bottom' === $which && ! $this->has_items() ) {
            return;
        }
        echo '<div class="tablenav ' . esc_attr( $which ) . '">';
        $this->extra_tablenav( $which );
        $this->pagination( $which );
        echo '<br class="clear" /></div>';
    }

    /**
     * @param string $which top | bottom
     */
    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }
        $a = $this->args;
        echo '<div class="alignleft actions">';
        if ( $this->state === My_IAPSNJ_Members::STATE_ACTIVE ) {
            $this->select(
                'due',
                __( 'Renewal', 'my-iapsnj' ),
                [
                    ''  => __( 'All renewal states', 'my-iapsnj' ),
                    /* translators: %d: next calendar year */
                    '1' => sprintf( __( 'Renewal due for %d', 'my-iapsnj' ), (int) ( $this->ctx['next_year'] ?? 0 ) ),
                ],
                (string) $a['due']
            );
            $within = [ '' => __( 'Any lapse date', 'my-iapsnj' ) ];
            foreach ( [ 30, 60, 90, 180, 365 ] as $d ) {
                /* translators: %d: number of days */
                $within[ (string) $d ] = sprintf( __( 'Lapses within %d days', 'my-iapsnj' ), $d );
            }
            if ( $a['within'] > 0 && ! isset( $within[ (string) $a['within'] ] ) ) {
                /* translators: %d: number of days */
                $within[ (string) $a['within'] ] = sprintf( __( 'Lapses within %d days', 'my-iapsnj' ), (int) $a['within'] );
            }
            $this->select( 'within', __( 'Lapses within', 'my-iapsnj' ), $within, $a['within'] > 0 ? (string) $a['within'] : '' );
            $this->select(
                'comped',
                __( 'Dues', 'my-iapsnj' ),
                [
                    ''  => __( 'Paying and comped', 'my-iapsnj' ),
                    '0' => __( 'Paying only', 'my-iapsnj' ),
                    '1' => __( 'Comped only (Lifetime / Honorary)', 'my-iapsnj' ),
                ],
                (string) $a['comped']
            );
        } else {
            $years = [ '' => __( 'Any paid-through year', 'my-iapsnj' ) ];
            foreach ( (array) ( $this->ctx['years'] ?? [] ) as $y => $n ) {
                $years[ (string) $y ] = (string) $y === 'none'
                    /* translators: %s: number of members */
                    ? sprintf( __( 'No paid-through date (%s)', 'my-iapsnj' ), number_format_i18n( (int) $n ) )
                    /* translators: 1: year, 2: number of members */
                    : sprintf( __( 'Paid through %1$s (%2$s)', 'my-iapsnj' ), (string) $y, number_format_i18n( (int) $n ) );
            }
            if ( $a['year'] !== '' && ! isset( $years[ $a['year'] ] ) ) {
                /* translators: %s: year */
                $years[ $a['year'] ] = sprintf( __( 'Paid through %s', 'my-iapsnj' ), $a['year'] );
            }
            $this->select( 'year', __( 'Paid-through year', 'my-iapsnj' ), $years, (string) $a['year'] );
            $since = [ '' => __( 'Any time', 'my-iapsnj' ) ];
            foreach ( [ 30, 90, 180, 365, 730 ] as $d ) {
                /* translators: %d: number of days */
                $since[ (string) $d ] = sprintf( __( 'Lapsed in the last %d days', 'my-iapsnj' ), $d );
            }
            if ( $a['since'] > 0 && ! isset( $since[ (string) $a['since'] ] ) ) {
                /* translators: %d: number of days */
                $since[ (string) $a['since'] ] = sprintf( __( 'Lapsed in the last %d days', 'my-iapsnj' ), (int) $a['since'] );
            }
            $this->select( 'since', __( 'Lapsed since', 'my-iapsnj' ), $since, $a['since'] > 0 ? (string) $a['since'] : '' );
        }
        $pp = [];
        foreach ( My_IAPSNJ_Members::PER_PAGE_CHOICES as $n ) {
            /* translators: %d: rows per page */
            $pp[ (string) $n ] = sprintf( __( '%d per page', 'my-iapsnj' ), $n );
        }
        $this->select( 'per_page', __( 'Rows per page', 'my-iapsnj' ), $pp, (string) $a['per_page'] );
        submit_button( __( 'Filter', 'my-iapsnj' ), '', 'filter_action', false, [ 'id' => 'my-iapsnj-members-filter' ] );

        $filtered = $a['type'] !== '' || $a['s'] !== '' || $a['check'] !== '' || $a['due'] !== '' || $a['within'] > 0
            || $a['comped'] !== '' || $a['year'] !== '' || $a['since'] > 0;
        if ( $filtered ) {
            echo ' <a class="button-link" href="' . esc_url( (string) ( $this->ctx['base_url'] ?? '' ) ) . '">' . esc_html__( 'Clear filters', 'my-iapsnj' ) . '</a>';
        }
        echo '</div>';
        echo '<div class="alignleft actions"><a class="button" href="' . esc_url( (string) ( $this->ctx['export_url'] ?? '' ) ) . '">' . esc_html__( 'Export CSV', 'my-iapsnj' ) . '</a></div>';
    }

    /**
     * @param array<string,string> $options value => label
     */
    private function select( string $name, string $label, array $options, string $current ): void {
        $id = 'my-iapsnj-members-' . $name;
        echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
        foreach ( $options as $value => $text ) {
            echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( $text ) . '</option>';
        }
        echo '</select> ';
    }

    // -----------------------------------------------------------------------
    // Columns
    // -----------------------------------------------------------------------

    /**
     * @param array $item
     */
    protected function column_name( $item ): string {
        $name = trim( $item['first_name'] . ' ' . $item['last_name'] );
        if ( $name === '' ) {
            $name = __( '(no name)', 'my-iapsnj' );
        }
        return '<strong><a href="' . esc_url( $item['crm_url'] ) . '">' . esc_html( $name ) . '</a></strong>';
    }

    /**
     * Row actions under the name: CRM contact / WordPress user / Last order.
     *
     * @param array  $item
     * @param string $column_name
     * @param string $primary
     */
    protected function handle_row_actions( $item, $column_name, $primary ) {
        if ( $column_name !== $primary ) {
            return '';
        }
        $actions = [
            'crm' => '<a href="' . esc_url( $item['crm_url'] ) . '">' . esc_html__( 'CRM contact', 'my-iapsnj' ) . '</a>',
        ];
        if ( ! empty( $item['user_id'] ) ) {
            $actions['wp_user'] = '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $item['user_id'] ) ) . '">' . esc_html__( 'WordPress user', 'my-iapsnj' ) . '</a>';
        }
        if ( is_array( $item['last_order'] ) ) {
            $actions['order'] = '<a href="' . esc_url( $item['last_order']['url'] ) . '">' . esc_html__( 'Last order', 'my-iapsnj' ) . '</a>';
        }
        return $this->row_actions( $actions );
    }

    /**
     * @param array $item
     */
    protected function column_email( $item ): string {
        $email = (string) $item['email'];
        return $email !== '' ? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' : '';
    }

    /**
     * @param array $item
     */
    protected function column_paid_through( $item ): string {
        $pt = (string) $item['paid_through'];
        if ( ! empty( $item['is_comped'] ) ) {
            return esc_html__( 'No expiration', 'my-iapsnj' );
        }
        if ( $pt === '' ) {
            return '<em>' . esc_html__( 'No date', 'my-iapsnj' ) . '</em>';
        }
        if ( empty( $item['paid_through_valid'] ) ) {
            /* translators: %s: stored paid_through value */
            return esc_html( sprintf( __( '%s (not YYYY-MM-DD)', 'my-iapsnj' ), $pt ) );
        }
        return esc_html( My_IAPSNJ_Dates::ymd_display( $pt ) );
    }

    /**
     * @param array $item
     */
    protected function column_lapsed_on( $item ): string {
        return $item['lapsed_on'] !== '' ? esc_html( My_IAPSNJ_Dates::ymd_display( $item['lapsed_on'] ) ) : '&mdash;';
    }

    /**
     * @param array $item
     */
    protected function column_last_paid_year( $item ): string {
        return $item['last_paid_year'] ? esc_html( (string) $item['last_paid_year'] ) : '&mdash;';
    }

    /**
     * @param array $item
     */
    protected function column_last_order( $item ): string {
        $o = $item['last_order'];
        if ( ! is_array( $o ) ) {
            return '&mdash;';
        }
        $parts = array_filter( [
            '#' . (int) $o['id'],
            My_IAPSNJ_Dates::mysql_utc_display( $o['date'] ),
            (string) $o['amount'],
            (string) $o['method'],
        ] );
        return '<a href="' . esc_url( $o['url'] ) . '">' . esc_html( implode( ' · ', $parts ) ) . '</a>';
    }

    /**
     * @param array $item
     */
    protected function column_flags( $item ): string {
        $flags = [];
        if ( ! empty( $item['pending_check'] ) ) {
            $flags[] = __( 'Check pending', 'my-iapsnj' );
        }
        if ( is_array( $item['subscription'] ) ) {
            $next   = My_IAPSNJ_Dates::mysql_utc_display( $item['subscription']['next_billing_date'] );
            $status = (string) $item['subscription']['status'];
            if ( in_array( $status, [ 'active', 'trialing' ], true ) ) {
                $flags[] = $next !== ''
                    /* translators: %s: next billing date */
                    ? sprintf( __( 'Subscription active (next %s)', 'my-iapsnj' ), $next )
                    : __( 'Subscription active', 'my-iapsnj' );
            } else {
                /* translators: %s: FluentCart subscription status */
                $flags[] = sprintf( __( 'Subscription %s', 'my-iapsnj' ), str_replace( '_', ' ', $status ) );
            }
        }
        $active = $item['state'] === My_IAPSNJ_Members::STATE_ACTIVE;
        if ( $active !== ! empty( $item['has_active_tag'] ) ) {
            $flags[] = $active
                ? __( 'Tag out of sync (Member-Active missing)', 'my-iapsnj' )
                : __( 'Tag out of sync (still Member-Active)', 'my-iapsnj' );
        }
        if ( in_array( $item['crm_status'], My_IAPSNJ_Members::CRM_STATUS_FLAGS, true ) ) {
            /* translators: %s: FluentCRM contact status */
            $flags[] = sprintf( __( 'CRM status: %s', 'my-iapsnj' ), $item['crm_status'] );
        }
        return implode( '<br />', array_map( 'esc_html', $flags ) );
    }

    /**
     * member_type, member_number, department.
     *
     * @param array  $item
     * @param string $column_name
     */
    protected function column_default( $item, $column_name ) {
        $value = isset( $item[ $column_name ] ) && is_scalar( $item[ $column_name ] ) ? (string) $item[ $column_name ] : '';
        return $value !== '' ? esc_html( $value ) : '&mdash;';
    }
}
