<?php
/*
Plugin Name: AI Support Chatbot
Description: 24/7 AI support chatbot that answers only from your WordPress posts/pages.
Version: 2.0
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

class AI_Support_Chatbot {

    private $api_key;
    private $embedding_model;
    private $chat_model;
    private $search_limit;
    private $similarity_threshold;
    private $post_types;
    private $max_history;

    public function __construct() {
        // Load settings
        $this->api_key              = get_option('ai_cohere_api_key', '');
        $this->embedding_model      = get_option('ai_embedding_model', 'embed-english-v2.0');
        $this->chat_model           = get_option('ai_chat_model', 'command-a-03-2025');
        $this->search_limit         = get_option('ai_search_limit', 10);
        $this->similarity_threshold = get_option('ai_similarity_threshold', 0.5);
        $this->post_types           = get_option('ai_post_types', ['post', 'page']);
        $this->max_history          = get_option('ai_max_history', 5); // number of previous messages to keep

        register_activation_hook(__FILE__, [$this, 'create_table']);
        register_activation_hook(__FILE__, [$this, 'activate_cron']);   // new
        register_deactivation_hook(__FILE__, [$this, 'deactivate_cron']); // new

        add_filter('cron_schedules', [$this, 'add_cron_interval']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('wp_ajax_ai_generate_embeddings', [$this, 'generate_embeddings']);
        add_action('wp_ajax_ai_stream_chat', [$this, 'stream_chat']);
        add_action('wp_ajax_nopriv_ai_stream_chat', [$this, 'stream_chat']);
        add_action('ai_hourly_embedding_update', [$this, 'cron_generate_embeddings']); // new
        add_shortcode('ai_chatbot', [$this, 'chatbot_ui']);
    }

    /* -----------------------------------------------
       DATABASE
    ----------------------------------------------- */

    public function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_post_embeddings';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT NOT NULL,
            embedding LONGTEXT NOT NULL,
            UNIQUE KEY post_id (post_id)
        ) $charset;");
    }

    /* -----------------------------------------------
       Add custom cron schedule for 10 minutes.
    ----------------------------------------------- */
    public function add_cron_interval($schedules) {
        $schedules['every_ten_minutes'] = [
            'interval' => 600, // 10 minutes in seconds
            'display'  => __('Every 10 Minutes')
        ];
        return $schedules;
    }
    
    /* -----------------------------------------------
       Schedule hourly cron on plugin activation.
    ----------------------------------------------- */
    public function activate_cron() {
        if (!wp_next_scheduled('ai_hourly_embedding_update')) {
            wp_schedule_event(time(), 'every_ten_minutes', 'ai_hourly_embedding_update');
        }
    }

    /* -----------------------------------------------
       Clear cron on deactivation.
    ----------------------------------------------- */
    public function deactivate_cron() {
        wp_clear_scheduled_hook('ai_hourly_embedding_update');
    }

    /* -----------------------------------------------
       ADMIN PANEL
    ----------------------------------------------- */

    public function admin_menu() {
        add_menu_page(
            'AI Support Chatbot',
            'AI Chatbot',
            'manage_options',
            'ai-support-chatbot',
            [$this, 'admin_page'],
            'dashicons-format-chat'
        );
    }

    public function admin_page() {
        // Save settings with nonce
        if (isset($_POST['save_settings']) && check_admin_referer('ai_chatbot_settings')) {
            update_option('ai_cohere_api_key', sanitize_text_field($_POST['api_key']));
            update_option('ai_embedding_model', sanitize_text_field($_POST['embedding_model']));
            update_option('ai_chat_model', sanitize_text_field($_POST['chat_model']));
            update_option('ai_search_limit', intval($_POST['search_limit']));
            update_option('ai_similarity_threshold', floatval($_POST['similarity_threshold']));
            update_option('ai_max_history', intval($_POST['max_history']));

            $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : [];
            update_option('ai_post_types', $post_types);

            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $api_key              = get_option('ai_cohere_api_key', '');
        $embedding_model      = get_option('ai_embedding_model', 'embed-english-v2.0');
        $chat_model           = get_option('ai_chat_model', 'command-a-03-2025');
        $search_limit         = get_option('ai_search_limit', 5);
        $similarity_threshold = get_option('ai_similarity_threshold', 0.7);
        $max_history          = get_option('ai_max_history', 5);
        $selected_post_types  = get_option('ai_post_types', ['post', 'page']);
        $all_post_types       = get_post_types(['public' => true], 'objects');
        ?>
        <div class="wrap">
            <h1>AI Support Chatbot Settings</h1>
            <form method="post">
                <?php wp_nonce_field('ai_chatbot_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>Cohere API Key</th>
                        <td><input type="text" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>Embedding Model</th>
                        <td>
                            <select name="embedding_model">
                                <option value="embed-english-v2.0" <?php selected($embedding_model, 'embed-english-v2.0'); ?>>embed-english-v2.0</option>
                                <option value="embed-english-v3.0" <?php selected($embedding_model, 'embed-english-v3.0'); ?>>embed-english-v3.0</option>
                                <option value="embed-multilingual-v2.0" <?php selected($embedding_model, 'embed-multilingual-v2.0'); ?>>embed-multilingual-v2.0</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Chat Model</th>
                        <td>
                            <select name="chat_model">
                                <option value="command-a-03-2025" <?php selected($chat_model, 'command-a-03-2025'); ?>>command-a-03-2025</option>
                                <option value="command-r-03-2025" <?php selected($chat_model, 'command-r-03-2025'); ?>>command-r-03-2025</option>
                                <option value="command-r-plus-03-2025" <?php selected($chat_model, 'command-r-plus-03-2025'); ?>>command-r-plus-03-2025</option>
                                <option value="command-light" <?php selected($chat_model, 'command-light'); ?>>command-light</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Number of search results</th>
                        <td><input type="number" name="search_limit" value="<?php echo esc_attr($search_limit); ?>" min="1" max="20" /></td>
                    </tr>
                    <tr>
                        <th>Similarity threshold (0-1)</th>
                        <td><input type="number" name="similarity_threshold" value="<?php echo esc_attr($similarity_threshold); ?>" min="0" max="1" step="0.05" />
                        <p class="description">Only posts with cosine similarity above this value will be used as context.</p></td>
                    </tr>
                    <tr>
                        <th>Max conversation history</th>
                        <td><input type="number" name="max_history" value="<?php echo esc_attr($max_history); ?>" min="0" max="20" />
                        <p class="description">Number of previous messages to keep for context (0 = single‑turn).</p></td>
                    </tr>
                    <tr>
                        <th>Post Types to Index</th>
                        <td>
                            <?php foreach ($all_post_types as $pt) : ?>
                                <label>
                                    <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($pt->name); ?>" 
                                        <?php checked(in_array($pt->name, $selected_post_types)); ?> />
                                    <?php echo esc_html($pt->label); ?>
                                </label><br />
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="save_settings" class="button-primary" value="Save Settings" /></p>
            </form>

            <hr />
            <h2>Generate Embeddings</h2>
            <p>Click below to create embeddings for all selected post types. This may take a while on large sites.</p>
            <button id="generate-embeddings" class="button button-secondary">Generate Embeddings</button>
            <p id="embedding-status"></p>
            <script>
            document.getElementById('generate-embeddings').onclick = function() {
                document.getElementById('embedding-status').innerText = 'Processing...';
                fetch(ajaxurl + '?action=ai_generate_embeddings')
                .then(r => r.text())
                .then(t => document.getElementById('embedding-status').innerText = t);
            };
            </script>
        </div>
        <?php
    }

    /* -----------------------------------------------
       EMBEDDINGS & SEARCH
    ----------------------------------------------- */

        /**
     * Generate embedding with detailed error handling.
     *
     * @param string $text The text to embed.
     * @return array|false Returns embedding array on success, or false on failure.
     *                     On failure, it logs the error and returns false.
     */
    private function generate_embedding($text) {
        if (empty($this->api_key)) {
            error_log('AI Chatbot: API key is empty.');
            return false;
        }

        $url = 'https://api.cohere.com/v2/embed';
        $body = [
            'model' => $this->embedding_model,
            'texts' => [$text]
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body'    => json_encode($body),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            error_log('AI Chatbot: WP_Error - ' . $response->get_error_message());
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? $data['error'] : 'Unknown API error');
            error_log("AI Chatbot: HTTP $http_code - $error_msg");
            return false;
        }

        // Correct extraction: embeddings are under the key 'float' (or int8/uint8)
        if (isset($data['embeddings']['float'][0])) {
            return $data['embeddings']['float'][0];
        } elseif (isset($data['embeddings']['int8'][0])) {
            return $data['embeddings']['int8'][0];
        } elseif (isset($data['embeddings']['uint8'][0])) {
            return $data['embeddings']['uint8'][0];
        } else {
            error_log('AI Chatbot: No embedding found in response: ' . $body);
            return false;
        }
    }

    /**
     * Generate embeddings for all selected posts with detailed error reporting.
     */
    public function generate_embeddings() {
        global $wpdb;

        // Check API key
        if (empty($this->api_key)) {
            wp_die('Error: Cohere API key is not set. Please save it in the admin panel.');
        }

        $post_types = get_option('ai_post_types', ['post', 'page']);
        if (empty($post_types)) {
            wp_die('Error: No post types selected for indexing.');
        }

        $posts = get_posts([
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids', // get only IDs to reduce memory
        ]);

        $total = count($posts);
        if ($total === 0) {
            wp_die('No published posts found for the selected post types.');
        }

        $table = $wpdb->prefix . 'ai_post_embeddings';
        $success = 0;
        $errors = [];

        foreach ($posts as $post_id) {
            $post = get_post($post_id);
            $content = $post->post_title . ' ' . wp_trim_words($post->post_content, 1000);
            
            $embedding = $this->generate_embedding($content);
            if ($embedding === false) {
                // Error already logged; we just count it
                $errors[] = "Post ID $post_id";
                continue;
            }

            $wpdb->replace($table, [
                'post_id'   => $post_id,
                'embedding' => json_encode($embedding)
            ]);
            $success++;
        }

        // Build output message
        $message = "Total posts found: $total\n";
        $message .= "Embeddings generated successfully: $success\n";

        if (!empty($errors)) {
            $message .= "Failed posts (" . count($errors) . "): " . implode(', ', array_slice($errors, 0, 10));
            if (count($errors) > 10) $message .= '...';
        }

        // Add a note to check error logs
        // $message .= "\n\nPlease check your WordPress error log (wp-content/debug.log if WP_DEBUG enabled) for detailed API errors.";

        echo nl2br(esc_html($message));
        wp_die();
    }

    private function cosine_similarity($a, $b) {
        $dot = 0; $normA = 0; $normB = 0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA == 0 || $normB == 0) return 0;
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Semantic search with similarity threshold.
     */
    private function semantic_search($query, $limit = 5) {
        global $wpdb;
        $query_emb = $this->generate_embedding($query);
        if (!$query_emb) return [];

        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ai_post_embeddings");
        $scores = [];

        foreach ($rows as $row) {
            $emb = json_decode($row->embedding, true);
            if (!$emb) continue;
            $score = $this->cosine_similarity($query_emb, $emb);
            if ($score >= $this->similarity_threshold) {
                $scores[] = [
                    'post_id' => $row->post_id,
                    'score'   => $score
                ];
            }
        }

        // Sort by score descending
        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        $ids = array_slice(array_column($scores, 'post_id'), 0, $limit);
        if (empty($ids)) return [];

        return get_posts(['post__in' => $ids, 'orderby' => 'post__in', 'post_type' => 'any']);
    }

    /**
     * Hybrid search: combine keyword (WP search) and semantic.
     */
    private function hybrid_search($query, $limit = 5) {
        $wp_results = get_posts([
            's'              => $query,
            'posts_per_page' => $limit * 2, // get more for merging
            'post_type'      => $this->post_types,
            'post_status'    => 'publish'
        ]);

        $semantic = $this->semantic_search($query, $limit * 2);

        // Merge and deduplicate
        $merged = array_merge($wp_results, $semantic);
        $unique = [];
        foreach ($merged as $p) {
            $unique[$p->ID] = $p;
        }

        // Optionally re-rank by relevance (semantic score) – here we just take first $limit
        return array_slice($unique, 0, $limit);
    }

    /* -----------------------------------------------
       CHAT HANDLING (with history support)
    ----------------------------------------------- */

    /**
     * Extract response text from Cohere API (handles multiple formats).
     */
    private function extract_cohere_text($data) {
        // v2 chat format: { "message": { "content": [ {"type":"text","text":"..."} ] } }
        if (isset($data['message']['content']) && is_array($data['message']['content'])) {
            foreach ($data['message']['content'] as $content) {
                if (isset($content['text'])) {
                    return $content['text'];
                }
            }
        }

        // Alternative v2 format: { "text": [ {"type":"text","text":"..."} ] }
        if (isset($data['text']) && is_array($data['text'])) {
            foreach ($data['text'] as $item) {
                if (isset($item['text'])) {
                    return $item['text'];
                }
            }
        }

        // v1 generate format: { "generations": [ {"text": "..."} ] }
        if (isset($data['generations']) && is_array($data['generations']) && isset($data['generations'][0]['text'])) {
            return $data['generations'][0]['text'];
        }

        // Simple string fallback
        if (isset($data['text']) && is_string($data['text'])) {
            return $data['text'];
        }
        if (isset($data['response']) && is_string($data['response'])) {
            return $data['response'];
        }

        return null;
    }

    /**
     * Main AJAX handler for chat (SSE).
     */
    public function stream_chat() {

        if (!$this->check_rate_limit()) {
            wp_send_json_error(['text' => 'Too many requests. Please wait a moment.']);
        }

        if (empty($this->api_key)) {
            wp_send_json_error(['text' => 'Chatbot is not configured yet.']);
        }

        $query = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
        if (empty($query)) {
            wp_send_json_error(['text' => 'Please type a message.']);
        }

        $history = isset($_POST['history']) ? json_decode(stripslashes($_POST['history']), true) : [];
        if (!is_array($history)) $history = [];

        if (count($history) > $this->max_history * 2) {
            $history = array_slice($history, -$this->max_history * 2);
        }

        $posts = $this->hybrid_search($query, $this->search_limit);

        $context = '';
        foreach ($posts as $post) {
            $context .= "Title: " . $post->post_title . "\n";
            $context .= "Content: " . wp_trim_words($post->post_content, 1000) . "\n\n";
        }

        if (empty($context)) {
            $context = "No relevant information found in the knowledge base.";
        }

        $system_prompt = "You are a support assistant for " . get_bloginfo('name') . ". ";
        $system_prompt .= "Answer only from the context below. ";
        $system_prompt .= "If not found, say you don't have that information.\n\n";
        $system_prompt .= "Context:\n" . $context;

        $messages = [['role' => 'system', 'content' => $system_prompt]];

        foreach ($history as $turn) {
            if (isset($turn['role'], $turn['content'])) {
                $messages[] = $turn;
            }
        }

        $messages[] = ['role' => 'user', 'content' => $query];

        $response = wp_remote_post('https://api.cohere.com/v2/chat', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body' => json_encode([
                'model' => $this->chat_model,
                'messages' => $messages,
                'temperature' => 0.3
            ]),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['text' => $response->get_error_message()]);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $this->extract_cohere_text($data);

        if (!$text) {
            wp_send_json_error(['text' => 'Could not process response.']);
        }

        wp_send_json_success(['text' => $text]);
    }

    private function check_rate_limit() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $key = 'ai_chat_limit_' . md5($ip);
        $count = get_transient($key);
        if ($count && $count >= 10) return false;
        set_transient($key, $count ? $count + 1 : 1, MINUTE_IN_SECONDS);
        return true;
    }

    /* -----------------------------------------------
       Cron job to generate embeddings silently (no output).
    ----------------------------------------------- */
    
    public function cron_generate_embeddings() {
        // Prevent running if API key missing
        if (empty($this->api_key)) {
            error_log('AI Chatbot Cron: API key missing. Skipping embedding generation.');
            return;
        }

        global $wpdb;
        $post_types = get_option('ai_post_types', ['post', 'page']);
        if (empty($post_types)) {
            error_log('AI Chatbot Cron: No post types selected.');
            return;
        }

        $posts = get_posts([
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);

        if (empty($posts)) {
            error_log('AI Chatbot Cron: No published posts found.');
            return;
        }

        $table = $wpdb->prefix . 'ai_post_embeddings';
        $success = 0;
        $errors = 0;

        foreach ($posts as $post_id) {
            $post = get_post($post_id);
            $content = $post->post_title . ' ' . wp_trim_words($post->post_content, 1000);
            
            $embedding = $this->generate_embedding($content);
            if ($embedding === false) {
                $errors++;
                continue;
            }

            $wpdb->replace($table, [
                'post_id'   => $post_id,
                'embedding' => json_encode($embedding)
            ]);
            $success++;
        }

        error_log("AI Chatbot Cron: Completed. Success: $success, Errors: $errors");
    }

    /* -----------------------------------------------
       FRONTEND UI (with history)
    ----------------------------------------------- */

    public function chatbot_ui() {
        $session_id = 'chat_' . md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
        ob_start();
        ?>
        <style>
            /* Floating Chat Widget Styles */
            #ai-chat-widget {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 9999;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            }

            /* Toggle Button */
            #ai-chat-toggle {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                background-color: #dd7500;
                color: white;
                border: none;
                cursor: pointer;
                box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                font-size: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: transform 0.2s;
            }
            #ai-chat-toggle:hover { transform: scale(1.1); }

            /* Chat Panel */
            #ai-chat-panel {
                position: absolute;
                bottom: 80px;
                right: 0;
                width: 350px;
                max-width: calc(100vw - 40px);
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.2);
                overflow: hidden;
                display: none;
                flex-direction: column;
                border: 1px solid #ddd;
            }
            #ai-chat-panel.open { display: flex; }

            /* Panel Header */
            #ai-chat-header {
                background-color: #dd7500;
                color: white;
                padding: 12px 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-weight: bold;
            }
            #ai-chat-header button {
                background: none;
                border: none;
                color: white;
                font-size: 20px;
                cursor: pointer;
                line-height: 1;
            }

            /* Chat Box */
            #ai-chat-box {
                display: flex;
                flex-direction: column;
                height: 350px;
                overflow-y: auto;
                padding: 12px;
                background-color: #f9f9f9;
                border-bottom: 1px solid #eee;
                font-size: 14px;
            }

            /* Input area */
            #ai-chat-input-area {
                display: flex;
                padding: 10px;
                background-color: white;
                border-top: 1px solid #eee;
            }
            #ai-chat-input {
                flex: 1;
                padding: 8px 12px;
                border: 1px solid #ccc;
                border-radius: 20px;
                outline: none;
                font-size: 14px;
                color: #414042;
            }
            #ai-chat-input:focus { border-color: #dd7500; }
            #ai-chat-send {
                background-color: #dd7500;
                color: white;
                border: none;
                border-radius: 20px;
                padding: 8px 16px;
                margin-left: 8px;
                cursor: pointer;
                font-weight: bold;
                font-size: 14px;
            }
            #ai-chat-send:hover { background-color: #c46400; }

            /* Chat messages */
            .chat-message {
                white-space: pre-wrap;
                margin-bottom: 10px;
                padding: 10px 14px;
                border-radius: 18px;
                max-width: 75%;
                word-wrap: break-word;
                font-size: 14px;
                display: inline-block;
                line-height: 1.4;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .chat-user {
                background-color: #ffe5b3;
                color: #414042;
                align-self: flex-end;
                text-align: right;
            }
            .chat-ai {
                background-color: #e1f0ff;
                color: #000;
                align-self: flex-start;
                text-align: left;
            }
            .chat-typing {
                font-style: italic;
                color: #888;
            }
        </style>

        <div id="ai-chat-widget">
            <button id="ai-chat-toggle" aria-label="Open chat">💬</button>
            <div id="ai-chat-panel">
                <div id="ai-chat-header">
                    <span>Support Chat</span>
                    <button id="ai-chat-close" aria-label="Close chat">✕</button>
                </div>
                <div id="ai-chat-box"></div>
                <div id="ai-chat-input-area">
                    <input type="text" id="ai-chat-input" placeholder="Type your question..." />
                    <button id="ai-chat-send">Send</button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const chatBox = document.getElementById('ai-chat-box');
            const input = document.getElementById('ai-chat-input');
            const sendBtn = document.getElementById('ai-chat-send');
            const toggleBtn = document.getElementById('ai-chat-toggle');
            const closeBtn = document.getElementById('ai-chat-close');
            const panel = document.getElementById('ai-chat-panel');

            let history = [];

            // Load chat history
            const saved = sessionStorage.getItem('ai_chat_history');
            if (saved) {
                try {
                    history = JSON.parse(saved);
                    history.forEach(msg => {
                        const cls = msg.role === 'user' ? 'chat-user' : 'chat-ai';
                        const sender = msg.role === 'user' ? 'You' : 'AI';
                        chatBox.innerHTML += `<div class="chat-message ${cls}"><b>${sender}:</b> ${escapeHtml(msg.content)}</div>`;
                    });
                    chatBox.scrollTop = chatBox.scrollHeight;
                } catch (e) {}
            }

            function escapeHtml(unsafe) {
                return unsafe.replace(/[&<>"]/g, m => {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    if (m === '"') return '&quot;';
                    return m;
                });
            }

            async function sendMessage() {
                const msg = input.value.trim();
                if (!msg) return;

                chatBox.innerHTML += `<div class="chat-message chat-user"><b>You:</b> ${escapeHtml(msg)}</div>`;
                chatBox.scrollTop = chatBox.scrollHeight;
                input.value = '';

                history.push({ role: 'user', content: msg });

                const typingDiv = document.createElement('div');
                typingDiv.className = 'chat-message chat-ai chat-typing';
                typingDiv.innerHTML = '<b>AI:</b> typing...';
                chatBox.appendChild(typingDiv);

                try {
                    const response = await fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            action: "ai_stream_chat",
                            message: msg,
                            history: JSON.stringify(history)
                        })
                    });

                    const result = await response.json();
                    typingDiv.remove();

                    if (result.success) {
                        chatBox.innerHTML += `<div class="chat-message chat-ai"><b>AI:</b> ${escapeHtml(result.data.text)}</div>`;
                        history.push({ role: 'assistant', content: result.data.text });
                    } else {
                        chatBox.innerHTML += `<div class="chat-message chat-ai"><b>AI:</b> ${escapeHtml(result.data.text)}</div>`;
                    }

                    chatBox.scrollTop = chatBox.scrollHeight;
                    sessionStorage.setItem('ai_chat_history', JSON.stringify(history));

                } catch (err) {
                    typingDiv.remove();
                    chatBox.innerHTML += `<div class="chat-message chat-ai chat-typing"><b>AI:</b> Connection error. Try again.</div>`;
                }
            }

            toggleBtn.addEventListener('click', () => panel.classList.add('open'));
            closeBtn.addEventListener('click', () => panel.classList.remove('open'));
            sendBtn.addEventListener('click', sendMessage);
            input.addEventListener('keypress', e => { if (e.key === 'Enter') sendMessage(); });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

new AI_Support_Chatbot();