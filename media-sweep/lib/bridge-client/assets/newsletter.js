/**
 * WPCreatix Bridge client: newsletter card (vanilla, no build step). Nothing is sent before Subscribe.
 */
( function () {
	'use strict';

	/**
	 * POST a form to admin-ajax and read the JSON reply.
	 *
	 * @param {HTMLFormElement} form Form to send.
	 * @return {Promise<Object>} Reply data ({} when the reply is not JSON).
	 */
	function send( form ) {
		var data = new FormData( form );

		data.set( 'js', '1' );

		// The hidden "action" field shadows form.action, so read the attribute.
		return fetch( form.getAttribute( 'action' ), {
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
	 * Set the card state and the error text.
	 *
	 * @param {Element} box     Card root.
	 * @param {string}  state   idle, error or success.
	 * @param {string}  message Error text.
	 * @return {void}
	 */
	function show( box, state, message ) {
		var email = box.querySelector( '[data-wcxbn-email]' );

		box.setAttribute( 'data-state', state );
		box.querySelector( '[data-wcxbn-error]' ).textContent = 'error' === state ? message || '' : '';
		email.setAttribute( 'aria-invalid', 'error' === state && ! email.checkValidity() ? 'true' : 'false' );
	}

	/**
	 * Mark the Subscribe button busy or idle.
	 *
	 * @param {HTMLFormElement} form  Subscribe form.
	 * @param {boolean}         state Whether a request is running.
	 * @return {void}
	 */
	function busy( form, state ) {
		var button = form.querySelector( '[data-wcxbn-subscribe]' );
		var label = form.querySelector( '[data-wcxbn-label]' );

		if ( ! label.hasAttribute( 'data-idle' ) ) {
			label.setAttribute( 'data-idle', label.textContent );
		}

		button.disabled = state;
		button.setAttribute( 'aria-busy', state ? 'true' : 'false' );
		label.textContent = state ? form.getAttribute( 'data-wcxbn-busy' ) : label.getAttribute( 'data-idle' );
	}

	/**
	 * Remove the card after the success text was readable for a moment.
	 *
	 * @param {Element} box Card root.
	 * @return {void}
	 */
	function leave( box ) {
		var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		window.setTimeout( function () {
			box.classList.add( 'is-leaving' );
			window.setTimeout( function () {
				box.remove();
			}, still ? 0 : 300 );
		}, 4000 );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var box = form && form.closest ? form.closest( '[data-wcxbn-box]' ) : null;
		var email;
		var request;

		if ( ! box ) {
			return;
		}

		event.preventDefault();

		if ( form.hasAttribute( 'data-wcxbn-dismiss-form' ) ) {
			send( form ).catch( function () {} );
			box.remove();
			return;
		}

		email = form.querySelector( '[data-wcxbn-email]' );
		email.value = email.value.trim();

		if ( ! email.value || ! email.checkValidity() ) {
			show( box, 'error', form.getAttribute( 'data-wcxbn-invalid' ) );
			email.focus();
			return;
		}

		// Build the request before disabling: disabled fields are left out of FormData.
		request = send( form );
		show( box, 'idle', '' );
		busy( form, true );
		request
			.then( function ( data ) {
				busy( form, false );

				if ( data.done ) {
					box.querySelector( '[data-wcxbn-success]' ).textContent = data.message;
					show( box, 'success', '' );
					leave( box );
					return;
				}

				show( box, 'error', data.message || form.getAttribute( 'data-wcxbn-failed' ) );
			} )
			.catch( function () {
				busy( form, false );
				show( box, 'error', form.getAttribute( 'data-wcxbn-failed' ) );
			} );
	} );
}() );
