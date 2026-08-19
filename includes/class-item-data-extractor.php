<?php

/**
 * Item data and metadata extractor for WooCommerce orders.
 *
 * Extracts and formats item-level attributes such as Size/Volume,
 * Atomizer ID / Packaging, Tab, and Key for inclusion in Telegram notifications.
 *
 * @package TelegramOrderNotify
 */

declare(strict_types=1);

namespace TON;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Item_Data_Extractor
 */
final class Item_Data_Extractor
{
    /**
     * Extracts and returns formatted bullet lines for a single order line item.
     *
     * @param \WC_Order_Item_Product $item
     * @return array<int, string> List of formatted bullet strings.
     */
    public static function get_item_details_lines(\WC_Order_Item_Product $item): array
    {
        $details = [];

        // 1. Size / Volume (pa_volume or size_ml)
        $size = self::extract_size($item);
        if ($size) {
            $formatted = Message_Formatter::format_bullet(
                __('Об\'єм (мл)', 'telegram-order-notify'),
                $size
            );
            if ($formatted) {
                $details[] = $formatted;
            }
        }

        // 2. Atomizer / Packaging (atomizer_id or wc_attr_options)
        $atomizer = self::extract_atomizer($item);
        if ($atomizer) {
            $formatted = Message_Formatter::format_bullet(
                __('ID Атомайзера', 'telegram-order-notify'),
                $atomizer
            );
            if ($formatted) {
                $details[] = $formatted;
            }
        }

        // 3. Tab
        $tab = $item->get_meta('tab');
        if (! empty($tab)) {
            $formatted = Message_Formatter::format_bullet(
                __('Таб', 'telegram-order-notify'),
                (string) $tab
            );
            if ($formatted) {
                $details[] = $formatted;
            }
        }

        // 4. Key
        $key = $item->get_meta('key');
        if (! empty($key)) {
            $formatted = Message_Formatter::format_bullet(
                __('Ключ', 'telegram-order-notify'),
                (string) $key
            );
            if ($formatted) {
                $details[] = $formatted;
            }
        }

        /**
         * Filter item details lines before adding to the message.
         *
         * @param array<int, string>     $details
         * @param \WC_Order_Item_Product $item
         */
        return (array) apply_filters('ton_order_item_details_lines', $details, $item);
    }

    /**
     * Extracts size / volume from item variation or metadata.
     *
     * @param \WC_Order_Item_Product $item
     * @return string|null
     */
    private static function extract_size(\WC_Order_Item_Product $item): ?string
    {
        $val = $item->get_meta('pa_volume') ?: $item->get_meta('size_ml');

        if (empty($val)) {
            $product = $item->get_product();
            if ($product instanceof \WC_Product_Variation) {
                $val = $product->get_attribute('pa_volume');
            }
        }

        if (empty($val)) {
            return null;
        }

        $val = (string) $val;

        // Resolve taxonomy term name if taxonomy exists (e.g. slug "10-ml" -> "10 мл")
        if (taxonomy_exists('pa_volume')) {
            $term = get_term_by('slug', $val, 'pa_volume');
            if ($term instanceof \WP_Term && ! empty($term->name)) {
                return $term->name;
            }
        }

        if (preg_match('/^(\d+)[-_]?ml$/i', trim($val), $matches)) {
            return $matches[1] . ' мл';
        }

        return trim($val);
    }

    /**
     * Extracts atomizer ID or packaging details from item metadata.
     *
     * @param \WC_Order_Item_Product $item
     * @return string|null
     */
    private static function extract_atomizer(\WC_Order_Item_Product $item): ?string
    {
        $val = $item->get_meta('atomizer_id') ?: $item->get_meta('wc_attr_options');

        if (empty($val)) {
            return null;
        }

        if (is_array($val)) {
            return self::format_atomizer_array($val);
        }

        if (is_string($val) && (str_starts_with(trim($val), '[') || str_starts_with(trim($val), '{'))) {
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                return self::format_atomizer_array($decoded);
            }
        }

        return (string) $val;
    }

    /**
     * Formats array-based atomizer options (such as wc_attr_options).
     *
     * @param array<mixed> $options
     * @return string
     */
    private static function format_atomizer_array(array $options): string
    {
        $results = [];

        foreach ($options as $option) {
            if (is_array($option)) {
                $title = $option['title'] ?? $option['name'] ?? $option['id'] ?? '';
                if ($title) {
                    $results[] = (string) $title;
                }
            } elseif (is_string($option)) {
                $results[] = $option;
            }
        }

        return implode(', ', $results);
    }
}
