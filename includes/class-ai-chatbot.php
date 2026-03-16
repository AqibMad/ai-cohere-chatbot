<?php
class AI_Support_Chatbot {

    private static $instance = null;

    public $db;
    public $api;
    public $agent;
    public $admin;
    public $frontend;

    private function __construct() {
        $this->db       = new AI_Support_Chatbot_DB();
        $this->api      = new AI_Support_Chatbot_API();
        $this->agent    = new AI_Support_Chatbot_Agent();
        $this->admin    = new AI_Support_Chatbot_Admin();
        $this->frontend = new AI_Support_Chatbot_Frontend();
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function schedule_cron() {
        if (!wp_next_scheduled('ai_hourly_embedding_update')) {
            wp_schedule_event(time(), 'hourly', 'ai_hourly_embedding_update');
        }
    }

    private function clear_cron() {
        wp_clear_scheduled_hook('ai_hourly_embedding_update');
    }

    // Cron callback (delegated to API class)
    public function cron_generate_embeddings() {
        $this->api->cron_generate_embeddings();
    }
}