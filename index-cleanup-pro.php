<?php
/**
 * Plugin Name:       Index Cleanup Pro
 * Plugin URI:        https://bestlocalseoservices.com/
 * Description:       Removes dead and spam URLs from Google's index. Serves "410 Gone" + noindex for listed URLs, a temporary dead-URL sitemap with lastmod, Googlebot recrawl tracking and a status tester. Never redirects, never touches real pages.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            BLSS – Best Local SEO Services & Development
 * Author URI:        https://bestlocalseoservices.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       index-cleanup-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ICP_VERSION', '1.0.0' );
define( 'ICP_DB_VERSION', '1' );
define( 'ICP_FILE', __FILE__ );
define( 'ICP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICP_URL', plugin_dir_url( __FILE__ ) );

require_once ICP_DIR . 'includes/class-icp-db.php';
require_once ICP_DIR . 'includes/class-icp-util.php';
require_once ICP_DIR . 'includes/class-icp-cache.php';
require_once ICP_DIR . 'includes/class-icp-frontend.php';

if ( is_admin() ) {
	require_once ICP_DIR . 'includes/class-icp-import.php';
	require_once ICP_DIR . 'includes/class-icp-list-table.php';
	require_once ICP_DIR . 'includes/class-icp-admin.php';
}

register_activation_hook( __FILE__, array( 'ICP_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ICP_DB', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ICP_DB', 'maybe_upgrade' ) );
add_action( 'icp_daily_cleanup', array( 'ICP_DB', 'prune_log' ) );

ICP_Frontend::init();

if ( is_admin() ) {
	ICP_Admin::init();
}
