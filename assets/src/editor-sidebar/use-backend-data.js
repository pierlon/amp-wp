/**
 * Returns the object set on the back end via an inline script.
 */
export function useBackendData() {
	return global.ampPluginSidebar || {};
}
