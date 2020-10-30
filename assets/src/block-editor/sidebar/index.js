/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { MODULE_KEY } from '../../block-validation/store';

function Error( { title } ) {
	return (
		<span dangerouslySetInnerHTML={ { __html: title } } />
	);
}
Error.propTypes = {
	title: PropTypes.string.isRequired,
};

export function Sidebar() {
	const { validationErrors, isDirty } = useSelect( ( select ) => ( {
		validationErrors: select( MODULE_KEY ).getValidationErrors(),
		isDirty: select( 'core/editor' ).isEditedPostDirty(),
	} ) );

	return (
		<ul>
			{ validationErrors.map( ( validationError, index ) => (
				<li key={ `${ validationError.clientId }${ index }` }>
					<Error { ...validationError } />
				</li>
			) ) }
		</ul>
	);
}
