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

        // Hook cron job
        add_action('ai_hourly_embedding_update', [$this->api, 'cron_generate_embeddings']);
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}