import { createReduxStore, register } from '@wordpress/data';
import { api } from '../common/api';

export const STORE = 'masthead/core';

const DEFAULT = {
	boot: null,
	board: {
		id: null,
		columns: {},
		counts: {},
		loading: false,
		error: null,
		query: { search: '', author: 0 },
	},
};

function replaceCard( columns, card ) {
	const out = {};
	for ( const [ stage, col ] of Object.entries( columns ) ) {
		out[ stage ] = {
			...col,
			cards: col.cards.filter( ( c ) => c.id !== card.id ),
		};
	}
	return out;
}

function reducer( state = DEFAULT, action ) {
	switch ( action.type ) {
		case 'SET_BOOT':
			return { ...state, boot: action.boot };
		case 'PATCH_BOOT':
			return { ...state, boot: { ...state.boot, ...action.patch } };
		case 'BOARD_LOADING':
			return {
				...state,
				board: {
					...state.board,
					id: action.id,
					query: action.query,
					loading: true,
					error: null,
					columns:
						action.id === state.board.id ? state.board.columns : {},
				},
			};
		case 'BOARD_LOADED':
			if ( action.id !== state.board.id ) {
				return state;
			}
			return {
				...state,
				board: {
					...state.board,
					loading: false,
					columns: action.columns,
					counts: action.counts,
				},
			};
		case 'BOARD_ERROR':
			return {
				...state,
				board: { ...state.board, loading: false, error: action.error },
			};
		case 'COLUMN_PAGE': {
			const col = state.board.columns[ action.stage ];
			if ( ! col ) {
				return state;
			}
			const seen = new Set( col.cards.map( ( c ) => c.id ) );
			return {
				...state,
				board: {
					...state.board,
					columns: {
						...state.board.columns,
						[ action.stage ]: {
							...action.column,
							cards: [
								...col.cards,
								...action.column.cards.filter(
									( c ) => ! seen.has( c.id )
								),
							],
						},
					},
				},
			};
		}
		case 'PLACE_CARD': {
			const { card, index } = action;
			let from = null;
			let fromIndex = 0;
			for ( const [ stage, col ] of Object.entries(
				state.board.columns
			) ) {
				const i = col.cards.findIndex( ( x ) => x.id === card.id );
				if ( i >= 0 ) {
					from = stage;
					fromIndex = i;
				}
			}
			const columns = replaceCard( state.board.columns, card );
			const counts = { ...state.board.counts };
			const moved = from !== card.stage;
			if ( from && moved ) {
				columns[ from ] = {
					...columns[ from ],
					total:
						columns[ from ].total === null
							? null
							: Math.max( 0, columns[ from ].total - 1 ),
				};
				counts[ from ] = Math.max( 0, ( counts[ from ] || 1 ) - 1 );
			}
			const target = columns[ card.stage ];
			if ( target ) {
				const cards = [ ...target.cards ];
				const at = moved ? ( index ?? 0 ) : fromIndex;
				cards.splice( Math.min( at, cards.length ), 0, card );
				columns[ card.stage ] = {
					...target,
					cards,
					total:
						moved && target.total !== null
							? target.total + 1
							: target.total,
				};
			}
			if ( moved ) {
				counts[ card.stage ] = ( counts[ card.stage ] || 0 ) + 1;
			}
			return { ...state, board: { ...state.board, columns, counts } };
		}
		case 'REMOVE_CARD': {
			const columns = {};
			for ( const [ stage, col ] of Object.entries(
				state.board.columns
			) ) {
				const had = col.cards.some( ( c ) => c.id === action.id );
				columns[ stage ] = had
					? {
							...col,
							cards: col.cards.filter(
								( c ) => c.id !== action.id
							),
							total: col.total === null ? null : col.total - 1,
						}
					: col;
			}
			return { ...state, board: { ...state.board, columns } };
		}
		case 'RESTORE_BOARD':
			return {
				...state,
				board: {
					...state.board,
					columns: action.columns,
					counts: action.counts,
				},
			};
	}
	return state;
}

const actions = {
	setBoot: ( boot ) => ( { type: 'SET_BOOT', boot } ),
	patchBoot: ( patch ) => ( { type: 'PATCH_BOOT', patch } ),

	refreshBoot:
		() =>
		async ( { dispatch } ) => {
			const boot = await api.bootstrap();
			dispatch( { type: 'SET_BOOT', boot } );
			return boot;
		},

	loadBoard:
		( id, query = { search: '', author: 0 } ) =>
		async ( { dispatch } ) => {
			dispatch( { type: 'BOARD_LOADING', id, query } );
			try {
				const res = await api.board( id, {
					search: query.search || undefined,
					author: query.author || undefined,
				} );
				dispatch( {
					type: 'BOARD_LOADED',
					id,
					columns: res.columns || {},
					counts: res.counts || {},
				} );
				return res;
			} catch ( error ) {
				dispatch( { type: 'BOARD_ERROR', error } );
				throw error;
			}
		},

	loadMore:
		( stage ) =>
		async ( { dispatch, select } ) => {
			const board = select.getBoardState();
			const col = board.columns[ stage ];
			if ( ! col ) {
				return;
			}
			const res = await api.board( board.id, {
				stage,
				page: col.page + 1,
				search: board.query.search || undefined,
				author: board.query.author || undefined,
			} );
			dispatch( {
				type: 'COLUMN_PAGE',
				stage,
				column: res.columns[ stage ],
			} );
		},

	placeCard: ( card, index ) => ( { type: 'PLACE_CARD', card, index } ),
	removeCard: ( id ) => ( { type: 'REMOVE_CARD', id } ),

	/**
	 * Optimistic move. Resolves with the server response; rejects (after rolling back) on failure.
	 *
	 * @param {number} id    Post ID.
	 * @param {string} stage Target stage ID.
	 */
	moveCard:
		( id, stage ) =>
		async ( { dispatch, select } ) => {
			const card = select.getCard( id );
			if ( ! card || card.stage === stage ) {
				return null;
			}
			const board = select.getBoardState();
			const snapshot = { columns: board.columns, counts: board.counts };
			dispatch( { type: 'PLACE_CARD', card: { ...card, stage } } );
			try {
				const res = await api.move( id, stage, card.rev );
				dispatch( { type: 'PLACE_CARD', card: res.post } );
				return res;
			} catch ( error ) {
				dispatch( { type: 'RESTORE_BOARD', ...snapshot } );
				if ( error?.data?.card ) {
					dispatch( { type: 'PLACE_CARD', card: error.data.card } );
				}
				throw error;
			}
		},
};

const selectors = {
	getBoot: ( state ) => state.boot,
	getUser: ( state ) => state.boot?.user,
	getWorkflows: ( state ) => state.boot?.workflows || [],
	getWorkflow: ( state, key ) =>
		( state.boot?.workflows || [] ).find( ( w ) => w.key === key ),
	getBoards: ( state ) => state.boot?.boards || [],
	getBoardConfig: ( state, id ) =>
		( state.boot?.boards || [] ).find( ( b ) => b.id === id ),
	getDefaultBoard: ( state ) => state.boot?.default_board,
	getRules: ( state ) => state.boot?.rules,
	getBoardState: ( state ) => state.board,
	getCard: ( state, id ) => {
		for ( const col of Object.values( state.board.columns ) ) {
			const c = col.cards.find( ( x ) => x.id === id );
			if ( c ) {
				return c;
			}
		}
		return null;
	},
};

export const store = createReduxStore( STORE, { reducer, actions, selectors } );
register( store );
