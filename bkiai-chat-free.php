<?php
/**
 * Plugin Name: BKiAI Chat Free
 * Plugin URI: https://businesskiai.de/bki-ai-chat/
 * Description: Add an AI chat to your WordPress site with voice recording, chat border customisation, and a clear Free vs Pro feature overview.
 * Version: 3.0.0
 * Author: BusinessKiAI
 * Author URI: https://businesskiai.de/
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bkiai-chat-free
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BKIAI_CHAT_FREE_VERSION', '3.0.0');
define('BKIAI_CHAT_FREE_FILE', __FILE__);
define('BKIAI_CHAT_FREE_DIR', plugin_dir_path(__FILE__));
define('BKIAI_CHAT_FREE_URL', plugin_dir_url(__FILE__));

require_once BKIAI_CHAT_FREE_DIR . 'includes/class-bkiai-plan-manager.php';

class BKiAI_Chat_Plugin {
    const OPTION_KEY = 'bkiai_chat_settings';
    const SHORTCODE = 'bkiai_chat';
    const NONCE_ACTION = 'bkiai_chat_nonce_action';
    const ADMIN_NONCE_ACTION = 'bkiai_chat_save_settings';
    const MAX_HISTORY_MESSAGES = 8;
    const NOTICE_TRANSIENT_KEY = 'bkiai_chat_admin_notice';
    const LOG_TABLE_SUFFIX = 'bkiai_chat_logs';
    const LOG_PURGE_OPTION = 'bkiai_chat_log_last_purge';

    private $models = array(
        'gpt-4o-mini' => 'gpt-4o-mini',
        'gpt-4o' => 'gpt-4o',
        'gpt-4.1-mini' => 'gpt-4.1-mini',
        'gpt-4.1' => 'gpt-4.1',
        'gpt-5-mini' => 'gpt-5-mini',
        'gpt-5.1' => 'gpt-5.1',
        'gpt-5.3' => 'gpt-5.3',
        'gpt-5.4' => 'gpt-5.4',
    );

    public function __construct() {
        add_action('admin_init', array($this, 'register_privacy_policy_content'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_bkiai_chat_save_settings', array($this, 'handle_admin_save'));
        if (BKiAI_Plan_Manager::can_use_feature('chat_logs')) {
            add_action('admin_post_bkiai_chat_export_logs', array($this, 'handle_logs_export'));
        }
        add_action('init', array($this, 'maybe_setup_storage'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp_footer', array($this, 'render_global_popup'));
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('wp_ajax_bkiai_chat_send_message', array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_bkiai_chat_send_message', array($this, 'handle_chat_request'));
        add_action('wp_ajax_bkiai_chat_stream_message', array($this, 'handle_chat_stream_request'));
        add_action('wp_ajax_nopriv_bkiai_chat_stream_message', array($this, 'handle_chat_stream_request'));
        if (BKiAI_Plan_Manager::can_use_feature('voice_realtime')) {
            add_action('wp_ajax_bkiai_chat_realtime_offer', array($this, 'handle_realtime_offer'));
            add_action('wp_ajax_nopriv_bkiai_chat_realtime_offer', array($this, 'handle_realtime_offer'));
        }
    }

    public function load_textdomain() {
        return;
    }

    public function register_privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content  = '<p>' . esc_html__('BKiAI Chat Free sends chat content, prompts, and optional uploaded knowledge-file content to OpenAI in order to generate chatbot responses.', 'bkiai-chat-free') . '</p>';
        $content .= '<p>' . esc_html__('The free edition does not provide local chat-log storage in the WordPress backend. Site owners should still review their privacy policy and any agreements required for their OpenAI account.', 'bkiai-chat-free') . '</p>';

        wp_add_privacy_policy_content(
            esc_html__('BKiAI Chat Free', 'bkiai-chat-free'),
            wp_kses_post($content)
        );
    }

    public function add_admin_menu() {
        add_options_page(
            'BKiAI Chat',
            'BKiAI Chat',
            'manage_options',
            'bkiai-chat',
            array($this, 'render_settings_page')
        );

        if (BKiAI_Plan_Manager::can_use_feature('chat_logs')) {
            add_submenu_page(
                'options-general.php',
                'BKiAI Chat Logs',
                'BKiAI Chat Logs',
                'manage_options',
                'bkiai-chat-logs',
                array($this, 'render_logs_page')
            );
        }
    }

    public function register_assets() {
        wp_register_style(
            'bkiai-chat-style',
            BKIAI_CHAT_FREE_URL . 'assets/bkiai-chat.css',
            array(),
            BKIAI_CHAT_FREE_VERSION
        );

        wp_register_script(
            'bkiai-chat-script',
            BKIAI_CHAT_FREE_URL . 'assets/bkiai-chat.js',
            array(),
            BKIAI_CHAT_FREE_VERSION,
            true
        );

        if (!is_admin()) {
            $settings = $this->get_settings();
            if (
                !empty($settings['bots'][1]) &&
                $settings['bots'][1]['enabled'] === '1' &&
                isset($settings['bots'][1]['popup_enabled']) &&
                $settings['bots'][1]['popup_enabled'] === '1'
            ) {
                $this->enqueue_frontend_assets();
            }
        }
    }

    private function enqueue_frontend_assets() {
        $settings = $this->get_settings();

        wp_enqueue_style('bkiai-chat-style');
        wp_enqueue_script('bkiai-chat-script');

        wp_localize_script('bkiai-chat-script', 'bkiaiChatConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'errorMessage' => 'The response could not be loaded right now. Please try again in a moment.',
            'sendingLabel' => 'The AI is thinking',
            'historyLimit' => self::MAX_HISTORY_MESSAGES,
            'clearLabel' => isset($settings['design']['clear_button_text']) && trim((string) $settings['design']['clear_button_text']) !== '' ? sanitize_text_field($settings['design']['clear_button_text']) : 'Clear chat',
            'sendOnEnter' => true,
            'streamDisplayMode' => 'word',
            'streamWordDelay' => 32,
            'voiceNotSupported' => 'Voice input is not supported in this browser.',
            'voiceOutputNotSupported' => 'Speech output is not supported in this browser.',
            'voiceConversationStartLabel' => 'Start live voice conversation',
            'voiceConversationStopLabel' => 'Stop live voice conversation',
            'voiceListeningLabel' => 'Listening…',
            'voiceProcessingLabel' => 'Processing…',
            'voiceSpeakingLabel' => 'Speaking…',
            'voiceResumeLabel' => 'Listening again…',
            'copyLabel' => 'Copy',
            'copiedLabel' => 'Copied',
            'copyErrorLabel' => 'Copy failed',
            'showSources' => isset($settings['design']['show_sources']) ? ($settings['design']['show_sources'] === '1') : true,
            'loadingAriaLabel' => 'Response is loading',
            'popupOpenLabel' => 'Open chat',
            'popupCloseLabel' => 'Close chat',
            'fullscreenOpenLabel' => 'Expand chat',
            'fullscreenCloseLabel' => 'Shrink chat',
            'fullscreenOpenShortLabel' => '',
            'fullscreenCloseShortLabel' => '',
            'downloadImageLabel' => 'Download image',
            'generatedImageLabel' => 'Generated image',
            'downloadPdfLabel' => 'Download PDF',
            'generatedPdfLabel' => 'Generated PDF',
            'streamAction' => 'bkiai_chat_stream_message',
            'realtimeAction' => 'bkiai_chat_realtime_offer',
            'stopLabel' => 'Stop',
            'realtimeVoiceNotSupported' => 'Realtime voice is not supported in this browser.',
            'realtimeVoiceConnectingLabel' => 'Connecting voice…',
            'realtimeVoiceActiveLabel' => 'Live voice conversation is active.',
            'realtimeVoiceStartLabel' => 'Start live voice conversation',
            'realtimeVoiceStopLabel' => 'Stop live voice conversation',
            'realtimeVoiceErrorLabel' => 'The live voice session could not be started right now. Please try again.',
            'realtimeVoiceGermanHint' => 'Live voice is configured for fluent Standard German.',
        ));
    }

    private function get_default_bot($index) {
        return array(
            'enabled' => $index === 1 ? '1' : '0',
            'title' => 'BKiAI Chat ' . $index,
            'model' => 'gpt-4o-mini',
            'welcome_message' => 'Hello! How can I help?',
            'system_prompt' => 'You are a helpful assistant on a WordPress website.',
            'use_website_content' => '0',
            'use_web_search' => '0',
            'daily_message_limit' => '25',
            'daily_token_limit' => '12000',
            'popup_enabled' => '0',
            'popup_position' => 'bottom-right',
            'popup_page_scope' => 'all',
            'popup_selected_page_ids' => array(),
            'image_generation_enabled' => '0',
            'pdf_generation_enabled' => '0',
            'website_scope' => 'all',
            'selected_content_ids' => array(),
            'knowledge_files' => array(),
        );
    }

    private function get_default_design() {
        return array(
            'width' => '100%',
            'height' => '420px',
            'background_color' => '#ffffff',
            'background_fill_type' => 'solid',
            'background_fill_preset' => 'soft',
            'background_fill_angle' => '135',
            'header_color' => '#ffffff',
            'header_fill_type' => 'solid',
            'header_fill_preset' => 'soft',
            'header_fill_angle' => '135',
            'title_text_color' => '#6b7280',
            'border_width' => '1',
            'border_color' => '#e5e7eb',
            'footer_color' => '#ffffff',
            'footer_fill_type' => 'solid',
            'footer_fill_preset' => 'soft',
            'footer_fill_angle' => '135',
            'button_color' => '#2563eb',
            'button_fill_type' => 'solid',
            'button_fill_preset' => 'deep',
            'button_fill_angle' => '135',
            'expand_button_color' => '#ffffff',
            'expand_button_fill_type' => 'solid',
            'expand_button_fill_preset' => 'soft',
            'expand_button_fill_angle' => '135',
            'reset_text_color' => '#dc2626',
            'chat_radius' => '18',
            'input_radius' => '22',
            'logo_url' => '',
            'chat_history_background_image_url' => '',
            'show_sources' => '1',
            'box_shadow_enabled' => '1',
            'voice_enabled' => '0',
            'voice_realtime_enabled' => '0',
            'voice_reply_gender' => 'female',
            'send_button_text' => 'Send',
            'clear_button_text' => 'Clear chat',
            'input_placeholder_text' => 'Ask any question',
        );
    }

    private function get_default_license() {
        return array(
            'key' => '',
            'status' => 'not_connected',
            'plan' => 'free',
            'instance_url' => home_url('/'),
            'last_checked' => '',
        );
    }

    private function get_settings() {
        $defaults = array(
            'api_key' => '',
            'privacy' => array(
                'log_retention_days' => 30,
            ),
            'design' => $this->get_default_design(),
            'license' => $this->get_default_license(),
            'bot_count' => 5,
            'bots' => array(
                1 => $this->get_default_bot(1),
                2 => $this->get_default_bot(2),
                3 => $this->get_default_bot(3),
                4 => $this->get_default_bot(4),
                5 => $this->get_default_bot(5),
            ),
        );

        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }

        $settings = wp_parse_args($saved, $defaults);
        $settings['privacy'] = wp_parse_args(isset($settings['privacy']) && is_array($settings['privacy']) ? $settings['privacy'] : array(), $defaults['privacy']);
        $settings['design'] = wp_parse_args(isset($settings['design']) && is_array($settings['design']) ? $settings['design'] : array(), $this->get_default_design());
        $settings['license'] = wp_parse_args(isset($settings['license']) && is_array($settings['license']) ? $settings['license'] : array(), $this->get_default_license());

        $savedBotKeys = isset($settings['bots']) && is_array($settings['bots']) ? array_map('intval', array_keys($settings['bots'])) : array();
        $savedBotMax = !empty($savedBotKeys) ? max($savedBotKeys) : 5;
        $botCount = max(5, min(20, intval(isset($settings['bot_count']) ? $settings['bot_count'] : $savedBotMax)));
        $botCount = max($botCount, $savedBotMax);
        $settings['bot_count'] = $botCount;

        for ($i = 1; $i <= $botCount; $i++) {
            $settings['bots'][$i] = wp_parse_args(isset($settings['bots'][$i]) ? $settings['bots'][$i] : array(), $this->get_default_bot($i));
            if (!is_array($settings['bots'][$i]['knowledge_files'])) {
                $settings['bots'][$i]['knowledge_files'] = array();
            }
            if (!is_array($settings['bots'][$i]['selected_content_ids'])) {
                $settings['bots'][$i]['selected_content_ids'] = array();
            }
        }

        return $settings;
    }

    private function get_bot_count($settings = null) {
        if (!is_array($settings)) {
            $settings = $this->get_settings();
        }

        $botCount = isset($settings['bot_count']) ? intval($settings['bot_count']) : 5;
        $botKeys = isset($settings['bots']) && is_array($settings['bots']) ? array_map('intval', array_keys($settings['bots'])) : array();
        if (!empty($botKeys)) {
            $botCount = max($botCount, max($botKeys));
        }

        return max(5, min(20, $botCount));
    }

    private function update_settings($settings) {
        update_option(self::OPTION_KEY, $settings, false);
    }

    private function sanitize_dimension($value, $default) {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        if (preg_match('/^\d+(\.\d+)?(px|%|vh|vw|rem|em)$/', $value)) {
            return $value;
        }
        if (preg_match('/^\d+$/', $value)) {
            return $value . 'px';
        }
        return $default;
    }

    private function sanitize_radius_px($value, $default) {
        $value = trim((string) $value);
        if ($value === '') {
            return (string) intval($default);
        }
        $value = preg_replace('/[^0-9]/', '', $value);
        if ($value === '') {
            return (string) intval($default);
        }
        $value = max(0, min(80, intval($value)));
        return (string) $value;
    }

    private function sanitize_border_width($value, $default) {
        $value = trim((string) $value);
        if ($value === '') {
            return (string) max(0, intval($default));
        }
        $value = preg_replace('/[^0-9]/', '', $value);
        if ($value === '') {
            return (string) max(0, intval($default));
        }
        return (string) max(0, intval($value));
    }

    private function sanitize_color($value, $default) {
        $value = trim((string) $value);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return strtolower($value);
        }
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $value)) {
            return strtolower($value);
        }
        return $default;
    }

    private function get_fill_modes() {
        return array(
            'solid' => 'Solid',
            'gradient' => 'Gradient',
            'pattern' => 'Pattern',
        );
    }

    private function get_fill_presets() {
        return array(
            'soft' => 'Soft',
            'shine' => 'Light',
            'deep' => 'Intense',
            'split' => 'Multi-step',
            'stripes' => 'Diagonal stripes',
            'dots' => 'Dots',
            'grid' => 'Grid',
            'mesh' => 'Cross lines',
        );
    }


private function get_design_presets() {
    return array(
        'bkiai_light' => array(
            'label' => 'BusinessKiAI Light',
            'values' => array(
                'background_color' => '#ffffff',
                'background_fill_type' => 'solid',
                'background_fill_preset' => 'soft',
                'background_fill_angle' => '135',
                'header_color' => '#eaf2ff',
                'header_fill_type' => 'gradient',
                'header_fill_preset' => 'soft',
                'header_fill_angle' => '135',
                'button_color' => '#1d4ed8',
                'button_fill_type' => 'gradient',
                'button_fill_preset' => 'deep',
                'button_fill_angle' => '135',
                'footer_color' => '#ffffff',
                'footer_fill_type' => 'solid',
                'footer_fill_preset' => 'soft',
                'footer_fill_angle' => '135',
                'expand_button_color' => '#f8fafc',
                'expand_button_fill_type' => 'gradient',
                'expand_button_fill_preset' => 'soft',
                'expand_button_fill_angle' => '135',
                'box_shadow_enabled' => '1',
            ),
        ),
        'bkiai_dark' => array(
            'label' => 'BusinessKiAI Dark',
            'values' => array(
                'background_color' => '#e5e7eb',
                'background_fill_type' => 'gradient',
                'background_fill_preset' => 'deep',
                'background_fill_angle' => '135',
                'header_color' => '#cbd5e1',
                'header_fill_type' => 'gradient',
                'header_fill_preset' => 'split',
                'header_fill_angle' => '135',
                'button_color' => '#0f172a',
                'button_fill_type' => 'gradient',
                'button_fill_preset' => 'deep',
                'button_fill_angle' => '135',
                'footer_color' => '#dbe4ef',
                'footer_fill_type' => 'gradient',
                'footer_fill_preset' => 'soft',
                'footer_fill_angle' => '135',
                'expand_button_color' => '#cbd5e1',
                'expand_button_fill_type' => 'gradient',
                'expand_button_fill_preset' => 'shine',
                'expand_button_fill_angle' => '135',
                'box_shadow_enabled' => '1',
            ),
        ),
        'chatgpt_like' => array(
            'label' => 'ChatGPT Style',
            'values' => array(
                'background_color' => '#ffffff',
                'background_fill_type' => 'solid',
                'background_fill_preset' => 'soft',
                'background_fill_angle' => '135',
                'header_color' => '#f7f7f8',
                'header_fill_type' => 'solid',
                'header_fill_preset' => 'soft',
                'header_fill_angle' => '135',
                'button_color' => '#111827',
                'button_fill_type' => 'solid',
                'button_fill_preset' => 'deep',
                'button_fill_angle' => '135',
                'footer_color' => '#ffffff',
                'footer_fill_type' => 'solid',
                'footer_fill_preset' => 'soft',
                'footer_fill_angle' => '135',
                'expand_button_color' => '#ffffff',
                'expand_button_fill_type' => 'solid',
                'expand_button_fill_preset' => 'soft',
                'expand_button_fill_angle' => '135',
                'box_shadow_enabled' => '1',
            ),
        ),
        'modern_blue_grey' => array(
            'label' => 'Modern Blue-Gray',
            'values' => array(
                'background_color' => '#f3f7fb',
                'background_fill_type' => 'gradient',
                'background_fill_preset' => 'shine',
                'background_fill_angle' => '135',
                'header_color' => '#dbeafe',
                'header_fill_type' => 'gradient',
                'header_fill_preset' => 'split',
                'header_fill_angle' => '135',
                'button_color' => '#334155',
                'button_fill_type' => 'gradient',
                'button_fill_preset' => 'deep',
                'button_fill_angle' => '135',
                'footer_color' => '#eef2ff',
                'footer_fill_type' => 'gradient',
                'footer_fill_preset' => 'soft',
                'footer_fill_angle' => '135',
                'expand_button_color' => '#e2e8f0',
                'expand_button_fill_type' => 'gradient',
                'expand_button_fill_preset' => 'shine',
                'expand_button_fill_angle' => '135',
                'box_shadow_enabled' => '1',
            ),
        ),
    );
}

    private function sanitize_fill_type($value, $default = 'solid') {
        $allowed = array_keys($this->get_fill_modes());
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function sanitize_fill_preset($value, $default = 'soft') {
        $allowed = array_keys($this->get_fill_presets());
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function sanitize_fill_angle($value, $default = '135') {
        $angle = intval($value);
        if ($angle < 0 || $angle > 360) {
            return $default;
        }
        return (string) $angle;
    }

    private function hex_to_rgba($hexColor, $alpha = 1) {
        $hexColor = ltrim((string) $hexColor, '#');
        if (strlen($hexColor) === 3) {
            $hexColor = $hexColor[0] . $hexColor[0] . $hexColor[1] . $hexColor[1] . $hexColor[2] . $hexColor[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hexColor)) {
            return 'rgba(255,255,255,' . max(0, min(1, floatval($alpha))) . ')';
        }
        $r = hexdec(substr($hexColor, 0, 2));
        $g = hexdec(substr($hexColor, 2, 2));
        $b = hexdec(substr($hexColor, 4, 2));
        return sprintf('rgba(%d,%d,%d,%.3F)', $r, $g, $b, max(0, min(1, floatval($alpha))));
    }

    private function build_fill_css($baseColor, $type, $preset, $angle) {
        $primary = $this->sanitize_color($baseColor, '#ffffff');
        $type = $this->sanitize_fill_type($type, 'solid');
        $preset = $this->sanitize_fill_preset($preset, 'soft');
        $angle = intval($this->sanitize_fill_angle($angle, '135'));

        if ($type === 'solid') {
            return $primary;
        }

        $light = $this->adjust_color_brightness($primary, 52);
        $mid = $this->adjust_color_brightness($primary, 22);
        $dark = $this->adjust_color_brightness($primary, -24);
        $deeper = $this->adjust_color_brightness($primary, -44);
        $overlayStrong = $this->hex_to_rgba('#ffffff', 0.24);
        $overlaySoft = $this->hex_to_rgba('#ffffff', 0.10);
        $overlayGrid = $this->hex_to_rgba('#ffffff', 0.18);

        if ($type === 'gradient') {
            switch ($preset) {
                case 'shine':
                    return sprintf('linear-gradient(%1$ddeg, %2$s 0%%, %3$s 38%%, %4$s 100%%)', $angle, $light, $mid, $dark);
                case 'deep':
                    return sprintf('linear-gradient(%1$ddeg, %2$s 0%%, %3$s 100%%)', $angle, $mid, $deeper);
                case 'split':
                    return sprintf('linear-gradient(%1$ddeg, %2$s 0%%, %3$s 52%%, %4$s 100%%)', $angle, $light, $primary, $dark);
                case 'soft':
                default:
                    return sprintf('linear-gradient(%1$ddeg, %2$s 0%%, %3$s 100%%)', $angle, $light, $primary);
            }
        }

        switch ($preset) {
            case 'dots':
                return sprintf('radial-gradient(circle, %1$s 0 2px, transparent 2.4px) 0 0 / 18px 18px repeat, linear-gradient(%2$ddeg, %3$s 0%%, %4$s 100%%)', $overlayStrong, $angle, $light, $primary);
            case 'grid':
                return sprintf('linear-gradient(%1$s 1px, transparent 1px) 0 0 / 18px 18px repeat, linear-gradient(90deg, %1$s 1px, transparent 1px) 0 0 / 18px 18px repeat, linear-gradient(%2$ddeg, %3$s 0%%, %4$s 100%%)', $overlayGrid, $angle, $light, $primary);
            case 'mesh':
                return sprintf('repeating-linear-gradient(%1$ddeg, %2$s 0 2px, transparent 2px 14px), repeating-linear-gradient(%3$ddeg, %4$s 0 2px, transparent 2px 14px), linear-gradient(%1$ddeg, %5$s 0%%, %6$s 100%%)', $angle, $overlayStrong, ($angle + 90) % 360, $overlaySoft, $mid, $dark);
            case 'stripes':
            default:
                return sprintf('repeating-linear-gradient(%1$ddeg, %2$s 0 10px, %3$s 10px 20px), linear-gradient(%1$ddeg, %4$s 0%%, %5$s 100%%)', $angle, $overlayStrong, $overlaySoft, $light, $primary);
        }
    }

    private function render_fill_setting_row($area, $label, $inputId, $design, $description = '') {
        $colorKey = $area . '_color';
        $typeKey = $area . '_fill_type';
        $presetKey = $area . '_fill_preset';
        $angleKey = $area . '_fill_angle';

        $color = isset($design[$colorKey]) ? $design[$colorKey] : '#ffffff';
        $type = isset($design[$typeKey]) ? $design[$typeKey] : 'solid';
        $preset = isset($design[$presetKey]) ? $design[$presetKey] : 'soft';
        $angle = isset($design[$angleKey]) ? $design[$angleKey] : '135';
        $preview = $this->build_fill_css($color, $type, $preset, $angle);
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($inputId); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <div class="bkiai-color-field-group">
                    <input type="text" id="<?php echo esc_attr($inputId); ?>" name="design[<?php echo esc_attr($colorKey); ?>]" value="<?php echo esc_attr($color); ?>" class="regular-text bkiai-color-text" />
                    <input type="color" value="<?php echo esc_attr($color); ?>" class="bkiai-color-palette" data-target="<?php echo esc_attr($inputId); ?>" aria-label="Color palette for <?php echo esc_attr($label); ?>" />
                </div>
                <div class="bkiai-fill-options">
                    <label>Display
                        <select name="design[<?php echo esc_attr($typeKey); ?>]" class="bkiai-fill-type" data-scope="<?php echo esc_attr($area); ?>">
                            <?php foreach ($this->get_fill_modes() as $modeValue => $modeLabel) : ?>
                                <option value="<?php echo esc_attr($modeValue); ?>" <?php selected($type, $modeValue); ?>><?php echo esc_html($modeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Preset
                        <select name="design[<?php echo esc_attr($presetKey); ?>]" class="bkiai-fill-preset" data-scope="<?php echo esc_attr($area); ?>">
                            <optgroup label="Gradient">
                                <?php foreach (array('soft' => 'Soft', 'shine' => 'Light', 'deep' => 'Intense', 'split' => 'Multi-step') as $presetValue => $presetLabel) : ?>
                                    <option value="<?php echo esc_attr($presetValue); ?>" <?php selected($preset, $presetValue); ?>><?php echo esc_html($presetLabel); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Pattern">
                                <?php foreach (array('stripes' => 'Diagonal stripes', 'dots' => 'Dots', 'grid' => 'Grid', 'mesh' => 'Cross lines') as $presetValue => $presetLabel) : ?>
                                    <option value="<?php echo esc_attr($presetValue); ?>" <?php selected($preset, $presetValue); ?>><?php echo esc_html($presetLabel); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </label>
                    <label>Angle
                        <select name="design[<?php echo esc_attr($angleKey); ?>]" class="bkiai-fill-angle" data-scope="<?php echo esc_attr($area); ?>">
                            <?php foreach (array('0','45','90','135','180','225','270','315') as $angleOption) : ?>
                                <option value="<?php echo esc_attr($angleOption); ?>" <?php selected((string) $angle, (string) $angleOption); ?>><?php echo esc_html($angleOption); ?>°</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="bkiai-fill-preview" data-scope="<?php echo esc_attr($area); ?>" style="background: <?php echo esc_attr($preview); ?>;"></span>
                </div>
                <p class="description bkiai-color-help">You can enter the hex code or use the color palette directly. Gradient and pattern use this base color.</p>
                <?php if ($description !== '') : ?><p class="description bkiai-color-help"><?php echo esc_html($description); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }


    private function get_contrast_text_color($hexColor) {
        $hexColor = ltrim((string) $hexColor, '#');
        if (strlen($hexColor) === 3) {
            $hexColor = $hexColor[0] . $hexColor[0] . $hexColor[1] . $hexColor[1] . $hexColor[2] . $hexColor[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hexColor)) {
            return '#1f2937';
        }
        $r = hexdec(substr($hexColor, 0, 2));
        $g = hexdec(substr($hexColor, 2, 2));
        $b = hexdec(substr($hexColor, 4, 2));
        $luminance = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
        return $luminance >= 160 ? '#1f2937' : '#ffffff';
    }

    private function adjust_color_brightness($hexColor, $steps = -18) {
        $hexColor = ltrim((string) $hexColor, '#');
        if (strlen($hexColor) === 3) {
            $hexColor = $hexColor[0] . $hexColor[0] . $hexColor[1] . $hexColor[1] . $hexColor[2] . $hexColor[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hexColor)) {
            return '#cbd5e1';
        }
        $steps = max(-255, min(255, intval($steps)));
        $parts = str_split($hexColor, 2);
        $result = '#';
        foreach ($parts as $part) {
            $value = hexdec($part);
            $value = max(0, min(255, $value + $steps));
            $result .= str_pad(dechex($value), 2, '0', STR_PAD_LEFT);
        }
        return strtolower($result);
    }

    private function set_admin_notice($message, $type = 'success') {
        set_transient(self::NOTICE_TRANSIENT_KEY, array(
            'message' => (string) $message,
            'type' => $type === 'error' ? 'error' : 'success',
        ), 120);
    }

    private function get_upload_base_dir() {
        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir']) . 'bkiai-chat';
        $baseUrl = trailingslashit($uploads['baseurl']) . 'bkiai-chat';
        if (!file_exists($baseDir)) {
            wp_mkdir_p($baseDir);
        }
        return array($baseDir, $baseUrl);
    }

    private function format_bytes($bytes) {
        $bytes = max(0, intval($bytes));
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }


public function maybe_setup_storage() {
    if (!BKiAI_Plan_Manager::can_use_feature('chat_logs')) {
        return;
    }

    $this->maybe_create_logs_table();
    $this->maybe_cleanup_logs();
}

private function get_logs_table_name() {
    global $wpdb;
    return $wpdb->prefix . self::LOG_TABLE_SUFFIX;
}

private function maybe_create_logs_table() {
    global $wpdb;

    $table = $this->get_logs_table_name();
    $charsetCollate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        bot_id tinyint(3) unsigned NOT NULL DEFAULT 1,
        visitor_key varchar(64) NOT NULL DEFAULT '',
        page_url text NULL,
        page_title text NULL,
        user_message longtext NULL,
        assistant_message longtext NULL,
        token_estimate int(11) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY bot_id (bot_id)
    ) {$charsetCollate};";

    dbDelta($sql);
}

private function maybe_cleanup_logs() {
    return;
}

private function log_chat_interaction($botIndex, $userMessage, $assistantMessage, $pageUrl = '', $pageTitle = '') {
    return;
}


private function get_selectable_content_options() {
    $items = get_posts(array(
        'post_type' => array('page', 'post'),
        'post_status' => 'publish',
        'numberposts' => 200,
        'orderby' => 'title',
        'order' => 'ASC',
    ));

    $options = array();
    foreach ($items as $item) {
        $options[] = array(
            'id' => intval($item->ID),
            'label' => get_the_title($item) . ' (' . get_post_type($item) . ')',
            'url' => get_permalink($item),
        );
    }
    return $options;
}

private function maybe_handle_logs_actions() {
    return;
}

private function normalize_match_value($value) {
    $value = remove_accents(wp_strip_all_tags((string) $value));
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return trim((string) $value);
}

private function should_hide_popup_on_current_page() {
    if (is_admin() || wp_doing_ajax()) {
        return false;
    }

    $blockedTerms = array(
        'agb',
        'impressum',
        'datenschutz',
        'datenschutzerklarung',
        'barrierefreiheit',
        'barrierefreiheitserklarung',
    );

    $candidates = array();
    $requestUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    if ($requestUri !== '') {
        $candidates[] = $requestUri;
    }

    if (is_singular()) {
        $post = get_queried_object();
        if ($post && !empty($post->post_title)) {
            $candidates[] = $post->post_title;
        }
        if ($post && !empty($post->post_name)) {
            $candidates[] = $post->post_name;
        }
    }

    foreach ($candidates as $candidate) {
        $normalized = $this->normalize_match_value($candidate);
        foreach ($blockedTerms as $term) {
            if ($normalized !== '' && strpos($normalized, $term) !== false) {
                return true;
            }
        }
    }

    return false;
}


private function should_render_popup_on_current_page($bot) {
    if ($this->should_hide_popup_on_current_page()) {
        return false;
    }

    $scope = isset($bot['popup_page_scope']) ? (string) $bot['popup_page_scope'] : 'all';
    if ($scope !== 'selected') {
        return true;
    }

    if (!is_singular()) {
        return false;
    }

    $selectedIds = isset($bot['popup_selected_page_ids']) && is_array($bot['popup_selected_page_ids'])
        ? array_values(array_filter(array_map('intval', $bot['popup_selected_page_ids'])))
        : array();

    if (empty($selectedIds)) {
        return false;
    }

    $queried = get_queried_object_id();
    return $queried > 0 && in_array(intval($queried), $selectedIds, true);
}


private function build_logs_export_url($selectedDays, $selectedBot, $search) {
    return wp_nonce_url(
        add_query_arg(
            array(
                'action' => 'bkiai_chat_export_logs',
                'days'   => max(1, intval($selectedDays)),
                'bot'    => max(0, intval($selectedBot)),
                's'      => (string) $search,
            ),
            admin_url('admin-post.php')
        ),
        'bkiai_export_logs'
    );
}

public function handle_logs_export() {
    wp_die(esc_html__('Chat log export is not available in the free edition.', 'bkiai-chat-free'));
}

public function render_logs_page() {
    wp_die(esc_html__('Chat logs are not available in the free edition.', 'bkiai-chat-free'));
}

public function render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = $this->get_settings();
    $botCount = $this->get_bot_count($settings);
    $saved = $this->get_get_text('settings-updated');
    $adminNotice = get_transient(self::NOTICE_TRANSIENT_KEY);
    if ($adminNotice) {
        delete_transient(self::NOTICE_TRANSIENT_KEY);
    }
    $contentOptions = $this->get_selectable_content_options();

$currentPlan = BKiAI_Plan_Manager::get_current_plan();
$planConfig = BKiAI_Plan_Manager::get_plan_config($currentPlan);
$maxActiveBots = BKiAI_Plan_Manager::get_max_active_bots();

$bot1Accessible = BKiAI_Plan_Manager::is_bot_accessible(1);
$bot2Accessible = BKiAI_Plan_Manager::is_bot_accessible(2);
$bot3Accessible = BKiAI_Plan_Manager::is_bot_accessible(3);
$bot4Accessible = BKiAI_Plan_Manager::is_bot_accessible(4);
$bot5Accessible = BKiAI_Plan_Manager::is_bot_accessible(5);

$canDuplicateBots = !empty($planConfig['can_duplicate_bots']);
$canDeleteDynamicBots = !empty($planConfig['can_delete_dynamic_bots']);
$canUseChatLogs = !empty($planConfig['chat_logs']);
$canUseVoice = !empty($planConfig['voice']);
$canUseRealtimeVoice = !empty($planConfig['voice_realtime']);
$canUseImageGeneration = !empty($planConfig['image_generation']);
$canUsePdfGeneration = !empty($planConfig['pdf_generation']);
$canUseWebSearch = !empty($planConfig['web_search']);
$canUseWebsiteKnowledge = !empty($planConfig['website_knowledge']);
$maxKnowledgeFiles = intval($planConfig['max_knowledge_files']);
$licenseSettings = isset($settings['license']) && is_array($settings['license']) ? wp_parse_args($settings['license'], $this->get_default_license()) : $this->get_default_license();
$licenseStatus = isset($licenseSettings['status']) ? (string) $licenseSettings['status'] : 'not_connected';
$licenseStatusLabels = array(
    'not_connected' => 'Not connected yet',
    'stored_locally' => 'Key stored locally',
    'activated_placeholder' => 'Activation saved locally (EDD connection comes later)',
    'deactivated' => 'Deactivated locally',
    'invalid_placeholder' => 'No license key entered',
);
$licenseStatusLabel = isset($licenseStatusLabels[$licenseStatus]) ? $licenseStatusLabels[$licenseStatus] : 'Unknown';
$isFreeBuild = BKiAI_Plan_Manager::is_free_build();
$isPremiumBuild = BKiAI_Plan_Manager::is_premium_build();
$licenseTabLabel = 'Compare / Upgrade';
$upgradeProUrl = BKiAI_Plan_Manager::get_upgrade_url('upgrade_tab', 'pro');
$upgradeExpertUrl = BKiAI_Plan_Manager::get_upgrade_url('upgrade_tab', 'expert');
$installedEditionLabel = 'Free';
$installedEditionDescription = 'You are currently using the Free edition of BKiAI Chat.';
$activePlanDescription = 'In the free build, the active plan is always Free.';
$installedEditionCard = 'free';
$showProUpgradeInCompare = true;
$showExpertUpgradeInCompare = true;

wp_enqueue_media();
?>
<div class="wrap">
        <h1>BKiAI Chat</h1>
        <p><strong>Shortcodes:</strong> <?php if ($isFreeBuild) : ?><code>[bkiai_chat bot="1"]</code><?php else : ?><code>[bkiai_chat bot="1"]</code>, <code>[bkiai_chat bot="2"]</code>, <code>[bkiai_chat bot="3"]</code>, <code>[bkiai_chat bot="4"]</code>, <code>[bkiai_chat bot="5"]</code> and <code>[bkiai_chat bot="X"]</code><?php endif; ?></p>
            <?php if ($saved === '1') : ?>
                <div class="notice is-dismissible bkiai-admin-notice bkiai-admin-notice-success"><p>Settings were saved successfully.</p></div>
            <?php endif; ?>
            <?php if (!empty($adminNotice['message'])) : ?>
                <div class="notice is-dismissible bkiai-admin-notice bkiai-admin-notice-<?php echo esc_attr($adminNotice['type']); ?>"><p><?php echo esc_html($adminNotice['message']); ?></p></div>
            <?php endif; ?>
            <style>
                .bkiai-admin-notice { border-left-width: 6px; border-radius: 8px; padding: 10px 14px; margin: 14px 0 18px; }
                .bkiai-admin-notice p { margin: 0.2em 0; font-weight: 600; }
                .bkiai-admin-notice-success { background: #ecfdf3; border-left-color: #16a34a; color: #166534; }
                .bkiai-admin-notice-error { background: #fef2f2; border-left-color: #dc2626; color: #991b1b; }
                .bkiai-admin-notice-warning { background: #fff7ed; border-left-color: #ea580c; color: #9a3412; }
                .bkiai-admin-notice-info { background: #eff6ff; border-left-color: #2563eb; color: #1d4ed8; }
                .bkiai-color-field-group { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
                .bkiai-color-palette { width:52px; height:38px; padding:2px; border:1px solid #d0d5dd; border-radius:8px; background:#fff; cursor:pointer; }
                .bkiai-color-help { margin-top:6px; color:#646970; }
                .bkiai-fill-options { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-top:10px; }
                .bkiai-fill-options label { display:flex; flex-direction:column; gap:4px; font-weight:600; }
                .bkiai-fill-options select { min-width:140px; }
                .bkiai-fill-preview { width:72px; height:38px; border-radius:10px; border:1px solid #d0d5dd; display:inline-block; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.25); }
                .bkiai-logo-field-group { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
                .bkiai-logo-preview { max-height:42px; width:auto; display:block; border:1px solid #d0d5dd; border-radius:8px; background:#fff; padding:4px; }
                .bkiai-logo-preview.is-hidden { display:none; }
                .bkiai-design-preset-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
                .bkiai-design-preset-row select { min-width:240px; }

                .bkiai-systemprompt-meta { margin-top:8px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
                .bkiai-systemprompt-hint { margin:0; color:#50575e; }
                .bkiai-systemprompt-count { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#eef2ff; border:1px solid #c7d2fe; color:#3730a3; font-weight:600; }
                .bkiai-systemprompt-count.is-warning { background:#fff7ed; border-color:#fdba74; color:#9a3412; }
                .bkiai-systemprompt-count.is-danger { background:#fef2f2; border-color:#fca5a5; color:#b91c1c; }
                .bkiai-knowledge-help { margin-top:10px; max-width:980px; }
                .bkiai-knowledge-help summary { cursor:pointer; font-weight:600; color:#1d4ed8; }
                .bkiai-knowledge-help summary:hover { color:#1e40af; }
                .bkiai-knowledge-help-box { margin-top:10px; padding:14px; border:1px solid #dcdcde; border-radius:12px; background:#fff; }
                .bkiai-knowledge-help-box table { width:100%; border-collapse:collapse; margin:0 0 14px; }
                .bkiai-knowledge-help-box th, .bkiai-knowledge-help-box td { border:1px solid #e5e7eb; padding:8px 10px; text-align:left; vertical-align:top; }
                .bkiai-knowledge-help-box thead th { background:#f8fafc; font-weight:700; }
                .bkiai-knowledge-help-box h4 { margin:14px 0 8px; }
                .bkiai-knowledge-help-box p { margin:8px 0; }
                .bkiai-knowledge-help-box ul { margin:8px 0 8px 18px; }

                .bkiai-admin-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:18px 0 22px; }
                .bkiai-admin-tab-button,
                .bkiai-admin-tab-link { border:1px solid #d0d5dd; background:#fff; color:#1f2937; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:600; }
                .bkiai-admin-tab-button.is-active { background:#2563eb; color:#fff; border-color:#2563eb; }
                .bkiai-upsell-card { max-width:920px; padding:16px 18px; border:1px solid #d0d5dd; border-radius:12px; background:#f8fafc; }
                .bkiai-upsell-card h3 { margin:0 0 8px; font-size:15px; }
                .bkiai-upsell-card p { margin:0 0 10px; }
                .bkiai-upsell-card ul { margin:8px 0 12px 18px; }
                .bkiai-plan-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; max-width:1100px; }
                .bkiai-plan-card { border:1px solid #d0d5dd; border-radius:14px; background:#fff; padding:18px; box-shadow:0 1px 2px rgba(16,24,40,0.05); }
                .bkiai-plan-card h3 { margin:0 0 6px; font-size:18px; }
                .bkiai-plan-badge { display:inline-block; padding:4px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-weight:600; margin-bottom:10px; }
                .bkiai-plan-current-marker { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#ecfdf3; color:#166534; border:1px solid #86efac; font-weight:700; margin:2px 0 12px; }
                .bkiai-plan-status-row { display:flex; gap:10px; flex-wrap:wrap; margin:10px 0 14px; }
                .bkiai-plan-status-pill { display:inline-flex; align-items:center; padding:4px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-weight:600; }
                .bkiai-plan-status-pill.is-neutral { background:#f8fafc; color:#334155; border:1px solid #d0d5dd; }
                .bkiai-plan-card .description { margin-top:0; }
                .bkiai-plan-card ul { margin:10px 0 14px 18px; }
                .bkiai-feature-list { margin-top:18px; max-width:1100px; border-collapse:collapse; }
                .bkiai-feature-list th, .bkiai-feature-list td { border:1px solid #d0d5dd; padding:10px 12px; text-align:left; vertical-align:top; }
                .bkiai-feature-list th { background:#f8fafc; }
                .bkiai-admin-tab-link { display:inline-flex; align-items:center; text-decoration:none; }
                .bkiai-admin-tab-link:hover { border-color:#93c5fd; color:#1d4ed8; }
                .bkiai-admin-tab-link.is-locked,
                .bkiai-admin-tab-button.is-locked { background:#fff7ed; border-color:#fdba74; color:#9a3412; }
                .bkiai-tab-plan-badge { display:inline-flex; align-items:center; justify-content:center; margin-left:8px; padding:2px 8px; border-radius:999px; border:1px solid #93c5fd; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; line-height:1.3; text-transform:uppercase; }
                .bkiai-tab-plan-badge.is-expert { border-color:#c4b5fd; background:#f5f3ff; color:#6d28d9; }
                .bkiai-locked-panel-copy { max-width:920px; }
                .bkiai-admin-tab-panel { display:none; }
                .bkiai-admin-tab-panel.is-active { display:block; }
                .bkiai-upgrade-link { color:#1d4ed8; font-weight:600; text-decoration:none; }
                .bkiai-upgrade-link:hover { color:#1e40af; text-decoration:underline; }
                .bkiai-upgrade-button-inline { display:inline-flex; align-items:center; margin-top:8px; }
            </style>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bkiai_chat_save_settings" />
                <input type="hidden" id="bkiai_active_tab" name="active_tab" value="<?php echo esc_attr($this->get_get_key('active_tab', 'general')); ?>" />
                <?php wp_nonce_field(self::ADMIN_NONCE_ACTION, 'bkiai_chat_admin_nonce'); ?>

                <?php
$botAccessibleMap = array(
    1 => $bot1Accessible,
    2 => $bot2Accessible,
    3 => $bot3Accessible,
    4 => $bot4Accessible,
    5 => $bot5Accessible,
);

$displayBotTabs = $isFreeBuild ? 5 : max(5, (int) $botCount);
$adminBotCount = $isFreeBuild ? 5 : $botCount;
?>
<div class="bkiai-admin-tabs" role="tablist" aria-label="BKiAI Chat Settings">
    <button type="button" class="bkiai-admin-tab-button is-active" data-tab-target="general">General</button>
    <button type="button" class="bkiai-admin-tab-button" data-tab-target="license"><?php echo esc_html($licenseTabLabel); ?></button>

    <?php for ($i = 1; $i <= $displayBotTabs; $i++) : ?>
        <?php
        $isAccessible = isset($botAccessibleMap[$i])
            ? $botAccessibleMap[$i]
            : ($currentPlan === BKiAI_Plan_Manager::PLAN_EXPERT);
        $buttonClasses = 'bkiai-admin-tab-button';
        $planBadgeLabel = '';
        $planBadgeClass = '';
        if (!$isAccessible) {
            $buttonClasses .= ' is-locked';
            if ($i === 2) {
                $planBadgeLabel = 'Pro';
            } else {
                $planBadgeLabel = 'Expert';
                $planBadgeClass = ' is-expert';
            }
        }
        ?>
            <button
                type="button"
                class="<?php echo esc_attr($buttonClasses); ?>"
                data-tab-target="bot-<?php echo esc_attr((string) $i); ?>"
                <?php
                $tabTitleAttribute = '';
                if (!$isAccessible) {
                    /* translators: %s: plan label such as Pro or Expert. */
                    $availableInLabel = sprintf(__('Available in %s', 'bkiai-chat-free'), $planBadgeLabel);
                    $tabTitleAttribute = sprintf(
                        'title="%s"',
                        esc_attr($availableInLabel)
                    );
                }
                echo $tabTitleAttribute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            >
                Bot <?php echo esc_html((string) $i); ?>
                <?php if (!$isAccessible) : ?><span class="bkiai-tab-plan-badge<?php echo esc_attr($planBadgeClass); ?>"><?php echo esc_html($planBadgeLabel); ?></span><?php endif; ?>
            </button>
    <?php endfor; ?>

    <?php if ($isFreeBuild) : ?>
        <button
            type="button"
            class="bkiai-admin-tab-button bkiai-admin-tab-button-add is-locked"
            data-tab-target="bot-add"
            title="Additional duplicated bots are available in Expert."
        >+<span class="bkiai-tab-plan-badge is-expert">Expert</span></button>
    <?php elseif ($canDuplicateBots) : ?>
        <button
            type="submit"
            class="bkiai-admin-tab-button bkiai-admin-tab-button-add"
            name="duplicate_bot"
            value="1"
            title="Duplicate the currently active bot into a new bot"
        >+</button>
    <?php endif; ?>
