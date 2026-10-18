import { registerPlugin } from '@wordpress/plugins';
import {
	PluginPrePublishPanel,
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import {
	useDispatch,
	useSelect,
	dispatch as dataDispatch,
	resolveSelect,
} from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import {
	createPortal,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, arrowRight, check, close, columns } from '@wordpress/icons';
import { api, errorMessage } from '../common/api';
import { Avatar, Progress } from '../common/components';
import { words } from '../common/format';
import { NoteText } from '../admin-app/drawer/NoteInput';
import './editor-plugin.scss';

const DATA = window.mastheadEditor || {};
const WF = DATA.workflow;

async function refreshEditorRecord( postType, id ) {
	await dataDispatch( coreStore ).invalidateResolution( 'getEntityRecord', [
		'postType',
		postType,
		id,
	] );
	await resolveSelect( coreStore ).getEntityRecord(
		'postType',
		postType,
		id
	);
}

function useMasthead() {
	const { id, postType, isSaving, isAutosaving, status } = useSelect(
		( select ) => {
			const ed = select( editorStore );
			return {
				id: ed.getCurrentPostId(),
				postType: ed.getCurrentPostType(),
				isSaving: ed.isSavingPost(),
				isAutosaving: ed.isAutosavingPost(),
				status: ed.getCurrentPostAttribute( 'status' ),
			};
		},
		[]
	);
	const { createErrorNotice, createSuccessNotice } =
		useDispatch( 'core/notices' );
	const [ post, setPost ] = useState( null );
	const [ version, setVersion ] = useState( 0 );

	const load = useCallback( () => {
		if ( ! id ) {
			return Promise.resolve();
		}
		return api.post( id ).then(
			( p ) => {
				setPost( p );
				setVersion( ( v ) => v + 1 );
			},
			() => {}
		);
	}, [ id ] );

	useEffect( () => {
		load();
	}, [ load, status ] );

	const wasSaving = useRef( false );
	useEffect( () => {
		if ( wasSaving.current && ! isSaving && ! isAutosaving ) {
			load();
		}
		wasSaving.current = isSaving;
	}, [ isSaving, isAutosaving, load ] );

	const notify = ( message, bad = false ) =>
		( bad ? createErrorNotice : createSuccessNotice )( message, {
			type: 'snackbar',
			id: 'masthead-notice',
		} );

	const move = async ( stage ) => {
		try {
			const res = await api.move( id, stage, post?.rev );
			notify( res.message );
			await refreshEditorRecord( postType, id );
			await load();
		} catch ( e ) {
			notify( errorMessage( e ), true );
			if ( e?.code === 'masthead_conflict' ) {
				await load();
			}
		}
	};

	const patch = async ( data ) => {
		try {
			const res = await api.patch( id, data );
			setPost( res.post );
			setVersion( ( v ) => v + 1 );
		} catch ( e ) {
			notify( errorMessage( e ), true );
		}
	};

	return { id, postType, post, move, patch, version, notify };
}

function Stepper( { post, onMove } ) {
	const current = WF.stages.findIndex( ( s ) => s.id === post.stage );
	return (
		<ol className="mh-steps" aria-label={ __( 'Stages', 'masthead' ) }>
			{ WF.stages.map( ( s, i ) => {
				let state = 'future';
				if ( i < current ) {
					state = 'past';
				} else if ( i === current ) {
					state = 'current';
				}
				return (
					<li key={ s.id } className={ 'mh-step is-' + state }>
						<button
							type="button"
							className="mh-step__btn"
							disabled={ i === current || ! post.can_move }
							aria-current={ i === current ? 'step' : undefined }
							onClick={ () => onMove( s.id ) }
						>
							<span className="mh-step__rail" aria-hidden="true">
								<span
									className="mh-step__dot"
									style={
										state !== 'future'
											? {
													borderColor: s.color,
													background:
														state === 'past'
															? s.color
															: '#fff',
												}
											: undefined
									}
								/>
								{ i < WF.stages.length - 1 && (
									<span
										className="mh-step__line"
										style={
											i < current
												? { background: s.color }
												: undefined
										}
									/>
								) }
							</span>
							<span className="mh-step__name">
								{ s.name }
								{ state === 'current' && (
									<span className="mh-badge mh-badge--accent">
										{ __( 'CURRENT', 'masthead' ) }
									</span>
								) }
							</span>
						</button>
					</li>
				);
			} ) }
		</ol>
	);
}

function Sidebar( { m } ) {
	const { post } = m;
	if ( ! post ) {
		return <p className="mh-ed-pad">{ __( 'Loading…', 'masthead' ) }</p>;
	}
	const i = WF.stages.findIndex( ( s ) => s.id === post.stage );
	const next = WF.stages[ i + 1 ];
	const last = post.thread[ post.thread.length - 1 ];
	const boardUrl =
		DATA.boardUrl +
		( DATA.board
			? '#/board/' + encodeURIComponent( DATA.board ) + '?post=' + post.id
			: '' );

	return (
		<div className="mh-editor">
			<section className="mh-ed-section">
				<h3 className="mh-ed-label">{ __( 'Stage', 'masthead' ) }</h3>
				<Stepper post={ post } onMove={ m.move } />
			</section>
			<section className="mh-ed-section mh-ed-grid">
				<span className="mh-muted">
					{ __( 'Assignee', 'masthead' ) }
				</span>
				<span className="mh-row">
					<Avatar user={ post.author } size={ 22 } />
					{ post.author?.name }
				</span>
				<span className="mh-muted">
					{ __( 'Reviewer', 'masthead' ) }
				</span>
				<span className="mh-row">
					{ post.reviewer ? (
						<>
							<Avatar user={ post.reviewer.user } size={ 22 } />
							{ post.reviewer.user?.name }
						</>
					) : (
						<span className="mh-muted">
							{ __( 'No reviewer', 'masthead' ) }
						</span>
					) }
				</span>
				<label className="mh-muted" htmlFor="mh-ed-due">
					{ __( 'Due', 'masthead' ) }
				</label>
				<input
					id="mh-ed-due"
					type="date"
					className={
						'mh-input mh-input--sm' +
						( post.overdue ? ' is-bad' : '' )
					}
					value={ post.due || '' }
					disabled={ ! post.can_move }
					onChange={ ( e ) =>
						m.patch( { due: e.target.value || '' } )
					}
				/>
				<span className="mh-muted">{ __( 'Length', 'masthead' ) }</span>
				<span>{ words( post.words ) }</span>
			</section>
			{ WF.checklist.length > 0 && (
				<section className="mh-ed-section">
					<div className="mh-row">
						<h3 className="mh-ed-label mh-grow">
							{ __( 'Checklist', 'masthead' ) }
						</h3>
						<span className="mh-muted">
							{
								/* translators: 1: done, 2: total. */ sprintf(
									__( '%1$d of %2$d', 'masthead' ),
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
					{ WF.checklist.map( ( item ) => {
						const done = !! post.checked?.[ item.id ];
						return (
							<button
								key={ item.id }
								type="button"
								role="checkbox"
								aria-checked={ done }
								disabled={ ! post.can_move }
								className={
									'mh-check' + ( done ? ' is-done' : '' )
								}
								onClick={ () =>
									m.patch( {
										checklist: { [ item.id ]: ! done },
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
			<section className="mh-ed-section">
				<h3 className="mh-ed-label">{ __( 'Notes', 'masthead' ) }</h3>
				{ last ? (
					<div className="mh-row mh-row--top">
						<Avatar user={ last.author } size={ 22 } />
						<span className="mh-ed-note">
							<NoteText
								text={ last.text }
								mentions={ last.mentions }
							/>
						</span>
					</div>
				) : (
					<span className="mh-muted">
						{ __( 'No notes yet.', 'masthead' ) }
					</span>
				) }
				<a className="mh-ed-link" href={ boardUrl }>
					{ post.thread.length
						? sprintf(
								/* translators: %d: number of notes. */
								__( 'View all %d notes', 'masthead' ),
								post.thread.length
							)
						: __( 'Add a note on the board', 'masthead' ) }
				</a>
			</section>
			{ next && post.can_move && (
				<div className="mh-ed-foot">
					<button
						type="button"
						className="mh-btn mh-btn--dark mh-btn--block"
						onClick={ () => m.move( next.id ) }
					>
						{
							/* translators: %s: stage. */ sprintf(
								__( 'Move to %s', 'masthead' ),
								next.name
							)
						}
						<Icon icon={ arrowRight } size={ 18 } />
					</button>
				</div>
			) }
		</div>
	);
}

function PrePublish( { m } ) {
	const { isScheduling } = useSelect(
		( select ) => ( {
			isScheduling: select( editorStore ).isEditedPostBeingScheduled(),
		} ),
		[]
	);
	const { lockPostSaving, unlockPostSaving } = useDispatch( editorStore );
	const [ checks, setChecks ] = useState( null );

	const target =
		( isScheduling
			? WF.stages.find( ( s ) => s.wp_status === 'future' )
			: null ) || WF.stages.find( ( s ) => s.wp_status === 'publish' );

	useEffect( () => {
		if ( ! m.id ) {
			return;
		}
		api.checks( m.id, target?.id ).then( setChecks, () =>
			setChecks( null )
		);
	}, [ m.id, target?.id, m.version ] );

	useEffect( () => {
		if ( checks && ! checks.ok ) {
			lockPostSaving( 'masthead' );
		} else {
			unlockPostSaving( 'masthead' );
		}
	}, [ checks, lockPostSaving, unlockPostSaving ] );

	useEffect(
		() => () => unlockPostSaving( 'masthead' ),
		[ unlockPostSaving ]
	);

	const verb =
		target && /sen[dt]/i.test( target.name )
			? __( 'send', 'masthead' )
			: __( 'publish', 'masthead' );

	return (
		<PluginPrePublishPanel
			title={
				/* translators: %s: publish or send. */
				sprintf( __( 'Are you ready to %s?', 'masthead' ), verb )
			}
			initialOpen
			icon={ columns }
		>
			<div className="mh-editor">
				<p className="mh-muted">
					{
						/* translators: %s: workflow name. */ sprintf(
							__(
								'Masthead checks this post against the %s workflow before it goes live.',
								'masthead'
							),
							WF.name
						)
					}
				</p>
				{ ! checks && (
					<p className="mh-muted">
						{ __( 'Checking…', 'masthead' ) }
					</p>
				) }
				{ checks && (
					<>
						<ul className="mh-prechecks">
							{ checks.checks.map( ( c ) => (
								<li
									key={ c.key }
									className={
										'mh-precheck' +
										( c.ok ? ' is-ok' : ' is-bad' )
									}
								>
									<span className="mh-precheck__icon">
										<Icon
											icon={ c.ok ? check : close }
											size={ 18 }
										/>
										<span className="screen-reader-text">
											{ c.ok
												? __( 'Passed', 'masthead' )
												: __( 'Failed', 'masthead' ) }
										</span>
									</span>
									<span className="mh-precheck__text">
										<span className="mh-strong">
											{ c.label }
										</span>
										<span className="mh-precheck__detail">
											{ c.message }
										</span>
									</span>
								</li>
							) ) }
						</ul>
						<p
							className={
								'mh-precheck-summary' +
								( checks.ok ? ' is-ok' : ' is-bad' )
							}
							role="status"
						>
							{ checks.ok
								? sprintf(
										/* translators: %s: stage name. */
										__(
											'All checks pass. This post moves to %s.',
											'masthead'
										),
										checks.target?.name
									)
								: __(
										'Publishing is locked until every check passes.',
										'masthead'
									) }
						</p>
					</>
				) }
			</div>
		</PluginPrePublishPanel>
	);
}

function DocBarStage( { post } ) {
	const [ host, setHost ] = useState( null );
	useEffect( () => {
		const find = () => {
			const bar = document.querySelector( '.editor-document-bar' );
			if ( ! bar ) {
				return;
			}
			let el = bar.querySelector( '.mh-docbar' );
			if ( ! el ) {
				el = document.createElement( 'span' );
				el.className = 'mh-docbar-host';
				const title =
					bar.querySelector( '.editor-document-bar__title' ) ||
					bar.lastElementChild;
				title?.after( el );
			}
			setHost( ( h ) => ( h === el ? h : el ) );
		};
		find();
		const obs = new window.MutationObserver( () => {
			if ( ! document.querySelector( '.mh-docbar-host' ) ) {
				find();
			}
		} );
		obs.observe( document.body, { childList: true, subtree: true } );
		return () => obs.disconnect();
	}, [] );
	const stage = post && WF.stages.find( ( s ) => s.id === post.stage );
	if ( ! host || ! stage ) {
		return null;
	}
	return createPortal(
		<span className="mh-docbar">
			<span
				className="mh-dot"
				style={ { background: stage.color } }
				aria-hidden="true"
			/>
			<span className="screen-reader-text">
				{ __( 'Stage:', 'masthead' ) }{ ' ' }
			</span>
			{ stage.name }
		</span>,
		host
	);
}

function Plugin() {
	const m = useMasthead();
	if ( ! WF ) {
		return null;
	}
	return (
		<>
			<PluginSidebarMoreMenuItem
				target="masthead-sidebar"
				icon={ columns }
			>
				{ __( 'Masthead', 'masthead' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				className="mh-sidebar"
				name="masthead-sidebar"
				title={ __( 'Masthead', 'masthead' ) }
				icon={ columns }
			>
				<Sidebar m={ m } />
			</PluginSidebar>
			<PrePublish m={ m } />
			<DocBarStage post={ m.post } />
		</>
	);
}

registerPlugin( 'masthead', { render: Plugin } );
