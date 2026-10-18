import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Icon,
	arrowUp,
	chevronDown,
	chevronUp,
	dragHandle,
	trash,
} from '@wordpress/icons';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Toggle } from '../../common/components';
import { UserPicker } from '../../common/pickers';

const NOTIFY = () => [
	{ id: 'none', name: __( 'Nobody', 'masthead' ) },
	{ id: 'assignee', name: __( 'Assignee', 'masthead' ) },
	{ id: 'editors', name: __( 'Editors', 'masthead' ) },
	{ id: 'everyone', name: __( 'Everyone', 'masthead' ) },
];

export function chips( stage, statuses ) {
	const out = [
		{
			t: sprintf(
				/* translators: %s: WordPress post status. */
				__( 'WP: %s', 'masthead' ),
				statuses[ stage.wp_status ] || stage.wp_status
			),
			tone: 'neutral',
		},
	];
	if ( stage.required ) {
		out.push( { t: __( 'Can’t skip', 'masthead' ), tone: 'accent' } );
	}
	if ( stage.approval ) {
		out.push( { t: __( 'Approval', 'masthead' ), tone: 'warn' } );
	}
	if ( stage.reviewer ) {
		const r = stage.reviewer_user;
		out.push( {
			t: sprintf(
				/* translators: %s: first name. */
				__( 'Reviewer: %s', 'masthead' ),
				r?.first || '#' + stage.reviewer
			),
			tone: 'neutral',
		} );
	}
	if ( stage.limit > 0 ) {
		out.push( {
			t: sprintf(
				/* translators: %d: limit. */
				__( 'Limit %d', 'masthead' ),
				stage.limit
			),
			tone: stage.limit_mode === 'block' ? 'bad' : 'neutral',
		} );
	}
	if ( stage.notify && stage.notify !== 'none' ) {
		const n = NOTIFY().find( ( o ) => o.id === stage.notify );
		out.push( {
			t: sprintf(
				/* translators: %s: audience, lowercase. */
				__( 'Notify %s', 'masthead' ),
				( n?.name || stage.notify ).toLowerCase()
			),
			tone: 'neutral',
		} );
	}
	return out;
}

