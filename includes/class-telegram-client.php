<?php

/**
 * Telegram Bot API client.
 *
 * Thin wrapper around wp_remote_post that sends a single text message
 * to a configured chat. Authentication credentials are read from
 * WordPress options at send time so they stay in sync with the
 * settings page without needing to re-instantiate the client.
 *
 * @package TelegramOrderNotify
 */

declare(strict_types=1);

namespace TON;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Telegram_Client
 */
final class Telegram_Client
{
    /**
     * Telegram Bot API base URL (token is appended at call time).
     */
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * HTTP timeout in seconds for requests to the Telegram API.
     */
    private const HTTP_TIMEOUT = 15;

    /**
     * Sends an HTML-formatted message to the configured chat.
     *
     * @param string $text Message body. Telegram HTML subset is supported.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function send_message(string $text): true|\WP_Error
    {
        $token   = (string) get_option('ton_bot_token', '');
        $chat_id = (string) get_option('ton_chat_id', '');

        if ('' === $token || '' === $chat_id) {
            return new \WP_Error(
                'ton_config',
                __('Telegram bot token or chat ID is not configured.', 'telegram-order-notify')
            );
        }

        // The token is appended as a URL path segment; colons and hyphens in
        // bot tokens are valid path characters and must not be percent-encoded.
        $url = self::API_BASE . $token . '/sendMessage';

        $response = wp_remote_post(
            $url,
            [
                'timeout' => self::HTTP_TIMEOUT,
                'body'    => [
                    'chat_id'                  => $chat_id,
                    'text'                     => $text,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => 'true',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);

        if (200 !== $http_code) {
            return new \WP_Error(
                'ton_api',
                sprintf(
                    /* translators: 1: HTTP status code, 2: API response body */
                    __('Telegram API error (HTTP %1$d): %2$s', 'telegram-order-notify'),
                    $http_code,
                    wp_remote_retrieve_body($response)
                )
            );
        }

        return true;
    }
}
