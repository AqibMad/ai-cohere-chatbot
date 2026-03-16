<?php
/**
 * Plugin Name: AI Support Chatbot
 * Description: 24/7 AI support chatbot that answers from your WordPress content, with professional human handoff.
 * Version: 3.2
 * Author: Your Name
 * Text Domain: ai-support-chatbot
 */

if (!defined('ABSPATH')) exit;

define('AI_CHATBOT_VERSION', '3.2');
define('AI_CHATBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load required classes
require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-ai-chatbot-db.php';
require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-ai-chatbot-api.php';
require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-ai-chatbot-agent.php';
require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-ai-chatbot-admin.php';
require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-ai-chatbot-frontend.php';
require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-ai-chatbot.php';

// Activation hook
register_activation_hook(__FILE__, 'ai_chatbot_activate');
function ai_chatbot_activate() {
    $db = new AI_Support_Chatbot_DB();
    $db->create_tables();

    if (!wp_next_scheduled('ai_hourly_embedding_update')) {
        wp_schedule_event(time(), 'hourly', 'ai_hourly_embedding_update');
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'ai_chatbot_deactivate');
function ai_chatbot_deactivate() {
    wp_clear_scheduled_hook('ai_hourly_embedding_update');
}

// Initialize the plugin
AI_Support_Chatbot::get_instance();