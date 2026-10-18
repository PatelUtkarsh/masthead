import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { useEffect, useState } from '@wordpress/element';

/**
 * Users load on demand. Nothing is preloaded with the page, so sites with
 * thousands of users stay fast. Results are cached for the page session.
 */
const cache = new Map();

function remember( users ) {
	users.forEach( ( u ) => cache.set( u.id, u ) );
	return users;
}

export function rememberUser( user ) {
	if ( user?.id ) {
		cache.set( user.id, user );
	}
}

export function cachedUser( id ) {
	return cache.get( Number( id ) ) || null;
}

/**
 * Search users who can view Masthead.
 *
 * @param {Object}  args
 * @param {string}  args.search    Text.
 * @param {number}  args.page      Page.
 * @param {boolean} args.approvers Only users who can approve.
 * @param {boolean} args.editors   Only users who can edit posts.
 * @param {boolean} args.anyRole   Every user with a Masthead role (admins only).
 * @param {number}  args.perPage   Page size.
 * @return {Promise<{users: Array, has_more: boolean}>} Page of users.
 */
export async function searchUsers( {
	search = '',
	page = 1,
	approvers = false,
	editors = false,
	anyRole = false,
	perPage = 20,
} = {} ) {
	const res = await apiFetch( {
		path: addQueryArgs( '/masthead/v1/users', {
			search: search || undefined,
			page,
			per_page: perPage,
			approvers: approvers ? 1 : undefined,
			editors: editors ? 1 : undefined,
			any_role: anyRole ? 1 : undefined,
		} ),
	} );
	remember( res.users );
	return res;
}

const pending = new Map();

/**
 * Load users by ID (one request for all missing IDs).
 *
 * @param {number[]} ids User IDs.
 * @return {Promise<Array>} Users.
 */
export async function loadUsers( ids ) {
	const missing = [
		...new Set( ids.map( Number ).filter( Boolean ) ),
	].filter( ( id ) => ! cache.has( id ) && ! pending.has( id ) );
	if ( missing.length ) {
		const request = apiFetch( {
			path: addQueryArgs( '/masthead/v1/users', {
				include: missing,
				per_page: missing.length,
			} ),
		} ).then( ( res ) => remember( res.users ) );
		missing.forEach( ( id ) => pending.set( id, request ) );
		try {
			await request;
		} finally {
			missing.forEach( ( id ) => pending.delete( id ) );
		}
	}
	await Promise.all(
		ids.map( ( id ) => pending.get( Number( id ) ) ).filter( Boolean )
	);
	return ids.map( ( id ) => cache.get( Number( id ) ) ).filter( Boolean );
}

/**
 * One user by ID, loaded on demand.
 *
 * @param {number|null} id       User ID.
 * @param {Object|null} fallback Known summary, used until the fetch finishes.
 * @return {Object|null} User.
 */
export function useUser( id, fallback = null ) {
	const [ user, setUser ] = useState( () =>
		id ? cachedUser( id ) || fallback : null
	);
	useEffect( () => {
		if ( ! id ) {
			setUser( null );
			return;
		}
		if ( fallback?.id === Number( id ) ) {
			rememberUser( fallback );
		}
		const hit = cachedUser( id );
		if ( hit ) {
			setUser( hit );
			return;
		}
		let live = true;
		loadUsers( [ id ] ).then( ( [ u ] ) => live && setUser( u || null ) );
		return () => {
			live = false;
		};
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps -- fallback is a seed only.
	return user;
}
