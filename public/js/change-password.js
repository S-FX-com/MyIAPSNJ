/* My IAPSNJ – [iapsnj_change_password]: strength meter, show / hide, save. */
( function () {
    'use strict';

    var cfg = window.myIapsnjChangePassword;
    if ( ! cfg ) {
        return;
    }

    var form = document.querySelector( '.my-iapsnj-cp-form' );
    if ( ! form ) {
        return;
    }

    var fieldNew    = form.querySelector( '[name="new_password"]' );
    var fieldRepeat = form.querySelector( '[name="repeat_password"]' );
    var meter       = form.querySelector( '.my-iapsnj-cp-strength' );
    var meterText   = form.querySelector( '.my-iapsnj-cp-strength-text' );
    var matchNote   = form.querySelector( '.my-iapsnj-cp-match' );
    var message     = form.querySelector( '.my-iapsnj-cp-message' );
    var submit      = form.querySelector( '.my-iapsnj-cp-submit' );

    /**
     * 0 Very weak … 3 Strong. Uses WordPress's zxcvbn meter (which knows
     * common passwords and the member's own name / email); falls back to a
     * length-and-variety count if zxcvbn has not loaded.
     */
    function strengthLevel( pw ) {
        if ( pw.length < cfg.minLength ) {
            return 0;
        }
        var score = -1;
        if ( window.wp && wp.passwordStrength && typeof window.zxcvbn !== 'undefined' ) {
            score = wp.passwordStrength.meter( pw, cfg.disallowed, pw );
        }
        if ( score < 0 ) {
            var kinds = [ /[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/ ].filter( function ( re ) {
                return re.test( pw );
            } ).length;
            score = Math.min( 4, ( pw.length >= 12 ? 2 : 1 ) + ( kinds >= 3 ? 1 : 0 ) + ( kinds === 4 ? 1 : 0 ) );
        }
        // zxcvbn scores 0–4; 0 and 1 are both "very weak".
        return Math.max( 0, Math.min( 3, score - 1 ) );
    }

    function updateStrength() {
        var pw = fieldNew.value;
        if ( pw === '' ) {
            meter.hidden = true;
            return;
        }
        var level = strengthLevel( pw );
        meter.hidden = false;
        meter.setAttribute( 'data-level', String( level ) );
        meterText.textContent = pw.length < cfg.minLength
            ? cfg.i18n.tooShort
            : cfg.i18n.strength + ' ' + cfg.i18n.levels[ level ];
    }

    function updateMatch() {
        var repeat = fieldRepeat.value;
        matchNote.className = 'my-iapsnj-cp-match';
        if ( repeat === '' ) {
            matchNote.textContent = '';
            return;
        }
        var same = repeat === fieldNew.value;
        matchNote.textContent = same ? cfg.i18n.match : cfg.i18n.mismatch;
        matchNote.classList.add( same ? 'is-ok' : 'is-bad' );
    }

    function showMessage( text, success ) {
        message.textContent = text;
        message.classList.toggle( 'is-success', !! success );
        message.hidden = false;
    }

    fieldNew.addEventListener( 'input', function () {
        updateStrength();
        updateMatch();
    } );
    fieldRepeat.addEventListener( 'input', updateMatch );

    // The eye on each field shows or hides what was typed.
    Array.prototype.forEach.call( form.querySelectorAll( '.my-iapsnj-cp-toggle' ), function ( btn ) {
        btn.addEventListener( 'click', function () {
            var input = document.getElementById( btn.getAttribute( 'aria-controls' ) );
            var show  = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
            btn.setAttribute( 'aria-label', show ? cfg.i18n.hide : cfg.i18n.show );
        } );
    } );

    form.addEventListener( 'submit', function ( e ) {
        e.preventDefault();
        message.hidden = true;

        var pw = fieldNew.value;
        if ( pw === '' || fieldRepeat.value === '' ) {
            showMessage( cfg.i18n.required, false );
            return;
        }
        if ( pw !== fieldRepeat.value ) {
            showMessage( cfg.i18n.mismatch, false );
            fieldRepeat.focus();
            return;
        }
        if ( pw.length < cfg.minLength ) {
            showMessage( cfg.i18n.tooShort, false );
            fieldNew.focus();
            return;
        }

        var body = new FormData();
        body.append( 'action', cfg.action );
        body.append( 'nonce', cfg.nonce );
        body.append( 'new_password', pw );
        body.append( 'repeat_password', fieldRepeat.value );

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
                    fieldNew.value = '';
                    fieldRepeat.value = '';
                    form.querySelector( '.my-iapsnj-cp-row' ).hidden = true;
                    submit.hidden = true;
                    showMessage( json.data.message, true );
                    return;
                }
                showMessage( json && json.data && json.data.message ? json.data.message : cfg.i18n.error, false );
                submit.disabled = false;
                submit.textContent = label;
            } )
            .catch( function () {
                showMessage( cfg.i18n.error, false );
                submit.disabled = false;
                submit.textContent = label;
            } );
    } );
}() );
