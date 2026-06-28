<?php

/**
 * Plugin Name:       Telegram Order Notifications
 * Description:       Sends a Telegram notification to the administrator when a new WooCommerce order is created.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Anton Vakulov
 * Text Domain:       telegram-order-notify
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package TelegramOrderNotify
 */

if (! defined('ABSPATH')) {
    exit;
}

define('TON_VERSION', '1.0.0');
define('TON_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once TON_PLUGIN_DIR . 'includes/class-telegram-client.php';
require_once TON_PLUGIN_DIR . 'includes/class-order-notification.php';
require_once TON_PLUGIN_DIR . 'includes/class-external-notification.php';
require_once TON_PLUGIN_DIR . 'includes/class-settings.php';
require_once TON_PLUGIN_DIR . 'includes/class-plugin.php';

add_action('plugins_loaded', [ 'TON\Plugin', 'instance' ]);
