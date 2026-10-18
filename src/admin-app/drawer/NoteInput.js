import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Avatar } from '../../common/components';
import { searchUsers } from '../../common/users';

/**
 * Note input with \@mention autocomplete. People load from the server as you
 * type, so this works with any team size. Enter submits; when the list is
 * open, Enter picks.
 *
 * @param {Object}                                                   props
 * @param {number}                                                   props.selfId      Current user ID (never suggested).
 * @param {(text: string, mentionIds: number[]) => Promise<unknown>} props.onSubmit    Submit handler.
 * @param {string}                                                   props.placeholder Placeholder.
 */
export default function NoteInput( { selfId, onSubmit, placeholder } ) {
	const [ text, setText ] = useState( '' );
	const [ query, setQuery ] = useState( null );
	const [ matches, setMatches ] = useState( [] );
	const [ index, setIndex ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const picked = useRef( new Map() );
	const input = useRef();
	const seq = useRef( 0 );

	useEffect( () => {
		if ( query === null ) {
			setMatches( [] );
			return;
		}
		const n = ++seq.current;
		const t = setTimeout( () => {
			searchUsers( { search: query, perPage: 6 } ).then(
				( r ) => {
					if ( n === seq.current ) {
						setMatches(
							r.users.filter( ( u ) => u.id !== selfId )
						);
						setIndex( 0 );
					}
				},
				() => {}
			);
		}, 200 );
		return () => clearTimeout( t );
	}, [ query, selfId ] );

	const detect = ( value, caret ) => {
		const before = value.slice( 0, caret );
		const m = before.match( /(?:^|\s)@([^@\n]{0,30})$/ );
		setQuery( m && ! /\s{2}/.test( m[ 1 ] ) ? m[ 1 ] : null );
	};

	const pick = ( person ) => {
		const el = input.current;
		const caret = el.selectionStart ?? text.length;
		const before = text
			.slice( 0, caret )
			.replace( /@([^@\n]{0,30})$/, '@' + person.name + ' ' );
		picked.current.set( person.id, person.name );
		setText( before + text.slice( caret ) );
		setQuery( null );
		window.requestAnimationFrame( () => {
			el.focus();
			el.setSelectionRange( before.length, before.length );
		} );
	};

	const submit = async () => {
		const value = text.trim();
		if ( ! value || busy ) {
			return;
		}
		const ids = [ ...picked.current.entries() ]
			.filter( ( [ , name ] ) => value.includes( '@' + name ) )
			.map( ( [ id ] ) => id );
		setBusy( true );
		try {
			await onSubmit( value, ids );
			setText( '' );
			picked.current.clear();
		} finally {
			setBusy( false );
		}
	};

	const open = query !== null && matches.length > 0;
	const listId = 'mh-mentions';

	return (
		<div className="mh-note-input">
			{ open && (
				<ul
					className="mh-popover mh-mentions"
					id={ listId }
					role="listbox"
					aria-label={ __( 'Mention someone', 'masthead' ) }
				>
					{ matches.map( ( p, i ) => (
						<li
							key={ p.id }
							role="option"
							aria-selected={ i === index }
							id={ `mh-mention-${ p.id }` }
						>
							<button
								type="button"
								tabIndex={ -1 }
								className={
									'mh-mentions__opt' +
									( i === index ? ' is-active' : '' )
								}
								onMouseDown={ ( e ) => {
									e.preventDefault();
									pick( p );
								} }
							>
								<Avatar user={ p } size={ 22 } />
								<span className="mh-mentions__name">
									{ p.name }
								</span>
								<span className="mh-muted">{ p.role }</span>
							</button>
						</li>
					) ) }
				</ul>
			) }
			<div className="mh-note-input__row">
				<label className="screen-reader-text" htmlFor="mh-note-text">
					{ __( 'Leave a note', 'masthead' ) }
				</label>
				<input
					id="mh-note-text"
					ref={ input }
					type="text"
					value={ text }
					placeholder={ placeholder }
					role="combobox"
					aria-expanded={ open }
					aria-controls={ open ? listId : undefined }
					aria-autocomplete="list"
					aria-activedescendant={
						open
							? `mh-mention-${ matches[ index ]?.id }`
							: undefined
					}
					onChange={ ( e ) => {
						setText( e.target.value );
						detect(
							e.target.value,
							e.target.selectionStart ?? e.target.value.length
						);
					} }
					onKeyDown={ ( e ) => {
						if (
							open &&
							( e.key === 'ArrowDown' || e.key === 'ArrowUp' )
						) {
							e.preventDefault();
							setIndex(
								( i ) =>
									( i +
										( e.key === 'ArrowDown'
											? 1
											: matches.length - 1 ) ) %
									matches.length
							);
							return;
						}
						if (
							open &&
							( e.key === 'Enter' || e.key === 'Tab' )
						) {
							e.preventDefault();
							pick( matches[ index ] );
							return;
						}
						if ( open && e.key === 'Escape' ) {
							e.preventDefault();
							e.stopPropagation();
							setQuery( null );
							return;
						}
						if ( e.key === 'Enter' ) {
							e.preventDefault();
							submit();
						}
					} }
				/>
				<button
					type="button"
					className="mh-btn mh-btn--dark mh-btn--sm"
					onClick={ submit }
					disabled={ busy || ! text.trim() }
				>
					{ __( 'Post', 'masthead' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * Render note text with mentions in the accent colour.
 *
 * @param {Object} props
 * @param {string} props.text     Note.
 * @param {Array}  props.mentions Mentioned users ({ name }).
 */
export function NoteText( { text, mentions = [] } ) {
	const names = mentions
		.map( ( p ) => p?.name )
		.filter( Boolean )
		.sort( ( a, b ) => b.length - a.length );
	if ( ! names.length ) {
		return text;
	}
	const esc = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const re = new RegExp( '(@(?:' + names.map( esc ).join( '|' ) + '))', 'g' );
	return text.split( re ).map( ( part, i ) =>
		i % 2 === 1 ? (
			<span key={ i } className="mh-mention">
				{ part }
			</span>
		) : (
			part
		)
	);
}
