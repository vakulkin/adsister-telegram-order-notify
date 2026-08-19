<?php

/**
 * WooCommerce order notification handler.
 *
 * Hooks into WooCommerce checkout, builds the formatted Telegram
 * message from order data, and delegates delivery to Telegram_Client.
 *
 * @package TelegramOrderNotify
 */

declare(strict_types=1);

namespace TON;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Order_Notification
 */
final class Order_Notification
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
        add_action('woocommerce_checkout_order_created', [ $this, 'on_new_order' ]);
    }

    /**
     * Fires when WooCommerce creates a new order during checkout.
     *
     * @param \WC_Order $order
     */
    public function on_new_order(\WC_Order $order): void
    {
        $message = $this->build_message($order);

        /**
         * Filter the Telegram order notification message.
         *
         * @param string    $message Formatted HTML message.
         * @param \WC_Order $order   WooCommerce order instance.
         */
        $message = (string) apply_filters('ton_order_notification_message', $message, $order);

        Message_Formatter::send_and_log($this->client, $message, 'order');
    }

    /**
     * Builds the plain-HTML Telegram message from the order.
     *
     * @param \WC_Order $order
     * @return string
     */
    public function build_message(\WC_Order $order): string
    {
        $order_id  = $order->get_id();
        $order_url = esc_url($order->get_edit_order_url());
        $status    = wc_get_order_status_name($order->get_status());
        $currency  = $order->get_currency();

        $date_created = $order->get_date_created();
        $date         = ($date_created instanceof \WC_DateTime)
            ? $date_created->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))
            : '';

        // Customer details.
        $billing   = $order->get_address('billing');
        $full_name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        $email     = $billing['email'] ?? '';
        $phone     = $billing['phone'] ?? '';

        $billing_addr  = Message_Formatter::format_address($billing);
        $shipping_addr = Message_Formatter::format_address($order->get_address('shipping')) ?: $billing_addr;

        // Order items.
        $item_lines = [];
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product  = $item->get_product();
            $sku      = ($product instanceof \WC_Product) ? $product->get_sku() : '';
            $sku_text = $sku ? ' (SKU: ' . esc_html($sku) . ')' : '';
            $total    = Message_Formatter::format_price((float) $item->get_total(), $currency);

            $item_lines[] = sprintf(
                '  - %1$s%2$s x%3$d: %4$s',
                esc_html($item->get_name()),
                $sku_text,
                (int) $item->get_quantity(),
                $total
            );

            // Extract item metadata: Size, Atomizer, Tab, Key, etc.
            $details = Item_Data_Extractor::get_item_details_lines($item);
            foreach ($details as $detail_line) {
                $item_lines[] = $detail_line;
            }
        }

        // Totals.
        $subtotal       = Message_Formatter::format_price((float) $order->get_subtotal(), $currency);
        $shipping_total = Message_Formatter::format_price((float) $order->get_shipping_total(), $currency);
        $grand_total    = Message_Formatter::format_price((float) $order->get_total(), $currency);

        // Optional lines.
        $discount_total = (float) $order->get_discount_total();
        $discount_line  = '';
        if ($discount_total > 0) {
            $discount_line = "\n<b>" . esc_html__('Знижка', 'telegram-order-notify') . ':</b> -'
                . Message_Formatter::format_price($discount_total, $currency);
        }

        $coupons      = $order->get_coupon_codes();
        $coupons_line = $coupons
            ? "\n<b>" . esc_html__('Купони', 'telegram-order-notify') . ':</b> ' . esc_html(implode(', ', $coupons))
            : '';

        $customer_note = $order->get_customer_note();
        $note_line     = $customer_note
            ? "\n<b>" . esc_html__('Примітка клієнта', 'telegram-order-notify') . ':</b> ' . esc_html($customer_note)
            : '';

        // Header.
        $lines = Message_Formatter::build_header(__('Нове замовлення', 'telegram-order-notify'));

        // Order Summary.
        $lines[] = sprintf('<b>%s:</b> <a href="%s">%d</a>', esc_html__('Замовлення #', 'telegram-order-notify'), $order_url, $order_id);
        $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Дата', 'telegram-order-notify'), esc_html($date));
        $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Статус', 'telegram-order-notify'), esc_html($status));

        // Order-level Tab / Key if present
        $order_tab = $order->get_meta('tab') ?: $order->get_meta('tab_key');
        if (! empty($order_tab)) {
            $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Таб', 'telegram-order-notify'), esc_html((string) $order_tab));
        }
        $order_key = $order->get_meta('key');
        if (! empty($order_key)) {
            $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Ключ', 'telegram-order-notify'), esc_html((string) $order_key));
        }

        // Customer Info.
        $lines[] = '';
        $lines[] = '<b>' . esc_html__('Клієнт', 'telegram-order-notify') . '</b>';
        $lines[] = sprintf('%s: %s', esc_html__('Ім\'я', 'telegram-order-notify'), esc_html($full_name));

        if ($email) {
            $lines[] = sprintf('Email: %s', esc_html($email));
        }
        if ($phone) {
            $lines[] = sprintf('%s: %s', esc_html__('Телефон', 'telegram-order-notify'), esc_html($phone));
        }

        // Addresses.
        $lines[] = '';
        $lines[] = '<b>' . esc_html__('Адреса платника', 'telegram-order-notify') . '</b>';
        $lines[] = $billing_addr;
        $lines[] = '';
        $lines[] = '<b>' . esc_html__('Адреса доставки', 'telegram-order-notify') . '</b>';
        $lines[] = $shipping_addr;

        // Items.
        $lines[] = '';
        $lines[] = '<b>' . esc_html__('Товари', 'telegram-order-notify') . '</b>';
        foreach ($item_lines as $item_line) {
            $lines[] = $item_line;
        }

        // Payment & Shipping methods.
        $lines[] = '';
        $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Оплата', 'telegram-order-notify'), esc_html($order->get_payment_method_title() ?: '—'));
        $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Доставка', 'telegram-order-notify'), esc_html($order->get_shipping_method() ?: '—'));

        // Totals.
        $lines[] = '';
        $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Вартість товарів', 'telegram-order-notify'), $subtotal);
        $lines[] = sprintf('<b>%s:</b> %s', esc_html__('Вартість доставки', 'telegram-order-notify'), $shipping_total);

        return implode("\n", $lines)
            . $discount_line
            . $coupons_line
            . sprintf("\n<b>%s:</b> %s", esc_html__('Разом', 'telegram-order-notify'), $grand_total)
            . $note_line;
    }
}
