<?php
/**
 * Core plugin bootstrap.
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads all plugin classes and wires their hooks.
 */
final class MIS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var MIS_Plugin|null
	 */
	private static ?MIS_Plugin $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return MIS_Plugin
	 */
	public static function instance(): MIS_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Requires class files and hooks bootstrap into plugins_loaded.
	 */
	private function __construct() {
		$this->load_dependencies();
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Loads every component class file.
	 */
	private function load_dependencies(): void {
		$files = array(
			'class-mis-settings.php',
			'class-mis-signer.php',
			'class-mis-api-client.php',
			'class-mis-job-store.php',
			'class-mis-poller.php',
			'class-mis-publisher.php',
			'class-mis-post-ui.php',
			'class-mis-admin.php',
		);

		foreach ( $files as $file ) {
			$path = MIS_PLUGIN_DIR . 'includes/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Calls each component's register() method once its class is available.
	 */
	public function init(): void {
		$components = array(
			'MIS_Settings',
			'MIS_Publisher',
			'MIS_Poller',
			'MIS_Admin',
			'MIS_Post_UI',
		);

		foreach ( $components as $class ) {
			if ( class_exists( $class ) && is_callable( array( $class, 'register' ) ) ) {
				call_user_func( array( $class, 'register' ) );
			}
		}
	}
}
