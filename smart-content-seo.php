<?php
/**
 * Plugin Name:       Smart Content SEO
 * Description:       Write posts as usual (or let AI write them with free photos), then let AI (Claude, ChatGPT or Gemini) turn them into visually designed articles with their own HTML/CSS. Edit by leaving comments for the AI.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-content-seo
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SMC_VERSION', '0.3.0' );
define( 'SMC_FILE', __FILE__ );
define( 'SMC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMC_URL', plugin_dir_url( __FILE__ ) );

require_once SMC_DIR . 'includes/functions.php';
require_once SMC_DIR . 'includes/class-smc-css.php';
require_once SMC_DIR . 'includes/class-smc-posts.php';
require_once SMC_DIR . 'includes/class-smc-ai.php';
require_once SMC_DIR . 'includes/class-smc-stock.php';
require_once SMC_DIR . 'includes/class-smc-writer.php';
require_once SMC_DIR . 'includes/class-smc-jobs.php';
require_once SMC_DIR . 'includes/class-smc-rest.php';
require_once SMC_DIR . 'includes/class-smc-render.php';
require_once SMC_DIR . 'includes/class-smc-settings.php';
require_once SMC_DIR . 'includes/class-smc-admin.php';
require_once SMC_DIR . 'includes/class-smc-list.php';

// Bản dịch đi kèm plugin (thư mục languages), dùng khi plugin không được cài từ WordPress.org.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'smart-content-seo', false, dirname( plugin_basename( SMC_FILE ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		SMC_Posts::init();
		SMC_Rest::init();
		SMC_Render::init();
		if ( is_admin() ) {
			SMC_Settings::init();
			SMC_Admin::init();
			SMC_List::init();
		}
	}
);
