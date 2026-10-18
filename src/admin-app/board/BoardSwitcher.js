import { useSelect } from '@wordpress/data';
import { useRef, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, check, cog, plus } from '@wordpress/icons';
import { STORE } from '../../store';
import { useEscape } from '../../common/components';
import { navigate } from '../router';
import { api } from '../../common/api';

export default function BoardSwitcher( { current, onClose, onEdit, onNew } ) {
	const { boards, workflows, defaultBoard, user } = useSelect(
		( select ) => ( {
			boards: select( STORE ).getBoards(),
			workflows: select( STORE ).getWorkflows(),
			defaultBoard: select( STORE ).getDefaultBoard(),
			user: select( STORE ).getUser(),
		} ),
		[]
	);
	useEscape( onClose );
	const ref = useRef();
	// Counts are loaded only when the menu opens.
	const [ counts, setCounts ] = useState( {} );
	useEffect( () => {
		let live = true;
		api.boards( { counts: 1 } ).then(
			( res ) =>
				live &&
				setCounts(
					Object.fromEntries(
						res.boards.map( ( b ) => [ b.id, b.count ] )
					)
				),
			() => {}
		);
		return () => {
			live = false;
		};
	}, [] );
	useEffect( () => {
		ref.current?.querySelector( 'button' )?.focus();
	}, [] );

	return (
		<>
			<div
				className="mh-backdrop mh-backdrop--clear"
				role="presentation"
				onClick={ onClose }
			/>
			<div
				className="mh-popover mh-switcher"
				ref={ ref }
				role="menu"
				aria-label={ __( 'Boards', 'masthead' ) }
			>
				{ workflows.map( ( wf ) => {
					const list = boards.filter(
						( b ) => b.workflow === wf.key
					);
					if ( ! list.length ) {
						return null;
					}
					return (
						<div key={ wf.key } className="mh-switcher__group">
							<div className="mh-switcher__label">
								<span
									className="mh-square"
									style={ { background: wf.color } }
									aria-hidden="true"
								/>
								{
									/* translators: %s: workflow name. */ sprintf(
										__( '%s workflow', 'masthead' ),
										wf.name
									)
								}
							</div>
							{ list.map( ( b ) => (
								<div
									key={ b.id }
									className={
										'mh-switcher__row' +
										( b.id === current ? ' is-active' : '' )
									}
								>
									<button
										type="button"
										role="menuitemradio"
										aria-checked={ b.id === current }
										className="mh-switcher__main"
										onClick={ () => {
											onClose();
											navigate( 'board', b.id );
										} }
									>
										<span className="mh-switcher__text">
											<span className="mh-switcher__name">
												{ b.name }
												{ b.id === defaultBoard && (
													<span className="mh-badge">
														{ __(
															'DEFAULT',
															'masthead'
														) }
													</span>
												) }
											</span>
											<span className="mh-muted">
												{ b.summary }
											</span>
										</span>
										<span className="mh-muted">
											{ counts[ b.id ] ?? '…' }
										</span>
										{ b.id === current && (
											<Icon
												icon={ check }
												size={ 18 }
												className="mh-accent"
											/>
										) }
									</button>
									{ user.caps.manage_boards && (
										<button
											type="button"
											className="mh-icon-btn"
											aria-label={ sprintf(
												/* translators: %s: board name. */
												__(
													'Settings for %s',
													'masthead'
												),
												b.name
											) }
											onClick={ () => onEdit( b ) }
										>
											<Icon icon={ cog } size={ 17 } />
										</button>
									) }
								</div>
							) ) }
						</div>
					);
				} ) }
				{ user.caps.manage_boards && (
					<>
						<div className="mh-divider" />
						<button
							type="button"
							role="menuitem"
							className="mh-switcher__new"
							onClick={ onNew }
						>
							<Icon icon={ plus } size={ 18 } />
							{ __( 'New board', 'masthead' ) }
						</button>
					</>
				) }
			</div>
		</>
	);
}
