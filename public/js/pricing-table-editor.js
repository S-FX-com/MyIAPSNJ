/* My IAPSNJ – "Pricing Table" block editor (sfx/pricing-table). No build step. */
( function ( wp ) {
    'use strict';

    var el = wp.element.createElement, Fragment = wp.element.Fragment;
    var be = wp.blockEditor || wp.editor;
    var RichText = be.RichText, InspectorControls = be.InspectorControls, useBlockProps = be.useBlockProps;
    var PanelBody = wp.components.PanelBody, TextControl = wp.components.TextControl,
        ToggleControl = wp.components.ToggleControl, Button = wp.components.Button;

    function merge( a, b ) { return Object.assign( {}, a, b ); }
    function strip( s ) { return String( s || '' ).replace( /<[^>]*>/g, '' ); }
    function patchAt( list, k, patch ) { return list.map( function ( x, j ) { return j === k ? merge( x, patch ) : x; } ); }

    function rt( tag, cls, value, ph, onChange ) {
        return el( RichText, {
            tagName: tag, className: cls, value: value || '', placeholder: ph,
            allowedFormats: [], disableLineBreaks: true, onChange: onChange
        } );
    }

    function featurePreview( list, setList ) {
        var rows = list.map( function ( f, k ) {
            return el( 'li', { key: k, className: f.on ? 'is-on' : 'is-off' },
                rt( 'span', undefined, f.text, 'Feature', function ( v ) { setList( patchAt( list, k, { text: v } ) ); } ),
                el( 'span', { className: 'p29-feat-ctl', contentEditable: false },
                    el( Button, {
                        variant: 'tertiary', size: 'small',
                        onClick: function () { setList( patchAt( list, k, { on: ! f.on } ) ); }
                    }, f.on ? 'Exclude' : 'Include' ),
                    el( Button, {
                        variant: 'tertiary', size: 'small', isDestructive: true,
                        onClick: function () { setList( list.filter( function ( _, j ) { return j !== k; } ) ); }
                    }, 'Remove' ) ) );
        } );
        rows.push( el( 'li', { key: 'add', className: 'p29-edit-only' },
            el( Button, {
                variant: 'secondary', size: 'small',
                onClick: function () { setList( list.concat( [ { text: 'New feature', on: true } ] ) ); }
            }, '+ Add feature' ) ) );
        return el( 'ul', { className: 'p29-features' }, rows );
    }

    function featureControls( list, setList ) {
        return el( Fragment, null,
            list.map( function ( f, k ) {
                return el( 'div', { key: k, style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' } },
                    el( 'div', { style: { flex: 1 } }, el( ToggleControl, {
                        label: strip( f.text ) || '(empty)', checked: !! f.on, __nextHasNoMarginBottom: true,
                        onChange: function ( v ) { setList( patchAt( list, k, { on: v } ) ); }
                    } ) ),
                    el( Button, {
                        isDestructive: true, variant: 'tertiary', size: 'small',
                        onClick: function () { setList( list.filter( function ( _, j ) { return j !== k; } ) ); }
                    }, 'Remove' ) );
            } ),
            el( Button, {
                variant: 'secondary',
                onClick: function () { setList( list.concat( [ { text: 'New feature', on: true } ] ) ); }
            }, 'Add feature' ) );
    }

    wp.blocks.registerBlockType( 'sfx/pricing-table', {
        title: 'Pricing Table',
        category: 'widgets',
        icon: 'money-alt',
        edit: function ( props ) {
            var a = props.attributes, set = props.setAttributes;
            var blockProps = useBlockProps( { className: 'p29' } );

            function setPlan( i, patch ) { set( { plans: patchAt( a.plans, i, patch ) } ); }

            function addPlan() {
                var last = a.plans[ a.plans.length - 1 ] || {};
                var copy = merge( last, {
                    name: 'New plan', price: '$0', btnUrl: '', altText: '', altUrl: '',
                    features: ( last.features || [] ).map( function ( f ) { return merge( f, {} ); } )
                } );
                set( { plans: a.plans.concat( [ copy ] ) } );
            }

            var sidebar = el( InspectorControls, null,
                el( PanelBody, { title: 'Plans', initialOpen: true },
                    el( 'p', { style: { marginTop: 0 } }, a.plans.length + ' plan' + ( a.plans.length === 1 ? '' : 's' ) + '. Columns resize automatically.' ),
                    el( Button, { variant: 'primary', onClick: addPlan }, 'Add plan' ) ),
                a.plans.map( function ( plan, i ) {
                    return el( PanelBody, { key: i, title: 'Plan ' + ( i + 1 ) + ': ' + strip( plan.name ), initialOpen: false },
                        el( TextControl, { label: 'Button URL', value: plan.btnUrl || '', __nextHasNoMarginBottom: true, onChange: function ( v ) { setPlan( i, { btnUrl: v } ); } } ),
                        el( 'div', { style: { marginTop: '16px' } }, el( TextControl, {
                            label: 'Second option link (optional)', value: plan.altUrl || '', __nextHasNoMarginBottom: true,
                            help: 'The line under the button becomes a link to this address. Leave empty for plain text.',
                            onChange: function ( v ) { setPlan( i, { altUrl: v } ); }
                        } ) ),
                        el( 'p', { style: { marginTop: '16px', fontWeight: 600 } }, 'Features' ),
                        featureControls( plan.features, function ( l ) { setPlan( i, { features: l } ); } ),
                        el( 'div', { style: { marginTop: '16px' } }, el( Button, {
                            isDestructive: true, variant: 'secondary', disabled: a.plans.length <= 1,
                            onClick: function () { set( { plans: a.plans.filter( function ( _, j ) { return j !== i; } ) } ); }
                        }, 'Remove this plan' ) ) );
                } ) );

            function planCard( plan, i ) {
                return el( 'div', { className: 'p29__item', key: 'plan' + i },
                    el( 'article', { className: 'p29-card' },
                        el( 'div', { className: 'p29-card__top' },
                            el( 'div', { className: 'p29-card__top-inner' },
                                el( 'div', { className: 'p29-pill' }, rt( 'span', undefined, plan.pill, 'Tagline', function ( v ) { setPlan( i, { pill: v } ); } ) ),
                                rt( 'h3', 'p29-card__title', plan.name, 'Plan name', function ( v ) { setPlan( i, { name: v } ); } ),
                                rt( 'p', 'p29-card__desc', plan.desc, 'Description', function ( v ) { setPlan( i, { desc: v } ); } ),
                                el( 'p', { className: 'p29-price' },
                                    rt( 'span', 'p29-price__amount', plan.price, '$0', function ( v ) { setPlan( i, { price: v } ); } ),
                                    rt( 'span', 'p29-price__suffix', plan.suffix, '/year', function ( v ) { setPlan( i, { suffix: v } ); } ) ) ),
                            el( 'div', { className: 'wp-block-button p29-btn' },
                                el( 'div', { className: 'wp-block-button__link wp-element-button' },
                                    rt( 'span', undefined, plan.btnText, 'Button text', function ( v ) { setPlan( i, { btnText: v } ); } ) ) ),
                            rt( 'p', 'p29-alt', plan.altText, 'Optional second option, e.g. Prefer to pay once? $30 for one year', function ( v ) { setPlan( i, { altText: v } ); } ) ),
                        featurePreview( plan.features, function ( l ) { setPlan( i, { features: l } ); } ) ) );
            }

            return el( Fragment, null, sidebar,
                el( 'section', blockProps,
                    el( 'div', { className: 'p29__grid' }, a.plans.map( planCard ) ) ) );
        },
        save: function () { return null; }
    } );
}( window.wp ) );
