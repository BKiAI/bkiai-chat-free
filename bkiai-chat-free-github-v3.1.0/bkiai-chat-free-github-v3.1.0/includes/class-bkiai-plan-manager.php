<?php
if (!defined('ABSPATH')) exit;

class BKiAI_Plan_Manager {
    const BUILD_FREE    = 'free';
    const BUILD_PREMIUM = 'premium';

    const PLAN_FREE   = 'free';
    const PLAN_PRO    = 'pro';
    const PLAN_EXPERT = 'expert';

    public static function get_build_type() {
        // Aktueller Entwicklungsstand:
        // Wir arbeiten weiterhin auf der Premium-Basis.
        // Für die spätere Free-ZIP muss hier nur auf BUILD_FREE umgestellt werden.
        return self::BUILD_FREE;
    }

    public static function is_free_build() {
        return self::get_build_type() === self::BUILD_FREE;
    }

    public static function is_premium_build() {
        return self::get_build_type() === self::BUILD_PREMIUM;
    }

    public static function get_current_plan() {
        // Free-Build ist immer Free.
        if (self::is_free_build()) {
            return self::PLAN_FREE;
        }

        // Premium-Build:
        // Solange die echte EDD-/Lizenzanbindung noch nicht verdrahtet ist,
        // lassen wir die Entwicklungsbasis bewusst auf Expert.
        return self::PLAN_EXPERT;
    }

    public static function get_plan_config($plan = null) {
        if (!$plan) {
            $plan = self::get_current_plan();
        }

        $configs = array(
            self::PLAN_FREE => array(
                'max_active_bots'         => 1,
                'can_duplicate_bots'      => false,
                'can_delete_dynamic_bots' => false,
                'chat_logs'               => false,
                'voice'                   => true,
                'voice_realtime'          => false,
                'image_generation'        => false,
                'pdf_generation'          => false,
                'web_search'              => false,
                'website_knowledge'       => false,
                'max_knowledge_files'     => 1,
                'allowed_models'          => array('gpt-4o-mini', 'gpt-4.1-mini'),
                'popup_bot_1'             => true,
                'full_model_access'       => false,
                'license_tab'             => true,
            ),

            self::PLAN_PRO => array(
                'max_active_bots'         => 2,
                'can_duplicate_bots'      => false,
                'can_delete_dynamic_bots' => false,
                'chat_logs'               => true,
                'voice'                   => true,
                'voice_realtime'          => true,
                'image_generation'        => true,
                'pdf_generation'          => true,
                'web_search'              => true,
                'website_knowledge'       => true,
                'max_knowledge_files'     => 999,
                'allowed_models'          => array(),
                'popup_bot_1'             => true,
                'full_model_access'       => true,
                'license_tab'             => true,
            ),

            self::PLAN_EXPERT => array(
                'max_active_bots'         => 20,
                'can_duplicate_bots'      => true,
                'can_delete_dynamic_bots' => true,
                'chat_logs'               => true,
                'voice'                   => true,
                'voice_realtime'          => true,
                'image_generation'        => true,
                'pdf_generation'          => true,
                'web_search'              => true,
                'website_knowledge'       => true,
                'max_knowledge_files'     => 999,
                'allowed_models'          => array(),
                'popup_bot_1'             => true,
                'full_model_access'       => true,
                'license_tab'             => true,
            ),
        );

        return isset($configs[$plan]) ? $configs[$plan] : $configs[self::PLAN_FREE];
    }

    public static function can_use_feature($feature) {
        $config = self::get_plan_config();
        return !empty($config[$feature]);
    }

    public static function get_max_active_bots() {
        $config = self::get_plan_config();
        return intval($config['max_active_bots']);
    }

    public static function get_max_knowledge_files() {
        $config = self::get_plan_config();
        return intval($config['max_knowledge_files']);
    }

    public static function is_model_allowed($model) {
        $config = self::get_plan_config();

        if (!empty($config['full_model_access'])) {
            return true;
        }

        return in_array($model, $config['allowed_models'], true);
    }

    public static function is_bot_accessible($bot_number) {
        $config = self::get_plan_config();
        return intval($bot_number) <= intval($config['max_active_bots']);
    }

    public static function get_upgrade_base_url() {
        return apply_filters(
            'bkiai_upgrade_base_url',
            'https://businesskiai.de/downloads/bkiai-chatbot-premium/'
        );
    }

    public static function get_upgrade_url($context = 'general', $plan = '') {
        $base_url = self::get_upgrade_base_url();

        $args = array(
            'utm_source'   => 'bkiai-chat-free',
            'utm_medium'   => 'plugin',
            'utm_campaign' => 'upgrade',
            'utm_content'  => sanitize_key($context),
        );

        if (!empty($plan)) {
            $args['plan'] = sanitize_key($plan);
        }

        return add_query_arg($args, $base_url);
    }
}
