import { useEffect, useMemo, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import { Icon, chevronLeft, chevronRight } from '@wordpress/icons';
import {
	DndContext,
	DragOverlay,
	PointerSensor,
	useDraggable,
	useDroppable,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import { STORE } from '../../store';
import { api, errorMessage } from '../../common/api';
import { useToast } from '../../common/components';
import { parseYmd, shortDate, toYmd } from '../../common/format';
import { openPost } from '../router';

const WEEKDAYS = [ 1, 2, 3, 4, 5, 6, 0 ];

function monthKey( date ) {
	return `${ date.getFullYear() }-${ String( date.getMonth() + 1 ).padStart( 2, '0' ) }`;
}

function Chip( { post, color, onOpen } ) {
	const { attributes, listeners, setNodeRef, isDragging } = useDraggable( {
		id: post.id,
		disabled: ! post.can_move,
	} );
	return (
		<button
			type="button"
			ref={ setNodeRef }
			{ ...attributes }
			{ ...listeners }
			className={ 'mh-cal-chip' + ( isDragging ? ' is-dragging' : '' ) }
			title={ post.title }
			onClick={ onOpen }
		>
			<span
				className="mh-dot mh-dot--sm"
				style={ { background: color } }
				aria-hidden="true"
			/>
			<span className="mh-cal-chip__title">{ post.title }</span>
		</button>
	);
}

function Day( { ymd, inMonth, isToday, children } ) {
	const { setNodeRef, isOver } = useDroppable( { id: 'day:' + ymd } );
	const date = parseYmd( ymd );
	return (
		<div
			ref={ setNodeRef }
			className={
				'mh-cal-day' +
				( inMonth ? '' : ' is-out' ) +
				( isOver ? ' is-over' : '' )
			}
			aria-label={ dateI18n( 'l, F j', date ) }
			role="group"
		>
			<span
				className={ 'mh-cal-day__num' + ( isToday ? ' is-today' : '' ) }
			>
				{ date.getDate() }
			</span>
			{ children }
		</div>
	);
}

function UnscheduledCard( { post, stage, onOpen } ) {
	const { attributes, listeners, setNodeRef, isDragging } = useDraggable( {
		id: post.id,
		disabled: ! post.can_move,
	} );
	return (
		<button
			type="button"
			ref={ setNodeRef }
			{ ...attributes }
			{ ...listeners }
			className={ 'mh-unsched' + ( isDragging ? ' is-dragging' : '' ) }
			onClick={ onOpen }
		>
			<span className="mh-unsched__title">{ post.title }</span>
			<span className="mh-muted mh-row">
				<span
					className="mh-dot mh-dot--sm"
					style={ { background: stage?.color } }
					aria-hidden="true"
				/>
				{ stage?.name } · { post.author?.name }
			</span>
		</button>
	);
}

export default function Calendar( { route } ) {
	const toast = useToast();
	const { workflows, boot } = useSelect(
		( select ) => ( {
			workflows: select( STORE ).getWorkflows(),
			boot: select( STORE ).getBoot(),
		} ),
		[]
	);
	const [ month, setMonth ] = useState( () => parseYmd( boot.today ) );
	const [ hidden, setHidden ] = useState( {} );
	const [ data, setData ] = useState( {
		posts: [],
		unscheduled: [],
		unscheduled_more: false,
		posts_more: false,
		page: 1,
	} );
	const [ loadingMore, setLoadingMore ] = useState( false );
	const [ active, setActive ] = useState( null );
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 6 } } )
	);

	const shown = workflows
		.filter( ( w ) => ! hidden[ w.key ] )
		.map( ( w ) => w.key );
	const search = route.query.s || '';
	const who = Number( route.query.who || 0 );

	const monthId = monthKey( month );
	const shownKey = shown.join( ',' );

	const load = () =>
		api
			.calendar( monthId, shown.length ? shown : [ '__none__' ], {
				search: search || undefined,
				author: who || undefined,
			} )
			.then( setData, ( e ) =>
				toast( errorMessage( e ), { bad: true } )
			);

	const query = () => ( {
		search: search || undefined,
		author: who || undefined,
	} );

	const moreUnscheduled = async () => {
		setLoadingMore( true );
		try {
			const res = await api.calendar(
				monthId,
				shown.length ? shown : [ '__none__' ],
				{
					...query(),
					only: 'unscheduled',
					page: data.page + 1,
				}
			);
			setData( ( d ) => ( {
				...d,
				page: res.page,
				unscheduled_more: res.unscheduled_more,
				unscheduled: [
					...d.unscheduled,
					...res.unscheduled.filter(
						( p ) => ! d.unscheduled.some( ( x ) => x.id === p.id )
					),
				],
			} ) );
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		} finally {
			setLoadingMore( false );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `load` is recreated each render; the keys below cover its inputs.
	}, [ monthId, shownKey, search, who, route.query.post ] );

	const wfOf = ( post ) => workflows.find( ( w ) => w.key === post.workflow );
	const stageOf = ( post ) =>
		wfOf( post )?.stages.find( ( s ) => s.id === post.stage );

	const cells = useMemo( () => {
		const first = new Date( month.getFullYear(), month.getMonth(), 1, 12 );
		const offset = ( first.getDay() + 6 ) % 7;
		const start = new Date( first );
		start.setDate( first.getDate() - offset );
		const last = new Date(
			month.getFullYear(),
			month.getMonth() + 1,
			0,
			12
		);
		const count = Math.ceil( ( offset + last.getDate() ) / 7 ) * 7;
		return Array.from( { length: count }, ( _, i ) => {
			const d = new Date( start );
			d.setDate( start.getDate() + i );
			return {
				ymd: toYmd( d ),
				inMonth: d.getMonth() === month.getMonth(),
			};
		} );
	}, [ month ] );

	const byDay = useMemo( () => {
		const map = {};
		data.posts.forEach( ( p ) => {
			( map[ p.due ] = map[ p.due ] || [] ).push( p );
		} );
		return map;
	}, [ data.posts ] );

	const all = [ ...data.posts, ...data.unscheduled ];
	const activePost = active ? all.find( ( p ) => p.id === active ) : null;

	const drop = async ( id, ymd ) => {
		const post = all.find( ( p ) => p.id === id );
		if ( ! post || post.due === ymd ) {
			return;
		}
		try {
			const res = await api.patch( id, {
				due: ymd,
				auto_schedule: true,
			} );
			const label = shortDate( ymd );
			if ( res.scheduled ) {
				/* translators: %s: date. */
				toast( sprintf( __( 'Scheduled for %s', 'masthead' ), label ) );
			} else if ( res.blocked ) {
				toast(
					sprintf(
						/* translators: 1: date, 2: reason. */
						__(
							'Date set for %1$s · not scheduled: %2$s',
							'masthead'
						),
						label,
						res.blocked
					),
					{ bad: true }
				);
			} else {
				/* translators: %s: date. */
				toast( sprintf( __( 'Date set for %s', 'masthead' ), label ) );
			}
			load();
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		}
	};

	const step = ( n ) =>
		setMonth(
			new Date( month.getFullYear(), month.getMonth() + n, 1, 12 )
		);

	return (
		<DndContext
			sensors={ sensors }
			onDragStart={ ( { active: a } ) => setActive( a.id ) }
			onDragCancel={ () => setActive( null ) }
			onDragEnd={ ( { active: a, over } ) => {
				setActive( null );
				if ( over && String( over.id ).startsWith( 'day:' ) ) {
					drop( a.id, String( over.id ).slice( 4 ) );
				}
			} }
			accessibility={ {
				announcements: {
					onDragStart: ( { active: a } ) =>
						sprintf(
							/* translators: %s: post title. */
							__( 'Picked up %s', 'masthead' ),
							all.find( ( p ) => p.id === a.id )?.title || ''
						),
					onDragOver: ( { over } ) =>
						over
							? dateI18n(
									'l, F j',
									parseYmd( String( over.id ).slice( 4 ) )
								)
							: '',
					onDragEnd: ( { active: a, over } ) =>
						over
							? sprintf(
									/* translators: 1: post title, 2: date. */
									__( 'Dropped %1$s on %2$s', 'masthead' ),
									all.find( ( p ) => p.id === a.id )?.title ||
										'',
									shortDate( String( over.id ).slice( 4 ) )
								)
							: '',
					onDragCancel: () => __( 'Cancelled', 'masthead' ),
				},
				screenReaderInstructions: {
					draggable: __(
						'To set a date with the keyboard, open the post and use its Due field.',
						'masthead'
					),
				},
			} }
		>
			<div className="mh-calendar">
				<div className="mh-cal">
					<div className="mh-cal__head">
						<h3 className="mh-cal__month">
							{ dateI18n( 'F Y', month ) }
						</h3>
						<div
							className="mh-cal__legend"
							role="group"
							aria-label={ __( 'Workflows', 'masthead' ) }
						>
							{ workflows.map( ( w ) => (
								<button
									key={ w.key }
									type="button"
									aria-pressed={ ! hidden[ w.key ] }
									className={
										'mh-legend' +
										( hidden[ w.key ] ? ' is-off' : '' )
									}
									onClick={ () =>
										setHidden( {
											...hidden,
											[ w.key ]: ! hidden[ w.key ],
										} )
									}
								>
									<span
										className="mh-square"
										style={ { background: w.color } }
										aria-hidden="true"
									/>
									{ w.name }
								</button>
							) ) }
						</div>
						<span className="mh-spacer" />
						<button
							type="button"
							className="mh-icon-btn mh-icon-btn--outline"
							onClick={ () => step( -1 ) }
							aria-label={ __( 'Previous month', 'masthead' ) }
						>
							<Icon icon={ chevronLeft } size={ 18 } />
						</button>
						<button
							type="button"
							className="mh-btn mh-btn--secondary mh-btn--sm"
							onClick={ () => setMonth( parseYmd( boot.today ) ) }
						>
							{ __( 'Today', 'masthead' ) }
						</button>
						<button
							type="button"
							className="mh-icon-btn mh-icon-btn--outline"
							onClick={ () => step( 1 ) }
							aria-label={ __( 'Next month', 'masthead' ) }
						>
							<Icon icon={ chevronRight } size={ 18 } />
						</button>
					</div>
					{ data.posts_more && (
						<p className="mh-notice-inline mh-cal__limit">
							{ __(
								'This month has more posts than the calendar shows. Search or filter by person to see the rest.',
								'masthead'
							) }
						</p>
					) }
					<div className="mh-cal__weekdays" aria-hidden="true">
						{ WEEKDAYS.map( ( d ) => (
							<span key={ d }>
								{ dateI18n(
									'D',
									new Date( 2024, 0, 7 + d, 12 )
								) }
							</span>
						) ) }
					</div>
					<div className="mh-cal__grid">
						{ cells.map( ( c ) => (
							<Day
								key={ c.ymd }
								ymd={ c.ymd }
								inMonth={ c.inMonth }
								isToday={ c.ymd === boot.today }
							>
								{ ( byDay[ c.ymd ] || [] ).map( ( p ) => (
									<Chip
										key={ p.id }
										post={ p }
										color={ wfOf( p )?.color }
										onOpen={ () => openPost( route, p.id ) }
									/>
								) ) }
							</Day>
						) ) }
					</div>
				</div>
				<aside
					className="mh-unsched-panel"
					aria-labelledby="mh-unsched-title"
				>
					<div className="mh-row">
						<h3 id="mh-unsched-title" className="mh-section__title">
							{ __( 'Unscheduled', 'masthead' ) }
						</h3>
						<span className="mh-count">
							{ data.unscheduled.length }
							{ data.unscheduled_more ? '+' : '' }
						</span>
					</div>
					<p className="mh-muted">
						{ __(
							'Drag a post onto a day to set its publish date.',
							'masthead'
						) }
					</p>
					{ data.unscheduled.map( ( p ) => (
						<UnscheduledCard
							key={ p.id }
							post={ p }
							stage={ stageOf( p ) }
							onOpen={ () => openPost( route, p.id ) }
						/>
					) ) }
					{ data.unscheduled_more && (
						<button
							type="button"
							className="mh-btn mh-btn--ghost"
							disabled={ loadingMore }
							onClick={ moreUnscheduled }
						>
							{ loadingMore
								? __( 'Loading…', 'masthead' )
								: __( 'Show more', 'masthead' ) }
						</button>
					) }
				</aside>
			</div>
			<DragOverlay dropAnimation={ null }>
				{ activePost ? (
					<div className="mh-cal-chip is-overlay">
						<span
							className="mh-dot mh-dot--sm"
							style={ { background: wfOf( activePost )?.color } }
						/>
						<span className="mh-cal-chip__title">
							{ activePost.title }
						</span>
					</div>
				) : null }
			</DragOverlay>
		</DndContext>
	);
}
