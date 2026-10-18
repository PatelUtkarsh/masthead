import domReady from '@wordpress/dom-ready';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../common/api';
import { Avatar } from '../common/components';
import { ago } from '../common/format';
import './admin-bar.scss';

const DATA = window.mastheadAdminBar || {};

function setCount( n ) {
	const el = document.querySelector(
		'#wp-admin-bar-masthead-notifications .masthead-ab-count'
	);
	if ( ! el ) {
		return;
	}
	el.textContent = n > 0 ? String( n ) : '';
	el.dataset.count = String( n );
	el.classList.toggle( 'is-empty', n <= 0 );
	const sr = el.nextElementSibling;
	if ( sr ) {
		sr.textContent = sprintf(
			/* translators: %d: unread notifications. */
			_n(
				'%d unread notification',
				'%d unread notifications',
				n,
				'masthead'
			),
			n
		);
	}
}

function Dropdown( { open, onClose, anchor } ) {
	const [ items, setItems ] = useState( null );
	const ref = useRef();

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		let live = true;
		api.notifications().then( ( res ) => {
			if ( ! live ) {
				return;
			}
			setItems( res.items );
			if ( res.unread > 0 ) {
				api.readNotifications().then( () => setCount( 0 ) );
			}
		} );
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				onClose();
				anchor.focus();
			}
		};
		const onDoc = ( e ) => {
			if (
				! ref.current?.contains( e.target ) &&
				! anchor.contains( e.target )
			) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', onKey );
		document.addEventListener( 'mousedown', onDoc );
		window.requestAnimationFrame( () => ref.current?.focus() );
		return () => {
			live = false;
			document.removeEventListener( 'keydown', onKey );
			document.removeEventListener( 'mousedown', onDoc );
		};
	}, [ open ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! open ) {
		return null;
	}

	return (
		<div
			className="mh-ab"
			ref={ ref }
			tabIndex={ -1 }
			role="dialog"
			aria-label={ __( 'Notifications', 'masthead' ) }
		>
			<div className="mh-ab__head">
				<span className="mh-ab__title">
					{ __( 'Notifications', 'masthead' ) }
				</span>
				<span className="mh-ab__who">{ DATA.user?.name }</span>
			</div>
			<div className="mh-ab__list">
				{ items === null && (
					<p className="mh-ab__empty">
						{ __( 'Loading…', 'masthead' ) }
					</p>
				) }
				{ items && items.length === 0 && (
					<p className="mh-ab__empty">
						{ __( 'You’re all caught up.', 'masthead' ) }
					</p>
				) }
				{ items &&
					items.map( ( n ) => {
						const href = n.post
							? DATA.boardUrl +
								( n.board
									? '#/board/' +
										encodeURIComponent( n.board ) +
										'?post=' +
										n.post.id
									: '' )
							: DATA.boardUrl;
						return (
							<a
								key={ n.id }
								className="mh-ab__item"
								href={ href }
							>
								<Avatar user={ n.event.actor } size={ 26 } />
								<span className="mh-ab__text">
									<span>
										<b>
											{ n.event.actor?.name ||
												__( 'Masthead', 'masthead' ) }
										</b>{ ' ' }
										{ n.event.text }
									</span>
									{ n.post && (
										<span className="mh-ab__post">
											{ n.post.title }
										</span>
									) }
									<span className="mh-ab__time">
										{ ago( n.event.created_at ) }
									</span>
								</span>
								<span
									className={
										'mh-ab__dot' +
										( n.read ? '' : ' is-unread' )
									}
									aria-hidden="true"
								/>
								{ ! n.read && (
									<span className="screen-reader-text">
										{ __( 'Unread', 'masthead' ) }
									</span>
								) }
							</a>
						);
					} ) }
			</div>
		</div>
	);
}

function Bell( { anchor } ) {
	const [ open, setOpen ] = useState( false );
	useEffect( () => {
		const onClick = ( e ) => {
			e.preventDefault();
			setOpen( ( o ) => ! o );
		};
		anchor.setAttribute( 'aria-haspopup', 'dialog' );
		anchor.setAttribute( 'role', 'button' );
		anchor.addEventListener( 'click', onClick );
		return () => anchor.removeEventListener( 'click', onClick );
	}, [ anchor ] );
	useEffect( () => {
		anchor.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		anchor.parentElement?.classList.toggle( 'is-open', open );
	}, [ open, anchor ] );
	return (
		<Dropdown
			open={ open }
			onClose={ () => setOpen( false ) }
			anchor={ anchor }
		/>
	);
}

domReady( () => {
	const root = document.getElementById( 'masthead-notifications-root' );
	const anchor = document.querySelector(
		'#wp-admin-bar-masthead-notifications > .ab-item'
	);
	if ( ! root || ! anchor ) {
		return;
	}
	createRoot( root ).render( <Bell anchor={ anchor } /> );

	const $ = window.jQuery;
	if ( $ && window.wp?.heartbeat ) {
		window.wp.heartbeat.interval( 60 );
		$( document ).on( 'heartbeat-send.masthead', ( e, data ) => {
			data.masthead_notifications = 1;
		} );
		$( document ).on( 'heartbeat-tick.masthead', ( e, data ) => {
			if ( data?.masthead_notifications ) {
				setCount( Number( data.masthead_notifications.unread ) || 0 );
			}
		} );
	}
} );
