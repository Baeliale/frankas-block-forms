const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Blocks are found through their block.json files (src/<block>/block.json).
// src/editor is not a block: it adds the settings panel to the form editor.
module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		'editor/index': './src/editor/index.js',
	} ),
};
