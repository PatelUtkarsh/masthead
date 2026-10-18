import { useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from '../store';
import { ToastProvider } from '../common/components';
import { navigate, openPost, useRoute } from './router';
import Header from './Header';
import Board from './board/Board';
import Calendar from './calendar/Calendar';
import Workflows from './workflows/Workflows';
import Setup from './setup/Setup';
import Drawer from './drawer/Drawer';

const VIEWS = [ 'board', 'calendar', 'workflows', 'setup' ];

function useAdminMenuHighlight( view ) {
	useEffect( () => {
		const slugs = {
			board: 'masthead',
			calendar: 'masthead-calendar',
			workflows: 'masthead-workflows',
			setup: 'masthead-setup',
		};
		const items = document.querySelectorAll(
			'#toplevel_page_masthead .wp-submenu li'
		);
		items.forEach( ( li ) => {
			const a = li.querySelector( 'a' );
			const href = a?.getAttribute( 'href' ) || '';
			const on = href.endsWith( 'page=' + slugs[ view ] );
			li.classList.toggle( 'current', on );
			a?.classList.toggle( 'current', on );
			if ( on ) {
				a?.setAttribute( 'aria-current', 'page' );
			} else {
				a?.removeAttribute( 'aria-current' );
			}
		} );
	}, [ view ] );
}

export default function App( { initial } ) {
	const route = useRoute();
	const { boot, defaultBoard } = useSelect(
		( select ) => ( {
			boot: select( STORE ).getBoot(),
			defaultBoard: select( STORE ).getDefaultBoard(),
		} ),
		[]
	);

	// Pick the first route from the submenu page, or Setup on first run.
	useEffect( () => {
		if ( VIEWS.includes( route.view ) ) {
			return;
		}
		const needsSetup =
			! boot.settings.setup_complete && boot.user.caps.manage_workflows;
		const start = needsSetup && initial === 'board' ? 'setup' : initial;
		if ( start === 'board' ) {
			navigate( 'board', defaultBoard, route.query );
		} else if ( start === 'workflows' ) {
			navigate( 'workflows', boot.workflows[ 0 ]?.key || '' );
		} else {
			navigate( start, '', route.query );
		}
	}, [ route.view ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useAdminMenuHighlight( route.view );

	if ( ! VIEWS.includes( route.view ) ) {
		return <p className="mh-loading">{ __( 'Loading…', 'masthead' ) }</p>;
	}

	const postId = route.query.post ? Number( route.query.post ) : null;

	return (
		<ToastProvider>
			<div className={ 'mh-app mh-app--' + route.view }>
				{ route.view !== 'setup' && <Header route={ route } /> }
				{ route.view === 'board' && <Board route={ route } /> }
				{ route.view === 'calendar' && <Calendar route={ route } /> }
				{ route.view === 'workflows' && <Workflows route={ route } /> }
				{ route.view === 'setup' && <Setup /> }
				{ postId && (
					<Drawer
						key={ postId }
						id={ postId }
						route={ route }
						onClose={ () => openPost( route, null ) }
					/>
				) }
			</div>
		</ToastProvider>
	);
}
