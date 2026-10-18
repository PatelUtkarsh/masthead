import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { store } from '../store';
import App from './App';
import './admin-app.scss';

domReady( () => {
	const root = document.getElementById( 'masthead-root' );
	if ( ! root ) {
		return;
	}
	dispatch( store ).setBoot( window.mastheadBootstrap );
	createRoot( root ).render(
		<App initial={ root.dataset.route || 'board' } />
	);
} );
