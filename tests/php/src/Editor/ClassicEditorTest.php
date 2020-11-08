<?php

namespace AmpProject\AmpWP\Tests\Editor;

use AMP_Options_Manager;
use AMP_Post_Type_Support;
use AMP_Theme_Support;
use AmpProject\AmpWP\DevTools\UserAccess;
use AmpProject\AmpWP\Editor\ClassicEditor;
use AmpProject\AmpWP\Editor\PostAMPStatus;
use AmpProject\AmpWP\Infrastructure\Conditional;
use AmpProject\AmpWP\Infrastructure\Delayed;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;
use AmpProject\AmpWP\Option;
use AmpProject\AmpWP\Tests\DependencyInjectedTestCase;
use AmpProject\AmpWP\Tests\Helpers\AssertContainsCompatibility;
use WP_Error;

/** @coversDefaultClass \AmpProject\AmpWP\Tests\Editor\ClassicEditor */
final class ClassicEditorTest extends DependencyInjectedTestCase {

	use AssertContainsCompatibility;

	/** @var ClassicEditor */
	private $instance;

	public function setUp() {
		parent::setUp();

		$this->instance = new ClassicEditor( new PostAMPStatus(), new UserAccess() );
	}

	public function test_it_can_be_initialized() {
		$this->assertInstanceOf( ClassicEditor::class, $this->instance );
		$this->assertInstanceOf( Conditional::class, $this->instance );
		$this->assertInstanceOf( Service::class, $this->instance );
		$this->assertInstanceOf( Registerable::class, $this->instance );
		$this->assertInstanceOf( Delayed::class, $this->instance );
	}

	/** @covers ::get_registration_action() */
	public function test_get_registration_action() {
		$this->assertEquals( 'plugins_loaded', ClassicEditor::get_registration_action() );
	}

	/** @covers ::register() */
	public function test_register() {
		$this->instance->register();

		$this->assertEquals( 10, has_action( 'admin_enqueue_scripts', [ $this->instance, 'enqueue_assets' ] ) );
		$this->assertEquals( 10, has_action( 'post_submitbox_misc_actions', [ $this->instance, 'render_status' ] ) );
		$this->assertEquals( 10, has_filter( 'preview_post_link', [ $this->instance, 'preview_post_link' ] ) );
		$this->assertEquals( 10, has_action( 'save_post', [ $this->instance, 'save_amp_status' ] ) );
		$this->assertEquals( 10, has_action( 'save_post', [ $this->instance, 'handle_save_post_prompting_validation' ] ) );
	}

	/**
	 * @covers ::enqueue_assets
	 * @covers ::is_classic_editor_with_amp_support
	 */
	public function test_enqueue_assets_with_classic_editor_and_no_post() {
		if ( version_compare( get_bloginfo( 'version' ), '5.0', '>=' ) ) {
			$this->markTestSkipped();
		}

		$this->assertFalse( wp_script_is( ClassicEditor::ASSETS_HANDLE ) );
	}

	/**
	 * @covers ::enqueue_assets
	 * @covers ::is_classic_editor_with_amp_support
	 */
	public function test_enqueue_assets_with_classic_editor_and_global_post() {
		global $post;

		if ( version_compare( get_bloginfo( 'version' ), '5.0', '>=' ) ) {
			$this->markTestSkipped();
		}

		$post = $this->factory()->post->create_and_get();

		$this->assertTrue( wp_script_is( ClassicEditor::ASSETS_HANDLE ) );
	}

	/**
	 * @covers ::enqueue_assets
	 * @covers ::is_classic_editor_with_amp_support
	 */
	public function test_enqueue_assets_with_block_editor_and_no_post() {
		if ( version_compare( get_bloginfo( 'version' ), '5.0', '<' ) ) {
			$this->markTestSkipped();
		}

		$this->assertFalse( wp_script_is( ClassicEditor::ASSETS_HANDLE ) );
	}

	/**
	 * @covers ::enqueue_assets
	 * @covers ::is_classic_editor_with_amp_support
	 */
	public function test_enqueue_assets_with_block_editor_and_global_post() {
		global $post;

		if ( version_compare( get_bloginfo( 'version' ), '5.0', '<' ) ) {
			$this->markTestSkipped();
		}

		$post = $this->factory()->post->create_and_get();

		$this->assertFalse( wp_script_is( ClassicEditor::ASSETS_HANDLE ) );
	}

