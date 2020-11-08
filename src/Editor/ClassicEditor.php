<?php
/**
 * AMP meta box settings for the classic editor.
 *
 * @package AMP
 * @since 0.6
 */

namespace AmpProject\AmpWP\Editor;

use AMP_Post_Type_Support;
use AMP_Theme_Support;
use AMP_Validation_Manager;
use AmpProject\AmpWP\DevTools\UserAccess;
use AmpProject\AmpWP\Infrastructure\Conditional;
use AmpProject\AmpWP\Infrastructure\Delayed;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;
use AmpProject\AmpWP\Script;
use WP_Error;

/**
 * Class handling the classic editor.
 *
 * @since 2.1
 * @internal
 */
final class ClassicEditor implements Conditional, Delayed, Service, Registerable {

	use FrontendValidation;

	/**
	 * Assets handle.
	 *
	 * @since 0.6
	 * @var string
	 */
	const ASSETS_HANDLE = 'amp-post-meta-box';

	/**
	 * PostAMPStatus instance.
	 *
	 * @var PostAMPStatus
	 */
	private $post_amp_status;

	/**
	 * UserAccess instance.
	 *
	 * @var UserAccess
	 */
	private $dev_tools_user_access;

	/**
	 * Class constructor.
	 *
	 * @param PostAMPStatus $post_amp_status PostAMPStatus instance.
	 * @param UserAccess    $dev_tools_user_access UserAccess instance.
	 */
	public function __construct( PostAMPStatus $post_amp_status, UserAccess $dev_tools_user_access ) {
		$this->post_amp_status       = $post_amp_status;
		$this->dev_tools_user_access = $dev_tools_user_access;
	}

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
		return 'plugins_loaded';
	}

	/**
	 * Initialize.
	 *
	 * @since 0.6
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'save_post', [ $this, 'handle_save_post_prompting_validation' ] );
		add_action( 'post_submitbox_misc_actions', [ $this, 'render_status' ] );
		add_filter( 'preview_post_link', [ $this, 'preview_post_link' ] );
		add_action( 'save_post', [ $this, 'save_amp_status' ] );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 0.6
	 */
	public function enqueue_assets() {
		if ( ! $this->is_classic_editor_with_amp_support() ) {
			return;
		}

		$post = get_post();

		$script = new Script( self::ASSETS_HANDLE, [ Script::FLAG_NAME_HAS_STYLE, Script::FLAG_NAME_IN_HEAD ] );
		$script->enqueue();

		if ( ! amp_is_legacy() ) {
			$availability   = AMP_Theme_Support::get_template_availability( $post );
			$support_errors = $availability['errors'];
		} else {
			$support_errors = AMP_Post_Type_Support::get_support_errors( $post );
		}

		wp_add_inline_script(
			self::ASSETS_HANDLE,
			sprintf(
				'ampPostMetaBox.boot( %s );',
				wp_json_encode(
					[
						'previewLink'     => esc_url_raw( amp_add_paired_endpoint( get_preview_post_link( $post ) ) ),
						'canonical'       => amp_is_canonical(),
						'enabled'         => empty( $support_errors ),
						'canSupport'      => 0 === count( array_diff( $support_errors, [ 'post-status-disabled' ] ) ),
						'statusInputName' => PostAMPStatus::STATUS_INPUT_NAME,
						'l10n'            => [
							'ampPreviewBtnLabel' => __( 'Preview changes in AMP (opens in new window)', 'amp' ),
						],
					]
				)
			)
		);
	}

	/**
	 * Handle save_post action to queue re-validation of the post on the frontend.
	 *
	 * This is intended to only apply to post edits made in the classic editor.
	 *
	 * @see BlockEditor::get_amp_validity_rest_field() The method responsible for validation post changes via Gutenberg.
	 * @see FrontendValidation::validate_queued_posts_on_frontend()
	 *
	 * @param int $post_id Post ID.
	 */
	public function handle_save_post_prompting_validation( $post_id ) {
		global $pagenow;

		if ( ! $this->dev_tools_user_access->is_user_enabled() ) {
			return;
		}

		$post = get_post( $post_id );

		$is_classic_editor_post_save = (
			isset( $_SERVER['REQUEST_METHOD'] )
			&&
			'POST' === $_SERVER['REQUEST_METHOD']
			&&
			'post.php' === $pagenow
			&&
			isset( $_POST['post_ID'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&&
			(int) $_POST['post_ID'] === (int) $post_id // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		$should_validate_post = (
			$is_classic_editor_post_save
			&&
			AMP_Validation_Manager::post_supports_validation( $post )
			&&
			! isset( $this->posts_pending_frontend_validation[ $post_id ] )
		);
		if ( $should_validate_post ) {
			$this->posts_pending_frontend_validation[ $post_id ] = true;

			// The reason for shutdown is to ensure that all postmeta changes have been saved, including whether AMP is enabled.
			if ( ! has_action( 'shutdown', [ $this, 'validate_queued_posts_on_frontend' ] ) ) {
				add_action( 'shutdown', [ $this, 'validate_queued_posts_on_frontend' ] );
			}
		}
	}

	/**
	 * Validate the posts pending frontend validation.
	 *
	 * @see BlockEditor::handle_save_post_prompting_validation()
	 *
	 * @return array Mapping of post ID to the result of validating or storing the validation result.
	 */
	public function validate_queued_posts_on_frontend() {
		$posts = array_filter(
			array_map( 'get_post', array_keys( array_filter( $this->posts_pending_frontend_validation ) ) ),
			function( $post ) {
				return AMP_Validation_Manager::post_supports_validation( $post );
			}
		);

		$validation_posts = [];

		/*
		 * It is unlikely that there will be more than one post in the array.
		 * For the bulk recheck action, see AMP_Validated_URL_Post_Type::handle_bulk_action().
		 */
		foreach ( $posts as $post ) {
			$url = amp_get_permalink( $post->ID );
			if ( ! $url ) {
				$validation_posts[ $post->ID ] = new WP_Error( 'no_amp_permalink' );
				continue;
			}

			// Prevent re-validating.
			$this->posts_pending_frontend_validation[ $post->ID ] = false;

			$invalid_url_post_id = (int) get_post_meta( $post->ID, '_amp_validated_url_post_id', true );

			$validity = AMP_Validation_Manager::validate_url_and_store( $url, $invalid_url_post_id );

			// Remember the amp_validated_url post so that when the slug changes the old amp_validated_url post can be updated.
			if ( ! is_wp_error( $validity ) && $invalid_url_post_id !== $validity['post_id'] ) {
				update_post_meta( $post->ID, '_amp_validated_url_post_id', $validity['post_id'] );
			}

			$validation_posts[ $post->ID ] = $validity instanceof WP_Error ? $validity : $validity['post_id'];
		}

		return $validation_posts;
	}

	/**
	 * Render AMP status.
	 *
	 * @since 0.6
	 * @param WP_Post $post Post.
	 */
	public function render_status( $post ) {
		$verify = (
			isset( $post->ID )
			&&
			in_array( $post->post_type, AMP_Post_Type_Support::get_eligible_post_types(), true )
			&&
			current_user_can( 'edit_post', $post->ID )
		);

		if ( true !== $verify ) {
			return;
		}

		$status_and_errors = $this->post_amp_status->get_status_and_errors( $post );
		$status            = $status_and_errors['status']; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Used in amp-enabled-classic-editor-toggle.php.
		$errors            = $status_and_errors['errors'];

		// Skip showing any error message if the user doesn't have the ability to do anything about it.
		if ( ! empty( $errors ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis
		$error_messages = $this->post_amp_status->get_error_messages( $errors );

		$labels = [
			'enabled'  => __( 'Enabled', 'amp' ),
			'disabled' => __( 'Disabled', 'amp' ),
		];
		// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis

		// The preceding variables are used inside the following amp-status.php template.
		include AMP__DIR__ . '/includes/templates/amp-enabled-classic-editor-toggle.php';
	}

	/**
	 * Save AMP Status.
	 *
	 * @since 0.6
	 * @param int $post_id The Post ID.
	 */
	public function save_amp_status( $post_id ) {
		if ( ! $this->is_classic_editor_with_amp_support( $post_id ) ) {
			return;
		}

		$verify = (
			isset( $_POST[ PostAMPStatus::NONCE_NAME ], $_POST[ PostAMPStatus::STATUS_INPUT_NAME ] )
			&&
			wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ PostAMPStatus::NONCE_NAME ] ) ), PostAMPStatus::NONCE_ACTION )
			&&
			current_user_can( 'edit_post', $post_id )
			&&
			! wp_is_post_revision( $post_id )
			&&
			! wp_is_post_autosave( $post_id )
		);

		if ( true === $verify ) {
			update_post_meta(
				$post_id,
				PostAMPStatus::STATUS_POST_META_KEY,
				$_POST[ PostAMPStatus::STATUS_INPUT_NAME ] // Note: The sanitize_callback has been supplied in the register_meta() call above.
			);
		}
	}

	/**
	 * Modify post preview link.
	 *
	 * Add the AMP query var if the amp-preview flag is set.
	 *
	 * @since 0.6
	 *
	 * @param string $link The post preview link.
	 * @return string Preview URL.
	 */
	public function preview_post_link( $link ) {
		$is_amp = (
			isset( $_POST['amp-preview'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&&
			'do-preview' === sanitize_key( wp_unslash( $_POST['amp-preview'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		if ( $is_amp ) {
			$link = amp_add_paired_endpoint( $link );
		}

		return $link;
	}

	/**
	 * Returns whether the current screen is the classic editor for an AMP-enabled post type.
	 *
	 * @param int|null $post A post ID or null to use the current global post.
	 * @return boolean
	 */
	private function is_classic_editor_with_amp_support( $post = null ) {
		$post   = get_post( $post );
		$screen = get_current_screen();
		return (
			$post &&
			$screen &&
			isset( $screen->base ) &&
			'post' === $screen->base &&
			( ! isset( $screen->is_block_editor ) || ! $screen->is_block_editor ) &&
			in_array( $post->post_type, AMP_Post_Type_Support::get_eligible_post_types(), true )
		);
	}
}
