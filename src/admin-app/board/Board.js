import { useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, inbox, keyboard, search as searchIcon } from '@wordpress/icons';
import {
	DndContext,
	DragOverlay,
	PointerSensor,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import { STORE } from '../../store';
import { api, errorMessage } from '../../common/api';
import { useToast } from '../../common/components';
import { navigate, openPost } from '../router';
import Column from './Column';
import { CardBody } from './Card';
import useMove from './useMove';
import { useUser } from '../../common/users';

export default function Board( { route } ) {
	const toast = useToast();
	const { boards, defaultBoard, workflows, state, user } = useSelect(
		( select ) => {
			const s = select( STORE );
			return {
				boards: s.getBoards(),
				defaultBoard: s.getDefaultBoard(),
				workflows: s.getWorkflows(),
				state: s.getBoardState(),
				user: s.getUser(),
			};
		},
		[]
	);
	const { loadBoard, placeCard } = useDispatch( STORE );
	const move = useMove();
	const [ activeId, setActiveId ] = useState( null );
	const [ overStage, setOverStage ] = useState( null );

	const board =
		boards.find( ( b ) => b.id === route.param ) ||
		boards.find( ( b ) => b.id === defaultBoard );
	const wf = board
		? workflows.find( ( w ) => w.key === board.workflow )
		: null;
	const search = route.query.s || '';
	const who = Number( route.query.who || 0 );
	const whoUser = useUser( who || null );

	useEffect( () => {
		if ( board && route.param !== board.id ) {
			navigate( 'board', board.id, route.query );
		}
	}, [ board?.id, route.param ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( board ) {
			loadBoard( board.id, { search, author: who } ).catch( ( e ) =>
				toast( errorMessage( e ), { bad: true } )
			);
		}
	}, [ board?.id, search, who ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 6 } } )
	);

	const cardsById = useMemo( () => {
		const map = new Map();
		Object.values( state.columns ).forEach( ( col ) =>
			col.cards.forEach( ( c ) => map.set( c.id, c ) )
		);
		return map;
	}, [ state.columns ] );

	if ( ! board || ! wf ) {
		return (
			<div className="mh-empty-card">
				<span>
					{ __( 'This board no longer exists.', 'masthead' ) }
				</span>
			</div>
		);
	}

	const stageName = ( id ) =>
		wf.stages.find( ( s ) => s.id === id )?.name || id;
	const total = Object.values( state.columns ).reduce(
		( n, c ) => n + ( c.total ?? c.cards.length ),
		0
	);
	const loaded =
		state.id === board.id &&
		! state.loading &&
		Object.keys( state.columns ).length > 0;
	const filtered = !! search || !! who;
	const whoName = whoUser?.name;
	const term = [ search, whoName ].filter( Boolean ).join( ' · ' );

	const addPost = async ( stage ) => {
		try {
			const post = await api.createPost( {
				workflow: wf.key,
				stage,
				board: board.id,
			} );
			placeCard( post );
			openPost( route, post.id );
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		}
	};

	const keyMove = async ( card, dir ) => {
		const i = wf.stages.findIndex( ( s ) => s.id === card.stage );
		const target = wf.stages[ i + dir ];
		if ( ! target ) {
			return;
		}
		const focus = () =>
			window.requestAnimationFrame( () =>
				document.querySelector( `[data-card="${ card.id }"]` )?.focus()
			);
		const pending = move( card.id, target.id );
		focus();
		await pending;
		focus();
	};

	const active = activeId ? cardsById.get( activeId ) : null;

	const announcements = {
		onDragStart: ( { active: a } ) =>
			sprintf(
				/* translators: %s: post title. */
				__( 'Picked up %s', 'masthead' ),
				cardsById.get( a.id )?.title || ''
			),
		onDragOver: ( { active: a, over } ) =>
			over
				? sprintf(
						/* translators: 1: post title, 2: stage name. */
						__( '%1$s is over %2$s', 'masthead' ),
						cardsById.get( a.id )?.title || '',
						stageName( over.id )
					)
				: '',
		onDragEnd: ( { active: a, over } ) =>
			over
				? sprintf(
						/* translators: 1: post title, 2: stage name. */
						__( 'Moved %1$s to %2$s', 'masthead' ),
						cardsById.get( a.id )?.title || '',
						stageName( over.id )
					)
				: sprintf(
						/* translators: %s: post title. */
						__( 'Dropped %s', 'masthead' ),
						cardsById.get( a.id )?.title || ''
					),
		onDragCancel: ( { active: a } ) =>
			sprintf(
				/* translators: %s: post title. */
				__( 'Cancelled moving %s', 'masthead' ),
				cardsById.get( a.id )?.title || ''
			),
	};

	return (
		<div className="mh-board">
			{ loaded && total === 0 && filtered && (
				<div className="mh-banner">
					<Icon icon={ searchIcon } size={ 18 } />
					<span className="mh-banner__text">
						{
							/* translators: %s: search term. */ sprintf(
								__(
									'No posts on this board match “%s”',
									'masthead'
								),
								term
							)
						}
					</span>
					<button
						type="button"
						className="mh-btn mh-btn--secondary mh-btn--sm"
						onClick={ () => navigate( 'board', board.id ) }
					>
						{ __( 'Clear filters', 'masthead' ) }
					</button>
				</div>
			) }
			{ loaded && total === 0 && ! filtered && (
				<div className="mh-empty-board">
					<span className="mh-empty-board__icon" aria-hidden="true">
						<Icon icon={ inbox } size={ 22 } />
					</span>
					<div className="mh-empty-board__text">
						<span className="mh-empty-board__title">
							{
								/* translators: %s: board name. */ sprintf(
									__( '%s is empty', 'masthead' ),
									board.name
								)
							}
						</span>
						<span className="mh-muted">
							{ __(
								'Posts that match this board’s filters show up here. Add one to get started.',
								'masthead'
							) }
						</span>
					</div>
					<button
						type="button"
						className="mh-btn mh-btn--primary"
						onClick={ () => addPost( wf.stages[ 0 ]?.id ) }
					>
						{ __( 'Add the first post', 'masthead' ) }
					</button>
				</div>
			) }
			{ state.error && (
				<div className="mh-banner mh-banner--error">
					<span className="mh-banner__text">
						{ errorMessage( state.error ) }
					</span>
					<button
						type="button"
						className="mh-btn mh-btn--secondary mh-btn--sm"
						onClick={ () =>
							loadBoard( board.id, { search, author: who } )
						}
					>
						{ __( 'Retry', 'masthead' ) }
					</button>
				</div>
			) }
			<DndContext
				sensors={ sensors }
				accessibility={ {
					announcements,
					screenReaderInstructions: {
						draggable: __(
							'Press Enter to open this post. Use the left and right arrow keys to move it to the previous or next stage.',
							'masthead'
						),
					},
				} }
				onDragStart={ ( { active: a } ) => setActiveId( a.id ) }
				onDragOver={ ( { over } ) => setOverStage( over?.id ?? null ) }
				onDragCancel={ () => {
					setActiveId( null );
					setOverStage( null );
				} }
				onDragEnd={ ( { active: a, over } ) => {
					setActiveId( null );
					setOverStage( null );
					if ( over && cardsById.get( a.id )?.stage !== over.id ) {
						move( a.id, over.id );
					}
				} }
			>
				<div className="mh-columns" aria-busy={ state.loading }>
					{ wf.stages.map( ( stage ) => (
						<Column
							key={ stage.id }
							stage={ stage }
							column={ state.columns[ stage.id ] }
							count={
								state.counts[ stage.id ] ?? stage.count ?? 0
							}
							loading={
								state.loading && ! state.columns[ stage.id ]
							}
							isOver={
								overStage === stage.id &&
								active &&
								active.stage !== stage.id
							}
							activeId={ activeId }
							canAdd={ user.caps.view }
							onAdd={ () => addPost( stage.id ) }
							onOpen={ ( id ) => openPost( route, id ) }
							onKeyMove={ keyMove }
						/>
					) ) }
				</div>
				<DragOverlay dropAnimation={ null }>
					{ active ? <CardBody card={ active } overlay /> : null }
				</DragOverlay>
			</DndContext>
			<p className="mh-hint">
				<Icon icon={ keyboard } size={ 16 } />
				{ __(
					'Tab to a card, then ← → to move it between stages. Enter opens it.',
					'masthead'
				) }
			</p>
		</div>
	);
}