	/**
	 * @covers ::handle_save_post_prompting_validation
	 * @covers FrontendValidation::validate_queued_posts_on_frontend
	 */
	public function test_handle_save_post_prompting_validation_and_validate_queued_posts_on_frontend() {
		$admin_user_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor_user_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		wp_set_current_user( $admin_user_id );
		$service = $this->injector->make( UserAccess::class );

		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::STANDARD_MODE_SLUG );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['pagenow']        = 'post.php';

		register_post_type( 'secret', [ 'public' => false ] );
		$secret           = self::factory()->post->create_and_get( [ 'post_type' => 'secret' ] );
		$_POST['post_ID'] = $secret->ID;
		$this->instance->handle_save_post_prompting_validation( $secret->ID );
		$this->assertFalse( has_action( 'shutdown', [ $this->instance, 'validate_queued_posts_on_frontend' ] ) );
		$this->assertEmpty( $this->instance->validate_queued_posts_on_frontend() );

		$auto_draft       = self::factory()->post->create_and_get( [ 'post_status' => 'auto-draft' ] );
		$_POST['post_ID'] = $auto_draft->ID;
		$this->instance->handle_save_post_prompting_validation( $auto_draft->ID );
		$this->assertFalse( has_action( 'shutdown', [ $this->instance, 'validate_queued_posts_on_frontend' ] ) );
		$this->assertEmpty( $this->instance->validate_queued_posts_on_frontend() );

