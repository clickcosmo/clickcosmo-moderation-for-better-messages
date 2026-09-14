<?php
/**
 * Plugin Name:       Message Board Moderation for Better Messages
 * Description:       Adds configurable per-thread pre-moderation and a frontend moderation queue for Better Messages.
 * Version:           1.0.3
 * Author:            ClickCOSMO
 * Author URI:        https://clickcosmo.com
 * Text Domain:       message-board-moderation-for-bm
 * ClickCOSMO Support: yes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MBM_BM_VERSION', '1.0.3' );
define( 'MBM_BM_FILE', __FILE__ );
define( 'MBM_BM_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBM_BM_URL', plugin_dir_url( __FILE__ ) );

require_once MBM_BM_DIR . 'includes/class-mbm-bm.php';

MBM_BM::instance();