</div>

                <div class="bkiai-admin-tab-panel is-active" data-tab-panel="general">
                <h2>General</h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="bkiai_api_key">OpenAI API key</label></th>
                            <td>
                                <input type="password" id="bkiai_api_key" name="api_key" value="<?php echo esc_attr($settings['api_key']); ?>" class="regular-text" autocomplete="off" />
                                <p class="description">The key remains stored in the backend and is not shown in the browser.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="2" style="padding-top:18px;"><h2 style="margin:0;">Privacy and chat logs</h2><p class="description" style="margin:4px 0 0;">Here you define how long archived chat conversations may remain stored in the backend.</p></th>
                        </tr>
                        <tr>
                            <th scope="row">Privacy and local storage</th>
                            <td>
                                <?php if ($canUseChatLogs) : ?>
                                    <input type="number" id="bkiai_log_retention_days" name="privacy[log_retention_days]" min="1" max="180" step="1" value="<?php echo esc_attr((string) $settings['privacy']['log_retention_days']); ?>" class="small-text" />
                                    <p class="description">Defines after how many days chat logs are automatically deleted in the backend. Maximum 180 days.</p>
                                    <p class="description">
                                        <a href="<?php echo esc_url(admin_url('options-general.php?page=bkiai-chat-logs')); ?>">Open chat log</a>
                                    </p>
                                <?php else : ?>
                                    <div class="bkiai-upsell-card">
                                        <h3>No local chat logs in Free</h3>
                                        <p>The free edition does not store or expose a local chat-log view in the WordPress backend. Pro adds optional local chat logs with filtering and export.</p>
                                        <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('feature_chat_logs', 'pro')); ?>" target="_blank" rel="noopener noreferrer">Learn more about Pro</a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_width">Window width</label></th>
                            <td>
                                <input type="text" id="bkiai_design_width" name="design[width]" value="<?php echo esc_attr($settings['design']['width']); ?>" class="regular-text" />
                                <p class="description">Examples: <code>100%</code>, <code>780px</code>, <code>90vw</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_height">Window height</label></th>
                            <td>
                                <input type="text" id="bkiai_design_height" name="design[height]" value="<?php echo esc_attr($settings['design']['height']); ?>" class="regular-text" />
                                <p class="description">Controls only the height of the message area. Examples: <code>420px</code>, <code>60vh</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_chat_radius">Chat window / popup corner radius</label></th>
                            <td>
                                <input type="number" min="0" max="80" step="1" id="bkiai_design_chat_radius" name="design[chat_radius]" value="<?php echo esc_attr($settings['design']['chat_radius']); ?>" class="small-text" />
                                <span style="margin-left:6px;">px</span>
                                <p class="description">Defines how rounded the outer corners of the chat window or popup window are.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_input_radius">Input field corner radius</label></th>
                            <td>
                                <input type="number" min="0" max="80" step="1" id="bkiai_design_input_radius" name="design[input_radius]" value="<?php echo esc_attr($settings['design']['input_radius']); ?>" class="small-text" />
                                <span style="margin-left:6px;">px</span>
                                <p class="description">Defines how rounded the corners of the input field are.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="bkiai_design_preset">Standard design set</label></th>
                            <td>
                                <div class="bkiai-design-preset-row">
                                    <select id="bkiai_design_preset">
                                        <option value="">Please choose ...</option>
                                        <?php foreach ($this->get_design_presets() as $presetKey => $presetData) : ?>
                                            <option value="<?php echo esc_attr($presetKey); ?>"><?php echo esc_html($presetData['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="button" id="bkiai_apply_design_preset">Apply design preset</button>
                                </div>
                                <p class="description">Applies attractive preset values for colors, gradients, and patterns. You can still customize all values afterwards.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_border_width">Chat border thickness</label></th>
                            <td>
                                <input type="number" min="0" step="1" id="bkiai_design_border_width" name="design[border_width]" value="<?php echo esc_attr(isset($settings['design']['border_width']) ? $settings['design']['border_width'] : '1'); ?>" class="small-text" />
                                <span style="margin-left:6px;">px</span>
                                <p class="description">Defines the outer border thickness of the chat window. <code>0</code> px means no border.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_border_color">Chat border colour</label></th>
                            <td>
<?php $borderColor = isset($settings['design']['border_color']) ? $settings['design']['border_color'] : '#e5e7eb'; ?>
                                <div class="bkiai-color-field-group">
                                    <input type="text" id="bkiai_design_border_color" name="design[border_color]" value="<?php echo esc_attr($borderColor); ?>" class="regular-text bkiai-color-text" />
                                    <input type="color" value="<?php echo esc_attr($borderColor); ?>" class="bkiai-color-palette" data-target="bkiai_design_border_color" aria-label="Color palette for chat border" />
                                </div>
                                <p class="description">Defines the outer border colour of the chat window.</p>
                            </td>
                        </tr>
<?php $this->render_fill_setting_row('background', 'Chat background color', 'bkiai_design_bg', $settings['design']); ?>
<?php $this->render_fill_setting_row('header', 'Header color', 'bkiai_design_header', $settings['design']); ?>
                        <tr>
                            <th scope="row"><label for="bkiai_design_title_text_color">Chat title text colour</label></th>
                            <td>
<?php $titleTextColor = isset($settings['design']['title_text_color']) ? $settings['design']['title_text_color'] : '#6b7280'; ?>
                                <div class="bkiai-color-field-group">
                                    <input type="text" id="bkiai_design_title_text_color" name="design[title_text_color]" value="<?php echo esc_attr($titleTextColor); ?>" class="regular-text bkiai-color-text" />
                                    <input type="color" value="<?php echo esc_attr($titleTextColor); ?>" class="bkiai-color-palette" data-target="bkiai_design_title_text_color" aria-label="Color palette for chat title" />
                                </div>
                                <p class="description">Controls the text colour of the chat title in the header.</p>
                            </td>
                        </tr>
<?php $this->render_fill_setting_row('button', 'Button color (Send)', 'bkiai_design_button', $settings['design']); ?>
                        <tr>
                            <th scope="row"><label for="bkiai_design_send_button_text">Send button text</label></th>
                            <td>
                                <input type="text" id="bkiai_design_send_button_text" name="design[send_button_text]" value="<?php echo esc_attr(isset($settings['design']['send_button_text']) ? $settings['design']['send_button_text'] : 'Send'); ?>" class="regular-text" maxlength="40" />
                                <p class="description">Default: <code>Send</code>. You can enter any language, for example <code>Senden</code>, <code>Enviar</code> or <code>Envoyer</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_logo_url">Logo / image in header</label></th>
                            <td>
                                <div class="bkiai-logo-field-group">
                                    <input type="text" id="bkiai_design_logo_url" name="design[logo_url]" value="<?php echo esc_attr($settings['design']['logo_url']); ?>" class="regular-text" placeholder="https://.../logo.png" />
                                    <input type="file" id="bkiai_logo_file" name="bkiai_logo_file" accept="image/*" style="display:none;" />
                                    <input type="hidden" id="bkiai_logo_remove" name="design[logo_remove]" value="0" />
                                    <button type="button" class="button" id="bkiai_logo_select_button">Choose logo</button>
                                    <button type="button" class="button" id="bkiai_logo_remove_button">Remove logo</button>
                                    <img src="<?php echo esc_url($settings['design']['logo_url']); ?>" id="bkiai_logo_preview" class="bkiai-logo-preview <?php echo empty($settings['design']['logo_url']) ? 'is-hidden' : ''; ?>" alt="Logo preview" />
                                </div>
                                <p class="description">This image or logo is displayed in the top left of the chat window next to the chat title.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_chat_history_background_image_url">Chat history background image</label></th>
                            <td>
                                <div class="bkiai-logo-field-group">
                                    <input type="text" id="bkiai_design_chat_history_background_image_url" name="design[chat_history_background_image_url]" value="<?php echo esc_attr(isset($settings['design']['chat_history_background_image_url']) ? $settings['design']['chat_history_background_image_url'] : ''); ?>" class="regular-text" placeholder="https://.../background.jpg" />
                                    <input type="file" id="bkiai_chat_history_background_file" name="bkiai_chat_history_background_file" accept="image/*" style="display:none;" />
                                    <input type="hidden" id="bkiai_chat_history_background_remove" name="design[chat_history_background_image_remove]" value="0" />
                                    <button type="button" class="button" id="bkiai_chat_history_background_select_button">Choose background image</button>
                                    <button type="button" class="button" id="bkiai_chat_history_background_remove_button">Remove background image</button>
                                    <img src="<?php echo esc_url(isset($settings['design']['chat_history_background_image_url']) ? $settings['design']['chat_history_background_image_url'] : ''); ?>" id="bkiai_chat_history_background_preview" class="bkiai-logo-preview <?php echo empty($settings['design']['chat_history_background_image_url']) ? 'is-hidden' : ''; ?>" alt="Chat history background preview" />
                                </div>
                                <p class="description">Optional. This image is displayed only inside the chat history / message area, behind the messages.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Show source references</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="design[show_sources]" value="1" <?php checked(isset($settings['design']['show_sources']) ? $settings['design']['show_sources'] : '1', '1'); ?> />
                                    Show the source references at the end of chatbot answers
                                </label>
                                <p class="description">If disabled, the chatbot answer is shown without the source list at the end.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_input_placeholder_text">Input placeholder text</label></th>
                            <td>
                                <input type="text" id="bkiai_design_input_placeholder_text" name="design[input_placeholder_text]" value="<?php echo esc_attr(isset($settings['design']['input_placeholder_text']) ? $settings['design']['input_placeholder_text'] : 'Ask any question'); ?>" class="regular-text" maxlength="80" />
                                <p class="description">Default: <code>Ask any question</code>. This text appears inside the chat input field before the user types.</p>
                            </td>
                        </tr>
<?php $this->render_fill_setting_row('footer', 'Footer color', 'bkiai_design_footer', $settings['design']); ?>
<?php $this->render_fill_setting_row('expand_button', 'Expand / shrink button color', 'bkiai_design_expand_button', $settings['design'], 'Controls the background color of the expand and shrink button.'); ?>
                        <tr>
                            <th scope="row"><label for="bkiai_design_clear_button_text">Clear chat text</label></th>
                            <td>
                                <input type="text" id="bkiai_design_clear_button_text" name="design[clear_button_text]" value="<?php echo esc_attr(isset($settings['design']['clear_button_text']) ? $settings['design']['clear_button_text'] : 'Clear chat'); ?>" class="regular-text" maxlength="40" />
                                <p class="description">Default: <code>Clear chat</code>. You can enter any language for the reset button text.</p>
                            </td>
                        </tr>
<?php $resetTextColor = isset($settings['design']['reset_text_color']) ? $settings['design']['reset_text_color'] : '#dc2626'; ?>
                        <tr>
                            <th scope="row"><label for="bkiai_design_reset_text_color">Text colour “Clear chat”</label></th>
                            <td>
                                <div class="bkiai-color-field-group">
                                    <input type="text" id="bkiai_design_reset_text_color" name="design[reset_text_color]" value="<?php echo esc_attr($resetTextColor); ?>" class="regular-text bkiai-color-text" />
                                    <input type="color" value="<?php echo esc_attr($resetTextColor); ?>" class="bkiai-color-palette" data-target="bkiai_design_reset_text_color" aria-label="Color palette for Clear chat" />
                                </div>
                                <p class="description">Controls the text colour of the “Clear chat” button including the red X.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Box shadow</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="design[box_shadow_enabled]" value="1" <?php checked($settings['design']['box_shadow_enabled'], '1'); ?> />
                                    Enable box shadow
                                </label>
                            </td>
                        </tr>
                        <tr>
    <th scope="row">Voice control</th>
    <td>
        <?php if ($canUseVoice) : ?>
            <label>
                <input type="checkbox" name="design[voice_enabled]" value="1" <?php checked(isset($settings['design']['voice_enabled']) ? $settings['design']['voice_enabled'] : '0', '1'); ?> />
                Enable microphone button in chat
            </label>
            <p class="description">Free includes browser-based voice recording for the microphone button when supported by the browser.</p>

            <?php if ($canUseRealtimeVoice) : ?>
                <div style="margin-top:12px;">
                    <label style="display:block;margin-bottom:8px;">
                        <input type="checkbox" name="design[voice_realtime_enabled]" value="1" <?php checked(isset($settings['design']['voice_realtime_enabled']) ? $settings['design']['voice_realtime_enabled'] : '0', '1'); ?> />
                        Enable live voice conversation
                    </label>

                    <label for="bkiai_voice_reply_gender" style="display:inline-block;min-width:140px;">Reply voice</label>
                    <select id="bkiai_voice_reply_gender" name="design[voice_reply_gender]">
                        <option value="female" <?php selected(isset($settings['design']['voice_reply_gender']) ? $settings['design']['voice_reply_gender'] : 'female', 'female'); ?>>Female</option>
                        <option value="male" <?php selected(isset($settings['design']['voice_reply_gender']) ? $settings['design']['voice_reply_gender'] : 'female', 'male'); ?>>Male</option>
                    </select>

                    <p class="description">When enabled, the microphone button starts an OpenAI Realtime live conversation. The browser microphone is streamed to the session and the reply comes back as AI-generated audio.</p>
                </div>
            <?php else : ?>
                <input type="hidden" name="design[voice_realtime_enabled]" value="0" />
                <input type="hidden" name="design[voice_reply_gender]" value="<?php echo esc_attr(isset($settings['design']['voice_reply_gender']) ? $settings['design']['voice_reply_gender'] : 'female'); ?>" />

                <div class="bkiai-upsell-card" style="margin-top:12px;max-width:760px;">
                    <h3>Live conversation in Pro and Expert</h3>
                    <p>Free includes voice recording for the microphone button. Pro and Expert add live conversation with AI reply voices.</p>
                    <ul>
                        <li>Voice recording in Free</li>
                        <li>Live conversation in Pro and Expert</li>
                        <li>AI reply voice selection in Pro and Expert</li>
                    </ul>
                    <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('feature_voice_live', 'pro')); ?>" target="_blank" rel="noopener noreferrer">Learn more about Pro</a>
                </div>
            <?php endif; ?>
        <?php else : ?>
            <input type="hidden" name="design[voice_enabled]" value="0" />
            <input type="hidden" name="design[voice_realtime_enabled]" value="0" />
            <input type="hidden" name="design[voice_reply_gender]" value="<?php echo esc_attr(isset($settings['design']['voice_reply_gender']) ? $settings['design']['voice_reply_gender'] : 'female'); ?>" />
            <p class="description">Voice features are not available in the current plan.</p>
        <?php endif; ?>
    </td>
</tr>
                    </tbody>
                </table>
                </div>

                                <div class="bkiai-admin-tab-panel" data-tab-panel="license">
                    <h2><?php echo esc_html($licenseTabLabel); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">Installed package</th>
                                <td>
                                    <strong><?php echo esc_html($installedEditionLabel); ?></strong>
                                    <p class="description"><?php echo esc_html($installedEditionDescription); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Active plan on this site</th>
                                <td>
                                    <strong><?php echo esc_html(ucfirst($currentPlan)); ?></strong>
                                    <p class="description"><?php echo esc_html($activePlanDescription); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Compare editions</th>
                                <td>
                                    <p class="description" style="margin-top:0;">The Expert edition supports dynamic bot duplication with up to 20 total bots in one installation.</p>
                                    <div class="bkiai-plan-status-row">
                                        <span class="bkiai-plan-status-pill">Installed package: <?php echo esc_html($installedEditionLabel); ?></span>
                                        <span class="bkiai-plan-status-pill is-neutral">Active plan: <?php echo esc_html(ucfirst($currentPlan)); ?></span>
                                    </div>

                                    <div class="bkiai-plan-grid" style="margin-top:12px;">
                                        <div class="bkiai-plan-card">
                                            <span class="bkiai-plan-badge">Free</span>
                                            <h3>BKiAI Chat Free</h3>
                                            <p>Suitable for a lean Bot 1 setup with core chat functionality.</p>
                                            <ul>
                                                <li>1 active bot</li>
                                                <li>GPT-4o mini and GPT-4.1 mini</li>
                                                <li>Voice recording</li>
                                                <li>Basic design settings</li>
                                                <li>1 knowledge file per bot</li>
                                            </ul>
                                            <?php if ($installedEditionCard === 'free') : ?>
                                                <div class="bkiai-plan-current-marker">Installed package</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="bkiai-plan-card">
                                            <span class="bkiai-plan-badge">Pro</span>
                                            <h3>BKiAI Chat Pro</h3>
                                            <p>For users who need richer knowledge sources and advanced interaction features.</p>
                                            <ul>
                                                <li>Bot 1 and Bot 2 active</li>
                                                <li>GPT-4o mini, GPT-4o, GPT-4.1 mini, GPT-4.1, GPT-5 mini, GPT-5.1, GPT-5.3, and GPT-5.4</li>
                                                <li>Live conversation</li>
                                                <li>Image generation</li>
                                                <li>PDF generation</li>
                                                <li>Web search and website knowledge</li>
                                                <li>Chat logs</li>
                                            </ul>
                                            <?php if ($installedEditionCard === 'pro') : ?>
                                                <div class="bkiai-plan-current-marker">Installed package</div>
                                            <?php elseif ($showProUpgradeInCompare) : ?>
                                                <a class="button button-primary" href="<?php echo esc_url($upgradeProUrl); ?>" target="_blank" rel="noopener noreferrer">View Pro</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="bkiai-plan-card">
                                            <span class="bkiai-plan-badge">Expert</span>
                                            <h3>BKiAI Chat Expert</h3>
                                            <p>For larger multi-bot setups and maximum flexibility.</p>
                                            <ul>
                                                <li>Bot 1–5 active</li>
                                                <li>Dynamic bot duplication up to 20 total bots</li>
                                                <li>GPT-4o mini, GPT-4o, GPT-4.1 mini, GPT-4.1, GPT-5 mini, GPT-5.1, GPT-5.3, and GPT-5.4</li>
                                                <li>Live conversation</li>
                                                <li>Image and PDF generation</li>
                                                <li>Full premium feature set</li>
                                            </ul>
                                            <?php if ($installedEditionCard === 'expert') : ?>
                                                <div class="bkiai-plan-current-marker">Installed package</div>
                                            <?php elseif ($showExpertUpgradeInCompare) : ?>
                                                <a class="button button-secondary" href="<?php echo esc_url($upgradeExpertUrl); ?>" target="_blank" rel="noopener noreferrer">View Expert</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <table class="bkiai-feature-list" style="margin-top:18px; width:100%;">
                                        <thead>
                                            <tr>
                                                <th>Feature</th>
                                                <th>Free</th>
                                                <th>Pro</th>
                                                <th>Expert</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>Active bots</td><td>1</td><td>2</td><td>Up to 20 total with duplication</td></tr>
                                            <tr><td>GPT models</td><td>GPT-4o mini, GPT-4.1 mini</td><td>GPT-4o mini, GPT-4o, GPT-4.1 mini, GPT-4.1, GPT-5 mini, GPT-5.1, GPT-5.3, GPT-5.4</td><td>GPT-4o mini, GPT-4o, GPT-4.1 mini, GPT-4.1, GPT-5 mini, GPT-5.1, GPT-5.3, GPT-5.4</td></tr>
                                            <tr><td>Voice control</td><td>Voice recording</td><td>Live conversation</td><td>Live conversation</td></tr>
                                            <tr><td>Image generation</td><td>—</td><td>Yes</td><td>Yes</td></tr>
                                            <tr><td>PDF generation</td><td>—</td><td>Yes</td><td>Yes</td></tr>
                                            <tr><td>Web search</td><td>—</td><td>Yes</td><td>Yes</td></tr>
                                            <tr><td>Website knowledge</td><td>—</td><td>Yes</td><td>Yes</td></tr>
                                            <tr><td>Chat logs</td><td>—</td><td>Yes</td><td>Yes</td></tr>
                                            <tr><td>Knowledge-file limits</td><td>Lean</td><td>Extended</td><td>Extended</td></tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">License status</th>
                                <td>
                                    <?php if ($isFreeBuild) : ?>
                                        <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#ecfdf3;color:#166534;font-weight:600;">No license required in the free build</span>
                                        <p class="description">Upgrade on your website when you want to move from Free to Pro or Expert.</p>
                                    <?php else : ?>
                                        <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:600;"><?php echo esc_html($licenseStatusLabel); ?></span>
                                        <?php if (!empty($licenseSettings['last_checked'])) : ?>
                                            <p class="description">Last local update: <?php echo esc_html($licenseSettings['last_checked']); ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($isPremiumBuild) : ?>
                                <tr>
                                    <th scope="row"><label for="bkiai_license_key">License key</label></th>
                                    <td>
                                        <input type="text" id="bkiai_license_key" name="license[key]" value="<?php echo esc_attr(isset($licenseSettings['key']) ? $licenseSettings['key'] : ''); ?>" class="regular-text" autocomplete="off" placeholder="Enter your Pro or Expert license key" />
                                        <p class="description">Enter the Pro or Expert license key from your Easy Digital Downloads purchase. Activation is sent directly to your EDD store.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Actions</th>
                                    <td>
                                        <button type="submit" name="activate_license" value="1" class="button button-primary">Activate License</button>
                                        <button type="submit" name="deactivate_license" value="1" class="button button-secondary" style="margin-left:8px;">Deactivate License</button>
                                        <p class="description">Activate sends the key to your EDD store. Deactivate removes the current site activation and falls back to the free plan until a valid key is activated again.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Instance</th>
                                    <td>
                                        <code><?php echo esc_html(home_url('/')); ?></code>
                                        <p class="description">This site URL is sent to Easy Digital Downloads Software Licensing during activation and deactivation.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php for ($i = 1; $i <= $adminBotCount; $i++) : 
					$bot = isset($settings['bots'][$i]) ? $settings['bots'][$i] : $this->get_default_bot($i);
					$isBotAccessible = isset($botAccessibleMap[$i])
						? $botAccessibleMap[$i]
						: ($currentPlan === BKiAI_Plan_Manager::PLAN_EXPERT);
				?>
				<div class="bkiai-admin-tab-panel" data-tab-panel="bot-<?php echo esc_attr((string) $i); ?>">
					<div class="bkiai-bot-tab-header">
						<h2>Bot <?php echo esc_html((string) $i); ?></h2>
						<?php if ($i > 5 && $canDeleteDynamicBots) : ?>
							<button type="submit" class="button button-secondary bkiai-delete-bot-button" name="delete_bot" value="<?php echo esc_attr((string) $i); ?>" onclick="return confirm('Delete this duplicated bot?');">Delete this bot</button>
						<?php endif; ?>
					</div>

					<?php if ($isBotAccessible) : ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">Enable bot</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="bots[<?php echo esc_attr((string) $i); ?>][enabled]" value="1" <?php checked($bot['enabled'], '1'); ?> />
                                        Bot <?php echo esc_html((string) $i); ?> active
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="bot_title_<?php echo esc_attr((string) $i); ?>">Chat title</label></th>
                                <td><input type="text" id="bot_title_<?php echo esc_attr((string) $i); ?>" name="bots[<?php echo esc_attr((string) $i); ?>][title]" value="<?php echo esc_attr($bot['title']); ?>" class="regular-text" /></td>
                            </tr>
<?php
$currentBotModel = isset($bot['model']) ? $bot['model'] : 'gpt-4o-mini';
$modelIsAllowed = BKiAI_Plan_Manager::is_model_allowed($currentBotModel);
?>
<tr>
    <th scope="row"><label for="bot_model_<?php echo esc_attr((string) $i); ?>">GPT model</label></th>
    <td>
        <select id="bot_model_<?php echo esc_attr((string) $i); ?>" name="bots[<?php echo esc_attr((string) $i); ?>][model]">
            <?php
            $modelOptions = !empty($planConfig['full_model_access'])
                ? $this->models
                : array_intersect_key($this->models, array_flip(isset($planConfig['allowed_models']) ? (array) $planConfig['allowed_models'] : array()));
            foreach ($modelOptions as $value => $label) :
            ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($currentBotModel, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>

        <?php if (empty($planConfig['full_model_access'])) : ?>
            <p class="description">Free includes GPT-4o mini and GPT-4.1 mini. Pro and Expert unlock GPT-4o mini, GPT-4o, GPT-4.1 mini, GPT-4.1, GPT-5 mini, GPT-5.1, GPT-5.3, and GPT-5.4.</p>
            <?php if (!$modelIsAllowed) : ?>
                <div class="bkiai-upsell-card" style="margin-top:10px;">
                    <h3>More models in Pro</h3>
                    <p>This bot currently references a model that is not part of the free edition. Save Bot 1 with one of the available free models, or upgrade to Pro for broader model access.</p>
                    <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('feature_models', 'pro')); ?>" target="_blank" rel="noopener noreferrer">Learn more about Pro</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </td>
</tr>
							<tr>
								<th scope="row">Image generation</th>
								<td>
									<?php if ($canUseImageGeneration) : ?>
										<label>
											<input type="checkbox" name="bots[<?php echo esc_attr((string) $i); ?>][image_generation_enabled]" value="1" <?php checked(isset($bot['image_generation_enabled']) ? $bot['image_generation_enabled'] : '0', '1'); ?> />
											Enable image generation for this bot
										</label>
										<p class="description">When this option is enabled, the bot can generate images for suitable prompts. Image generation is additionally handled through OpenAI.</p>
                                    <?php else : ?>
                                        <div class="bkiai-upsell-card">
                                            <h3>Image generation in Pro</h3>
                                            <p>Pro can generate images directly from suitable prompts via OpenAI. The free edition remains focused on text chat.</p>
                                            <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('feature_image_generation', 'pro')); ?>" target="_blank" rel="noopener noreferrer">Learn more about Pro</a>
                                        </div>
                                    <?php endif; ?>
								</td>
							</tr>

                            <tr>
                                <th scope="row">PDF generation</th>
                                <td>
                                    <?php if ($canUsePdfGeneration) : ?>
                                        <label>
                                            <input type="checkbox" name="bots[<?php echo esc_attr((string) $i); ?>][pdf_generation_enabled]" value="1" <?php checked(isset($bot['pdf_generation_enabled']) ? $bot['pdf_generation_enabled'] : '1', '1'); ?> />
                                            Enable PDF generation for this bot
                                        </label>
                                        <p class="description">When this option is enabled, the bot can create a PDF from its reply for suitable prompts such as /pdf, “create a PDF”, or “export as PDF”.</p>
                                    <?php else : ?>
                                        <input type="hidden" name="bots[<?php echo esc_attr((string) $i); ?>][pdf_generation_enabled]" value="0" />
                                        <div class="bkiai-upsell-card">
                                            <h3>PDF generation in Pro</h3>
                                            <p>Pro can turn suitable chatbot replies into downloadable PDF files. The free edition keeps this option visible, but the feature itself stays locked.</p>
                                            <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('feature_pdf_generation', 'pro')); ?>" target="_blank" rel="noopener noreferrer">Learn more about Pro</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="bot_welcome_<?php echo esc_attr((string) $i); ?>">Welcome message</label></th>
                                <td><textarea id="bot_welcome_<?php echo esc_attr((string) $i); ?>" name="bots[<?php echo esc_attr((string) $i); ?>][welcome_message]" rows="3" class="large-text"><?php echo esc_textarea($bot['welcome_message']); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="bot_prompt_<?php echo esc_attr((string) $i); ?>">System-Prompt</label></th>
                                <td>
                                    <textarea id="bot_prompt_<?php echo esc_attr((string) $i); ?>" name="bots[<?php echo esc_attr((string) $i); ?>][system_prompt]" rows="6" class="large-text bkiai-systemprompt-field" data-counter-target="bot_prompt_count_<?php echo esc_attr((string) $i); ?>"><?php echo esc_textarea($bot['system_prompt']); ?></textarea>
                                    <div class="bkiai-systemprompt-meta">
                                        <p class="description bkiai-systemprompt-hint">This text controls the behavior of the respective bot. The system prompt should stay as compact as possible and ideally remain under 2500 characters.</p>
                                        <span id="bot_prompt_count_<?php echo esc_attr((string) $i); ?>" class="bkiai-systemprompt-count" aria-live="polite">0 characters</span>
                                    </div>
                                </td>
                            </tr>
