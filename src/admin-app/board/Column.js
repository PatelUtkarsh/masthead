import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, plus } from '@wordpress/icons';
import { useDroppable } from '@dnd-kit/core';
import { STORE } from '../../store';
import Card from './Card';

export default function Column( {
	stage,
	column,
	count,
	loading,
	isOver,
	activeId,
	canAdd,
	onAdd,
	onOpen,
	onKeyMove,
} ) {
	const { setNodeRef } = useDroppable( { id: stage.id } );
	const { loadMore } = useDispatch( STORE );
	const [ busy, setBusy ] = useState( false );

	const cards = column?.cards || [];
	// Filtered boards don't count rows on the server: show "20+" until the end is reached.
	const total = column?.total ?? cards.length;
	const totalLabel =
		column?.total === null && column?.has_more
			? `${ cards.length }+`
			: String( total );

	const more = useCallback( async () => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		try {
			await loadMore( stage.id );
		} finally {
			setBusy( false );
		}
	}, [ busy, loadMore, stage.id ] );

	// Lazy-load the next page when the end of the column scrolls into view.
	const sentinel = useRef();
	useEffect( () => {
		const el = sentinel.current;
		if ( ! el || ! column?.has_more || ! window.IntersectionObserver ) {
			return;
		}
		const io = new window.IntersectionObserver(
			( entries ) => entries.some( ( e ) => e.isIntersecting ) && more(),
			{ rootMargin: '200px' }
		);
		io.observe( el );
		return () => io.disconnect();
	}, [ column?.has_more, more ] );
	const hasLimit = stage.limit > 0;
	const over = hasLimit && count > stage.limit;
	const full = hasLimit && count >= stage.limit;
	const label = hasLimit ? `${ count } / ${ stage.limit }` : totalLabel;
	const headingId = `mh-col-${ stage.id }`;

	return (
		<section
			ref={ setNodeRef }
			className={ 'mh-column' + ( isOver ? ' is-over' : '' ) }
			style={ isOver ? { borderColor: stage.color } : undefined }
			aria-labelledby={ headingId }
		>
			<div className="mh-column__head">
				<span
					className="mh-dot"
					style={ { background: stage.color } }
					aria-hidden="true"
				/>
				<h3 className="mh-column__name" id={ headingId }>
					{ stage.name }
				</h3>
				<span
					className={
						'mh-count' +
						( over ? ' is-over' : '' ) +
						( full && ! over ? ' is-full' : '' )
					}
					title={
						hasLimit
							? sprintf(
									/* translators: 1: posts, 2: limit. */
									__( '%1$d of %2$d allowed', 'masthead' ),
									count,
									stage.limit
								)
							: undefined
					}
				>
					{ label }
				</span>
				<span className="mh-spacer" />
				{ canAdd && (
					<button
						type="button"
						className="mh-icon-btn mh-icon-btn--sm"
						onClick={ onAdd }
						aria-label={ sprintf(
							/* translators: %s: stage name. */
							__( 'Add post to %s', 'masthead' ),
							stage.name
						) }
						title={ __( 'Add post', 'masthead' ) }
					>
						<Icon icon={ plus } size={ 18 } />
					</button>
				) }
			</div>
			<div className="mh-column__cards">
				{ isOver && (
					<div
						className="mh-drop"
						style={ { borderColor: stage.color } }
						aria-hidden="true"
					/>
				) }
				{ cards.map( ( card ) => (
					<Card
						key={ card.id }
						card={ card }
						dragging={ activeId === card.id }
						onOpen={ () => onOpen( card.id ) }
						onKeyMove={ onKeyMove }
					/>
				) ) }
				{ loading && (
					<div className="mh-skeleton" aria-hidden="true" />
				) }
				{ ! loading && cards.length === 0 && ! isOver && (
					<div className="mh-column__empty">
						{ __( 'Drop a post here', 'masthead' ) }
					</div>
				) }
				{ column?.has_more && (
					<button
						ref={ sentinel }
						type="button"
						className="mh-btn mh-btn--ghost mh-btn--block"
						disabled={ busy }
						onClick={ more }
					>
						{ busy
							? __( 'Loading…', 'masthead' )
							: __( 'Show more', 'masthead' ) }
					</button>
				) }
			</div>
		</section>
	);
}
