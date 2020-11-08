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

namespace AmpProject\AmpWP\Editor;

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
 * BlockEditor class.
 *
 * @internal
 */
final class BlockEditor implements Conditional, Delayed, Service, Registerable {

	use FrontendValidation;

	/**
	 * Block asset handle.
	 *
	 * @var string
	 */
	const BLOCK_ASSET_HANDLE = 'amp-block-editor';

	/**
	 * Editor validation asset handle.
	 *
	 * @var string
	 */
	const VALIDATION_ASSET_HANDLE = 'amp-block-validation';

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
		add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
	}

	/**
	 * Enqueues scripts and styles.
	 */
	public function enqueue_assets() {
		if ( ! in_array( get_post_type(), AMP_Post_Type_Support::get_eligible_post_types(), true ) ) {
			return;
		}

		if ( ! $this->editor_support->editor_supports_amp_block_editor_features() ) {
			return;
		}

		$status_and_errors = $this->post_amp_status->get_status_and_errors( get_post() );
		$block_script      = new Script( self::BLOCK_ASSET_HANDLE, [ Script::FLAG_NAME_HAS_STYLE ] );
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

		$block_validation_script = new Script( self::VALIDATION_ASSET_HANDLE, [ Script::FLAG_NAME_HAS_STYLE ] );
		$block_validation_script->enqueue();
		$block_validation_script->add_data(
			'ampBlockValidation',
			[
				'isSanitizationAutoAccepted' => AMP_Validation_Manager::is_sanitization_auto_accepted(),
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
