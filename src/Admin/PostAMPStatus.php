<?php
/**
 * AMP meta box settings for the classic editor.
 *
 * @package AMP
 * @since 0.6
 */

namespace AmpProject\AmpWP\Admin;

use AMP_Options_Manager;
use AMP_Post_Type_Support;
use AMP_Theme_Support;
use AmpProject\AmpWP\Infrastructure\Conditional;
use AmpProject\AmpWP\Infrastructure\Delayed;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;

/**
 * Post AMP status handler.
 *
 * @since 0.6
 * @internal
 */
final class PostAMPStatus implements Conditional, Delayed, Service, Registerable {

	/**
	 * The name for the REST API field containing whether AMP is enabled for a post.
	 *
	 * @since 2.0
	 * @var string
	 */
	const REST_FIELD_NAME_AMP_ENABLED = 'amp_enabled';

	/**
	 * The enabled status post meta value.
	 *
	 * @since 0.6
	 * @var string
	 */
	const ENABLED_STATUS = 'enabled';

	/**
	 * The disabled status post meta value.
	 *
	 * @since 0.6
	 * @var string
	 */
	const DISABLED_STATUS = 'disabled';

	/**
	 * The status post meta key.
	 *
	 * @since 0.6
	 * @var string
	 */
	const STATUS_POST_META_KEY = 'amp_status';

	/**
	 * The nonce name.
	 *
	 * @since 0.6
	 * @var string
	 */
	const NONCE_NAME = 'amp-status-nonce';

	/**
	 * The nonce action.
	 *
	 * @since 0.6
	 * @var string
	 */
	const NONCE_ACTION = 'amp-update-status';

	/**
	 * The field name for the enabled/disabled radio buttons.
	 *
	 * @since 0.6
	 * @var string
	 */
	const STATUS_INPUT_NAME = 'amp_status';

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
	 * Initialize.
	 *
	 * @since 0.6
	 */
	public function register() {
		register_meta(
			'post',
			self::STATUS_POST_META_KEY,
			[
				'sanitize_callback' => [ $this, 'sanitize_status' ],
				'auth_callback'     => '__return_false',
				'type'              => 'string',
				'description'       => __( 'AMP status.', 'amp' ),
				'show_in_rest'      => false,
				'single'            => true,
			]
		);

		add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
	}

	/**
	 * Registers REST fields needed for the plugin sidebar.
	 */
	public function register_rest_fields() {
		register_rest_field(
			AMP_Post_Type_Support::get_post_types_for_rest_api(),
			self::REST_FIELD_NAME_AMP_ENABLED,
			[
				'get_callback'    => [ $this, 'get_amp_enabled_rest_field' ],
				'update_callback' => [ $this, 'update_amp_enabled_rest_field' ],
				'schema'          => [
					'description' => __( 'AMP enabled', 'amp' ),
					'type'        => 'boolean',
				],
			]
		);
	}

	/**
	 * Sanitize status.
	 *
	 * @param string $status Status.
	 * @return string Sanitized status. Empty string when invalid.
	 */
	public function sanitize_status( $status ) {
		$status = strtolower( trim( $status ) );
		if ( ! in_array( $status, [ self::ENABLED_STATUS, self::DISABLED_STATUS ], true ) ) {
			/*
			 * In lieu of actual validation being available, clear the status entirely
			 * so that the underlying default status will be used instead.
			 * In the future it would be ideal if register_meta() accepted a
			 * validate_callback as well which the REST API could leverage.
			 */
			$status = '';
		}
		return $status;
	}

	/**
	 * Gets the AMP enabled status and errors.
	 *
	 * @since 1.0
	 * @param WP_Post $post The post to check.
	 * @return array {
	 *     The status and errors.
	 *
	 *     @type string    $status The AMP enabled status.
	 *     @type string[]  $errors AMP errors.
	 * }
	 */
	public function get_status_and_errors( $post ) {
		/*
		 * When theme support is present then theme templates can be served in AMP and we check first if the template is available.
		 * Checking for template availability will include a check for get_support_errors. Otherwise, if theme support is not present
		 * then we just check get_support_errors.
		 */
		if ( ! amp_is_legacy() ) {
			$availability = AMP_Theme_Support::get_template_availability( $post );
			$status       = $availability['supported'] ? self::ENABLED_STATUS : self::DISABLED_STATUS;
			$errors       = array_diff( $availability['errors'], [ 'post-status-disabled' ] ); // Subtract the status which the metabox will allow to be toggled.
		} else {
			$errors = AMP_Post_Type_Support::get_support_errors( $post );
			$status = empty( $errors ) ? self::ENABLED_STATUS : self::DISABLED_STATUS;
			$errors = array_diff( $errors, [ 'post-status-disabled' ] ); // Subtract the status which the metabox will allow to be toggled.
		}

		return compact( 'status', 'errors' );
	}

