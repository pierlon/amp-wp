/**
 * Returns the object set on the back end via an inline script.
 *
 * @param {string} varName The name of the JS variable set
 */
export function useInlineData( varName ) {
	return global[ varName ];
}
