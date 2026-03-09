<?php
/*
Plugin Name: AI Support Chatbot
Description: 24/7 AI support chatbot that answers only from your WordPress posts/pages.
Version: 2.2
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
    private $last_api_error = ''; // Stores the last API error for display

    public function __construct() {
        // Load settings
        $this->api_key              = get_option('ai_cohere_api_key', '');
        $this->embedding_model      = get_option('ai_embedding_model', 'embed-english-v2.0');
        $this->chat_model           = get_option('ai_chat_model', 'command-a-03-2025');
        $this->search_limit         = get_option('ai_search_limit', 10);
        $this->similarity_threshold = get_option('ai_similarity_threshold', 0.5);
        $this->post_types           = get_option('ai_post_types', ['post', 'page']);
        $this->max_history          = get_option('ai_max_history', 5);

        register_activation_hook(__FILE__, [$this, 'create_table']);
        register_activation_hook(__FILE__, [$this, 'activate_cron']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_cron']);

        // Optional: remove this line if you deleted the method
        // add_filter('cron_schedules', [$this, 'add_cron_interval']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('wp_ajax_ai_generate_embeddings', [$this, 'generate_embeddings']);
        add_action('wp_ajax_ai_stream_chat', [$this, 'stream_chat']);
        add_action('wp_ajax_nopriv_ai_stream_chat', [$this, 'stream_chat']);
        add_action('ai_hourly_embedding_update', [$this, 'cron_generate_embeddings']);

        add_action('wp_ajax_ai_save_chat_session', [$this,'save_chat_session']);
        add_action('wp_ajax_nopriv_ai_save_chat_session', [$this,'save_chat_session']);

        add_action('wp_ajax_ai_save_chat', [$this,'save_chat']);
        add_action('wp_ajax_nopriv_ai_save_chat', [$this,'save_chat']);
                
        add_shortcode('ai_chatbot', [$this, 'chatbot_ui']);

    }

    /* -----------------------------------------------
       DATABASE
    ----------------------------------------------- */
    public function create_table() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // embeddings table (existing)
        $table1 = $wpdb->prefix . 'ai_post_embeddings';

        dbDelta("CREATE TABLE $table1 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT NOT NULL,
            embedding LONGTEXT NOT NULL,
            UNIQUE KEY post_id (post_id)
        ) $charset;");

        // chat sessions table
        $table2 = $wpdb->prefix . 'ai_chat_sessions';

        dbDelta("CREATE TABLE $table2 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200),
            email VARCHAR(200),
            phone VARCHAR(50),
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;");

        // chat messages table
        $table3 = $wpdb->prefix . 'ai_chat_messages';

        dbDelta("CREATE TABLE $table3 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT NOT NULL,
            role VARCHAR(20),
            message LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY session_id (session_id)
        ) $charset;");
    }

    /* -----------------------------------------------
       CRON
    ----------------------------------------------- */
    // public function add_cron_interval($schedules) {
    //     $schedules['every_ten_minutes'] = [
    //         'interval' => 600,
    //         'display'  => __('Every 10 Minutes')
    //     ];
    //     return $schedules;
    // }

    public function activate_cron() {
        if (!wp_next_scheduled('ai_hourly_embedding_update')) {
            wp_schedule_event(time(), 'hourly', 'ai_hourly_embedding_update');
        }
    }

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
        // Save settings
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
        $search_limit         = get_option('ai_search_limit', 10);
        $similarity_threshold = get_option('ai_similarity_threshold', 0.5);
        $max_history          = get_option('ai_max_history', 5);
        $selected_post_types  = get_option('ai_post_types', ['post', 'page']);
        $all_post_types       = get_post_types(['public' => true], 'objects');
        ?>
        <div class="wrap">
            <h1>AI Support Chatbot Settings</h1>
            <form method="post">
                <?php wp_nonce_field('ai_chatbot_settings'); ?>
                <table class="form-table">
                    <tr><th>Cohere API Key</th><td><input type="text" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></td></tr>
                    <tr><th>Embedding Model</th><td><select name="embedding_model"><?php
                        $models = ['embed-english-v2.0','embed-english-v3.0','embed-multilingual-v2.0'];
                        foreach($models as $m) echo "<option value='$m' ".selected($embedding_model,$m,false).">$m</option>";
                    ?></select></td></tr>
                    <tr><th>Chat Model</th><td><select name="chat_model"><?php
                        $chat_models = ['command-a-03-2025','command-r-03-2025','command-r-plus-03-2025','command-light'];
                        foreach($chat_models as $cm) echo "<option value='$cm' ".selected($chat_model,$cm,false).">$cm</option>";
                    ?></select></td></tr>
                    <tr><th>Number of search results</th><td><input type="number" name="search_limit" value="<?php echo esc_attr($search_limit); ?>" min="1" max="20" /></td></tr>
                    <tr><th>Similarity threshold (0-1)</th><td><input type="number" name="similarity_threshold" value="<?php echo esc_attr($similarity_threshold); ?>" min="0" max="1" step="0.05" /><p class="description">Only posts with cosine similarity above this value will be used as context.</p></td></tr>
                    <tr><th>Max conversation history</th><td><input type="number" name="max_history" value="<?php echo esc_attr($max_history); ?>" min="0" max="20" /><p class="description">Number of previous messages to keep for context (0 = single‑turn).</p></td></tr>
                    <tr><th>Post Types to Index</th><td><?php foreach ($all_post_types as $pt) : ?><label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $selected_post_types)); ?> /> <?php echo esc_html($pt->label); ?></label><br /><?php endforeach; ?></td></tr>
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
    private function generate_embedding($text) {
        if (empty($this->api_key)) {
            $this->last_api_error = 'API key is empty.';
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
            $this->last_api_error = 'WP_Error: ' . $response->get_error_message();
            error_log('AI Chatbot: ' . $this->last_api_error);
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? $data['error'] : 'Unknown API error');
            $this->last_api_error = "HTTP $http_code - $error_msg";
            error_log('AI Chatbot: ' . $this->last_api_error);
            return false;
        }

        if (isset($data['embeddings']['float'][0])) {
            return $data['embeddings']['float'][0];
        } elseif (isset($data['embeddings']['int8'][0])) {
            return $data['embeddings']['int8'][0];
        } elseif (isset($data['embeddings']['uint8'][0])) {
            return $data['embeddings']['uint8'][0];
        } else {
            $this->last_api_error = 'No embedding found in response.';
            error_log('AI Chatbot: ' . $this->last_api_error . ' - ' . $body);
            return false;
        }
    }

    public function generate_embeddings() {
        global $wpdb;

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
            'fields'         => 'ids',
        ]);

        $total = count($posts);
        if ($total === 0) {
            wp_die('No published posts found for the selected post types.');
        }

        $table = $wpdb->prefix . 'ai_post_embeddings';
        $success = 0;
        $errors = [];
        $fatal_error = false;

        foreach ($posts as $post_id) {
            $post = get_post($post_id);
            $content = $post->post_title . ' ' . wp_trim_words($post->post_content, 1000);
            
            $embedding = $this->generate_embedding($content);
            if ($embedding === false) {
                $errors[] = "Post ID $post_id";
                // If first post fails and it's a rate limit or auth error, abort
                if (empty($success) && strpos($this->last_api_error, '429') !== false) {
                    $fatal_error = 'API rate limit exceeded. Please try again later or upgrade your API key.';
                    break;
                }
                if (empty($success) && strpos($this->last_api_error, '401') !== false) {
                    $fatal_error = 'Invalid API key. Please check your Cohere API key.';
                    break;
                }
                if (empty($success) && strpos($this->last_api_error, '400') !== false) {
                    $fatal_error = 'API error: ' . $this->last_api_error;
                    break;
                }
                continue;
            }

            $wpdb->replace($table, [
                'post_id'   => $post_id,
                'embedding' => json_encode($embedding)
            ]);
            $success++;
        }

        if ($fatal_error) {
            wp_die('<span style="color:#a00;">Error: ' . esc_html($fatal_error) . '</span><br>No embeddings were generated.');
        }

        $message = "Total posts found: $total\n";
        $message .= "Embeddings generated successfully: $success\n";

        if (!empty($errors)) {
            $message .= "Failed posts (" . count($errors) . "): " . implode(', ', array_slice($errors, 0, 10));
            if (count($errors) > 10) $message .= '...';
        }

        // Add a note about checking error log if there were failures
        if (!empty($errors)) {
            $message .= "\n\nCheck your WordPress error log for API error details.";
        }

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

        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        $ids = array_slice(array_column($scores, 'post_id'), 0, $limit);
        if (empty($ids)) return [];

        return get_posts(['post__in' => $ids, 'orderby' => 'post__in', 'post_type' => 'any']);
    }

    private function hybrid_search($query, $limit = 5) {
        $wp_results = get_posts([
            's'              => $query,
            'posts_per_page' => $limit * 2,
            'post_type'      => $this->post_types,
            'post_status'    => 'publish'
        ]);

        $semantic = $this->semantic_search($query, $limit * 2);

        $merged = array_merge($wp_results, $semantic);
        $unique = [];
        foreach ($merged as $p) {
            $unique[$p->ID] = $p;
        }

        return array_slice($unique, 0, $limit);
    }

    /* -----------------------------------------------
       CHAT HANDLING
    ----------------------------------------------- */
    private function extract_cohere_text($data) {
        if (isset($data['message']['content']) && is_array($data['message']['content'])) {
            foreach ($data['message']['content'] as $content) {
                if (isset($content['text'])) {
                    return $content['text'];
                }
            }
        }
        if (isset($data['text']) && is_array($data['text'])) {
            foreach ($data['text'] as $item) {
                if (isset($item['text'])) {
                    return $item['text'];
                }
            }
        }
        if (isset($data['generations']) && is_array($data['generations']) && isset($data['generations'][0]['text'])) {
            return $data['generations'][0]['text'];
        }
        if (isset($data['text']) && is_string($data['text'])) {
            return $data['text'];
        }
        if (isset($data['response']) && is_string($data['response'])) {
            return $data['response'];
        }
        return null;
    }

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
            wp_send_json_error(['text' => 'Connection error: ' . $response->get_error_message()]);
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code !== 200) {
            $error_msg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? $data['error'] : 'Unknown API error');
            // Make the error user‑friendly
            if ($http_code === 429) {
                $error_msg = 'API rate limit reached. Please try again later or upgrade your API key.';
            } elseif ($http_code === 401) {
                $error_msg = 'Invalid API key. Please check your Cohere API key in the admin settings.';
            } elseif ($http_code === 400) {
                $error_msg = 'Bad request. Please check your settings (model, etc.).';
            } else {
                $error_msg = "API error ($http_code): $error_msg";
            }
            wp_send_json_error(['text' => $error_msg]);
        }

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
       CRON JOB
    ----------------------------------------------- */
    public function cron_generate_embeddings() {
        if (empty($this->api_key)) {
            error_log('AI Chatbot Cron: API key missing. Skipping.');
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
       FRONTEND UI
    ----------------------------------------------- */
    public function chatbot_ui() {
        ob_start();
        ?>
        <style>
            :root{--brand-start:#ff8a00;--brand-end:#dd7500;--brand-dark:#c46400;--muted:#6b7280;--bg:#ffffff;--panel-bg:#fbfbfd;--bubble-user:#FFE8C2;--bubble-ai:#ffffff;--radius:14px;--shadow:0 20px 50px rgba(12,15,20,0.12);--font:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial}
            #ai-chat-widget{position:fixed;bottom:22px;right:22px;z-index:99999;font-family:var(--font);-webkit-font-smoothing:antialiased}
            #ai-chat-toggle{width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--brand-start),var(--brand-end));border:none;color:#fff;font-size:28px;cursor:pointer;box-shadow:var(--shadow);display:flex;align-items:center;justify-content:center;transition:transform .16s ease,box-shadow .16s ease}
            #ai-chat-toggle:focus{outline:3px solid rgba(255,138,0,.18)}
            #ai-chat-toggle:hover{transform:translateY(-3px);box-shadow:0 28px 60px rgba(12,15,20,.18)}
            #ai-unread-badge{position:absolute;top:-6px;right:-6px;min-width:20px;height:20px;background:#ff3860;color:#fff;font-size:12px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 6px;box-shadow:0 6px 16px rgba(0,0,0,.12)}
            #ai-chat-panel{position:absolute;bottom:90px;right:0;width:420px;max-width:calc(100vw - 34px);background:var(--panel-bg);border-radius:var(--radius);overflow:hidden;display:none;flex-direction:column;box-shadow:0 40px 80px rgba(12,15,20,.16);border:1px solid rgba(16,24,40,.04)}
            #ai-chat-panel.open{display:flex}
            #ai-chat-header{background:linear-gradient(90deg,var(--brand-start),var(--brand-end));color:#fff;padding:14px 16px;display:flex;align-items:center;gap:12px;justify-content:space-between}
            #ai-chat-header .left{display:flex;align-items:center;gap:12px}
            #ai-chat-header h4{margin:0;font-size:15px;letter-spacing:.2px}
            #ai-chat-header p{margin:0;font-size:12px;opacity:.95;color:rgba(255,255,255,.92)}
            #ai-chat-header .actions{display:flex;gap:8px;align-items:center}
            #ai-chat-close{background:0 0;border:none;color:rgba(255,255,255,.95);font-size:18px;cursor:pointer}
            #ai-widget-body{display:flex;flex-direction:column;gap:0}
            #ai-user-form{padding:18px;background:linear-gradient(180deg,rgba(255,138,0,.04),transparent);display:flex;flex-direction:column;gap:10px}
            #ai-user-form h3{margin:0;font-size:16px}
            #ai-user-form p{margin:0;color:var(--muted);font-size:13px}
            .ai-field-row{display:flex;gap:8px}
            .ai-field{flex:1;display:flex;flex-direction:column;gap:6px}
            .ai-field input{width:100%;padding:15px 12px;border-radius:10px;border:1px solid #e6e7eb;background:#fff;font-size:14px;transition:box-shadow .12s ease,border-color .12s ease}
            .ai-field input:focus{outline:0;border-color:var(--brand-end);box-shadow:0 6px 18px rgba(221,117,0,.08)}
            .ai-error{color:#d23;font-size:12px;height:14px}
            #ai-start-chat{margin-top:6px;background:linear-gradient(90deg,var(--brand-end),var(--brand-dark));border:none;color:#fff;padding:10px 12px;border-radius:10px;cursor:pointer;font-weight:600}
            #ai-start-chat[disabled]{opacity:.6;cursor:not-allowed}
            #ai-chat-area{display:flex;flex-direction:column;height:520px;min-height:380px}
            #ai-chat-box{flex:1;overflow:auto;padding:18px;background:linear-gradient(180deg,#f8fafc,#f7f8fb);display:flex;flex-direction:column;gap:12px}
            .msg-row{display:flex;gap:10px;align-items:flex-end;max-width:100%}
            .msg-row.ai{align-self:flex-start}
            .msg-row.user{align-self:flex-end;justify-content:flex-end}
            .msg-avatar{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff}
            .avatar-ai{background:linear-gradient(90deg,var(--brand-start),var(--brand-end))}
            .avatar-user{background:#64748b}
            .bubble{max-width:78%;padding:10px 14px;border-radius:12px;font-size:14px;line-height:1.4;box-shadow:0 6px 18px rgba(12,15,20,.04);position:relative}
            .bubble.ai{background:var(--bubble-ai);border:1px solid rgba(14,20,30,.03)}
            .bubble.user{background:var(--bubble-user)}
            .bubble .meta{display:flex;gap:8px;align-items:center;margin-top:6px;font-size:12px;color:var(--muted)}
            .bubble .meta time{opacity:.9}
            .typing-dots{width:46px;height:18px;display:flex;gap:6px;align-items:center;justify-content:center}
            .typing-dots span{display:block;width:7px;height:7px;border-radius:50%;background:rgba(99,102,241,.18);animation:blink 1s infinite}
            .typing-dots span:nth-child(2){animation-delay:.12s}
            .typing-dots span:nth-child(3){animation-delay:.24s}
            @keyframes blink{
            0%{opacity:.2}
            50%{opacity:1}
            100%{opacity:.2}
            }
            #ai-chat-input-area{display:flex;gap:10px;padding:12px;border-top:1px solid rgba(14,20,30,.04);background:#fff;align-items:center}
            #ai-chat-input{flex:1;padding:10px 14px;border-radius:999px;border:1px solid #e6e7eb;font-size:14px;min-height:42px}
            #ai-chat-send{background:linear-gradient(90deg,var(--brand-start),var(--brand-end));color:#fff;border:none;padding:10px 14px;border-radius:999px;cursor:pointer;font-weight:600;box-shadow:0 8px 22px rgba(221,117,0,.12)}
            @media (max-width:520px){
            #ai-chat-panel{right:12px;left:12px;width:auto;bottom:80px}
            #ai-chat-area{height:520px}
            }
        </style>
        <div id="ai-chat-widget" aria-live="polite">
            <button id="ai-chat-toggle" aria-expanded="false" aria-controls="ai-chat-panel" aria-label="Open support chat">
                💬
                <span id="ai-unread-badge" style="display:none;">0</span>
            </button>

            <div id="ai-chat-panel" role="dialog" aria-modal="false" aria-label="AI support chat panel">
                <div id="ai-chat-header">
                    <div class="left">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <rect x="2" y="2" width="20" height="20" rx="6" fill="white" opacity="0.06"></rect>
                            <path d="M4 12c0-4.418 3.582-8 8-8s8 3.582 8 8-3.582 8-8 8c-1.42 0-2.763-.328-3.96-.915L4 20l1.915-3.96A7.962 7.962 0 0 1 4 12z" fill="white" opacity="0.12"></path>
                        </svg>
                        <div>
                            <h4>Support Chat</h4>
                            <p>Answers from your site content — 24/7</p>
                        </div>
                    </div>

                    <div class="actions">
                        <button id="ai-chat-close" aria-label="Close chat">✕</button>
                    </div>
                </div>

                <div id="ai-widget-body">
                    <div id="ai-user-form" role="form" aria-labelledby="welcome-title">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <h3 id="welcome-title">👋 Welcome</h3>
                                <p>Please enter your details to start a secure conversation.</p>
                            </div>
                        </div>

                        <div class="ai-field-row">
                            <div class="ai-field">
                                <label class="sr-only" for="ai-user-name">Full name</label>
                                <input id="ai-user-name" type="text" placeholder="Full name" autocomplete="name" />
                                <div class="ai-error" data-for="name"></div>
                            </div>
                            <div class="ai-field">
                                <label class="sr-only" for="ai-user-phone">Phone</label>
                                <input id="ai-user-phone" type="tel" placeholder="Phone (optional)" autocomplete="tel" />
                                <div class="ai-error" data-for="phone"></div>
                            </div>
                        </div>

                        <div class="ai-field">
                            <label class="sr-only" for="ai-user-email">Email</label>
                            <input id="ai-user-email" type="email" placeholder="Email address" autocomplete="email" />
                            <div class="ai-error" data-for="email"></div>
                        </div>

                        <div style="display:flex;gap:8px;justify-content:space-between;align-items:center">
                            <!-- <small style="color:var(--muted)">We only use this to personalize & save your conversation.</small> -->
                            <button id="ai-start-chat">Start conversation</button>
                        </div>
                    </div>

                    <div id="ai-chat-area" style="display:none;">
                        <div id="ai-chat-box" role="log" aria-live="polite" aria-relevant="additions"></div>

                        <div id="ai-chat-input-area">
                            <input id="ai-chat-input" type="text" placeholder="Ask a question about our site, services, or docs..." aria-label="Type your question" />
                            <button id="ai-chat-send" aria-label="Send message">Send</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function(){
                const ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
                const widget = document.getElementById('ai-chat-widget');
                const panel = document.getElementById('ai-chat-panel');
                const toggleBtn = document.getElementById('ai-chat-toggle');
                const closeBtn = document.getElementById('ai-chat-close');
                const unreadBadge = document.getElementById('ai-unread-badge');

                const startBtn = document.getElementById('ai-start-chat');
                const userForm = document.getElementById('ai-user-form');
                const chatArea = document.getElementById('ai-chat-area');
                const chatBox = document.getElementById('ai-chat-box');
                const input = document.getElementById('ai-chat-input');
                const sendBtn = document.getElementById('ai-chat-send');

                // form fields & errors
                const nameEl = document.getElementById('ai-user-name');
                const emailEl = document.getElementById('ai-user-email');
                const phoneEl = document.getElementById('ai-user-phone');

                function elError(name, text){
                    const el = document.querySelector('.ai-error[data-for="'+name+'"]');
                    if(el) el.textContent = text || '';
                }

                function validateForm(){
                    let ok = true;
                    elError('name',''); elError('email',''); elError('phone','');
                    if(!nameEl.value.trim()){ elError('name','Required'); ok = false; }
                    if(!emailEl.value.trim()){ elError('email','Required'); ok = false; }
                    else if(!/^\S+@\S+\.\S+$/.test(emailEl.value.trim())){ elError('email','Invalid email'); ok = false; }
                    if(phoneEl.value.trim() && !/^[0-9+\-\s()]{5,20}$/.test(phoneEl.value.trim())){ elError('phone','Invalid phone'); ok = false; }
                    return ok;
                }

                function escapeHtml(unsafe){
                    if(!unsafe) return '';
                    return unsafe.replace(/[&<>"]/g, function(m){
                        if(m==='&') return '&amp;';
                        if(m==='<') return '&lt;';
                        if(m==='>') return '&gt;';
                        if(m==='"') return '&quot;';
                        return m;
                    });
                }

                function formatTime(ts){
                    try{
                        const d = new Date(ts || Date.now());
                        return d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    }catch(e){ return ''; }
                }

                // history saved in sessionStorage (persist across open/refresh)
                let history = [];
                try {
                    const raw = sessionStorage.getItem('ai_chat_history');
                    if(raw) history = JSON.parse(raw) || [];
                } catch(e){ history = []; }

                // apply user info if exists
                let userData = null;
                try{
                    const u = sessionStorage.getItem('ai_chat_user');
                    if(u) userData = JSON.parse(u);
                }catch(e){ userData = null; }

                // unread count management
                let unreadCount = 0;
                function updateUnreadBadge(){
                    if(unreadCount > 0){
                        unreadBadge.style.display = 'flex';
                        unreadBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                    } else unreadBadge.style.display = 'none';
                }
                updateUnreadBadge();

                // render history
                function renderHistory(){
                    chatBox.innerHTML = '';
                    history.forEach(msg => {
                        appendMessageToDOM(msg, false);
                    });
                    scrollToBottom();
                }
                function scrollToBottom(){
                    chatBox.scrollTo({ top: chatBox.scrollHeight + 200, behavior: 'smooth' });
                }

                function createAvatar(role){
                    const span = document.createElement('div');
                    span.className = 'msg-avatar ' + (role === 'user' ? 'avatar-user' : 'avatar-ai');
                    span.setAttribute('aria-hidden', 'true');
                    span.textContent = role === 'user' ? (userData && userData.name ? userData.name.charAt(0).toUpperCase() : 'Y') : 'AI';
                    return span;
                }

                function appendMessageToDOM(message, incrementUnread = true){
                    // message: { role: 'user'|'assistant', content, ts, isTyping? }
                    const row = document.createElement('div');
                    row.className = 'msg-row ' + (message.role === 'user' ? 'user' : 'ai');

                    const bubble = document.createElement('div');
                    bubble.className = 'bubble ' + (message.role === 'user' ? 'user' : 'ai');

                    // content
                    if(message.role === 'assistant' && message.isTyping){
                        bubble.innerHTML = '<div class="typing-dots" aria-hidden="true"><span></span><span></span><span></span></div><div style="font-size:12px;color:var(--muted);margin-top:6px">AI is typing…</div>';
                    } else {
                        bubble.innerHTML = '<div style="white-space:pre-wrap;">' + escapeHtml(message.content) + '</div>';
                        const meta = document.createElement('div');
                        meta.className = 'meta';
                        meta.innerHTML = '<time datetime="'+(message.ts||'')+'">'+formatTime(message.ts)+'</time>';
                        bubble.appendChild(meta);
                    }

                    if(message.role === 'assistant'){
                        row.appendChild(createAvatar('assistant'));
                        row.appendChild(bubble);
                    } else {
                        row.appendChild(bubble);
                        row.appendChild(createAvatar('user'));
                    }

                    chatBox.appendChild(row);

                    if(incrementUnread && panel.classList.contains('open') === false && message.role === 'assistant'){
                        unreadCount++;
                        updateUnreadBadge();
                    }
                }

                // initial render
                renderHistory();

                // toggle panel
                toggleBtn.addEventListener('click', function(){
                    const open = panel.classList.toggle('open');
                    toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if(open){
                        unreadCount = 0; updateUnreadBadge();
                        if(userData && userData.name && userData.email){
                            userForm.style.display = 'none';
                            chatArea.style.display = 'flex';
                        } else {
                            userForm.style.display = 'flex';
                            chatArea.style.display = 'none';
                        }
                        setTimeout(()=> { if(chatArea.style.display!=='none') input.focus(); }, 220);
                    }
                });

                closeBtn.addEventListener('click', function(){ panel.classList.remove('open'); toggleBtn.setAttribute('aria-expanded','false'); });

                // start conversation
                startBtn.addEventListener('click', function(){
                    if(!validateForm()) return;
                    userData = {
                        name: nameEl.value.trim(),
                        email: emailEl.value.trim(),
                        phone: phoneEl.value.trim()
                    };
                    try{ sessionStorage.setItem('ai_chat_user', JSON.stringify(userData)); }catch(e){}
                    userForm.style.display = 'none';
                    chatArea.style.display = 'flex';
                    input.focus();

                    // Optionally notify server to create a session (if you implement handler 'ai_save_chat_session')
                    fetch(ajaxUrl, {
                        method:'POST',
                        headers: { 'Content-Type':'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ai_save_chat_session',
                            user: JSON.stringify(userData),
                            initial: 'started'
                        })
                    }).catch(()=>{/* ignore errors if endpoint doesn't exist */});
                });

                // typing placeholder element reference
                let typingPlaceholder = null;

                // Helper: create a cleaned history array (no ts, no isTyping, role mapped to Cohere-safe)
                function makeCleanHistoryFrom(historyArray){
                    return (historyArray || []).filter(m => !m.isTyping).map(m => {
                        const role = (m.role === 'user') ? 'USER' : (m.role === 'assistant' ? 'CHATBOT' : (m.role || '').toUpperCase());
                        return {
                            role: role,
                            message: (m.content || m.message || '')
                        };
                    });
                }

                // send message
                async function sendMessage(){
                    const text = input.value.trim();
                    if(!text) return;

                    // push user message to local history (keep ts for local display only)
                    const userMsg = { role: 'user', content: text, ts: new Date().toISOString() };
                    history.push(userMsg);
                    try{ sessionStorage.setItem('ai_chat_history', JSON.stringify(history)); }catch(e){}
                    appendMessageToDOM(userMsg, false);
                    input.value = '';
                    scrollToBottom();

                    // show typing placeholder
                    const typingMsg = { role:'assistant', isTyping:true, ts: new Date().toISOString() };
                    appendMessageToDOM(typingMsg, false);
                    typingPlaceholder = chatBox.lastChild;

                    // prepare a cleaned history to send to the server/Cohere (NO ts, NO isTyping)
                    const cleanedHistory = makeCleanHistoryFrom(history);

                    // send to server (existing endpoint)
                    try {
                        const resp = await fetch(ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'ai_stream_chat',
                                message: text,
                                history: JSON.stringify(cleanedHistory), // <<--- cleaned, safe for Cohere
                                user: userData ? JSON.stringify(userData) : ''
                            })
                        });

                        const result = await resp.json();

                        // remove typing placeholder
                        if(typingPlaceholder) typingPlaceholder.remove();

                        let answer = '';
                        if (result && result.success && result.data && result.data.text){
                            answer = result.data.text;
                        } else if (result && result.data && result.data.text) {
                            answer = result.data.text;
                        } else {
                            answer = 'Sorry — I could not get a response. Please try again.';
                        }

                        // push AI message to local history (keep ts locally)
                        const aiMsg = { role: 'assistant', content: answer, ts: new Date().toISOString() };
                        history.push(aiMsg);
                        try{ sessionStorage.setItem('ai_chat_history', JSON.stringify(history)); }catch(e){}
                        appendMessageToDOM(aiMsg, false);
                        scrollToBottom();

                        // Optionally save each message to server (handler 'ai_save_chat' expected)
                        // NOTE: we send the message content & cleaned full history (no ts)
                        fetch(ajaxUrl, {
                            method:'POST',
                            headers: { 'Content-Type':'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'ai_save_chat',
                                user: JSON.stringify(userData || {}),
                                message: JSON.stringify({ role: 'CHATBOT', message: answer }), // normalized
                                full_history: JSON.stringify(makeCleanHistoryFrom(history))
                            })
                        }).catch(()=>{ /* ignore if not implemented */ });

                    } catch (err){
                        if(typingPlaceholder) typingPlaceholder.remove();
                        const errMsg = { role:'assistant', content: 'Connection error. Please try again later.', ts: new Date().toISOString() };
                        history.push(errMsg);
                        try{ sessionStorage.setItem('ai_chat_history', JSON.stringify(history)); }catch(e){}
                        appendMessageToDOM(errMsg, false);
                        scrollToBottom();
                    }
                }

                sendBtn.addEventListener('click', sendMessage);
                input.addEventListener('keydown', function(e){
                    if(e.key === 'Enter' && !e.shiftKey){
                        e.preventDefault();
                        sendMessage();
                    }
                });

                document.addEventListener('keydown', function(e){
                    if(e.key === 'Escape') panel.classList.remove('open');
                });

            })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function save_chat_session() {

        global $wpdb;

        $table = $wpdb->prefix . 'ai_chat_sessions';

        $user = json_decode(stripslashes($_POST['user']), true);

        if(!$user){
            wp_send_json_error();
        }

        $name  = sanitize_text_field($user['name']);
        $email = sanitize_email($user['email']);
        $phone = sanitize_text_field($user['phone']);

        $wpdb->insert($table,[
            'name'=>$name,
            'email'=>$email,
            'phone'=>$phone
        ]);

        $session_id = $wpdb->insert_id;

        wp_send_json_success([
            'session_id'=>$session_id
        ]);
    }

    public function save_chat(){

        global $wpdb;

        $session_table = $wpdb->prefix.'ai_chat_sessions';
        $msg_table = $wpdb->prefix.'ai_chat_messages';

        $user = json_decode(stripslashes($_POST['user']), true);
        $message = json_decode(stripslashes($_POST['message']), true);

        if(!$user || !$message){
            wp_send_json_error();
        }

        // find existing session
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $session_table WHERE email=%s ORDER BY id DESC LIMIT 1",
            $user['email']
        ));

        if(!$session){
            wp_send_json_error();
        }

        $wpdb->insert($msg_table,[
            'session_id'=>$session->id,
            'role'=>sanitize_text_field($message['role']),
            'message'=>sanitize_textarea_field($message['message'])
        ]);

        wp_send_json_success();
    }
}

new AI_Support_Chatbot();