<tr>
    <th scope="row">Knowledge sources</th>
    <td>
        <?php if ($canUseWebsiteKnowledge) : ?>
            <label>
                <input type="checkbox" name="bots[<?php echo esc_attr((string) $i); ?>][use_website_content]" value="1" <?php checked(isset($bot['use_website_content']) ? $bot['use_website_content'] : '0', '1'); ?> />
                Use content from your website pages and posts
            </label>
            <br />
            <?php if ($canUseWebSearch) : ?>
                <label>
                    <input type="checkbox" name="bots[<?php echo esc_attr((string) $i); ?>][use_web_search]" value="1" <?php checked(isset($bot['use_web_search']) ? $bot['use_web_search'] : '0', '1'); ?> />
                    Enable web search
                </label>
            <?php endif; ?>

            <div style="margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fff;max-width:900px;">
                <strong>Website content</strong><br />
                <label style="display:block;margin-top:8px;">
                    <input type="radio" name="bots[<?php echo esc_attr((string) $i); ?>][website_scope]" value="all" <?php checked(isset($bot['website_scope']) ? $bot['website_scope'] : 'all', 'all'); ?> />
                    Use the entire website including all subpages
                </label>
                <label style="display:block;margin-top:6px;">
                    <input type="radio" name="bots[<?php echo esc_attr((string) $i); ?>][website_scope]" value="selected" <?php checked(isset($bot['website_scope']) ? $bot['website_scope'] : 'all', 'selected'); ?> />
                    Use only selected pages/posts
                </label>
                <div style="margin-top:10px;">
                    <select name="bots[<?php echo esc_attr((string) $i); ?>][selected_content_ids][]" multiple size="8" style="min-width:420px;max-width:900px;">
                        <?php foreach ($contentOptions as $contentOption) : ?>
                            <option value="<?php echo esc_attr((string) $contentOption['id']); ?>" <?php selected(in_array(intval($contentOption['id']), array_map('intval', isset($bot['selected_content_ids']) ? $bot['selected_content_ids'] : array()), true), true); ?>><?php echo esc_html($contentOption['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Only relevant if "use only selected pages/posts" is active. Use Ctrl or Cmd for multiple selection.</p>
                </div>
            </div>
        <?php else : ?>
            <div class="bkiai-upsell-card">
                <h3>Additional knowledge sources in Pro</h3>
                <p>Pro can enrich Bot 1 with selected website content and optional web search. The free edition keeps the bot focused on its own prompt and uploaded knowledge files.</p>
                <ul>
                    <li>Use posts and pages from your WordPress site as knowledge</li>
                    <li>Restrict website knowledge to selected pages</li>
                    <li>Enable web search for current information</li>
                </ul>
                <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('feature_knowledge_sources', 'pro')); ?>" target="_blank" rel="noopener noreferrer">Learn more about Pro</a>
            </div>
        <?php endif; ?>
    </td>
</tr>
                            <tr>
                                <th scope="row">Daily rate limits</th>
                                <td>
                                    <label>Messages per visitor per day: <input type="number" min="1" step="1" name="bots[<?php echo esc_attr((string) $i); ?>][daily_message_limit]" value="<?php echo esc_attr($bot['daily_message_limit']); ?>" class="small-text" /></label><br /><br />
                                    <label>Approx. tokens per visitor per day: <input type="number" min="1" step="1" name="bots[<?php echo esc_attr((string) $i); ?>][daily_token_limit]" value="<?php echo esc_attr($bot['daily_token_limit']); ?>" class="small-text" /></label>
                                    <p class="description">The token limit is an approximation to help you control usage more effectively.</p>
                                </td>
                            </tr>
                            <?php if ($i === 1) : ?>
                            <tr>
                                <th scope="row">Popup window</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="bots[<?php echo esc_attr((string) $i); ?>][popup_enabled]" value="1" <?php checked(isset($bot['popup_enabled']) ? $bot['popup_enabled'] : '0', '1'); ?> />
                                        Show Bot 1 as a collapsible popup window
                                    </label>
                                    <p class="description">When this option is enabled, Bot 1 is displayed as a floating chat window with a standard size similar to common chat providers. By default, the popup opens automatically only on desktop devices.</p>
                                    <label for="bot_popup_position_<?php echo esc_attr((string) $i); ?>" style="display:inline-block;margin-top:10px;">Popup position</label><br />
                                    <select id="bot_popup_position_<?php echo esc_attr((string) $i); ?>" name="bots[<?php echo esc_attr((string) $i); ?>][popup_position]">
                                        <option value="bottom-right" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'bottom-right'); ?>>bottom right</option>
                                        <option value="bottom-left" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'bottom-left'); ?>>bottom left</option>
                                        <option value="top-right" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'top-right'); ?>>top right</option>
                                        <option value="top-left" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'top-left'); ?>>top left</option>
                                    </select>
                                    <label for="bot_popup_page_scope_<?php echo esc_attr((string) $i); ?>" style="display:inline-block;margin-top:10px;">Show popup only on specific pages</label><br />
                                    <select id="bot_popup_page_scope_<?php echo esc_attr((string) $i); ?>" name="bots[<?php echo esc_attr((string) $i); ?>][popup_page_scope]">
                                        <option value="all" <?php selected(isset($bot['popup_page_scope']) ? $bot['popup_page_scope'] : 'all', 'all'); ?>>Show on all allowed pages</option>
                                        <option value="selected" <?php selected(isset($bot['popup_page_scope']) ? $bot['popup_page_scope'] : 'all', 'selected'); ?>>Show only on selected pages</option>
                                    </select>
                                    <div class="bkiai-popup-page-select" style="margin-top:10px;">
                                        <label for="bot_popup_selected_pages_<?php echo esc_attr((string) $i); ?>">Selected pages / posts</label><br />
                                        <select id="bot_popup_selected_pages_<?php echo esc_attr((string) $i); ?>" name="bots[<?php echo esc_attr((string) $i); ?>][popup_selected_page_ids][]" multiple size="8" style="min-width:360px;max-width:100%;">
                                            <?php foreach ($contentOptions as $contentOption) : ?>
                                                <option value="<?php echo esc_attr((string) $contentOption['id']); ?>" <?php echo in_array(intval($contentOption['id']), isset($bot['popup_selected_page_ids']) ? array_map('intval', (array) $bot['popup_selected_page_ids']) : array(), true) ? 'selected' : ''; ?>><?php echo esc_html($contentOption['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">Only relevant if "Show only on selected pages" is selected above. Hold Ctrl or Cmd for multiple selection.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <tr>
                                <th scope="row">Upload knowledge files</th>
                                <td>
                                    <?php
                                    $knowledgeFiles = isset($bot['knowledge_files']) && is_array($bot['knowledge_files']) ? $bot['knowledge_files'] : array();
                                    $knowledgeFileCount = count($knowledgeFiles);
                                    $knowledgeLimitReached = $knowledgeFileCount >= $maxKnowledgeFiles;
                                    $isKnowledgeUploadLocked = $knowledgeLimitReached && ($maxKnowledgeFiles < 999);
                                    ?>

                                    <?php if (!$isKnowledgeUploadLocked) : ?>
                                        <div class="bkiai-admin-file-input">
                                            <input type="file" id="bkiai_knowledge_files_<?php echo esc_attr((string) $i); ?>" name="knowledge_files_<?php echo esc_attr((string) $i); ?>[]" multiple accept=".md,.markdown,.txt,.csv,text/plain,text/markdown,text/csv" class="bkiai-admin-file-native" hidden style="display:none !important;" aria-hidden="true" tabindex="-1" />
                                            <button type="button" class="button bkiai-admin-file-trigger" data-target="bkiai_knowledge_files_<?php echo esc_attr((string) $i); ?>">Choose files</button>
                                            <span class="bkiai-admin-file-status" data-empty-label="No file chosen">No file chosen</span>
                                        </div>

                                        <p class="description">
                                            Allowed: Markdown, TXT, CSV. After saving, the file will no longer be shown in the upload field for security reasons. It will instead appear below as a saved file.
                                            <?php if ($maxKnowledgeFiles < 999) : ?>
                                                <br /><span style="color:#a05a00;">Free includes up to <?php echo esc_html((string) $maxKnowledgeFiles); ?> knowledge file per bot.</span>
                                            <?php endif; ?>
                                        </p>
                                    <?php else : ?>
                                        <div class="bkiai-upsell-card">
                                            <h3>More knowledge files in Pro</h3>
                                            <p>Free includes up to <?php echo esc_html((string) $maxKnowledgeFiles); ?> uploaded knowledge file per bot. Pro increases this limit substantially for larger use cases.</p>
                                            <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('feature_knowledge_files', 'pro')); ?>" target="_blank" rel="noopener noreferrer">Learn more about Pro</a>
                                        </div>
                                    <?php endif; ?>
                                    <details class="bkiai-knowledge-help">
                                        <summary>Show recommendations for knowledge file size, quantity, and structure</summary>
                                        <div class="bkiai-knowledge-help-box">
                                            <h4>Recommended size and quantity for knowledge files per bot</h4>
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Area</th>
                                                        <th>Recommendation</th>
                                                        <th>Still usable</th>
                                                        <th>Better avoided</th>
                                                        <th>Reason</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>Markdown / TXT per file</td>
                                                        <td>1–20 KB</td>
                                                        <td>20–80 KB</td>
                                                        <td>over 100 KB</td>
                                                        <td>Small, clearly themed files are processed much more accurately in the current plugin.</td>
                                                    </tr>
                                                    <tr>
                                                        <td>CSV per file</td>
                                                        <td>10–200 KB</td>
                                                        <td>200 KB–1 MB</td>
                                                        <td>over 1 MB</td>
                                                        <td>CSV works well when the file is cleanly structured and limited to a clear topic.</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Number of knowledge files per bot</td>
                                                        <td>5–20 files</td>
                                                        <td>20–40 files</td>
                                                        <td>over 40 files</td>
                                                        <td>Too many files make selection less precise and increase redundancy.</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Topics per file</td>
                                                        <td>exactly 1 topic</td>
                                                        <td>1 main topic + a few subtopics</td>
                                                        <td>many mixed topics</td>
                                                        <td>The more clearly scoped the file is, the more precisely the bot can use relevant content.</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Total volume per bot</td>
                                                        <td>up to approx. 1–5 MB</td>
                                                        <td>5–15 MB</td>
                                                        <td>significantly above that</td>
                                                        <td>The server can store more, but for the current bot processing, too much content usually adds no real benefit.</td>
                                                    </tr>
                                                    <tr>
                                                        <td>System prompt</td>
                                                        <td>800–2500 characters</td>
                                                        <td>2500–5000 characters</td>
                                                        <td>over 6000 characters</td>
                                                        <td>A prompt that is too long pushes out the user question, knowledge sources, and conversation history.</td>
                                                    </tr>
                                                </tbody>
                                            </table>

                                            <h4>Practical recommendation for the knowledge base</h4>
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Point</th>
                                                        <th>Recommended</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>Best file structure</td>
                                                        <td>Prefer many small, well-named files instead of one huge collection file</td>
                                                    </tr>
                                                    <tr>
                                                        <td>File naming</td>
                                                        <td>z. B. wordpress-chatbots.csv, ki-tools-marketing.csv, preise-ki-tools.md</td>
                                                    </tr>
                                                    <tr>
                                                        <td>CSV structure</td>
                                                        <td>Consistent columns, no empty rows, and no unnecessary blocks of text in single cells</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Content structure</td>
                                                        <td>One clear topic area per file</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Important content</td>
                                                        <td>Place important content as high in the file as possible</td>
                                                    </tr>
                                                </tbody>
                                            </table>

                                            <p><strong>Short summary:</strong> Knowledge files per bot should be as small, clearly themed, and cleanly named as possible. For Markdown/TXT, 1–20 KB per file is ideal; for CSV, 10–200 KB. In most cases, 5–20 knowledge files per bot is recommended.</p>
                                        </div>
                                    </details>
                                    <?php if (!empty($bot['knowledge_files'])) : ?>
                                        <div style="margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fff;max-width:980px;">
                                            <strong>Saved knowledge files (<?php echo esc_html((string) count($bot['knowledge_files'])); ?>)</strong>
                                            <ul style="margin:10px 0 0 0;list-style:none;">
                                                <?php foreach ($bot['knowledge_files'] as $fileIndex => $file) : ?>
                                                    <li style="margin-bottom:12px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fafafa;">
                                                        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                                                            <strong><?php echo esc_html(isset($file['name']) ? $file['name'] : 'File'); ?></strong>
                                                            <label><input type="checkbox" name="knowledge_active[<?php echo esc_attr((string) $i); ?>][<?php echo esc_attr((string) $fileIndex); ?>]" value="1" <?php checked(!isset($file['active']) || $file['active'] === '1', true); ?> /> active</label>
                                                            <label><input type="checkbox" name="delete_files[<?php echo esc_attr((string) $i); ?>][]" value="<?php echo esc_attr((string) $fileIndex); ?>" /> delete</label>
                                                            <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:<?php echo (!isset($file['active']) || $file['active'] === '1') ? '#dcfce7' : '#fee2e2'; ?>;font-size:12px;"><?php echo (!isset($file['active']) || $file['active'] === '1') ? 'active' : 'deactiveiert'; ?></span>
                                                        </div>
                                                        <div style="color:#50575e;margin-top:6px;">
                                                            Status: <?php echo !empty($file['path']) && file_exists($file['path']) ? 'saved' : 'File missing'; ?>
                                                            <?php if (!empty($file['type'])) : ?> · Type: <?php echo esc_html(strtoupper($file['type'])); ?><?php endif; ?>
                                                            <?php if (!empty($file['size'])) : ?> · Size: <?php echo esc_html($this->format_bytes($file['size'])); ?><?php endif; ?>
                                                            <?php if (!empty($file['uploaded_at'])) : ?> · Uploaded: <?php echo esc_html($file['uploaded_at']); ?><?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($file['path'])) : ?>
                                                            <code style="display:inline-block;margin-top:4px;word-break:break-all;"><?php echo esc_html($file['path']); ?></code>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php else : ?>
                                        <p class="description" style="margin-top:10px;">No knowledge file is currently saved for this bot.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
						</tbody>
					</table>

                        <?php else : ?>
                            <?php
                            $requiredPlan = ($i === 2) ? 'pro' : 'expert';
                            $lockedTitle = ($i === 2) ? 'Bot 2 is available in Pro' : 'Bot ' . $i . ' is available in Expert';
                            $lockedCopy = ($i === 2)
                                ? 'The free edition includes one active bot. Upgrade to Pro to activate Bot 2 and manage a second chatbot profile.'
                                : 'The free edition focuses on Bot 1. Upgrade to Expert to activate Bots 3–5 and work with a broader multi-bot setup.';
                            $lockedFeatures = ($i === 2)
                                ? array('Second active bot', 'GPT-4o mini, GPT-4o, GPT-4.1 mini, GPT-4.1, GPT-5 mini, GPT-5.1, GPT-5.3, and GPT-5.4', 'Live conversation plus web search, image generation, and website knowledge')
                                : array('Bots 3–5 active', 'Additional duplicated bots via the + tab up to 20 total bots', 'Full GPT model set, live conversation, and the larger multi-bot workflow');
                            ?>
                            <div class="bkiai-upsell-card bkiai-locked-panel-copy" style="margin-top:8px;">
                                <h3><?php echo esc_html($lockedTitle); ?></h3>
                                <p><?php echo esc_html($lockedCopy); ?></p>
                                <ul>
                                    <?php foreach ($lockedFeatures as $lockedFeature) : ?>
                                        <li><?php echo esc_html($lockedFeature); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('bot_tab_' . $i, $requiredPlan)); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html($requiredPlan === 'pro' ? 'View Pro' : 'View Expert'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
					</div>
					<?php endfor; ?>

                <?php if ($isFreeBuild) : ?>
                <div class="bkiai-admin-tab-panel" data-tab-panel="bot-add">
                    <div class="bkiai-bot-tab-header">
                        <h2>Additional bots</h2>
                    </div>
                    <div class="bkiai-upsell-card bkiai-locked-panel-copy">
                        <h3>The + tab is available in Expert</h3>
                        <p>In the free edition, the + tab is shown as a preview of the extended multi-bot workflow. Expert can duplicate the currently active bot and create additional bot tabs up to a maximum of 20 total bots.</p>
                        <ul>
                            <li>Duplicate the current bot into a new bot tab</li>
                            <li>Create additional bots beyond Bot 5</li>
                            <li>Manage larger chatbot setups with the full GPT model set and live conversation</li>
                        </ul>
                        <a class="button button-secondary" href="<?php echo esc_url(BKiAI_Plan_Manager::get_upgrade_url('bot_tab_add', 'expert')); ?>" target="_blank" rel="noopener noreferrer">View Expert</a>
                    </div>
                </div>
                <?php endif; ?>

                <?php submit_button('Save settings'); ?>
                <?php if (!$isFreeBuild) : ?><p class="description" style="margin-top:8px;">Use the <strong>+</strong> tab to duplicate the currently active bot and create additional bot tabs when you need more than five bots, up to a maximum of 20 total bots.</p><?php endif; ?>
            </form>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var activeTabInput = document.getElementById('bkiai_active_tab');

                    function activateAdminTab(target) {
                        if (!target) {
                            target = 'general';
                        }
                        var targetExists = false;
                        document.querySelectorAll('.bkiai-admin-tab-button').forEach(function (item) {
                            if (item.getAttribute('data-tab-target') === target) {
                                targetExists = true;
                            }
                        });
                        if (!targetExists) {
                            target = 'general';
                        }

                        document.querySelectorAll('.bkiai-admin-tab-button').forEach(function (item) {
                            item.classList.toggle('is-active', item.getAttribute('data-tab-target') === target);
                        });
                        document.querySelectorAll('.bkiai-admin-tab-panel').forEach(function (panel) {
                            var isActive = panel.getAttribute('data-tab-panel') === target;
                            panel.classList.toggle('is-active', isActive);
                            panel.style.display = isActive ? 'block' : 'none';
                        });

                        if (activeTabInput) {
                            activeTabInput.value = target;
                        }

                        try {
                            window.localStorage.setItem('bkiai_admin_active_tab', target);
                        } catch (e) {}
                    }

                    var urlTab = '';
                    try {
                        urlTab = new URLSearchParams(window.location.search).get('active_tab') || '';
                    } catch (e) {}
                    var savedTab = '';
                    try {
                        savedTab = window.localStorage.getItem('bkiai_admin_active_tab') || '';
                    } catch (e) {}

                    activateAdminTab(urlTab || (activeTabInput ? activeTabInput.value : '') || savedTab || 'general');

                    document.querySelectorAll('.bkiai-admin-tab-button').forEach(function (button) {
                        button.addEventListener('click', function () {
                            activateAdminTab(button.getAttribute('data-tab-target'));
                        });
                    });

                    document.querySelectorAll('.bkiai-color-palette').forEach(function (picker) {
                        var targetId = picker.getAttribute('data-target');
                        var textInput = document.getElementById(targetId);
                        if (!textInput) {
                            return;
                        }
                        picker.addEventListener('input', function () {
                            textInput.value = picker.value;
                        });
                        textInput.addEventListener('input', function () {
                            var value = (textInput.value || '').trim();
                            if (/^#[0-9a-fA-F]{6}$/.test(value)) {
                                picker.value = value;
                            }
                        });
                    });
                    document.querySelectorAll('.bkiai-admin-file-trigger').forEach(function (button) {
                        var targetId = button.getAttribute('data-target');
                        var fileInput = document.getElementById(targetId);
                        var status = button.parentNode ? button.parentNode.querySelector('.bkiai-admin-file-status') : null;
                        if (!fileInput || !status) {
                            return;
                        }

                        var syncFileStatus = function () {
                            var files = fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
                            if (!files.length) {
                                status.textContent = status.getAttribute('data-empty-label') || 'No file chosen';
                                return;
                            }
                            status.textContent = files.map(function (file) { return file.name; }).join(', ');
                        };

                        button.addEventListener('click', function () {
                            fileInput.click();
                        });

                        fileInput.addEventListener('change', syncFileStatus);
                        syncFileStatus();
                    });

                    function normalizeHex(hex) {
                        var value = (hex || '').trim();
                        if (/^#[0-9a-fA-F]{3}$/.test(value)) {
                            return '#' + value.charAt(1) + value.charAt(1) + value.charAt(2) + value.charAt(2) + value.charAt(3) + value.charAt(3);
                        }
                        return /^#[0-9a-fA-F]{6}$/.test(value) ? value.toLowerCase() : '#ffffff';
                    }

                    function adjustColor(hex, amount) {
                        hex = normalizeHex(hex).replace('#', '');
                        var num = parseInt(hex, 16);
                        var r = Math.max(0, Math.min(255, (num >> 16) + amount));
                        var g = Math.max(0, Math.min(255, ((num >> 8) & 0x00ff) + amount));
                        var b = Math.max(0, Math.min(255, (num & 0x0000ff) + amount));
                        return '#' + [r, g, b].map(function (part) { return part.toString(16).padStart(2, '0'); }).join('');
                    }

                    function rgba(hex, alpha) {
                        hex = normalizeHex(hex).replace('#', '');
                        var num = parseInt(hex, 16);
                        var r = num >> 16;
                        var g = (num >> 8) & 0x00ff;
                        var b = num & 0x0000ff;
                        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
                    }

                    function buildFillPreview(color, type, preset, angle) {
                        color = normalizeHex(color);
                        angle = parseInt(angle || '135', 10);
                        var light = adjustColor(color, 52);
                        var mid = adjustColor(color, 22);
                        var dark = adjustColor(color, -24);
                        var deeper = adjustColor(color, -44);
                        if (type === 'solid') {
                            return color;
                        }
                        if (type === 'gradient') {
                            switch (preset) {
                                case 'shine':
                                    return 'linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + mid + ' 38%, ' + dark + ' 100%)';
                                case 'deep':
                                    return 'linear-gradient(' + angle + 'deg, ' + mid + ' 0%, ' + deeper + ' 100%)';
                                case 'split':
                                    return 'linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 52%, ' + dark + ' 100%)';
                                default:
                                    return 'linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
                            }
                        }
                        if (preset === 'dots') {
                            return 'radial-gradient(circle, ' + rgba('#ffffff', 0.30) + ' 0 2px, transparent 2.4px) 0 0 / 18px 18px repeat, linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
                        }
                        if (preset === 'grid') {
                            return 'linear-gradient(' + rgba('#ffffff', 0.18) + ' 1px, transparent 1px) 0 0 / 18px 18px repeat, linear-gradient(90deg, ' + rgba('#ffffff', 0.18) + ' 1px, transparent 1px) 0 0 / 18px 18px repeat, linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
                        }
                        if (preset === 'mesh') {
                            return 'repeating-linear-gradient(' + angle + 'deg, ' + rgba('#ffffff', 0.24) + ' 0 2px, transparent 2px 14px), repeating-linear-gradient(' + ((angle + 90) % 360) + 'deg, ' + rgba('#ffffff', 0.10) + ' 0 2px, transparent 2px 14px), linear-gradient(' + angle + 'deg, ' + mid + ' 0%, ' + dark + ' 100%)';
                        }
                        return 'repeating-linear-gradient(' + angle + 'deg, ' + rgba('#ffffff', 0.24) + ' 0 10px, ' + rgba('#ffffff', 0.10) + ' 10px 20px), linear-gradient(' + angle + 'deg, ' + light + ' 0%, ' + color + ' 100%)';
                    }

                    document.querySelectorAll('.bkiai-fill-preview').forEach(function (preview) {
                        var scope = preview.getAttribute('data-scope');
                        var colorInput = document.querySelector('input[name="design[' + scope + '_color]"]');
                        var typeSelect = document.querySelector('select[name="design[' + scope + '_fill_type]"]');
                        var presetSelect = document.querySelector('select[name="design[' + scope + '_fill_preset]"]');
                        var angleSelect = document.querySelector('select[name="design[' + scope + '_fill_angle]"]');
                        var updatePreview = function () {
                            preview.style.background = buildFillPreview(colorInput ? colorInput.value : '#ffffff', typeSelect ? typeSelect.value : 'solid', presetSelect ? presetSelect.value : 'soft', angleSelect ? angleSelect.value : '135');
                        };
                        [colorInput, typeSelect, presetSelect, angleSelect].forEach(function (element) {
                            if (element) {
                                element.addEventListener('input', updatePreview);
                                element.addEventListener('change', updatePreview);
                            }
                        });
                        updatePreview();
                    });


