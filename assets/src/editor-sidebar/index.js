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
import './store';
import '../block-editor/store';
import { updateValidationErrors, maybeResetValidationErrors } from './helpers';

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
registerPlugin( name, { icon: MoreMenuIcon, render: AMPPluginSidebar } );

subscribe( () => {
	const { isEditedPostDirty, getCurrentPost } = select( 'core/editor' );
	const isAMPEnabled = getCurrentPost()?.amp_enabled;

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
