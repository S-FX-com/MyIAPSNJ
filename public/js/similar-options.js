/* My IAPSNJ – "Did you mean …?" for an answer typed under "Other (not listed)".
   The same rules as My_IAPSNJ_Similar (PHP), which checks again on submit. */
( function () {
    'use strict';

    var C = window.myIapsnjSimilar;
    if ( ! C ) {
        return;
    }

    function normalize( s ) {
        return String( s || '' ).toLowerCase().replace( /[.'’`]/g, '' ).replace( /[^\p{L}\p{N}]+/gu, ' ' ).trim();
    }

    function core( s ) {
        return normalize( s ).split( ' ' ).filter( function ( w ) {
            return w !== '' && C.stop.indexOf( w ) < 0;
        } ).join( ' ' );
    }

    // The characters of a string (code points, like the PHP side counts them).
    function chars( s ) {
        return Array.from( s );
    }

    // Edits (insert, delete, change, or swap two neighbours), counted in characters.
    function distance( a, b ) {
        var x = chars( a ), y = chars( b ), d = [], i, j, cost;
        for ( i = 0; i <= x.length; i++ ) {
            d[ i ] = [ i ];
        }
        for ( j = 0; j <= y.length; j++ ) {
            d[ 0 ][ j ] = j;
        }
        for ( i = 1; i <= x.length; i++ ) {
            for ( j = 1; j <= y.length; j++ ) {
                cost = x[ i - 1 ] === y[ j - 1 ] ? 0 : 1;
                d[ i ][ j ] = Math.min( d[ i - 1 ][ j ] + 1, d[ i ][ j - 1 ] + 1, d[ i - 1 ][ j - 1 ] + cost );
                if ( i > 1 && j > 1 && x[ i - 1 ] === y[ j - 2 ] && x[ i - 2 ] === y[ j - 1 ] ) {
                    d[ i ][ j ] = Math.min( d[ i ][ j ], d[ i - 2 ][ j - 2 ] + 1 );
                }
            }
        }
        return d[ x.length ][ y.length ];
    }

    function score( a, b ) {
        var na = normalize( a ), nb = normalize( b );
        if ( ! na || ! nb ) {
            return 0;
        }
        if ( na === nb ) {
            return 1;
        }
        var ca = core( a ) || na, cb = core( b ) || nb;
        if ( ca === cb ) {
            return 0.95;
        }
        var la = chars( ca ).length, lb = chars( cb ).length;
        var short = la <= lb ? ca : cb;
        var long = short === ca ? cb : ca;
        if ( Math.min( la, lb ) >= C.minLength && ( ' ' + long + ' ' ).indexOf( ' ' + short + ' ' ) >= 0 ) {
            return 0.85;
        }
        var max = Math.max( la, lb );
        if ( max >= C.minLength && max <= 255 ) {
            return 1 - distance( ca, cb ) / max;
        }
        return 0;
    }

    function matches( typed, options ) {
        var found = [];
        options.forEach( function ( o ) {
            var s = score( typed, o );
            if ( s >= C.threshold ) {
                found.push( { option: o, score: s } );
            }
        } );
        found.sort( function ( x, y ) {
            return y.score - x.score;
        } );
        return found.slice( 0, C.max ).map( function ( f ) {
            return f.option;
        } );
    }

    // ---- The proposal box under the typed answer ---------------------------
    function parts( name ) {
        return {
            select: document.querySelector( 'select[name="' + name + '"]' ),
            text: document.querySelector( 'input[name="' + name + '__other"]' ),
            confirm: document.querySelector( 'input[name="' + name + '__confirm"]' ),
            box: document.querySelector( '[data-my-iapsnj-suggest-for="' + name + '"]' )
        };
    }

    function hide( p ) {
        if ( p.box ) {
            p.box.hidden = true;
            p.box.textContent = '';
            p.box.removeAttribute( 'data-key' );
        }
    }

    function button( label, cls, onClick ) {
        var b = document.createElement( 'button' );
        b.type = 'button';
        b.className = cls || '';
        b.textContent = label;
        b.addEventListener( 'click', onClick );
        return b;
    }

    function fire( el, type ) {
        el.dispatchEvent( new Event( type, { bubbles: true } ) );
    }

    function check( name ) {
        var p = parts( name );
        if ( ! p.select || ! p.text || ! p.box ) {
            return;
        }
        var typed = p.text.value.trim();
        var options = Array.prototype.map.call( p.select.options, function ( o ) {
            return o.value;
        } ).filter( function ( v ) {
            return v !== '' && v.indexOf( '__' ) !== 0;
        } );
        // Nothing to propose: not typing, already confirmed, or nothing alike.
        if ( p.select.value.indexOf( '__' ) !== 0 || typed === '' || ( p.confirm && normalize( p.confirm.value ) === normalize( typed ) ) ) {
            hide( p );
            return;
        }
        var found = matches( typed, options );
        if ( ! found.length ) {
            hide( p );
            return;
        }
        // The same proposal is already showing (clicking a button blurs the text
        // box, which checks again): leave it, or the click would land on nothing.
        var key = typed + '|' + found.join( '|' );
        if ( ! p.box.hidden && p.box.getAttribute( 'data-key' ) === key ) {
            return;
        }
        p.box.setAttribute( 'data-key', key );
        p.box.textContent = '';
        var title = document.createElement( 'span' );
        title.className = 'my-iapsnj-suggest-title';
        title.textContent = C.i18n.did;
        p.box.appendChild( title );
        var row = document.createElement( 'span' );
        row.className = 'my-iapsnj-suggest-buttons';
        found.forEach( function ( option ) {
            row.appendChild( button( C.i18n.use.replace( '%s', option ), '', function () {
                p.select.value = option;
                if ( p.confirm ) {
                    p.confirm.value = '';
                }
                p.text.value = '';
                hide( p );
                fire( p.select, 'change' ); // hides the typed box and lets the form save the choice
                p.select.focus();
            } ) );
        } );
        row.appendChild( button( C.i18n.different, 'my-iapsnj-suggest-keep', function () {
            if ( p.confirm ) {
                p.confirm.value = typed;
            }
            p.box.removeAttribute( 'data-key' );
            p.box.textContent = C.i18n.kept;
        } ) );
        p.box.appendChild( row );
        p.box.hidden = false;
    }

    function nameOf( el ) {
        return el && el.name && /__other$/.test( el.name ) ? el.name.replace( /__other$/, '' ) : '';
    }

    var timers = {};
    function on( e ) {
        var name = nameOf( e.target );
        if ( ! name ) {
            return;
        }
        var p = parts( name );
        if ( e.type === 'input' ) {
            // Editing the text withdraws an earlier "mine is different".
            if ( p.confirm && normalize( p.confirm.value ) !== normalize( p.text.value ) ) {
                p.confirm.value = '';
            }
            clearTimeout( timers[ name ] );
            timers[ name ] = setTimeout( function () {
                check( name );
            }, 500 );
        } else {
            clearTimeout( timers[ name ] );
            check( name );
        }
    }
    document.addEventListener( 'input', on, true );
    document.addEventListener( 'change', on, true );
    document.addEventListener( 'focusout', on, true );

    window.myIapsnjSimilarApi = { score: score, matches: matches, normalize: normalize };
}() );
