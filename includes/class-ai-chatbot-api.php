<?php
class AI_Support_Chatbot_API {

    private $api_key;
    private $embedding_model;
    private $chat_model;
    private $search_limit;
    private $similarity_threshold;
    private $post_types;
    private $max_history;
    private $last_api_error = '';

    public function __construct() {
        $this->api_key              = get_option('ai_cohere_api_key', '');
        $this->embedding_model      = get_option('ai_embedding_model', 'embed-english-v2.0');
        $this->chat_model           = get_option('ai_chat_model', 'command-a-03-2025');
        $this->search_limit         = get_option('ai_search_limit', 10);
        $this->similarity_threshold = get_option('ai_similarity_threshold', 0.5);
        $this->post_types           = get_option('ai_post_types', ['post', 'page']);
        $this->max_history          = get_option('ai_max_history', 5);

        // AJAX handler for generating embeddings (admin only)
        add_action('wp_ajax_ai_generate_embeddings', [$this, 'ajax_generate_embeddings']);
    }

    public function generate_embedding($text, $type = 'search_document') {

        if (empty($this->api_key)) {
            $this->last_api_error = 'API key is empty.';
            return false;
        }

        $url = 'https://api.cohere.com/v2/embed';

        $body = [
            'model'      => $this->embedding_model,
            'input_type' => $type, // ✅ IMPORTANT FIX
            'texts'      => [$text]
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body'    => json_encode($body),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            $this->last_api_error = $response->get_error_message();
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_res  = wp_remote_retrieve_body($response);
        $data      = json_decode($body_res, true);

        if ($http_code !== 200) {
            $this->last_api_error = "HTTP $http_code";
            return false;
        }

        return $data['embeddings']['float'][0] ?? false;
    }

    public function ajax_generate_embeddings() {
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

        global $wpdb;
        $table = $wpdb->prefix . 'ai_post_embeddings';
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            wp_die('Error: Embeddings table does not exist. Please deactivate and reactivate the plugin.');
        }

        $success = 0;
        $errors = [];
        $fatal_error = false;

        foreach ($posts as $post_id) {
            $post = get_post($post_id);
            $clean_content = wp_strip_all_tags($post->post_content);
            $clean_content = preg_replace('/\s+/', ' ', $clean_content);

            $content = $post->post_title . '. ' . wp_trim_words($clean_content, 300);

            $embedding = $this->generate_embedding($content, 'search_document');
            if ($embedding === false) {
                $errors[] = "Post ID $post_id";
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
            $message .= "\n\nCheck your WordPress error log for API error details.";
        }

        echo nl2br(esc_html($message));
        wp_die();
    }

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
            $clean_content = wp_strip_all_tags($post->post_content);
            $clean_content = preg_replace('/\s+/', ' ', $clean_content); // extra spaces remove

            $content = $post->post_title . '. ' . wp_trim_words($clean_content, 300);
            
            $embedding = $this->generate_embedding($content, 'search_document');
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
        $query_emb = $this->generate_embedding($query, 'search_query');
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

    public function get_relevant_context($query) {
        $posts = $this->hybrid_search($query, $this->search_limit);
        $context = '';
        foreach ($posts as $post) {
            $context .= "Title: " . $post->post_title . "\n";
            $clean = wp_strip_all_tags($post->post_content);
            $clean = preg_replace('/\s+/', ' ', $clean);

            $context .= "Content: " . wp_trim_words($clean, 200);
        }
        if (empty($context)) {
            $context = "No relevant information found in the knowledge base.";
        }
        return $context;
    }

    public function ajax_stream_chat() {
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

        $session_id = intval($_POST['session_id'] ?? 0);
        if ($session_id) {
            global $wpdb;
            $session_table = $wpdb->prefix . 'ai_chat_sessions';
            $session = $wpdb->get_row($wpdb->prepare("SELECT status FROM $session_table WHERE id = %d", $session_id));
            if ($session && $session->status === 'human_handling') {
                wp_send_json_success([
                    'text' => '',
                    'agent_handling' => true
                ]);
            }
        }

        $history = isset($_POST['history']) ? json_decode(stripslashes($_POST['history']), true) : [];
        if (!is_array($history)) $history = [];

        if (count($history) > $this->max_history * 2) {
            $history = array_slice($history, -$this->max_history * 2);
        }

        $context = $this->get_relevant_context($query);

        $system_prompt = "You are a support assistant for " . get_bloginfo('name') . ". ";
        $system_prompt .= "Answer only from the context below. ";
        $system_prompt .= "If not found, say you don't have that information.\n\n";
        $system_prompt .= "Context:\n" . $context;

        $messages = [['role' => 'system', 'content' => $system_prompt]];

        foreach ($history as $turn) {
            if (isset($turn['role'], $turn['message'])) {
                $messages[] = ['role' => strtolower($turn['role']), 'content' => $turn['message']];
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

        wp_send_json_success(['text' => $text, 'needs_escalation' => false]);
    }

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

    private function check_rate_limit() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $key = 'ai_chat_limit_' . md5($ip);
        $count = get_transient($key);
        if ($count && $count >= 10) return false;
        set_transient($key, $count ? $count + 1 : 1, MINUTE_IN_SECONDS);
        return true;
    }
}