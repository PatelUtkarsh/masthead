import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, chevronRight, close, plus } from '@wordpress/icons';
import {
	DndContext,
	KeyboardSensor,
	PointerSensor,
	closestCenter,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	SortableContext,
	arrayMove,
	sortableKeyboardCoordinates,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { STORE } from '../../store';
import { api, errorMessage } from '../../common/api';
import { Toggle, useToast } from '../../common/components';
import { navigate } from '../router';
import StageRow from './StageRow';
import NewWorkflowModal from './NewWorkflowModal';
import BoardModal from '../board/BoardModal';

const clone = ( v ) => JSON.parse( JSON.stringify( v ) );
const strip = ( wf ) => {
	const out = clone( wf );
	// Drop read-only fields the server adds (count, reviewer summary).
	out.stages = out.stages.map(
		( { count, reviewer_user: reviewerUser, ...s } ) => s
	);
	return out;
};
const same = ( a, b ) =>
	JSON.stringify( strip( a ) ) === JSON.stringify( strip( b ) );

export default function Workflows( { route } ) {
	const toast = useToast();
	const { workflows, boards, boot, rules, user } = useSelect( ( select ) => {
		const s = select( STORE );
		return {
			workflows: s.getWorkflows(),
			boards: s.getBoards(),
			boot: s.getBoot(),
			rules: s.getRules(),
			user: s.getUser(),
		};
	}, [] );
	const { patchBoot } = useDispatch( STORE );
	const readOnly = ! user.caps.manage_workflows;

	const key = workflows.some( ( w ) => w.key === route.param )
		? route.param
		: workflows[ 0 ]?.key;
	const saved = workflows.find( ( w ) => w.key === key );
	const [ drafts, setDrafts ] = useState( {} );
	const [ openStage, setOpenStage ] = useState( null );
	const [ newCheck, setNewCheck ] = useState( '' );
	const [ modal, setModal ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const draft = drafts[ key ] || saved;
	const dirtyKeys = workflows
		.filter( ( w ) => drafts[ w.key ] && ! same( drafts[ w.key ], w ) )
		.map( ( w ) => w.key );
	const dirty = dirtyKeys.length > 0;

	useEffect( () => {
		if ( ! dirty ) {
			return;
		}
		const guard = ( e ) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', guard );
		return () => window.removeEventListener( 'beforeunload', guard );
	}, [ dirty ] );

	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 4 } } ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);

	if ( ! saved ) {
		return null;
	}

	const edit = ( fn ) =>
		setDrafts( ( d ) => ( {
			...d,
			[ key ]: fn( clone( d[ key ] || saved ) ),
		} ) );
	const editStage = ( id, patch ) =>
		edit( ( w ) => ( {
			...w,
			stages: w.stages.map( ( s ) =>
				s.id === id ? { ...s, ...patch } : s
			),
		} ) );
	const setWorkflow = ( wf ) =>
		patchBoot( {
			workflows: workflows.map( ( w ) => ( w.key === wf.key ? wf : w ) ),
		} );

	const save = async () => {
		setSaving( true );
		try {
			for ( const k of dirtyKeys ) {
				const res = await api.saveWorkflow( k, strip( drafts[ k ] ) );
				setWorkflow( res.workflow );
				res.warnings.forEach( ( w ) => toast( w, { bad: true } ) );
				if ( ! res.warnings.length ) {
					toast( __( 'Workflow saved', 'masthead' ) );
				}
			}
			setDrafts( {} );
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		} finally {
			setSaving( false );
		}
	};

	const addStage = () =>
		edit( ( w ) => {
			const used = new Set( w.stages.map( ( s ) => s.id ) );
			let n = 1;
			while ( used.has( 'stage-' + n ) ) {
				n++;
			}
			const stage = {
				id: 'stage-' + n,
				name: __( 'New stage', 'masthead' ),
				color: boot.palette[
					( w.stages.length + 1 ) % boot.palette.length
				],
				wp_status: 'draft',
				limit: 0,
				limit_mode: 'warn',
				notify: 'none',
				reviewer: null,
				required: false,
				approval: false,
			};
			const stages = [ ...w.stages ];
			stages.splice( Math.max( 0, stages.length - 1 ), 0, stage );
			setOpenStage( stage.id );
			return { ...w, stages };
		} );

	const deleteStage = async ( stage ) => {
		if ( ! saved.stages.some( ( s ) => s.id === stage.id ) ) {
			edit( ( w ) => ( {
				...w,
				stages: w.stages.filter( ( s ) => s.id !== stage.id ),
			} ) );
			return;
		}
		const index = draft.stages.findIndex( ( s ) => s.id === stage.id );
		try {
			const res = await api.deleteStage( key, stage.id );
			setWorkflow( res.workflow );
			edit( ( w ) => ( {
				...w,
				stages: w.stages.filter( ( s ) => s.id !== stage.id ),
			} ) );
			const prev = draft.stages[ index - 1 ] || draft.stages[ index + 1 ];
			const msg = res.moved
				? sprintf(
						/* translators: 1: stage, 2: number of posts, 3: stage. */
						_n(
							'Deleted %1$s · %2$d post moved to %3$s',
							'Deleted %1$s · %2$d posts moved to %3$s',
							res.moved,
							'masthead'
						),
						stage.name,
						res.moved,
						prev?.name
					)
				: /* translators: %s: stage. */
					sprintf( __( 'Deleted %s', 'masthead' ), stage.name );
			toast( msg, {
				action: {
					label: __( 'Undo', 'masthead' ),
					onClick: async () => {
						try {
							const undone = await api.undo( res.token );
							setWorkflow( undone.workflow );
							edit( ( w ) => {
								const stages = w.stages.filter(
									( s ) => s.id !== stage.id
								);
								stages.splice( index, 0, stage );
								return { ...w, stages };
							} );
							toast(
								sprintf(
									/* translators: %s: stage. */
									__( 'Restored %s', 'masthead' ),
									stage.name
								)
							);
						} catch ( e ) {
							toast( errorMessage( e ), { bad: true } );
						}
					},
				},
			} );
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		}
	};

	const setRule = async ( patch ) => {
		const next = { ...rules, ...patch };
		patchBoot( { rules: next } );
		try {
			patchBoot( { rules: await api.saveRules( patch ) } );
		} catch ( e ) {
			patchBoot( { rules } );
			toast( errorMessage( e ), { bad: true } );
		}
	};

	const addCheck = () => {
		const label = newCheck.trim();
		if ( ! label ) {
			return;
		}
		edit( ( w ) => ( {
			...w,
			checklist: [
				...w.checklist,
				{
					id: 'c_' + Math.random().toString( 36 ).slice( 2, 10 ),
					label,
				},
			],
		} ) );
		setNewCheck( '' );
	};

	const counts = Object.fromEntries(
		saved.stages.map( ( s ) => [ s.id, s.count || 0 ] )
	);
	const wfBoards = boards.filter( ( b ) => b.workflow === key );

	return (
		<div className="mh-workflows">
			<div className="mh-wf-tabs-wrap">
				<div
					className="mh-wf-tabs"
					role="tablist"
					aria-label={ __( 'Workflows', 'masthead' ) }
				>
					{ workflows.map( ( w ) => (
						<button
							key={ w.key }
							type="button"
							role="tab"
							aria-selected={ w.key === key }
							className={
								'mh-wf-tab' +
								( w.key === key ? ' is-active' : '' )
							}
							onClick={ () => navigate( 'workflows', w.key ) }
						>
							<span
								className="mh-square mh-square--lg"
								style={ { background: w.color } }
								aria-hidden="true"
							/>
							<span className="mh-wf-tab__text">
								<span className="mh-strong">
									{ w.name }
									{ dirtyKeys.includes( w.key ) && (
										<span
											className="mh-dirty-dot"
											title={ __(
												'Unsaved changes',
												'masthead'
											) }
										/>
									) }
								</span>
								<span className="mh-muted">
									{
										/* translators: 1: plural post type label, 2: stage count. */ sprintf(
											_n(
												'%1$s · %2$d stage',
												'%1$s · %2$d stages',
												w.stages.length,
												'masthead'
											),
											w.plural || w.name,
											w.stages.length
										)
									}
								</span>
							</span>
						</button>
					) ) }
					{ ! readOnly && (
						<button
							type="button"
							className="mh-wf-tab mh-wf-tab--add"
							onClick={ () => setModal( 'workflow' ) }
						>
							<Icon icon={ plus } size={ 18 } />
							{ __( 'Add workflow', 'masthead' ) }
						</button>
					) }
				</div>
				<p className="mh-muted">
					{ __(
						'Each post type has one workflow. Boards are saved views on top of a workflow, so one post can appear on several boards.',
						'masthead'
					) }
				</p>
				{ readOnly && (
					<p className="mh-notice-inline">
						{ __(
							'Only Administrators can change workflows.',
							'masthead'
						) }
					</p>
				) }
			</div>

			<div className="mh-panel mh-stages">
				<div className="mh-panel__head">
					<div className="mh-panel__titles">
						<h3 className="mh-panel__title">
							{
								/* translators: %s: workflow name. */ sprintf(
									__( '%s stages', 'masthead' ),
									draft.name
								)
							}
						</h3>
						<span className="mh-muted">
							{ __(
								'Columns on every board that uses this workflow, left to right. Open a stage to set its rules.',
								'masthead'
							) }
						</span>
					</div>
					{ ! readOnly && (
						<button
							type="button"
							className="mh-btn mh-btn--outline"
							onClick={ addStage }
						>
							<Icon icon={ plus } size={ 18 } />
							{ __( 'Add stage', 'masthead' ) }
						</button>
					) }
				</div>
				<DndContext
					sensors={ sensors }
					collisionDetection={ closestCenter }
					accessibility={ {
						announcements: {
							onDragStart: ( { active } ) =>
								sprintf(
									/* translators: %s: stage. */
									__( 'Picked up %s', 'masthead' ),
									draft.stages.find(
										( s ) => s.id === active.id
									)?.name
								),
							onDragOver: ( { over } ) =>
								over
									? sprintf(
											/* translators: %d: position. */
											__( 'Position %d', 'masthead' ),
											draft.stages.findIndex(
												( s ) => s.id === over.id
											) + 1
										)
									: '',
							onDragEnd: ( { active, over } ) =>
								over
									? sprintf(
											/* translators: 1: stage, 2: position. */
											__(
												'Moved %1$s to position %2$d',
												'masthead'
											),
											draft.stages.find(
												( s ) => s.id === active.id
											)?.name,
											draft.stages.findIndex(
												( s ) => s.id === over.id
											) + 1
										)
									: '',
							onDragCancel: () =>
								__( 'Reorder cancelled', 'masthead' ),
						},
					} }
					onDragEnd={ ( { active, over } ) => {
						if ( over && active.id !== over.id ) {
							edit( ( w ) => {
								const from = w.stages.findIndex(
									( s ) => s.id === active.id
								);
								const to = w.stages.findIndex(
									( s ) => s.id === over.id
								);
								return {
									...w,
									stages: arrayMove( w.stages, from, to ),
								};
							} );
						}
					} }
				>
					<SortableContext
						items={ draft.stages.map( ( s ) => s.id ) }
						strategy={ verticalListSortingStrategy }
					>
						{ draft.stages.map( ( stage, i ) => (
							<StageRow
								key={ stage.id }
								stage={ stage }
								index={ i }
								count={ counts[ stage.id ] || 0 }
								open={ openStage === stage.id }
								onToggleOpen={ () =>
									setOpenStage(
										openStage === stage.id ? null : stage.id
									)
								}
								onChange={ ( patch ) =>
									editStage( stage.id, patch )
								}
								onUp={ () =>
									edit( ( w ) => ( {
										...w,
										stages: arrayMove( w.stages, i, i - 1 ),
									} ) )
								}
								onDelete={ () => deleteStage( stage ) }
								canDelete={ draft.stages.length > 2 }
								palette={ boot.palette }
								statuses={ boot.statuses }
								readOnly={ readOnly }
							/>
						) ) }
					</SortableContext>
				</DndContext>
			</div>

			<div className="mh-wf-side">
				<div className="mh-panel mh-panel--pad">
					<div className="mh-row">
						<h3 className="mh-panel__title mh-grow">
							{
								/* translators: %s: workflow name. */ sprintf(
									__( 'Boards using %s', 'masthead' ),
									saved.name
								)
							}
						</h3>
						{ user.caps.manage_boards && (
							<button
								type="button"
								className="mh-btn mh-btn--ghost mh-btn--sm"
								onClick={ () => setModal( 'board' ) }
							>
								<Icon icon={ plus } size={ 18 } />
								{ __( 'New board', 'masthead' ) }
							</button>
						) }
					</div>
					{ wfBoards.map( ( b ) => (
						<button
							key={ b.id }
							type="button"
							className="mh-list-btn"
							onClick={ () => navigate( 'board', b.id ) }
						>
							<span className="mh-list-btn__text">
								<span className="mh-strong">{ b.name }</span>
								<span className="mh-muted">{ b.summary }</span>
							</span>
							<span className="mh-muted">{ b.count }</span>
							<Icon icon={ chevronRight } size={ 18 } />
						</button>
					) ) }
				</div>

				<div className="mh-panel mh-panel--pad">
					<div className="mh-row mh-row--baseline">
						<h3 className="mh-panel__title">
							{ __( 'Rules', 'masthead' ) }
						</h3>
						<span className="mh-muted">
							{ __( 'All workflows', 'masthead' ) }
						</span>
					</div>
					{ [
						{
							k: 'enforce_approvals',
							title: __( 'Enforce approvals', 'masthead' ),
							desc: __(
								'Stages marked “Needs approval” lock posts until an Editor moves them on.',
								'masthead'
							),
						},
						{
							k: 'auto_schedule',
							title: __( 'Auto-schedule', 'masthead' ),
							desc: __(
								'Dropping a ready post on a calendar day schedules it, if every check passes.',
								'masthead'
							),
						},
						{
							k: 'digest',
							title: __( 'Morning digest', 'masthead' ),
							desc: __(
								'One email a day with overdue posts, posts due today and unread notifications.',
								'masthead'
							),
						},
					].map( ( r ) => (
						<div key={ r.k } className="mh-setting">
							<div className="mh-setting__text">
								<span className="mh-strong">{ r.title }</span>
								<span className="mh-muted">{ r.desc }</span>
							</div>
							<Toggle
								checked={ !! rules[ r.k ] }
								onChange={ ( v ) => setRule( { [ r.k ]: v } ) }
								label={ r.title }
								disabled={ readOnly }
							/>
						</div>
					) ) }
					{ rules.digest && (
						<label
							className="mh-field mh-field--inline"
							htmlFor="mh-digest-time"
						>
							<span className="mh-field__label">
								{ __( 'Send at', 'masthead' ) }
							</span>
							<input
								id="mh-digest-time"
								type="time"
								className="mh-input mh-input--sm"
								value={ rules.digest_time }
								disabled={ readOnly }
								onChange={ ( e ) =>
									setRule( { digest_time: e.target.value } )
								}
							/>
						</label>
					) }
				</div>

				<div className="mh-panel mh-panel--pad">
					<div className="mh-panel__titles">
						<h3 className="mh-panel__title">
							{
								/* translators: %s: workflow name. */ sprintf(
									__( '%s checklist', 'masthead' ),
									draft.name
								)
							}
						</h3>
						<span className="mh-muted">
							{
								/* translators: %s: singular post type label, lowercase. */ sprintf(
									__(
										'Shown on every %s and in the editor sidebar.',
										'masthead'
									),
									(
										draft.singular ||
										__( 'post', 'masthead' )
									).toLowerCase()
								)
							}
						</span>
					</div>
					<div className="mh-setting mh-setting--box">
						<div className="mh-setting__text">
							<span className="mh-strong">
								{ __( 'Checklist gate', 'masthead' ) }
							</span>
							<span className="mh-muted">
								{ __(
									'Block stages with a Scheduled or Published status until every item is ticked.',
									'masthead'
								) }
							</span>
						</div>
						<Toggle
							checked={ draft.gate }
							onChange={ ( v ) =>
								edit( ( w ) => ( { ...w, gate: v } ) )
							}
							label={ __( 'Checklist gate', 'masthead' ) }
							disabled={ readOnly }
						/>
					</div>
					<ol className="mh-checklist-edit">
						{ draft.checklist.map( ( item, i ) => (
							<li key={ item.id }>
								<span className="mh-faint mh-num">
									{ i + 1 }
								</span>
								<span className="mh-grow">{ item.label }</span>
								{ ! readOnly && (
									<button
										type="button"
										className="mh-icon-btn mh-icon-btn--danger mh-icon-btn--sm"
										aria-label={ sprintf(
											/* translators: %s: checklist item. */
											__( 'Remove %s', 'masthead' ),
											item.label
										) }
										onClick={ () =>
											edit( ( w ) => ( {
												...w,
												checklist: w.checklist.filter(
													( c ) => c.id !== item.id
												),
											} ) )
										}
									>
										<Icon icon={ close } size={ 16 } />
									</button>
								) }
							</li>
						) ) }
					</ol>
					{ ! readOnly && (
						<div className="mh-row">
							<label
								className="screen-reader-text"
								htmlFor="mh-new-check"
							>
								{ __( 'Add an item', 'masthead' ) }
							</label>
							<input
								id="mh-new-check"
								className="mh-input mh-grow"
								value={ newCheck }
								placeholder={ __( 'Add an item', 'masthead' ) }
								onChange={ ( e ) =>
									setNewCheck( e.target.value )
								}
								onKeyDown={ ( e ) =>
									e.key === 'Enter' && addCheck()
								}
							/>
							<button
								type="button"
								className="mh-btn mh-btn--dark"
								onClick={ addCheck }
							>
								{ __( 'Add', 'masthead' ) }
							</button>
						</div>
					) }
				</div>
			</div>

			{ dirty && (
				<div
					className="mh-dirty-bar"
					role="region"
					aria-label={ __( 'Unsaved changes', 'masthead' ) }
				>
					<span
						className="mh-dot"
						style={ { background: '#d97706' } }
						aria-hidden="true"
					/>
					<span className="mh-strong">
						{ __( 'Unsaved workflow changes', 'masthead' ) }
					</span>
					<button
						type="button"
						className="mh-btn mh-btn--link"
						onClick={ () => setDrafts( {} ) }
						disabled={ saving }
					>
						{ __( 'Discard', 'masthead' ) }
					</button>
					<button
						type="button"
						className="mh-btn mh-btn--primary"
						onClick={ save }
						disabled={ saving }
					>
						{ __( 'Save changes', 'masthead' ) }
					</button>
				</div>
			) }

			{ modal === 'workflow' && (
				<NewWorkflowModal onClose={ () => setModal( null ) } />
			) }
			{ modal === 'board' && (
				<BoardModal
					board={ null }
					workflow={ key }
					onClose={ () => setModal( null ) }
				/>
			) }
		</div>
	);
}
