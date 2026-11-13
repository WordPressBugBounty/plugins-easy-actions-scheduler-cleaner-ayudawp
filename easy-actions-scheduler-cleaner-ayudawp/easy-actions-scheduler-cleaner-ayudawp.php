<?php
/**
 * Plugin Name: Easy Actions Scheduler Cleaner
 * Plugin URI: https://servicios.ayudawp.com
 * Description: Clean up your Action Scheduler database by removing completed, failed, canceled, past-due actions, old pending actions, and action logs. Manual and scheduled cleanup modes with email notifications.
 * Version: 1.3.1
 * Author: Fernando Tellado
 * Author URI: https://ayudawp.com
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 11.0
 *
 * WC HPOS Compatible: yes
 * WC Blocks Compatible: yes
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-actions-scheduler-cleaner-ayudawp
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants.
define( 'EASC_PLUGIN_VERSION', '1.3.1' );
define( 'EASC_PLUGIN_FILE', __FILE__ );
define( 'EASC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EASC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* Declare HPOS compatibility */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Include required files.
require_once EASC_PLUGIN_DIR . 'includes/core-functions.php';
require_once EASC_PLUGIN_DIR . 'includes/database-functions.php';
require_once EASC_PLUGIN_DIR . 'includes/space-functions.php';
require_once EASC_PLUGIN_DIR . 'includes/schedule-functions.php';
require_once EASC_PLUGIN_DIR . 'includes/notification-functions.php';
require_once EASC_PLUGIN_DIR . 'includes/admin-functions.php';
require_once EASC_PLUGIN_DIR . 'includes/class-easc-promo-banner.php';

/**
 * Main plugin class
 *
 * @since 1.0.0
 */
class Easy_Actions_Scheduler_Cleaner_AyudaWP {

	/**
	 * Single instance of the plugin
	 *
	 * @since 1.0.0
	 * @var Easy_Actions_Scheduler_Cleaner_AyudaWP
	 */
	private static $instance = null;

	/**
	 * Get single instance
	 *
	 * @since 1.0.0
	 * @return Easy_Actions_Scheduler_Cleaner_AyudaWP
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		register_activation_hook( EASC_PLUGIN_FILE, 'easc_on_activation' );
		register_deactivation_hook( EASC_PLUGIN_FILE, 'easc_on_deactivation' );
	}

	/**
	 * Initialize plugin
	 *
	 * @since 1.0.0
	 */
	public function init() {
		if ( is_admin() ) {
			easc_init_admin();
		}

		// Register scheduled cleanup hook.
		easc_init_schedule();

		// Register notification hooks.
		easc_init_notifications();
	}
}

// Initialize plugin.
Easy_Actions_Scheduler_Cleaner_AyudaWP::get_instance();
