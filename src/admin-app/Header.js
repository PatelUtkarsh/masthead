import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import {
	Icon,
	calendar,
	chevronDown,
	cog,
	columns,
	plus,
	search as searchIcon,
	settings,
} from '@wordpress/icons';
import { debounce } from '@wordpress/compose';
import { STORE } from '../store';
import { api, errorMessage } from '../common/api';
import { Segmented, useToast } from '../common/components';
import { navigate } from './router';
import BoardSwitcher from './board/BoardSwitcher';
import BoardModal from './board/BoardModal';
import AssigneeFilter from './AssigneeFilter';

export default function Header( { route } ) {
	const toast = useToast();
	const { boards, defaultBoard, workflows, user } = useSelect( ( select ) => {
		const s = select( STORE );
		return {
			boards: s.getBoards(),
			defaultBoard: s.getDefaultBoard(),
			workflows: s.getWorkflows(),
			user: s.getUser(),
		};
	}, [] );
	const { placeCard } = useDispatch( STORE );
	const [ menu, setMenu ] = useState( false );
	const [ modal, setModal ] = useState( null );
	const [ term, setTerm ] = useState( route.query.s || '' );

	const isBoard = route.view === 'board';
	const board = isBoard
		? boards.find( ( b ) => b.id === route.param ) ||
			boards.find( ( b ) => b.id === defaultBoard )
		: null;
	const wf =
		( board && workflows.find( ( w ) => w.key === board.workflow ) ) ||
		( route.view === 'workflows' &&
			workflows.find( ( w ) => w.key === route.param ) ) ||
		workflows[ 0 ];

	const routeRef = useRef( route );
	routeRef.current = route;
	const pushSearch = useMemo(
		() =>
			debounce( ( value ) => {
				const r = routeRef.current;
				navigate( r.view, r.param, { ...r.query, s: value } );
			}, 300 ),
		[]
	);
	useEffect( () => () => pushSearch.cancel(), [ pushSearch ] );

	useEffect( () => {
		setTerm( route.query.s || '' );
	}, [ route.view ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const filters = [];
	if ( board?.labels?.category ) {
		filters.push( board.labels.category.name );
	}
	if ( board?.labels?.author ) {
		filters.push( board.labels.author.name );
	}

	let eyebrow = '';
	if ( route.view === 'calendar' ) {
		eyebrow = __( 'All workflows', 'masthead' );
	} else if ( wf ) {
		/* translators: %s: workflow name. */
		eyebrow = sprintf( __( '%s workflow', 'masthead' ), wf.name );
	}
	if ( isBoard && filters.length ) {
		eyebrow += ' · ' + filters.join( ' · ' );
	}

	const title = {
		board: board?.name || __( 'Board', 'masthead' ),
		calendar: __( 'Calendar', 'masthead' ),
		workflows: __( 'Workflows', 'masthead' ),
	}[ route.view ];

	const newPost = async () => {
		const workflow = board?.workflow || wf?.key;
		try {
			const post = await api.createPost( { workflow, board: board?.id } );
			if ( isBoard ) {
				placeCard( post );
			}
			navigate( route.view, route.param, {
				...route.query,
				post: String( post.id ),
			} );
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		}
	};

	return (
		<header className="mh-header">
			<div className="mh-header__brand">
				<span className="mh-tile" aria-hidden="true">
					<Icon icon={ columns } size={ 20 } />
				</span>
				<div className="mh-header__titles">
					<span className="mh-eyebrow">
						{ __( 'Masthead', 'masthead' ) } · { eyebrow }
					</span>
					{ isBoard ? (
						<div className="mh-header__switch">
							<button
								type="button"
								className="mh-title-btn"
								aria-haspopup="menu"
								aria-expanded={ menu }
								onClick={ () => setMenu( ! menu ) }
							>
								{ title }
								<Icon icon={ chevronDown } size={ 22 } />
							</button>
							{ board && user.caps.manage_boards && (
								<button
									type="button"
									className="mh-icon-btn"
									aria-label={ __(
										'Board settings',
										'masthead'
									) }
									title={ __( 'Board settings', 'masthead' ) }
									onClick={ () => setModal( { board } ) }
								>
									<Icon icon={ cog } size={ 18 } />
								</button>
							) }
							{ menu && (
								<BoardSwitcher
									current={ board?.id }
									onClose={ () => setMenu( false ) }
									onEdit={ ( b ) => {
										setMenu( false );
										setModal( { board: b } );
									} }
									onNew={ () => {
										setMenu( false );
										setModal( {
											board: null,
											workflow: wf?.key,
										} );
									} }
								/>
							) }
						</div>
					) : (
						<h2 className="mh-title">{ title }</h2>
					) }
				</div>
			</div>
			<Segmented
				label={ __( 'Views', 'masthead' ) }
				value={ route.view }
				onChange={ ( v ) => {
					if ( v === 'board' ) {
						navigate( 'board', defaultBoard );
					} else if ( v === 'workflows' ) {
						navigate( 'workflows', wf?.key || workflows[ 0 ]?.key );
					} else {
						navigate( v );
					}
				} }
				options={ [
					{
						value: 'board',
						label: __( 'Board', 'masthead' ),
						icon: columns,
					},
					{
						value: 'calendar',
						label: __( 'Calendar', 'masthead' ),
						icon: calendar,
					},
					{
						value: 'workflows',
						label: __( 'Workflows', 'masthead' ),
						icon: settings,
					},
				] }
			/>
			<span className="mh-spacer" />
			{ ( isBoard || route.view === 'calendar' ) && (
				<div className="mh-header__tools">
					<label className="mh-search" htmlFor="mh-search-input">
						<Icon icon={ searchIcon } size={ 18 } />
						<span className="screen-reader-text">
							{ __( 'Search posts', 'masthead' ) }
						</span>
						<input
							id="mh-search-input"
							type="search"
							value={ term }
							placeholder={ __( 'Search posts', 'masthead' ) }
							onChange={ ( e ) => {
								setTerm( e.target.value );
								pushSearch( e.target.value );
							} }
						/>
					</label>
					<AssigneeFilter route={ route } />
				</div>
			) }
			<button
				type="button"
				className="mh-btn mh-btn--primary"
				onClick={ newPost }
			>
				<Icon icon={ plus } size={ 18 } />
				{ __( 'New post', 'masthead' ) }
			</button>
			{ modal && (
				<BoardModal
					board={ modal.board }
					workflow={ modal.workflow }
					onClose={ () => setModal( null ) }
				/>
			) }
		</header>
	);
}
