const path = require( 'path' );
const base = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...base,
	testDir: path.join( __dirname, 'specs' ),
	use: {
		...base.use,
		viewport: { width: 1400, height: 900 },
	},
};
