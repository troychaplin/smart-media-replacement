const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry,
		'smart-media-replacement': path.resolve(__dirname, 'src/smart-media-replacement.js'),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'build'),
		filename: '[name].js',
	},
};
