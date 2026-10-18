import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { STORE } from '../../store';
import { api, errorMessage } from '../../common/api';
import {
	Dialog,
	Segmented,
	StagePill,
	Toggle,
	useToast,
} from '../../common/components';
import { navigate } from '../router';
import { CategoryPicker, UserPicker } from '../../common/pickers';

/**
 * New board / Board settings modal.
 *
 * @param {Object}      props
 * @param {Object|null} props.board    Board to edit, or null to create.
 * @param {string}      props.workflow Preselected workflow for a new board.
 * @param {() => void}  props.onClose  Close handler.
 */
export default function BoardModal( { board, workflow, onClose } ) {
	const toast = useToast();
	const { workflows, boards, defaultBoard } = useSelect(
		( select ) => ( {
			workflows: select( STORE ).getWorkflows(),
			boards: select( STORE ).getBoards(),
			defaultBoard: select( STORE ).getDefaultBoard(),
		} ),
		[]
	);
	const { patchBoot } = useDispatch( STORE );

	const [ name, setName ] = useState( board?.name || '' );
	const [ wfKey, setWfKey ] = useState(
		board?.workflow || workflow || workflows[ 0 ]?.key
	);
	const [ category, setCategory ] = useState(
		board?.labels?.category || null
	);
	const [ author, setAuthor ] = useState( board?.filters?.author || 0 );
	const [ isDefault, setIsDefault ] = useState(
		board ? board.id === defaultBoard : false
	);
	const [ count, setCount ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const wf = workflows.find( ( w ) => w.key === wfKey );

	useEffect( () => {
		let live = true;
		api.boardCount( wfKey, { category: category?.id, author } ).then(
			( r ) => live && setCount( r.count ),
			() => live && setCount( null )
		);
		return () => {
			live = false;
		};
	}, [ wfKey, category?.id, author ] );

	const save = async () => {
		if ( ! name.trim() ) {
			return;
		}
		setBusy( true );
		const data = {
			name: name.trim(),
			workflow: wfKey,
			filters: { category: category?.id || null, author: author || null },
			default: isDefault,
		};
		try {
			if ( board ) {
				const res = await api.updateBoard( board.id, data );
				const fresh = await api.boards();
				patchBoot( {
					boards: fresh.boards,
					default_board: fresh.default_board,
				} );
				toast( __( 'Board saved', 'masthead' ) );
				navigate( 'board', res.id );
			} else {
				const res = await api.createBoard( data );
				const fresh = await api.boards();
				patchBoot( {
					boards: fresh.boards,
					default_board: fresh.default_board,
				} );
				/* translators: %s: board name. */
				toast( sprintf( __( 'Created %s', 'masthead' ), res.name ) );
				navigate( 'board', res.id );
			}
			onClose();
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		} finally {
			setBusy( false );
		}
	};

	const remove = async () => {
		setBusy( true );
		try {
			const res = await api.deleteBoard( board.id );
			patchBoot( {
				boards: boards.filter( ( b ) => b.id !== board.id ),
				default_board: res.default_board,
			} );
			/* translators: %s: board name. */
			toast( sprintf( __( 'Deleted %s', 'masthead' ), board.name ) );
			navigate( 'board', res.default_board );
			onClose();
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Dialog
			title={
				board
					? __( 'Board settings', 'masthead' )
					: __( 'New board', 'masthead' )
			}
			onClose={ onClose }
			footer={
				<>
					{ board && (
						<button
							type="button"
							className="mh-btn mh-btn--danger-link"
							onClick={ remove }
							disabled={ busy || boards.length <= 1 }
							title={
								boards.length <= 1
									? __(
											'At least one board must remain.',
											'masthead'
										)
									: undefined
							}
						>
							{ __( 'Delete board', 'masthead' ) }
						</button>
					) }
					<span className="mh-spacer" />
					<button
						type="button"
						className="mh-btn mh-btn--link"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'masthead' ) }
					</button>
					<button
						type="button"
						className="mh-btn mh-btn--primary"
						onClick={ save }
						disabled={ busy || ! name.trim() }
					>
						{ board
							? __( 'Save changes', 'masthead' )
							: __( 'Create board', 'masthead' ) }
					</button>
				</>
			}
		>
			<label className="mh-field" htmlFor="mh-board-name">
				<span className="mh-field__label">
					{ __( 'Name', 'masthead' ) }
				</span>
				<input
					id="mh-board-name"
					type="text"
					className="mh-input"
					value={ name }
					placeholder={ __( 'e.g. Culture desk', 'masthead' ) }
					onChange={ ( e ) => setName( e.target.value ) }
				/>
			</label>
			<div className="mh-field">
				<span className="mh-field__label">
					{ __( 'Content', 'masthead' ) }
				</span>
				<Segmented
					label={ __( 'Content type', 'masthead' ) }
					value={ wfKey }
					onChange={ setWfKey }
					options={ workflows.map( ( w ) => ( {
						value: w.key,
						label: w.plural || w.name,
					} ) ) }
				/>
				{ wf && (
					<div className="mh-wfbox">
						<span className="mh-muted mh-row">
							<span
								className="mh-square"
								style={ { background: wf.color } }
								aria-hidden="true"
							/>
							{
								/* translators: %s: workflow name. */ sprintf(
									__( 'Uses the %s workflow', 'masthead' ),
									wf.name
								)
							}
						</span>
						<div className="mh-pills">
							{ wf.stages.map( ( s ) => (
								<StagePill
									key={ s.id }
									stage={ s }
									className="mh-pill--outline"
								/>
							) ) }
						</div>
					</div>
				) }
			</div>
			<div className="mh-field">
				<span className="mh-field__label">
					{ __( 'Only show', 'masthead' ) }
				</span>
				<div className="mh-grid2">
					<CategoryPicker
						label={ __( 'Category', 'masthead' ) }
						noneLabel={ __( 'Any category', 'masthead' ) }
						selected={ category }
						onChange={ setCategory }
					/>
					<UserPicker
						label={ __( 'Assignee', 'masthead' ) }
						noneLabel={ __( 'Anyone', 'masthead' ) }
						value={ author }
						initial={ board?.labels?.author || null }
						onChange={ ( u ) => setAuthor( u ? u.id : 0 ) }
					/>
				</div>
				<span className="mh-muted">
					{ count === null
						? '\u00a0'
						: sprintf(
								/* translators: %d: number of posts. */
								_n(
									'%d post matches right now',
									'%d posts match right now',
									count,
									'masthead'
								),
								count
							) }
				</span>
			</div>
			<div className="mh-setting">
				<div className="mh-setting__text">
					<span className="mh-strong">
						{ __( 'Default board', 'masthead' ) }
					</span>
					<span className="mh-muted">
						{ __(
							'Opens first from the Masthead menu.',
							'masthead'
						) }
					</span>
				</div>
				<Toggle
					checked={ isDefault }
					onChange={ setIsDefault }
					label={ __( 'Default board', 'masthead' ) }
				/>
			</div>
		</Dialog>
	);
}