var designPresets = {"bkiai_light": {"label": "BusinessKiAI Light", "values": {"background_color": "#ffffff", "background_fill_type": "solid", "background_fill_preset": "soft", "background_fill_angle": "135", "header_color": "#eaf2ff", "header_fill_type": "gradient", "header_fill_preset": "soft", "header_fill_angle": "135", "button_color": "#1d4ed8", "button_fill_type": "gradient", "button_fill_preset": "deep", "button_fill_angle": "135", "footer_color": "#ffffff", "footer_fill_type": "solid", "footer_fill_preset": "soft", "footer_fill_angle": "135", "expand_button_color": "#f8fafc", "expand_button_fill_type": "gradient", "expand_button_fill_preset": "soft", "expand_button_fill_angle": "135", "box_shadow_enabled": "1"}}, "bkiai_dark": {"label": "BusinessKiAI Dark", "values": {"background_color": "#e5e7eb", "background_fill_type": "gradient", "background_fill_preset": "deep", "background_fill_angle": "135", "header_color": "#cbd5e1", "header_fill_type": "gradient", "header_fill_preset": "split", "header_fill_angle": "135", "button_color": "#0f172a", "button_fill_type": "gradient", "button_fill_preset": "deep", "button_fill_angle": "135", "footer_color": "#dbe4ef", "footer_fill_type": "gradient", "footer_fill_preset": "soft", "footer_fill_angle": "135", "expand_button_color": "#cbd5e1", "expand_button_fill_type": "gradient", "expand_button_fill_preset": "shine", "expand_button_fill_angle": "135", "box_shadow_enabled": "1"}}, "chatgpt_like": {"label": "ChatGPT \u00e4hnlich", "values": {"background_color": "#ffffff", "background_fill_type": "solid", "background_fill_preset": "soft", "background_fill_angle": "135", "header_color": "#f7f7f8", "header_fill_type": "solid", "header_fill_preset": "soft", "header_fill_angle": "135", "button_color": "#111827", "button_fill_type": "solid", "button_fill_preset": "deep", "button_fill_angle": "135", "footer_color": "#ffffff", "footer_fill_type": "solid", "footer_fill_preset": "soft", "footer_fill_angle": "135", "expand_button_color": "#ffffff", "expand_button_fill_type": "solid", "expand_button_fill_preset": "soft", "expand_button_fill_angle": "135", "box_shadow_enabled": "1"}}, "modern_blue_grey": {"label": "Modern Blue-Gray", "values": {"background_color": "#f3f7fb", "background_fill_type": "gradient", "background_fill_preset": "shine", "background_fill_angle": "135", "header_color": "#dbeafe", "header_fill_type": "gradient", "header_fill_preset": "split", "header_fill_angle": "135", "button_color": "#334155", "button_fill_type": "gradient", "button_fill_preset": "deep", "button_fill_angle": "135", "footer_color": "#eef2ff", "footer_fill_type": "gradient", "footer_fill_preset": "soft", "footer_fill_angle": "135", "expand_button_color": "#e2e8f0", "expand_button_fill_type": "gradient", "expand_button_fill_preset": "shine", "expand_button_fill_angle": "135", "box_shadow_enabled": "1"}}};
var designPresetSelect = document.getElementById('bkiai_design_preset');
var applyDesignPresetButton = document.getElementById('bkiai_apply_design_preset');

