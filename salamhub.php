<?php
/**
 * Plugin Name:       باسلام‌هاب – همگام‌سازی ووکامرس و باسلام
 * Plugin URI:        https://github.com/tahakarami-dev/salamhub
 * Description:       فروشگاه ووکامرس و غرفه‌ی باسلام را دوطرفه همگام نگه می‌دارد: محصول یک بار ثبت می‌شود، در دو جا فروخته می‌شود و هیچ سفارشی گم نمی‌شود.
 * Version:           0.6.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Author:            طاها کرمی
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       salamhub
 * Domain Path:       /languages
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

define( 'SLH_VERSION', '0.6.1' );
define( 'SLH_DB_VERSION', '3' );
define( 'SLH_FILE', __FILE__ );
define( 'SLH_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLH_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'SLH_API_BASE' ) ) {
	// Overridable in wp-config.php (e.g. for a local mock server in tests).
	define( 'SLH_API_BASE', 'https://openapi.basalam.com' );
}

require_once SLH_DIR . 'includes/functions.php';
require_once SLH_DIR . 'includes/class-slh-installer.php';
require_once SLH_DIR . 'includes/class-slh-crypto.php';
require_once SLH_DIR . 'includes/class-slh-settings.php';
require_once SLH_DIR . 'includes/class-slh-logger.php';
require_once SLH_DIR . 'includes/class-slh-lock.php';
require_once SLH_DIR . 'includes/class-slh-links.php';
require_once SLH_DIR . 'includes/class-slh-queue.php';
require_once SLH_DIR . 'includes/class-slh-categories.php';
require_once SLH_DIR . 'includes/class-slh-price-rules.php';
require_once SLH_DIR . 'includes/class-slh-bulk.php';
require_once SLH_DIR . 'includes/class-slh-linker.php';
require_once SLH_DIR . 'includes/api/class-slh-api-error.php';
require_once SLH_DIR . 'includes/api/class-slh-api-client.php';
require_once SLH_DIR . 'includes/sync/class-slh-product-mapper.php';
require_once SLH_DIR . 'includes/sync/class-slh-image-sync.php';
require_once SLH_DIR . 'includes/sync/class-slh-product-sync.php';
require_once SLH_DIR . 'includes/class-slh-inventory.php';
require_once SLH_DIR . 'includes/orders/class-slh-order-sync.php';
require_once SLH_DIR . 'includes/orders/class-slh-reconcile.php';
require_once SLH_DIR . 'includes/class-slh-importer.php';
require_once SLH_DIR . 'includes/class-slh-notifier.php';
require_once SLH_DIR . 'includes/admin/class-slh-admin.php';
require_once SLH_DIR . 'includes/admin/class-slh-product-ui.php';
require_once SLH_DIR . 'includes/admin/class-slh-admin-tools.php';
require_once SLH_DIR . 'includes/admin/class-slh-app.php';
require_once SLH_DIR . 'includes/admin/class-slh-order-ui.php';
require_once SLH_DIR . 'includes/admin/class-slh-import-ui.php';
require_once SLH_DIR . 'includes/class-slh-plugin.php';

register_activation_hook( __FILE__, array( 'SLH_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SLH_Installer', 'deactivate' ) );

// Declare compatibility with WooCommerce HPOS (orders are not touched in this phase,
// but later phases use the CRUD API only, never direct post queries).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'SLH_Plugin', 'boot' ), 20 );
