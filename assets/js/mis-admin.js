/**
 * Make it Static — admin jobs page behaviour.
 */
( function () {
	'use strict';

	var STATUS_GROUPS = {
		queued: 'running',
		started: 'running',
		deferred: 'running',
		scheduled: 'running',
		finished: 'finished',
		failed: 'failed',
		stopped: 'failed',
		canceled: 'failed',
		expired: 'expired'
	};

	function post( data ) {
		var body = new URLSearchParams();
		body.set( 'nonce', window.misAdmin.nonce );
		Object.keys( data ).forEach( function ( key ) {
			body.set( key, data[ key ] );
		} );

		return window
			.fetch( window.misAdmin.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function setMessage( el, text, isError ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.toggle( 'is-error', !! isError );
		el.classList.toggle( 'is-success', !! text && ! isError );
	}

	function errorMessage( json ) {
		if ( json && json.data && json.data.message ) {
			return json.data.message;
		}
		return window.misAdmin.strings.requestFailed;
	}

	function updateBadge( row, status ) {
		var badge = row && row.querySelector( '.mis-badge' );
		var group = STATUS_GROUPS[ status ];
		if ( ! badge || ! group ) {
			return;
		}
		badge.textContent = status;
		badge.setAttribute( 'data-status', status );
		badge.className = 'mis-badge mis-badge--' + group;
		if ( group === 'running' ) {
			badge.classList.add( 'mis-badge--pulse' );
		}
	}

	function handleJobAction( button ) {
		var action = button.getAttribute( 'data-action' );
		var rowId = button.getAttribute( 'data-row-id' );
		var row = button.closest( 'tr' );
		var msg = row ? row.querySelector( '.mis-action-msg' ) : null;

		if ( action === 'cancel' && ! window.confirm( window.misAdmin.strings.confirmCancel ) ) {
			return;
		}

		button.disabled = true;
		setMessage( msg, window.misAdmin.strings.working, false );

		post( {
			action: action === 'cancel' ? 'mis_cancel_job' : 'mis_retry_job',
			row_id: rowId
		} )
			.then( function ( json ) {
				if ( json && json.success ) {
					setMessage( msg, json.data && json.data.message ? json.data.message : '', false );
					if ( json.data && json.data.status ) {
						updateBadge( row, json.data.status );
					}
					// Action availability depends on server state; refresh to re-render buttons.
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					setMessage( msg, errorMessage( json ), true );
					button.disabled = false;
				}
			} )
			.catch( function () {
				setMessage( msg, window.misAdmin.strings.requestFailed, true );
				button.disabled = false;
			} );
	}

	function handleDetailsToggle( button ) {
		var rowId = button.getAttribute( 'data-row-id' );
		var row = rowId ? document.querySelector( 'tr[data-detail-for="' + rowId + '"]' ) : null;
		if ( ! row ) {
			return;
		}

		var expanded = row.hidden;
		row.hidden = ! expanded;
		button.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );

		var strings = window.misAdmin.strings || {};
		button.textContent = expanded
			? strings.hideDetails || 'Hide details'
			: strings.viewDetails || 'View details';
	}

	function handleRepublish( button ) {
		var msg = document.getElementById( 'mis-republish-msg' );

		button.disabled = true;
		setMessage( msg, window.misAdmin.strings.working, false );

		post( {
			action: 'mis_publish_now',
			post_id: 0
		} )
			.then( function ( json ) {
				if ( json && json.success ) {
					setMessage( msg, window.misAdmin.strings.publishQueued, false );
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					setMessage( msg, errorMessage( json ), true );
					button.disabled = false;
				}
			} )
			.catch( function () {
				setMessage( msg, window.misAdmin.strings.requestFailed, true );
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var actionButton = event.target.closest( '.mis-action' );
		if ( actionButton ) {
			event.preventDefault();
			handleJobAction( actionButton );
			return;
		}

		var toggleButton = event.target.closest( '.mis-details-toggle' );
		if ( toggleButton ) {
			event.preventDefault();
			handleDetailsToggle( toggleButton );
			return;
		}

		var republishButton = event.target.closest( '#mis-republish-site' );
		if ( republishButton ) {
			event.preventDefault();
			handleRepublish( republishButton );
		}
	} );
} )();
