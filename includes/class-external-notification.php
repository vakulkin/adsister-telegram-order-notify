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
     */
    public function handle_request(\WP_REST_Request $request)
    {
        $phone       = $request->get_param('phone') ?? '';
        $product_id  = $request->get_param('product_id') ?? '';
        $tab         = $request->get_param('tab') ?? '';
        $key         = $request->get_param('key') ?? '';
        $size_ml     = $request->get_param('size_ml') ?? '';
        $atomizer_id = $request->get_param('atomizer_id') ?? '';
        $label       = $request->get_param('label') ?? '';

        // If phone is empty, it's considered an error based on user instructions.
        if (empty($phone)) {
            return $this->send_response(false, 'Введіть коректний номер телефону.');
        }

        // Try to get the product name if WooCommerce is active and product_id is present.
        $product_name = '';
        if (!empty($product_id) && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product instanceof \WC_Product) {
                $product_name = $product->get_name();
            }
        }

        $site_name = get_bloginfo('name');
        
        $lines = [
            sprintf('<b>Швидке замовлення — %s</b>', esc_html($site_name)),
            '---',
            '<b>Телефон:</b> ' . esc_html(trim((string)$phone)),
        ];

        if (!empty($product_id)) {
            $product_info = esc_html((string)$product_id);
            if ($product_name) {
                $product_info .= ' (' . esc_html($product_name) . ')';
            }
            $lines[] = '<b>ID Товару:</b> ' . $product_info;
        }

        if (!empty($label)) {
            $lines[] = '<b>Мітка:</b> ' . esc_html((string)$label);
        }
        if (!empty($size_ml)) {
            $lines[] = '<b>Об\'єм (мл):</b> ' . esc_html((string)$size_ml);
        }
        if (!empty($atomizer_id)) {
            $lines[] = '<b>ID Атомайзера:</b> ' . esc_html((string)$atomizer_id);
        }
        if (!empty($tab)) {
            $lines[] = '<b>Таб:</b> ' . esc_html((string)$tab);
        }
        if (!empty($key)) {
            $lines[] = '<b>Ключ:</b> ' . esc_html((string)$key);
        }

        $message = implode("\n", $lines);
        $result  = $this->client->send_message($message);

        if (is_wp_error($result)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[TON] Failed to send external Telegram notification: ' . $result->get_error_message());
            return $this->send_response(false, 'Помилка при відправці в Telegram.');
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
    private function send_response(bool $success, string $message = '')
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
        
        // Ensure CORS header is present as requested
        $response->header('Access-Control-Allow-Origin', '*');
        
        return $response;
    }
}
