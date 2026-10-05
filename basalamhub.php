<?php
/**
 * Plugin Name:       باسلام‌هاب – همگام‌سازی ووکامرس و باسلام
 * Plugin URI:        https://github.com/tahakarami-dev/basalamhub
 * Description:       فروشگاه ووکامرس و غرفه‌ی باسلام را دوطرفه همگام نگه می‌دارد: محصول یک بار ثبت می‌شود، در دو جا فروخته می‌شود و هیچ سفارشی گم نمی‌شود.
 * Version:           0.8.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:     10.2
 * Author:            طاها کرمی
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       basalamhub
 * Domain Path:       /languages
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

define( 'BSH_VERSION', '0.8.1' );
define( 'BSH_DB_VERSION', '3' );
define( 'BSH_FILE', __FILE__ );
define( 'BSH_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSH_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'BSH_API_BASE' ) ) {
	// Overridable in wp-config.php (e.g. for a local mock server in tests).
	define( 'BSH_API_BASE', 'https://openapi.basalam.com' );
}

require_once BSH_DIR . 'includes/functions.php';
require_once BSH_DIR . 'includes/class-bsh-installer.php';
require_once BSH_DIR . 'includes/class-bsh-migrate.php';
require_once BSH_DIR . 'includes/class-bsh-crypto.php';
require_once BSH_DIR . 'includes/class-bsh-settings.php';
require_once BSH_DIR . 'includes/class-bsh-logger.php';
require_once BSH_DIR . 'includes/class-bsh-lock.php';
require_once BSH_DIR . 'includes/class-bsh-links.php';
require_once BSH_DIR . 'includes/class-bsh-queue.php';
require_once BSH_DIR . 'includes/class-bsh-categories.php';
require_once BSH_DIR . 'includes/class-bsh-price-rules.php';
require_once BSH_DIR . 'includes/class-bsh-bulk.php';
require_once BSH_DIR . 'includes/class-bsh-linker.php';
require_once BSH_DIR . 'includes/api/class-bsh-api-error.php';
require_once BSH_DIR . 'includes/api/class-bsh-api-client.php';
require_once BSH_DIR . 'includes/sync/class-bsh-product-mapper.php';
require_once BSH_DIR . 'includes/sync/class-bsh-image-sync.php';
require_once BSH_DIR . 'includes/sync/class-bsh-product-sync.php';
require_once BSH_DIR . 'includes/class-bsh-inventory.php';
require_once BSH_DIR . 'includes/orders/class-bsh-order-sync.php';
require_once BSH_DIR . 'includes/orders/class-bsh-reconcile.php';
require_once BSH_DIR . 'includes/class-bsh-importer.php';
require_once BSH_DIR . 'includes/class-bsh-notifier.php';
require_once BSH_DIR . 'includes/class-bsh-sales.php';
require_once BSH_DIR . 'includes/class-bsh-stock-alerts.php';
require_once BSH_DIR . 'includes/class-bsh-report.php';
require_once BSH_DIR . 'includes/admin/class-bsh-icons.php';
require_once BSH_DIR . 'includes/admin/class-bsh-admin.php';
require_once BSH_DIR . 'includes/admin/class-bsh-product-ui.php';
require_once BSH_DIR . 'includes/admin/class-bsh-admin-tools.php';
require_once BSH_DIR . 'includes/admin/class-bsh-app.php';
require_once BSH_DIR . 'includes/admin/class-bsh-order-ui.php';
require_once BSH_DIR . 'includes/admin/class-bsh-import-ui.php';
require_once BSH_DIR . 'includes/class-bsh-plugin.php';

register_activation_hook( __FILE__, array( 'BSH_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BSH_Installer', 'deactivate' ) );

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

add_action( 'plugins_loaded', array( 'BSH_Plugin', 'boot' ), 20 );
