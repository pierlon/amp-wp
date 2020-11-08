<?php
/**
 * Trait providing frontend validation in both the classic editor and block editor.
 *
 * @package AMP
 * @since 2.1
 */

namespace AmpProject\AmpWP\Editor;

use AMP_Validation_Manager;
use WP_Error;

/**
 * Class handling the classic editor.
 *
 * @since 2.1
 * @internal
 */
trait FrontendValidation {

	/**
	 * Post IDs for posts that have been updated which need to be re-validated.
	 *
	 * Keys are post IDs and values are whether the post has been re-validated.
	 *
	 * @var bool[]
	 */
	private $posts_pending_frontend_validation = [];

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
}
