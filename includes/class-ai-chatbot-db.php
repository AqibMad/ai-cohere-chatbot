<?php
class AI_Support_Chatbot_DB {

    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Embeddings table
        $table1 = $wpdb->prefix . 'ai_post_embeddings';
        dbDelta("CREATE TABLE $table1 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT NOT NULL,
            embedding LONGTEXT NOT NULL,
            UNIQUE KEY post_id (post_id)
        ) $charset;");

        // Chat sessions table
        $table2 = $wpdb->prefix . 'ai_chat_sessions';
        dbDelta("CREATE TABLE $table2 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200),
            email VARCHAR(200),
            phone VARCHAR(50),
            status VARCHAR(50) DEFAULT 'ai_handling',
            agent_id BIGINT NULL,
            sentiment_score FLOAT DEFAULT 0,
            requires_escalation TINYINT(1) DEFAULT 0,
            escalation_reason VARCHAR(255),
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            escalated_at DATETIME NULL,
            resolved_at DATETIME NULL,
            KEY email (email),
            KEY status (status),
            KEY agent_id (agent_id)
        ) $charset;");

        // Chat messages table
        $table3 = $wpdb->prefix . 'ai_chat_messages';
        dbDelta("CREATE TABLE $table3 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT NOT NULL,
            role VARCHAR(20),
            message LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY session_id (session_id)
        ) $charset;");

        // Agents table
        $table4 = $wpdb->prefix . 'ai_chat_agents';
        dbDelta("CREATE TABLE $table4 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            is_online TINYINT(1) DEFAULT 0,
            max_chats INT DEFAULT 5,
            current_chats INT DEFAULT 0,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_id (user_id)
        ) $charset;");
    }
}