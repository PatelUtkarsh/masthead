import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { STORE } from '../../store';
import { api, errorMessage } from '../../common/api';
import { Dialog, Segmented, useToast } from '../../common/components';
import { navigate } from '../router';

function slugify( name, taken ) {
	let base = name
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.slice( 0, 20 );
	if ( ! base ) {
		return '';
	}
	let slug = base;
	let n = 2;
	while ( taken.includes( slug ) ) {
		const suffix = '_' + n++;
		slug = base.slice( 0, 20 - suffix.length ) + suffix;
	}
	base = slug;
	return base;
}

export default function NewWorkflowModal( { onClose } ) {
	const toast = useToast();
	const { workflows, postTypes } = useSelect(
		( select ) => ( {
			workflows: select( STORE ).getWorkflows(),
			postTypes: select( STORE ).getBoot().post_types,
		} ),
		[]
	);
	const { refreshBoot } = useDispatch( STORE );
	const [ name, setName ] = useState( '' );
	const [ from, setFrom ] = useState( 'simple' );
	const [ busy, setBusy ] = useState( false );

	const source = workflows[ 0 ];
	const slug = slugify( name, Object.keys( postTypes || {} ) );

	const create = async () => {
		if ( ! name.trim() ) {
			return;
		}
		setBusy( true );
		try {
			const res = await api.createWorkflow( {
				singular: name.trim(),
				from,
				source: source?.key,
			} );
			await refreshBoot();
			toast(
				sprintf(
					/* translators: %s: workflow name. */
					__( 'Created the %s workflow', 'masthead' ),
					res.workflow.name
				)
			);
			navigate( 'workflows', res.workflow.key );
			onClose();
		} catch ( e ) {
			toast( errorMessage( e ), { bad: true } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Dialog
			title={ __( 'New workflow', 'masthead' ) }
			onClose={ onClose }
			width={ 440 }
			footer={
				<>
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
						disabled={ busy || ! name.trim() }
						onClick={ create }
					>
						{ __( 'Create workflow', 'masthead' ) }
					</button>
				</>
			}
		>
			<label className="mh-field" htmlFor="mh-new-workflow-name">
				<span className="mh-field__label">
					{ __( 'Content type (singular)', 'masthead' ) }
				</span>
				<input
					id="mh-new-workflow-name"
					className="mh-input"
					value={ name }
					placeholder={ __( 'e.g. Podcast', 'masthead' ) }
					onChange={ ( e ) => setName( e.target.value ) }
					onKeyDown={ ( e ) => e.key === 'Enter' && create() }
				/>
				<span className="mh-muted">
					{ slug
						? sprintf(
								/* translators: %s: post type slug. */
								__(
									'Registers a post type with the slug “%s”, its own board and its own menu item.',
									'masthead'
								),
								slug
							)
						: __(
								'Each workflow registers its own post type.',
								'masthead'
							) }
				</span>
			</label>
			<div className="mh-field">
				<span className="mh-field__label">
					{ __( 'Start from', 'masthead' ) }
				</span>
				<Segmented
					label={ __( 'Start from', 'masthead' ) }
					value={ from }
					onChange={ setFrom }
					options={ [
						{ value: 'simple', label: __( 'Simple', 'masthead' ) },
						{
							value: 'copy',
							label: sprintf(
								/* translators: %s: workflow name. */
								__( 'Copy %s', 'masthead' ),
								source?.name || __( 'Articles', 'masthead' )
							),
						},
					] }
				/>
			</div>
		</Dialog>
	);
}
