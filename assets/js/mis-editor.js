( function ( wp ) {
	'use strict';

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginSidebar = wp.editPost.PluginSidebar;
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	function MisSidebar() {
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		var state = useState( { busy: false, message: '', isError: false } );
		var ui = state[ 0 ];
		var setUi = state[ 1 ];

		function onPublish() {
			setUi( { busy: true, message: '', isError: false } );

			var body = new window.FormData();
			body.append( 'action', 'mis_publish_now' );
			body.append( 'nonce', window.misEditor.nonce );
			body.append( 'post_id', postId );

			window
				.fetch( window.misEditor.ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( resp ) {
					if ( resp && resp.success && resp.data && resp.data.job ) {
						setUi( {
							busy: false,
							message: sprintf(
								/* translators: %d: job row ID. */
								__( 'Static publish queued (job #%d).', 'make-it-static' ),
								resp.data.job.id
							),
							isError: false,
						} );
					} else {
						setUi( {
							busy: false,
							message:
								resp && resp.data && resp.data.message
									? resp.data.message
									: __( 'The static publish request failed.', 'make-it-static' ),
							isError: true,
						} );
					}
				} )
				.catch( function () {
					setUi( {
						busy: false,
						message: __( 'The static publish request failed.', 'make-it-static' ),
						isError: true,
					} );
				} );
		}

		return el(
			PluginSidebar,
			{ name: 'mis-sidebar', title: __( 'Make it Static', 'make-it-static' ), icon: 'download' },
			el(
				'div',
				{ className: 'mis-sidebar-panel', style: { padding: '16px' } },
				el(
					'p',
					{},
					__( 'Publish this post to the static site.', 'make-it-static' )
				),
				el(
					Button,
					{
						variant: 'primary',
						onClick: onPublish,
						disabled: ui.busy || ! postId,
					},
					ui.busy
						? __( 'Queuing…', 'make-it-static' )
						: __( 'Publish static', 'make-it-static' )
				),
				ui.busy && el( Spinner, {} ),
				ui.message &&
					el(
						Notice,
						{ status: ui.isError ? 'error' : 'success', isDismissible: false },
						ui.message
					)
			)
		);
	}

	registerPlugin( 'make-it-static', { render: MisSidebar } );
} )( window.wp );
