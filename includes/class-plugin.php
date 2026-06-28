<?php

/**
 * Main plugin controller.
 *
 * Bootstraps all plugin components and wires them together via
 * dependency injection. Follows the singleton pattern so WordPress
 * only instantiates the plugin once per request.
 *
 * @package TelegramOrderNotify
 */

declare(strict_types=1);

namespace TON;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Plugin
 */
final class Plugin
{
    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * @var Telegram_Client
     */
    private Telegram_Client $telegram_client;

    /**
     * @var Settings
     */
    private Settings $settings;

    /**
     * @var Order_Notification
     */
    private Order_Notification $order_notification;

    /**
     * Private constructor — use instance() instead.
     */
    private function __construct()
    {
    }

    /**
     * Returns (and creates on first call) the singleton instance.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }

        return self::$instance;
    }

    /**
     * Wires up all components.
     */
    private function init(): void
    {
        $this->telegram_client    = new Telegram_Client();
        $this->settings           = new Settings($this->telegram_client);
        $this->order_notification = new Order_Notification($this->telegram_client);

        $this->settings->register_hooks();
        $this->order_notification->register_hooks();
    }
}
