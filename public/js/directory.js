/* My IAPSNJ – [iapsnj_directory]: the filter form updates the list as the member types. */
( function () {
    'use strict';

    // Without these the plain GET form keeps working (a page load per change).
    if ( ! window.fetch || ! window.URLSearchParams || ! window.FormData ) {
        return;
    }

    var DEBOUNCE_MS = 250;
    var MIN_CHARS   = 2; // the same floor as My_IAPSNJ_Directory::SEARCH_MIN_CHARS

    function init( root ) {
        var form   = root.querySelector( '.my-iapsnj-dir-filters' );
        var count  = root.querySelector( '.my-iapsnj-dir-count' );
        var list   = root.querySelector( '.my-iapsnj-dir-list' );
        var reset  = root.querySelector( '.my-iapsnj-dir-reset' );
        var search = form ? form.querySelector( 'input[type="search"]' ) : null;
        if ( ! form || ! count || ! list || ! search ) {
            return;
        }

        var pageKey    = root.getAttribute( 'data-page-key' ) || 'mdir_page';
        var timer      = 0;
        var sequence   = 0;
        var controller = null;

        function isFilter( el ) {
            return el.name && el.name.indexOf( 'mdir_' ) === 0;
        }

        /** The name search as the server reads it: words only, nothing under two characters. */
        function searchText() {
            var text = search.value.replace( /[\s,]+/g, ' ' ).trim();
            return text.length < MIN_CHARS ? '' : text;
        }

        /** The filters that are set, as the query string of the page address. */
        function filterParams() {
            var params = new URLSearchParams();
            Array.prototype.forEach.call( form.elements, function ( el ) {
                if ( isFilter( el ) && el.value !== '' ) {
                    params.append( el.name, el === search ? searchText() : el.value );
                }
            } );
            if ( params.get( search.name ) === '' ) {
                params.delete( search.name );
            }
            return params;
        }

        /** Address of the page for these filters (what a reload or a bookmark would need). */
        function pageUrl( page ) {
            var params = filterParams();
            // Fields that are not ours (page_id on plain permalinks) stay in the address.
            Array.prototype.forEach.call( form.elements, function ( el ) {
                if ( el.name && ! isFilter( el ) && el.type === 'hidden' ) {
                    params.append( el.name, el.value );
                }
            } );
            if ( page > 1 ) {
                params.append( pageKey, String( page ) );
            }
            var query = params.toString();
            return window.location.pathname + ( query ? '?' + query : '' );
        }

        var applied = filterParams().toString();

        function update( page ) {
            window.clearTimeout( timer );
            if ( controller ) {
                controller.abort();
            }
            controller = window.AbortController ? new AbortController() : null;

            var mine = ++sequence;
            var body = filterParams();
            body.append( 'action', root.getAttribute( 'data-action' ) || '' );
            body.append( 'nonce', root.getAttribute( 'data-nonce' ) || '' );
            body.append( 'per_page', root.getAttribute( 'data-per-page' ) || '' );
            body.append( 'photo_meta', root.getAttribute( 'data-photo-meta' ) || '' );
            body.append( 'page_url', window.location.href );
            if ( page > 1 ) {
                body.append( pageKey, String( page ) );
            }
            applied = filterParams().toString();

            root.classList.add( 'is-loading' );
            list.setAttribute( 'aria-busy', 'true' );

            fetch( root.getAttribute( 'data-ajax-url' ), {
                method: 'POST',
                credentials: 'same-origin',
                body: body,
                signal: controller ? controller.signal : undefined
            } ).then( function ( response ) {
                return response.json();
            } ).then( function ( json ) {
                if ( mine !== sequence ) {
                    return; // a newer search is on its way
                }
                if ( ! json || ! json.success || ! json.data ) {
                    throw new Error( 'directory search failed' );
                }
                count.textContent = json.data.count;
                list.innerHTML    = json.data.html;
                if ( reset ) {
                    reset.hidden = applied === '';
                }
                try {
                    window.history.replaceState( null, '', pageUrl( page ) );
                } catch ( e ) {
                    // the address stays as it was; the list is still right
                }
                root.classList.remove( 'is-loading' );
                list.removeAttribute( 'aria-busy' );
                if ( page > 1 && root.getBoundingClientRect().top < 0 ) {
                    root.scrollIntoView( { block: 'start' } );
                }
            } ).catch( function ( err ) {
                if ( mine !== sequence || ( err && err.name === 'AbortError' ) ) {
                    return;
                }
                // The page may be stale (an expired nonce) or the network is down: load it the plain way.
                window.location.href = pageUrl( page );
            } );
        }

        function changed() {
            return filterParams().toString() !== applied;
        }

        search.addEventListener( 'input', function () {
            window.clearTimeout( timer );
            if ( changed() ) {
                timer = window.setTimeout( function () {
                    update( 1 );
                }, DEBOUNCE_MS );
            }
        } );

        // Dropdowns and dates apply at once (the search box has already, through input).
        form.addEventListener( 'change', function () {
            if ( changed() ) {
                update( 1 );
            }
        } );

        form.addEventListener( 'submit', function ( event ) {
            event.preventDefault();
            update( 1 );
        } );

        if ( reset ) {
            reset.addEventListener( 'click', function ( event ) {
                event.preventDefault();
                Array.prototype.forEach.call( form.elements, function ( el ) {
                    if ( isFilter( el ) ) {
                        el.value = '';
                    }
                } );
                update( 1 );
                search.focus();
            } );
        }

        // Paging stays on the page too (the links still work without scripts).
        list.addEventListener( 'click', function ( event ) {
            var link = event.target.closest ? event.target.closest( 'a.page-numbers' ) : null;
            if ( ! link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
                return;
            }
            var page = parseInt( new URL( link.href, window.location.href ).searchParams.get( pageKey ), 10 );
            event.preventDefault();
            update( page > 1 ? page : 1 );
        } );
    }

    Array.prototype.forEach.call( document.querySelectorAll( '.my-iapsnj-dir' ), init );
}() );
