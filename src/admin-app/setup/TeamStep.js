import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Avatar, Toggle } from '../../common/components';
import { searchUsers } from '../../common/users';

/**
 * Team step of the setup wizard. Users load 20 at a time with search, and
 * only the toggles you change are sent, so it works with any number of users.
 *
 * @param {Object}                                  props
 * @param {number}                                  props.selfId   Current user (always included).
 * @param {Record<number, boolean>}                 props.changes  Changed toggles.
 * @param {(next: Record<number, boolean>) => void} props.onChange Change handler.
 */
export default function TeamStep( { selfId, changes, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const [ users, setUsers ] = useState( [] );
	const [ page, setPage ] = useState( 1 );
	const [ more, setMore ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const seq = useRef( 0 );

	useEffect( () => {
		const n = ++seq.current;
		setBusy( true );
		const t = setTimeout(
			() =>
				searchUsers( { search, anyRole: true } ).then(
					( r ) => {
						if ( n === seq.current ) {
							setUsers( r.users );
							setMore( r.has_more );
							setPage( 1 );
							setBusy( false );
						}
					},
					() => setBusy( false )
				),
			search ? 250 : 0
		);
		return () => clearTimeout( t );
	}, [ search ] );

	const loadMore = async () => {
		setBusy( true );
		try {
			const r = await searchUsers( {
				search,
				anyRole: true,
				page: page + 1,
			} );
			setUsers( ( prev ) => [ ...prev, ...r.users ] );
			setMore( r.has_more );
			setPage( page + 1 );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="mh-team-step">
			<label className="screen-reader-text" htmlFor="mh-team-search">
				{ __( 'Search users', 'masthead' ) }
			</label>
			<input
				id="mh-team-search"
				type="search"
				className="mh-input"
				placeholder={ __( 'Search users…', 'masthead' ) }
				value={ search }
				onChange={ ( e ) => setSearch( e.target.value ) }
			/>
			<div className="mh-team" aria-busy={ busy }>
				{ users.map( ( u ) => {
					const on =
						u.id in changes ? changes[ u.id ] : !! u.included;
					return (
						<div
							key={ u.id }
							className="mh-setting mh-setting--box-line"
						>
							<Avatar user={ u } size={ 30 } />
							<div className="mh-setting__text">
								<span className="mh-strong">{ u.name }</span>
								<span className="mh-muted">{ u.role }</span>
							</div>
							<Toggle
								checked={ on }
								disabled={ u.id === selfId }
								onChange={ ( v ) =>
									onChange( { ...changes, [ u.id ]: v } )
								}
								label={ sprintf(
									/* translators: %s: user. */
									__( 'Include %s', 'masthead' ),
									u.name
								) }
							/>
						</div>
					);
				} ) }
			</div>
			{ ! busy && ! users.length && (
				<p className="mh-muted">
					{ __( 'No users found.', 'masthead' ) }
				</p>
			) }
			{ more && (
				<button
					type="button"
					className="mh-btn mh-btn--ghost"
					onClick={ loadMore }
					disabled={ busy }
				>
					{ busy
						? __( 'Loading…', 'masthead' )
						: __( 'Show more people', 'masthead' ) }
				</button>
			) }
		</div>
	);
}
