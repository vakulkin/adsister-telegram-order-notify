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
        $result  = $this->client->send_message($message);

        if (is_wp_error($result)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[TON] Failed to send Telegram notification: ' . $result->get_error_message());
        }
    }

    /**
     * Builds the plain-HTML Telegram message from the order.
     *
     * @param \WC_Order $order
     * @return string
     */
    private function build_message(\WC_Order $order): string
    {
        $site_name = get_bloginfo('name');
        $order_id  = $order->get_id();
        $order_url = esc_url($order->get_edit_order_url());
        $status    = wc_get_order_status_name($order->get_status());

        $date_created = $order->get_date_created();
        $date         = ($date_created instanceof \WC_DateTime)
            ? $date_created->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))
            : '';

        // Customer details.
        $billing   = $order->get_address('billing');
        $full_name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        $email     = $billing['email'] ?? '';
        $phone     = $billing['phone'] ?? '';

        $billing_addr  = $this->format_address($billing);
        $shipping_addr = $this->format_address($order->get_address('shipping')) ?: $billing_addr;

        // Order items.
        $item_lines = [];
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product  = $item->get_product();
            $sku      = ($product instanceof \WC_Product) ? $product->get_sku() : '';
            $sku_text = $sku ? ' (SKU: ' . esc_html($sku) . ')' : '';
            $total    = $this->format_price((float) $item->get_total(), $order->get_currency());

            $item_lines[] = sprintf(
                '  - %1$s%2$s x%3$d: %4$s',
                esc_html($item->get_name()),
                $sku_text,
                (int) $item->get_quantity(),
                $total
            );
        }

        // Totals.
        $subtotal       = $this->format_price((float) $order->get_subtotal(), $order->get_currency());
        $shipping_total = $this->format_price((float) $order->get_shipping_total(), $order->get_currency());
        $grand_total    = $this->format_price((float) $order->get_total(), $order->get_currency());

        // Optional lines.
        $discount_total = (float) $order->get_discount_total();
        $discount_line  = '';
        if ($discount_total > 0) {
            $discount_line = "\n<b>Discount:</b> -" . $this->format_price($discount_total, $order->get_currency());
        }

        $coupons      = $order->get_coupon_codes();
        $coupons_line = $coupons
            ? "\n<b>Coupons:</b> " . esc_html(implode(', ', $coupons))
            : '';

        $customer_note = $order->get_customer_note();
        $note_line     = $customer_note
            ? "\n<b>Customer note:</b> " . esc_html($customer_note)
            : '';

        // Assemble message lines.
        $lines = [
            sprintf('<b>New order — %s</b>', esc_html($site_name)),
            '---',
            sprintf('<b>Order #:</b> <a href="%s">%d</a>', $order_url, $order_id),
            sprintf('<b>Date:</b> %s', esc_html($date)),
            sprintf('<b>Status:</b> %s', esc_html($status)),
            '',
            '<b>Customer</b>',
            sprintf('Name: %s', esc_html($full_name)),
        ];

        if ($email) {
            $lines[] = sprintf('Email: %s', esc_html($email));
        }
        if ($phone) {
            $lines[] = sprintf('Phone: %s', esc_html($phone));
        }

        $lines[] = '';
        $lines[] = '<b>Billing address</b>';
        $lines[] = $billing_addr;
        $lines[] = '';
        $lines[] = '<b>Shipping address</b>';
        $lines[] = $shipping_addr;
        $lines[] = '';
        $lines[] = '<b>Items</b>';

        foreach ($item_lines as $item_line) {
            $lines[] = $item_line;
        }

        $lines[] = '';
        $lines[] = sprintf('<b>Payment:</b> %s', esc_html($order->get_payment_method_title() ?: '—'));
        $lines[] = sprintf('<b>Shipping method:</b> %s', esc_html($order->get_shipping_method() ?: '—'));
        $lines[] = '';
        $lines[] = sprintf('<b>Subtotal:</b> %s', $subtotal);
        $lines[] = sprintf('<b>Shipping cost:</b> %s', $shipping_total);

        return implode("\n", $lines)
            . $discount_line
            . $coupons_line
            . sprintf("\n<b>Total:</b> %s", $grand_total)
            . $note_line;
    }

    /**
     * Converts an address array to a single escaped string.
     *
     * @param array<string,string> $address
     * @return string
     */
    private function format_address(array $address): string
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
     * Formats a monetary amount stripping wc_price HTML to plain text.
     *
     * @param float  $amount
     * @param string $currency
     * @return string
     */
    private function format_price(float $amount, string $currency): string
    {
        return html_entity_decode(
            strip_tags(wc_price($amount, [ 'currency' => $currency ])),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
