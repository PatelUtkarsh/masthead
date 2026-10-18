import {
	createPortal,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Icon, chevronDown, close } from '@wordpress/icons';
import { Avatar } from './components';
import { searchUsers, useUser } from './users';

/**
 * Searchable combobox that loads options on demand, one page at a time.
 *
 * @param {Object}                                                              props
 * @param {string}                                                              props.label        Accessible label.
 * @param {Object|null}                                                         props.selected     Selected option ({ id, name }).
 * @param {(opt: Object|null) => void}                                          props.onChange     Change handler.
 * @param {(q: string, page: number) => Promise<{items: Array, more: boolean}>} props.load         Loader.
 * @param {(opt: Object) => Object}                                             props.renderOption Option renderer.
 * @param {string}                                                              props.noneLabel    Label of the empty choice, or '' for none.
 * @param {string}                                                              props.placeholder  Placeholder.
 * @param {boolean}                                                             props.disabled     Disabled.
 * @param {string}                                                              props.id           Input ID.
 */
export function AsyncPicker( {
	label,
	selected,
	onChange,
	load,
	renderOption,
	noneLabel = '',
	placeholder = __( 'Search…', 'masthead' ),
	disabled = false,
	id,
} ) {
	const auto = useId();
	const inputId = id || `mh-picker-${ auto }`;
	const listId = `${ inputId }-list`;
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ items, setItems ] = useState( [] );
	const [ page, setPage ] = useState( 1 );
	const [ more, setMore ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ active, setActive ] = useState( 0 );
	const wrap = useRef();
	const input = useRef();
	const seq = useRef( 0 );
	const [ pos, setPos ] = useState( null );
	const list = useRef();

	// The list is positioned against the viewport so scrolling containers
	// (modal body, drawer) never clip it.
	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const place = () => {
			const r = wrap.current?.getBoundingClientRect();
			if ( ! r ) {
				return;
			}
			const below = window.innerHeight - r.bottom;
			const up = below < 220 && r.top > below;
			setPos( {
				left: r.left,
				width: Math.max( r.width, 240 ),
				...( up
					? { bottom: window.innerHeight - r.top + 4 }
					: { top: r.bottom + 4 } ),
			} );
		};
		place();
		window.addEventListener( 'resize', place );
		window.addEventListener( 'scroll', place, true );
		return () => {
			window.removeEventListener( 'resize', place );
			window.removeEventListener( 'scroll', place, true );
		};
	}, [ open ] );

	const options = noneLabel
		? [ { id: 0, name: noneLabel, none: true }, ...items ]
		: items;

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const n = ++seq.current;
		setBusy( true );
		const t = setTimeout(
			() =>
				load( query, 1 ).then(
					( r ) => {
						if ( n === seq.current ) {
							setItems( r.items );
							setMore( r.more );
							setPage( 1 );
							setActive( 0 );
							setBusy( false );
						}
					},
					() => n === seq.current && setBusy( false )
				),
			query ? 250 : 0
		);
		return () => clearTimeout( t );
	}, [ open, query ] ); // eslint-disable-line react-hooks/exhaustive-deps -- load is stable per picker.

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const onDoc = ( e ) => {
			if (
				! wrap.current?.contains( e.target ) &&
				! list.current?.contains( e.target )
			) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', onDoc );
		return () => document.removeEventListener( 'mousedown', onDoc );
	}, [ open ] );

	const loadMore = async () => {
		setBusy( true );
		try {
			const r = await load( query, page + 1 );
			setItems( ( prev ) => [
				...prev,
				...r.items.filter(
					( x ) => ! prev.some( ( p ) => p.id === x.id )
				),
			] );
			setMore( r.more );
			setPage( page + 1 );
		} finally {
			setBusy( false );
		}
	};

	const choose = ( opt ) => {
		onChange( opt?.none ? null : opt );
		setOpen( false );
		setQuery( '' );
		input.current?.blur();
	};

	const onKeyDown = ( e ) => {
		if ( ! open && ( e.key === 'ArrowDown' || e.key === 'Enter' ) ) {
			e.preventDefault();
			setOpen( true );
			return;
		}
		if ( ! open ) {
			return;
		}
		if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			const n = options.length;
			if ( n ) {
				setActive(
					( a ) => ( a + ( e.key === 'ArrowDown' ? 1 : n - 1 ) ) % n
				);
			}
		} else if ( e.key === 'Enter' ) {
			e.preventDefault();
			if ( options[ active ] ) {
				choose( options[ active ] );
			}
		} else if ( e.key === 'Escape' ) {
			e.preventDefault();
			e.stopPropagation();
			setOpen( false );
		}
	};

	return (
		<div
			className={ 'mh-picker' + ( disabled ? ' is-disabled' : '' ) }
			ref={ wrap }
		>
			<div className="mh-picker__control">
				{ ! open && selected && renderOption( selected, true ) }
				<input
					ref={ input }
					id={ inputId }
					className="mh-picker__input"
					type="text"
					role="combobox"
					aria-label={ label }
					aria-expanded={ open }
					aria-controls={ listId }
					aria-autocomplete="list"
					aria-activedescendant={
						open && options[ active ]
							? `${ listId }-${ options[ active ].id }`
							: undefined
					}
					disabled={ disabled }
					value={ open ? query : '' }
					placeholder={ open || ! selected ? placeholder : '' }
					onFocus={ () => setOpen( true ) }
					onChange={ ( e ) => {
						setQuery( e.target.value );
						setOpen( true );
					} }
					onKeyDown={ onKeyDown }
				/>
				{ selected && noneLabel && ! disabled && (
					<button
						type="button"
						className="mh-icon-btn mh-icon-btn--sm"
						aria-label={ __( 'Clear', 'masthead' ) }
						onClick={ () => choose( null ) }
					>
						<Icon icon={ close } size={ 16 } />
					</button>
				) }
				<Icon
					icon={ chevronDown }
					size={ 18 }
					className="mh-picker__chevron"
				/>
			</div>
			{ open &&
				createPortal(
					<div className="mh-root mh-picker-portal">
						<ul
							ref={ list }
							className="mh-popover mh-picker__list"
							id={ listId }
							role="listbox"
							aria-label={ label }
							aria-busy={ busy }
							style={
								pos ? { position: 'fixed', ...pos } : undefined
							}
						>
							{ options.map( ( opt, i ) => (
								<li
									key={ opt.id }
									id={ `${ listId }-${ opt.id }` }
									role="option"
									aria-selected={ i === active }
									className={
										'mh-picker__opt' +
										( i === active ? ' is-active' : '' ) +
										( selected?.id === opt.id
											? ' is-selected'
											: '' )
									}
									onMouseDown={ ( e ) => {
										e.preventDefault();
										choose( opt );
									} }
									onMouseEnter={ () => setActive( i ) }
								>
									{ opt.none ? (
										<span className="mh-muted">
											{ opt.name }
										</span>
									) : (
										renderOption( opt, false )
									) }
								</li>
							) ) }
							{ ! busy && ! items.length && (
								<li className="mh-picker__empty">
									{ __( 'No matches', 'masthead' ) }
								</li>
							) }
							{ busy && (
								<li className="mh-picker__empty">
									{ __( 'Loading…', 'masthead' ) }
								</li>
							) }
							{ more && ! busy && (
								<li className="mh-picker__more">
									<button
										type="button"
										className="mh-btn mh-btn--ghost mh-btn--sm mh-btn--block"
										onMouseDown={ ( e ) =>
											e.preventDefault()
										}
										onClick={ loadMore }
									>
										{ __( 'Load more', 'masthead' ) }
									</button>
								</li>
							) }
						</ul>
					</div>,
					document.body
				) }
		</div>
	);
}