function updateAllFillPreviews() {
    document.querySelectorAll('.bkiai-fill-preview').forEach(function (preview) {
        var scope = preview.getAttribute('data-scope');
        var colorInput = document.querySelector('input[name="design[' + scope + '_color]"]');
        var typeSelect = document.querySelector('select[name="design[' + scope + '_fill_type]"]');
        var presetSelect = document.querySelector('select[name="design[' + scope + '_fill_preset]"]');
        var angleSelect = document.querySelector('select[name="design[' + scope + '_fill_angle]"]');
        preview.style.background = buildFillPreview(colorInput ? colorInput.value : '#ffffff', typeSelect ? typeSelect.value : 'solid', presetSelect ? presetSelect.value : 'soft', angleSelect ? angleSelect.value : '135');
    });
}

if (applyDesignPresetButton && designPresetSelect) {
    applyDesignPresetButton.addEventListener('click', function () {
        var selected = designPresetSelect.value;
        if (!selected || !designPresets[selected]) {
            return;
        }
        var values = designPresets[selected].values || {};
        Object.keys(values).forEach(function (key) {
            var value = values[key];
            if (key === 'box_shadow_enabled') {
                var shadowCheckbox = document.querySelector('input[name="design[box_shadow_enabled]"]');
                if (shadowCheckbox) {
                    shadowCheckbox.checked = value === '1';
                }
                return;
            }
            var field = document.querySelector('[name="design[' + key + ']"]');
            if (field) {
                field.value = value;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (key.indexOf('_color') !== -1) {
                var targetInput = document.querySelector('input[name="design[' + key + ']"]');
                var targetId = targetInput ? targetInput.id : '';
                if (targetId) {
                    var colorPicker = document.querySelector('.bkiai-color-palette[data-target="' + targetId + '"]');
                    if (colorPicker) {
                        colorPicker.value = value;
                    }
                }
            }
        });
        updateAllFillPreviews();
    });
}

                    var logoSelectButton = document.getElementById('bkiai_logo_select_button');
                    var logoRemoveButton = document.getElementById('bkiai_logo_remove_button');
                    var logoFileInput = document.getElementById('bkiai_logo_file');
                    var logoUrlInput = document.getElementById('bkiai_design_logo_url');
                    var logoRemoveInput = document.getElementById('bkiai_logo_remove');
                    var logoPreview = document.getElementById('bkiai_logo_preview');

                    function setLogoPreview(src) {
                        if (!logoPreview) {
                            return;
                        }
                        if (src) {
                            logoPreview.src = src;
                            logoPreview.classList.remove('is-hidden');
                        } else {
                            logoPreview.src = '';
                            logoPreview.classList.add('is-hidden');
                        }
                    }

                    if (logoSelectButton && logoFileInput) {
                        logoSelectButton.addEventListener('click', function (event) {
                            event.preventDefault();
                            logoFileInput.click();
                        });
                    }

                    if (logoFileInput) {
                        logoFileInput.addEventListener('change', function () {
                            if (logoFileInput.files && logoFileInput.files[0]) {
                                if (logoRemoveInput) {
                                    logoRemoveInput.value = '0';
                                }
            
                    document.querySelectorAll('.bkiai-systemprompt-field').forEach(function (textarea) {
                        var counterId = textarea.getAttribute('data-counter-target');
                        var counter = counterId ? document.getElementById(counterId) : null;
                        if (!counter) {
                            return;
                        }
                        var updateCounter = function () {
                            var count = (textarea.value || '').length;
                            counter.textContent = count + ' characters';
                            counter.classList.toggle('is-warning', count > 2500 && count <= 5000);
                            counter.classList.toggle('is-danger', count > 5000);
                        };
                        textarea.addEventListener('input', updateCounter);
                        updateCounter();
                    });

                    if (logoUrlInput) {
                                    logoUrlInput.value = logoFileInput.files[0].name;
                                }
                                var reader = new FileReader();
                                reader.onload = function (readerEvent) {
                                    setLogoPreview(readerEvent.target.result);
                                };
                                reader.readAsDataURL(logoFileInput.files[0]);
                            }
                        });
                    }

                    if (logoRemoveButton) {
                        logoRemoveButton.addEventListener('click', function (event) {
                            event.preventDefault();
                            if (logoRemoveInput) {
                                logoRemoveInput.value = '1';
                            }
        
                    document.querySelectorAll('.bkiai-systemprompt-field').forEach(function (textarea) {
                        var counterId = textarea.getAttribute('data-counter-target');
                        var counter = counterId ? document.getElementById(counterId) : null;
                        if (!counter) {
                            return;
                        }
                        var updateCounter = function () {
                            var count = (textarea.value || '').length;
                            counter.textContent = count + ' characters';
                            counter.classList.toggle('is-warning', count > 2500 && count <= 5000);
                            counter.classList.toggle('is-danger', count > 5000);
                        };
                        textarea.addEventListener('input', updateCounter);
                        updateCounter();
                    });

                    if (logoUrlInput) {
                                logoUrlInput.value = '';
                            }
                            if (logoFileInput) {
                                logoFileInput.value = '';
                            }
                            setLogoPreview('');
                        });
                    }


                    document.querySelectorAll('.bkiai-systemprompt-field').forEach(function (textarea) {
                        var counterId = textarea.getAttribute('data-counter-target');
                        var counter = counterId ? document.getElementById(counterId) : null;
                        if (!counter) {
                            return;
                        }
                        var updateCounter = function () {
                            var count = (textarea.value || '').length;
                            counter.textContent = count + ' characters';
                            counter.classList.toggle('is-warning', count > 2500 && count <= 5000);
                            counter.classList.toggle('is-danger', count > 5000);
                        };
                        textarea.addEventListener('input', updateCounter);
                        updateCounter();
                    });

                    if (logoUrlInput) {
                        logoUrlInput.addEventListener('input', function () {
                            var value = (logoUrlInput.value || '').trim();
                            if (!value) {
                                setLogoPreview('');
                                return;
                            }
                            if (logoRemoveInput) {
                                logoRemoveInput.value = '0';
                            }
                            if (/^https?:\/\//i.test(value) || value.indexOf('/') === 0) {
                                setLogoPreview(value);
                            }
                        });
                    }
                });
            </script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    function attachImageFieldPreview(config) {
                        if (!config) {
                            return;
                        }

                        var selectButton = document.getElementById(config.selectButtonId);
                        var removeButton = document.getElementById(config.removeButtonId);
                        var fileInput = document.getElementById(config.fileInputId);
                        var urlInput = document.getElementById(config.urlInputId);
                        var removeInput = document.getElementById(config.removeInputId);
                        var preview = document.getElementById(config.previewId);

                        if (!fileInput || !urlInput || !preview) {
                            return;
                        }

                        var setPreview = function (src) {
                            if (src) {
                                preview.src = src;
                                preview.classList.remove('is-hidden');
                            } else {
                                preview.src = '';
                                preview.classList.add('is-hidden');
                            }
                        };

                        if (selectButton) {
                            selectButton.addEventListener('click', function (event) {
                                event.preventDefault();
                                fileInput.click();
                            });
                        }

                        fileInput.addEventListener('change', function () {
                            if (fileInput.files && fileInput.files[0]) {
                                if (removeInput) {
                                    removeInput.value = '0';
                                }
                                urlInput.value = fileInput.files[0].name;
                                var reader = new FileReader();
                                reader.onload = function (readerEvent) {
                                    setPreview(readerEvent.target.result);
                                };
                                reader.readAsDataURL(fileInput.files[0]);
                            }
                        });

                        if (removeButton) {
                            removeButton.addEventListener('click', function (event) {
                                event.preventDefault();
                                if (removeInput) {
                                    removeInput.value = '1';
                                }
                                urlInput.value = '';
                                fileInput.value = '';
                                setPreview('');
                            });
                        }

                        urlInput.addEventListener('input', function () {
                            var value = (urlInput.value || '').trim();
                            if (!value) {
                                setPreview('');
                                return;
                            }
                            if (removeInput) {
                                removeInput.value = '0';
                            }
                            if (/^https?:\/\//i.test(value) || value.indexOf('/') === 0) {
                                setPreview(value);
                            }
                        });
                    }

                    attachImageFieldPreview({
                        selectButtonId: 'bkiai_chat_history_background_select_button',
                        removeButtonId: 'bkiai_chat_history_background_remove_button',
                        fileInputId: 'bkiai_chat_history_background_file',
                        urlInputId: 'bkiai_design_chat_history_background_image_url',
                        removeInputId: 'bkiai_chat_history_background_remove',
                        previewId: 'bkiai_chat_history_background_preview'
                    });
                });
            </script>
        </div>
        <?php
    }


    private function sanitize_recursive_textarea_field($value) {
        if (is_array($value)) {
            return array_map(array($this, 'sanitize_recursive_textarea_field'), $value);
        }

        if (is_scalar($value) || null === $value) {
            return sanitize_textarea_field((string) $value);
        }

        return '';
    }

    private function get_post_array($key) {
        $value = filter_input(INPUT_POST, $key, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

        return is_array($value) ? $value : array();
    }

    private function get_post_text($key, $default = '') {
        $value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW);

        if (null === $value || false === $value) {
            return $default;
        }

        return sanitize_text_field((string) $value);
    }

    private function get_post_textarea($key, $default = '') {
        $value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW);

        if (null === $value || false === $value) {
            return $default;
        }

        return sanitize_textarea_field((string) $value);
    }

    private function get_post_key($key, $default = '') {
        $value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW);

        if (null === $value || false === $value) {
            return $default;
        }

        return sanitize_key((string) $value);
    }

    private function get_get_text($key, $default = '') {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);

        if (null === $value || false === $value) {
            return $default;
        }

        return sanitize_text_field((string) $value);
    }

    private function get_get_key($key, $default = '') {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);

        if (null === $value || false === $value) {
            return $default;
        }

        return sanitize_key((string) $value);
    }

    private function get_uploaded_file_array($field_name) {
        if (!is_string($field_name) || '' === $field_name) {
            return null;
        }

        // This helper is only used from admin save handlers that verify the plugin nonce before calling it.
        if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified in handle_admin_save() before this helper is called.
            return null;
        }

        $file_array = $_FILES[$field_name]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw file metadata is required here and is only read after nonce verification in handle_admin_save().

        return $file_array;
    }

    public function handle_admin_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'bkiai-chat-free'));
        }

        check_admin_referer(self::ADMIN_NONCE_ACTION, 'bkiai_chat_admin_nonce');

        $existing = $this->get_settings();
        $existingBotCount = $this->get_bot_count($existing);
        $postedDesign = $this->sanitize_recursive_textarea_field($this->get_post_array('design'));
        $postedLicense = $this->sanitize_recursive_textarea_field($this->get_post_array('license'));
        $postedPrivacy = $this->sanitize_recursive_textarea_field($this->get_post_array('privacy'));

        $currentPlan = BKiAI_Plan_Manager::get_current_plan();
        $planConfig = BKiAI_Plan_Manager::get_plan_config($currentPlan);
        $canDuplicateBots = !empty($planConfig['can_duplicate_bots']);
        $canDeleteDynamicBots = !empty($planConfig['can_delete_dynamic_bots']);
        $canUseVoice = !empty($planConfig['voice']);
$canUseRealtimeVoice = !empty($planConfig['voice_realtime']);
        $canUseImageGeneration = !empty($planConfig['image_generation']);
