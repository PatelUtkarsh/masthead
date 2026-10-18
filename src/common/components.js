import { Icon, check, close, cancelCircleFilled } from '@wordpress/icons';
import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import {
	useConstrainedTabbing,
	useFocusOnMount,
	useFocusReturn,
	useMergeRefs,
} from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Round avatar with initials (falls back from Gravatar to initials so it works offline).
 *
 * @param {Object}      props
 * @param {Object|null} props.user User summary.
 * @param {number}      props.size Size in px.
 */
export function Avatar( { user, size = 22 } ) {
	if ( ! user ) {
		return (
			<span
				className="mh-avatar mh-avatar--empty"
				style={ { width: size, height: size } }
				aria-hidden="true"
			/>
		);
	}
	return (
		<span
			className="mh-avatar"
			title={ user.name }
			style={ {
				width: size,
				height: size,
				background: user.color,
				fontSize: Math.max( 9, Math.round( size * 0.4 ) ),
			} }
		>
			<span aria-hidden="true">{ user.initials }</span>
			<span className="screen-reader-text">{ user.name }</span>
		</span>
	);
}

/**
 * 34×20 switch.
 *
 * @param {Object}                   props
 * @param {boolean}                  props.checked  On.
 * @param {(value: boolean) => void} props.onChange Called with the new value.
 * @param {string}                   props.label    Accessible label.
 * @param {boolean}                  props.disabled Disabled.
 */
export function Toggle( { checked, onChange, label, disabled = false } ) {
	return (
		<button
			type="button"
			role="switch"
			aria-checked={ checked }
			aria-label={ label }
			disabled={ disabled }
			className={ 'mh-toggle' + ( checked ? ' is-on' : '' ) }
			onClick={ () => onChange( ! checked ) }
		>
			<span className="mh-toggle__knob" />
		</button>
	);
}

/**
 * Stage pill: dot + name. Never colour alone.
 *
 * @param {Object} props
 * @param {Object} props.stage     Stage.
 * @param {string} props.className Extra class.
 */
export function StagePill( { stage, className = '' } ) {
	if ( ! stage ) {
		return null;
	}
	return (
		<span className={ 'mh-pill ' + className }>
			<span
				className="mh-dot"
				style={ { background: stage.color } }
				aria-hidden="true"
			/>
			{ stage.name }
		</span>
	);
}

/**
 * Segmented control.
 *
 * @param {Object}                  props
 * @param {Array}                   props.options  [{value, label, icon}].
 * @param {string}                  props.value    Selected value.
 * @param {(value: string) => void} props.onChange Change handler.
 * @param {string}                  props.label    Group label.
 */
export function Segmented( { options, value, onChange, label } ) {
	return (
		<div className="mh-seg" role="group" aria-label={ label }>
			{ options.map( ( o ) => (
				<button
					key={ o.value }
					type="button"
					className={
						'mh-seg__opt' +
						( o.value === value ? ' is-active' : '' )
					}
					aria-pressed={ o.value === value }
					onClick={ () => onChange( o.value ) }
				>
					{ o.icon && <Icon icon={ o.icon } size={ 18 } /> }
					{ o.label }
				</button>
			) ) }
		</div>
	);
}

/**
 * Modal dialog with focus trap, Esc to close and focus return.
 *
 * @param {Object}     props
 * @param {string}     props.title    Title.
 * @param {() => void} props.onClose  Close handler.
 * @param {Object}     props.children Body.
 * @param {Object}     props.footer   Footer.
 * @param {number}     props.width    Max width.
 */
export function Dialog( { title, onClose, children, footer, width = 480 } ) {
	const ref = useMergeRefs( [
		useConstrainedTabbing(),
		useFocusOnMount( 'firstElement' ),
		useFocusReturn(),
	] );
	useEscape( onClose );
	return (
		<>
			<div
				className="mh-backdrop mh-backdrop--modal"
				role="presentation"
				onClick={ onClose }
			/>
			<div
				className="mh-modal"
				role="dialog"
				aria-modal="true"
				aria-label={ title }
				ref={ ref }
				style={ { width: `min(${ width }px, calc(100vw - 32px))` } }
			>
				<div className="mh-modal__head">
					<h2 className="mh-modal__title">{ title }</h2>
					<button
						type="button"
						className="mh-icon-btn"
						onClick={ onClose }
						aria-label={ __( 'Close', 'masthead' ) }
					>
						<Icon icon={ close } size={ 20 } />
					</button>
				</div>
				<div className="mh-modal__body">{ children }</div>
				{ footer && <div className="mh-modal__foot">{ footer }</div> }
			</div>
		</>
	);
}

/**
 * Close the topmost layer on Escape. Layers register in mount order.
 */
const layers = [];
export function useEscape( onEscape, active = true ) {
	const cb = useRef( onEscape );
	cb.current = onEscape;
	useEffect( () => {
		if ( ! active ) {
			return;
		}
		const entry = { fn: () => cb.current() };
		layers.push( entry );
		const handler = ( e ) => {
			if ( e.key !== 'Escape' || layers[ layers.length - 1 ] !== entry ) {
				return;
			}
			e.stopPropagation();
			entry.fn();
		};
		window.addEventListener( 'keydown', handler );
		return () => {
			window.removeEventListener( 'keydown', handler );
			layers.splice( layers.indexOf( entry ), 1 );
		};
	}, [ active ] );
}

const ToastContext = createContext( () => {} );

/**
 * Toast provider: dark pill, bottom centre, role=status, aria-live=polite.
 * 3.2 s, or 6 s when it has an action.
 *
 * @param {Object} props
 * @param {Object} props.children Children.
 */
export function ToastProvider( { children } ) {
	const [ toast, setToast ] = useState( null );
	const timer = useRef();
	const show = useCallback( ( message, options = {} ) => {
		clearTimeout( timer.current );
		const next = {
			message,
			bad: !! options.bad,
			action: options.action,
			key: Date.now(),
		};
		setToast( next );
		timer.current = setTimeout(
			() => setToast( null ),
			options.action ? 6000 : 3200
		);
	}, [] );
	useEffect( () => () => clearTimeout( timer.current ), [] );
	return (
		<ToastContext.Provider value={ show }>
			{ children }
			<div className="mh-toast-region" role="status" aria-live="polite">
				{ toast && (
					<div className="mh-toast" key={ toast.key }>
						<Icon
							icon={ toast.bad ? cancelCircleFilled : check }
							size={ 18 }
							className={
								toast.bad
									? 'mh-toast__icon is-bad'
									: 'mh-toast__icon'
							}
						/>
						<span>{ toast.message }</span>
						{ toast.action && (
							<button
								type="button"
								className="mh-toast__action"
								onClick={ () => {
									setToast( null );
									toast.action.onClick();
								} }
							>
								{ toast.action.label }
							</button>
						) }
					</div>
				) }
			</div>
		</ToastContext.Provider>
	);
}

export const useToast = () => useContext( ToastContext );

/**
 * Small progress bar: blue, green when complete.
 *
 * @param {Object} props
 * @param {number} props.done  Done.
 * @param {number} props.total Total.
 */
export function Progress( { done, total } ) {
	const pct = total ? Math.round( ( done / total ) * 100 ) : 0;
	return (
		<div
			className="mh-progress"
			role="progressbar"
			aria-valuemin={ 0 }
			aria-valuemax={ total }
			aria-valuenow={ done }
		>
			<div
				className={
					'mh-progress__bar' +
					( total && done === total ? ' is-done' : '' )
				}
				style={ { width: pct + '%' } }
			/>
		</div>
	);
}
