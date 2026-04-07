<?php
/**
 * Plugin Name: BKiAI KI Chatbot
 * Plugin URI: https://businesskiai.de/bki-ai-chat/
 * Description: Add an AI chat to your WordPress site with one configurable bot, voice recording, design settings, and optional knowledge-file support.
 * Version: 3.3.0
 * Author: BusinessKiAI
 * Author URI: https://businesskiai.de/
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bkiai-chat
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BKIAI_CHAT_FREE_VERSION', '3.3.0');
define('BKIAI_CHAT_FREE_FILE', __FILE__);
define('BKIAI_CHAT_FREE_DIR', plugin_dir_path(__FILE__));
define('BKIAI_CHAT_FREE_URL', plugin_dir_url(__FILE__));

class BKiAI_Chat_Plugin {
    const OPTION_KEY = 'bkiai_chat_settings';
    const SHORTCODE = 'bkiai_chat';
    const NONCE_ACTION = 'bkiai_chat_nonce_action';
    const ADMIN_NONCE_ACTION = 'bkiai_chat_save_settings';
    const MAX_HISTORY_MESSAGES = 8;
    const NOTICE_TRANSIENT_KEY = 'bkiai_chat_admin_notice';

    private $models = array(
        'gpt-4o-mini' => 'gpt-4o-mini',
        'gpt-4.1-mini' => 'gpt-4.1-mini',
    );

    public function __construct() {
        add_action('admin_init', array($this, 'register_privacy_policy_content'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_bkiai_chat_save_settings', array($this, 'handle_admin_save'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp_footer', array($this, 'render_global_popup'));
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('wp_ajax_bkiai_chat_send_message', array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_bkiai_chat_send_message', array($this, 'handle_chat_request'));
        add_action('wp_ajax_bkiai_chat_stream_message', array($this, 'handle_chat_stream_request'));
        add_action('wp_ajax_nopriv_bkiai_chat_stream_message', array($this, 'handle_chat_stream_request'));
    }

    public function load_textdomain() {
        return;
    }

    public function register_privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content  = '<p>' . esc_html__('BKiAI Chat sends chat content, prompts, and optional uploaded knowledge-file content to OpenAI in order to generate chatbot responses.', 'bkiai-chat') . '</p>';
        $content .= '<p>' . esc_html__('The free edition does not provide local chat-log storage, image generation, PDF generation, web search, or website-content knowledge sources.', 'bkiai-chat') . '</p>';

        wp_add_privacy_policy_content(
            esc_html__('BKiAI Chat', 'bkiai-chat'),
            wp_kses_post($content)
        );
    }

    public function add_admin_menu() {
        add_menu_page(
            'BKiAI Chat',
            'BKiAI Chat',
            'manage_options',
            'bkiai-chat',
            array($this, 'render_settings_page'),
            'dashicons-format-chat',
            56
        );

        add_submenu_page(
            'bkiai-chat',
            'BKiAI Chat Settings',
            'Settings',
            'manage_options',
            'bkiai-chat',
            array($this, 'render_settings_page')
        );
    }

    private function get_site_language_mode() {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $locale = strtolower((string) $locale);
        return strpos($locale, 'de') === 0 ? 'de' : 'en';
    }

    private function get_request_guard_message($settings = null) {
        $isEnglish = $this->get_site_language_mode() === 'en';
        return $isEnglish
            ? 'Too many chat requests came from this connection in a short time. Please wait a moment and try again.'
            : 'Zu viele Chat-Anfragen kamen in kurzer Zeit von dieser Verbindung. Bitte warte einen Moment und versuche es dann erneut.';
    }

    private function get_request_guard_settings($settings = null) {
        if (!is_array($settings)) {
            $settings = $this->get_settings();
        }

        $privacy = isset($settings['privacy']) && is_array($settings['privacy']) ? $settings['privacy'] : array();

        return array(
            'enabled' => !empty($privacy['public_request_guard_enabled']) && $privacy['public_request_guard_enabled'] === '1',
            'window_seconds' => max(30, min(600, intval(isset($privacy['request_guard_window_seconds']) ? $privacy['request_guard_window_seconds'] : 60))),
            'chat_limit' => max(1, min(300, intval(isset($privacy['chat_burst_limit_per_ip']) ? $privacy['chat_burst_limit_per_ip'] : 20))),
        );
    }

    private function enforce_public_request_guard($settings = null) {
        $guard = $this->get_request_guard_settings($settings);
        if (empty($guard['enabled'])) {
            return true;
        }

        $windowSeconds = max(30, intval($guard['window_seconds']));
        $limit = max(1, intval($guard['chat_limit']));
        $transientKey = 'bkiai_guard_chat_' . $this->get_visitor_key() . '_' . $windowSeconds;
        $state = get_transient($transientKey);
        $now = time();

        if (!is_array($state) || empty($state['reset_at']) || intval($state['reset_at']) <= $now) {
            $state = array(
                'count' => 0,
                'reset_at' => $now + $windowSeconds,
            );
        }

        if (intval($state['count']) >= $limit) {
            return new WP_Error('bkiai_chat_burst_limit', $this->get_request_guard_message($settings), array('status' => 429));
        }

        $state['count'] = intval($state['count']) + 1;
        set_transient($transientKey, $state, max(1, intval($state['reset_at']) - $now));
        return true;
    }

    private function should_show_bot_sources($bot, $settings = null) {
        if (!is_array($settings)) {
            $settings = $this->get_settings();
        }

        if (isset($bot['show_sources'])) {
            return $bot['show_sources'] === '1';
        }

        return !isset($settings['design']['show_sources']) || $settings['design']['show_sources'] === '1';
    }

    private function filter_response_sources_for_bot($response, $bot, $settings = null) {
        if (!is_array($response)) {
            return $response;
        }

        if (!$this->should_show_bot_sources($bot, $settings)) {
            $response['sources'] = array();
        } elseif (!isset($response['sources']) || !is_array($response['sources'])) {
            $response['sources'] = array();
        }

        return $response;
    }

    public function enqueue_admin_assets($hook_suffix) {
        if (!in_array($hook_suffix, array('settings_page_bkiai-chat', 'toplevel_page_bkiai-chat'), true)) {
            return;
        }

        wp_enqueue_style(
            'bkiai-chat-admin-style',
            BKIAI_CHAT_FREE_URL . 'assets/admin.css',
            array(),
            BKIAI_CHAT_FREE_VERSION
        );

        wp_enqueue_script(
            'bkiai-chat-admin-script',
            BKIAI_CHAT_FREE_URL . 'assets/admin.js',
            array(),
            BKIAI_CHAT_FREE_VERSION,
            true
        );

        wp_localize_script(
            'bkiai-chat-admin-script',
            'bkiaiAdminConfig',
            array(
                'designPresets' => $this->get_design_presets(),
            )
        );

        wp_enqueue_media();
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
            'streamWordDelay' => 48,
            'streamWordDelayJitter' => 18,
            'streamInitialDelay' => 280,
            'streamSpaceDelay' => 10,
            'streamCommaDelay' => 140,
            'streamSentenceDelay' => 260,
            'streamParagraphDelay' => 340,
            'voiceNotSupported' => 'Voice input is not supported in this browser.',
            'voiceOutputNotSupported' => 'Speech output is not supported in this browser.',
            'voiceConversationStartLabel' => 'Start voice input',
            'voiceConversationStopLabel' => 'Stop voice input',
            'voiceListeningLabel' => 'Listening…',
            'voiceProcessingLabel' => 'Processing…',
            'voiceSpeakingLabel' => 'Speaking…',
            'voiceResumeLabel' => 'Listening again…',
            'copyLabel' => 'Copy',
            'copiedLabel' => 'Copied',
            'copyErrorLabel' => 'Copy failed',
            'showSources' => true,
            'loadingAriaLabel' => 'Response is loading',
            'popupOpenLabel' => 'Open chat',
            'popupCloseLabel' => 'Close chat',
            'fullscreenOpenLabel' => 'Expand chat',
            'fullscreenCloseLabel' => 'Shrink chat',
            'fullscreenOpenShortLabel' => '',
            'fullscreenCloseShortLabel' => '',
            'streamAction' => 'bkiai_chat_stream_message',
        ));
    }

    private function get_default_bot($index) {
        return array(
            'enabled' => $index === 1 ? '1' : '0',
            'title' => 'BKiAI Chat ' . $index,
            'model' => 'gpt-4o-mini',
            'welcome_message' => 'Hello! How can I help?',
            'system_prompt' => 'You are a helpful assistant on a WordPress website.',
            'daily_message_limit' => '25',
            'daily_token_limit' => '12000',
            'popup_enabled' => '0',
            'popup_position' => 'bottom-right',
            'popup_page_scope' => 'all',
            'popup_selected_page_ids' => array(),
            'knowledge_files' => array(),
            'show_sources' => '1',
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
            'input_height' => '26px',
            'logo_url' => '',
            'chat_history_background_image_url' => '',
            'show_sources' => '1',
            'box_shadow_enabled' => '1',
            'voice_enabled' => '0',
            'voice_reply_gender' => 'female',
            'send_button_text' => 'Send',
            'clear_button_text' => 'Clear chat',
            'input_placeholder_text' => 'Ask any question',
        );
    }

    private function get_settings() {
        $defaults = array(
            'api_key' => '',
            'privacy' => array(
                'log_retention_days' => 30,
                'public_request_guard_enabled' => '0',
                'request_guard_window_seconds' => 60,
                'chat_burst_limit_per_ip' => 20,
            ),
            'design' => $this->get_default_design(),
            'bot_count' => 1,
            'bots' => array(
                1 => $this->get_default_bot(1),
            ),
        );

        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }

        $settings = wp_parse_args($saved, $defaults);
        $settings['privacy'] = wp_parse_args(isset($settings['privacy']) && is_array($settings['privacy']) ? $settings['privacy'] : array(), $defaults['privacy']);
        $settings['design'] = wp_parse_args(isset($settings['design']) && is_array($settings['design']) ? $settings['design'] : array(), $this->get_default_design());
        $settings['bot_count'] = 1;
        $settings['bots'] = array(
            1 => wp_parse_args(isset($settings['bots'][1]) ? $settings['bots'][1] : array(), $this->get_default_bot(1)),
        );
        if (!isset($settings['bots'][1]['show_sources'])) {
            $settings['bots'][1]['show_sources'] = isset($settings['design']['show_sources']) ? (string) $settings['design']['show_sources'] : '1';
        } else {
            $settings['bots'][1]['show_sources'] = $settings['bots'][1]['show_sources'] === '0' ? '0' : '1';
        }
        if (!is_array($settings['bots'][1]['knowledge_files'])) {
            $settings['bots'][1]['knowledge_files'] = array();
        }
        if (!is_array($settings['bots'][1]['popup_selected_page_ids'])) {
            $settings['bots'][1]['popup_selected_page_ids'] = array();
        }

        return $settings;
    }

    private function get_bot_count($settings = null) {
        return 1;
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

    private function sanitize_message_area_height($value, $default) {
        $value = $this->sanitize_dimension($value, $default);

        if (preg_match('/^(\d+(?:\.\d+)?)px$/', $value, $matches)) {
            return max(150, (int) round((float) $matches[1])) . 'px';
        }

        return $value;
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
private function log_chat_interaction($botIndex, $userMessage, $assistantMessage, $pageUrl = '', $pageTitle = '') {
    return;
}
private function normalize_match_value($value) {
    $value = remove_accents(wp_strip_all_tags((string) $value));
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return trim((string) $value);
}

private function get_selectable_content_options() {
    $postTypes = get_post_types(array(
        'public' => true,
        'show_ui' => true,
    ), 'objects');

    if (!is_array($postTypes)) {
        return array();
    }

    unset($postTypes['attachment']);

    $options = array();
    foreach ($postTypes as $postType => $object) {
        $items = get_posts(array(
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => 500,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        if (empty($items)) {
            continue;
        }

        $label = isset($object->labels->singular_name) && $object->labels->singular_name !== ''
            ? $object->labels->singular_name
            : $postType;

        foreach ($items as $postId) {
            $title = get_the_title($postId);
            if ($title === '') {
                /* translators: %d: WordPress post ID. */
                $title = sprintf(__('(No title) #%d', 'bkiai-chat'), $postId);
            }
            $options[] = array(
                'id' => (int) $postId,
                'label' => sprintf('%s (%s)', $title, $label),
            );
        }
    }

    usort($options, function ($a, $b) {
        return strcasecmp((string) $a['label'], (string) $b['label']);
    });

    return $options;
}

