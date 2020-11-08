<?php
/**
 * Handles a single admin script.
 *
 * @package AMP
 */

namespace AmpProject\AmpWP;

/**
 * Registers a script and performs related functions.
 *
 * @internal
 */
final class Script {

	/**
	 * Option to indicate the script should be printed in the document head.
	 *
	 * @var string
	 */
	const FLAG_NAME_IN_HEAD = 'in_head';

	/**
	 * Flag for use if the script has an accompanying stylesheet.
	 *
	 * @var string
	 */
	const FLAG_NAME_HAS_STYLE = 'has_style';

	/**
	 * The script handle.
	 *
	 * @var string
	 */
	private $handle;

	/**
	 * Array of options flags.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * Class constructor.
	 *
	 * @param string   $handle Script handle. Is expected to be the name of the file.
	 * @param string[] $options Array of options flags.
	 */
	public function __construct( $handle, $options = [] ) {
		$this->handle  = $handle;
		$this->options = $options;
	}

	/**
	 * Enqueues the script.
	 */
	public function enqueue() {
		$asset_file = AMP__DIR__ . '/assets/js/' . $this->handle . '.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset        = require $asset_file;
		$dependencies = $asset['dependencies'];
		$version      = $asset['version'];

		wp_enqueue_script(
			$this->handle,
			amp_get_asset_url( 'js/' . $this->handle . '.js' ),
			$dependencies,
			$version,
			! $this->get_option( self::FLAG_NAME_IN_HEAD )
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $this->handle, 'amp' );
		} elseif ( function_exists( 'wp_get_jed_locale_data' ) || function_exists( 'gutenberg_get_jed_locale_data' ) ) {
			$locale_data  = function_exists( 'wp_get_jed_locale_data' ) ? wp_get_jed_locale_data( 'amp' ) : gutenberg_get_jed_locale_data( 'amp' );
			$translations = wp_json_encode( $locale_data );

			wp_add_inline_script(
				$this->handle,
				'wp.i18n.setLocaleData( ' . $translations . ', "amp" );',
				'after'
			);
		}

		if ( $this->get_option( self::FLAG_NAME_HAS_STYLE ) ) {
			$this->enqueue_style( $version );
		}
	}

	/**
	 * Makes a variable available to the script.
	 *
	 * @param string $var_name The name of the variable that will be available globally in JS.
	 * @param mixed  $data Data to assign to the the variable. Will be JSON encoded.
	 * @return void
	 */
	public function add_data( $var_name, $data ) {
		wp_add_inline_script(
			$this->handle,
			sprintf(
				'var %s = %s',
				esc_js( $var_name ),
				wp_json_encode( $data )
			),
			'before'
		);
	}

	/**
	 * Enqueues the script's correponding style.
	 *
	 * @param string $version The asset version.
	 */
	private function enqueue_style( $version = AMP__VERSION ) {
		wp_enqueue_style(
			$this->handle,
			amp_get_asset_url( 'css/' . $this->handle . '.css' ),
			[],
			$version
		);

		wp_styles()->add_data( $this->handle, 'rtl', 'replace' );
	}

	/**
	 * Returns whether the specified flag was passed in the options array.
	 *
	 * @param string $flag_name Flag name.
	 * @return bool
	 */
	private function get_option( $flag_name ) {
		return in_array( $flag_name, $this->options, true );
	}
}
