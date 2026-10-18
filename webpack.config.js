const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin-app': path.resolve( __dirname, 'src/admin-app/index.js' ),
		'editor-plugin': path.resolve( __dirname, 'src/editor-plugin/index.js' ),
		'dashboard-widget': path.resolve( __dirname, 'src/dashboard-widget/index.js' ),
		'list-table': path.resolve( __dirname, 'src/list-table/index.js' ),
		'admin-bar': path.resolve( __dirname, 'src/admin-bar/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
