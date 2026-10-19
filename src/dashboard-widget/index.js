import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import { Icon, arrowRight } from '@wordpress/icons';
import { Avatar } from '../common/components';
import { parseYmd, shortDate } from '../common/format';
import './dashboard-widget.scss';

function reasonText( item ) {
	if ( item.reason === 'review' ) {
		return sprintf(
			/* translators: %s: stage name. */
			__( 'Waiting for your review · %s', 'masthead' ),
			item.stage?.name || ''
		);
	}
	if ( item.reason === 'overdue' ) {
		return sprintf(
			/* translators: %s: date. */
			__( 'Overdue · due %s', 'masthead' ),
			shortDate( item.due )
		);
	}
	return item.due
		? sprintf(
				/* translators: 1: stage, 2: date. */
				__( '%1$s · due %2$s', 'masthead' ),
				item.stage?.name || '',
				shortDate( item.due )
			)
		: item.stage?.name || '';
}

function Widget( { data } ) {
	if ( data.empty ) {
		return (
			<p>{ __( 'Set up Masthead to see your pipeline.', 'masthead' ) }</p>
		);
	}
	const segs = data.stages.filter( ( s ) => s.count > 0 );
	return (
		<div className="mh-dash">
			<div className="mh-dash__big">
				<span className="mh-dash__num">{ data.in_progress }</span>
				<span className="mh-dash__label">
					{
						/* translators: %s: workflow. */ sprintf(
							__( '%s in progress', 'masthead' ),
							data.workflow.name
						)
					}
				</span>
				{ data.overdue > 0 && (
					<span className="mh-dash__overdue">
						·{ ' ' }
						{ data.overdue_more
							? sprintf(
									/* translators: %d: overdue posts shown before "+". */
									__( '%d+ overdue', 'masthead' ),
									data.overdue
								)
							: sprintf(
									/* translators: %d: overdue posts. */
									_n(
										'%d overdue',
										'%d overdue',
										data.overdue,
										'masthead'
									),
									data.overdue
								) }
					</span>
				) }
			</div>
			<div
				className="mh-dash__bar"
				role="img"
				aria-label={ data.stages
					.map( ( s ) => `${ s.name }: ${ s.count }` )
					.join( ', ' ) }
			>
				{ segs.map( ( s ) => (
					<span
						key={ s.id }
						style={ { flex: s.count, background: s.color } }
					/>
				) ) }
			</div>
			<ul className="mh-dash__legend">
				{ data.stages.map( ( s ) => (
					<li key={ s.id }>
						<span
							className="mh-square"
							style={ { background: s.color } }
							aria-hidden="true"
						/>
						{ s.name }
						<b>{ s.count }</b>
					</li>
				) ) }
			</ul>
			<div className="mh-dash__lists">
				<div>
					<h3 className="mh-dash__heading">
						{ __( 'Waiting on you', 'masthead' ) }
					</h3>
					{ ! data.waiting.length && (
						<p className="mh-muted">
							{ __( 'Nothing waiting on you.', 'masthead' ) }
						</p>
					) }
					<ul className="mh-dash__items">
						{ data.waiting.map( ( w ) => (
							<li key={ w.id }>
								<a
									href={ w.edit_url }
									className="mh-dash__item"
								>
									<span
										className="mh-dot mh-dot--sm"
										style={ { background: w.stage?.color } }
										aria-hidden="true"
									/>
									<span className="mh-dash__text">
										<span className="mh-dash__title">
											{ w.title }
										</span>
										<span
											className={
												'mh-dash__meta' +
												( w.reason === 'overdue'
													? ' is-bad'
													: '' )
											}
										>
											{ reasonText( w ) }
										</span>
									</span>
									<Avatar user={ w.author } size={ 22 } />
								</a>
							</li>
						) ) }
					</ul>
				</div>
				<div>
					<h3 className="mh-dash__heading">
						{ __( 'Publishing next', 'masthead' ) }
					</h3>
					{ ! data.next.length && (
						<p className="mh-muted">
							{ __( 'Nothing scheduled.', 'masthead' ) }
						</p>
					) }
					<ul className="mh-dash__items">
						{ data.next.map( ( n ) => {
							const d = parseYmd( n.date.slice( 0, 10 ) );
							return (
								<li key={ n.id }>
									<a
										href={ n.edit_url }
										className="mh-dash__item"
									>
										<span
											className="mh-dash__tile"
											aria-hidden="true"
										>
											<span>{ dateI18n( 'M', d ) }</span>
											<b>{ d.getDate() }</b>
										</span>
										<span className="mh-dash__text">
											<span className="mh-dash__title">
												{ n.title }
											</span>
											<span className="mh-dash__meta">
												{ n.date_label } ·{ ' ' }
												{ n.author?.name }
											</span>
										</span>
									</a>
								</li>
							);
						} ) }
					</ul>
				</div>
			</div>
			<a className="mh-dash__open" href={ data.board_url }>
				{ __( 'Open board', 'masthead' ) }
				<Icon icon={ arrowRight } size={ 16 } />
			</a>
		</div>
	);
}

domReady( () => {
	const el = document.getElementById( 'masthead-dashboard-root' );
	if ( el && window.mastheadDashboard ) {
		createRoot( el ).render( <Widget data={ window.mastheadDashboard } /> );
	}
} );
