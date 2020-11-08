<?php

namespace AmpProject\AmpWP\Tests\Editor;

use AMP_Options_Manager;
use AMP_Post_Type_Support;
use AMP_Theme_Support;
use AMP_Validated_URL_Post_Type;
use AMP_Validation_Error_Taxonomy;
use AmpProject\AmpWP\DevTools\UserAccess;
use AmpProject\AmpWP\Editor\BlockEditor;
use AmpProject\AmpWP\Editor\EditorSupport;
use AmpProject\AmpWP\Editor\PostAMPStatus;
use AmpProject\AmpWP\Infrastructure\Conditional;
use AmpProject\AmpWP\Infrastructure\Delayed;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;
use AmpProject\AmpWP\Option;
use AmpProject\AmpWP\Tests\DependencyInjectedTestCase;
use AmpProject\AmpWP\Tests\Helpers\AssertContainsCompatibility;
use AmpProject\AmpWP\Tests\Helpers\AssertRestApiField;
use AmpProject\AmpWP\Tests\Helpers\HandleValidation;
use WP_Error;
use WP_REST_Request;

/** @coversDefaultClass \AmpProject\AmpWP\Tests\Editor\BlockEditor */
final class BlockEditorTest extends DependencyInjectedTestCase {

	use HandleValidation, AssertRestApiField, AssertContainsCompatibility;

	/** @var BlockEditor */
	private $instance;

	public function setUp() {
		parent::setUp();

		global $wp_scripts, $wp_styles;
		$wp_scripts = null;
		$wp_styles  = null;

		$this->instance = new BlockEditor(
			new EditorSupport(),
			new UserAccess(),
			new PostAMPStatus()
		);
	}

	/**
	 * Tear down.
	 *
	 * @inheritdoc
	 */
	public function tearDown() {
		global $wp_scripts, $wp_styles;
		$wp_scripts = null;
		$wp_styles  = null;
		parent::tearDown();
	}

	public function test_it_can_be_initialized() {
		$this->assertInstanceOf( BlockEditor::class, $this->instance );
		$this->assertInstanceOf( Conditional::class, $this->instance );
		$this->assertInstanceOf( Service::class, $this->instance );
		$this->assertInstanceOf( Registerable::class, $this->instance );
		$this->assertInstanceOf( Delayed::class, $this->instance );
	}

	/** @covers ::get_registration_action() */
	public function test_get_registration_action() {
		$this->assertEquals( 'plugins_loaded', BlockEditor::get_registration_action() );
	}

	/** @covers ::register() */
	public function test_register() {
		$this->instance->register();
		$this->assertEquals( 10, has_action( 'admin_enqueue_scripts', [ $this->instance, 'enqueue_assets' ] ) );
		$this->assertEquals( 10, has_action( 'rest_api_init', [ $this->instance, 'register_rest_fields' ] ) );
	}

