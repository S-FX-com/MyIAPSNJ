/* My IAPSNJ – [iapsnj_edit_profile]: conditional fields, "Other (not listed)", save. */
( function () {
    'use strict';

    var cfg = window.myIapsnjEditProfile;
    var form = document.querySelector( '.my-iapsnj-ep-form' );
    if ( ! cfg || ! form ) {
        return;
    }

    var message = form.querySelector( '.my-iapsnj-ep-message' );
    var submit  = form.querySelector( '.my-iapsnj-ep-submit' );
    var P = cfg.prefix;
    var O = cfg.otherValue;

    // ---- Photo: preview what was chosen, check it before it is sent --------
    var photoInput   = form.querySelector( 'input[name="iapsnj_photo"]' );
    var photoRemove  = form.querySelector( 'input[name="iapsnj_photo_remove"]' );
    var photoRemoveW = form.querySelector( '.my-iapsnj-ep-photo-remove' );
    var photoBox     = form.querySelector( '.my-iapsnj-ep-photo-preview' );
    var savedPhoto   = photoBox ? ( photoBox.querySelector( 'img' ) ? photoBox.querySelector( 'img' ).getAttribute( 'src' ) : '' ) : '';
    var objectUrl    = '';
    var TYPES        = [ 'image/jpeg', 'image/png', 'image/webp' ];

    function setPreview( url ) {
        if ( ! photoBox ) {
            return;
        }
        var img = photoBox.querySelector( 'img' );
        if ( ! url ) {
            if ( img ) {
                img.remove();
            }
            return;
        }
        if ( ! img ) {
            img = document.createElement( 'img' );
            img.className = 'my-iapsnj-ep-photo-img';
            img.width = 96;
            img.height = 96;
            img.alt = '';
            // A photo that fails to load falls back to the silhouette under it.
            img.onerror = function () { img.style.display = 'none'; };
            photoBox.appendChild( img );
        }
        img.style.display = '';
        img.setAttribute( 'src', url );
    }

    function photoProblem( file ) {
        if ( TYPES.indexOf( file.type ) < 0 ) {
            return cfg.i18n.photoType;
        }
        if ( cfg.maxBytes && file.size > cfg.maxBytes ) {
            return cfg.i18n.photoSize;
        }
        return '';
    }

    if ( photoInput ) {
        photoInput.addEventListener( 'change', function () {
            clearErrors();
            if ( objectUrl ) {
                URL.revokeObjectURL( objectUrl );
                objectUrl = '';
            }
            var file = photoInput.files && photoInput.files[ 0 ];
            if ( ! file ) {
                setPreview( photoRemove && photoRemove.checked ? '' : savedPhoto );
                return;
            }
            var problem = photoProblem( file );
            if ( problem ) {
                photoInput.value = '';
                setPreview( savedPhoto );
                showErrors( { iapsnj_photo: problem } );
                return;
            }
            if ( photoRemove ) {
                photoRemove.checked = false;
            }
            objectUrl = URL.createObjectURL( file );
            setPreview( objectUrl );
        } );
    }
    if ( photoRemove ) {
        photoRemove.addEventListener( 'change', function () {
            if ( photoRemove.checked && photoInput ) {
                photoInput.value = '';
            }
            setPreview( photoRemove.checked ? '' : savedPhoto );
        } );
    }

    function val( name ) {
        var v = '';
        form.querySelectorAll( '[name="' + name + '"]' ).forEach( function ( i ) {
            if ( i.type === 'checkbox' ) {
                if ( i.checked ) {
                    v = 'yes';
                }
            } else if ( i.type === 'radio' ) {
                if ( i.checked ) {
                    v = i.value;
                }
            } else {
                v = String( i.value || '' ).trim();
            }
        } );
        return v;
    }

    /* Same rules as the checkout: a conditional field shows (and is submitted)
       only while its parent's answer matches; the text box under "Other" shows
       only while "Other" is picked. A hidden field is disabled, so it is not
       sent and the browser does not require it. */
    function update() {
        form.querySelectorAll( '[data-my-iapsnj-parent]' ).forEach( function ( w ) {
            var when = [];
            try {
                when = JSON.parse( w.getAttribute( 'data-my-iapsnj-show-when' ) || '[]' );
            } catch ( e ) { /* any answer */ }
            var v  = val( w.getAttribute( 'data-my-iapsnj-parent' ) );
            var on = v !== '' && ( ! when.length || when.indexOf( v ) > -1 );
            w.style.display = on ? '' : 'none';
            w.querySelectorAll( 'input,select,textarea' ).forEach( function ( i ) {
                i.disabled = ! on;
            } );
        } );
        form.querySelectorAll( '[data-my-iapsnj-other-for]' ).forEach( function ( w ) {
            var n    = w.getAttribute( 'data-my-iapsnj-other-for' );
            var s    = form.querySelector( 'select[name="' + n + '"]' );
            var on   = !! s && ! s.disabled && s.value === O;
            var link = form.querySelector( '[data-my-iapsnj-other-link="' + n + '"]' );
            w.style.display = on ? '' : 'none';
            w.querySelectorAll( 'input' ).forEach( function ( i ) {
                i.disabled = ! on;
            } );
            if ( link ) {
                link.style.display = on || ! s || s.disabled ? 'none' : '';
            }
        } );
        form.querySelectorAll( '[data-my-iapsnj-section]' ).forEach( function ( g ) {
            var any = false;
            g.querySelectorAll( '.my-iapsnj-field' ).forEach( function ( f ) {
                if ( f.style.display !== 'none' ) {
                    any = true;
                }
            } );
            g.style.display = any ? '' : 'none';
        } );
    }

    function clearErrors() {
        message.hidden = true;
        message.classList.remove( 'is-success' );
        form.querySelectorAll( '.my-iapsnj-ep-error' ).forEach( function ( e ) {
            e.remove();
        } );
        form.querySelectorAll( '[aria-invalid]' ).forEach( function ( i ) {
            i.removeAttribute( 'aria-invalid' );
        } );
    }

    function showMessage( text, success ) {
        message.textContent = text;
        message.classList.toggle( 'is-success', !! success );
        message.hidden = false;
        message.scrollIntoView( { behavior: 'smooth', block: 'center' } );
    }

    function showErrors( errors ) {
        var first = null;
        Object.keys( errors ).forEach( function ( name ) {
            var input = form.querySelector( '[name="' + name + '"]' );
            if ( ! input ) {
                return;
            }
            input.setAttribute( 'aria-invalid', 'true' );
            var note = document.createElement( 'p' );
            note.className = 'my-iapsnj-ep-error';
            note.textContent = errors[ name ];
            var wrap = input.closest( '.my-iapsnj-ep-photo-controls, .my-iapsnj-field, .my-iapsnj-other' ) || input.parentNode;
            wrap.appendChild( note );
            if ( ! first ) {
                first = input;
            }
        } );
        if ( first ) {
            first.focus();
        }
    }

    form.addEventListener( 'change', update );
    form.addEventListener( 'input', function ( e ) {
        if ( e.target && e.target.name && e.target.name.indexOf( P ) === 0 ) {
            update();
        }
    } );
    // "Department not listed? Add it here": pick "Other" and open its text box.
    form.addEventListener( 'click', function ( e ) {
        var a = e.target && e.target.closest ? e.target.closest( '.my-iapsnj-other-link' ) : null;
        if ( ! a ) {
            return;
        }
        e.preventDefault();
        var w = a.closest( '[data-my-iapsnj-other-link]' );
        var n = w ? w.getAttribute( 'data-my-iapsnj-other-link' ) : '';
        var s = n ? form.querySelector( 'select[name="' + n + '"]' ) : null;
        if ( ! s ) {
            return;
        }
        s.value = O;
        update();
        var i = form.querySelector( '[data-my-iapsnj-other-for="' + n + '"] input' );
        if ( i ) {
            i.focus();
        }
    } );

    form.addEventListener( 'submit', function ( e ) {
        e.preventDefault();
        clearErrors();

        var body = new FormData( form );
        body.append( 'action', cfg.action );
        body.append( 'nonce', cfg.nonce );

        var label = submit.textContent;
        submit.disabled = true;
        submit.textContent = cfg.i18n.saving;

        fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
            .then( function ( res ) {
                return res.json().catch( function () {
                    return null;
                } );
            } )
            .then( function ( json ) {
                if ( json && json.success ) {
                    if ( typeof json.data.photo === 'string' ) {
                        savedPhoto = json.data.photo;
                        setPreview( savedPhoto );
                        if ( photoInput ) {
                            photoInput.value = '';
                        }
                        if ( photoRemove ) {
                            photoRemove.checked = false;
                        }
                        if ( photoRemoveW ) {
                            photoRemoveW.hidden = savedPhoto === '';
                        }
                    }
                    showMessage( json.data.message, true );
                } else if ( json && json.data ) {
                    if ( json.data.errors ) {
                        showErrors( json.data.errors );
                    }
                    showMessage( json.data.message || cfg.i18n.error, false );
                } else {
                    showMessage( cfg.i18n.error, false );
                }
            } )
            .catch( function () {
                showMessage( cfg.i18n.error, false );
            } )
            .then( function () {
                submit.disabled = false;
                submit.textContent = label;
            } );
    } );

    update();
}() );