private function build_context_blocks($bot, $message) {
    $blocks = array();

    $knowledgeText = $this->get_relevant_knowledge_files_text($bot, $message);
    if ($knowledgeText !== '') {
        $blocks[] = "Knowledge file context:
" . $knowledgeText;
    }

    return $blocks;
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
public function render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = $this->get_settings();
    $saved = $this->get_get_text('settings-updated');
    $adminNotice = get_transient(self::NOTICE_TRANSIENT_KEY);
    if ($adminNotice) {
        delete_transient(self::NOTICE_TRANSIENT_KEY);
    }

    $contentOptions = $this->get_selectable_content_options();
    $upgradeUrl = 'https://businesskiai.de/bki-ai-chat/#aichat';
    $bot = isset($settings['bots'][1]) && is_array($settings['bots'][1])
        ? wp_parse_args($settings['bots'][1], $this->get_default_bot(1))
        : $this->get_default_bot(1);
    $design = isset($settings['design']) && is_array($settings['design'])
        ? wp_parse_args($settings['design'], $this->get_default_design())
        : $this->get_default_design();

    if (!is_array($bot['knowledge_files'])) {
        $bot['knowledge_files'] = array();
    }

    ?>
    <div class="wrap bkiai-admin-wrap">
        <div class="bkiai-admin-header">
            <div class="bkiai-admin-header-copy">
                <h1>BKiAI Chat</h1>
                <p><strong>Shortcodes:</strong> <code>[bkiai_chat bot="1"]</code></p>
            </div>
            <div class="bkiai-admin-header-actions">
                <a class="button button-primary bkiai-upgrade-cta" href="<?php echo esc_url($upgradeUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Pro & Expert ansehen', 'bkiai-chat'); ?></a>
            </div>
        </div>

        <?php if ($saved === '1') : ?>
            <div class="notice is-dismissible bkiai-admin-notice bkiai-admin-notice-success"><p><?php echo esc_html__('Settings were saved successfully.', 'bkiai-chat'); ?></p></div>
        <?php endif; ?>

        <?php if (!empty($adminNotice['message'])) : ?>
            <div class="notice is-dismissible bkiai-admin-notice bkiai-admin-notice-<?php echo esc_attr(isset($adminNotice['type']) ? $adminNotice['type'] : 'info'); ?>"><p><?php echo esc_html($adminNotice['message']); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="bkiai_chat_save_settings" />
            <input type="hidden" id="bkiai_active_tab" name="active_tab" value="<?php echo esc_attr($this->get_get_key('active_tab', 'general')); ?>" />
            <?php wp_nonce_field(self::ADMIN_NONCE_ACTION, 'bkiai_chat_admin_nonce'); ?>

            <div class="bkiai-admin-tabs" role="tablist" aria-label="BKiAI Chat Settings">
                <button type="button" class="bkiai-admin-tab-button is-active" data-tab-target="general"><?php echo esc_html__('General', 'bkiai-chat'); ?></button>
                <button type="button" class="bkiai-admin-tab-button" data-tab-target="bot-1"><?php echo esc_html__('Bot 1', 'bkiai-chat'); ?></button>
            </div>

            <div class="bkiai-admin-upgrade-box">
                <div class="bkiai-admin-upgrade-box__copy">
                    <h2><?php echo esc_html__('Mehr Funktionen mit Pro & Expert', 'bkiai-chat'); ?></h2>
                    <p><?php echo esc_html__('Weitere Bots, erweiterte KI-Funktionen und die Kaufversion findest du auf der Produktseite von BusinessKiai.', 'bkiai-chat'); ?></p>
                </div>
                <div class="bkiai-admin-upgrade-box__actions">
                    <a class="button bkiai-admin-upgrade-box__button" href="<?php echo esc_url($upgradeUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Zur Pro- & Expert-Seite', 'bkiai-chat'); ?></a>
                </div>
            </div>

            <div class="bkiai-admin-tab-panel is-active" data-tab-panel="general">
                <h2><?php echo esc_html__('General', 'bkiai-chat'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="bkiai_api_key"><?php echo esc_html__('OpenAI API key', 'bkiai-chat'); ?></label></th>
                            <td>
                                <input type="password" id="bkiai_api_key" name="api_key" value="<?php echo esc_attr(isset($settings['api_key']) ? $settings['api_key'] : ''); ?>" class="regular-text" autocomplete="off" />
                                <p class="description"><?php echo esc_html__('The key remains stored in the backend and is not shown in the browser.', 'bkiai-chat'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Privacy and local storage', 'bkiai-chat'); ?></th>
                            <td>
                                <p class="description"><?php echo esc_html__('The free edition does not store local chat logs in the WordPress backend.', 'bkiai-chat'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Public request protection', 'bkiai-chat'); ?></th>
                            <td>
                                <div class="bkiai-inline-help-wrap">
                                    <label><input type="checkbox" name="privacy[public_request_guard_enabled]" value="1" <?php checked(isset($settings['privacy']['public_request_guard_enabled']) ? $settings['privacy']['public_request_guard_enabled'] : '0', '1'); ?> /> <?php echo esc_html__('Enable burst protection for public chat requests', 'bkiai-chat'); ?></label>
                                    <details class="bkiai-inline-help">
                                        <summary aria-label="Public request protection help">?</summary>
                                        <div class="bkiai-inline-help__box">
                                            <strong><?php echo esc_html__('Public request protection', 'bkiai-chat'); ?></strong>
                                            <p><?php echo esc_html__('This setting limits public chat requests per connection or IP within a defined time window. It helps reduce spam, abuse and unnecessary API costs.', 'bkiai-chat'); ?></p>
                                            <p><?php echo esc_html__('Protection window defines the time period used for counting requests. Max. chat requests per connection defines how many public chat requests are allowed during that time.', 'bkiai-chat'); ?></p>
                                            <p><?php echo esc_html__('If the values are set too low, normal visitors may also be temporarily blocked.', 'bkiai-chat'); ?></p>
                                        </div>
                                    </details>
                                </div>
                                <p style="margin-top:10px;">
                                    <label for="bkiai_request_guard_window_seconds"><?php echo esc_html__('Protection window (seconds)', 'bkiai-chat'); ?></label><br />
                                    <input type="number" min="30" max="600" step="1" id="bkiai_request_guard_window_seconds" name="privacy[request_guard_window_seconds]" value="<?php echo esc_attr(isset($settings['privacy']['request_guard_window_seconds']) ? $settings['privacy']['request_guard_window_seconds'] : 60); ?>" class="small-text" />
                                </p>
                                <p style="margin-top:10px;">
                                    <label for="bkiai_chat_burst_limit_per_ip"><?php echo esc_html__('Max. chat requests per connection', 'bkiai-chat'); ?></label><br />
                                    <input type="number" min="1" max="300" step="1" id="bkiai_chat_burst_limit_per_ip" name="privacy[chat_burst_limit_per_ip]" value="<?php echo esc_attr(isset($settings['privacy']['chat_burst_limit_per_ip']) ? $settings['privacy']['chat_burst_limit_per_ip'] : 20); ?>" class="small-text" />
                                </p>
                                <p class="description"><?php echo esc_html__('Limits repeated chat requests from the same connection within a short time window.', 'bkiai-chat'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_width"><?php echo esc_html__('Window width', 'bkiai-chat'); ?></label></th>
                            <td>
                                <input type="text" id="bkiai_design_width" name="design[width]" value="<?php echo esc_attr($design['width']); ?>" class="regular-text" />
                                <p class="description"><?php echo wp_kses_post(__('Examples: <code>100%</code>, <code>780px</code>, <code>90vw</code>.', 'bkiai-chat')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_height"><?php echo esc_html__('Window height', 'bkiai-chat'); ?></label></th>
                            <td>
                                <input type="text" id="bkiai_design_height" name="design[height]" value="<?php echo esc_attr($design['height']); ?>" class="regular-text" />
                                <p class="description"><?php echo wp_kses_post(__('Minimum: <code>150px</code>. Controls only the height of the message area. Examples: <code>150px</code>, <code>420px</code>, <code>60vh</code>.', 'bkiai-chat')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_chat_radius"><?php echo esc_html__('Chat window / popup corner radius', 'bkiai-chat'); ?></label></th>
                            <td>
                                <input type="number" min="0" max="80" step="1" id="bkiai_design_chat_radius" name="design[chat_radius]" value="<?php echo esc_attr($design['chat_radius']); ?>" class="small-text" /> <span>px</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_input_radius"><?php echo esc_html__('Input field corner radius', 'bkiai-chat'); ?></label></th>
                            <td>
                                <input type="number" min="0" max="80" step="1" id="bkiai_design_input_radius" name="design[input_radius]" value="<?php echo esc_attr($design['input_radius']); ?>" class="small-text" /> <span>px</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_input_height"><?php echo esc_html__('Input field default height', 'bkiai-chat'); ?></label></th>
                            <td>
                                <input type="text" id="bkiai_design_input_height" name="design[input_height]" value="<?php echo esc_attr(isset($design['input_height']) ? $design['input_height'] : '26px'); ?>" class="regular-text" />
                                <p class="description"><?php echo wp_kses_post(__('Minimum: <code>26px</code>. Examples: <code>26px</code>, <code>44px</code>, <code>6rem</code>.', 'bkiai-chat')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_preset"><?php echo esc_html__('Standard design set', 'bkiai-chat'); ?></label></th>
                            <td>
                                <div class="bkiai-design-preset-row">
                                    <select id="bkiai_design_preset">
                                        <option value=""><?php echo esc_html__('Please choose ...', 'bkiai-chat'); ?></option>
                                        <?php foreach ($this->get_design_presets() as $presetKey => $presetData) : ?>
                                            <option value="<?php echo esc_attr($presetKey); ?>"><?php echo esc_html($presetData['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="button" id="bkiai_apply_design_preset"><?php echo esc_html__('Apply design preset', 'bkiai-chat'); ?></button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_border_width"><?php echo esc_html__('Chat border thickness', 'bkiai-chat'); ?></label></th>
                            <td>
                                <input type="number" min="0" step="1" id="bkiai_design_border_width" name="design[border_width]" value="<?php echo esc_attr(isset($design['border_width']) ? $design['border_width'] : '1'); ?>" class="small-text" /> <span>px</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_border_color"><?php echo esc_html__('Chat border colour', 'bkiai-chat'); ?></label></th>
                            <td>
                                <?php $borderColor = isset($design['border_color']) ? $design['border_color'] : '#e5e7eb'; ?>
                                <div class="bkiai-color-field-group">
                                    <input type="text" id="bkiai_design_border_color" name="design[border_color]" value="<?php echo esc_attr($borderColor); ?>" class="regular-text bkiai-color-text" />
                                    <input type="color" value="<?php echo esc_attr($borderColor); ?>" class="bkiai-color-palette" data-target="bkiai_design_border_color" />
                                </div>
                            </td>
                        </tr>
                        <?php $this->render_fill_setting_row('background', 'Chat background color', 'bkiai_design_bg', $design); ?>
                        <?php $this->render_fill_setting_row('header', 'Header color', 'bkiai_design_header', $design); ?>
                        <tr>
                            <th scope="row"><label for="bkiai_design_title_text_color"><?php echo esc_html__('Chat title text colour', 'bkiai-chat'); ?></label></th>
                            <td>
                                <?php $titleTextColor = isset($design['title_text_color']) ? $design['title_text_color'] : '#6b7280'; ?>
                                <div class="bkiai-color-field-group">
                                    <input type="text" id="bkiai_design_title_text_color" name="design[title_text_color]" value="<?php echo esc_attr($titleTextColor); ?>" class="regular-text bkiai-color-text" />
                                    <input type="color" value="<?php echo esc_attr($titleTextColor); ?>" class="bkiai-color-palette" data-target="bkiai_design_title_text_color" />
                                </div>
                            </td>
                        </tr>
                        <?php $this->render_fill_setting_row('button', 'Button color (Send)', 'bkiai_design_button', $design); ?>
                        <tr>
                            <th scope="row"><label for="bkiai_design_send_button_text"><?php echo esc_html__('Send button text', 'bkiai-chat'); ?></label></th>
                            <td><input type="text" id="bkiai_design_send_button_text" name="design[send_button_text]" value="<?php echo esc_attr(isset($design['send_button_text']) ? $design['send_button_text'] : 'Send'); ?>" class="regular-text" maxlength="40" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_logo_url"><?php echo esc_html__('Logo / image in header', 'bkiai-chat'); ?></label></th>
                            <td>
                                <div class="bkiai-logo-field-group">
                                    <input type="text" id="bkiai_design_logo_url" name="design[logo_url]" value="<?php echo esc_attr(isset($design['logo_url']) ? $design['logo_url'] : ''); ?>" class="regular-text" placeholder="https://.../logo.png" />
                                    <input type="file" id="bkiai_logo_file" name="bkiai_logo_file" accept="image/*" style="display:none;" />
                                    <input type="hidden" id="bkiai_logo_remove" name="design[logo_remove]" value="0" />
                                    <button type="button" class="button" id="bkiai_logo_select_button"><?php echo esc_html__('Choose logo', 'bkiai-chat'); ?></button>
                                    <button type="button" class="button" id="bkiai_logo_remove_button"><?php echo esc_html__('Remove logo', 'bkiai-chat'); ?></button>
                                    <img src="<?php echo esc_url(isset($design['logo_url']) ? $design['logo_url'] : ''); ?>" id="bkiai_logo_preview" class="bkiai-logo-preview <?php echo empty($design['logo_url']) ? 'is-hidden' : ''; ?>" alt="" />
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_chat_history_background_image_url"><?php echo esc_html__('Chat history background image', 'bkiai-chat'); ?></label></th>
                            <td>
                                <div class="bkiai-logo-field-group">
                                    <input type="text" id="bkiai_design_chat_history_background_image_url" name="design[chat_history_background_image_url]" value="<?php echo esc_attr(isset($design['chat_history_background_image_url']) ? $design['chat_history_background_image_url'] : ''); ?>" class="regular-text" placeholder="https://.../background.jpg" />
                                    <input type="file" id="bkiai_chat_history_background_file" name="bkiai_chat_history_background_file" accept="image/*" style="display:none;" />
                                    <input type="hidden" id="bkiai_chat_history_background_remove" name="design[chat_history_background_image_remove]" value="0" />
                                    <button type="button" class="button" id="bkiai_chat_history_background_select_button"><?php echo esc_html__('Choose background image', 'bkiai-chat'); ?></button>
                                    <button type="button" class="button" id="bkiai_chat_history_background_remove_button"><?php echo esc_html__('Remove background image', 'bkiai-chat'); ?></button>
                                    <img src="<?php echo esc_url(isset($design['chat_history_background_image_url']) ? $design['chat_history_background_image_url'] : ''); ?>" id="bkiai_chat_history_background_preview" class="bkiai-logo-preview <?php echo empty($design['chat_history_background_image_url']) ? 'is-hidden' : ''; ?>" alt="" />
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Show source references', 'bkiai-chat'); ?></th>
                            <td><label><input type="checkbox" name="design[show_sources]" value="1" <?php checked(isset($design['show_sources']) ? $design['show_sources'] : '1', '1'); ?> /> <?php echo esc_html__('Show the source references at the end of chatbot answers', 'bkiai-chat'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_input_placeholder_text"><?php echo esc_html__('Input placeholder text', 'bkiai-chat'); ?></label></th>
                            <td><input type="text" id="bkiai_design_input_placeholder_text" name="design[input_placeholder_text]" value="<?php echo esc_attr(isset($design['input_placeholder_text']) ? $design['input_placeholder_text'] : 'Ask any question'); ?>" class="regular-text" maxlength="80" /></td>
                        </tr>
                        <?php $this->render_fill_setting_row('footer', 'Footer color', 'bkiai_design_footer', $design); ?>
                        <?php $this->render_fill_setting_row('expand_button', 'Expand / shrink button color', 'bkiai_design_expand_button', $design); ?>
                        <tr>
                            <th scope="row"><label for="bkiai_design_clear_button_text"><?php echo esc_html__('Clear chat text', 'bkiai-chat'); ?></label></th>
                            <td><input type="text" id="bkiai_design_clear_button_text" name="design[clear_button_text]" value="<?php echo esc_attr(isset($design['clear_button_text']) ? $design['clear_button_text'] : 'Clear chat'); ?>" class="regular-text" maxlength="40" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bkiai_design_reset_text_color"><?php echo esc_html__('Text colour “Clear chat”', 'bkiai-chat'); ?></label></th>
                            <td>
                                <?php $resetTextColor = isset($design['reset_text_color']) ? $design['reset_text_color'] : '#dc2626'; ?>
                                <div class="bkiai-color-field-group">
                                    <input type="text" id="bkiai_design_reset_text_color" name="design[reset_text_color]" value="<?php echo esc_attr($resetTextColor); ?>" class="regular-text bkiai-color-text" />
                                    <input type="color" value="<?php echo esc_attr($resetTextColor); ?>" class="bkiai-color-palette" data-target="bkiai_design_reset_text_color" />
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Box shadow', 'bkiai-chat'); ?></th>
                            <td><label><input type="checkbox" name="design[box_shadow_enabled]" value="1" <?php checked(isset($design['box_shadow_enabled']) ? $design['box_shadow_enabled'] : '1', '1'); ?> /> <?php echo esc_html__('Enable box shadow', 'bkiai-chat'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Voice control', 'bkiai-chat'); ?></th>
                            <td><label><input type="checkbox" name="design[voice_enabled]" value="1" <?php checked(isset($design['voice_enabled']) ? $design['voice_enabled'] : '0', '1'); ?> /> <?php echo esc_html__('Enable microphone button in chat', 'bkiai-chat'); ?></label></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="bkiai-admin-tab-panel" data-tab-panel="bot-1">
                <h2><?php echo esc_html__('Bot 1', 'bkiai-chat'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Status', 'bkiai-chat'); ?></th>
                            <td><label><input type="checkbox" name="bots[1][enabled]" value="1" <?php checked(isset($bot['enabled']) ? $bot['enabled'] : '0', '1'); ?> /> <?php echo esc_html__('Enable Bot 1', 'bkiai-chat'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bot_title_1"><?php echo esc_html__('Title', 'bkiai-chat'); ?></label></th>
                            <td><input type="text" id="bot_title_1" name="bots[1][title]" value="<?php echo esc_attr(isset($bot['title']) ? $bot['title'] : 'BKiAI Chat 1'); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bot_model_1"><?php echo esc_html__('Model', 'bkiai-chat'); ?></label></th>
                            <td>
                                <select id="bot_model_1" name="bots[1][model]">
                                    <?php foreach ($this->models as $modelKey => $modelLabel) : ?>
                                        <option value="<?php echo esc_attr($modelKey); ?>" <?php selected(isset($bot['model']) ? $bot['model'] : 'gpt-4o-mini', $modelKey); ?>><?php echo esc_html($modelLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bot_welcome_1"><?php echo esc_html__('Welcome message', 'bkiai-chat'); ?></label></th>
                            <td><textarea id="bot_welcome_1" name="bots[1][welcome_message]" rows="3" class="large-text"><?php echo esc_textarea(isset($bot['welcome_message']) ? $bot['welcome_message'] : ''); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bot_prompt_1"><?php echo esc_html__('System prompt', 'bkiai-chat'); ?></label></th>
                            <td>
                                <textarea id="bot_prompt_1" name="bots[1][system_prompt]" rows="8" class="large-text bkiai-systemprompt-field" data-counter-target="bot_prompt_count_1"><?php echo esc_textarea(isset($bot['system_prompt']) ? $bot['system_prompt'] : ''); ?></textarea>
                                <div class="bkiai-systemprompt-meta">
                                    <p class="description bkiai-systemprompt-hint"><?php echo esc_html__('Keep the system prompt compact. Around 800–2500 characters is usually a good range.', 'bkiai-chat'); ?></p>
                                    <span id="bot_prompt_count_1" class="bkiai-systemprompt-count" aria-live="polite">0 characters</span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Daily rate limits', 'bkiai-chat'); ?></th>
                            <td>
                                <label><?php echo esc_html__('Messages per visitor per day:', 'bkiai-chat'); ?> <input type="number" min="1" step="1" name="bots[1][daily_message_limit]" value="<?php echo esc_attr(isset($bot['daily_message_limit']) ? $bot['daily_message_limit'] : '25'); ?>" class="small-text" /></label><br /><br />
                                <label><?php echo esc_html__('Approx. tokens per visitor per day:', 'bkiai-chat'); ?> <input type="number" min="1" step="1" name="bots[1][daily_token_limit]" value="<?php echo esc_attr(isset($bot['daily_token_limit']) ? $bot['daily_token_limit'] : '12000'); ?>" class="small-text" /></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Popup window', 'bkiai-chat'); ?></th>
                            <td>
                                <label><input type="checkbox" name="bots[1][popup_enabled]" value="1" <?php checked(isset($bot['popup_enabled']) ? $bot['popup_enabled'] : '0', '1'); ?> /> <?php echo esc_html__('Show Bot 1 as a collapsible popup window', 'bkiai-chat'); ?></label>
                                <p style="margin-top:10px;">
                                    <label for="bot_popup_position_1"><?php echo esc_html__('Popup position', 'bkiai-chat'); ?></label><br />
                                    <select id="bot_popup_position_1" name="bots[1][popup_position]">
                                        <option value="bottom-right" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'bottom-right'); ?>>bottom-right</option>
                                        <option value="bottom-left" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'bottom-left'); ?>>bottom-left</option>
                                        <option value="top-right" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'top-right'); ?>>top-right</option>
                                        <option value="top-left" <?php selected(isset($bot['popup_position']) ? $bot['popup_position'] : 'bottom-right', 'top-left'); ?>>top-left</option>
                                    </select>
                                </p>
                                <p style="margin-top:10px;">
                                    <label><input type="radio" name="bots[1][popup_page_scope]" value="all" <?php checked(isset($bot['popup_page_scope']) ? $bot['popup_page_scope'] : 'all', 'all'); ?> /> <?php echo esc_html__('Show on all pages', 'bkiai-chat'); ?></label><br />
                                    <label><input type="radio" name="bots[1][popup_page_scope]" value="selected" <?php checked(isset($bot['popup_page_scope']) ? $bot['popup_page_scope'] : 'all', 'selected'); ?> /> <?php echo esc_html__('Show only on selected pages/posts', 'bkiai-chat'); ?></label>
                                </p>
                                <p>
                                    <select id="bot_popup_selected_pages_1" name="bots[1][popup_selected_page_ids][]" multiple size="8" style="min-width:360px;max-width:100%;">
                                        <?php foreach ($contentOptions as $contentOption) : ?>
                                            <option value="<?php echo esc_attr((string) $contentOption['id']); ?>" <?php echo in_array(intval($contentOption['id']), isset($bot['popup_selected_page_ids']) ? array_map('intval', (array) $bot['popup_selected_page_ids']) : array(), true) ? 'selected' : ''; ?>><?php echo esc_html($contentOption['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Knowledge files', 'bkiai-chat'); ?></th>
                            <td>
                                <div class="bkiai-logo-field-group" style="align-items:flex-start;">
                                    <input type="file" id="knowledge_files_1" name="knowledge_files_1[]" accept=".md,.markdown,.txt,.csv" multiple style="display:none;" />
                                    <button type="button" class="button bkiai-admin-file-trigger" data-target="knowledge_files_1"><?php echo esc_html__('Choose file(s)', 'bkiai-chat'); ?></button>
                                    <span class="bkiai-admin-file-status" data-empty-label="<?php echo esc_attr__('No file chosen', 'bkiai-chat'); ?>"><?php echo esc_html__('No file chosen', 'bkiai-chat'); ?></span>
                                </div>
                                <p class="description"><?php echo esc_html__('Allowed file types: MD, Markdown, TXT, CSV. In the free edition, one knowledge file can be stored for Bot 1.', 'bkiai-chat'); ?></p>
                                <p style="margin-top:10px;">
                                    <label><input type="checkbox" name="bots[1][show_sources]" value="1" <?php checked(isset($bot['show_sources']) ? $bot['show_sources'] : '1', '1'); ?> /> <?php echo esc_html__('Show the source references below chatbot answers', 'bkiai-chat'); ?></label>
                                </p>

                                <?php if (!empty($bot['knowledge_files'])) : ?>
                                    <div style="margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fff;max-width:980px;">
                                        <?php /* translators: %d: Number of saved knowledge files. */ ?>
                                        <strong><?php echo esc_html(sprintf(__('Saved knowledge files (%d)', 'bkiai-chat'), count($bot['knowledge_files']))); ?></strong>
                                        <ul style="margin:10px 0 0 0;list-style:none;">
                                            <?php foreach ($bot['knowledge_files'] as $fileIndex => $file) : ?>
                                                <li style="margin-bottom:12px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fafafa;">
                                                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                                                        <strong><?php echo esc_html(isset($file['name']) ? $file['name'] : 'File'); ?></strong>
                                                        <label><input type="checkbox" name="knowledge_active[1][<?php echo esc_attr((string) $fileIndex); ?>]" value="1" <?php checked(!isset($file['active']) || $file['active'] === '1', true); ?> /> <?php echo esc_html__('active', 'bkiai-chat'); ?></label>
                                                        <label><input type="checkbox" name="delete_files[1][]" value="<?php echo esc_attr((string) $fileIndex); ?>" /> <?php echo esc_html__('delete', 'bkiai-chat'); ?></label>
                                                    </div>
                                                    <div style="color:#50575e;margin-top:6px;">
                                                        <?php echo esc_html(!empty($file['path']) && file_exists($file['path']) ? __('saved', 'bkiai-chat') : __('File missing', 'bkiai-chat')); ?>
                                                        <?php if (!empty($file['type'])) : ?> · <?php echo esc_html(strtoupper($file['type'])); ?><?php endif; ?>
                                                        <?php if (!empty($file['size'])) : ?> · <?php echo esc_html($this->format_bytes($file['size'])); ?><?php endif; ?>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else : ?>
                                    <p class="description" style="margin-top:10px;"><?php echo esc_html__('No knowledge file is currently saved for this bot.', 'bkiai-chat'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php submit_button(__('Save settings', 'bkiai-chat')); ?>
        </form>
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
        wp_die(esc_html__('You are not allowed to manage these settings.', 'bkiai-chat'));
    }

    check_admin_referer(self::ADMIN_NONCE_ACTION, 'bkiai_chat_admin_nonce');

    $existing = $this->get_settings();
    $postedDesign = $this->sanitize_recursive_textarea_field($this->get_post_array('design'));
    $postedPrivacy = $this->sanitize_recursive_textarea_field($this->get_post_array('privacy'));
    $postedBots = $this->sanitize_recursive_textarea_field($this->get_post_array('bots'));
    $deleteFiles = $this->sanitize_recursive_textarea_field($this->get_post_array('delete_files'));
    $knowledgeActive = $this->sanitize_recursive_textarea_field($this->get_post_array('knowledge_active'));

    $uploadedLogoUrl = '';
    $uploadedChatHistoryBackgroundUrl = '';
    $logoRemoveRequested = isset($postedDesign['logo_remove']) && $postedDesign['logo_remove'] === '1';
    $chatHistoryBackgroundRemoveRequested = isset($postedDesign['chat_history_background_image_remove']) && $postedDesign['chat_history_background_image_remove'] === '1';

    $logo_file = $this->get_uploaded_file_array('bkiai_logo_file');
    if (is_array($logo_file) && !empty($logo_file['name']) && !empty($logo_file['tmp_name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $attachmentId = media_handle_upload('bkiai_logo_file', 0);
        if (!is_wp_error($attachmentId)) {
            $uploadedLogoUrl = wp_get_attachment_url($attachmentId);
            $logoRemoveRequested = false;
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
        }
    }

    $existingDesign = isset($existing['design']) && is_array($existing['design'])
        ? wp_parse_args($existing['design'], $this->get_default_design())
        : $this->get_default_design();

    $settings = array(
        'api_key' => sanitize_text_field($this->get_post_text('api_key')),
        'privacy' => array(
            'log_retention_days' => isset($postedPrivacy['log_retention_days']) ? max(1, min(180, intval($postedPrivacy['log_retention_days']))) : 30,
            'public_request_guard_enabled' => isset($postedPrivacy['public_request_guard_enabled']) ? '1' : '0',
            'request_guard_window_seconds' => isset($postedPrivacy['request_guard_window_seconds']) ? max(30, min(600, intval($postedPrivacy['request_guard_window_seconds']))) : intval(isset($existing['privacy']['request_guard_window_seconds']) ? $existing['privacy']['request_guard_window_seconds'] : 60),
            'chat_burst_limit_per_ip' => isset($postedPrivacy['chat_burst_limit_per_ip']) ? max(1, min(300, intval($postedPrivacy['chat_burst_limit_per_ip']))) : intval(isset($existing['privacy']['chat_burst_limit_per_ip']) ? $existing['privacy']['chat_burst_limit_per_ip'] : 20),
        ),
        'design' => array(
            'width' => $this->sanitize_dimension(isset($postedDesign['width']) ? $postedDesign['width'] : $existingDesign['width'], $this->get_default_design()['width']),
            'height' => $this->sanitize_message_area_height(isset($postedDesign['height']) ? $postedDesign['height'] : $existingDesign['height'], $this->get_default_design()['height']),
            'background_color' => $this->sanitize_color(isset($postedDesign['background_color']) ? $postedDesign['background_color'] : $existingDesign['background_color'], '#ffffff'),
            'background_fill_type' => sanitize_key(isset($postedDesign['background_fill_type']) ? $postedDesign['background_fill_type'] : $existingDesign['background_fill_type']),
            'background_fill_preset' => sanitize_key(isset($postedDesign['background_fill_preset']) ? $postedDesign['background_fill_preset'] : $existingDesign['background_fill_preset']),
            'background_fill_angle' => sanitize_text_field(isset($postedDesign['background_fill_angle']) ? $postedDesign['background_fill_angle'] : $existingDesign['background_fill_angle']),
            'header_color' => $this->sanitize_color(isset($postedDesign['header_color']) ? $postedDesign['header_color'] : $existingDesign['header_color'], '#ffffff'),
            'header_fill_type' => sanitize_key(isset($postedDesign['header_fill_type']) ? $postedDesign['header_fill_type'] : $existingDesign['header_fill_type']),
            'header_fill_preset' => sanitize_key(isset($postedDesign['header_fill_preset']) ? $postedDesign['header_fill_preset'] : $existingDesign['header_fill_preset']),
            'header_fill_angle' => sanitize_text_field(isset($postedDesign['header_fill_angle']) ? $postedDesign['header_fill_angle'] : $existingDesign['header_fill_angle']),
            'title_text_color' => $this->sanitize_color(isset($postedDesign['title_text_color']) ? $postedDesign['title_text_color'] : $existingDesign['title_text_color'], '#6b7280'),
            'border_width' => $this->sanitize_border_width(isset($postedDesign['border_width']) ? $postedDesign['border_width'] : $existingDesign['border_width'], '1'),
            'border_color' => $this->sanitize_color(isset($postedDesign['border_color']) ? $postedDesign['border_color'] : $existingDesign['border_color'], '#e5e7eb'),
            'footer_color' => $this->sanitize_color(isset($postedDesign['footer_color']) ? $postedDesign['footer_color'] : $existingDesign['footer_color'], '#ffffff'),
            'footer_fill_type' => sanitize_key(isset($postedDesign['footer_fill_type']) ? $postedDesign['footer_fill_type'] : $existingDesign['footer_fill_type']),
            'footer_fill_preset' => sanitize_key(isset($postedDesign['footer_fill_preset']) ? $postedDesign['footer_fill_preset'] : $existingDesign['footer_fill_preset']),
            'footer_fill_angle' => sanitize_text_field(isset($postedDesign['footer_fill_angle']) ? $postedDesign['footer_fill_angle'] : $existingDesign['footer_fill_angle']),
            'button_color' => $this->sanitize_color(isset($postedDesign['button_color']) ? $postedDesign['button_color'] : $existingDesign['button_color'], '#2563eb'),
            'button_fill_type' => sanitize_key(isset($postedDesign['button_fill_type']) ? $postedDesign['button_fill_type'] : $existingDesign['button_fill_type']),
            'button_fill_preset' => sanitize_key(isset($postedDesign['button_fill_preset']) ? $postedDesign['button_fill_preset'] : $existingDesign['button_fill_preset']),
            'button_fill_angle' => sanitize_text_field(isset($postedDesign['button_fill_angle']) ? $postedDesign['button_fill_angle'] : $existingDesign['button_fill_angle']),
            'expand_button_color' => $this->sanitize_color(isset($postedDesign['expand_button_color']) ? $postedDesign['expand_button_color'] : $existingDesign['expand_button_color'], '#ffffff'),
            'expand_button_fill_type' => sanitize_key(isset($postedDesign['expand_button_fill_type']) ? $postedDesign['expand_button_fill_type'] : $existingDesign['expand_button_fill_type']),
            'expand_button_fill_preset' => sanitize_key(isset($postedDesign['expand_button_fill_preset']) ? $postedDesign['expand_button_fill_preset'] : $existingDesign['expand_button_fill_preset']),
            'expand_button_fill_angle' => sanitize_text_field(isset($postedDesign['expand_button_fill_angle']) ? $postedDesign['expand_button_fill_angle'] : $existingDesign['expand_button_fill_angle']),
            'reset_text_color' => $this->sanitize_color(isset($postedDesign['reset_text_color']) ? $postedDesign['reset_text_color'] : $existingDesign['reset_text_color'], '#dc2626'),
            'chat_radius' => $this->sanitize_radius_px(isset($postedDesign['chat_radius']) ? $postedDesign['chat_radius'] : $existingDesign['chat_radius'], '18'),
            'input_radius' => $this->sanitize_radius_px(isset($postedDesign['input_radius']) ? $postedDesign['input_radius'] : $existingDesign['input_radius'], '22'),
            'input_height' => $this->sanitize_dimension(isset($postedDesign['input_height']) ? $postedDesign['input_height'] : $existingDesign['input_height'], '26px'),
            'logo_url' => $logoRemoveRequested ? '' : (!empty($uploadedLogoUrl) ? esc_url_raw($uploadedLogoUrl) : (isset($postedDesign['logo_url']) ? esc_url_raw($postedDesign['logo_url']) : $existingDesign['logo_url'])),
            'chat_history_background_image_url' => $chatHistoryBackgroundRemoveRequested ? '' : (!empty($uploadedChatHistoryBackgroundUrl) ? esc_url_raw($uploadedChatHistoryBackgroundUrl) : (isset($postedDesign['chat_history_background_image_url']) ? esc_url_raw($postedDesign['chat_history_background_image_url']) : $existingDesign['chat_history_background_image_url'])),
            'show_sources' => isset($existingDesign['show_sources']) ? $existingDesign['show_sources'] : '1',
            'box_shadow_enabled' => isset($postedDesign['box_shadow_enabled']) ? '1' : '0',
            'voice_enabled' => isset($postedDesign['voice_enabled']) ? '1' : '0',
            'voice_reply_gender' => in_array(isset($postedDesign['voice_reply_gender']) ? $postedDesign['voice_reply_gender'] : 'female', array('female', 'male'), true) ? sanitize_text_field($postedDesign['voice_reply_gender']) : 'female',
            'send_button_text' => sanitize_text_field(isset($postedDesign['send_button_text']) ? $postedDesign['send_button_text'] : 'Send'),
            'clear_button_text' => sanitize_text_field(isset($postedDesign['clear_button_text']) ? $postedDesign['clear_button_text'] : 'Clear chat'),
            'input_placeholder_text' => sanitize_text_field(isset($postedDesign['input_placeholder_text']) ? $postedDesign['input_placeholder_text'] : 'Ask any question'),
        ),
        'bot_count' => 1,
        'bots' => array(),
    );

    $botPosted = isset($postedBots[1]) && is_array($postedBots[1]) ? $postedBots[1] : array();
    $existingBot = isset($existing['bots'][1]) && is_array($existing['bots'][1]) ? wp_parse_args($existing['bots'][1], $this->get_default_bot(1)) : $this->get_default_bot(1);
    if (!is_array($existingBot['knowledge_files'])) {
        $existingBot['knowledge_files'] = array();
    }

    $bot = $this->get_default_bot(1);
    $bot['enabled'] = isset($botPosted['enabled']) ? '1' : '0';
    $bot['title'] = isset($botPosted['title']) ? sanitize_text_field($botPosted['title']) : $bot['title'];
    $bot['model'] = isset($botPosted['model'], $this->models[$botPosted['model']]) ? sanitize_text_field($botPosted['model']) : 'gpt-4o-mini';
    $bot['welcome_message'] = isset($botPosted['welcome_message']) ? sanitize_textarea_field($botPosted['welcome_message']) : $bot['welcome_message'];
    $bot['system_prompt'] = isset($botPosted['system_prompt']) ? sanitize_textarea_field($botPosted['system_prompt']) : $bot['system_prompt'];
    $bot['daily_message_limit'] = isset($botPosted['daily_message_limit']) ? max(1, intval($botPosted['daily_message_limit'])) : intval($bot['daily_message_limit']);
    $bot['daily_token_limit'] = isset($botPosted['daily_token_limit']) ? max(1, intval($botPosted['daily_token_limit'])) : intval($bot['daily_token_limit']);
    $allowedPopupPositions = array('bottom-right', 'bottom-left', 'top-right', 'top-left');
    $bot['popup_enabled'] = isset($botPosted['popup_enabled']) ? '1' : '0';
    $bot['popup_position'] = (isset($botPosted['popup_position']) && in_array($botPosted['popup_position'], $allowedPopupPositions, true)) ? sanitize_text_field($botPosted['popup_position']) : 'bottom-right';
    $bot['popup_page_scope'] = (isset($botPosted['popup_page_scope']) && $botPosted['popup_page_scope'] === 'selected') ? 'selected' : 'all';
    $popupSelectedIdsRaw = (isset($botPosted['popup_selected_page_ids']) && is_array($botPosted['popup_selected_page_ids'])) ? $botPosted['popup_selected_page_ids'] : array();
    $bot['popup_selected_page_ids'] = array_values(array_filter(array_map('intval', $popupSelectedIdsRaw)));
    $bot['show_sources'] = isset($botPosted['show_sources']) ? '1' : '0';
    $bot['knowledge_files'] = $existingBot['knowledge_files'];

    $deleteCount = 0;
    if (isset($deleteFiles[1]) && is_array($deleteFiles[1])) {
        foreach ($deleteFiles[1] as $deleteIndex) {
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
            $bot['knowledge_files'][$fileIndex]['active'] = (isset($knowledgeActive[1]) && isset($knowledgeActive[1][$fileIndex])) ? '1' : '0';
        }
    }

    $remainingKnowledgeSlots = max(0, 1 - count($bot['knowledge_files']));
    $newFiles = $this->handle_uploaded_files('knowledge_files_1', 1, $remainingKnowledgeSlots);
    $uploadCount = 0;
    if (!empty($newFiles)) {
        $bot['knowledge_files'] = array_merge($bot['knowledge_files'], $newFiles);
        $uploadCount = count($newFiles);
    }

    $settings['bots'][1] = $bot;
    $this->update_settings($settings);

    $noticeParts = array();
    if ($uploadCount > 0) {
        $noticeParts[] = $uploadCount . ' knowledge file(s) saved';
    }
    if ($deleteCount > 0) {
        $noticeParts[] = $deleteCount . ' knowledge file(s) deleted';
    }
    if (!empty($noticeParts)) {
        $this->set_admin_notice(implode(' | ', $noticeParts), 'success');
    }

    $activeTab = $this->get_post_key('active_tab', 'general');
    if (!preg_match('/^(general|bot-1)$/', $activeTab)) {
        $activeTab = 'general';
    }

    wp_safe_redirect(admin_url('admin.php?page=bkiai-chat&settings-updated=1&active_tab=' . rawurlencode($activeTab)));
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
    $messageHeight = esc_attr($this->sanitize_message_area_height($design['height'], $this->get_default_design()['height']));
    $wrapperWidth = esc_attr($design['width']);
    $inputHeight = isset($design['input_height']) ? $this->sanitize_dimension($design['input_height'], $this->get_default_design()['input_height']) : $this->get_default_design()['input_height'];

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
        '--bkiai-bg:%s; --bkiai-header:%s; --bkiai-footer:%s; --bkiai-button:%s; --bkiai-expand-bg:%s; --bkiai-expand-text:%s; --bkiai-expand-border:%s; --bkiai-reset-text:%s; --bkiai-title-text:%s; --bkiai-border-width:%s; --bkiai-border-color:%s; --bkiai-bg-fill:%s; --bkiai-header-fill:%s; --bkiai-footer-fill:%s; --bkiai-button-fill:%s; --bkiai-expand-fill:%s; --bkiai-messages-height:%s; --bkiai-shadow:%s; --bkiai-chat-radius:%s; --bkiai-input-radius:%s; --bkiai-input-min-height:%s; width:%s;',
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
        $inputHeight,
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
                <div class="bkiai-chat-wrapper bkiai-chat-wrapper-popup" style="<?php echo esc_attr($style); ?>" data-bot-id="<?php echo esc_attr((string) $botIndex); ?>" data-welcome-message="<?php echo esc_attr($bot['welcome_message']); ?>" data-voice-enabled="<?php echo esc_attr($design['voice_enabled']); ?>" data-voice-gender="<?php echo esc_attr(isset($design['voice_reply_gender']) ? $design['voice_reply_gender'] : 'female'); ?>" data-show-sources="<?php echo esc_attr(isset($bot['show_sources']) ? $bot['show_sources'] : '1'); ?>" data-send-button-label="<?php echo esc_attr($sendButtonText); ?>">
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
                            <textarea class="bkiai-chat-input" rows="1" placeholder="<?php echo esc_attr($inputPlaceholderText); ?>"></textarea>
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
        <div class="bkiai-chat-wrapper" style="<?php echo esc_attr($style); ?>" data-bot-id="<?php echo esc_attr((string) $botIndex); ?>" data-welcome-message="<?php echo esc_attr($bot['welcome_message']); ?>" data-voice-enabled="<?php echo esc_attr($design['voice_enabled']); ?>" data-voice-gender="<?php echo esc_attr(isset($design['voice_reply_gender']) ? $design['voice_reply_gender'] : 'female'); ?>" data-show-sources="<?php echo esc_attr(isset($bot['show_sources']) ? $bot['show_sources'] : '1'); ?>" data-send-button-label="<?php echo esc_attr($sendButtonText); ?>">
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
                    <textarea class="bkiai-chat-input" rows="1" placeholder="<?php echo esc_attr($inputPlaceholderText); ?>"></textarea>
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

public function handle_chat_stream_request() {
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

    $response = $this->call_openai(
        $prepared['settings']['api_key'],
        $prepared['bot'],
        $prepared['message'],
        $prepared['history']
    );
    if (!is_wp_error($response)) {
        $response = $this->filter_response_sources_for_bot($response, $prepared['bot'], $prepared['settings']);
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

    $replyText = isset($response['reply']) ? (string) $response['reply'] : '';
    $this->increase_rate_usage($prepared['bot_index'], $prepared['message'], $replyText);
    $this->log_chat_interaction($prepared['bot_index'], $prepared['message'], $replyText, $prepared['page_url'], $prepared['page_title']);
    wp_send_json_success(array(
        'reply' => $replyText,
        'sources' => isset($response['sources']) ? $response['sources'] : array(),
        'image_url' => '',
        'image_alt' => '',
        'pdf_url' => '',
        'pdf_filename' => '',
    ));
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

    $burstCheck = $this->enforce_public_request_guard($settings);
    if (is_wp_error($burstCheck)) {
        return $burstCheck;
    }

    return array(
        'settings' => $settings,
        'message' => $message,
        'bot_index' => $botIndex,
        'bot' => $bot,
        'history' => is_array($history) ? $this->sanitize_recursive_textarea_field($history) : array(),
        'page_url' => $pageUrl,
        'page_title' => $pageTitle,
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

    $knowledgePayload = $this->get_relevant_knowledge_files_context($bot, $message);
    if (!empty($knowledgePayload['text'])) {
        $systemText .= "

Use this additional context when helpful. If the information is insufficient, state that clearly.

Knowledge file context:
" . $knowledgePayload['text'];
    }
    if (!empty($knowledgePayload['source_labels']) && is_array($knowledgePayload['source_labels'])) {
        $sourceHints = $knowledgePayload['source_labels'];
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

    return array(
        'model' => $bot['model'],
        'input' => $input,
    );
}

private function stream_text_response($apiKey, $bot, $message, $history, $pageUrl, $pageTitle, $botIndex) {
    $response = $this->call_openai($apiKey, $bot, $message, $history);
    if (!is_wp_error($response)) {
        $settings = $this->get_settings();
        $response = $this->filter_response_sources_for_bot($response, $bot, $settings);
    }

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
private function extract_keywords($text) {
        $text = $this->normalize_retrieval_text($text);
        if ($text === '') {
            return array();
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text);
        if (!is_array($parts)) {
            return array();
        }

        $stopwords = array(
            'und','oder','aber','doch','noch','auch','nicht','kein','keine','einer','eine','einem','einen','eines','der','die','das','den','dem','des',
            'ist','sind','war','waren','wird','werden','sein','mit','ohne','für','fuer','von','vom','zum','zur','aus','bei','im','in','am','an','auf','über','ueber','unter',
            'bitte','kann','kannst','soll','sollen','möchte','moechte','will','wollen','wie','was','welche','welcher','welches','wo','wann','warum',
            'the','and','for','with','from','this','that','these','those','into','about','your','you','are','was','were','can','could','should','would','please'
        );
        $stopwordLookup = array_fill_keys($stopwords, true);

        $keywords = array();
        foreach ($parts as $word) {
            $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            if ($word === '' || $length < 3) {
                continue;
            }
            if (isset($stopwordLookup[$word])) {
                continue;
            }
            $keywords[$word] = true;
        }

        return array_keys($keywords);
    }

    private function normalize_retrieval_text($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtolower(wp_strip_all_tags($text));
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    private function score_text($text, $keywords) {
        return $this->score_context_chunk($text, $keywords, '', '', '');
    }

    private function score_context_chunk($text, $keywords, $queryText = '', $sourceName = '', $heading = '') {
        $textNorm = $this->normalize_retrieval_text($text);
        if ($textNorm === '') {
            return 0;
        }
        if (empty($keywords)) {
            return 1;
        }

        $metaNorm = $this->normalize_retrieval_text($sourceName . ' ' . $heading);
        $score = 0;
        $matchedKeywordCount = 0;

        foreach ($keywords as $keyword) {
            $textHits = substr_count($textNorm, $keyword);
            $metaHits = substr_count($metaNorm, $keyword);
            if ($textHits > 0 || $metaHits > 0) {
                $matchedKeywordCount++;
            }
            $score += min(10, $textHits) * 4;
            $score += min(5, $metaHits) * 8;
        }

        $score += $matchedKeywordCount * 7;

        $normalizedQuery = $this->normalize_retrieval_text($queryText);
        if ($normalizedQuery !== '') {
            $queryLength = function_exists('mb_strlen') ? mb_strlen($normalizedQuery, 'UTF-8') : strlen($normalizedQuery);
            if ($queryLength >= 12 && strpos($textNorm, $normalizedQuery) !== false) {
                $score += 18;
            }
            if ($queryLength >= 12 && strpos($metaNorm, $normalizedQuery) !== false) {
                $score += 14;
            }
        }

        return $score;
    }

    private function trim_text($text, $maxLength = 2500) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length <= $maxLength) {
            return $text;
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength, 'UTF-8') . ' …' : substr($text, 0, $maxLength) . ' …';
    }

    private function get_relevant_knowledge_files_context($bot, $message) {
        if (empty($bot['knowledge_files']) || !is_array($bot['knowledge_files'])) {
            return array('text' => '', 'source_labels' => array());
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

            $chunks = $this->get_knowledge_file_chunks($file);
            if (empty($chunks)) {
                continue;
            }

            foreach ($chunks as $chunk) {
                $score = $this->score_context_chunk(
                    isset($chunk['text']) ? $chunk['text'] : '',
                    $keywords,
                    $message,
                    isset($chunk['source_name']) ? $chunk['source_name'] : '',
                    isset($chunk['heading']) ? $chunk['heading'] : ''
                );
                if ($score <= 0) {
                    continue;
                }
                $chunk['score'] = $score;
                $matches[] = $chunk;
            }
        }

        return $this->finalize_context_matches($matches, 'knowledge');
    }

    private function get_relevant_knowledge_files_text($bot, $message) {
        $payload = $this->get_relevant_knowledge_files_context($bot, $message);
        return isset($payload['text']) ? $payload['text'] : '';
    }

    private function get_knowledge_file_chunks($file) {
        if (empty($file['path']) || !file_exists($file['path'])) {
            return array();
        }

        $cacheKey = 'bkiai_kf_' . md5($file['path'] . '|' . @filemtime($file['path']) . '|' . @filesize($file['path']));
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $content = @file_get_contents($file['path']);
        if ($content === false || trim($content) === '') {
            return array();
        }

        $extension = !empty($file['type']) ? strtolower((string) $file['type']) : strtolower(pathinfo((string) $file['path'], PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            $chunks = $this->split_csv_content_into_chunks($content, isset($file['name']) ? $file['name'] : 'CSV');
        } elseif ($extension === 'md' || $extension === 'markdown') {
            $chunks = $this->split_markdown_into_chunks($content, isset($file['name']) ? $file['name'] : 'Markdown');
        } else {
            $chunks = $this->split_plain_text_into_chunks($content, isset($file['name']) ? $file['name'] : 'Text');
        }

        set_transient($cacheKey, $chunks, WEEK_IN_SECONDS);
        return $chunks;
    }

    private function split_markdown_into_chunks($content, $sourceName, $sourceUrl = '', $sourceType = 'Knowledge') {
        $content = str_replace(array("
", "
"), "
", (string) $content);
        $lines = explode("
", $content);
        $chunks = array();
        $currentHeading = '';
        $buffer = array();
        $chunkIndex = 0;

        $flushBuffer = function () use (&$buffer, &$chunks, &$chunkIndex, $sourceName, $sourceUrl, $sourceType, &$currentHeading) {
            if (empty($buffer)) {
                return;
            }
            $text = trim(implode("
", $buffer));
            $buffer = array();
            if ($text === '') {
                return;
            }
            $parts = $this->split_text_by_length($text, 900, 1200);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $chunkIndex++;
                $chunks[] = array(
                    'type' => strtolower($sourceType),
                    'source_name' => $sourceName,
                    'source_url' => $sourceUrl,
                    'heading' => $currentHeading,
                    'text' => $part,
                    'chunk_index' => $chunkIndex,
                );
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/u', trim($line), $matches)) {
                $flushBuffer();
                $currentHeading = trim($matches[1]);
                continue;
            }
            if (trim($line) === '') {
                $flushBuffer();
                continue;
            }
            $buffer[] = trim($line);
        }

        $flushBuffer();
        return $chunks;
    }

    private function split_plain_text_into_chunks($content, $sourceName, $sourceUrl = '', $sourceType = 'Knowledge') {
        $content = str_replace(array("
", "
"), "
", (string) $content);
        $paragraphs = preg_split('/
\s*
+/u', $content);
        if (!is_array($paragraphs)) {
            $paragraphs = array($content);
        }

        $chunks = array();
        $buffer = '';
        $chunkIndex = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $candidate = trim($buffer === '' ? $paragraph : $buffer . "

" . $paragraph);
            $length = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);
            if ($buffer !== '' && $length > 1000) {
                foreach ($this->split_text_by_length($buffer, 900, 1200) as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $chunkIndex++;
                    $chunks[] = array(
                        'type' => strtolower($sourceType),
                        'source_name' => $sourceName,
                        'source_url' => $sourceUrl,
                        'heading' => '',
                        'text' => $part,
                        'chunk_index' => $chunkIndex,
                    );
                }
                $buffer = $paragraph;
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            foreach ($this->split_text_by_length($buffer, 900, 1200) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $chunkIndex++;
                $chunks[] = array(
                    'type' => strtolower($sourceType),
                    'source_name' => $sourceName,
                    'source_url' => $sourceUrl,
                    'heading' => '',
                    'text' => $part,
                    'chunk_index' => $chunkIndex,
                );
            }
        }

        return $chunks;
    }

    private function split_csv_content_into_chunks($content, $sourceName) {
        $content = (string) $content;
        $lines = preg_split('/
|
|
/', $content);
        if (!is_array($lines) || empty($lines)) {
            return array();
        }

        $delimiter = $this->detect_csv_delimiter(isset($lines[0]) ? $lines[0] : '');

        try {
            $csvFile = new SplTempFileObject();
            $csvFile->fwrite($content);
            $csvFile->rewind();

            $header = $csvFile->fgetcsv($delimiter);
            if (!is_array($header)) {
                return $this->split_plain_text_into_chunks($content, $sourceName);
            }

            $chunks = array();
            $batch = array();
            $chunkIndex = 0;
            $rowNumber = 1;

            while (!$csvFile->eof()) {
                $row = $csvFile->fgetcsv($delimiter);
                if ($row === false || $row === array(null)) {
                    continue;
                }
                if (!is_array($row)) {
                    continue;
                }

                $rowNumber++;
                $pairs = array();
                foreach ($header as $index => $columnName) {
                    $columnName = trim((string) $columnName);
                    $value = isset($row[$index]) ? trim((string) $row[$index]) : '';
                    if ($columnName === '' && $value === '') {
                        continue;
                    }
                    $pairs[] = ($columnName !== '' ? $columnName : ('Column ' . ($index + 1))) . ': ' . $value;
                }
                if (empty($pairs)) {
                    continue;
                }

                $batch[] = 'Record ' . ($rowNumber - 1) . ' | ' . implode(' | ', $pairs);
                if (count($batch) >= 3) {
                    $chunkIndex++;
                    $chunks[] = array(
                        'type' => 'knowledge',
                        'source_name' => $sourceName,
                        'source_url' => '',
                        'heading' => 'CSV data',
                        'text' => implode("
", $batch),
                        'chunk_index' => $chunkIndex,
                    );
                    $batch = array();
                }
            }

            if (!empty($batch)) {
                $chunkIndex++;
                $chunks[] = array(
                    'type' => 'knowledge',
                    'source_name' => $sourceName,
                    'source_url' => '',
                    'heading' => 'CSV data',
                    'text' => implode("
", $batch),
                    'chunk_index' => $chunkIndex,
                );
            }

            return $chunks;
        } catch (Exception $e) {
            return $this->split_plain_text_into_chunks($content, $sourceName);
        }
    }

    private function detect_csv_delimiter($line) {
        $candidates = array(',', ';', "	", '|');
        $bestDelimiter = ',';
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $score = substr_count((string) $line, $candidate);
            if ($score > $bestScore) {
                $bestDelimiter = $candidate;
                $bestScore = $score;
            }
        }
        return $bestDelimiter;
    }

    private function split_text_by_length($text, $targetLength = 900, $hardLimit = 1200) {
        $text = trim((string) $text);
        if ($text === '') {
            return array();
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length <= $hardLimit) {
            return array($text);
        }

        $segments = preg_split('/(?<=[\.!?])\s+/u', $text);
        if (!is_array($segments) || empty($segments)) {
            return array($this->trim_text($text, $hardLimit));
        }

        $parts = array();
        $buffer = '';
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $candidate = trim($buffer === '' ? $segment : $buffer . ' ' . $segment);
            $candidateLength = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);
            if ($buffer !== '' && $candidateLength > $targetLength) {
                $parts[] = $buffer;
                $buffer = $segment;
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        $normalizedParts = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $normalizedParts[] = $part;
            }
        }

        return !empty($normalizedParts) ? $normalizedParts : array($this->trim_text($text, $hardLimit));
    }

    private function finalize_context_matches($matches, $defaultType = 'knowledge') {
        if (empty($matches)) {
            return array('text' => '', 'source_labels' => array());
        }

        usort($matches, function ($a, $b) {
            $scoreCompare = intval($b['score']) <=> intval($a['score']);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            return intval(isset($a['chunk_index']) ? $a['chunk_index'] : 0) <=> intval(isset($b['chunk_index']) ? $b['chunk_index'] : 0);
        });

        $selected = array();
        $selectedHashes = array();
        $combinedLength = 0;
        $maxCombinedLength = 7000;

        foreach ($matches as $match) {
            $chunkText = isset($match['text']) ? trim((string) $match['text']) : '';
            if ($chunkText === '') {
                continue;
            }
            $hash = md5($chunkText);
            if (isset($selectedHashes[$hash])) {
                continue;
            }
            $formatted = $this->format_context_chunk_for_prompt($match, $defaultType);
            $formattedLength = function_exists('mb_strlen') ? mb_strlen($formatted, 'UTF-8') : strlen($formatted);
            if (!empty($selected) && ($combinedLength + $formattedLength) > $maxCombinedLength) {
                continue;
            }
            $selected[] = $formatted;
            $selectedHashes[$hash] = true;
            $combinedLength += $formattedLength;
            if (count($selected) >= 6) {
                break;
            }
        }

        if (empty($selected)) {
            return array('text' => '', 'source_labels' => array());
        }

        $sourceLabels = array();
        foreach ($matches as $match) {
            $sourceLabels[] = $this->format_context_source_label($match, $defaultType);
        }

        return array(
            'text' => implode("

", $selected),
            'source_labels' => $this->limit_source_labels($sourceLabels, 6),
        );
    }

    private function format_context_chunk_for_prompt($chunk, $defaultType = 'knowledge') {
        $type = !empty($chunk['type']) ? $chunk['type'] : $defaultType;
        $sourceName = !empty($chunk['source_name']) ? $chunk['source_name'] : ucfirst($type);
        $heading = !empty($chunk['heading']) ? $chunk['heading'] : '';
        $url = !empty($chunk['source_url']) ? $chunk['source_url'] : '';

        $headerParts = array('Knowledge file', $sourceName);
        if ($heading !== '') {
            $headerParts[] = 'Section: ' . $heading;
        }
        if ($url !== '') {
            $headerParts[] = $url;
        }

        return '[' . implode(' | ', $headerParts) . "]
" . trim((string) $chunk['text']);
    }

    private function format_context_source_label($chunk, $defaultType = 'knowledge') {
        $type = !empty($chunk['type']) ? $chunk['type'] : $defaultType;
        $sourceName = !empty($chunk['source_name']) ? trim((string) $chunk['source_name']) : ucfirst($type);
        $heading = !empty($chunk['heading']) ? trim((string) $chunk['heading']) : '';

        $label = 'File: ' . $sourceName;
        if ($heading !== '') {
            $label .= ' · ' . $this->trim_text($heading, 38);
        }
        return $label;
    }

    private function limit_source_labels($labels, $maxItems = 8) {
        $labels = array_values(array_filter(array_map('sanitize_text_field', (array) $labels), function ($label) {
            return $label !== '';
        }));
        $unique = array();
        foreach ($labels as $label) {
            if (!isset($unique[$label])) {
                $unique[$label] = true;
            }
            if (count($unique) >= $maxItems) {
                break;
            }
        }
        return array_keys($unique);
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

    if ($statusCode >= 500) {
        return 'OpenAI is temporarily unavailable right now. Please try again shortly.';
    }

    if ($statusCode === 400) {
        return 'The request could not be processed. Please check the model, prompt, or knowledge sources and try again.';
    }

    if (!empty($rawMessage)) {
        return $rawMessage;
    }

    return 'OpenAI did not return a usable answer.';
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