export default function StageRow( {
	stage,
	index,
	count,
	open,
	onToggleOpen,
	onChange,
	onUp,
	onDelete,
	canDelete,
	palette,
	statuses,
	readOnly,
} ) {
	const {
		attributes,
		listeners,
		setNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id: stage.id, disabled: readOnly } );
	const [ confirm, setConfirm ] = useState( false );
	const base = `mh-stage-${ stage.id }`;

	return (
		<div
			ref={ setNodeRef }
			className={
				'mh-stage' +
				( isDragging ? ' is-dragging' : '' ) +
				( open ? ' is-open' : '' )
			}
			style={ {
				transform: CSS.Transform.toString( transform ),
				transition,
			} }
		>
			<div className="mh-stage__row">
				<button
					type="button"
					className="mh-stage__handle"
					{ ...attributes }
					{ ...listeners }
					disabled={ readOnly }
					aria-label={ sprintf(
						/* translators: %s: stage name. */
						__(
							'Reorder %s. Press Space to pick up, arrow keys to move, Space to drop.',
							'masthead'
						),
						stage.name
					) }
					title={ __( 'Drag to reorder', 'masthead' ) }
				>
					<Icon icon={ dragHandle } size={ 18 } />
				</button>
				<button
					type="button"
					className="mh-stage__color"
					style={ { background: stage.color } }
					disabled={ readOnly }
					aria-label={ sprintf(
						/* translators: %s: stage name. */
						__( 'Change colour of %s', 'masthead' ),
						stage.name
					) }
					title={ __( 'Change colour', 'masthead' ) }
					onClick={ () =>
						onChange( {
							color: palette[
								( palette.indexOf( stage.color ) + 1 ) %
									palette.length
							],
						} )
					}
				/>
				<input
					className="mh-input mh-stage__name"
					value={ stage.name }
					disabled={ readOnly }
					aria-label={ __( 'Stage name', 'masthead' ) }
					onChange={ ( e ) => onChange( { name: e.target.value } ) }
				/>
				<div className="mh-stage__chips">
					{ chips( stage, statuses ).map( ( c ) => (
						<span
							key={ c.t }
							className={ 'mh-tag mh-tag--' + c.tone }
						>
							{ c.t }
						</span>
					) ) }
				</div>
				<span className="mh-muted mh-nowrap">
					{
						/* translators: %d: posts. */ sprintf(
							_n( '%d post', '%d posts', count, 'masthead' ),
							count
						)
					}
				</span>
				<div className="mh-stage__actions">
					<button
						type="button"
						className="mh-icon-btn"
						aria-expanded={ open }
						aria-controls={ base + '-rules' }
						aria-label={ sprintf(
							/* translators: %s: stage name. */
							__( 'Rules for %s', 'masthead' ),
							stage.name
						) }
						title={ __( 'Stage rules', 'masthead' ) }
						onClick={ onToggleOpen }
					>
						<Icon
							icon={ open ? chevronUp : chevronDown }
							size={ 20 }
						/>
					</button>
					<button
						type="button"
						className="mh-icon-btn"
						disabled={ readOnly || index === 0 }
						aria-label={ sprintf(
							/* translators: %s: stage name. */
							__( 'Move %s left', 'masthead' ),
							stage.name
						) }
						title={ __( 'Move left', 'masthead' ) }
						onClick={ onUp }
					>
						<Icon icon={ arrowUp } size={ 18 } />
					</button>
					{ ! confirm ? (
						<button
							type="button"
							className="mh-icon-btn mh-icon-btn--danger"
							disabled={ readOnly || ! canDelete }
							aria-label={ sprintf(
								/* translators: %s: stage name. */
								__( 'Delete %s', 'masthead' ),
								stage.name
							) }
							title={
								canDelete
									? __( 'Delete stage', 'masthead' )
									: __(
											'A workflow needs at least two stages',
											'masthead'
										)
							}
							onClick={ () => setConfirm( true ) }
						>
							<Icon icon={ trash } size={ 18 } />
						</button>
					) : (
						<span className="mh-row">
							<button
								type="button"
								className="mh-btn mh-btn--danger mh-btn--xs"
								onClick={ () => {
									setConfirm( false );
									onDelete();
								} }
							>
								{ __( 'Delete', 'masthead' ) }
							</button>
							<button
								type="button"
								className="mh-btn mh-btn--link mh-btn--xs"
								onClick={ () => setConfirm( false ) }
							>
								{ __( 'Cancel', 'masthead' ) }
							</button>
						</span>
					) }
				</div>
			</div>
			{ open && (
				<fieldset
					className="mh-stage__rules"
					id={ base + '-rules' }
					disabled={ readOnly }
				>
					<legend className="screen-reader-text">
						{
							/* translators: %s: stage name. */ sprintf(
								__( '%s rules', 'masthead' ),
								stage.name
							)
						}
					</legend>
					<label className="mh-field" htmlFor={ base + '-wp-status' }>
						<span className="mh-field__label">
							{ __( 'WordPress status', 'masthead' ) }
						</span>
						<select
							id={ base + '-wp-status' }
							className="mh-select mh-select--sm"
							value={ stage.wp_status }
							onChange={ ( e ) =>
								onChange( { wp_status: e.target.value } )
							}
						>
							{ Object.entries( statuses ).map(
								( [ id, name ] ) => (
									<option key={ id } value={ id }>
										{ name }
									</option>
								)
							) }
						</select>
						<span className="mh-help">
							{ __(
								'Saved on the post when it enters this stage.',
								'masthead'
							) }
						</span>
					</label>
					<div className="mh-field">
						<label
							className="mh-field__label"
							htmlFor={ base + '-reviewer' }
						>
							{ __( 'Reviewer', 'masthead' ) }
						</label>
						<UserPicker
							id={ base + '-reviewer' }
							label={ __( 'Reviewer', 'masthead' ) }
							noneLabel={ __( 'Nobody', 'masthead' ) }
							approvers
							disabled={ readOnly }
							value={ stage.reviewer || null }
							initial={ stage.reviewer_user || null }
							onChange={ ( u ) =>
								onChange( {
									reviewer: u ? u.id : null,
									reviewer_user: u || null,
								} )
							}
						/>
						<span className="mh-help">
							{ __(
								'Shown on the post and notified on entry.',
								'masthead'
							) }
						</span>
					</div>
					<label className="mh-field" htmlFor={ base + '-notify' }>
						<span className="mh-field__label">
							{ __( 'Notify on entry', 'masthead' ) }
						</span>
						<select
							id={ base + '-notify' }
							className="mh-select mh-select--sm"
							value={ stage.notify }
							onChange={ ( e ) =>
								onChange( { notify: e.target.value } )
							}
						>
							{ NOTIFY().map( ( o ) => (
								<option key={ o.id } value={ o.id }>
									{ o.name }
								</option>
							) ) }
						</select>
					</label>
					<label className="mh-field" htmlFor={ base + '-limit' }>
						<span className="mh-field__label">
							{ __( 'WIP limit', 'masthead' ) }
						</span>
						<input
							id={ base + '-limit' }
							type="number"
							min={ 0 }
							className="mh-input mh-input--sm"
							placeholder={ __( 'No limit', 'masthead' ) }
							value={ stage.limit || '' }
							onChange={ ( e ) =>
								onChange( {
									limit: Math.max(
										0,
										Number( e.target.value ) || 0
									),
								} )
							}
						/>
						<span className="mh-help">
							{ __(
								'Column turns red when exceeded.',
								'masthead'
							) }
						</span>
					</label>
					<label
						className="mh-field"
						htmlFor={ base + '-limit-mode' }
					>
						<span className="mh-field__label">
							{ __( 'When full', 'masthead' ) }
						</span>
						<select
							id={ base + '-limit-mode' }
							className="mh-select mh-select--sm"
							value={ stage.limit_mode }
							onChange={ ( e ) =>
								onChange( { limit_mode: e.target.value } )
							}
						>
							<option value="warn">
								{ __( 'Warn only', 'masthead' ) }
							</option>
							<option value="block">
								{ __( 'Block moves', 'masthead' ) }
							</option>
						</select>
						<span className="mh-help">
							{ __(
								'Block stops new posts entering a full stage.',
								'masthead'
							) }
						</span>
					</label>
					<div className="mh-setting">
						<div className="mh-setting__text">
							<span className="mh-strong">
								{ __( 'Can’t be skipped', 'masthead' ) }
							</span>
							<span className="mh-help">
								{ __(
									'Posts must pass through this stage before moving further right.',
									'masthead'
								) }
							</span>
						</div>
						<Toggle
							checked={ stage.required }
							onChange={ ( v ) => onChange( { required: v } ) }
							label={ __( 'Can’t be skipped', 'masthead' ) }
							disabled={ readOnly }
						/>
					</div>
					<div className="mh-setting">
						<div className="mh-setting__text">
							<span className="mh-strong">
								{ __( 'Needs approval to leave', 'masthead' ) }
							</span>
							<span className="mh-help">
								{ __(
									'Only Editors and Administrators can move posts out.',
									'masthead'
								) }
							</span>
						</div>
						<Toggle
							checked={ stage.approval }
							onChange={ ( v ) => onChange( { approval: v } ) }
							label={ __(
								'Needs approval to leave',
								'masthead'
							) }
							disabled={ readOnly }
						/>
					</div>
				</fieldset>
			) }
		</div>
	);
}