	/**
	 * Gets the AMP enabled error message(s).
	 *
	 * @since 1.0
	 * @see AMP_Post_Type_Support::get_support_errors()
	 *
	 * @param string[] $errors The AMP enabled errors.
	 * @return array $error_messages The error messages, as an array of strings.
	 */
	public function get_error_messages( $errors ) {
		$settings_screen_url = admin_url( 'admin.php?page=' . AMP_Options_Manager::OPTION_NAME );

		$error_messages = [];
		if ( in_array( 'template_unsupported', $errors, true ) || in_array( 'no_matching_template', $errors, true ) ) {
			$error_messages[] = sprintf(
				/* translators: %s is a link to the AMP settings screen */
				__( 'There are no <a href="%s" target="_blank">supported templates</a>.', 'amp' ),
				esc_url( $settings_screen_url )
			);
		}
		if ( in_array( 'post-type-support', $errors, true ) ) {
			$error_messages[] = sprintf(
				/* translators: %s is a link to the AMP settings screen */
				__( 'This post type is not <a href="%s" target="_blank">enabled</a>.', 'amp' ),
				esc_url( $settings_screen_url )
			);
		}
		if ( in_array( 'skip-post', $errors, true ) ) {
			$error_messages[] = __( 'A plugin or theme has disabled AMP support.', 'amp' );
		}
		if ( count( array_diff( $errors, [ 'post-type-support', 'skip-post', 'template_unsupported', 'no_matching_template' ] ) ) > 0 ) {
			$error_messages[] = __( 'Unavailable for an unknown reason.', 'amp' );
		}

		return $error_messages;
	}

	/**
	 * Get the value of whether AMP is enabled for a REST API request.
	 *
	 * @since 2.0
	 *
	 * @param array $post_data Post data.
	 * @return bool Whether AMP is enabled on post.
	 */
	public function get_amp_enabled_rest_field( $post_data ) {
		$status = $this->sanitize_status( get_post_meta( $post_data['id'], self::STATUS_POST_META_KEY, true ) );

		if ( '' === $status ) {
			$post              = get_post( $post_data['id'] );
			$status_and_errors = $this->get_status_and_errors( $post );

			if ( isset( $status_and_errors['status'] ) ) {
				$status = $status_and_errors['status'];
			}
		}

		return self::ENABLED_STATUS === $status;
	}

	/**
	 * Update whether AMP is enabled for a REST API request.
	 *
	 * @since 2.0
	 *
	 * @param bool    $is_enabled Whether AMP is enabled.
	 * @param WP_Post $post       Post being updated.
	 * @return null|WP_Error Null on success, WP_Error object on failure.
	 */
	public function update_amp_enabled_rest_field( $is_enabled, $post ) {
		if ( ! in_array( $post->post_type, AMP_Post_Type_Support::get_post_types_for_rest_api(), true ) ) {
			return new WP_Error(
				'rest_invalid_post_type',
				sprintf(
					/* translators: %s: The name of the post type. */
					__( 'AMP is not supported for the "%s" post type.', 'amp' ),
					$post->post_type
				),
				[ 'status' => 400 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'rest_insufficient_permission',
				__( 'Insufficient permissions to change whether AMP is enabled.', 'amp' ),
				[ 'status' => 403 ]
			);
		}

		$status = $is_enabled ? self::ENABLED_STATUS : self::DISABLED_STATUS;

		// Note: The sanitize_callback has been supplied in the register_meta() call above.
		$updated = update_post_meta(
			$post->ID,
			self::STATUS_POST_META_KEY,
			$status
		);

		if ( false === $updated ) {
			return new WP_Error(
				'rest_update_failed',
				__( 'The AMP enabled status failed to be updated.', 'amp' ),
				[ 'status' => 500 ]
			);
		}

		return null;
	}
}
