/**
 * WordPress dependencies
 */
import { registerStore } from '@wordpress/data';

/**
 * Internal dependencies
 */
import reducer from './reducer';
import * as actions from './actions';
import * as selectors from './selectors';

/**
 * Module Constants
 */
export const MODULE_KEY = 'amp/block-validation';

export default registerStore(
	MODULE_KEY,
	{
		reducer,
		selectors,
		actions,
		initialState: {
			...window.ampBlockValidation,
			errors: [],
			reviewLink: undefined,
		},
	},
);