		// Testing without $_POST context.
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'post' ] );
		$this->instance->handle_save_post_prompting_validation( $post->ID );
		$this->assertFalse( has_action( 'shutdown', [ $this->instance, 'validate_queued_posts_on_frontend' ] ) );

		// Test when user doesn't have the capability.
		wp_set_current_user( $editor_user_id );
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'post' ] );
		$this->instance->handle_save_post_prompting_validation( $post->ID );
		$this->assertFalse( has_action( 'shutdown', [ $this->instance, 'validate_queued_posts_on_frontend' ] ) );

		// Test when user has dev tools turned off.
		wp_set_current_user( $admin_user_id );
		$service->set_user_enabled( $admin_user_id, false );
		$post             = self::factory()->post->create_and_get( [ 'post_type' => 'post' ] );
		$_POST['post_ID'] = $post->ID;
		$this->instance->handle_save_post_prompting_validation( $post->ID );
		$this->assertFalse( has_action( 'shutdown', [ $this->instance, 'validate_queued_posts_on_frontend' ] ) );

		// Test success.
		$service->set_user_enabled( $admin_user_id, true );
		wp_set_current_user( $admin_user_id );
		$post             = self::factory()->post->create_and_get( [ 'post_type' => 'post' ] );
		$_POST['post_ID'] = $post->ID;
		$this->instance->handle_save_post_prompting_validation( $post->ID );
		$this->assertEquals( 10, has_action( 'shutdown', [ $this->instance, 'validate_queued_posts_on_frontend' ] ) );

		add_filter(
			'pre_http_request',
			static function() {
				return new WP_Error( 'http_request_made' );
			}
		);
		$results = $this->instance->validate_queued_posts_on_frontend();
		$this->assertArrayHasKey( $post->ID, $results );
		$this->assertInstanceOf( 'WP_Error', $results[ $post->ID ] );

		unset( $GLOBALS['pagenow'] );
	}

	/**
	 * @covers ::render_status()
	 */
	public function test_render_status() {
		AMP_Options_Manager::update_option( Option::ALL_TEMPLATES_SUPPORTED, false );
		$post = self::factory()->post->create_and_get();
		wp_set_current_user(
			self::factory()->user->create(
				[
					'role' => 'administrator',
				]
			)
		);
		add_post_type_support( 'post', AMP_Post_Type_Support::SLUG );
		$amp_status_markup = '<div class="misc-pub-section misc-amp-status"';
		$checkbox_enabled  = '<input id="amp-status-enabled" type="radio" name="amp_status" value="enabled"  checked=\'checked\'>';

		// This is not in AMP 'canonical mode' but rather reader or transitional mode.
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::READER_MODE_SLUG );
		$output = get_echo( [ $this->instance, 'render_status' ], [ $post ] );
		$this->assertStringContains( $amp_status_markup, $output );
		$this->assertStringContains( $checkbox_enabled, $output );

		// This is in AMP-first mode with a template that can be rendered.
		AMP_Options_Manager::update_option( Option::THEME_SUPPORT, AMP_Theme_Support::STANDARD_MODE_SLUG );
		$output = get_echo( [ $this->instance, 'render_status' ], [ $post ] );
		$this->assertStringContains( $amp_status_markup, $output );
		$this->assertStringContains( $checkbox_enabled, $output );

		// Post type no longer supports AMP, so no status input.
		$supported_post_types = array_diff( AMP_Options_Manager::get_option( Option::SUPPORTED_POST_TYPES ), [ 'post' ] );
		AMP_Options_Manager::update_option( Option::SUPPORTED_POST_TYPES, $supported_post_types );
		$output = get_echo( [ $this->instance, 'render_status' ], [ $post ] );
		$this->assertStringContains( 'This post type is not', $output );
		$this->assertStringNotContains( $checkbox_enabled, $output );
		$supported_post_types[] = 'post';
		AMP_Options_Manager::update_option( Option::SUPPORTED_POST_TYPES, $supported_post_types );

		// No template is available to render the post.
		add_filter( 'amp_supportable_templates', '__return_empty_array' );
		AMP_Options_Manager::update_option( Option::ALL_TEMPLATES_SUPPORTED, false );
		$output = get_echo( [ $this->instance, 'render_status' ], [ $post ] );
		$this->assertStringContains( 'There are no supported templates.', wp_strip_all_tags( $output ) );
		$this->assertStringNotContains( $checkbox_enabled, $output );

		// User doesn't have the capability to display the metabox.
		add_post_type_support( 'post', AMP_Post_Type_Support::SLUG );
		wp_set_current_user(
			self::factory()->user->create(
				[
					'role' => 'subscriber',
				]
			)
		);

		$output = get_echo( [ $this->instance, 'render_status' ], [ $post ] );
		$this->assertEmpty( $output );
	}

	/**
	 * @covers ::save_amp_status()
	 */
	public function test_save_amp_status() {
		if ( version_compare( get_bloginfo( 'version' ), '5.0', '>=' ) ) {
			$this->markTestSkipped();
		}

		$this->instance->register();

		// Test failure.
		$post_id = self::factory()->post->create();
		$this->assertEmpty( get_post_meta( $post_id, PostAMPStatus::STATUS_POST_META_KEY, true ) );

		// Setup for success.
		wp_set_current_user(
			self::factory()->user->create(
				[
					'role' => 'administrator',
				]
			)
		);
		$_POST[ PostAMPStatus::NONCE_NAME ]        = wp_create_nonce( PostAMPStatus::NONCE_ACTION );
		$_POST[ PostAMPStatus::STATUS_INPUT_NAME ] = 'disabled';

		// Test revision bail.
		$post_id = self::factory()->post->create();
		delete_post_meta( $post_id, PostAMPStatus::STATUS_POST_META_KEY );
		wp_save_post_revision( $post_id );
		$this->assertEmpty( get_post_meta( $post_id, PostAMPStatus::STATUS_POST_META_KEY, true ) );

		// Test post update success to disable.
		$post_id = self::factory()->post->create();
		delete_post_meta( $post_id, PostAMPStatus::STATUS_POST_META_KEY );
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'updated',
			]
		);
		$this->assertTrue( (bool) get_post_meta( $post_id, PostAMPStatus::STATUS_POST_META_KEY, true ) );

		// Test post update success to enable.
		$_POST[ PostAMPStatus::STATUS_INPUT_NAME ] = 'enabled';
		delete_post_meta( $post_id, PostAMPStatus::STATUS_POST_META_KEY );
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'updated',
			]
		);
		$this->assertEquals( PostAMPStatus::ENABLED_STATUS, get_post_meta( $post_id, PostAMPStatus::STATUS_POST_META_KEY, true ) );
	}

	/**
	 * @covers ::preview_post_link()
	 */
	public function test_preview_post_link() {
		$link = 'https://foo.bar';
		$this->assertEquals( 'https://foo.bar', $this->instance->preview_post_link( $link ) );
		$_POST['amp-preview'] = 'do-preview';
		$this->assertEquals( 'https://foo.bar?' . amp_get_slug() . '=1', $this->instance->preview_post_link( $link ) );
	}
}