$canUsePdfGeneration = !empty($planConfig['pdf_generation']);
        $canUseWebSearch = !empty($planConfig['web_search']);
        $canUseWebsiteKnowledge = !empty($planConfig['website_knowledge']);
        $fullModelAccess = !empty($planConfig['full_model_access']);
        $maxKnowledgeFiles = isset($planConfig['max_knowledge_files']) ? max(1, intval($planConfig['max_knowledge_files'])) : 1;
        $allowedModels = isset($planConfig['allowed_models']) && is_array($planConfig['allowed_models']) ? array_values(array_intersect(array_keys($this->models), $planConfig['allowed_models'])) : array();
        if (empty($allowedModels)) {
            $allowedModels = array('gpt-4o-mini', 'gpt-4.1-mini');
        }

        $uploadedLogoUrl = '';
        $uploadedChatHistoryBackgroundUrl = '';
        $logoRemoveRequested = isset($postedDesign['logo_remove']) && $postedDesign['logo_remove'] === '1';
        $chatHistoryBackgroundRemoveRequested = isset($postedDesign['chat_history_background_image_remove']) && $postedDesign['chat_history_background_image_remove'] === '1';
        $activateLicenseRequested = '1' === $this->get_post_text('activate_license');
        $deactivateLicenseRequested = '1' === $this->get_post_text('deactivate_license');
        $licenseNoticeMessage = '';
        $licenseNoticeType = 'success';

        $logo_file = $this->get_uploaded_file_array('bkiai_logo_file');
        if (is_array($logo_file) && !empty($logo_file['name']) && !empty($logo_file['tmp_name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $attachmentId = media_handle_upload('bkiai_logo_file', 0);

            if (!is_wp_error($attachmentId)) {
                $uploadedLogoUrl = wp_get_attachment_url($attachmentId);
                $logoRemoveRequested = false;
            } else {
                set_transient(self::NOTICE_TRANSIENT_KEY, array(
                    'type' => 'error',
                    'message' => 'The logo could not be uploaded: ' . $attachmentId->get_error_message(),
                ), 60);
            }
        }

        $chat_history_background_file = $this->get_uploaded_file_array('bkiai_chat_history_background_file');
        if (is_array($chat_history_background_file) && !empty($chat_history_background_file['name']) && !empty($chat_history_background_file['tmp_name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $attachmentId = media_handle_upload('bkiai_chat_history_background_file', 0);

            if (!is_wp_error($attachmentId)) {
                $uploadedChatHistoryBackgroundUrl = wp_get_attachment_url($attachmentId);
                $chatHistoryBackgroundRemoveRequested = false;
            } else {
                set_transient(self::NOTICE_TRANSIENT_KEY, array(
                    'type' => 'error',
                    'message' => 'The chat history background image could not be uploaded: ' . $attachmentId->get_error_message(),
                ), 60);
            }
        }

        $duplicateBotRequested = $canDuplicateBots && '1' === $this->get_post_text('duplicate_bot');
        $deleteBotIndex = absint($this->get_post_text('delete_bot', '0'));
        $deleteBotRequested = $canDeleteDynamicBots && $deleteBotIndex > 5 && $deleteBotIndex <= $existingBotCount;
        $activeTab = $this->get_post_key('active_tab', 'general');
        $sourceBotIndex = $existingBotCount;
        if (preg_match('/^bot\-(\d+)$/', $activeTab, $activeTabMatch)) {
            $sourceBotIndex = max(1, min($existingBotCount, intval($activeTabMatch[1])));
        }
        $targetBotCount = $existingBotCount;
        if ($duplicateBotRequested && !$deleteBotRequested) {
            $targetBotCount = min(20, $existingBotCount + 1);
        } elseif ($deleteBotRequested) {
            $targetBotCount = max(5, $existingBotCount - 1);
        }

        $settings = array(
            'api_key' => $this->get_post_text('api_key', $existing['api_key']),
            'bot_count' => $targetBotCount,
            'privacy' => array(
                'log_retention_days' => isset($postedPrivacy['log_retention_days']) ? max(1, min(180, intval($postedPrivacy['log_retention_days']))) : intval($existing['privacy']['log_retention_days']),
            ),
            'design' => array(
                'width' => $this->sanitize_dimension(isset($postedDesign['width']) ? $postedDesign['width'] : $existing['design']['width'], $this->get_default_design()['width']),
                'height' => $this->sanitize_dimension(isset($postedDesign['height']) ? $postedDesign['height'] : $existing['design']['height'], $this->get_default_design()['height']),
                'chat_radius' => $this->sanitize_radius_px(isset($postedDesign['chat_radius']) ? $postedDesign['chat_radius'] : $existing['design']['chat_radius'], $this->get_default_design()['chat_radius']),
                'input_radius' => $this->sanitize_radius_px(isset($postedDesign['input_radius']) ? $postedDesign['input_radius'] : $existing['design']['input_radius'], $this->get_default_design()['input_radius']),
                'background_color' => $this->sanitize_color(isset($postedDesign['background_color']) ? $postedDesign['background_color'] : $existing['design']['background_color'], $this->get_default_design()['background_color']),
                'background_fill_type' => $this->sanitize_fill_type(isset($postedDesign['background_fill_type']) ? $postedDesign['background_fill_type'] : $existing['design']['background_fill_type'], $this->get_default_design()['background_fill_type']),
                'background_fill_preset' => $this->sanitize_fill_preset(isset($postedDesign['background_fill_preset']) ? $postedDesign['background_fill_preset'] : $existing['design']['background_fill_preset'], $this->get_default_design()['background_fill_preset']),
                'background_fill_angle' => $this->sanitize_fill_angle(isset($postedDesign['background_fill_angle']) ? $postedDesign['background_fill_angle'] : $existing['design']['background_fill_angle'], $this->get_default_design()['background_fill_angle']),
                'header_color' => $this->sanitize_color(isset($postedDesign['header_color']) ? $postedDesign['header_color'] : $existing['design']['header_color'], $this->get_default_design()['header_color']),
                'header_fill_type' => $this->sanitize_fill_type(isset($postedDesign['header_fill_type']) ? $postedDesign['header_fill_type'] : $existing['design']['header_fill_type'], $this->get_default_design()['header_fill_type']),
                'header_fill_preset' => $this->sanitize_fill_preset(isset($postedDesign['header_fill_preset']) ? $postedDesign['header_fill_preset'] : $existing['design']['header_fill_preset'], $this->get_default_design()['header_fill_preset']),
                'header_fill_angle' => $this->sanitize_fill_angle(isset($postedDesign['header_fill_angle']) ? $postedDesign['header_fill_angle'] : $existing['design']['header_fill_angle'], $this->get_default_design()['header_fill_angle']),
                'title_text_color' => $this->sanitize_color(isset($postedDesign['title_text_color']) ? $postedDesign['title_text_color'] : $existing['design']['title_text_color'], $this->get_default_design()['title_text_color']),
                'border_width' => $this->sanitize_border_width(isset($postedDesign['border_width']) ? $postedDesign['border_width'] : $existing['design']['border_width'], $this->get_default_design()['border_width']),
                'border_color' => $this->sanitize_color(isset($postedDesign['border_color']) ? $postedDesign['border_color'] : $existing['design']['border_color'], $this->get_default_design()['border_color']),
                'footer_color' => $this->sanitize_color(isset($postedDesign['footer_color']) ? $postedDesign['footer_color'] : $existing['design']['footer_color'], $this->get_default_design()['footer_color']),
                'footer_fill_type' => $this->sanitize_fill_type(isset($postedDesign['footer_fill_type']) ? $postedDesign['footer_fill_type'] : $existing['design']['footer_fill_type'], $this->get_default_design()['footer_fill_type']),
                'footer_fill_preset' => $this->sanitize_fill_preset(isset($postedDesign['footer_fill_preset']) ? $postedDesign['footer_fill_preset'] : $existing['design']['footer_fill_preset'], $this->get_default_design()['footer_fill_preset']),
                'footer_fill_angle' => $this->sanitize_fill_angle(isset($postedDesign['footer_fill_angle']) ? $postedDesign['footer_fill_angle'] : $existing['design']['footer_fill_angle'], $this->get_default_design()['footer_fill_angle']),
                'button_color' => $this->sanitize_color(isset($postedDesign['button_color']) ? $postedDesign['button_color'] : $existing['design']['button_color'], $this->get_default_design()['button_color']),
                'button_fill_type' => $this->sanitize_fill_type(isset($postedDesign['button_fill_type']) ? $postedDesign['button_fill_type'] : $existing['design']['button_fill_type'], $this->get_default_design()['button_fill_type']),
                'button_fill_preset' => $this->sanitize_fill_preset(isset($postedDesign['button_fill_preset']) ? $postedDesign['button_fill_preset'] : $existing['design']['button_fill_preset'], $this->get_default_design()['button_fill_preset']),
                'button_fill_angle' => $this->sanitize_fill_angle(isset($postedDesign['button_fill_angle']) ? $postedDesign['button_fill_angle'] : $existing['design']['button_fill_angle'], $this->get_default_design()['button_fill_angle']),
                'expand_button_color' => $this->sanitize_color(isset($postedDesign['expand_button_color']) ? $postedDesign['expand_button_color'] : $existing['design']['expand_button_color'], $this->get_default_design()['expand_button_color']),
                'expand_button_fill_type' => $this->sanitize_fill_type(isset($postedDesign['expand_button_fill_type']) ? $postedDesign['expand_button_fill_type'] : $existing['design']['expand_button_fill_type'], $this->get_default_design()['expand_button_fill_type']),
                'expand_button_fill_preset' => $this->sanitize_fill_preset(isset($postedDesign['expand_button_fill_preset']) ? $postedDesign['expand_button_fill_preset'] : $existing['design']['expand_button_fill_preset'], $this->get_default_design()['expand_button_fill_preset']),
                'expand_button_fill_angle' => $this->sanitize_fill_angle(isset($postedDesign['expand_button_fill_angle']) ? $postedDesign['expand_button_fill_angle'] : $existing['design']['expand_button_fill_angle'], $this->get_default_design()['expand_button_fill_angle']),
                'reset_text_color' => $this->sanitize_color(isset($postedDesign['reset_text_color']) ? $postedDesign['reset_text_color'] : $existing['design']['reset_text_color'], $this->get_default_design()['reset_text_color']),
                'logo_url' => $logoRemoveRequested ? '' : (!empty($uploadedLogoUrl) ? esc_url_raw($uploadedLogoUrl) : (isset($postedDesign['logo_url']) ? esc_url_raw($postedDesign['logo_url']) : $existing['design']['logo_url'])),
                'chat_history_background_image_url' => $chatHistoryBackgroundRemoveRequested ? '' : (!empty($uploadedChatHistoryBackgroundUrl) ? esc_url_raw($uploadedChatHistoryBackgroundUrl) : (isset($postedDesign['chat_history_background_image_url']) ? esc_url_raw($postedDesign['chat_history_background_image_url']) : (isset($existing['design']['chat_history_background_image_url']) ? $existing['design']['chat_history_background_image_url'] : ''))),
                'show_sources' => isset($postedDesign['show_sources']) ? '1' : '0',
                'box_shadow_enabled' => isset($postedDesign['box_shadow_enabled']) ? '1' : '0',
                'voice_enabled' => ($canUseVoice && isset($postedDesign['voice_enabled'])) ? '1' : '0',
                'voice_realtime_enabled' => ($canUseRealtimeVoice && isset($postedDesign['voice_realtime_enabled'])) ? '1' : '0',
                'voice_reply_gender' => $canUseRealtimeVoice && isset($postedDesign['voice_reply_gender']) && in_array($postedDesign['voice_reply_gender'], array('female', 'male'), true) ? $postedDesign['voice_reply_gender'] : 'female',
                'send_button_text' => isset($postedDesign['send_button_text']) && trim((string) $postedDesign['send_button_text']) !== '' ? sanitize_text_field($postedDesign['send_button_text']) : 'Send',
                'clear_button_text' => isset($postedDesign['clear_button_text']) && trim((string) $postedDesign['clear_button_text']) !== '' ? sanitize_text_field($postedDesign['clear_button_text']) : 'Clear chat',
                'input_placeholder_text' => isset($postedDesign['input_placeholder_text']) && trim((string) $postedDesign['input_placeholder_text']) !== '' ? sanitize_text_field($postedDesign['input_placeholder_text']) : 'Ask any question',
            ),
            'license' => array(
                'key' => isset($postedLicense['key']) ? sanitize_text_field($postedLicense['key']) : (isset($existing['license']['key']) ? $existing['license']['key'] : ''),
                'status' => isset($existing['license']['status']) ? sanitize_text_field($existing['license']['status']) : 'not_connected',
                'plan' => $currentPlan,
                'instance_url' => home_url('/'),
                'last_checked' => isset($existing['license']['last_checked']) ? sanitize_text_field($existing['license']['last_checked']) : '',
            ),
            'bots' => array(),
        );

        if (empty($settings['api_key']) && !empty($existing['api_key'])) {
            $settings['api_key'] = $existing['api_key'];
        }

        if ($deactivateLicenseRequested) {
            $settings['license']['key'] = '';
            $settings['license']['status'] = 'deactivated';
            $settings['license']['last_checked'] = current_time('mysql');
            $licenseNoticeMessage = 'License was deactivated locally. The EDD connection will be added in the next step.';
        } elseif ($activateLicenseRequested) {
            if (!empty($settings['license']['key'])) {
                $settings['license']['status'] = 'activated_placeholder';
                $settings['license']['last_checked'] = current_time('mysql');
                $licenseNoticeMessage = 'License data was stored locally. The real activation against Easy Digital Downloads will be connected next.';
            } else {
                $settings['license']['status'] = 'invalid_placeholder';
                $settings['license']['last_checked'] = current_time('mysql');
                $licenseNoticeMessage = 'Please enter a license key before activating the license.';
                $licenseNoticeType = 'error';
            }
        } else {
            $settings['license']['status'] = !empty($settings['license']['key']) ? 'stored_locally' : 'not_connected';
        }

        $postedBots = $this->sanitize_recursive_textarea_field($this->get_post_array('bots'));
        $deleteFiles = $this->sanitize_recursive_textarea_field($this->get_post_array('delete_files'));
        $knowledgeActive = $this->sanitize_recursive_textarea_field($this->get_post_array('knowledge_active'));
        $uploadCount = 0;
        $deleteCount = 0;

        if ($deleteBotRequested && isset($existing['bots'][$deleteBotIndex]['knowledge_files']) && is_array($existing['bots'][$deleteBotIndex]['knowledge_files'])) {
            foreach ($existing['bots'][$deleteBotIndex]['knowledge_files'] as $deletedBotFile) {
                if (!empty($deletedBotFile['path']) && file_exists($deletedBotFile['path'])) {
                    wp_delete_file($deletedBotFile['path']);
                }
            }
        }

        for ($i = 1; $i <= $targetBotCount; $i++) {
            $sourceIndex = $i;
            if ($deleteBotRequested && $i >= $deleteBotIndex) {
                $sourceIndex = $i + 1;
            }

            $posted = isset($postedBots[$sourceIndex]) && is_array($postedBots[$sourceIndex]) ? $postedBots[$sourceIndex] : array();

            if ($duplicateBotRequested && !$deleteBotRequested && $i === $targetBotCount && $targetBotCount > $existingBotCount) {
                $posted = isset($postedBots[$sourceBotIndex]) && is_array($postedBots[$sourceBotIndex]) ? $postedBots[$sourceBotIndex] : (isset($existing['bots'][$sourceBotIndex]) && is_array($existing['bots'][$sourceBotIndex]) ? $existing['bots'][$sourceBotIndex] : array());
                $sourceIndex = $sourceBotIndex;
            }

            $isBotAccessible = BKiAI_Plan_Manager::is_bot_accessible($i);
            $existingBot = isset($existing['bots'][$sourceIndex]) && is_array($existing['bots'][$sourceIndex]) ? wp_parse_args($existing['bots'][$sourceIndex], $this->get_default_bot($i)) : $this->get_default_bot($i);
            if (!is_array($existingBot['knowledge_files'])) {
                $existingBot['knowledge_files'] = array();
            }
            if (!is_array($existingBot['selected_content_ids'])) {
                $existingBot['selected_content_ids'] = array();
            }
            if (!is_array($existingBot['popup_selected_page_ids'])) {
                $existingBot['popup_selected_page_ids'] = array();
            }

            if (!$isBotAccessible) {
                $settings['bots'][$i] = $existingBot;
                continue;
            }

            $bot = $this->get_default_bot($i);
            $bot['enabled'] = isset($posted['enabled']) ? '1' : '0';
            $bot['title'] = isset($posted['title']) ? sanitize_text_field($posted['title']) : $bot['title'];

            $selectedModel = isset($posted['model'], $this->models[$posted['model']]) ? sanitize_text_field($posted['model']) : $bot['model'];
            if (!$fullModelAccess && !BKiAI_Plan_Manager::is_model_allowed($selectedModel)) {
                $selectedModel = reset($allowedModels);
                if ($selectedModel === false || !isset($this->models[$selectedModel])) {
                    $selectedModel = 'gpt-4o-mini';
                }
            }
            $bot['model'] = $selectedModel;

            $bot['welcome_message'] = isset($posted['welcome_message']) ? sanitize_textarea_field($posted['welcome_message']) : $bot['welcome_message'];
            $bot['system_prompt'] = isset($posted['system_prompt']) ? sanitize_textarea_field($posted['system_prompt']) : $bot['system_prompt'];
            $bot['use_website_content'] = $canUseWebsiteKnowledge && isset($posted['use_website_content']) ? '1' : '0';
            $bot['use_web_search'] = $canUseWebSearch && isset($posted['use_web_search']) ? '1' : '0';
            $bot['website_scope'] = ($canUseWebsiteKnowledge && isset($posted['website_scope']) && $posted['website_scope'] === 'selected') ? 'selected' : 'all';
            $selectedIdsRaw = ($canUseWebsiteKnowledge && isset($posted['selected_content_ids']) && is_array($posted['selected_content_ids'])) ? $posted['selected_content_ids'] : array();
            $bot['selected_content_ids'] = array_values(array_filter(array_map('intval', $selectedIdsRaw)));
            $bot['daily_message_limit'] = isset($posted['daily_message_limit']) ? max(1, intval($posted['daily_message_limit'])) : intval($bot['daily_message_limit']);
            $bot['daily_token_limit'] = isset($posted['daily_token_limit']) ? max(1, intval($posted['daily_token_limit'])) : intval($bot['daily_token_limit']);
            $allowedPopupPositions = array('bottom-right', 'bottom-left', 'top-right', 'top-left');
            $bot['popup_enabled'] = ($i === 1 && isset($posted['popup_enabled'])) ? '1' : '0';
            $bot['popup_position'] = ($i === 1 && isset($posted['popup_position']) && in_array($posted['popup_position'], $allowedPopupPositions, true)) ? sanitize_text_field($posted['popup_position']) : 'bottom-right';
            $bot['popup_page_scope'] = ($i === 1 && isset($posted['popup_page_scope']) && $posted['popup_page_scope'] === 'selected') ? 'selected' : 'all';
            $popupSelectedIdsRaw = ($i === 1 && isset($posted['popup_selected_page_ids']) && is_array($posted['popup_selected_page_ids'])) ? $posted['popup_selected_page_ids'] : array();
            $bot['popup_selected_page_ids'] = ($i === 1) ? array_values(array_filter(array_map('intval', $popupSelectedIdsRaw))) : array();
            $bot['image_generation_enabled'] = ($canUseImageGeneration && isset($posted['image_generation_enabled'])) ? '1' : '0';
            $bot['pdf_generation_enabled'] = ($canUsePdfGeneration && isset($posted['pdf_generation_enabled'])) ? '1' : '0';
            $bot['knowledge_files'] = $existingBot['knowledge_files'];

            if (isset($deleteFiles[$i]) && is_array($deleteFiles[$i])) {
                foreach ($deleteFiles[$i] as $deleteIndex) {
                    $idx = intval($deleteIndex);
                    if (isset($bot['knowledge_files'][$idx])) {
                        if (!empty($bot['knowledge_files'][$idx]['path']) && file_exists($bot['knowledge_files'][$idx]['path'])) {
                            wp_delete_file($bot['knowledge_files'][$idx]['path']);
                        }
                        unset($bot['knowledge_files'][$idx]);
                        $deleteCount++;
                    }
                }
                $bot['knowledge_files'] = array_values($bot['knowledge_files']);
            }

            if (!empty($bot['knowledge_files'])) {
                foreach ($bot['knowledge_files'] as $fileIndex => $fileMeta) {
                    $bot['knowledge_files'][$fileIndex]['active'] = (isset($knowledgeActive[$i]) && isset($knowledgeActive[$i][$fileIndex])) ? '1' : '0';
                }
            }

            $remainingKnowledgeSlots = $maxKnowledgeFiles < 999 ? max(0, $maxKnowledgeFiles - count($bot['knowledge_files'])) : null;
            $newFiles = $this->handle_uploaded_files('knowledge_files_' . $i, $i, $remainingKnowledgeSlots);
            if (!empty($newFiles)) {
                $bot['knowledge_files'] = array_merge($bot['knowledge_files'], $newFiles);
                $uploadCount += count($newFiles);
            }

            $settings['bots'][$i] = $bot;
        }

        $noticeParts = array();
        if ($uploadCount > 0) {
            $noticeParts[] = $uploadCount . ' Knowledge file(en) saved';
        }
        if ($deleteCount > 0) {
            $noticeParts[] = $deleteCount . ' knowledge file(s) deleted';
        }
        if (!empty($noticeParts)) {
            $this->set_admin_notice(implode(' | ', $noticeParts), 'success');
        }
        if ($licenseNoticeMessage !== '') {
            $this->set_admin_notice($licenseNoticeMessage, $licenseNoticeType);
        }

        $this->update_settings($settings);
        $activeTab = $this->get_post_key('active_tab', 'general');
        if ($duplicateBotRequested && !$deleteBotRequested && $targetBotCount > $existingBotCount) {
            $activeTab = 'bot-' . $targetBotCount;
            set_transient(self::NOTICE_TRANSIENT_KEY, array(
                'type' => 'success',
                'message' => 'A new bot was created by duplicating Bot ' . $sourceBotIndex . '.',
            ), 60);
        } elseif ($deleteBotRequested) {
            $activeTab = 'bot-' . min($deleteBotIndex, $targetBotCount);
            if ($targetBotCount <= 5 && $deleteBotIndex <= 5) {
                $activeTab = 'bot-5';
            }
            set_transient(self::NOTICE_TRANSIENT_KEY, array(
                'type' => 'success',
                'message' => 'Bot ' . $deleteBotIndex . ' was deleted.',
            ), 60);
        }

        if (!preg_match('/^(general|license|bot-[0-9]+|bot-add)$/', $activeTab)) {
            $activeTab = 'general';
        }

        wp_safe_redirect(admin_url('options-general.php?page=bkiai-chat&settings-updated=1&active_tab=' . rawurlencode($activeTab)));
        exit;
    }

    private function handle_uploaded_files($fieldName, $botIndex = 0, $maxFiles = null) {
        check_admin_referer(self::ADMIN_NONCE_ACTION, 'bkiai_chat_admin_nonce');

        $files = $this->get_uploaded_file_array($fieldName);
        if (!is_array($files)) {
            return array();
        }
        if (empty($files['name']) || !is_array($files['name'])) {
            return array();
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        list($baseDir, $baseUrl) = $this->get_upload_base_dir();
        $botDir = trailingslashit($baseDir) . 'bot-' . intval($botIndex);
        $botUrl = trailingslashit($baseUrl) . 'bot-' . intval($botIndex);
        if (!file_exists($botDir)) {
            wp_mkdir_p($botDir);
        }

        WP_Filesystem();
        global $wp_filesystem;

        if (!($wp_filesystem instanceof WP_Filesystem_Base)) {
            return array();
        }

        $uploaded = array();
        $count = count($files['name']);

        for ($i = 0; $i < $count; $i++) {
            if ($maxFiles !== null && count($uploaded) >= max(0, intval($maxFiles))) {
                break;
            }
            if (empty($files['name'][$i]) || !empty($files['error'][$i])) {
                continue;
            }

            $originalName = sanitize_file_name($files['name'][$i]);
            $tmpName = isset($files['tmp_name'][$i]) ? (string) $files['tmp_name'][$i] : '';
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, array('md', 'markdown', 'txt', 'csv'), true)) {
                continue;
            }
            if (!is_uploaded_file($tmpName)) {
                continue;
            }

            $fileContents = file_get_contents($tmpName);
            if (false === $fileContents) {
                continue;
            }

            $safeName = wp_unique_filename($botDir, $originalName);
            $destination = trailingslashit($botDir) . $safeName;

            if (!$wp_filesystem->put_contents($destination, $fileContents, FS_CHMOD_FILE)) {
                continue;
            }

            $uploaded[] = array(
                'name' => $originalName,
                'stored_name' => $safeName,
                'path' => $destination,
                'url' => trailingslashit($botUrl) . rawurlencode($safeName),
                'type' => $ext,
                'size' => file_exists($destination) ? filesize($destination) : 0,
                'uploaded_at' => current_time('mysql'),
                'active' => '1',
            );
        }

        return $uploaded;
    }


private function render_chat_markup($botIndex, $bot, $design, $isPopup = false) {
    $popupPosition = isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right';
    $messageHeight = esc_attr($design['height']);
    $wrapperWidth = esc_attr($design['width']);

    if ($isPopup) {
        $messageHeight = '360px';
        $wrapperWidth = '380px';
    }

    $logoUrl = !empty($design['logo_url']) ? esc_url($design['logo_url']) : '';
    $messagesStyle = '';
    if (!empty($design['chat_history_background_image_url'])) {
        $messagesStyle = sprintf(
            "background-image:url('%s');background-size:cover;background-position:center center;background-repeat:no-repeat;",
            esc_url($design['chat_history_background_image_url'])
        );
    }
    $sendButtonText = !empty($design['send_button_text']) ? sanitize_text_field($design['send_button_text']) : 'Send';
    $clearButtonText = !empty($design['clear_button_text']) ? sanitize_text_field($design['clear_button_text']) : 'Clear chat';
    $inputPlaceholderText = !empty($design['input_placeholder_text']) ? sanitize_text_field($design['input_placeholder_text']) : 'Ask any question';

    $expandButtonColor = isset($design['expand_button_color']) ? $design['expand_button_color'] : '#ffffff';
    $expandButtonTextColor = $this->get_contrast_text_color($expandButtonColor);
    $expandButtonBorderColor = $this->adjust_color_brightness($expandButtonColor, -28);

    $backgroundFill = $this->build_fill_css($design['background_color'], isset($design['background_fill_type']) ? $design['background_fill_type'] : 'solid', isset($design['background_fill_preset']) ? $design['background_fill_preset'] : 'soft', isset($design['background_fill_angle']) ? $design['background_fill_angle'] : '135');
    $headerFill = $this->build_fill_css($design['header_color'], isset($design['header_fill_type']) ? $design['header_fill_type'] : 'solid', isset($design['header_fill_preset']) ? $design['header_fill_preset'] : 'soft', isset($design['header_fill_angle']) ? $design['header_fill_angle'] : '135');
    $footerFill = $this->build_fill_css($design['footer_color'], isset($design['footer_fill_type']) ? $design['footer_fill_type'] : 'solid', isset($design['footer_fill_preset']) ? $design['footer_fill_preset'] : 'soft', isset($design['footer_fill_angle']) ? $design['footer_fill_angle'] : '135');
    $buttonFill = $this->build_fill_css($design['button_color'], isset($design['button_fill_type']) ? $design['button_fill_type'] : 'solid', isset($design['button_fill_preset']) ? $design['button_fill_preset'] : 'deep', isset($design['button_fill_angle']) ? $design['button_fill_angle'] : '135');
    $expandFill = $this->build_fill_css($expandButtonColor, isset($design['expand_button_fill_type']) ? $design['expand_button_fill_type'] : 'solid', isset($design['expand_button_fill_preset']) ? $design['expand_button_fill_preset'] : 'soft', isset($design['expand_button_fill_angle']) ? $design['expand_button_fill_angle'] : '135');

    $style = sprintf(
        '--bkiai-bg:%s; --bkiai-header:%s; --bkiai-footer:%s; --bkiai-button:%s; --bkiai-expand-bg:%s; --bkiai-expand-text:%s; --bkiai-expand-border:%s; --bkiai-reset-text:%s; --bkiai-title-text:%s; --bkiai-border-width:%s; --bkiai-border-color:%s; --bkiai-bg-fill:%s; --bkiai-header-fill:%s; --bkiai-footer-fill:%s; --bkiai-button-fill:%s; --bkiai-expand-fill:%s; --bkiai-messages-height:%s; --bkiai-shadow:%s; --bkiai-chat-radius:%s; --bkiai-input-radius:%s; width:%s;',
        esc_attr($design['background_color']),
        esc_attr($design['header_color']),
        esc_attr($design['footer_color']),
        esc_attr($design['button_color']),
        esc_attr($expandButtonColor),
        esc_attr($expandButtonTextColor),
        esc_attr($expandButtonBorderColor),
        esc_attr(isset($design['reset_text_color']) ? $design['reset_text_color'] : '#dc2626'),
        esc_attr(isset($design['title_text_color']) ? $design['title_text_color'] : '#6b7280'),
        esc_attr(intval(isset($design['border_width']) ? $design['border_width'] : 1) . 'px'),
        esc_attr(isset($design['border_color']) ? $design['border_color'] : '#e5e7eb'),
        esc_attr($backgroundFill),
        esc_attr($headerFill),
        esc_attr($footerFill),
        esc_attr($buttonFill),
        esc_attr($expandFill),
        $messageHeight,
        $design['box_shadow_enabled'] === '1' ? '0 8px 30px rgba(15, 23, 42, 0.06)' : 'none',
        intval(isset($design['chat_radius']) ? $design['chat_radius'] : 18) . 'px',
        intval(isset($design['input_radius']) ? $design['input_radius'] : 22) . 'px',
        $wrapperWidth
    );

    ob_start();
    if ($isPopup) :
        $launcherLabel = !empty($bot['title']) ? $bot['title'] : 'BKiAI Chat';
        ?>
        <div class="bkiai-chat-popup-shell bkiai-chat-popup-<?php echo esc_attr($popupPosition); ?>">
            <button type="button" class="bkiai-chat-popup-launcher" aria-expanded="false" aria-controls="bkiai-chat-popup-<?php echo esc_attr((string) $botIndex); ?>">
                <span class="bkiai-chat-popup-launcher-icon">💬</span>
                <span class="bkiai-chat-popup-launcher-text"><?php echo esc_html($launcherLabel); ?></span>
            </button>
            <div id="bkiai-chat-popup-<?php echo esc_attr((string) $botIndex); ?>" class="bkiai-chat-popup-panel is-hidden">
                <div class="bkiai-chat-wrapper bkiai-chat-wrapper-popup" style="<?php echo esc_attr($style); ?>" data-bot-id="<?php echo esc_attr((string) $botIndex); ?>" data-welcome-message="<?php echo esc_attr($bot['welcome_message']); ?>" data-voice-enabled="<?php echo esc_attr($design['voice_enabled']); ?>" data-voice-realtime="<?php echo esc_attr(isset($design['voice_realtime_enabled']) ? $design['voice_realtime_enabled'] : '0'); ?>" data-voice-gender="<?php echo esc_attr(isset($design['voice_reply_gender']) ? $design['voice_reply_gender'] : 'female'); ?>" data-send-button-label="<?php echo esc_attr($sendButtonText); ?>">
                    <div class="bkiai-chat-header">
                        <div class="bkiai-chat-title-group">
                            <?php if ($logoUrl !== '') : ?>
                                <img src="<?php echo esc_url($logoUrl); ?>" class="bkiai-chat-logo" alt="Chat logo" />
                            <?php endif; ?>
                            <span class="bkiai-chat-title"><?php echo esc_html($bot['title']); ?></span>
                        </div>
                        <div class="bkiai-chat-header-actions">
                            <button type="button" class="bkiai-chat-reset" aria-label="<?php echo esc_attr($clearButtonText); ?>" title="<?php echo esc_attr($clearButtonText); ?>"><span class="bkiai-chat-reset-icon">×</span><span class="bkiai-chat-reset-text"><?php echo esc_html($clearButtonText); ?></span></button>
                            <button type="button" class="bkiai-chat-expand" aria-label="Expand chat" title="Expand chat"><span class="bkiai-chat-expand-icon" aria-hidden="true">□</span></button>
                            <button type="button" class="bkiai-chat-popup-toggle" aria-label="Close chat" title="Close chat">−</button>
                        </div>
                    </div>
                    <div class="bkiai-chat-messages"<?php echo $messagesStyle !== "" ? " style=\"" . esc_attr($messagesStyle) . "\"" : ""; ?>>
                        <div class="bkiai-chat-message bkiai-chat-message-bot"><div class="bkiai-chat-message-text"><?php echo esc_html($bot['welcome_message']); ?></div><button type="button" class="bkiai-chat-copy" data-copy-text="<?php echo esc_attr($bot['welcome_message']); ?>" aria-label="Copy">Copy</button></div>
                    </div>
                    <form class="bkiai-chat-form" method="post">
                        <div class="bkiai-chat-input-shell">
                            <textarea class="bkiai-chat-input" rows="2" placeholder="<?php echo esc_attr($inputPlaceholderText); ?>"></textarea>
                            <div class="bkiai-chat-controls">
                                <?php if ($design['voice_enabled'] === '1') : ?>
                                    <button type="button" class="bkiai-chat-voice" aria-label="Start voice input" title="Voice input">🎤</button>
                                <?php endif; ?>
                                <button type="submit" class="bkiai-chat-button"><?php echo esc_html($sendButtonText); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    else :
        ?>
        <div class="bkiai-chat-wrapper" style="<?php echo esc_attr($style); ?>" data-bot-id="<?php echo esc_attr((string) $botIndex); ?>" data-welcome-message="<?php echo esc_attr($bot['welcome_message']); ?>" data-voice-enabled="<?php echo esc_attr($design['voice_enabled']); ?>" data-voice-realtime="<?php echo esc_attr(isset($design['voice_realtime_enabled']) ? $design['voice_realtime_enabled'] : '0'); ?>" data-voice-gender="<?php echo esc_attr(isset($design['voice_reply_gender']) ? $design['voice_reply_gender'] : 'female'); ?>" data-send-button-label="<?php echo esc_attr($sendButtonText); ?>">
            <div class="bkiai-chat-header">
                <div class="bkiai-chat-title-group">
                    <?php if ($logoUrl !== '') : ?>
                        <img src="<?php echo esc_url($logoUrl); ?>" class="bkiai-chat-logo" alt="Chat logo" />
                    <?php endif; ?>
                    <span class="bkiai-chat-title"><?php echo esc_html($bot['title']); ?></span>
                </div>
                <div class="bkiai-chat-header-actions">
                    <button type="button" class="bkiai-chat-reset" aria-label="<?php echo esc_attr($clearButtonText); ?>" title="<?php echo esc_attr($clearButtonText); ?>"><span class="bkiai-chat-reset-icon">×</span><span class="bkiai-chat-reset-text"><?php echo esc_html($clearButtonText); ?></span></button>
                    <button type="button" class="bkiai-chat-expand" aria-label="Expand chat" title="Expand chat"><span class="bkiai-chat-expand-icon" aria-hidden="true">□</span></button>
                </div>
            </div>
            <div class="bkiai-chat-messages"<?php echo $messagesStyle !== "" ? " style=\"" . esc_attr($messagesStyle) . "\"" : ""; ?>>
                <div class="bkiai-chat-message bkiai-chat-message-bot"><div class="bkiai-chat-message-text"><?php echo esc_html($bot['welcome_message']); ?></div><button type="button" class="bkiai-chat-copy" data-copy-text="<?php echo esc_attr($bot['welcome_message']); ?>" aria-label="Copy">Copy</button></div>
            </div>
            <form class="bkiai-chat-form" method="post">
                <div class="bkiai-chat-input-shell">
                    <textarea class="bkiai-chat-input" rows="2" placeholder="<?php echo esc_attr($inputPlaceholderText); ?>"></textarea>
                    <div class="bkiai-chat-controls">
                        <?php if ($design['voice_enabled'] === '1') : ?>
                            <button type="button" class="bkiai-chat-voice" aria-label="Start voice input" title="Voice input">🎤</button>
                        <?php endif; ?>
                        <button type="submit" class="bkiai-chat-button"><?php echo esc_html($sendButtonText); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    endif;
    return ob_get_clean();
}

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'bot' => '1',
        ), $atts, self::SHORTCODE);

        $settings = $this->get_settings();
        $botIndex = max(1, min($this->get_bot_count($settings), intval($atts['bot'])));
        $bot = $settings['bots'][$botIndex];

        if ($bot['enabled'] !== '1') {
            return '<div class="bkiai-chat-disabled">This bot is currently inactive.</div>';
        }

        $isPopup = ($botIndex === 1 && isset($bot['popup_enabled']) && $bot['popup_enabled'] === '1');
        if ($isPopup) {
            return '';
        }

        $this->enqueue_frontend_assets();
        return $this->render_chat_markup($botIndex, $bot, $settings['design'], false);
    }

    public function render_global_popup() {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        $bot = isset($settings['bots'][1]) ? $settings['bots'][1] : $this->get_default_bot(1);
        if ($bot['enabled'] !== '1' || empty($bot['popup_enabled']) || $bot['popup_enabled'] !== '1') {
            return;
        }

        if (!$this->should_render_popup_on_current_page($bot)) {
            return;
        }

        echo $this->render_chat_markup(1, $bot, $settings['design'], true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is generated internally.
    }


private function prepare_chat_request_payload() {
    check_ajax_referer(self::NONCE_ACTION, 'nonce');
    $settings = $this->get_settings();
    $message = $this->get_post_textarea('message');
    $botIndex = max(1, min($this->get_bot_count($settings), absint($this->get_post_text('bot_id', '1'))));
    $history_json = $this->get_post_textarea('history');
    $history = '' !== $history_json ? json_decode($history_json, true) : array();
    $pageUrl = esc_url_raw($this->get_post_textarea('current_url'));
    $pageTitle = $this->get_post_text('page_title');

    if (empty($message)) {
        return $this->create_chat_error('bkiai_chat_empty_message', 'The message is empty.', 400);
    }

    if (empty($settings['api_key'])) {
        return $this->create_chat_error('bkiai_chat_missing_api_key', 'No API key has been saved.', 500);
    }

    $bot = $settings['bots'][$botIndex];
    if ($bot['enabled'] !== '1') {
        return $this->create_chat_error('bkiai_chat_bot_inactive', 'This bot is not active.', 400);
    }

    $rateCheck = $this->check_rate_limits($botIndex, $bot, $message);
    if (is_wp_error($rateCheck)) {
        return $rateCheck;
    }

    return array(
        'settings' => $settings,
        'message' => $message,
        'bot_index' => $botIndex,
        'bot' => $bot,
        'history' => is_array($history) ? $this->sanitize_recursive_textarea_field($history) : array(),
        'page_url' => $pageUrl,
        'page_title' => $pageTitle,
        'image_request' => (BKiAI_Plan_Manager::can_use_feature('image_generation') && !empty($bot['image_generation_enabled']) && $bot['image_generation_enabled'] === '1' && $this->is_image_generation_request($message)),
        'pdf_request' => (BKiAI_Plan_Manager::can_use_feature('pdf_generation') && !empty($bot['pdf_generation_enabled']) && $bot['pdf_generation_enabled'] === '1' && !$this->is_image_generation_request($message) && $this->is_pdf_generation_request($message)),
    );
}

private function prepare_streaming_headers() {
    if (!headers_sent()) {
        nocache_headers();
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    ob_implicit_flush(true);
}

private function stream_json_line($payload) {
    echo wp_json_encode($payload) . "\n";
    @flush();
}

private function build_openai_request_body($bot, $message, $history, &$sourceHints = array()) {
    $input = array();
    $systemText = $bot['system_prompt'];
    $sourceHints = array();

    if ($this->get_relevant_knowledge_files_text($bot, $message) !== '') {
        $sourceHints[] = 'Knowledge file';
    }
    if ($bot['use_website_content'] === '1' && $this->get_relevant_website_context($bot, $message) !== '') {
        $sourceHints[] = 'Website';
    }

    $contextBlocks = $this->build_context_blocks($bot, $message);
    if (!empty($contextBlocks)) {
        $systemText .= "\n\nUse this additional context when helpful. If the information is insufficient, state that clearly.\n\n" . implode("\n\n", $contextBlocks);
    }

    $input[] = array(
        'role' => 'system',
        'content' => array(
            array(
                'type' => 'input_text',
                'text' => $systemText,
            ),
        ),
    );

    $input = array_merge($input, $this->normalize_history($history));
    $input[] = array(
        'role' => 'user',
        'content' => array(
            array(
                'type' => 'input_text',
                'text' => $message,
            ),
        ),
    );

    $body = array(
        'model' => $bot['model'],
        'input' => $input,
    );

    if ($bot['use_web_search'] === '1') {
        $body['tools'] = array(
            array('type' => 'web_search_preview'),
        );
        $body['include'] = array('web_search_call.action.sources');
        $sourceHints[] = 'Web search';
    }

    return $body;
}

private function stream_text_response($apiKey, $bot, $message, $history, $pageUrl, $pageTitle, $botIndex) {
    $response = $this->call_openai($apiKey, $bot, $message, $history);

    if (is_wp_error($response)) {
        $this->stream_json_line(array(
            'type' => 'error',
            'message' => $response->get_error_message(),
            'error_code' => $response->get_error_code(),
        ));
        return;
    }

    $replyText = isset($response['reply']) ? (string) $response['reply'] : '';
    $this->increase_rate_usage($botIndex, $message, $replyText);
    $this->log_chat_interaction($botIndex, $message, $replyText, $pageUrl, $pageTitle);
    $this->stream_json_line(array(
        'type' => 'final',
        'reply' => $replyText,
        'sources' => isset($response['sources']) ? $response['sources'] : array(),
        'image_url' => isset($response['image_url']) ? $response['image_url'] : '',
        'image_alt' => isset($response['image_alt']) ? $response['image_alt'] : '',
        'pdf_url' => isset($response['pdf_url']) ? $response['pdf_url'] : '',
        'pdf_filename' => isset($response['pdf_filename']) ? $response['pdf_filename'] : '',
    ));
}


private function get_realtime_voice_name($voiceGender) {
    $voiceGender = strtolower(trim((string) $voiceGender));
    if ($voiceGender === 'male') {
        return 'cedar';
    }
    return 'marin';
}

private function build_realtime_session_config($bot, $voiceGender) {
    $systemPrompt = isset($bot['system_prompt']) ? trim((string) $bot['system_prompt']) : '';
    $title = isset($bot['title']) ? trim((string) $bot['title']) : 'BKiAI Chat';
    $instructionsParts = array();

    if ($systemPrompt !== '') {
        $instructionsParts[] = $systemPrompt;
    } else {
        $instructionsParts[] = 'You are a helpful AI assistant on a website.';
    }

    $instructionsParts[] = 'You are speaking to the user in a live voice conversation inside a website chat widget named "' . $title . '".';
    $instructionsParts[] = 'Always reply in fluent Standard German (Hochdeutsch). Do not switch to English. Do not use dialect spellings. Do not imitate accents or regional dialects.';
    $instructionsParts[] = 'Reply in a natural, warm, confident voice. Speak briskly and conversationally, similar to a real-time assistant, and keep answers concise unless the user explicitly asks for more detail.';
    $instructionsParts[] = 'Do not mention internal tools, system settings, or API details.';

    return array(
        'type' => 'realtime',
        'model' => 'gpt-realtime',
        'instructions' => implode("\n\n", $instructionsParts),
        'output_modalities' => array('audio'),
        'max_output_tokens' => 'inf',
        'audio' => array(
            'input' => array(
                'noise_reduction' => array(
                    'type' => 'near_field',
                ),
                'transcription' => array(
                    'model' => 'gpt-4o-mini-transcribe',
                    'language' => 'de',
                    'prompt' => 'Expect fluent Standard German (Hochdeutsch) without dialect spellings.',
                ),
                'turn_detection' => array(
                    'type' => 'server_vad',
                    'create_response' => true,
                    'interrupt_response' => true,
                ),
            ),
            'output' => array(
                'voice' => $this->get_realtime_voice_name($voiceGender),
                'speed' => 0.96,
            ),
        ),
    );
}

private function build_multipart_form_body($fields, &$contentType) {
    $boundary = '----bkiaiBoundary' . wp_generate_password(24, false, false);
    $eol = "\r\n";
    $body = '';

    foreach ($fields as $name => $value) {
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
        $body .= (string) $value . $eol;
    }

    $body .= '--' . $boundary . '--' . $eol;
    $contentType = 'multipart/form-data; boundary=' . $boundary;

    return $body;
}

private function create_realtime_answer_sdp($apiKey, $sessionConfig, $sdpOffer) {
    $contentType = '';
    $multipartBody = $this->build_multipart_form_body(array(
        'sdp' => $sdpOffer,
        'session' => wp_json_encode($sessionConfig),
    ), $contentType);

    $response = wp_remote_post('https://api.openai.com/v1/realtime/calls', array(
        'timeout' => 35,
        'headers' => array(
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => $contentType,
        ),
        'body' => $multipartBody,
    ));

    if (is_wp_error($response)) {
        return new WP_Error('bkiai_realtime_request_failed', 'The live voice connection to OpenAI failed.');
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    $trimmedBody = trim((string) $body);
    $decoded = json_decode($trimmedBody, true);

    if ($status >= 400 || $trimmedBody === '' || is_array($decoded)) {
        $message = 'The live voice session could not be created.';
        if (is_array($decoded) && !empty($decoded['error']['message'])) {
            $message = sanitize_text_field($decoded['error']['message']);
        }
        return new WP_Error('bkiai_realtime_session_failed', $message, array('status' => max(400, $status)));
    }

    return (string) $body;
}

public function handle_realtime_offer() {
    check_ajax_referer(self::NONCE_ACTION, 'nonce');

    $settings = $this->get_settings();
    $botIndex = max(1, min($this->get_bot_count($settings), absint($this->get_post_text('bot_id', '1'))));
    $sdpOffer = $this->get_post_textarea('sdp');
    $bot = isset($settings['bots'][$botIndex]) ? $settings['bots'][$botIndex] : $this->get_default_bot($botIndex);

    if (empty($settings['api_key'])) {
        wp_send_json_error(array(
            'message' => 'Please save your OpenAI API key before starting live voice mode.',
            'error_code' => 'bkiai_missing_api_key',
        ), 400);
    }

    if ($bot['enabled'] !== '1') {
        wp_send_json_error(array(
            'message' => 'This bot is currently disabled.',
            'error_code' => 'bkiai_bot_disabled',
        ), 400);
    }

    if (empty($settings['design']['voice_enabled']) || empty($settings['design']['voice_realtime_enabled'])) {
        wp_send_json_error(array(
            'message' => 'Live voice mode is not enabled in the plugin settings.',
            'error_code' => 'bkiai_voice_mode_disabled',
        ), 400);
    }

    if (trim($sdpOffer) === '') {
        wp_send_json_error(array(
            'message' => 'No session offer was received from the browser.',
            'error_code' => 'bkiai_missing_sdp_offer',
        ), 400);
    }

    $voiceGender = !empty($settings['design']['voice_reply_gender']) ? $settings['design']['voice_reply_gender'] : 'female';
    $sessionConfig = $this->build_realtime_session_config($bot, $voiceGender);
    $answerSdp = $this->create_realtime_answer_sdp($settings['api_key'], $sessionConfig, $sdpOffer);

    if (is_wp_error($answerSdp)) {
        $errorData = $answerSdp->get_error_data();
        $status = (is_array($errorData) && !empty($errorData['status'])) ? max(400, intval($errorData['status'])) : 500;
        wp_send_json_error(array(
            'message' => $answerSdp->get_error_message(),
            'error_code' => $answerSdp->get_error_code(),
        ), $status);
    }

    status_header(200);
    header('Content-Type: application/sdp; charset=utf-8');
    echo esc_textarea((string) $answerSdp);
    exit;
}

public function handle_chat_stream_request() {
    check_ajax_referer(self::NONCE_ACTION, 'nonce');
    $prepared = $this->prepare_chat_request_payload();

    if (is_wp_error($prepared)) {
        $this->prepare_streaming_headers();
        $this->stream_json_line(array(
            'type' => 'error',
            'message' => $prepared->get_error_message(),
            'error_code' => $prepared->get_error_code(),
        ));
        exit;
    }

    $this->prepare_streaming_headers();

    if (!empty($prepared['image_request'])) {
        $response = $this->call_openai_image($prepared['settings']['api_key'], $prepared['message'], $prepared['bot']);
        if (is_wp_error($response)) {
            $this->stream_json_line(array(
                'type' => 'error',
                'message' => $response->get_error_message(),
                'error_code' => $response->get_error_code(),
            ));
            exit;
        }

        $replyText = isset($response['reply']) ? $response['reply'] : '';
        $this->increase_rate_usage($prepared['bot_index'], $prepared['message'], $replyText);
        $this->log_chat_interaction($prepared['bot_index'], $prepared['message'], $replyText, $prepared['page_url'], $prepared['page_title']);
        $this->stream_json_line(array(
            'type' => 'final',
            'reply' => $replyText,
            'sources' => isset($response['sources']) ? $response['sources'] : array('Image generation'),
            'image_url' => isset($response['image_url']) ? $response['image_url'] : '',
            'image_alt' => isset($response['image_alt']) ? $response['image_alt'] : '',
            'pdf_url' => '',
            'pdf_filename' => '',
        ));
        exit;
    }

    if (!empty($prepared['pdf_request'])) {
        $response = $this->handle_pdf_generation_request($prepared);
        if (is_wp_error($response)) {
            $this->stream_json_line(array(
                'type' => 'error',
                'message' => $response->get_error_message(),
                'error_code' => $response->get_error_code(),
            ));
            exit;
        }

        $replyText = isset($response['reply']) ? $response['reply'] : '';
        $this->increase_rate_usage($prepared['bot_index'], $prepared['message'], $replyText);
        $this->log_chat_interaction($prepared['bot_index'], $prepared['message'], $replyText, $prepared['page_url'], $prepared['page_title']);
        $this->stream_json_line(array(
            'type' => 'final',
            'reply' => $replyText,
            'sources' => isset($response['sources']) ? $response['sources'] : array(),
            'image_url' => '',
            'image_alt' => '',
            'pdf_url' => isset($response['pdf_url']) ? $response['pdf_url'] : '',
            'pdf_filename' => isset($response['pdf_filename']) ? $response['pdf_filename'] : '',
        ));
        exit;
    }

    $this->stream_text_response(
        $prepared['settings']['api_key'],
        $prepared['bot'],
        $prepared['message'],
        $prepared['history'],
        $prepared['page_url'],
        $prepared['page_title'],
        $prepared['bot_index']
    );
    exit;
}


public function handle_chat_request() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $prepared = $this->prepare_chat_request_payload();

        if (is_wp_error($prepared)) {
            $errorData = $prepared->get_error_data();
            $status = 500;
            if (is_array($errorData) && !empty($errorData['status'])) {
                $status = max(400, intval($errorData['status']));
            }
            wp_send_json_error(array(
                'message' => $prepared->get_error_message(),
                'error_code' => $prepared->get_error_code(),
            ), $status);
        }

        if (!empty($prepared['image_request'])) {
            $response = $this->call_openai_image($prepared['settings']['api_key'], $prepared['message'], $prepared['bot']);
        } else {
            if (!empty($prepared['pdf_request'])) {
                $response = $this->handle_pdf_generation_request($prepared);
            } else {
                $response = $this->call_openai($prepared['settings']['api_key'], $prepared['bot'], $prepared['message'], $prepared['history']);
            }
        }

        if (is_wp_error($response)) {
            $errorData = $response->get_error_data();
            $status = 500;
            if (is_array($errorData) && !empty($errorData['status'])) {
                $status = max(400, intval($errorData['status']));
            }
            wp_send_json_error(array(
                'message' => $response->get_error_message(),
                'error_code' => $response->get_error_code(),
            ), $status);
        }

        $replyText = isset($response['reply']) ? $response['reply'] : '';
        $this->increase_rate_usage($prepared['bot_index'], $prepared['message'], $replyText);
        $this->log_chat_interaction($prepared['bot_index'], $prepared['message'], $replyText, $prepared['page_url'], $prepared['page_title']);
        wp_send_json_success(array(
            'reply' => $replyText,
            'sources' => isset($response['sources']) ? $response['sources'] : (!empty($prepared['image_request']) ? array('Image generation') : array()),
            'image_url' => isset($response['image_url']) ? $response['image_url'] : '',
            'image_alt' => isset($response['image_alt']) ? $response['image_alt'] : '',
            'pdf_url' => isset($response['pdf_url']) ? $response['pdf_url'] : '',
            'pdf_filename' => isset($response['pdf_filename']) ? $response['pdf_filename'] : '',
        ));
    }

    private function get_visitor_key() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return md5($ip . '|' . wp_salt('auth'));
    }

    private function get_usage_key($botIndex) {
        return 'bkiai_chat_usage_' . intval($botIndex) . '_' . $this->get_visitor_key() . '_' . gmdate('Ymd');
    }

    private function estimate_tokens($text) {
        $text = (string) $text;
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        return max(1, (int) ceil($length / 4));
    }

    private function check_rate_limits($botIndex, $bot, $message) {
        $usageKey = $this->get_usage_key($botIndex);
        $usage = get_transient($usageKey);
        if (!is_array($usage)) {
            $usage = array('messages' => 0, 'tokens' => 0);
        }

        $projectedTokens = $usage['tokens'] + $this->estimate_tokens($message);
        if ($usage['messages'] >= intval($bot['daily_message_limit'])) {
            return new WP_Error('bkiai_chat_rate_messages', 'The daily message limit has been reached.');
        }
        if ($projectedTokens > intval($bot['daily_token_limit'])) {
            return new WP_Error('bkiai_chat_rate_tokens', 'The daily token limit has been reached.');
        }

        return true;
    }

    private function increase_rate_usage($botIndex, $message, $reply) {
        $usageKey = $this->get_usage_key($botIndex);
        $usage = get_transient($usageKey);
        if (!is_array($usage)) {
            $usage = array('messages' => 0, 'tokens' => 0);
        }

        $usage['messages'] += 1;
        $usage['tokens'] += $this->estimate_tokens($message) + $this->estimate_tokens($reply);
        set_transient($usageKey, $usage, DAY_IN_SECONDS + HOUR_IN_SECONDS);
    }

    private function build_context_blocks($bot, $message) {
        $blocks = array();

        $fileContext = $this->get_relevant_knowledge_files_text($bot, $message);
        if (!empty($fileContext)) {
            $blocks[] = "Knowledge base from uploaded files:\n" . $fileContext;
        }

        if ($bot['use_website_content'] === '1') {
            $siteContext = $this->get_relevant_website_context($bot, $message);
            if (!empty($siteContext)) {
                $blocks[] = "Relevante Inhalte der Website:\n" . $siteContext;
            }
        }

        return $blocks;
    }

    private function extract_keywords($text) {
        $text = strtolower(wp_strip_all_tags($text));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text);
        if (!is_array($parts)) {
            return array();
        }
        $parts = array_filter($parts, function ($word) {
            return $word !== '' && (function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word)) >= 3;
        });
        return array_values(array_unique($parts));
    }

    private function score_text($text, $keywords) {
        $haystack = strtolower(wp_strip_all_tags($text));
        $score = 0;
        foreach ($keywords as $keyword) {
            $score += substr_count($haystack, strtolower($keyword));
        }
        return $score;
    }

    private function trim_text($text, $maxLength = 2500) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return '';
        }
        if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) <= $maxLength) {
            return $text;
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength, 'UTF-8') . ' …' : substr($text, 0, $maxLength) . ' …';
    }

    private function get_relevant_website_context($bot, $message) {
        $keywords = $this->extract_keywords($message);
        $queryArgs = array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            'numberposts' => 60,
            'orderby' => 'date',
            'order' => 'DESC',
        );
        if (isset($bot['website_scope']) && $bot['website_scope'] === 'selected' && !empty($bot['selected_content_ids']) && is_array($bot['selected_content_ids'])) {
            $queryArgs['post__in'] = array_values(array_filter(array_map('intval', $bot['selected_content_ids'])));
            $queryArgs['orderby'] = 'post__in';
            $queryArgs['numberposts'] = count($queryArgs['post__in']);
        }
        $posts = get_posts($queryArgs);

        if (empty($posts)) {
            return '';
        }

        $scored = array();
        foreach ($posts as $post) {
            $combined = $post->post_title . "\n" . $post->post_content;
            $score = !empty($keywords) ? $this->score_text($combined, $keywords) : 1;
            if ($score > 0) {
                $scored[] = array(
                    'score' => $score,
                    'title' => get_the_title($post),
                    'url' => get_permalink($post),
                    'text' => $this->trim_text($combined, 1200),
                );
            }
        }

        if (empty($scored)) {
            return '';
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $top = array_slice($scored, 0, 5);
        $parts = array();
        foreach ($top as $item) {
            $parts[] = '- ' . $item['title'] . ' (' . $item['url'] . ")\n" . $item['text'];
        }

        return implode("\n\n", $parts);
    }

    private function get_relevant_knowledge_files_text($bot, $message) {
        if (empty($bot['knowledge_files']) || !is_array($bot['knowledge_files'])) {
            return '';
        }

        $keywords = $this->extract_keywords($message);
        $matches = array();

        foreach ($bot['knowledge_files'] as $file) {
            if (isset($file['active']) && $file['active'] !== '1') {
                continue;
            }
            if (empty($file['path']) || !file_exists($file['path'])) {
                continue;
            }
            $content = @file_get_contents($file['path']);
            if ($content === false || $content === '') {
                continue;
            }
            $content = $this->trim_text($content, 2200);
            $score = !empty($keywords) ? $this->score_text($content, $keywords) : 1;
            if ($score > 0) {
                $matches[] = array(
                    'score' => $score,
                    'name' => isset($file['name']) ? $file['name'] : 'File',
                    'text' => $content,
                );
            }
        }

        if (empty($matches)) {
            return '';
        }

        usort($matches, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $top = array_slice($matches, 0, 3);
        $parts = array();
        foreach ($top as $item) {
            $parts[] = '- ' . $item['name'] . ":\n" . $item['text'];
        }

        return implode("\n\n", $parts);
    }

    private function normalize_history($history) {
        if (!is_array($history)) {
            return array();
        }

        $normalized = array();
        foreach ($history as $item) {
            if (!is_array($item) || empty($item['role']) || !isset($item['text'])) {
                continue;
            }
            $role = $item['role'] === 'assistant' ? 'assistant' : 'user';
            $text = sanitize_textarea_field($item['text']);
            if ($text === '') {
                continue;
            }
            $content_type = $role === 'assistant' ? 'output_text' : 'input_text';
            $normalized[] = array(
                'role' => $role,
                'content' => array(
                    array(
                        'type' => $content_type,
                        'text' => $text,
                    ),
                ),
            );
        }

        if (count($normalized) > self::MAX_HISTORY_MESSAGES) {
            $normalized = array_slice($normalized, -self::MAX_HISTORY_MESSAGES);
        }

        return $normalized;
    }



    private function is_image_generation_request($message) {
    $text = function_exists('mb_strtolower') ? mb_strtolower(wp_strip_all_tags((string) $message), 'UTF-8') : strtolower(wp_strip_all_tags((string) $message));
    if ($text === '') {
        return false;
    }

    if (preg_match('/^\s*\/(?:bild|image)\b/iu', $text)) {
        return true;
    }

    $hasVisualNoun = preg_match('/\b(bild|bilder|foto|grafik|illustration|logo|icon|ikon|symbol|poster|banner|cover|mockup|visual|image|images|picture|pictures|thumbnail|piktogramm|piktogram|piktogramme|emblem|svg|png|chatbot-grafik|chatbotgrafik)\b/u', $text);
    $hasCreateVerb = preg_match('/\b(generier|generiere|erstell|erstelle|erzeuge|zeichne|entwerf|entwerfe|designe|mach|mache|make|create|generate|draw|design)\b/u', $text);

    if ($hasVisualNoun && $hasCreateVerb) {
        return true;
    }

    if (preg_match('/\b(piktogramm|piktogram|piktogramme|logo|icon|ikon|emblem)\b/u', $text) && preg_match('/\b(für|mit|von|als)\b/u', $text)) {
        return true;
    }

    return false;
}

    private function sanitize_image_generation_prompt($message) {
    $prompt = trim(wp_strip_all_tags((string) $message));
    $prompt = preg_replace('/^\s*\/(?:bild|image)\b[:\-\s]*/iu', '', $prompt);
    $prompt = preg_replace('/^\s*(?:bitte\s+)?(?:generier(?:e)?|erstell(?:e)?|erzeuge|zeichne|entwerf(?:e)?|designe|mach(?:e)?|make|create|generate|draw|design)\s+(?:mir\s+)?/iu', '', $prompt);
    $prompt = trim($prompt);
    return $prompt !== '' ? $prompt : trim(wp_strip_all_tags((string) $message));
}

private function sanitize_pdf_request_message($message) {
    $text = trim((string) $message);
    if ($text === '') {
        return '';
    }

    $clean = preg_replace('/^\s*\/pdf\b[:\-\s]*/iu', '', $text);
    $clean = preg_replace('/\b(?:bitte\s+)?(?:pack(?:e)?|exportier(?:e)?|generier(?:e)?|erstell(?:e)?|erzeuge|konvertier(?:e)?|speicher(?:e)?|mach(?:e)?|create|generate|export|save|convert|make)\b/iu', ' ', $clean);
    $clean = preg_replace('/\b(?:mir|mich|uns|das|dies|diese|deine|deiner|deinen|deinem|deines|your|the|this|that)\b/iu', ' ', $clean);
    $clean = preg_replace('/\b(?:antwort|ergebnis|reply|response|result|download)\b/iu', ' ', $clean);
    $clean = preg_replace('/\b(?:als|as|into|to|in|zum|zur)\b/iu', ' ', $clean);
    $clean = preg_replace('/\b(?:eine|ein|einem|einen|a)\b/iu', ' ', $clean);
    $clean = preg_replace('/\bpdf\b/iu', ' ', $clean);
    $clean = preg_replace('/[\s,:;\-]+/u', ' ', $clean);
    return trim($clean);
}

private function get_last_assistant_reply_from_history($history) {
    if (!is_array($history)) {
        return '';
    }

    for ($i = count($history) - 1; $i >= 0; $i--) {
        $entry = isset($history[$i]) && is_array($history[$i]) ? $history[$i] : array();
        $role = isset($entry['role']) ? sanitize_key((string) $entry['role']) : '';
        if ($role !== 'assistant') {
            continue;
        }

        $content = '';
        if (isset($entry['content']) && is_string($entry['content'])) {
            $content = $entry['content'];
        } elseif (isset($entry['text']) && is_string($entry['text'])) {
            $content = $entry['text'];
        }

        $content = trim(wp_strip_all_tags((string) $content));
        if ($content !== '') {
            return $content;
        }
    }

    return '';
}

private function should_export_previous_answer_as_pdf($message, $history) {
    $messageText = function_exists('mb_strtolower') ? mb_strtolower(wp_strip_all_tags((string) $message), 'UTF-8') : strtolower(wp_strip_all_tags((string) $message));
    $lastAssistantReply = $this->get_last_assistant_reply_from_history($history);
    if ($lastAssistantReply === '') {
        return false;
    }

    $cleanMessage = $this->sanitize_pdf_request_message($messageText);
    if ($cleanMessage === '') {
        return true;
    }

    if (preg_match('/\b(letzte|letzten|vorige|vorherige|previous|last|deine|your)\b/u', $messageText) && preg_match('/\b(antwort|ergebnis|reply|response|result)\b/u', $messageText)) {
        return true;
    }

    return false;
}

private function create_uploaded_export_file($binary, $filename, $subdirectory, $mimeType = 'application/octet-stream') {
    $binary = (string) $binary;
    $filename = sanitize_file_name((string) $filename);
    if ($binary === '' || $filename === '') {
        return $this->create_chat_error('bkiai_chat_export_failed', 'The export file could not be prepared.', 500);
    }

    $uploadDir = wp_upload_dir();
    if (!empty($uploadDir['error'])) {
        return $this->create_chat_error('bkiai_chat_export_upload_dir_error', 'The WordPress uploads directory is not available right now.', 500);
    }

    $targetDir = trailingslashit($uploadDir['basedir']) . trim((string) $subdirectory, '/\\') . '/';
    $targetUrlBase = trailingslashit($uploadDir['baseurl']) . trim((string) $subdirectory, '/\\') . '/';

    if (!wp_mkdir_p($targetDir)) {
        return $this->create_chat_error('bkiai_chat_export_directory_failed', 'The export directory could not be created.', 500);
    }

    $filenameOnDisk = wp_unique_filename($targetDir, $filename);
    $filePath = $targetDir . $filenameOnDisk;
    $writtenBytes = file_put_contents($filePath, $binary);
    if ($writtenBytes === false || $writtenBytes <= 0) {
        return $this->create_chat_error('bkiai_chat_export_write_failed', 'The export file could not be written.', 500);
    }

    return array(
        'path' => $filePath,
        'url' => $targetUrlBase . rawurlencode($filenameOnDisk),
        'filename' => $filenameOnDisk,
        'mime_type' => $mimeType,
    );
}

private function build_image_filename($bot = array()) {
    $base = !empty($bot['title']) ? sanitize_title((string) $bot['title']) : 'bkiai-image';
    if ($base === '') {
        $base = 'bkiai-image';
    }
    return $base . '-' . gmdate('Ymd-His') . '.png';
}

private function create_generated_image_file($imageData, $bot = array()) {
    $binary = base64_decode((string) $imageData, true);
    if ($binary === false || $binary === '') {
        return $this->create_chat_error('bkiai_chat_image_decode_failed', 'The generated image could not be prepared for download.', 500);
    }

    return $this->create_uploaded_export_file($binary, $this->build_image_filename($bot), 'bkiai-chat-images', 'image/png');
}

private function handle_pdf_generation_request($prepared) {
    $history = isset($prepared['history']) && is_array($prepared['history']) ? $prepared['history'] : array();
    $message = isset($prepared['message']) ? (string) $prepared['message'] : '';
    $bot = isset($prepared['bot']) && is_array($prepared['bot']) ? $prepared['bot'] : array();

    $response = array(
        'reply' => '',
        'sources' => array(),
        'pdf_url' => '',
        'pdf_filename' => '',
    );

    if ($this->should_export_previous_answer_as_pdf($message, $history)) {
        $pdfText = $this->get_last_assistant_reply_from_history($history);
        if ($pdfText === '') {
            return $this->create_chat_error('bkiai_chat_empty_pdf_source', 'No previous chatbot answer is available for PDF export.', 400);
        }

        $exportFile = $this->create_pdf_download_file($pdfText, $bot);
        if (is_wp_error($exportFile)) {
            return $exportFile;
        }

        $response['reply'] = 'I created a PDF from the previous answer for you.';
        $response['sources'] = array('PDF export');
        $response['pdf_url'] = $exportFile['url'];
        $response['pdf_filename'] = $exportFile['filename'];
        return $response;
    }

    $cleanMessage = $this->sanitize_pdf_request_message($message);
    if ($cleanMessage === '') {
        $cleanMessage = $message;
    }

    $openAiResponse = $this->call_openai($prepared['settings']['api_key'], $bot, $cleanMessage, $history);
    if (is_wp_error($openAiResponse)) {
        return $openAiResponse;
    }

    $replyText = isset($openAiResponse['reply']) ? trim((string) $openAiResponse['reply']) : '';
    if ($replyText === '') {
        return $this->create_chat_error('bkiai_chat_empty_pdf_content', 'No readable reply is available for PDF creation.', 502);
    }

    $exportFile = $this->create_pdf_download_file($replyText, $bot);
    if (is_wp_error($exportFile)) {
        return $exportFile;
    }

    $response['reply'] = $replyText;
    $response['sources'] = isset($openAiResponse['sources']) && is_array($openAiResponse['sources']) ? $openAiResponse['sources'] : array();
    $response['pdf_url'] = $exportFile['url'];
    $response['pdf_filename'] = $exportFile['filename'];
    return $response;
}

    private function build_openai_image_error_message($statusCode, $decoded) {
        $message = $this->build_openai_error_message($statusCode, $decoded);
        $rawMessage = '';
        if (is_array($decoded) && !empty($decoded['error']) && is_array($decoded['error']) && !empty($decoded['error']['message'])) {
            $rawMessage = strtolower((string) $decoded['error']['message']);
        }
        if ($statusCode === 403 || strpos($rawMessage, 'verification') !== false || strpos($rawMessage, 'organization verification') !== false) {
            return 'Image generation is currently not enabled for this OpenAI account. Please check API access and organization verification.';
        }
        return $message;
    }

    private function call_openai_image($apiKey, $message, $bot = array()) {
        $prompt = trim((string) $message);
        if ($prompt === '') {
            return $this->create_chat_error('bkiai_chat_empty_image_prompt', 'A description is missing for image generation.', 400);
        }

        $body = array(
            'model' => 'gpt-image-1',
            'prompt' => $prompt,
            'size' => '1024x1024',
            'quality' => 'medium',
            'output_format' => 'png',
        );

        $http_response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($http_response)) {
            return $this->create_chat_error('bkiai_chat_image_http_error', 'The connection to OpenAI image generation failed. Please try again.', 502);
        }

        $statusCode = wp_remote_retrieve_response_code($http_response);
        $rawBody = wp_remote_retrieve_body($http_response);
        $decoded = json_decode($rawBody, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            return $this->create_chat_error('bkiai_chat_image_api_error', $this->build_openai_image_error_message($statusCode, $decoded), $statusCode);
        }

        $imageData = '';
        if (!empty($decoded['data'][0]['b64_json']) && is_string($decoded['data'][0]['b64_json'])) {
            $imageData = $decoded['data'][0]['b64_json'];
        }

        if ($imageData === '') {
            return $this->create_chat_error('bkiai_chat_image_empty', 'No image could be generated. Please describe your desired image a bit more clearly.', 502);
        }

        $caption = 'I created an image for you.';
        if (!empty($bot['title'])) {
            $caption = 'I created an image for ' . sanitize_text_field($bot['title']) . '.';
        }

        $imageUrl = 'data:image/png;base64,' . $imageData;
        $storedImage = $this->create_generated_image_file($imageData, $bot);
        if (!is_wp_error($storedImage) && !empty($storedImage['url'])) {
            $imageUrl = $storedImage['url'];
        }

        return array(
            'reply' => $caption,
            'sources' => array('Image generation'),
            'image_url' => $imageUrl,
            'image_alt' => 'Generated image',
        );
    }


    private function is_pdf_generation_request($message) {
        $text = function_exists('mb_strtolower') ? mb_strtolower(wp_strip_all_tags((string) $message), 'UTF-8') : strtolower(wp_strip_all_tags((string) $message));
        if ($text === '') {
            return false;
        }

        if (strpos($text, '/pdf') === 0) {
            return true;
        }

        $hasPdfTerm = preg_match('/\b(pdf|portable document)\b/u', $text);
        $hasCreateVerb = preg_match('/\b(generier|generiere|erstell|erstelle|erzeuge|exportier|exportiere|speicher|speichere|pack|packe|konvertier|konvertiere|mach|mache|create|generate|export|save|convert|make)\b/u', $text);
        $hasPdfPhrase = preg_match('/\b(als|as|into|to|in)\s+(eine\s+|a\s+)?pdf\b/u', $text);

        return (bool) ($hasPdfTerm && ($hasCreateVerb || $hasPdfPhrase));
    }

    private function attach_pdf_download_to_response($response, $bot = array()) {
        if (is_wp_error($response)) {
            return $response;
        }

        $replyText = isset($response['reply']) ? trim((string) $response['reply']) : '';
        if ($replyText === '') {
            return $this->create_chat_error('bkiai_chat_empty_pdf_content', 'No readable reply is available for PDF creation.', 502);
        }

        $exportFile = $this->create_pdf_download_file($replyText, $bot);
        if (is_wp_error($exportFile)) {
            return $exportFile;
        }

        $response['pdf_url'] = $exportFile['url'];
        $response['pdf_filename'] = $exportFile['filename'];
        return $response;
    }

    private function build_pdf_filename($bot = array()) {
        $base = !empty($bot['title']) ? sanitize_title((string) $bot['title']) : 'bkiai-chat-export';
        if ($base === '') {
            $base = 'bkiai-chat-export';
        }
        return $base . '-' . gmdate('Ymd-His') . '.pdf';
    }

    private function create_pdf_download_file($text, $bot = array()) {
        $title = !empty($bot['title']) ? sanitize_text_field((string) $bot['title']) . ' Export' : 'BKiAI Chat Export';
        $pdfBinary = $this->build_simple_pdf_document($title, (string) $text);
        if ($pdfBinary === '') {
            return $this->create_chat_error('bkiai_chat_pdf_failed', 'The PDF could not be created from the response.', 500);
        }
        return $this->create_uploaded_export_file($pdfBinary, $this->build_pdf_filename($bot), 'bkiai-chat-pdfs', 'application/pdf');
    }

    private function build_simple_pdf_document($title, $bodyText) {
        $prepareUtf8Text = function ($value) {
            $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = preg_replace("/\r\n?|\n/u", "\n", $value);
            $value = str_replace(array("\xC2\xA0", "•", "‣", "◦", "–", "—"), array(' ', '- ', '- ', '- ', '-', '-'), $value);
            return trim($value);
        };

        $convertToPdfEncoding = function ($value) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', (string) $value);
            if ($converted === false) {
                $converted = (string) $value;
            }
            return $converted;
        };

        $escapePdf = function ($value) {
            $value = str_replace('\\', '\\\\', (string) $value);
            $value = str_replace('(', '\\(', $value);
            $value = str_replace(')', '\\)', $value);
            return preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F]/", '', $value);
        };

        $wrapLines = function ($text, $limit = 90) {
            $text = preg_replace("/\r\n?|\n/u", "\n", (string) $text);
            $paragraphs = explode("\n", $text);
            $lines = array();

            foreach ($paragraphs as $paragraph) {
                $paragraph = trim((string) $paragraph);
                if ($paragraph === '') {
                    $lines[] = '';
                    continue;
                }

                $wrapped = wordwrap($paragraph, $limit, "\n", false);
                foreach (explode("\n", $wrapped) as $wrappedLine) {
                    $lines[] = rtrim((string) $wrappedLine);
                }
            }

            return $lines;
        };

        $titleUtf8 = $prepareUtf8Text($title);
        $bodyUtf8 = $prepareUtf8Text($bodyText);
        $bodyLinesUtf8 = $wrapLines($bodyUtf8, 90);
        if (empty($bodyLinesUtf8)) {
            $bodyLinesUtf8 = array(' ');
        }

        $titleEncoded = $escapePdf($convertToPdfEncoding($titleUtf8 !== '' ? $titleUtf8 : 'BKiAI Chat Export'));
        $bodyLines = array();
        foreach ($bodyLinesUtf8 as $line) {
            $bodyLines[] = $escapePdf($convertToPdfEncoding($line));
        }
        if (empty($bodyLines)) {
            $bodyLines = array(' ');
        }

        $linesPerPage = 46;
        $pages = array_chunk($bodyLines, $linesPerPage);
        $objects = array();
        $offsets = array();

        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = array();
        $pageObjectNumbers = array();
        $contentObjectNumbers = array();
        $nextObjectNumber = 4;
        foreach ($pages as $_pageLines) {
            $pageObjectNumbers[] = $nextObjectNumber;
            $contentObjectNumbers[] = $nextObjectNumber + 1;
            $kids[] = $nextObjectNumber . ' 0 R';
            $nextObjectNumber += 2;
        }
        $objects[] = "<< /Type /Pages /Kids [ " . implode(' ', $kids) . " ] /Count " . count($pages) . " >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";

        foreach ($pages as $pageIndex => $pageLines) {
            $contentObjectNumber = $contentObjectNumbers[$pageIndex];
            $contentParts = array();
            $contentParts[] = 'BT';
            $contentParts[] = '/F1 16 Tf';
            $contentParts[] = '50 760 Td';
            $contentParts[] = '(' . $titleEncoded . ') Tj';
            $contentParts[] = 'ET';
            $contentParts[] = 'BT';
            $contentParts[] = '/F1 11 Tf';
            $contentParts[] = '50 736 Td';
            $contentParts[] = '14 TL';
            foreach ($pageLines as $lineIndex => $line) {
                if ($lineIndex > 0) {
                    $contentParts[] = 'T*';
                }
                $contentParts[] = '(' . $line . ') Tj';
            }
            $contentParts[] = 'ET';
            $stream = implode("\n", $contentParts);
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents " . $contentObjectNumber . " 0 R >>";
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        foreach ($objects as $index => $objectContent) {
            $objectNumber = $index + 1;
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= $objectNumber . " 0 obj\n" . $objectContent . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
        return $pdf;
    }


private function create_chat_error($code, $message, $status = 500) {
    return new WP_Error($code, $message, array('status' => intval($status)));
}

private function build_openai_error_message($statusCode, $decoded) {
    $rawMessage = '';
    $errorCode = '';
    $errorType = '';

    if (is_array($decoded) && !empty($decoded['error']) && is_array($decoded['error'])) {
        $rawMessage = !empty($decoded['error']['message']) ? sanitize_text_field($decoded['error']['message']) : '';
        $errorCode = !empty($decoded['error']['code']) ? sanitize_text_field($decoded['error']['code']) : '';
        $errorType = !empty($decoded['error']['type']) ? sanitize_text_field($decoded['error']['type']) : '';
    }

    $haystack = strtolower(trim($errorCode . ' ' . $errorType . ' ' . $rawMessage));

    if ($statusCode === 401 || strpos($haystack, 'invalid_api_key') !== false || strpos($haystack, 'incorrect api key') !== false || strpos($haystack, 'api key') !== false) {
        return 'The saved OpenAI API key is invalid or no longer active.';
    }

    if ($statusCode === 429 || strpos($haystack, 'rate limit') !== false || strpos($haystack, 'quota') !== false || strpos($haystack, 'insufficient_quota') !== false) {
        return 'The OpenAI limit has currently been reached. Please try again later.';
    }

    if ($statusCode === 404 || strpos($haystack, 'model') !== false && (strpos($haystack, 'not found') !== false || strpos($haystack, 'does not exist') !== false || strpos($haystack, 'not available') !== false)) {
        return 'The selected model is not available in your current OpenAI access.';
    }

    if (strpos($haystack, 'web_search_preview') !== false || strpos($haystack, 'web search') !== false) {
        return 'Web search is currently not available with the selected model or in your current OpenAI access.';
    }

    if ($statusCode >= 500) {
        return 'OpenAI is temporarily unavailable right now. Please try again shortly.';
    }

    if ($statusCode === 400) {
        return 'The request could not be processed. Please check the model, prompt, or knowledge sources and try again.';
    }

    if (!empty($rawMessage)) {
        return $rawMessage;
    }

    return 'OpenAI hat keine verwertbare Answer geliefert.';
}


private function call_openai($apiKey, $bot, $message, $history) {
    $sourceHints = array();
    $body = $this->build_openai_request_body($bot, $message, $history, $sourceHints);

    $http_response = wp_remote_post('https://api.openai.com/v1/responses', array(
        'timeout' => 60,
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ),
        'body' => wp_json_encode($body),
    ));

    if (is_wp_error($http_response)) {
        return $this->create_chat_error('bkiai_chat_http_error', 'The connection to OpenAI failed. Please check your server connection and try again.', 502);
    }

    $statusCode = wp_remote_retrieve_response_code($http_response);
    $rawBody = wp_remote_retrieve_body($http_response);
    $decoded = json_decode($rawBody, true);

    if ($statusCode < 200 || $statusCode >= 300) {
        return $this->create_chat_error('bkiai_chat_api_error', $this->build_openai_error_message($statusCode, $decoded), $statusCode);
    }

    $reply = $this->extract_response_text($decoded);
    if ($reply === '') {
        return $this->create_chat_error('bkiai_chat_empty_reply', 'No readable answer could be generated. Please phrase the question a little more clearly and try again.', 502);
    }

    return array('reply' => $reply, 'sources' => $sourceHints);
}


    private function extract_response_text($decoded) {
        if (!is_array($decoded)) {
            return '';
        }

        if (!empty($decoded['output_text']) && is_string($decoded['output_text'])) {
            return trim($decoded['output_text']);
        }

        if (!empty($decoded['output']) && is_array($decoded['output'])) {
            $parts = array();
            foreach ($decoded['output'] as $outputItem) {
                if (empty($outputItem['content']) || !is_array($outputItem['content'])) {
                    continue;
                }
                foreach ($outputItem['content'] as $contentItem) {
                    if (!empty($contentItem['text']) && is_string($contentItem['text'])) {
                        $parts[] = $contentItem['text'];
                    }
                }
            }
            if (!empty($parts)) {
                return trim(implode("\n", $parts));
            }
        }

        return '';
    }
}

new BKiAI_Chat_Plugin();
