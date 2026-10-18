const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	ensureTeam,
	reset,
	postIn,
	tickAll,
	defaultBoard,
	drag,
	as,
} = require( '../helpers' );

test.describe( 'Rules across WordPress', () => {
	let ids;

	test.beforeAll( async ( { requestUtils } ) => {
		ids = await ensureTeam( requestUtils );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await reset( requestUtils );
	} );

	test( '4. One open checklist item blocks Scheduled, Published and editor Publish', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		const post = await postIn( requestUtils, 'ready', 'Harbour guide' );
		const wf = await tickAll( requestUtils, post.id, [] );
		const last = wf.checklist[ wf.checklist.length - 1 ].id;
		await requestUtils.rest( {
			method: 'PATCH',
			path: `/masthead/v1/posts/${ post.id }`,
			data: { checklist: { [ last ]: false } },
		} );

		for ( const stage of [ 'scheduled', 'published' ] ) {
			const err = await requestUtils
				.rest( {
					method: 'POST',
					path: `/masthead/v1/posts/${ post.id }/move`,
					data: { stage },
				} )
				.catch( ( e ) => e );
			expect( err.code ).toBe( 'masthead_checklist_open' );
		}

		await admin.editPost( post.id );
		await page
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.first()
			.click();
		const panel = page.locator( '.editor-post-publish-panel' );
		await expect(
			panel.getByText(
				'Blocked: 1 checklist item open on “Harbour guide”'
			)
		).toBeVisible();
		await expect(
			panel.getByRole( 'button', { name: 'Publish', exact: true } )
		).toBeDisabled();

		await requestUtils.rest( {
			method: 'PATCH',
			path: `/masthead/v1/posts/${ post.id }`,
			data: { checklist: { [ last ]: true } },
		} );
		const ok = await requestUtils.rest( {
			path: `/masthead/v1/posts/${ post.id }/checks`,
		} );
		expect( ok.ok ).toBe( true );
	} );

	test( '6. Status changes elsewhere move the card', async ( {
		requestUtils,
	} ) => {
		const ready = await postIn( requestUtils, 'ready', 'Sign painter' );
		await tickAll( requestUtils, ready.id );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ ready.id }`,
			data: { status: 'publish' },
		} );
		expect(
			(
				await requestUtils.rest( {
					path: `/masthead/v1/posts/${ ready.id }`,
				} )
			).stage
		).toBe( 'published' );

		const draft = await postIn( requestUtils, 'draft', 'Ferry timetable' );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ draft.id }`,
			data: { status: 'pending' },
		} );
		expect(
			(
				await requestUtils.rest( {
					path: `/masthead/v1/posts/${ draft.id }`,
				} )
			).stage
		).toBe( 'review' );
	} );

	test( '7. A Ready post dropped on a calendar day becomes Scheduled', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await postIn(
			requestUtils,
			'ready',
			'Weekend reading list'
		);
		await tickAll( requestUtils, post.id );
		await page.goto( '/wp-admin/admin.php?page=masthead-calendar' );
		const chip = page.locator( '.mh-unsched', {
			hasText: 'Weekend reading list',
		} );
		await expect( chip ).toBeVisible();

		await page.getByRole( 'button', { name: 'Next month' } ).click();
		const day = page.locator( '.mh-cal-day:not(.is-out)' ).nth( 14 );
		await drag( page, chip, day );
		await expect( page.locator( '.mh-toast' ) ).toContainText(
			'Scheduled for'
		);

		const fresh = await requestUtils.rest( {
			path: `/wp/v2/posts/${ post.id }`,
			params: { context: 'edit' },
		} );
		expect( fresh.status ).toBe( 'future' );
		const detail = await requestUtils.rest( {
			path: `/masthead/v1/posts/${ post.id }`,
		} );
		expect( detail.stage ).toBe( 'scheduled' );
		expect( fresh.date ).toBe( `${ detail.due }T09:00:00` );
	} );

	test( '8. Deleting a stage moves posts left; Undo restores both', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await postIn( requestUtils, 'ready', 'Cinema comeback' );
		await page.goto( '/wp-admin/admin.php?page=masthead-workflows' );
		await page.getByRole( 'button', { name: 'Delete Ready' } ).click();
		await page
			.getByRole( 'button', { name: 'Delete', exact: true } )
			.click();
		await expect( page.locator( '.mh-toast' ) ).toContainText(
			'Deleted Ready · 1 post moved to In review'
		);
		expect(
			(
				await requestUtils.rest( {
					path: `/masthead/v1/posts/${ post.id }`,
				} )
			).stage
		).toBe( 'review' );

		await page
			.locator( '.mh-toast' )
			.getByRole( 'button', { name: 'Undo' } )
			.click();
		await expect( page.locator( '.mh-toast' ) ).toContainText(
			'Restored Ready'
		);
		expect(
			(
				await requestUtils.rest( {
					path: `/masthead/v1/posts/${ post.id }`,
				} )
			).stage
		).toBe( 'ready' );
		const wf = await requestUtils.rest( {
			path: '/masthead/v1/workflows/articles',
		} );
		expect( wf.stages.map( ( s ) => s.id ) ).toContain( 'ready' );
	} );

	test( '9. Bulk "Move to Ready" reports moved and blocked', async ( {
		page,
		requestUtils,
	} ) => {
		const a = await postIn( requestUtils, 'review', 'Bulk A' );
		const b = await postIn( requestUtils, 'review', 'Bulk B' );
		const c = await postIn( requestUtils, 'idea', 'Bulk C' );
		await page.goto( '/wp-admin/edit.php' );
		for ( const p of [ a, b, c ] ) {
			await page.locator( `#cb-select-${ p.id }` ).check();
		}
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'masthead_move_ready' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.masthead-bulk-notice' ) ).toContainText(
			'Moved 2 to Ready · 1 blocked: Can’t skip In review. Move it there first'
		);
		await expect(
			page.locator( `#post-${ a.id } .column-masthead_stage` )
		).toContainText( 'Ready' );
	} );

	test( '10. @mentions notify and bump the bell count', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await postIn( requestUtils, 'draft', 'Dumpling house' );
		const editor = await as( 'editor' );
		const before = (
			await editor.rest( { path: '/masthead/v1/notifications' } )
		).unread;

		await page.goto(
			`/wp-admin/admin.php?page=masthead#/board/${ await defaultBoard( requestUtils ) }?post=${ post.id }`
		);
		const input = page.getByRole( 'combobox', { name: 'Leave a note' } );
		await input.fill( 'Can you look @May' );
		await page
			.locator( '#mh-mentions' )
			.getByRole( 'option', { name: /Maya Chen/ } )
			.click();
		await input.press( 'Enter' );
		await expect( page.locator( '.mh-toast' ) ).toContainText(
			'Note posted · Maya notified'
		);
		await expect(
			page.locator( '.mh-mention', { hasText: '@Maya Chen' } )
		).toBeVisible();

		const after = (
			await editor.rest( { path: '/masthead/v1/notifications' } )
		).unread;
		expect( after ).toBe( before + 1 );
		expect( ids.editor ).toBeGreaterThan( 0 );
	} );
} );
