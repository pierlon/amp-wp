/**
 * WordPress dependencies
 */
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { select, subscribe } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { Sidebar } from './sidebar';
import { ToolbarIcon, MoreMenuIcon } from './icon';

import './style.css';

const name = 'amp-sidebar';
const title = __( 'AMP for WordPress', 'amp' );

/**
 * Provides a dedicated sidebar for the plugin, with toggle buttons in the editor toolbar and more menu.
 */
function AMPPluginSidebar( ) {
	return (
		<>
			<PluginSidebarMoreMenuItem
				icon={ <MoreMenuIcon /> }
				target={ name }
			>
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				icon={ <ToolbarIcon /> }
				name={ name }
				title={ title }
			>
				<Sidebar />
			</PluginSidebar>
		</>
	);
}

const icon = MoreMenuIcon;
const render = AMPPluginSidebar;

registerPlugin( name, { icon, render } );

/**
 * Validates blocks for AMP compatibility.
 *
 * This uses the REST API response from saving a page to find validation errors.
 * If one exists for a block, it display it inline with a Notice component.
 */

/**
 * WordPress dependencies
 */

/**
 * Internal dependencies
 */
import { updateValidationErrors, maybeResetValidationErrors } from './helpers';
import './store';
import '../block-editor/store';

const { isEditedPostDirty } = select( 'core/editor' );

subscribe( () => {
	const isAMPEnabled = select( 'core/editor' ).getCurrentPost()?.amp__enabled;

	try {
		if ( ! isEditedPostDirty() ) {
			if ( ! isAMPEnabled ) {
				maybeResetValidationErrors();
			} else {
				updateValidationErrors();
			}
		}
	} catch ( err ) {}
} );

