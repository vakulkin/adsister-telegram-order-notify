<?php

/**
 * Message formatter and dispatcher helper for Telegram notifications.
 *
 * Provides shared utilities for HTML message assembly, field formatting,
 * price/address formatting, and unified dispatching with error logging.
 *
 * @package TelegramOrderNotify
 */

declare(strict_types=1);

namespace TON;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Message_Formatter
 */
final class Message_Formatter
{
    /**
     * Builds standard header lines for Telegram messages.
     *
     * @param string $title Header title.
     * @return array<int, string>
     */
    public static function build_header(string $title): array
    {
        $site_name = get_bloginfo('name');

        return [
            sprintf('<b>%s — %s</b>', esc_html($title), esc_html($site_name)),
            '---',
        ];
    }

    /**
     * Formats a labeled bold field line if the value is not empty.
     *
     * @param string      $label Field label (e.g. "Телефон", "ID Товару").
     * @param string|null $value Field value.
     * @param bool        $escape Whether to escape the value with esc_html.
     * @return string|null Formatted line or null if value is empty.
     */
    public static function format_field(string $label, ?string $value, bool $escape = true): ?string
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        $formatted_value = $escape ? esc_html(trim((string) $value)) : trim((string) $value);

        return sprintf('<b>%s:</b> %s', esc_html($label), $formatted_value);
    }

    /**
     * Formats an indented bullet item line if the value is not empty.
     *
     * @param string      $label Field label.
     * @param string|null $value Field value.
     * @param bool        $escape Whether to escape the value.
     * @return string|null Formatted bullet line or null if value is empty.
     */
    public static function format_bullet(string $label, ?string $value, bool $escape = true): ?string
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        $formatted_value = $escape ? esc_html(trim((string) $value)) : trim((string) $value);

        return sprintf('    • <b>%s:</b> %s', esc_html($label), $formatted_value);
    }

    /**
     * Converts a WooCommerce address array to a single escaped string.
     *
     * @param array<string, mixed> $address
     * @return string
     */
    public static function format_address(array $address): string
    {
        $parts = array_filter([
            $address['address_1'] ?? '',
            $address['address_2'] ?? '',
            $address['city']      ?? '',
            $address['state']     ?? '',
            $address['postcode']  ?? '',
            $address['country']   ?? '',
        ]);

        return esc_html(implode(', ', $parts));
    }

    /**
     * Formats a monetary amount, stripping wc_price HTML markup to plain text.
     *
     * @param float  $amount
     * @param string $currency
     * @return string
     */
    public static function format_price(float $amount, string $currency): string
    {
        if (! function_exists('wc_price')) {
            return sprintf('%.2f %s', $amount, $currency);
        }

        return html_entity_decode(
            strip_tags(wc_price($amount, [ 'currency' => $currency ])),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    /**
     * Sends a message via Telegram_Client and logs errors with context if it fails.
     *
     * @param Telegram_Client $client Telegram client instance.
     * @param string          $message Formatted message body.
     * @param string          $context Description for error logs (e.g. "order", "external").
     * @return true|\WP_Error
     */
    public static function send_and_log(Telegram_Client $client, string $message, string $context = 'order'): true|\WP_Error
    {
        $result = $client->send_message($message);

        if (is_wp_error($result)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('[TON] Failed to send %s Telegram notification: %s', $context, $result->get_error_message()));
        }

        return $result;
    }
}
