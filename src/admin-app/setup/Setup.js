import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf, _n } from '@wordpress/i18n';
import { Icon, arrowRight, check, columns } from '@wordpress/icons';
import { STORE } from '../../store';
import { api, errorMessage } from '../../common/api';
import { StagePill, Toggle, useToast } from '../../common/components';
import { navigate } from '../router';
import TeamStep from './TeamStep';

function Step( { n, title, sub, children } ) {
	return (
		<section
			className="mh-panel mh-panel--pad mh-setup__step"
			aria-labelledby={ `mh-step-${ n }` }
		>
			<div className="mh-row">
				<span className="mh-step-num" aria-hidden="true">
					{ n }
				</span>
				<h2 className="mh-panel__title" id={ `mh-step-${ n }` }>
					{ title }
				</h2>
				{ sub && <span className="mh-muted">{ sub }</span> }
			</div>
			{ children }
		</section>
	);
}

const chain = ( stages ) => stages.map( ( s ) => s.name ).join( ' → ' );

export default function Setup() {
	const toast = useToast();
	const boot = useSelect( ( select ) => select( STORE ).getBoot(), [] );
	const { setBoot } = useDispatch( STORE );
	const existingPages = boot.workflows.some(
		( w ) => w.post_type === 'page'
	);
	const existingNews = boot.workflows.some(
		( w ) => ( w.singular || '' ).toLowerCase() === 'newsletter'
	);

	const [ template, setTemplate ] = useState( 'team' );
	const [ pages, setPages ] = useState( true );
	const [ newsletter, setNewsletter ] = useState( true );
	const [ teamChanges, setTeamChanges ] = useState( {} );
	const [ importOn, setImportOn ] = useState( true );
	const [ busy, setBusy ] = useState( false );

	const counts = boot.post_counts || {};
	const parts = [];
	if ( counts.draft ) {
		parts.push(
			sprintf(
				/* translators: %d: drafts. */
				_n( '%d draft', '%d drafts', counts.draft, 'masthead' ),
				counts.draft
			)
		);
	}
	if ( counts.pending ) {
		parts.push(
			sprintf(
				/* translators: %d: pending posts. */
				_n(
					'%d pending review',
					'%d pending review',
					counts.pending,
					'masthead'
				),
				counts.pending
			)
		);
	}
	if ( counts.future ) {
		parts.push(
			sprintf(
				/* translators: %d: scheduled posts. */
				_n(
					'%d scheduled post',
					'%d scheduled posts',
					counts.future,
					'masthead'
				),
				counts.future
			)
		);
	}
	if ( counts.publish ) {
		parts.push(
			sprintf(
				/* translators: %d: published posts. */
				_n(
					'%d published post',
					'%d published posts',
					counts.publish,
					'masthead'
				),
				counts.publish
			)
		);
	}
	const importText = parts.length
		? sprintf(
				/* translators: %s: list of counts. */
				__( '%s will be placed on the board.', 'masthead' ),
				parts.join( ', ' )
			)
		: __( 'There are no posts to bring in yet.', 'masthead' );

	const finish = async ( skip = false ) => {
		setBusy( true );
		try {
			const next = await api.setup(
				skip
					? { skip: true }
					: {
							template,
							pages,
							newsletter,
							team: teamChanges,
							import: importOn,
						}
			);
			setBoot( next );
			if ( ! skip ) {
				toast( __( 'Your board is ready', 'masthead' ) );
			}
			navigate( 'board', next.default_board );
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="mh-setup">
			<div className="mh-setup__hero">
				<span className="mh-tile mh-tile--lg" aria-hidden="true">
					<Icon icon={ columns } size={ 28 } />
				</span>
				<div>
					<h1 className="mh-setup__title">
						{ __( 'Welcome to Masthead', 'masthead' ) }
					</h1>
					<p className="mh-setup__lead">
						{ __(
							'Turn your posts into an editorial board. Three choices and your team is ready.',
							'masthead'
						) }
					</p>
					{ boot.settings.setup_complete && (
						<p className="mh-notice-inline">
							{ __(
								'Setup has already run. Running it again replaces the stages and checklist of the Posts workflow. Posts keep their status and move to the matching stage.',
								'masthead'
							) }
						</p>
					) }
				</div>
			</div>

			<Step
				n={ 1 }
				title={ __( 'Choose a workflow for posts', 'masthead' ) }
			>
				<div
					className="mh-templates"
					role="radiogroup"
					aria-label={ __( 'Workflow for posts', 'masthead' ) }
				>
					{ [ 'simple', 'team', 'news' ].map( ( id ) => {
						const tpl = boot.templates[ id ];
						const sel = template === id;
						return (
							<button
								key={ id }
								type="button"
								role="radio"
								aria-checked={ sel }
								className={
									'mh-template' +
									( sel ? ' is-selected' : '' )
								}
								onClick={ () => setTemplate( id ) }
							>
								<span className="mh-row">
									<span className="mh-strong">
										{ tpl.label }
									</span>
									{ tpl.recommended && (
										<span className="mh-badge mh-badge--accent">
											{ __( 'RECOMMENDED', 'masthead' ) }
										</span>
									) }
									<span className="mh-spacer" />
									{ sel && (
										<Icon
											icon={ check }
											size={ 20 }
											className="mh-accent"
										/>
									) }
								</span>
								<span className="mh-muted">{ tpl.desc }</span>
								<span className="mh-pills">
									{ tpl.stages.map( ( s ) => (
										<StagePill
											key={ s.name }
											stage={ s }
											className="mh-pill--soft"
										/>
									) ) }
								</span>
							</button>
						);
					} ) }
				</div>
			</Step>

			<Step
				n={ 2 }
				title={ __( 'Other content types', 'masthead' ) }
				sub={ __( 'Each gets its own workflow and board', 'masthead' ) }
			>
				{ [
					{
						k: 'pages',
						on: pages || existingPages,
						set: setPages,
						name: __( 'Pages', 'masthead' ),
						stages: boot.templates.pages.stages,
						done: existingPages,
					},
					{
						k: 'newsletter',
						on: newsletter || existingNews,
						set: setNewsletter,
						name: __( 'Newsletter', 'masthead' ),
						stages: boot.templates.newsletter.stages,
						done: existingNews,
					},
				].map( ( o ) => (
					<div
						key={ o.k }
						className="mh-setting mh-setting--box-line"
					>
						<div className="mh-setting__text">
							<span className="mh-strong">{ o.name }</span>
							<span className="mh-muted">
								{ o.done
									? __( 'Already set up', 'masthead' )
									: chain( o.stages ) }
							</span>
						</div>
						<Toggle
							checked={ o.on }
							onChange={ o.set }
							label={ o.name }
							disabled={ o.done }
						/>
					</div>
				) ) }
			</Step>

			<Step
				n={ 3 }
				title={ __( 'Who’s on the team', 'masthead' ) }
				sub={ __(
					'Everyone with a role is included unless you turn them off',
					'masthead'
				) }
			>
				<TeamStep
					selfId={ boot.user.id }
					changes={ teamChanges }
					onChange={ setTeamChanges }
				/>
			</Step>

			<section
				className="mh-panel mh-panel--pad mh-setup__step mh-row mh-row--wrap"
				aria-labelledby="mh-step-4"
			>
				<span className="mh-step-num" aria-hidden="true">
					4
				</span>
				<div className="mh-setting__text mh-grow">
					<h2 className="mh-panel__title" id="mh-step-4">
						{ __( 'Bring in existing posts', 'masthead' ) }
					</h2>
					<span className="mh-muted">{ importText }</span>
				</div>
				<Toggle
					checked={ importOn }
					onChange={ setImportOn }
					label={ __( 'Bring in existing posts', 'masthead' ) }
				/>
			</section>

			<div className="mh-setup__foot">
				<button
					type="button"
					className="mh-btn mh-btn--link"
					onClick={ () => finish( true ) }
					disabled={ busy }
				>
					{ __( 'Skip for now', 'masthead' ) }
				</button>
				<button
					type="button"
					className="mh-btn mh-btn--primary mh-btn--lg"
					onClick={ () => finish( false ) }
					disabled={ busy }
				>
					{ __( 'Create board', 'masthead' ) }
					<Icon icon={ arrowRight } size={ 18 } />
				</button>
			</div>
		</div>
	);
}
