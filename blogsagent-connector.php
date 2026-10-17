<?php
/**
 * Plugin Name:       BlogsAgent Connector
 * Description:       Connects your WordPress site to BlogsAgent so scheduled, AI-written posts are delivered to the categories you choose.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            BlogsAgent
 * License:           GPL-2.0-or-later
 * Text Domain:       blogsagent-connector
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BLOGSAGENT_VERSION', '1.0.0');
define('BLOGSAGENT_OPTION', 'blogsagent_connection');
define('BLOGSAGENT_REPLAY_PREFIX', 'ba_sig_');

require_once __DIR__ . '/includes/class-blogsagent-connection.php';
require_once __DIR__ . '/includes/class-blogsagent-rest.php';
require_once __DIR__ . '/includes/class-blogsagent-admin.php';
require_once __DIR__ . '/includes/class-blogsagent-sync.php';

add_action('plugins_loaded', function () {
    BlogsAgent_REST::init();
    BlogsAgent_Admin::init();
    BlogsAgent_Sync::init();
});
