<?php

/**
 * External notification handler.
 *
 * Exposes a REST API endpoint to receive notifications from external scripts
 * and sends them to Telegram.
 *
 * @package TelegramOrderNotify
 */

declare(strict_types=1);

namespace TON;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class External_Notification
 */
final class External_Notification
{
    /**
     * @var Telegram_Client
     */
    private Telegram_Client $client;

    /**
     * @param Telegram_Client $client
     */
    public function __construct(Telegram_Client $client)
    {
        $this->client = $client;
    }

    /**
     * Registers all WordPress hooks for this component.
     */
    public function register_hooks(): void
    {
        add_action('rest_api_init', [ $this, 'register_routes' ]);
    }

    /**
     * Registers the REST API route.
     */
    public function register_routes(): void
    {
        register_rest_route('ton/v1', '/notify', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_request' ],
            'permission_callback' => '__return_true', // Publicly accessible
        ]);
    }

    /**
     * Handles the GET request and sends the Telegram message.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_request(\WP_REST_Request $request): \WP_REST_Response
    {
        $phone       = $request->get_param('phone') ?? '';
        $product_id  = $request->get_param('product_id') ?? '';
        $tab         = $request->get_param('tab') ?? '';
        $key         = $request->get_param('key') ?? '';
        $size_ml     = $request->get_param('size_ml') ?? '';
        $atomizer_id = $request->get_param('atomizer_id') ?? '';
        $label       = $request->get_param('label') ?? '';

        // If phone is empty, it is considered an error.
        if (empty(trim((string) $phone))) {
            return $this->send_response(false, __('Введіть коректний номер телефону.', 'telegram-order-notify'));
        }

        // Try to resolve product name if WooCommerce is active and product_id is present.
        $product_name = '';
        if (! empty($product_id) && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product instanceof \WC_Product) {
                $product_name = $product->get_name();
            }
        }

        $lines = Message_Formatter::build_header(__('Запит на товар', 'telegram-order-notify'));

        $field_phone = Message_Formatter::format_field(__('Телефон', 'telegram-order-notify'), (string) $phone);
        if ($field_phone) {
            $lines[] = $field_phone;
        }

        if (! empty($product_id)) {
            $product_info = (string) $product_id;
            if ($product_name) {
                $product_info .= ' (' . $product_name . ')';
            }
            $field_product = Message_Formatter::format_field(__('ID Товару', 'telegram-order-notify'), $product_info);
            if ($field_product) {
                $lines[] = $field_product;
            }
        }

        $fields = [
            __('Мітка', 'telegram-order-notify')         => (string) $label,
            __('Об\'єм (мл)', 'telegram-order-notify')   => (string) $size_ml,
            __('ID Атомайзера', 'telegram-order-notify') => (string) $atomizer_id,
            __('Таб', 'telegram-order-notify')           => (string) $tab,
            __('Ключ', 'telegram-order-notify')          => (string) $key,
        ];

        foreach ($fields as $field_label => $field_val) {
            $formatted = Message_Formatter::format_field($field_label, $field_val);
            if ($formatted) {
                $lines[] = $formatted;
            }
        }

        $message = implode("\n", $lines);

        /**
         * Filter external notification message before sending.
         *
         * @param string           $message
         * @param \WP_REST_Request $request
         */
        $message = (string) apply_filters('ton_external_notification_message', $message, $request);

        $result = Message_Formatter::send_and_log($this->client, $message, 'external');

        if (is_wp_error($result)) {
            return $this->send_response(false, __('Помилка при відправці в Telegram.', 'telegram-order-notify'));
        }

        return $this->send_response(true);
    }

    /**
     * Formats the response according to the external script requirements.
     *
     * @param bool   $success
     * @param string $message
     * @return \WP_REST_Response
     */
    private function send_response(bool $success, string $message = ''): \WP_REST_Response
    {
        $response = new \WP_REST_Response();

        if ($success) {
            $response->set_data([ 'success' => true ]);
        } else {
            $response->set_data([
                'success' => false,
                'message' => $message,
            ]);
        }

        // Ensure CORS header is present
        $response->header('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
