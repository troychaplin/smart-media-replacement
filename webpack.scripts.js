const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry,
		'smart-media-replacement': path.resolve(__dirname, 'src/smart-media-replacement.js'),
		'revision-ui': path.resolve(__dirname, 'src/revision-ui.css'),
		editor: path.resolve(__dirname, 'src/editor.js'),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'build'),
		filename: '[name].js',
	},
};
