import { useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from '../../store';
import { errorMessage, isNetworkError } from '../../common/api';
import { useToast } from '../../common/components';

/**
 * Move a card with optimistic UI, toasts and Retry.
 *
 * @return {(id: number, stage: string) => Promise<Object|null>} Move function.
 */
export default function useMove() {
	const { moveCard } = useDispatch( STORE );
	const toast = useToast();

	const move = useCallback(
		async ( id, stage ) => {
			try {
				const res = await moveCard( id, stage );
				if ( res ) {
					toast( res.message );
				}
				return res;
			} catch ( error ) {
				const retry = {
					label: __( 'Retry', 'masthead' ),
					onClick: () => move( id, stage ),
				};
				if ( error?.code === 'masthead_conflict' ) {
					toast(
						__(
							'This post changed while you were looking at it',
							'masthead'
						),
						{ bad: true, action: retry }
					);
				} else if ( isNetworkError( error ) ) {
					toast(
						__(
							'Couldn’t save the move. Your board hasn’t changed',
							'masthead'
						),
						{ bad: true, action: retry }
					);
				} else {
					toast( errorMessage( error ), { bad: true } );
				}
				return null;
			}
		},
		[ moveCard, toast ]
	);
	return move;
}
