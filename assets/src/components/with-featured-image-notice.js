/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createHigherOrderComponent } from '@wordpress/compose';
import { Notice } from '@wordpress/components';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { hasMinimumFeaturedImageWidth } from '../helpers';

/**
 * Higher-order component that is used for filtering the PostFeaturedImage component.
 *
 * Used to display notices in case the image does not meet minimum requirements.
 *
 * @return {Function} Higher-order component.
 */
export default createHigherOrderComponent(
	( PostFeaturedImage ) => {
		return ( props ) => {
			const { media } = props;

			if ( ! media || hasMinimumFeaturedImageWidth( media ) ) {
				return <PostFeaturedImage { ...props } />;
			}

			return (
				<Fragment>
					<Notice
						status="warning"
						isDismissible={ false }
					>
						<span>
							{ __( 'The featured image should have a width of at least 1200px.', 'amp' ) }
						</span>
					</Notice>
					<PostFeaturedImage { ...props } />
				</Fragment>
			);
		};
	},
	'withFeaturedImageNotice'
);
