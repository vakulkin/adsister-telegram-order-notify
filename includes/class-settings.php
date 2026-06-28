<?php
/**
 * Admin settings page.
 *
 * Registers the plugin options page, the individual settings fields,
 * and handles the test-notification form submission via admin-post.php
 * (PRG pattern — post, set transient, redirect, display notice).
 *
 * @package TelegramOrderNotify
 */

declare(strict_types=1);

namespace TON;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Settings
 */
final class Settings
{
    private const PAGE_SLUG         = 'ton-settings';
    private const SETTINGS_GROUP    = 'ton_settings_group';
    private const SETTINGS_SECTION  = 'ton_main';
    private const OPTION_TOKEN      = 'ton_bot_token';
    private const OPTION_CHAT_ID    = 'ton_chat_id';
    private const ADMIN_POST_ACTION = 'ton_test_notification';
    private const NONCE_ACTION      = 'ton_test_notification';
    private const NONCE_FIELD       = 'ton_test_nonce';
    private const TRANSIENT_PREFIX  = 'ton_test_result_';

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
        add_action('admin_menu', [ $this, 'add_settings_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('admin_post_' . self::ADMIN_POST_ACTION, [ $this, 'handle_test_notification' ]);
    }

    /**
     * Registers the options sub-page under Settings.
     */
    public function add_settings_page(): void
    {
        add_options_page(
            __('Telegram Order Notifications', 'telegram-order-notify'),
            __('Telegram Orders', 'telegram-order-notify'),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Registers settings, section, and fields via the Settings API.
     */
    public function register_settings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_TOKEN,
            [
                'type'              => 'string',
                'description'       => __('Telegram Bot API token', 'telegram-order-notify'),
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest'      => false,
                'default'           => '',
            ]
        );

        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_CHAT_ID,
            [
                'type'              => 'string',
                'description'       => __('Telegram chat or channel ID', 'telegram-order-notify'),
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest'      => false,
                'default'           => '',
            ]
        );

        add_settings_section(
            self::SETTINGS_SECTION,
            __('Bot Configuration', 'telegram-order-notify'),
            '__return_null',
            self::PAGE_SLUG
        );

        add_settings_field(
            'ton_field_bot_token',
            __('Bot Token', 'telegram-order-notify'),
            [ $this, 'render_field_bot_token' ],
            self::PAGE_SLUG,
            self::SETTINGS_SECTION
        );

        add_settings_field(
            'ton_field_chat_id',
            __('Chat / Channel ID', 'telegram-order-notify'),
            [ $this, 'render_field_chat_id' ],
            self::PAGE_SLUG,
            self::SETTINGS_SECTION
        );
    }

    /**
     * Renders the Bot Token input field.
     */
    public function render_field_bot_token(): void
    {
        $value = esc_attr((string) get_option(self::OPTION_TOKEN, ''));
        printf(
            '<input type="text" id="ton_bot_token" name="%s" value="%s" class="regular-text" placeholder="123456:ABC-DEF..." />',
            esc_attr(self::OPTION_TOKEN),
            $value // already escaped above
        );
        echo '<p class="description">';
        printf(
            wp_kses(
                /* translators: %s: URL to BotFather */
                __('Create a bot via <a href="%s" target="_blank" rel="noopener noreferrer">@BotFather</a> and paste the token here.', 'telegram-order-notify'),
                [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
            ),
            'https://t.me/BotFather'
        );
        echo '</p>';
    }

    /**
     * Renders the Chat ID input field.
     */
    public function render_field_chat_id(): void
    {
        $value = esc_attr((string) get_option(self::OPTION_CHAT_ID, ''));
        printf(
            '<input type="text" id="ton_chat_id" name="%s" value="%s" class="regular-text" placeholder="-1001234567890" />',
            esc_attr(self::OPTION_CHAT_ID),
            $value // already escaped above
        );
        echo '<p class="description">' . esc_html__(
            'Your personal Telegram chat ID or a group/channel ID. Start a conversation with your bot and call the getUpdates API method to find the ID.',
            'telegram-order-notify'
        ) . '</p>';
    }

    /**
     * Handles the test-notification POST via admin-post.php.
     *
     * Validates the nonce and capability, sends a test message, stores
     * the result in a short-lived transient, then redirects back (PRG).
     */
    public function handle_test_notification(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to perform this action.', 'telegram-order-notify'),
                403
            );
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $result = $this->client->send_message(
            '<b>' . __('Test notification', 'telegram-order-notify') . "</b>\n"
            . __('Telegram Order Notifications plugin is configured correctly.', 'telegram-order-notify')
        );

        $user_id       = get_current_user_id();
        $transient_key = self::TRANSIENT_PREFIX . $user_id;

        if (is_wp_error($result)) {
            set_transient($transient_key, [ 'error' => $result->get_error_message() ], MINUTE_IN_SECONDS);
        } else {
            set_transient($transient_key, [ 'success' => true ], MINUTE_IN_SECONDS);
        }

        wp_safe_redirect(
            add_query_arg([ 'page' => self::PAGE_SLUG ], admin_url('options-general.php'))
        );
        exit;
    }

    /**
     * Renders the full settings page HTML.
     */
    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $user_id       = get_current_user_id();
        $transient_key = self::TRANSIENT_PREFIX . $user_id;
        $test_result   = get_transient($transient_key);

        if (false !== $test_result) {
            delete_transient($transient_key);
        }
        ?>
<div class="wrap">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<?php if (is_array($test_result)) : ?>
	<?php if (isset($test_result['success'])) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php esc_html_e('Test message sent successfully.', 'telegram-order-notify'); ?>
		</p>
	</div>
	<?php elseif (isset($test_result['error'])) : ?>
	<div class="notice notice-error is-dismissible">
		<p><?php echo esc_html($test_result['error']); ?>
		</p>
	</div>
	<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php
                settings_fields(self::SETTINGS_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button(__('Save Settings', 'telegram-order-notify'));
        ?>
	</form>

	<hr />

	<h2><?php esc_html_e('Send a test notification', 'telegram-order-notify'); ?>
	</h2>
	<p><?php esc_html_e('Click the button below to verify that your bot token and chat ID are configured correctly.', 'telegram-order-notify'); ?>
	</p>

	<form method="post"
		action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action"
			value="<?php echo esc_attr(self::ADMIN_POST_ACTION); ?>" />
		<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
		<?php submit_button(__('Send test message', 'telegram-order-notify'), 'secondary', 'ton_test_submit', false); ?>
	</form>
</div>
<?php
    }
}
?>