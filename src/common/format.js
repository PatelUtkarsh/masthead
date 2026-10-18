import { __, _n, sprintf } from '@wordpress/i18n';
import { dateI18n, humanTimeDiff } from '@wordpress/date';

/**
 * Parse a Y-m-d string as a local date at noon (avoids timezone shifts).
 *
 * @param {string} ymd Date.
 * @return {Date} Date.
 */
export function parseYmd( ymd ) {
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	return new Date( y, m - 1, d, 12 );
}

/**
 * @param {Date} date Date.
 * @return {string} Y-m-d.
 */
export function toYmd( date ) {
	const m = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const d = String( date.getDate() ).padStart( 2, '0' );
	return `${ date.getFullYear() }-${ m }-${ d }`;
}

/**
 * "Oct 17".
 *
 * @param {string|null} ymd Date.
 * @return {string} Label.
 */
export function shortDate( ymd ) {
	if ( ! ymd ) {
		return '';
	}
	return dateI18n( 'M j', parseYmd( ymd ) );
}

/**
 * Due label for a card: "Oct 17", "Overdue · Oct 17" or "No date".
 *
 * @param {Object} card Card.
 * @return {string} Label.
 */
export function dueLabel( card ) {
	if ( ! card.due ) {
		return __( 'No date', 'masthead' );
	}
	if ( card.overdue ) {
		return sprintf(
			/* translators: %s: date. */
			__( 'Overdue · %s', 'masthead' ),
			shortDate( card.due )
		);
	}
	return shortDate( card.due );
}

/**
 * "2h ago".
 *
 * @param {string} iso ISO date.
 * @return {string} Relative time.
 */
export function ago( iso ) {
	if ( ! iso ) {
		return '';
	}
	return humanTimeDiff( iso );
}

/**
 * "1,840 words".
 *
 * @param {number} n Words.
 * @return {string} Label.
 */
export function words( n ) {
	return sprintf(
		/* translators: %s: number of words. */
		_n( '%s word', '%s words', n, 'masthead' ),
		n.toLocaleString()
	);
}

/**
 * Join first names: "Maya, Sam".
 *
 * @param {Array<{first?: string, name?: string}|null>} people People.
 * @return {string} Names.
 */
export function firsts( people ) {
	return people
		.filter( Boolean )
		.map( ( p ) => p.first || p.name )
		.join( ', ' );
}
