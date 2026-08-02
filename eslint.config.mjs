/**
 * ESLint flat config.
 *
 * Replaces the former .eslintrc.json / .eslintignore pair — ESLint 9+ (pulled
 * in by @wordpress/scripts 32) reads neither.
 *
 * This file is .mjs rather than .js on purpose: @wordpress/eslint-plugin 25
 * loads design tokens from an ESM-only module, which throws ERR_REQUIRE_ESM
 * when the config is evaluated as CommonJS.
 */
import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';

export default [
	{
		ignores: ['build/**', 'vendor/**', 'node_modules/**', '**/*.css', '**/*.scss'],
	},
	...wordpress.configs.recommended,
	{
		languageOptions: {
			ecmaVersion: 2021,
			sourceType: 'module',
			globals: {
				...globals.browser,
				...globals.jquery,
				wp: 'readonly',
			},
		},
		rules: {
			'@wordpress/no-global-active-element': 'warn',
			'@wordpress/no-unsafe-wp-apis': 'warn',
		},
	},
];
