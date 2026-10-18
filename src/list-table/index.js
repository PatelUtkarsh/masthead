import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';
import './list-table.scss';

/**
 * Posts screen: confirm Masthead bulk moves and keep both bulk selects in sync.
 */
domReady( () => {
	const form = document.getElementById( 'posts-filter' );
	if ( ! form ) {
		return;
	}
	form.addEventListener( 'submit', ( e ) => {
		const submitter = e.submitter;
		const select =
			submitter?.id === 'doaction2'
				? form.querySelector( '#bulk-action-selector-bottom' )
				: form.querySelector( '#bulk-action-selector-top' );
		const action = select?.value || '';
		if ( ! action.startsWith( 'masthead_move_' ) ) {
			return;
		}
		const checked = form.querySelectorAll(
			'input[name="post[]"]:checked'
		).length;
		if ( ! checked ) {
			e.preventDefault();
			// eslint-disable-next-line no-alert
			window.alert( __( 'Select at least one post first.', 'masthead' ) );
		}
	} );
} );
