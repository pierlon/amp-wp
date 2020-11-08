<?php

namespace AmpProject\AmpWP\Tests\Editor;

use AMP_Options_Manager;
use AMP_Post_Type_Support;
use AMP_Theme_Support;
use AmpProject\AmpWP\Editor\PostAMPStatus;
use AmpProject\AmpWP\Infrastructure\Conditional;
use AmpProject\AmpWP\Infrastructure\Delayed;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;
use AmpProject\AmpWP\Option;
use AmpProject\AmpWP\Tests\Helpers\AssertContainsCompatibility;
use AmpProject\AmpWP\Tests\Helpers\AssertRestApiField;
use WP_Error;
use WP_UnitTestCase;

/** @coversDefaultClass \AmpProject\AmpWP\Tests\Editor\PostAMPStatus */
final class PostAMPStatusTest extends WP_UnitTestCase {

	use AssertRestApiField, AssertContainsCompatibility;

	/** @var PostAMPStatus */
	private $instance;

	public function setUp() {
		parent::setUp();

		$this->instance = new PostAMPStatus();
	}

	public function test_it_can_be_initialized() {
		$this->assertInstanceOf( PostAMPStatus::class, $this->instance );
		$this->assertInstanceOf( Conditional::class, $this->instance );
		$this->assertInstanceOf( Service::class, $this->instance );
		$this->assertInstanceOf( Registerable::class, $this->instance );
		$this->assertInstanceOf( Delayed::class, $this->instance );
	}

	/** @covers ::get_registration_action() */
	public function test_get_registration_action() {
		$this->assertEquals( 'admin_init', PostAMPStatus::get_registration_action() );
	}

	/** @covers ::register() */
	public function test_register() {
		global $wp_meta_keys;

		$this->instance->register();

		$this->assertEquals( 10, has_action( 'rest_api_init', [ $this->instance, 'register_rest_fields' ] ) );
		$this->assertArrayHasKey( PostAMPStatus::STATUS_POST_META_KEY, $wp_meta_keys['post'][''] );
	}

	/** @covers ::register_rest_fields */
	public function test_register_rest_fields() {
		$this->instance->register_rest_fields();
		$this->assertRestApiFieldPresent(
			AMP_Post_Type_Support::get_post_types_for_rest_api(),
			PostAMPStatus::REST_FIELD_NAME_AMP_ENABLED,
			[
				'get_callback'    => [ $this->instance, 'get_amp_enabled_rest_field' ],
				'update_callback' => [ $this->instance, 'update_amp_enabled_rest_field' ],
				'schema'          => [
					'description' => __( 'AMP enabled', 'amp' ),
					'type'        => 'boolean',
					'context'     => [ 'edit' ],
				],
			]
		);
	}

	/** @covers ::sanitize_status */
	public function test_sanitize_status() {
		$this->assertEquals( '', $this->instance->sanitize_status( 'unknown-status' ) );
		$this->assertEquals( PostAMPStatus::ENABLED_STATUS, $this->instance->sanitize_status( PostAMPStatus::ENABLED_STATUS ) );
		$this->assertEquals( PostAMPStatus::DISABLED_STATUS, $this->instance->sanitize_status( PostAMPStatus::DISABLED_STATUS ) );
	}

	/** @covers ::get_status_and_errors */
	public function test_get_status_and_errors() {
		AMP_Options_Manager::update_option( Option::ALL_TEMPLATES_SUPPORTED, false );
		$expected_status_and_errors = [
			'status' => 'enabled',
			'errors' => [],
		];

		// A post of type post shouldn't have errors, and AMP should be enabled.
		$post = self::factory()->post->create_and_get();
		$this->assertEquals(
			$expected_status_and_errors,
			$this->instance->get_status_and_errors( $post )
		);

		// In AMP-first, there also shouldn't be errors.
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::STANDARD_MODE_SLUG );
		$this->assertEquals(
			$expected_status_and_errors,
			$this->instance->get_status_and_errors( $post )
		);

		// If post type doesn't support AMP, this method should return AMP as being disabled.
		$supported_post_types = array_diff( AMP_Options_Manager::get_option( Option::SUPPORTED_POST_TYPES ), [ 'post' ] );
		AMP_Options_Manager::update_option( Option::SUPPORTED_POST_TYPES, $supported_post_types );
		remove_post_type_support( 'post', AMP_Post_Type_Support::SLUG );
		$this->assertEquals(
			[
				'status' => 'disabled',
				'errors' => [ 'post-type-support' ],
			],
			$this->instance->get_status_and_errors( $post )
		);
		$supported_post_types[] = 'post';
		AMP_Options_Manager::update_option( Option::SUPPORTED_POST_TYPES, $supported_post_types );

