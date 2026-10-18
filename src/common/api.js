import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const NS = '/masthead/v1';

/**
 * Masthead REST client. Every call goes through api-fetch, which adds the REST nonce.
 */
export const api = {
	bootstrap: () => apiFetch( { path: `${ NS }/bootstrap` } ),

	boards: ( query = {} ) =>
		apiFetch( { path: addQueryArgs( `${ NS }/boards`, query ) } ),
	board: ( id, query = {} ) =>
		apiFetch( {
			path: addQueryArgs( `${ NS }/boards/${ id }`, {
				include: 'cards',
				...query,
			} ),
		} ),
	boardCount: ( workflow, filters ) =>
		apiFetch( {
			path: addQueryArgs( `${ NS }/boards/count`, {
				workflow,
				'filters[category]': filters.category || undefined,
				'filters[author]': filters.author || undefined,
			} ),
		} ),
	createBoard: ( data ) =>
		apiFetch( { path: `${ NS }/boards`, method: 'POST', data } ),
	updateBoard: ( id, data ) =>
		apiFetch( { path: `${ NS }/boards/${ id }`, method: 'PUT', data } ),
	deleteBoard: ( id ) =>
		apiFetch( { path: `${ NS }/boards/${ id }`, method: 'DELETE' } ),

	workflows: () => apiFetch( { path: `${ NS }/workflows` } ),
	saveWorkflow: ( key, data ) =>
		apiFetch( { path: `${ NS }/workflows/${ key }`, method: 'PUT', data } ),
	createWorkflow: ( data ) =>
		apiFetch( { path: `${ NS }/workflows`, method: 'POST', data } ),
	deleteWorkflow: ( key ) =>
		apiFetch( { path: `${ NS }/workflows/${ key }`, method: 'DELETE' } ),
	deleteStage: ( key, stage ) =>
		apiFetch( {
			path: `${ NS }/workflows/${ key }/stages/${ stage }`,
			method: 'DELETE',
		} ),
	undo: ( token ) =>
		apiFetch( {
			path: `${ NS }/workflows/undo`,
			method: 'POST',
			data: { token },
		} ),
	saveRules: ( data ) =>
		apiFetch( { path: `${ NS }/rules`, method: 'PUT', data } ),
	setup: ( data ) =>
		apiFetch( { path: `${ NS }/setup`, method: 'POST', data } ),

	post: ( id ) => apiFetch( { path: `${ NS }/posts/${ id }` } ),
	createPost: ( data ) =>
		apiFetch( { path: `${ NS }/posts`, method: 'POST', data } ),
	move: ( id, stage, rev ) =>
		apiFetch( {
			path: `${ NS }/posts/${ id }/move`,
			method: 'POST',
			data: rev === undefined ? { stage } : { stage, rev },
		} ),
	bulkMove: ( ids, stage ) =>
		apiFetch( {
			path: `${ NS }/posts/move`,
			method: 'POST',
			data: { ids, stage },
		} ),
	patch: ( id, data ) =>
		apiFetch( { path: `${ NS }/posts/${ id }`, method: 'PATCH', data } ),
	checks: ( id, target ) =>
		apiFetch( {
			path: addQueryArgs( `${ NS }/posts/${ id }/checks`, { target } ),
		} ),
	notes: ( id ) => apiFetch( { path: `${ NS }/posts/${ id }/notes` } ),
	addNote: ( id, text, mentions ) =>
		apiFetch( {
			path: `${ NS }/posts/${ id }/notes`,
			method: 'POST',
			data: { text, mentions },
		} ),
	activity: ( id ) => apiFetch( { path: `${ NS }/posts/${ id }/activity` } ),

	calendar: ( month, workflows, query = {} ) =>
		apiFetch( {
			path: addQueryArgs( `${ NS }/calendar`, {
				month,
				workflows: workflows.join( ',' ),
				...query,
			} ),
		} ),

	notifications: ( unread = false ) =>
		apiFetch( {
			path: addQueryArgs( `${ NS }/notifications`, {
				unread: unread ? 1 : undefined,
			} ),
		} ),
	readNotifications: ( ids ) =>
		apiFetch( {
			path: `${ NS }/notifications/read`,
			method: 'POST',
			data: ids ? { ids } : {},
		} ),
};

/**
 * Human message for an api-fetch error.
 *
 * @param {unknown} error Error thrown by api-fetch.
 * @return {string} Message.
 */
export function errorMessage( error ) {
	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( error.message );
	}
	return String( error );
}

/**
 * Is this a network failure rather than a rule failure?
 *
 * @param {unknown} error Error thrown by api-fetch.
 * @return {boolean} True when the request never got a REST response.
 */
export function isNetworkError( error ) {
	return (
		!! error &&
		typeof error === 'object' &&
		( error.code === 'fetch_error' || error.code === 'invalid_json' )
	);
}
