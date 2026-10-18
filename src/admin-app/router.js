import { useEffect, useState } from '@wordpress/element';

/**
 * Parse "#/board/b1?post=12" into { view, param, query }.
 *
 * @param {string} hash Location hash.
 * @return {{view: string, param: string, query: Object<string,string>}} Route.
 */
export function parse( hash ) {
	const raw = ( hash || '' ).replace( /^#\/?/, '' );
	const [ path, qs = '' ] = raw.split( '?' );
	const [ view = '', param = '' ] = path
		.split( '/' )
		.map( decodeURIComponent );
	const query = Object.fromEntries( new URLSearchParams( qs ) );
	return { view, param, query };
}

/**
 * Build a hash.
 *
 * @param {string} view  View.
 * @param {string} param Param.
 * @param {Object} query Query.
 * @return {string} Hash.
 */
export function build( view, param = '', query = {} ) {
	const qs = new URLSearchParams(
		Object.entries( query ).filter(
			( [ , v ] ) => v !== undefined && v !== null && v !== ''
		)
	).toString();
	return (
		'#/' +
		view +
		( param ? '/' + encodeURIComponent( param ) : '' ) +
		( qs ? '?' + qs : '' )
	);
}

export function navigate( view, param = '', query = {} ) {
	const next = build( view, param, query );
	if ( window.location.hash !== next ) {
		window.location.hash = next;
	}
}

export function useRoute() {
	const [ route, setRoute ] = useState( () => parse( window.location.hash ) );
	useEffect( () => {
		const onChange = () => setRoute( parse( window.location.hash ) );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );
	return route;
}

/**
 * Set or clear the drawer post in the current route.
 *
 * @param {Object}      route Current route.
 * @param {number|null} id    Post ID.
 */
export function openPost( route, id ) {
	const query = { ...route.query };
	if ( id ) {
		query.post = String( id );
	} else {
		delete query.post;
	}
	navigate( route.view, route.param, query );
}
