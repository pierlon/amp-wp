/**
 * WordPress dependencies
 */
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

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
