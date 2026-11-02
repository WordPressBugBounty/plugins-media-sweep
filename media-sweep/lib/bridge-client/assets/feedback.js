/**
 * WPCreatix Bridge client: optional deactivation feedback on the plugins screen (vanilla).
 */
( function () {
	'use strict';

	var config = window.wpcreatixBridgeClient || {};
	var modal = document.querySelector( '[data-wcxbc-feedback]' );
	var data;
	var current = null;

	if ( ! modal ) {
		return;
	}

	data = JSON.parse( modal.querySelector( '[data-wcxbc-feedback-data]' ).textContent );

	/**
	 * Our plugin row for a deactivate link, if any.
	 *
	 * @param {Element} link Clicked link.
	 * @return {Object|null} Plugin info.
	 */
	function pluginFor( link ) {
		var row = link.closest( 'tr[data-plugin]' );
		var basename = row ? row.getAttribute( 'data-plugin' ) : '';

		return data.plugins.filter( function ( plugin ) {
			return plugin.basename === basename;
		} )[ 0 ] || null;
	}

	/**
	 * Fill {plugin_name} in the template paragraphs.
	 *
	 * @param {string} name Plugin name.
	 * @return {void}
	 */
	function fill( name ) {
		modal.querySelectorAll( '[data-wcxbc-template]' ).forEach( function ( node ) {
			node.textContent = node.getAttribute( 'data-wcxbc-template' ).split( '{plugin_name}' ).join( name );
		} );
	}

	/**
	 * Open the modal for one plugin.
	 *
	 * @param {Object} plugin Plugin info.
	 * @param {string} href   Deactivate URL.
	 * @return {void}
	 */
	function open( plugin, href ) {
		var form = modal.querySelector( 'form' );

		current = { plugin: plugin, href: href };
		form.reset();
		modal.querySelector( '[data-wcxbc-text-row]' ).hidden = true;
		modal.querySelector( '[data-wcxbc-reply-row]' ).hidden = true;
		fill( plugin.name );
		modal.hidden = false;
		modal.querySelector( 'input[name="reason"]' ).focus();
	}

	/**
	 * Close without deactivating.
	 *
	 * @return {void}
	 */
	function close() {
		modal.hidden = true;
		current = null;
	}

	/**
	 * Go on with the deactivation.
	 *
	 * @return {void}
	 */
	function proceed() {
		window.location.href = current.href;
	}

	/**
	 * Send the answer (at most 2 s), then deactivate.
	 *
	 * @param {HTMLFormElement} form Feedback form.
	 * @return {void}
	 */
	function submit( form ) {
		var body = new FormData( form );
		var controller = window.AbortController ? new window.AbortController() : null;

		body.set( 'action', 'wpcreatix_bridge_client' );
		body.set( 'op', 'feedback' );
		body.set( 'nonce', config.nonce || '' );
		body.set( 'slug', current.plugin.slug );
		body.set( 'js', '1' );

		if ( ! body.get( 'reason' ) ) {
			proceed();
			return;
		}

		if ( controller ) {
			window.setTimeout( function () {
				controller.abort();
			}, 2000 );
		}

		modal.querySelectorAll( 'button' ).forEach( function ( button ) {
			button.disabled = true;
		} );

		fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body, signal: controller ? controller.signal : undefined } )
			.catch( function () {} )
			.then( proceed );
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( 'tr[data-plugin] .deactivate a' ) : null;
		var plugin = link ? pluginFor( link ) : null;

		if ( plugin ) {
			event.preventDefault();
			open( plugin, link.href );
			return;
		}

		if ( current && event.target.closest( '[data-wcxbc-skip]' ) ) {
			proceed();
			return;
		}

		if ( current && event.target === modal ) {
			close();
		}
	} );

	modal.addEventListener( 'change', function ( event ) {
		var textRow = modal.querySelector( '[data-wcxbc-text-row]' );
		var replyRow = modal.querySelector( '[data-wcxbc-reply-row]' );
		var email = replyRow.querySelector( 'input' );

		if ( 'reason' === event.target.name ) {
			textRow.hidden = ! event.target.getAttribute( 'data-placeholder' );
			textRow.querySelector( 'textarea' ).placeholder = event.target.getAttribute( 'data-placeholder' ) || '';
		}

		if ( event.target.hasAttribute( 'data-wcxbc-reply' ) ) {
			replyRow.hidden = ! event.target.checked;

			// The email is prefilled only after the box is ticked.
			if ( event.target.checked && ! email.value ) {
				email.value = data.email;
			}
		}
	} );

	modal.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		submit( event.target );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( current && 'Escape' === event.key ) {
			close();
		}
	} );
}() );