		// There's no template to render this post, so this method should also return AMP as disabled.
		add_filter( 'amp_supportable_templates', '__return_empty_array' );
		AMP_Options_Manager::update_option( Option::ALL_TEMPLATES_SUPPORTED, false );
		$this->assertEquals(
			[
				'status' => 'disabled',
				'errors' => [ 'no_matching_template' ],
			],
			$this->instance->get_status_and_errors( $post )
		);
	}

	/** @covers::get_error_messages() */
	public function test_get_error_messages() {
		$messages = $this->instance->get_error_messages( [ 'template_unsupported' ] );
		$this->assertStringContains( 'There are no', $messages[0] );
		$this->assertStringContains( 'page=amp-options', $messages[0] );

		$messages = $this->instance->get_error_messages( [ 'post-type-support' ] );
		$this->assertStringContains( 'This post type is not', $messages[0] );
		$this->assertStringContains( 'page=amp-options', $messages[0] );

		$this->assertEquals(
			[
				'A plugin or theme has disabled AMP support.',
				'Unavailable for an unknown reason.',
			],
			$this->instance->get_error_messages( [ 'skip-post', 'unknown-error' ] )
		);

		$this->assertEquals(
			[ 'Unavailable for an unknown reason.' ],
			$this->instance->get_error_messages( [ 'unknown-error' ] )
		);
	}

	/** @covers ::get_amp_enabled_rest_field */
	public function test_get_amp_enabled_rest_field() {
		AMP_Options_Manager::update_option( Option::ALL_TEMPLATES_SUPPORTED, false );

		// AMP status should be disabled if AMP is not supported for the `post` post type.
		$supported_post_types = array_diff( AMP_Options_Manager::get_option( Option::SUPPORTED_POST_TYPES ), [ 'post' ] );
		AMP_Options_Manager::update_option( Option::SUPPORTED_POST_TYPES, $supported_post_types );
		$id = self::factory()->post->create();
		$this->assertFalse(
			$this->instance->get_amp_enabled_rest_field( compact( 'id' ) )
		);

		// AMP status should be enabled if AMP is supported for the `post` post type.
		$supported_post_types[] = 'post';
		AMP_Options_Manager::update_option( Option::SUPPORTED_POST_TYPES, $supported_post_types );
		$id = self::factory()->post->create();
		$this->assertTrue(
			$this->instance->get_amp_enabled_rest_field( compact( 'id' ) )
		);

		// AMP status should be enabled if the `amp_status` post meta equals 'enabled'.
		$id = self::factory()->post->create();
		add_metadata( 'post', $id, PostAMPStatus::STATUS_POST_META_KEY, PostAMPStatus::ENABLED_STATUS );
		$this->assertTrue(
			$this->instance->get_amp_enabled_rest_field( compact( 'id' ) )
		);

		// AMP status should be disabled if the `amp_status` post meta equals 'disabled'.
		$id = self::factory()->post->create();
		add_metadata( 'post', $id, PostAMPStatus::STATUS_POST_META_KEY, PostAMPStatus::DISABLED_STATUS );
		$this->assertFalse(
			$this->instance->get_amp_enabled_rest_field( compact( 'id' ) )
		);
	}

	/**
	 * Test update_amp_enabled_rest_field.
	 *
	 * @covers AMP_Post_Meta_Box::update_amp_enabled_rest_field()
	 */
	public function test_update_amp_enabled_rest_field() {
		// User should not be able to update AMP status if they do not have the `edit_post` capability.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$post = self::factory()->post->create_and_get();
		add_metadata( 'post', $post->ID, PostAMPStatus::STATUS_POST_META_KEY, PostAMPStatus::ENABLED_STATUS );
		$result = $this->instance->update_amp_enabled_rest_field( false, $post );

		$this->assertEquals( PostAMPStatus::ENABLED_STATUS, get_post_meta( $post->ID, PostAMPStatus::STATUS_POST_META_KEY, true ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'rest_insufficient_permission', $result->get_error_code() );

		// User should be able to update AMP status if they have the sufficient capabilities.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post = self::factory()->post->create_and_get();
		add_metadata( 'post', $post->ID, PostAMPStatus::STATUS_POST_META_KEY, PostAMPStatus::ENABLED_STATUS );
		$this->assertNull( $this->instance->update_amp_enabled_rest_field( false, $post ) );

		$this->assertEquals( PostAMPStatus::DISABLED_STATUS, get_post_meta( $post->ID, PostAMPStatus::STATUS_POST_META_KEY, true ) );
	}
}
