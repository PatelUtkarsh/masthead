const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	ensureTeam,
	reset,
	postIn,
	defaultBoard,
	loginAs,
	drag,
	as,
} = require( '../helpers' );

const toast = ( page ) => page.locator( '.mh-toast' );
const column = ( page, name ) =>
	page
		.locator( 'section.mh-column' )
		.filter( { has: page.getByRole( 'heading', { name, exact: true } ) } );
const card = ( page, title ) => page.locator( '.mh-card', { hasText: title } );

test.describe( 'Board', () => {
	let ids;

	test.beforeAll( async ( { requestUtils } ) => {
		ids = await ensureTeam( requestUtils );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await reset( requestUtils );
	} );

	test( '1. Ideas → Ready is blocked; stepping through each stage works', async ( {
		page,
		requestUtils,
	} ) => {
		await postIn( requestUtils, 'idea', 'The last night trains' );
		const board = await defaultBoard( requestUtils );
		await page.goto(
			`/wp-admin/admin.php?page=masthead#/board/${ board }`
		);
		await expect( card( page, 'The last night trains' ) ).toBeVisible();

		await drag(
			page,
			card( page, 'The last night trains' ),
			column( page, 'Ready' )
		);
		await expect( toast( page ) ).toContainText(
			'Can’t skip In review. Move it there first'
		);
		await expect(
			column( page, 'Ideas' ).locator( '.mh-card' )
		).toHaveCount( 1 );

		const c = card( page, 'The last night trains' );
		await c.focus();
		for ( const stage of [ 'Drafting', 'In review', 'Ready' ] ) {
			await page.keyboard.press( 'ArrowRight' );
			await expect( toast( page ) ).toContainText(
				`Moved to ${ stage }`
			);
			await expect(
				column( page, stage ).locator( '.mh-card' )
			).toHaveCount( 1 );
		}
		await expect( card( page, 'The last night trains' ) ).toBeFocused();
	} );

	test( '2. Authors cannot move out of In review; Editors can', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await postIn( requestUtils, 'review', 'Library opinion', {
			author: ids.author,
		} );
		const board = await defaultBoard( requestUtils );

		await loginAs( page, 'author' );
		await page.goto(
			`/wp-admin/admin.php?page=masthead#/board/${ board }`
		);
		await card( page, 'Library opinion' ).focus();
		await page.keyboard.press( 'ArrowRight' );
		await expect( toast( page ) ).toContainText(
			'Needs approval: only Editors can move posts out of In review'
		);

		const editor = await as( 'editor' );
		const res = await editor.rest( {
			method: 'POST',
			path: `/masthead/v1/posts/${ post.id }/move`,
			data: { stage: 'ready' },
		} );
		expect( res.post.stage ).toBe( 'ready' );
	} );

	test( '3. Contributors cannot schedule or publish', async ( {
		requestUtils,
	} ) => {
		const post = await postIn( requestUtils, 'ready', 'Bakeries', {
			author: ids.contributor,
		} );
		const contributor = await as( 'contributor' );
		for ( const stage of [ 'scheduled', 'published' ] ) {
			const err = await contributor
				.rest( {
					method: 'POST',
					path: `/masthead/v1/posts/${ post.id }/move`,
					data: { stage },
				} )
				.catch( ( e ) => e );
			expect( err.code ).toBe( 'masthead_cannot_publish' );
		}
		const err = await contributor
			.rest( {
				method: 'POST',
				path: `/wp/v2/posts/${ post.id }`,
				data: { status: 'publish' },
			} )
			.catch( ( e ) => e );
		expect( err.code ).toBeTruthy();
		const fresh = await requestUtils.rest( {
			path: `/wp/v2/posts/${ post.id }`,
			params: { context: 'edit' },
		} );
		expect( fresh.status ).not.toBe( 'publish' );
	} );

	test( '5. WIP limit blocks a 4th post; warn mode allows it and turns the count red', async ( {
		page,
		requestUtils,
	} ) => {
		for ( let i = 1; i <= 3; i++ ) {
			await postIn( requestUtils, 'review', `Review ${ i }` );
		}
		const fourth = await postIn( requestUtils, 'draft', 'Fourth' );
		const err = await requestUtils
			.rest( {
				method: 'POST',
				path: `/masthead/v1/posts/${ fourth.id }/move`,
				data: { stage: 'review' },
			} )
			.catch( ( e ) => e );
		expect( err.message ).toBe(
			'In review is full (3/3). Move something on first'
		);

		const wf = await requestUtils.rest( {
			path: '/masthead/v1/workflows/articles',
		} );
		wf.stages = wf.stages.map( ( s ) =>
			s.id === 'review' ? { ...s, limit_mode: 'warn' } : s
		);
		await requestUtils.rest( {
			method: 'PUT',
			path: '/masthead/v1/workflows/articles',
			data: wf,
		} );
		const ok = await requestUtils.rest( {
			method: 'POST',
			path: `/masthead/v1/posts/${ fourth.id }/move`,
			data: { stage: 'review' },
		} );
		expect( ok.post.stage ).toBe( 'review' );

		await page.goto(
			`/wp-admin/admin.php?page=masthead#/board/${ await defaultBoard( requestUtils ) }`
		);
		await expect(
			column( page, 'In review' ).locator( '.mh-count' )
		).toHaveText( '4 / 3' );
		await expect(
			column( page, 'In review' ).locator( '.mh-count' )
		).toHaveClass( /is-over/ );
	} );

	test( '11. Keyboard path: Tab, →, Enter, Esc', async ( {
		page,
		requestUtils,
	} ) => {
		await postIn( requestUtils, 'idea', 'Night swimming' );
		await page.goto(
			`/wp-admin/admin.php?page=masthead#/board/${ await defaultBoard( requestUtils ) }`
		);
		await expect( card( page, 'Night swimming' ) ).toBeVisible();

		// Tab from the last header control into the first card.
		await page.getByRole( 'button', { name: 'New post' } ).focus();
		let found = false;
		for ( let i = 0; i < 20 && ! found; i++ ) {
			await page.keyboard.press( 'Tab' );
			found = await card( page, 'Night swimming' ).evaluate(
				( el ) => el === el.ownerDocument.activeElement
			);
		}
		expect( found ).toBe( true );

		await page.keyboard.press( 'ArrowRight' );
		await expect( page.locator( '.mh-toast-region' ) ).toContainText(
			'Moved to Drafting'
		);
		await expect(
			column( page, 'Drafting' ).locator( '.mh-card' )
		).toHaveCount( 1 );
		await expect( card( page, 'Night swimming' ) ).toBeFocused();

		await page.keyboard.press( 'Enter' );
		const drawer = page.getByRole( 'dialog', { name: 'Night swimming' } );
		await expect( drawer ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( drawer ).toBeHidden();
	} );

	test( '12. Board filters show the right posts; a post appears on every matching board', async ( {
		page,
		requestUtils,
	} ) => {
		const cat = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/categories',
			data: { name: 'Food ' + Date.now() },
		} );
		const food = await postIn(
			requestUtils,
			'draft',
			'River market stalls',
			{ author: ids.author }
		);
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ food.id }`,
			data: { categories: [ cat.id ] },
		} );
		await postIn( requestUtils, 'draft', 'Council budget' );

		const foodBoard = await requestUtils.rest( {
			method: 'POST',
			path: '/masthead/v1/boards',
			data: {
				name: 'Food desk',
				workflow: 'articles',
				filters: { category: cat.id },
			},
		} );
		const leoBoard = await requestUtils.rest( {
			method: 'POST',
			path: '/masthead/v1/boards',
			data: {
				name: 'Leo’s posts',
				workflow: 'articles',
				filters: { author: ids.author },
			},
		} );

		await page.goto(
			`/wp-admin/admin.php?page=masthead#/board/${ foodBoard.id }`
		);
		await expect( card( page, 'River market stalls' ) ).toBeVisible();
		await expect( card( page, 'Council budget' ) ).toHaveCount( 0 );

		const detail = await requestUtils.rest( {
			path: `/masthead/v1/posts/${ food.id }`,
		} );
		const on = detail.boards.map( ( b ) => b.id );
		expect( on ).toEqual(
			expect.arrayContaining( [ foodBoard.id, leoBoard.id ] )
		);
	} );

	test( 'Empty states', async ( { page, requestUtils } ) => {
		await page.goto(
			`/wp-admin/admin.php?page=masthead#/board/${ await defaultBoard( requestUtils ) }`
		);
		await expect(
			page.getByText( 'Editorial board is empty' )
		).toBeVisible();
		await expect(
			page.getByText( 'Drop a post here' ).first()
		).toBeVisible();
		await page.getByPlaceholder( 'Search posts' ).fill( 'zzzz' );
		await expect(
			page.getByText( 'No posts on this board match “zzzz”' )
		).toBeVisible();
	} );
} );
