<?php
/**
 * Class BlockEditor.
 *
 * Functionality related to the editor.
 *
 * @since 2.1
 *
 * @package AmpProject\AmpWP
 */

namespace AmpProject\AmpWP\Admin;

use AMP_Post_Type_Support;
use AMP_Validated_URL_Post_Type;
use AMP_Validation_Error_Taxonomy;
use AMP_Validation_Manager;
use AmpProject\AmpWP\DevTools\UserAccess;
use AmpProject\AmpWP\Infrastructure\Conditional;
use AmpProject\AmpWP\Infrastructure\Delayed;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;
use AmpProject\AmpWP\Script;

/**
 * PluginSidebar class.
 *
 * @internal
 */
final class BlockEditor implements Conditional, Delayed, Service, Registerable {

	/**
	 * The name of the REST API field with the AMP validation results.
	 *
	 * @var string
	 */
	const REST_FIELD_NAME_VALIDITY = 'amp_validity';

	/**
	 * EditorSupport instance.
	 *
	 * @var EditorSupport
	 */
	private $editor_support;

	/**
	 * UserAccess instance.
	 *
	 * @var UserAccess
	 */
	private $dev_tools_user_access;

	/**
	 * PostAMPStatus instance.
	 *
	 * @var PostAMPStatus
	 */
	private $post_amp_status;

	/**
	 * Post IDs for posts that have been updated which need to be re-validated.
	 *
	 * Keys are post IDs and values are whether the post has been re-validated.
	 *
	 * @var bool[]
	 */
	private $posts_pending_frontend_validation = [];

	/**
	 * Class constructor.
	 *
	 * @param EditorSupport $editor_support EditorSupport instance.
	 * @param UserAccess    $dev_tools_user_access UserAccess instance.
	 * @param PostAMPStatus $post_amp_status PostAMPStatus instance.
	 */
	public function __construct( EditorSupport $editor_support, UserAccess $dev_tools_user_access, PostAMPStatus $post_amp_status ) {
		$this->editor_support        = $editor_support;
		$this->dev_tools_user_access = $dev_tools_user_access;
		$this->post_amp_status       = $post_amp_status;
	}

