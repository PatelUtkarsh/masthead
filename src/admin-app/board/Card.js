import { __, sprintf } from '@wordpress/i18n';
import { Icon, calendar, comment, lock, published } from '@wordpress/icons';
import { useDraggable } from '@dnd-kit/core';
import { Avatar } from '../../common/components';
import { dueLabel } from '../../common/format';

/**
 * Visual card content (also used in the drag overlay).
 *
 * @param {Object}  props
 * @param {Object}  props.card    Card payload.
 * @param {boolean} props.overlay Rendered in the drag overlay.
 */
export function CardBody( { card, overlay = false } ) {
	const complete =
		card.checklist.total > 0 &&
		card.checklist.done === card.checklist.total;
	return (
		<div className={ 'mh-card__body' + ( overlay ? ' is-overlay' : '' ) }>
			<div className="mh-card__top">
				{ card.category && (
					<span
						className="mh-card__cat"
						style={ { color: card.category.color } }
					>
						{ card.category.name }
					</span>
				) }
				{ card.is_page && (
					<span className="mh-badge">
						{ __( 'PAGE', 'masthead' ) }
					</span>
				) }
				{ card.locked_by && (
					<span
						className="mh-card__lock"
						title={ sprintf(
							/* translators: %s: user name. */
							__( '%s is editing', 'masthead' ),
							card.locked_by.name
						) }
					>
						<Icon icon={ lock } size={ 14 } />
						{
							/* translators: %s: user name. */ sprintf(
								__( '%s is editing', 'masthead' ),
								card.locked_by.name
							)
						}
					</span>
				) }
				{ ! card.can_move && ! card.locked_by && (
					<span
						className="mh-card__readonly"
						title={ __( 'You can’t move this post', 'masthead' ) }
					>
						<Icon icon={ lock } size={ 14 } />
						<span className="screen-reader-text">
							{ __( 'Read only', 'masthead' ) }
						</span>
					</span>
				) }
			</div>
			<div className="mh-card__title">
				{ card.title || __( '(no title)', 'masthead' ) }
			</div>
			<div className="mh-card__meta">
				<span
					className={ 'mh-meta' + ( card.overdue ? ' is-bad' : '' ) }
				>
					<Icon icon={ calendar } size={ 15 } />
					{ dueLabel( card ) }
				</span>
				{ card.checklist.total > 0 && (
					<span
						className={ 'mh-meta' + ( complete ? ' is-good' : '' ) }
					>
						<Icon icon={ published } size={ 15 } />
						<span className="screen-reader-text">
							{ __( 'Checklist', 'masthead' ) }
						</span>
						{ card.checklist.done }/{ card.checklist.total }
					</span>
				) }
				{ card.notes > 0 && (
					<span className="mh-meta">
						<Icon icon={ comment } size={ 15 } />
						<span className="screen-reader-text">
							{ __( 'Notes', 'masthead' ) }
						</span>
						{ card.notes }
					</span>
				) }
				<span className="mh-spacer" />
				<Avatar user={ card.author } size={ 22 } />
			</div>
		</div>
	);
}

/**
 * Focusable, draggable card. ← → moves it, Enter/Space opens it.
 *
 * @param {Object}                                    props
 * @param {Object}                                    props.card      Card.
 * @param {boolean}                                   props.dragging  Being dragged.
 * @param {() => void}                                props.onOpen    Open the drawer.
 * @param {(card: Object, direction: number) => void} props.onKeyMove Move left (-1) or right (+1).
 */
export default function Card( { card, dragging, onOpen, onKeyMove } ) {
	const { attributes, listeners, setNodeRef } = useDraggable( {
		id: card.id,
		disabled: ! card.can_move,
	} );

	const onKeyDown = ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			onOpen();
			return;
		}
		if (
			( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) &&
			card.can_move
		) {
			e.preventDefault();
			onKeyMove( card, e.key === 'ArrowLeft' ? -1 : 1 );
		}
	};

	return (
		<div
			ref={ setNodeRef }
			{ ...attributes }
			{ ...listeners }
			role="button"
			tabIndex={ 0 }
			data-card={ card.id }
			aria-roledescription={ __( 'post card', 'masthead' ) }
			aria-label={ card.title }
			aria-disabled={ undefined }
			title={
				card.can_move
					? undefined
					: __( 'You can’t move this post', 'masthead' )
			}
			className={
				'mh-card' +
				( dragging ? ' is-dragging' : '' ) +
				( card.can_move ? '' : ' is-readonly' )
			}
			onClick={ onOpen }
			onKeyDown={ onKeyDown }
		>
			<CardBody card={ card } />
		</div>
	);
}
