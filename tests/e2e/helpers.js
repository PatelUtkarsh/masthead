const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

const PASSWORD = process.env.MASTHEAD_E2E_PASSWORD || 'password';

const PEOPLE = {
	editor: { username: 'maya', name: 'Maya Chen', role: 'editor' },
	author: { username: 'leo', name: 'Leo Ortiz', role: 'author' },
	contributor: { username: 'ava', name: 'Ava Kim', role: 'contributor' },
};

/**
 * Create the team once (idempotent) and return their IDs.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Admin request utils.
 * @return {Promise<Record<string, number>>} User IDs by role.
 */
async function ensureTeam( requestUtils ) {
	// PHPUnit in wp-env reinstalls the shared tests database, which deactivates plugins.
	await requestUtils.activatePlugin( 'masthead' );
	const existing = await requestUtils.rest( {
		path: '/wp/v2/users',
		params: { per_page: 100, context: 'edit' },
	} );
	const ids = {};
	for ( const [ role, p ] of Object.entries( PEOPLE ) ) {
		const found = existing.find( ( u ) => u.username === p.username );
		if ( found ) {
			ids[ role ] = found.id;
			continue;
		}
		const user = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: {
				username: p.username,
				email: `${ p.username }@example.com`,
				password: PASSWORD,
				name: p.name,
				first_name: p.name.split( ' ' )[ 0 ],
				last_name: p.name.split( ' ' )[ 1 ],
				roles: [ p.role ],
			},
		} );
		ids[ role ] = user.id;
	}
	return ids;
}

/**
 * Request utils authenticated as another user.
 *
 * @param {string} role editor|author|contributor.
 * @return {Promise<RequestUtils>} Utils.
 */
async function as( role ) {
	const p = PEOPLE[ role ];
	return RequestUtils.setup( {
		user: { username: p.username, password: PASSWORD },
	} );
}

/**
 * Reset Masthead to the Editorial team workflow with no posts.
 *
 * @param {RequestUtils} requestUtils Admin utils.
 */
async function reset( requestUtils ) {
	await requestUtils.deleteAllPosts();
	await requestUtils.rest( {
		method: 'POST',
		path: '/masthead/v1/setup',
		data: {
			template: 'team',
			pages: false,
			newsletter: false,
			import: true,
		},
	} );
	await requestUtils.rest( {
		method: 'PUT',
		path: '/masthead/v1/rules',
		data: { enforce_approvals: true, auto_schedule: true, digest: false },
	} );
}

/**
 * Create a post in a stage through Masthead.
 *
 * @param {RequestUtils} requestUtils Utils.
 * @param {string}       stage        Stage ID.
 * @param {string}       title        Title.
 * @param {Object}       extra        Extra patch (author, checklist, due).
 * @return {Promise<Object>} Post detail.
 */
async function postIn( requestUtils, stage, title, extra = {} ) {
	const created = await requestUtils.rest( {
		method: 'POST',
		path: '/masthead/v1/posts',
		data: { workflow: 'articles', stage: 'idea', title },
	} );
	const order = [
		'idea',
		'draft',
		'review',
		'ready',
		'scheduled',
		'published',
	];
	for ( const s of order.slice( 1, order.indexOf( stage ) + 1 ) ) {
		if ( s === 'scheduled' || s === 'published' ) {
			await tickAll( requestUtils, created.id );
		}
		if ( s === 'scheduled' ) {
			const d = new Date( Date.now() + 5 * 864e5 )
				.toISOString()
				.slice( 0, 10 );
			await requestUtils.rest( {
				method: 'PATCH',
				path: `/masthead/v1/posts/${ created.id }`,
				data: { due: d },
			} );
		}
		await requestUtils.rest( {
			method: 'POST',
			path: `/masthead/v1/posts/${ created.id }/move`,
			data: { stage: s },
		} );
	}
	if ( Object.keys( extra ).length ) {
		await requestUtils.rest( {
			method: 'PATCH',
			path: `/masthead/v1/posts/${ created.id }`,
			data: extra,
		} );
	}
	return requestUtils.rest( { path: `/masthead/v1/posts/${ created.id }` } );
}

async function tickAll( requestUtils, id, except = [] ) {
	const wf = await requestUtils.rest( {
		path: '/masthead/v1/workflows/articles',
	} );
	const checklist = Object.fromEntries(
		wf.checklist.map( ( c ) => [ c.id, ! except.includes( c.id ) ] )
	);
	await requestUtils.rest( {
		method: 'PATCH',
		path: `/masthead/v1/posts/${ id }`,
		data: { checklist },
	} );
	return wf;
}

async function defaultBoard( requestUtils ) {
	const res = await requestUtils.rest( { path: '/masthead/v1/boards' } );
	return res.default_board;
}

async function loginAs( page, role ) {
	const p = PEOPLE[ role ];
	await page.context().clearCookies();
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', p.username );
	await page.fill( '#user_pass', PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/**
 * Pointer drag that dnd-kit recognises (needs intermediate moves).
 *
 * @param {import('@playwright/test').Page}    page Page.
 * @param {import('@playwright/test').Locator} from Source.
 * @param {import('@playwright/test').Locator} to   Target.
 */
async function drag( page, from, to ) {
	const a = await from.boundingBox();
	const b = await to.boundingBox();
	await page.mouse.move( a.x + a.width / 2, a.y + a.height / 2 );
	await page.mouse.down();
	await page.mouse.move( a.x + a.width / 2 + 10, a.y + a.height / 2 + 10, {
		steps: 5,
	} );
	await page.mouse.move( b.x + b.width / 2, b.y + 60, { steps: 15 } );
	await page.mouse.up();
}

module.exports = {
	PEOPLE,
	PASSWORD,
	ensureTeam,
	as,
	reset,
	postIn,
	tickAll,
	defaultBoard,
	loginAs,
	drag,
};