	/**
	 * Check whether the conditional object is currently needed.
	 *
	 * @return bool Whether the conditional object is needed.
	 */
	public static function is_needed() {
		return is_admin() || ( defined( 'DOING_REST' ) && DOING_REST );
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
	 * Runs on instantiation.
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'save_post', [ $this, 'handle_save_post_prompting_validation' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
	}

	/**
	 * Enqueues scripts and styles.
	 */
	public function enqueue_assets() {
		if ( ! $this->editor_support->post_supports_amp() ) {
			return;
		}

		$sidebar_script = new Script( 'amp-plugin-sidebar', [ Script::FLAG_NAME_HAS_STYLE ] );
		$sidebar_script->enqueue();
		$sidebar_script->add_data(
			'ampPluginSidebar',
			[
				'HTML_ATTRIBUTE_ERROR_TYPE'  => AMP_Validation_Error_Taxonomy::HTML_ATTRIBUTE_ERROR_TYPE,
				'HTML_ELEMENT_ERROR_TYPE'    => AMP_Validation_Error_Taxonomy::HTML_ELEMENT_ERROR_TYPE,
				'JS_ERROR_TYPE'              => AMP_Validation_Error_Taxonomy::JS_ERROR_TYPE,
				'CSS_ERROR_TYPE'             => AMP_Validation_Error_Taxonomy::CSS_ERROR_TYPE,
				'isSanitizationAutoAccepted' => AMP_Validation_Manager::is_sanitization_auto_accepted(),
			]
		);

		$status_and_errors = $this->post_amp_status->get_status_and_errors( get_post() );
		$block_script      = new Script( 'amp-block-editor', [ Script::FLAG_NAME_HAS_STYLE ] );
		$block_script->enqueue();
		$block_script->add_data(
			'ampBlockEditor',
			[
				'ampSlug'         => amp_get_slug(),
				'errorMessages'   => $this->post_amp_status->get_error_messages( $status_and_errors['errors'] ),
				'hasThemeSupport' => ! amp_is_legacy(),
				'isStandardMode'  => amp_is_canonical(),
			]
		);

	}

	/**
	 * Registers REST fields needed for the plugin sidebar.
	 */
	public function register_rest_fields() {
		register_rest_field(
			AMP_Post_Type_Support::get_post_types_for_rest_api(),
			self::REST_FIELD_NAME_VALIDITY,
			[
				'get_callback' => [ $this, 'get_amp_validity_rest_field' ],
				'schema'       => [
					'description' => __( 'AMP validity status', 'amp' ),
					'type'        => 'object',
					'context'     => [ 'edit' ],
				],
			]
		);
	}

	/**
	 * Provides the URL of the edit screen for a post's validated URL post.
	 *
	 * @param array $post Array of post data formatted for REST.
	 * @return string URL.
	 */
	public function get_url_validation_link( $post ) {
		return get_edit_post_link( AMP_Validated_URL_Post_Type::get_invalid_url_post( get_permalink( $post['id'] ) ) );
	}

	/**
	 * Handle save_post action to queue re-validation of the post on the frontend.
	 *
	 * This is intended to only apply to post edits made in the classic editor.
	 *
	 * @see AMP_Validation_Manager::get_amp_validity_rest_field() The method responsible for validation post changes via Gutenberg.
	 * @see AMP_Validation_Manager::validate_queued_posts_on_frontend()
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
	 * @see PluginSidebar::handle_save_post_prompting_validation()
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
	 * Adds a field to the REST API responses to display the validation status.
	 *
	 * First, get existing errors for the post.
	 * If there are none, validate the post and return any errors.
	 *
	 * @param array           $post_data  Data for the post.
	 * @param string          $field_name The name of the field to add.
	 * @param WP_REST_Request $request    The name of the field to add.
	 * @return array|null $validation_data Validation data if it's available, or null.
	 */
	public function get_amp_validity_rest_field( $post_data, $field_name, $request ) {
		if ( ! current_user_can( 'edit_post', $post_data['id'] ) || ! $this->dev_tools_user_access->is_user_enabled() || ! AMP_Validation_Manager::post_supports_validation( $post_data['id'] ) ) {
			return null;
		}
		$post = get_post( $post_data['id'] );

		$validation_status_post = null;
		if ( in_array( $request->get_method(), [ 'PUT', 'POST' ], true ) ) {
			if ( ! isset( $this->posts_pending_frontend_validation[ $post->ID ] ) ) {
				$this->posts_pending_frontend_validation[ $post->ID ] = true;
			}
			$results = $this->validate_queued_posts_on_frontend();
			if ( isset( $results[ $post->ID ] ) && is_int( $results[ $post->ID ] ) ) {
				$validation_status_post = get_post( $results[ $post->ID ] );
			}
		}

		if ( empty( $validation_status_post ) ) {
			$validation_status_post = AMP_Validated_URL_Post_Type::get_invalid_url_post( amp_get_permalink( $post->ID ) );
		}

		$field = [
			'results'     => [],
			'review_link' => null,
		];

		if ( $validation_status_post ) {
			$field['review_link'] = get_edit_post_link( $validation_status_post->ID, 'raw' );
			foreach ( AMP_Validated_URL_Post_Type::get_invalid_url_validation_errors( $validation_status_post ) as $result ) {
				$field['results'][] = [
					'sanitized'   => AMP_Validation_Error_Taxonomy::VALIDATION_ERROR_ACK_ACCEPTED_STATUS === $result['status'],
					'title'       => AMP_Validation_Error_Taxonomy::get_error_title_from_code( $result['data'] ),
					'error'       => $result['data'],
					'status'      => $result['status'],
					'term_status' => $result['term_status'],
					'forced'      => $result['forced'],
				];
			}
		}

		return $field;
	}
}
