import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, arrowRight, close, lock, pencil } from '@wordpress/icons';
import {
	useConstrainedTabbing,
	useFocusOnMount,
	useFocusReturn,
	useMergeRefs,
} from '@wordpress/compose';
import { STORE } from '../../store';
import { api, errorMessage, isNetworkError } from '../../common/api';
import {
	Avatar,
	Progress,
	StagePill,
	useEscape,
	useToast,
} from '../../common/components';
import { ago, firsts, shortDate, words } from '../../common/format';
import { navigate } from '../router';
import NoteInput, { NoteText } from './NoteInput';
import { UserPicker } from '../../common/pickers';

const CARD_KEYS = [
	'id',
	'title',
	'stage',
	'workflow',
	'post_type',
	'is_page',
	'status',
	'date',
	'category',
	'author',
	'due',
	'overdue',
	'checklist',
	'checked',
	'locked_by',
	'rev',
	'can_move',
	'edit_url',
	'excerpt',
	'words',
];

function toCard( detail ) {
	const card = {};
	CARD_KEYS.forEach( ( k ) => ( card[ k ] = detail[ k ] ) );
	card.notes = Array.isArray( detail.thread )
		? detail.thread.length
		: detail.notes;
	return card;
}

export default function Drawer( { id, onClose } ) {
	const toast = useToast();
	const { workflows, boardCard, user } = useSelect(
		( select ) => ( {
			workflows: select( STORE ).getWorkflows(),
			boardCard: select( STORE ).getCard( id ),
			user: select( STORE ).getUser(),
		} ),
		[ id ]
	);
	const { placeCard, moveCard } = useDispatch( STORE );
	const [ post, setPost ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ title, setTitle ] = useState( '' );

	const ref = useMergeRefs( [
		useConstrainedTabbing(),
		useFocusOnMount( 'firstElement' ),
		useFocusReturn(),
	] );
	useEscape( onClose );

	const load = () =>
		api.post( id ).then(
			( p ) => {
				setPost( p );
				setTitle( p.title );
			},
			( e ) => setError( errorMessage( e ) )
		);

	useEffect( () => {
		load();
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const sync = ( detail ) => {
		setPost( detail );
		if ( boardCard ) {
			placeCard( toCard( detail ) );
		}
	};

	const patch = async ( data, success ) => {
		try {
			const res = await api.patch( id, data );
			sync( res.post );
			if ( success ) {
				toast( success( res ) );
			}
			return res;
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
			return null;
		}
	};

	const wf = post ? workflows.find( ( w ) => w.key === post.workflow ) : null;
	const stage = wf?.stages.find( ( s ) => s.id === post?.stage );
	const stageIndex = wf && stage ? wf.stages.indexOf( stage ) : -1;
	const next = wf && stageIndex >= 0 ? wf.stages[ stageIndex + 1 ] : null;

	const moveTo = async ( target ) => {
		const retry = {
			label: __( 'Retry', 'masthead' ),
			onClick: () => moveTo( target ),
		};
		try {
			const res =
				boardCard && boardCard.stage === post.stage
					? await moveCard( id, target )
					: await api.move( id, target, post.rev );
			if ( res ) {
				toast( res.message );
			}
			await load();
		} catch ( e ) {
			if ( e?.code === 'masthead_conflict' ) {
				await load();
				toast(
					__(
						'This post changed while you were looking at it',
						'masthead'
					),
					{ bad: true, action: retry }
				);
			} else if ( isNetworkError( e ) ) {
				toast(
					__(
						'Couldn’t save the move. Your board hasn’t changed',
						'masthead'
					),
					{ bad: true, action: retry }
				);
			} else if ( e?.code === 'masthead_needs_date' ) {
				toast( errorMessage( e ), { bad: true } );
				document.getElementById( 'mh-drawer-due' )?.focus();
				document.getElementById( 'mh-drawer-due' )?.showPicker?.();
			} else {
				toast( errorMessage( e ), { bad: true } );
			}
		}
	};

	const today = window.mastheadBootstrap?.today;
	const live =
		stage &&
		( stage.wp_status === 'future' || stage.wp_status === 'publish' );
	let dueNote = '';
	if ( post?.due ) {
		if ( post.overdue ) {
			dueNote = __( 'Overdue', 'masthead' );
		} else if ( live ) {
			dueNote =
				stage.wp_status === 'publish'
					? __( 'Published', 'masthead' )
					: __( 'Scheduled', 'masthead' );
		} else if ( post.due === today ) {
			dueNote = __( 'Today', 'masthead' );
		}
	}

	return (
		<>
			<div
				className="mh-backdrop mh-backdrop--drawer"
				role="presentation"
				onClick={ onClose }
			/>
			<aside
				className="mh-drawer"
				ref={ ref }
				role="dialog"
				aria-modal="true"
				aria-label={ post?.title || __( 'Post', 'masthead' ) }
			>
				<div className="mh-drawer__head">
					{ stage && <StagePill stage={ stage } /> }
					{ post?.category && (
						<span
							className="mh-card__cat"
							style={ { color: post.category.color } }
						>
							{ post.category.name }
						</span>
					) }
					<span className="mh-spacer" />
					{ post?.edit_url && (
						<a
							className="mh-btn mh-btn--secondary mh-btn--sm"
							href={ post.edit_url }
						>
							<Icon icon={ pencil } size={ 16 } />
							{ __( 'Open in editor', 'masthead' ) }
						</a>
					) }
					<button
						type="button"
						className="mh-icon-btn"
						onClick={ onClose }
						aria-label={ __( 'Close', 'masthead' ) }
					>
						<Icon icon={ close } size={ 20 } />
					</button>
				</div>

				{ ! post && ! error && (
					<p className="mh-drawer__loading">
						{ __( 'Loading…', 'masthead' ) }
					</p>
				) }
				{ error && <p className="mh-drawer__loading">{ error }</p> }

				{ post && wf && (
					<div className="mh-drawer__body">
						{ post.locked_by && (
							<div className="mh-lock-banner">
								<Icon icon={ lock } size={ 18 } />
								<span>
									{ sprintf(
										/* translators: %s: user name. */
										__(
											'%s is editing this post right now. Changes you make here may be overwritten when they save.',
											'masthead'
										),
										post.locked_by.name
									) }
								</span>
							</div>
						) }
						<label
							className="screen-reader-text"
							htmlFor="mh-drawer-title"
						>
							{ __( 'Title', 'masthead' ) }
						</label>
						<textarea
							id="mh-drawer-title"
							className="mh-drawer__title"
							rows={ 2 }
							value={ title }
							disabled={ ! post.can_move }
							onChange={ ( e ) => setTitle( e.target.value ) }
							onBlur={ () => {
								if ( title.trim() && title !== post.title ) {
									patch( { title: title.trim() } );
								}
							} }
						/>

						<div className="mh-fields">
							<label
								className="mh-fields__label"
								htmlFor="mh-drawer-stage"
							>
								{ __( 'Stage', 'masthead' ) }
							</label>
							<select
								id="mh-drawer-stage"
								className="mh-select mh-select--sm"
								value={ post.stage }
								disabled={ ! post.can_move }
								onChange={ ( e ) => moveTo( e.target.value ) }
							>
								{ wf.stages.map( ( s ) => (
									<option key={ s.id } value={ s.id }>
										{ s.name }
									</option>
								) ) }
							</select>

							<label
								className="mh-fields__label"
								htmlFor="mh-drawer-author"
							>
								{ __( 'Assignee', 'masthead' ) }
							</label>
							<UserPicker
								id="mh-drawer-author"
								label={ __( 'Assignee', 'masthead' ) }
								value={ post.author?.id || null }
								initial={ post.author }
								editors
								disabled={
									! post.can_move || ! user.caps.edit_others
								}
								onChange={ ( person ) =>
									person &&
									person.id !== post.author?.id &&
									patch( { author: person.id }, () =>
										sprintf(
											/* translators: %s: name. */
											__( 'Assigned to %s', 'masthead' ),
											person.name
										)
									)
								}
							/>

							<span className="mh-fields__label">
								{ __( 'Reviewer', 'masthead' ) }
							</span>
							<span className="mh-row">
								{ post.reviewer ? (
									<>
										<Avatar
											user={ post.reviewer.user }
											size={ 24 }
										/>
										{ post.reviewer.user?.name }
										<span className="mh-muted">
											{ post.reviewer.now
												? __(
														'reviewing now',
														'masthead'
													)
												: sprintf(
														/* translators: %s: stage name. */
														__(
															'at %s',
															'masthead'
														),
														post.reviewer.stage
													) }
										</span>
									</>
								) : (
									<span className="mh-muted">
										{ __( 'No reviewer', 'masthead' ) }
									</span>
								) }
							</span>

							<label
								className="mh-fields__label"
								htmlFor="mh-drawer-due"
							>
								{ __( 'Due', 'masthead' ) }
							</label>
							<span className="mh-row mh-row--wrap">
								<input
									id="mh-drawer-due"
									type="date"
									className="mh-input mh-input--sm"
									value={ post.due || '' }
									disabled={ ! post.can_move }
									onChange={ ( e ) =>
										patch(
											{ due: e.target.value || '' },
											() =>
												e.target.value
													? sprintf(
															/* translators: %s: date. */
															__(
																'Date set for %s',
																'masthead'
															),
															shortDate(
																e.target.value
															)
														)
													: __(
															'Due date cleared',
															'masthead'
														)
										)
									}
								/>
								{ dueNote && (
									<span
										className={
											'mh-due-note' +
											( post.overdue ? ' is-bad' : '' )
										}
									>
										{ dueNote }
									</span>
								) }
								{ post.due && post.can_move && (
									<button
										type="button"
										className="mh-link-btn"
										onClick={ () => patch( { due: '' } ) }
									>
										{ __( 'Clear', 'masthead' ) }
									</button>
								) }
							</span>

							<span className="mh-fields__label">
								{ __( 'Workflow', 'masthead' ) }
							</span>
							<span className="mh-row">
								<span
									className="mh-square"
									style={ { background: wf.color } }
									aria-hidden="true"
								/>
								{ wf.name } · { wf.singular || post.post_type }
							</span>

							<span className="mh-fields__label mh-fields__label--top">
								{ __( 'On boards', 'masthead' ) }
							</span>
							<span className="mh-chips">
								{ post.boards.map( ( b ) => (
									<button
										key={ b.id }
										type="button"
										className="mh-chip"
										onClick={ () =>
											navigate( 'board', b.id, {
												post: String( id ),
											} )
										}
									>
										{ b.name }
									</button>
								) ) }
								{ ! post.boards.length && (
									<span className="mh-muted">
										{ __( 'None', 'masthead' ) }
									</span>
								) }
							</span>

							<span className="mh-fields__label">
								{ __( 'WordPress', 'masthead' ) }
							</span>
							<span className="mh-mono">
								#{ post.id } · { post.status }
							</span>

							<span className="mh-fields__label">
								{ __( 'Length', 'masthead' ) }
							</span>
							<span>{ words( post.words ) }</span>
						</div>

						{ wf.checklist.length > 0 && (
							<section
								className="mh-section"
								aria-labelledby="mh-drawer-checklist"
							>
								<div className="mh-section__head">
									<h3
										id="mh-drawer-checklist"
										className="mh-section__title"
									>
										{ __( 'Checklist', 'masthead' ) }
									</h3>
									<span className="mh-muted">
										{
											/* translators: 1: done, 2: total. */ sprintf(
												__(
													'%1$d of %2$d',
													'masthead'
												),
												post.checklist.done,
												post.checklist.total
											)
										}
									</span>
								</div>
								<Progress
									done={ post.checklist.done }
									total={ post.checklist.total }
								/>
								{ wf.checklist.map( ( item ) => {
									const done = !! post.checked?.[ item.id ];
									return (
										<button
											key={ item.id }
											type="button"
											role="checkbox"
											aria-checked={ done }
											disabled={ ! post.can_move }
											className={
												'mh-check' +
												( done ? ' is-done' : '' )
											}
											onClick={ () =>
												patch( {
													checklist: {
														[ item.id ]: ! done,
													},
												} )
											}
										>
											<span
												className="mh-check__box"
												aria-hidden="true"
											/>
											<span className="mh-check__label">
												{ item.label }
											</span>
										</button>
									);
								} ) }
							</section>
						) }

						<section
							className="mh-section"
							aria-labelledby="mh-drawer-notes"
						>
							<h3
								id="mh-drawer-notes"
								className="mh-section__title"
							>
								{ __( 'Notes', 'masthead' ) }
							</h3>
							{ post.thread.map( ( n ) => (
								<div key={ n.id } className="mh-note">
									<Avatar user={ n.author } size={ 26 } />
									<div className="mh-note__body">
										<span className="mh-note__meta">
											<b>{ n.author?.name }</b>
											<span className="mh-faint">
												{ ' ' }
												· { ago( n.created_at ) }
											</span>
										</span>
										<span className="mh-note__text">
											<NoteText
												text={ n.text }
												mentions={ n.mentions }
											/>
										</span>
									</div>
								</div>
							) ) }
							<NoteInput
								selfId={ user.id }
								placeholder={ __(
									'Leave a note… type @ to mention',
									'masthead'
								) }
								onSubmit={ async ( text, mentions ) => {
									try {
										const res = await api.addNote(
											id,
											text,
											mentions
										);
										await load();
										if ( boardCard ) {
											placeCard( {
												...boardCard,
												notes: boardCard.notes + 1,
											} );
										}
										if ( res.mentioned.length ) {
											toast(
												sprintf(
													/* translators: %s: names. */
													__(
														'Note posted · %s notified',
														'masthead'
													),
													firsts( res.mentioned )
												)
											);
										}
									} catch ( e ) {
										toast( errorMessage( e ), {
											bad: true,
										} );
										throw e;
									}
								} }
							/>
						</section>

						<section
							className="mh-section"
							aria-labelledby="mh-drawer-activity"
						>
							<h3
								id="mh-drawer-activity"
								className="mh-section__title"
							>
								{ __( 'Activity', 'masthead' ) }
							</h3>
							<ul className="mh-activity">
								{ post.activity.map( ( ev ) => (
									<li
										key={ ev.id }
										className="mh-activity__item"
									>
										<span
											className="mh-activity__dot"
											aria-hidden="true"
										/>
										<span className="mh-activity__text">
											<b>
												{ ev.actor?.name ||
													__(
														'Masthead',
														'masthead'
													) }
											</b>{ ' ' }
											{ ev.text }
										</span>
										<span className="mh-faint">
											{ ago( ev.created_at ) }
										</span>
									</li>
								) ) }
							</ul>
						</section>
					</div>
				) }

				{ post && next && post.can_move && (
					<div className="mh-drawer__foot">
						<button
							type="button"
							className="mh-btn mh-btn--primary mh-btn--block mh-btn--lg"
							onClick={ () => moveTo( next.id ) }
						>
							{
								/* translators: %s: stage name. */ sprintf(
									__( 'Move to %s', 'masthead' ),
									next.name
								)
							}
							<Icon icon={ arrowRight } size={ 18 } />
						</button>
					</div>
				) }
			</aside>
		</>
	);
}
