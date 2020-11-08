<?php
/**
 * Functionality around editor support for AMP plugin features.
 *
 * @since 2.1
 *
 * @package AmpProject\AmpWP
 */

namespace AmpProject\AmpWP\Admin;

use AMP_Post_Type_Support;
use AmpProject\AmpWP\Infrastructure\Conditional;
use AmpProject\AmpWP\Infrastructure\Delayed;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;

/**
 * EditorSupport class.
 *
 * @internal
 */
final class EditorSupport implements Conditional, Delayed, Registerable, Service {

	/**
	 * The minimum version of Gutenberg supported by editor features.
	 *
	 * @var string
	 */
	const GB_MIN_VERSION = '5.4.0';

	/**
	 * The minimum version of WordPress supported by editor features.
	 *
	 * @var string
	 */
	const WP_MIN_VERSION = '5.2';

	/**
	 * Check whether the conditional object is currently needed.
	 *
	 * @return bool Whether the conditional object is needed.
	 */
	public static function is_needed() {
		return is_admin();
	}

	/**
	 * Get the action to use for registering the service.
	 *
	 * @return string Registration action to use.
	 */
	public static function get_registration_action() {
		return 'admin_init';
	}

	/**
	 * Runs on instantiation.
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_show_notice' ], 99 );
	}

	/**
	 * Shows a notice in the editor if the Gutenberg or WP version prevents plugin features from working.
	 */
	public function maybe_show_notice() {
		if ( ! $this->post_supports_amp() ) {
			return;
		}

		if ( ! $this->editor_supports_amp_plugin() ) {
			if ( current_user_can( 'manage_options' ) ) {
				wp_add_inline_script(
					'wp-edit-post',
					sprintf(
						'wp.domReady(
							function () {
								wp.data.dispatch( "core/notices" ).createWarningNotice( %s )
							}
						);',
						wp_json_encode( __( 'AMP functionality is not available since your version of the Block Editor is too old. Please either update WordPress core to the latest version or activate the Gutenberg plugin. As a last resort, you may use the Classic Editor plugin instead.', 'amp' ) )
					)
				);
			}
		}
	}

	/**
	 * Runs on instantiation.
	 *
	 * @param int|WP_Post|null $post The current post, or null to use the global post objecdt.
	 */
	public function post_supports_amp( $post = null ) {
		$post = get_post( $post );

		if ( empty( $post ) ) {
			return false;
		}

		return in_array( $post->post_type, AMP_Post_Type_Support::get_eligible_post_types(), true );
	}

	/**
	 * Returns whether the editor in the current environment supports plugin features.
	 *
	 * @return boolean
	 */
	public function editor_supports_amp_plugin() {
		return $this->gb_plugin_supports_editor_features() || $this->wp_core_supports_editor_features();
	}

	/**
	 * Returns whether the Gutenberg plugin provides minimal support.
	 *
	 * @return boolean
	 */
	public function gb_plugin_supports_editor_features() {
		return defined( 'GUTENBERG_VERSION' ) && version_compare( GUTENBERG_VERSION, self::GB_MIN_VERSION, '>=' );
	}

	/**
	 * Returns whether WP core provides minimum Gutenberg support.
	 *
	 * @return boolean
	 */
	public function wp_core_supports_editor_features() {
		return ! $this->gb_plugin_supports_editor_features() && version_compare( get_bloginfo( 'version' ), self::WP_MIN_VERSION, '>=' );
	}
}