	/** @covers ::enqueue_assets */
	public function test_enqueue_assets_with_no_global_post() {
		$this->assertFalse( wp_script_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertFalse( wp_script_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
		$this->assertFalse( wp_style_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertFalse( wp_style_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
	}

	/** @covers ::enqueue_assets */
	public function test_enqueue_assets_with_global_post_supporting_amp() {
		$post            = self::factory()->post->create_and_get();
		$GLOBALS['post'] = $post;

		set_current_screen( 'post.php' );
		get_current_screen()->is_block_editor = true;

		$this->instance->enqueue_assets();

		$this->assertTrue( wp_script_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertTrue( wp_script_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
		$this->assertTrue( wp_style_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertTrue( wp_style_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
	}

	/** @covers ::enqueue_assets */
	public function test_enqueue_assets_with_unsupported_post_type() {
		// If a post type doesn't have AMP enabled, the script shouldn't be enqueued.
		register_post_type(
			'secret',
			[ 'public' => false ]
		);
		$GLOBALS['post'] = self::factory()->post->create_and_get(
			[
				'post_type' => 'secret',
			]
		);

		set_current_screen( 'post.php' );
		get_current_screen()->is_block_editor = true;

		$this->instance->enqueue_assets();
		$this->assertFalse( wp_script_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertFalse( wp_script_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
		$this->assertFalse( wp_style_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertFalse( wp_style_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
	}

	/** @covers ::enqueue_assets */
	public function test_enqueue_assets_with_supported_post_type_and_no_block_editor() {
		$post            = self::factory()->post->create_and_get();
		$GLOBALS['post'] = $post;

		set_current_screen( 'post.php' );
		get_current_screen()->is_block_editor = false;
		$this->instance->enqueue_assets();

		$this->assertFalse( wp_script_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertFalse( wp_script_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
		$this->assertFalse( wp_style_is( BlockEditor::BLOCK_ASSET_HANDLE ) );
		$this->assertFalse( wp_style_is( BlockEditor::VALIDATION_ASSET_HANDLE ) );
	}

	/** @covers ::enqueue_assets */
	public function test_script_inline_data() {
		$post            = self::factory()->post->create_and_get();
		$GLOBALS['post'] = $post;

		set_current_screen( 'post.php' );
		get_current_screen()->is_block_editor = true;

		$this->instance->enqueue_assets();

		$this->assertStringContains( 'var ampBlockEditor', wp_scripts()->get_data( BlockEditor::BLOCK_ASSET_HANDLE, 'before' )[1] );
		$this->assertStringContains( 'var ampBlockValidation', wp_scripts()->get_data( BlockEditor::VALIDATION_ASSET_HANDLE, 'before' )[1] );
		unset( $GLOBALS['post'], $GLOBALS['current_screen'] );
	}

	/** @covers ::register_rest_fields */
	public function test_register_rest_fields_in_transitional_mode() {
		// Test in a transitional context.
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::TRANSITIONAL_MODE_SLUG );
		$this->instance->register_rest_fields();
		$this->assertRestApiFieldPresent(
			AMP_Post_Type_Support::get_post_types_for_rest_api(),
			BlockEditor::REST_FIELD_NAME_VALIDITY,
			[
				'get_callback' => [ $this->instance, 'get_amp_validity_rest_field' ],
				'schema'       => [
					'description' => __( 'AMP validity status', 'amp' ),
					'type'        => 'object',
					'context'     => [ 'edit' ],
				],
			]
		);
	}

	/** @covers ::register_rest_fields */
	public function test_register_rest_fields_in_standard_mode() {
		// Test in a AMP-first (canonical) context.
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::STANDARD_MODE_SLUG );
		$this->instance->register_rest_fields();
		$this->assertRestApiFieldPresent(
			AMP_Post_Type_Support::get_post_types_for_rest_api(),
			BlockEditor::REST_FIELD_NAME_VALIDITY,
			[
				'get_callback' => [ $this->instance, 'get_amp_validity_rest_field' ],
				'schema'       => [
					'description' => __( 'AMP validity status', 'amp' ),
					'type'        => 'object',
					'context'     => [ 'edit' ],
				],
			]
		);
	}

	/**
	 * Test get_amp_validity_rest_field.
	 *
	 * @covers $this->instance->get_amp_validity_rest_field()
	 * @covers AMP_Validation_Manager::validate_url()
	 */
	public function test_get_amp_validity_rest_field() {
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::TRANSITIONAL_MODE_SLUG );
		$this->accept_sanitization_by_default( false );
		AMP_Validated_URL_Post_Type::register();
		AMP_Validation_Error_Taxonomy::register();

		$id = self::factory()->post->create();
		$this->assertNull(
			$this->instance->get_amp_validity_rest_field(
				compact( 'id' ),
				'',
				new WP_REST_Request( 'GET' )
			)
		);

		// Create an error custom post for the ID, so this will return the errors in the field.
		$errors = [
			[
				'code' => 'test',
			],
		];
		$this->create_custom_post(
			$errors,
			amp_get_permalink( $id )
		);

		// Make sure capability check is honored.
		$this->assertNull(
			$this->instance->get_amp_validity_rest_field(
				compact( 'id' ),
				'',
				new WP_REST_Request( 'GET' )
			)
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Make user preference is honored.
		$service = $this->injector->make( UserAccess::class );
		$service->set_user_enabled( wp_get_current_user()->ID, false );
		$this->assertNull(
			$this->instance->get_amp_validity_rest_field(
				compact( 'id' ),
				'',
				new WP_REST_Request( 'GET' )
			)
		);
		$service->set_user_enabled( wp_get_current_user()->ID, true );

		// GET request.
		$field = $this->instance->get_amp_validity_rest_field(
			compact( 'id' ),
			'',
			new WP_REST_Request( 'GET' )
		);
		$this->assertArrayHasKey( 'results', $field );
		$this->assertArrayHasKey( 'review_link', $field );
		$this->assertEquals(
			$field['results'],
			array_map(
				static function ( $error ) {
					return [
						'sanitized'   => false,
						'title'       => 'Unknown error (test)',
						'error'       => $error,
						'status'      => AMP_Validation_Error_Taxonomy::VALIDATION_ERROR_NEW_REJECTED_STATUS,
						'term_status' => AMP_Validation_Error_Taxonomy::VALIDATION_ERROR_NEW_REJECTED_STATUS,
						'forced'      => false,
					];
				},
				$errors
			)
		);

		// PUT request.
		add_filter(
			'pre_http_request',
			static function() {
				return [
					'body'     => wp_json_encode( [ 'results' => [] ] ),
					'response' => [
						'code'    => 200,
						'message' => 'ok',
					],
				];
			}
		);
		$field = $this->instance->get_amp_validity_rest_field(
			compact( 'id' ),
			'',
			new WP_REST_Request( 'PUT' )
		);
		$this->assertArrayHasKey( 'results', $field );
		$this->assertArrayHasKey( 'review_link', $field );
		$this->assertEmpty( $field['results'] );
	}

	/**
	 * Creates and inserts a custom post.
	 *
	 * @param array  $errors Validation errors to populate.
	 * @param string $url    URL that the errors occur on. Defaults to the home page.
	 * @return int|WP_Error $error_post The ID of new custom post, or an error.
	 */
	public function create_custom_post( $errors = [], $url = null ) {
		if ( ! $url ) {
			$url = home_url( '/' );
		}

		return AMP_Validated_URL_Post_Type::store_validation_errors( $errors, $url );
	}
}
