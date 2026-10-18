import { useMemo, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Icon, people } from '@wordpress/icons';
import { STORE } from '../store';
import { Avatar, useEscape } from '../common/components';
import { UserPicker } from '../common/pickers';
import { useUser } from '../common/users';
import { navigate } from './router';

const MAX_AVATARS = 6;

/**
 * Assignee avatar filter. Avatars come from people already on screen (you and
 * the authors of loaded cards), so no user list is fetched up front. Anyone
 * else is one search away.
 *
 * @param {Object} props
 * @param {Object} props.route Current route.
 */
export default function AssigneeFilter( { route } ) {
	const who = Number( route.query.who || 0 );
	const { me, columns } = useSelect(
		( select ) => ( {
			me: select( STORE ).getUser(),
			columns: select( STORE ).getBoardState().columns,
		} ),
		[]
	);
	const selected = useUser( who || null );
	const [ open, setOpen ] = useState( false );
	useEscape( () => setOpen( false ), open );

	const avatars = useMemo( () => {
		const seen = new Map();
		const add = ( u ) => u?.id && ! seen.has( u.id ) && seen.set( u.id, u );
		add( me );
		add( selected );
		Object.values( columns ).forEach( ( col ) =>
			col.cards.forEach( ( c ) => add( c.author ) )
		);
		return [ ...seen.values() ].slice( 0, MAX_AVATARS );
	}, [ me, selected, columns ] );

	const set = ( id ) =>
		navigate( route.view, route.param, {
			...route.query,
			who: id ? String( id ) : '',
		} );

	return (
		<div
			className="mh-people"
			role="group"
			aria-label={ __( 'Filter by assignee', 'masthead' ) }
		>
			{ avatars.map( ( p ) => (
				<button
					key={ p.id }
					type="button"
					className={
						'mh-people__btn' +
						( who === p.id ? ' is-active' : '' ) +
						( who && who !== p.id ? ' is-dim' : '' )
					}
					aria-pressed={ who === p.id }
					title={ p.name }
					onClick={ () => set( who === p.id ? 0 : p.id ) }
				>
					<Avatar user={ p } size={ 28 } />
				</button>
			) ) }
			<div className="mh-people__more">
				<button
					type="button"
					className="mh-icon-btn mh-icon-btn--outline mh-icon-btn--round"
					aria-expanded={ open }
					aria-label={ __( 'Filter by someone else', 'masthead' ) }
					title={ __( 'Filter by someone else', 'masthead' ) }
					onClick={ () => setOpen( ! open ) }
				>
					<Icon icon={ people } size={ 18 } />
				</button>
				{ open && (
					<div className="mh-popover mh-people__pop">
						<UserPicker
							label={ __( 'Assignee', 'masthead' ) }
							noneLabel={ __( 'Anyone', 'masthead' ) }
							value={ who || null }
							onChange={ ( u ) => {
								set( u ? u.id : 0 );
								setOpen( false );
							} }
						/>
					</div>
				) }
			</div>
		</div>
	);
}
