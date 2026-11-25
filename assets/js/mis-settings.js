/**
 * Make it Static — settings page behaviour (test connection).
 */
( function () {
	'use strict';

	var cfg = window.misSettings || {};
	var btn = document.getElementById( 'mis-test-connection' );
	var out = document.getElementById( 'mis-test-result' );

	if ( ! btn || ! out ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		out.hidden = false;
		out.className = 'mis-test-result';
		out.textContent = cfg.strings.testing;

		window
			.fetch( cfg.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: 'action=mis_test_connection&nonce=' + encodeURIComponent( cfg.nonce )
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				var data = payload && payload.data ? payload.data : {};
				var ok = Boolean( payload && payload.success && data.ok && data.redis );
				out.className = ok ? 'mis-test-result mis-test-ok' : 'mis-test-result mis-test-error';
				out.textContent = data.human ? data.human : cfg.strings.error;
			} )
			.catch( function () {
				out.className = 'mis-test-result mis-test-error';
				out.textContent = cfg.strings.error;
			} )
			.finally( function () {
				btn.disabled = false;
			} );
	} );
} )();
