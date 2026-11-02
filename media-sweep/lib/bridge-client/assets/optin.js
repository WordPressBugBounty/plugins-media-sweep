/**
 * WPCreatix Bridge client: opt-in screen, notice, preview dialog and data sharing panel (vanilla, no build step).
 */
( function () {
	'use strict';

	var config = window.wpcreatixBridgeClient || {};
	var lastFocus = null;

	/**
	 * POST a form to admin-ajax and read the JSON reply.
	 *
	 * @param {HTMLFormElement} form  Form to send.
	 * @param {Object}          extra Extra fields to set.
	 * @return {Promise<Object>} Reply data ({} when the reply is not JSON).
	 */
	function send( form, extra ) {
		var data = new FormData( form );

		data.set( 'js', '1' );
		Object.keys( extra || {} ).forEach( function ( key ) {
			data.set( key, extra[ key ] );
		} );

		return fetch( config.ajax || form.getAttribute( 'action' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( reply ) {
				return reply && reply.data ? reply.data : {};
			} );
	}

	/**
	 * Show a message in a form's live region.
	 *
	 * @param {Element} root    Element that holds the message node.
	 * @param {string}  message Text.
	 * @return {void}
	 */
	function say( root, message ) {
		var node = root.querySelector( '[data-wcxbc-message]' );

		if ( node ) {
			node.textContent = message || '';
		}
	}

	/**
	 * Enable or disable every button in a form.
	 *
	 * @param {Element} form  Form.
	 * @param {boolean} state Whether a request is running.
	 * @return {void}
	 */
	function busy( form, state ) {
		form.querySelectorAll( 'button, input' ).forEach( function ( el ) {
			el.disabled = state;
		} );
	}

	/**
	 * Close the opt-in screen and show the plugin UI without a reload.
	 *
	 * @param {Element} screen Screen root.
	 * @return {void}
	 */
	function closeScreen( screen ) {
		document.body.classList.remove( 'wcxbc-optin-open' );
		screen.remove();

		// Apps that measured themselves while hidden get a chance to lay out again.
		window.dispatchEvent( new Event( 'resize' ) );
	}

	/**
	 * Opt-in screen: Allow & Continue or Skip.
	 *
	 * @param {HTMLFormElement} form      Screen form.
	 * @param {HTMLElement}     submitter Clicked button.
	 * @return {void}
	 */
	function submitOptin( form, submitter ) {
		var screen = form.closest( '[data-wcxbc-optin]' );
		var request = send( form, { op: submitter && submitter.value ? submitter.value : 'optin_skip' } );

		busy( form, true );
		request
			.then( function ( data ) {
				// A refused request (stale nonce, no rights) saved nothing, so the choice stays on screen.
				if ( ! data.done ) {
					busy( form, false );
					return;
				}

				closeScreen( screen );
			} )
			.catch( function () {
				busy( form, false );
			} );
	}

	/**
	 * Notice: Allow turns it into the thank-you line; No thanks and X remove it.
	 *
	 * @param {HTMLFormElement} form      Notice form.
	 * @param {HTMLElement}     submitter Clicked button.
	 * @return {void}
	 */
	function submitNotice( form, submitter ) {
		var notice = form.closest( '[data-wcxbc-notice]' );
		var op = submitter && submitter.value ? submitter.value : 'notice_dismiss';
		var request = send( form, { op: op } );

		busy( form, true );

		if ( 'notice_dismiss' === op ) {
			notice.remove();
			request.catch( function () {} );
			return;
		}

		request
			.then( function ( data ) {
				notice.setAttribute( 'data-state', 'success' );
				say( notice, data.message );
				busy( form, false );
			} )
			.catch( function () {
				busy( form, false );
			} );
	}

	/**
	 * Settings toggle or erase: the server returns the new panel.
	 *
	 * @param {HTMLFormElement} form Form.
	 * @return {void}
	 */
	function submitPanel( form ) {
		var panel = form.closest( '[data-wcxbc-settings]' );
		var question = form.getAttribute( 'data-wcxbc-confirm' );
		var request;

		// eslint-disable-next-line no-alert -- native confirm keeps the delete dialog dependency-free.
		if ( question && ! window.confirm( question ) ) {
			return;
		}

		request = send( form, {} );
		busy( form, true );
		request
			.then( function ( data ) {
				var holder = document.createElement( 'div' );

				if ( ! data.html ) {
					busy( form, false );
					say( form, data.message );
					return;
				}

				holder.innerHTML = data.html;
				panel.replaceWith( holder.firstElementChild );
			} )
			.catch( function () {
				busy( form, false );
			} );
	}

	/**
	 * Open the "what is sent" dialog and fill it with the exact bodies.
	 *
	 * @param {HTMLElement} button The link that opened it.
	 * @return {void}
	 */
	function openPreview( button ) {
		var screen = button.closest( '[data-wcxbc-optin]' );
		var panel = screen.querySelector( '[data-wcxbc-preview]' );
		var form = screen.querySelector( '[data-wcxbc-form="optin"]' );
		var json = panel.querySelector( '[data-wcxbc-preview-json]' );
		var ext = panel.querySelector( '[data-wcxbc-preview-ext]' );

		lastFocus = button;
		panel.hidden = false;
		panel.querySelector( '[data-wcxbc-close-preview]' ).focus();

		send( form, { op: 'preview' } )
			.then( function ( data ) {
				var parts = ( data.extensions || [] ).map( function ( part ) {
					return JSON.stringify( part, null, 2 );
				} );

				json.textContent = data.body ? JSON.stringify( data.body, null, 2 ) : '';
				ext.textContent = parts.join( '\n\n' );
				panel.querySelector( '[data-wcxbc-preview-ext-wrap]' ).hidden = ! parts.length;
			} )
			.catch( function () {
				json.textContent = '';
			} );
	}

	/**
	 * Close every open preview dialog and return focus.
	 *
	 * @return {void}
	 */
	function closePreview() {
		document.querySelectorAll( '[data-wcxbc-preview]' ).forEach( function ( panel ) {
			panel.hidden = true;
		} );

		if ( lastFocus ) {
			lastFocus.focus();
			lastFocus = null;
		}
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var kind = form && form.getAttribute ? form.getAttribute( 'data-wcxbc-form' ) : null;

		if ( ! kind ) {
			return;
		}

		event.preventDefault();

		if ( 'optin' === kind ) {
			submitOptin( form, event.submitter );
		} else if ( 'notice' === kind ) {
			submitNotice( form, event.submitter );
		} else {
			submitPanel( form );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var form = event.target.closest ? event.target.closest( '[data-wcxbc-form="usage"]' ) : null;

		if ( form && 'checkbox' === event.target.type ) {
			submitPanel( form );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest ? event.target : null;
		var details = target ? target.closest( '[data-wcxbc-details]' ) : null;
		var panel;

		if ( ! target ) {
			return;
		}

		if ( details ) {
			panel = details.closest( '[data-wcxbc-notice]' ).querySelector( '[data-wcxbc-details-panel]' );
			panel.hidden = ! panel.hidden;
			details.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
		}

		if ( target.closest( '[data-wcxbc-open-preview]' ) ) {
			openPreview( target.closest( '[data-wcxbc-open-preview]' ) );
		}

		if ( target.closest( '[data-wcxbc-close-preview]' ) || target.matches( '[data-wcxbc-preview]' ) ) {
			closePreview();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && document.querySelector( '[data-wcxbc-preview]:not([hidden])' ) ) {
			closePreview();
		}
	} );
}() );
