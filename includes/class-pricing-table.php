<?php
/**
 * Pricing Table: the "Pricing Table" Gutenberg block (sfx/pricing-table) that
 * draws the Join page's plan cards. It replaces the site's Code Snippets
 * version, keeping the block name and every attribute, so a page that uses
 * the block keeps working without being re-saved.
 *
 * Equal-width plan columns; fonts and button shapes follow the theme, the
 * colours are variables at the top of public/css/pricing-table.css. A plan
 * can carry one optional "second option" line under its button (for example
 * "Prefer to pay once? $30 for one year, no auto-renew" linking to the
 * one-time membership). When any plan has such a line the other plans get an
 * empty line of the same height, so buttons and feature lists stay level.
 *
 * Dynamic block: the markup is built here on every request, so changes apply
 * site-wide without re-saving pages. No build step: the editor script is
 * plain JavaScript (public/js/pricing-table-editor.js).
 *
 * While the old snippet is still active it has already registered the block
 * and this class steps aside; deactivate the snippet to switch over.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Pricing_Table {

    const BLOCK  = 'sfx/pricing-table';
    const STYLE  = 'my-iapsnj-pricing-table';
    const EDITOR = 'my-iapsnj-pricing-table-editor';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // After the default priority, so a snippet that registered the
        // block first is seen and left alone.
        add_action( 'init', [ $this, 'register_block' ], 20 );
    }

    /** Text from the editor: the same inline HTML WordPress allows in posts. */
    private static function text( $value ): string {
        return wp_kses_post( (string) $value );
    }

    /**
     * Plan fields and the plans a new block starts with.
     *
     * @return array{plans:array<int,array>}
     */
    public static function defaults(): array {
        $base = [
            'pill'     => '3 products available',
            'desc'     => 'A short description of this plan and who it is for.',
            'suffix'   => '/year',
            'btnText'  => 'Buy now',
            'btnUrl'   => '',
            'altText'  => '',
            'altUrl'   => '',
            'features' => [
                [ 'text' => 'Feature one', 'on' => true ],
                [ 'text' => 'Feature two', 'on' => true ],
                [ 'text' => 'Feature three', 'on' => true ],
                [ 'text' => 'Feature four', 'on' => false ],
                [ 'text' => 'Feature five', 'on' => false ],
            ],
        ];
        return [
            'plans' => [
                array_merge( $base, [ 'name' => 'Starter', 'price' => '$29' ] ),
                array_merge( $base, [ 'name' => 'Growth', 'price' => '$129' ] ),
                array_merge( $base, [ 'name' => 'Pro', 'price' => '$299' ] ),
            ],
        ];
    }

    public function register_block(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
            return; // the Code Snippets version is still active
        }

        wp_register_style( self::STYLE, MY_IAPSNJ_URL . 'public/css/pricing-table.css', [], MY_IAPSNJ_VERSION );
        wp_register_script(
            self::EDITOR,
            MY_IAPSNJ_URL . 'public/js/pricing-table-editor.js',
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ],
            MY_IAPSNJ_VERSION,
            true
        );

        register_block_type( self::BLOCK, [
            'api_version'     => 3,
            'title'           => 'Pricing Table',
            'category'        => 'widgets',
            'icon'            => 'money-alt',
            'description'     => 'Equal-width pricing columns that inherit your theme styling.',
            'supports'        => [
                'align'   => [ 'wide', 'full' ],
                'anchor'  => true,
                'html'    => false,
                'spacing' => [ 'margin' => true, 'padding' => true ],
            ],
            'attributes'      => [
                'plans' => [ 'type' => 'array', 'default' => self::defaults()['plans'] ],
            ],
            'style'           => self::STYLE,
            'editor_script'   => self::EDITOR,
            'render_callback' => [ __CLASS__, 'render' ],
        ] );
    }

    /**
     * @param array<string,mixed> $attrs
     */
    public static function render( $attrs ): string {
        $a     = wp_parse_args( (array) $attrs, self::defaults() );
        $plans = (array) $a['plans'];

        // Is there a second-option line on any plan? Then every plan gets one.
        $any_alt = false;
        foreach ( $plans as $p ) {
            if ( trim( (string) ( $p['altText'] ?? '' ) ) !== '' ) {
                $any_alt = true;
                break;
            }
        }

        $html  = '<section ' . get_block_wrapper_attributes( [ 'class' => 'p29' ] ) . '>';
        $html .= '<div class="p29__grid">';
        foreach ( $plans as $p ) {
            $url   = ! empty( $p['btnUrl'] ) ? esc_url( (string) $p['btnUrl'] ) : '#';
            $html .= '<div class="p29__item"><article class="p29-card"><div class="p29-card__top"><div class="p29-card__top-inner">';
            $html .= '<div class="p29-pill"><span>' . self::text( $p['pill'] ?? '' ) . '</span></div>';
            $html .= '<h3 class="p29-card__title">' . self::text( $p['name'] ?? '' ) . '</h3>';
            $html .= '<p class="p29-card__desc">' . self::text( $p['desc'] ?? '' ) . '</p>';
            $html .= '<p class="p29-price"><span class="p29-price__amount">' . self::text( $p['price'] ?? '' ) . '</span><span class="p29-price__suffix">' . self::text( $p['suffix'] ?? '' ) . '</span></p>';
            $html .= '</div><div class="wp-block-button p29-btn"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . self::text( $p['btnText'] ?? '' ) . '</a></div>';
            if ( $any_alt ) {
                $html .= self::alt_line( (string) ( $p['altText'] ?? '' ), (string) ( $p['altUrl'] ?? '' ) );
            }
            $html .= '</div>' . self::features( $p['features'] ?? [] ) . '</article></div>';
        }
        return $html . '</div></section>';
    }

    /** The optional line under the button; empty (but present) when the plan has none. */
    private static function alt_line( string $text, string $url ): string {
        $text = trim( $text );
        if ( $text === '' ) {
            return '<p class="p29-alt" aria-hidden="true"></p>';
        }
        $inner = self::text( $text );
        if ( trim( $url ) !== '' ) {
            $inner = '<a href="' . esc_url( $url ) . '">' . $inner . '</a>';
        }
        return '<p class="p29-alt">' . $inner . '</p>';
    }

    /**
     * @param mixed $features list of [ 'text' => string, 'on' => bool ]
     */
    private static function features( $features ): string {
        $out = '<ul class="p29-features">';
        foreach ( (array) $features as $f ) {
            $on   = ! empty( $f['on'] );
            $out .= '<li class="' . ( $on ? 'is-on' : 'is-off' ) . '"><span>' . self::text( $f['text'] ?? '' ) . '</span></li>';
        }
        return $out . '</ul>';
    }
}
