<?php
/**
 * Uninstall handler for BKiAI Chat Free.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$bkiai_option_key = 'bkiai_chat_settings';
$bkiai_notice_transient_key = 'bkiai_chat_admin_notice';
$bkiai_log_purge_option = 'bkiai_chat_log_last_purge';

delete_option($bkiai_option_key);
delete_site_option($bkiai_option_key);
delete_transient($bkiai_notice_transient_key);
delete_option($bkiai_log_purge_option);
delete_site_option($bkiai_log_purge_option);

