<?php
/**
 * Plugin Name:       Hilo Chat
 * Description:       Chat de soporte en vivo para WooCommerce con panel de agentes instalable (PWA), notificaciones push y contexto del cliente.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Mauricio Correa
 * Author URI:        https://mauriciocorread.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hilo-chat
 * Domain Path:       /languages
 * WC requires at least: 11.0
 * WC tested up to:   11.3
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

define( 'HILO_VERSION', '1.0.0' );
define( 'HILO_DB_VERSION', '1' );
define( 'HILO_PLUGIN_FILE', __FILE__ );
define( 'HILO_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'HILO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HILO_REST_NS', 'hilo-chat/v1' );
define( 'HILO_CAP', 'hilo_answer_chats' );

require_once HILO_PLUGIN_PATH . 'includes/class-hilo-install.php';

/**
 * Declares compatibility with WooCommerce features.
 */
function hilo_declare_woocommerce_compatibility() {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HILO_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HILO_PLUGIN_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'hilo_declare_woocommerce_compatibility' );

/**
 * Loads the plugin once dependencies are available.
 */
function hilo_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$files = array(
		'settings',
		'db',
		'schedule',
		'presence',
		'push',
		'mailer',
		'uploads',
		'woo-context',
		'rest',
		'rest-visitor',
		'rest-agent',
		'widget',
		'agent-app',
		'admin',
		'plugin',
	);
	foreach ( $files as $file ) {
		require_once HILO_PLUGIN_PATH . 'includes/class-hilo-' . $file . '.php';
	}

	HILO_Plugin::instance();
}
add_action( 'plugins_loaded', 'hilo_bootstrap', 20 );

register_activation_hook( __FILE__, array( 'HILO_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HILO_Install', 'deactivate' ) );
