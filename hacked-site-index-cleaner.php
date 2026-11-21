<?php
/**
 * Plugin Name:       Hacked Site Index Cleaner
 * Plugin URI:        https://github.com/amitwpseo/hacked-site-index-cleaner
 * Description:       Remove hacked-site spam URLs and dead pages from Google. Serves 410 Gone + noindex, a temporary dead-URL sitemap with lastmod, verified Googlebot recrawl tracking and a status tester. Never redirects, never touches real pages.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            BLSS – Best Local SEO Services & Development
 * Author URI:        https://bestlocalseoservices.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hacked-site-index-cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Another copy of this plugin (for example the older "Index Cleanup Pro" build)
// is already loaded: stop here instead of redeclaring its classes.
if ( defined( 'ICP_VERSION' ) || class_exists( 'ICP_DB', false ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><strong>Hacked Site Index Cleaner:</strong> another copy of this plugin (for example “Index Cleanup Pro”) is already active. Deactivate the old one first, then activate this one. Your URL list is kept.</p></div>';
		}
	);
	return;
}

define( 'ICP_VERSION', '1.1.0' );
define( 'ICP_DB_VERSION', '3' );
define( 'ICP_FILE', __FILE__ );
define( 'ICP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICP_URL', plugin_dir_url( __FILE__ ) );

require_once ICP_DIR . 'includes/class-icp-db.php';
require_once ICP_DIR . 'includes/class-icp-util.php';
require_once ICP_DIR . 'includes/class-icp-cache.php';
require_once ICP_DIR . 'includes/class-icp-frontend.php';
require_once ICP_DIR . 'includes/class-icp-spam.php';
require_once ICP_DIR . 'includes/class-icp-rules.php';
require_once ICP_DIR . 'includes/class-icp-monitor.php';
require_once ICP_DIR . 'includes/class-icp-search.php';
require_once ICP_DIR . 'includes/class-icp-tester.php';

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
ICP_Search::init();
ICP_Monitor::init();
ICP_Tester::init();

if ( is_admin() ) {
	ICP_Admin::init();
}