const renderUser = ( u ) => (
	<span className="mh-picker__user">
		<Avatar user={ u } size={ 22 } />
		<span className="mh-picker__name">{ u.name }</span>
		{ u.role && <span className="mh-muted">{ u.role }</span> }
	</span>
);

/**
 * Lazy user picker.
 *
 * @param {Object}                   props
 * @param {number|null}              props.value     Selected user ID.
 * @param {Object|null}              props.initial   Known summary of the selected user.
 * @param {(u: Object|null) => void} props.onChange  Change handler.
 * @param {string}                   props.label     Accessible label.
 * @param {string}                   props.noneLabel Empty choice label.
 * @param {boolean}                  props.approvers Only approvers.
 * @param {boolean}                  props.editors   Only users who can edit posts.
 * @param {boolean}                  props.disabled  Disabled.
 * @param {string}                   props.id        Input ID.
 */
export function UserPicker( {
	value,
	initial = null,
	onChange,
	label,
	noneLabel = '',
	approvers = false,
	editors = false,
	disabled = false,
	id,
} ) {
	const selected = useUser( value || null, initial );
	return (
		<AsyncPicker
			id={ id }
			label={ label }
			selected={ selected }
			onChange={ onChange }
			noneLabel={ noneLabel }
			disabled={ disabled }
			placeholder={ __( 'Search people…', 'masthead' ) }
			renderOption={ renderUser }
			load={ async ( q, page ) => {
				const r = await searchUsers( {
					search: q,
					page,
					approvers,
					editors,
				} );
				return { items: r.users, more: r.has_more };
			} }
		/>
	);
}

/**
 * Lazy category picker (core REST, 20 per page).
 *
 * @param {Object}                   props
 * @param {Object|null}              props.selected  Selected category ({ id, name }).
 * @param {(c: Object|null) => void} props.onChange  Change handler.
 * @param {string}                   props.label     Accessible label.
 * @param {string}                   props.noneLabel Empty choice label.
 */
export function CategoryPicker( { selected, onChange, label, noneLabel } ) {
	return (
		<AsyncPicker
			label={ label }
			selected={ selected }
			onChange={ onChange }
			noneLabel={ noneLabel }
			placeholder={ __( 'Search categories…', 'masthead' ) }
			renderOption={ ( c ) => (
				<span className="mh-picker__name">{ c.name }</span>
			) }
			load={ async ( q, page ) => {
				const res = await apiFetch( {
					path: addQueryArgs( '/wp/v2/categories', {
						search: q || undefined,
						offset: ( page - 1 ) * 20,
						per_page: 21,
						orderby: q ? 'name' : 'count',
						order: q ? 'asc' : 'desc',
						_fields: 'id,name',
					} ),
				} );
				const items = res.slice( 0, 20 ).map( ( c ) => ( {
					id: c.id,
					name: new window.DOMParser().parseFromString(
						c.name,
						'text/html'
					).body.textContent,
				} ) );
				return { items, more: res.length > 20 };
			} }
		/>
	);
}
