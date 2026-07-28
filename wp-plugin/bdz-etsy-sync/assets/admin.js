/**
 * Drives the sync from the admin screen.
 *
 * Each request runs a short, time-boxed slice of work on the server and comes
 * back with progress plus any new log lines. Polling in a chain like this (not
 * on a timer) means requests can never stack up on a slow server.
 *
 * Vanilla JS, no dependencies, no build step.
 */
( function () {
	'use strict';

	var progress = document.getElementById( 'bdz-progress' );
	var logBox = document.getElementById( 'bdz-log' );
	if ( ! progress || ! logBox ) {
		return;
	}

	var bar = progress.querySelector( '.bdz-bar span' );
	var message = progress.querySelector( '.bdz-message' );
	var statsBox = progress.querySelector( '.bdz-stats' );
	var since = logBox.textContent.trim() ? logBox.textContent.trim().split( '\n' ).length : 0;
	var stopped = false;

	function post( action ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', window.bdzEtsy.nonce );
		body.append( 'since', String( since ) );

		return fetch( window.bdzEtsy.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} );
	}

	function appendLog( lines ) {
		if ( ! lines || ! lines.length ) {
			return;
		}
		var text = '';
		lines.forEach( function ( line ) {
			text += '[' + line.level.toUpperCase() + '] ' + line.message + '\n';
		} );
		logBox.textContent += text;
		logBox.scrollTop = logBox.scrollHeight;
	}

	function paint( data ) {
		bar.style.width = ( data.percent || 0 ) + '%';
		message.textContent = data.message || '';

		if ( data.stats ) {
			statsBox.textContent =
				'created ' + data.stats.created +
				' · updated ' + data.stats.updated +
				' · skipped ' + data.stats.skipped +
				' · failed ' + data.stats.failed;
		}

		appendLog( data.log );
		if ( typeof data.next === 'number' ) {
			since = data.next;
		}
	}

	function pump() {
		if ( stopped ) {
			return;
		}

		post( 'bdz_etsy_tick' )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'unexpected response' );
				}
				paint( payload.data );

				if ( 'running' === payload.data.status ) {
					pump();
					return;
				}

				stopped = true;
				progress.setAttribute( 'data-running', '0' );

				if ( 'done' === payload.data.status ) {
					// Reload so the readiness panel and last-run summary refresh.
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
				}
			} )
			.catch( function ( error ) {
				stopped = true;
				message.textContent = 'Lost contact with the server (' + error.message +
					'). The sync keeps running in the background — reload to check on it.';
			} );
	}

	if ( '1' === progress.getAttribute( 'data-running' ) ) {
		pump();
	}
} )();
