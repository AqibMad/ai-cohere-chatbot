<?php
/*
Plugin Name: AI Support Chatbot (Cohere + WP Content)
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
        $this->search_limit         = get_option('ai_search_limit', 5);
        $this->similarity_threshold = get_option('ai_similarity_threshold', 0.7);
        $this->post_types           = get_option('ai_post_types', ['post', 'page']);
        $this->max_history          = get_option('ai_max_history', 5); // number of previous messages to keep

        register_activation_hook(__FILE__, [$this, 'create_table']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('wp_ajax_ai_generate_embeddings', [$this, 'generate_embeddings']);
        add_action('wp_ajax_ai_stream_chat', [$this, 'stream_chat']);
        add_action('wp_ajax_nopriv_ai_stream_chat', [$this, 'stream_chat']);
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

        // Remove the debug lines below after testing!
        // print_r($response);
        // wp_die();

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
            $content = $post->post_title . ' ' . wp_trim_words($post->post_content, 300);
            
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

        // if (!empty($errors)) {
        //     $message .= "Failed posts (" . count($errors) . "): " . implode(', ', array_slice($errors, 0, 10));
        //     if (count($errors) > 10) $message .= '...';
        // }

        // // Add a note to check error logs
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
        // First try WP keyword search
        $wp_results = get_posts([
            's'              => $query,
            'posts_per_page' => $limit,
            'post_type'      => $this->post_types,
            'post_status'    => 'publish'
        ]);

        // If enough results, return them
        if (count($wp_results) >= $limit) {
            return array_slice($wp_results, 0, $limit);
        }

        // Otherwise, supplement with semantic search
        $semantic = $this->semantic_search($query, $limit);
        $merged = array_merge($wp_results, $semantic);
        $unique = [];
        foreach ($merged as $p) {
            $unique[$p->ID] = $p;
        }
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
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');

        // Rate limiting (10 requests per minute per IP)
        if (!$this->check_rate_limit()) {
            $this->sse_message('I’m receiving too many requests. Please wait a moment.');
            exit;
        }

        if (empty($this->api_key)) {
            $this->sse_message('Chatbot is not configured yet. Please contact the site administrator.');
            exit;
        }

        $query = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';
        if (empty($query)) {
            $this->sse_message('Please type a message.');
            exit;
        }

        // Get conversation history from frontend (sent as JSON in query param 'history')
        $history_json = isset($_GET['history']) ? sanitize_text_field($_GET['history']) : '[]';
        $history = json_decode(stripslashes($history_json), true);
        if (!is_array($history)) $history = [];

        // Limit history length
        if (count($history) > $this->max_history * 2) { // each turn has user+assistant
            $history = array_slice($history, -$this->max_history * 2);
        }

        // Check cache (single‑turn only – history would break caching)
        $cache_key = 'ai_cache_' . md5($query . json_encode($history));
        $cached = get_transient($cache_key);
        if ($cached) {
            $this->sse_message($cached);
            exit;
        }

        // Retrieve relevant posts
        $posts = $this->hybrid_search($query, $this->search_limit);
        $context = '';
        foreach ($posts as $post) {
            $context .= "Title: " . $post->post_title . "\n";
            $context .= "Content: " . wp_trim_words($post->post_content, 200) . "\n\n";
        }

        // If no relevant context found, add a note
        if (empty($context)) {
            $context = "No relevant information found in the knowledge base.";
        }

        // Build system prompt
        $system_prompt = "You are a helpful support assistant for the website " . get_bloginfo('name') . ". ";
        $system_prompt .= "Answer the user's question based **only** on the context provided below. ";
        $system_prompt .= "If the answer cannot be found in the context, say: 'I'm sorry, I don't have information about that. Please contact support or try a different question.' ";
        $system_prompt .= "Do not use any external knowledge.\n\n";
        $system_prompt .= "Context:\n" . $context;

        // Prepare messages for Cohere API (including history)
        $messages = [];

        // Add system message (role 'system' is supported in Cohere v2 chat)
        $messages[] = ['role' => 'system', 'content' => $system_prompt];

        // Add conversation history (alternating user/assistant)
        foreach ($history as $turn) {
            if (isset($turn['role']) && isset($turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }

        // Add the new user query
        $messages[] = ['role' => 'user', 'content' => $query];

        // Call Cohere API
        $url = 'https://api.cohere.com/v2/chat';
        $body = [
            'model'    => $this->chat_model,
            'messages' => $messages,
            'temperature' => 0.3, // lower temperature for factual answers
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body'    => json_encode($body),
            'timeout' => 60
        ]);

        $text = '';

        if (is_wp_error($response)) {
            $text = 'Error: ' . $response->get_error_message();
        } else {
            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($http_code !== 200) {
                $error_msg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? $data['error'] : 'Unknown API error');
                $text = "API Error ($http_code): $error_msg";
                error_log('Cohere API error: ' . $body);
            } else {
                $extracted = $this->extract_cohere_text($data);
                if ($extracted) {
                    $text = $extracted;
                } else {
                    error_log('Unexpected Cohere response: ' . $body);
                    $text = 'Sorry, I could not process the response.';
                }
            }
        }

        $this->sse_message($text);

        // Cache only successful responses (no errors)
        if (!str_starts_with($text, 'Error:') && !str_starts_with($text, 'API Error')) {
            set_transient($cache_key, $text, HOUR_IN_SECONDS);
        }

        exit;
    }

    private function check_rate_limit() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $key = 'ai_chat_limit_' . md5($ip);
        $count = get_transient($key);
        if ($count && $count >= 10) return false;
        set_transient($key, $count ? $count + 1 : 1, MINUTE_IN_SECONDS);
        return true;
    }

    private function sse_message($text) {
        echo "data: " . json_encode(['text' => (string)$text]) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    /* -----------------------------------------------
       FRONTEND UI (with history)
    ----------------------------------------------- */

    public function chatbot_ui() {
        // Generate a unique session ID for this browser (for history)
        $session_id = 'chat_' . md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
        ob_start();
        ?>
        <div id="ai-chat-container">
            <div id="ai-chat-box" style="border:1px solid #ccc; padding:10px; height:400px; overflow-y:auto;"></div>
            <div style="display:flex; margin-top:5px;">
                <input type="text" id="ai-chat-input" style="flex:1; padding:8px;" placeholder="Type your question..." />
                <button id="ai-chat-send" style="padding:8px 15px;">Send</button>
            </div>
        </div>

        <script>
        (function() {
            const chatBox = document.getElementById('ai-chat-box');
            const input = document.getElementById('ai-chat-input');
            const sendBtn = document.getElementById('ai-chat-send');
            let history = []; // array of {role, content}

            // Load history from sessionStorage (optional)
            const saved = sessionStorage.getItem('ai_chat_history');
            if (saved) {
                try {
                    history = JSON.parse(saved);
                    // Display previous messages
                    history.forEach(msg => {
                        const sender = msg.role === 'user' ? 'You' : 'AI';
                        chatBox.innerHTML += `<div><b>${sender}:</b> ${escapeHtml(msg.content)}</div>`;
                    });
                    chatBox.scrollTop = chatBox.scrollHeight;
                } catch (e) {}
            }

            function escapeHtml(unsafe) {
                return unsafe.replace(/[&<>"]/g, function(m) {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    if (m === '"') return '&quot;';
                    return m;
                });
            }

            function sendMessage() {
                const msg = input.value.trim();
                if (!msg) return;

                // Display user message
                chatBox.innerHTML += `<div><b>You:</b> ${escapeHtml(msg)}</div>`;
                chatBox.scrollTop = chatBox.scrollHeight;
                input.value = '';

                // Add to history
                history.push({ role: 'user', content: msg });

                // Show typing indicator
                const typingDiv = document.createElement('div');
                typingDiv.id = 'ai-typing';
                typingDiv.innerHTML = '<b>AI:</b> <i>typing...</i>';
                chatBox.appendChild(typingDiv);
                chatBox.scrollTop = chatBox.scrollHeight;

                // Close any existing EventSource
                if (window.currentES) window.currentES.close();

                // Send history along with the new message
                const historyJson = encodeURIComponent(JSON.stringify(history));
                const url = "<?php echo admin_url('admin-ajax.php?action=ai_stream_chat&message='); ?>" + encodeURIComponent(msg) + "&history=" + historyJson;
                const es = new EventSource(url);
                window.currentES = es;

                es.onmessage = function(e) {
                    try {
                        const data = JSON.parse(e.data);
                        // Remove typing indicator
                        const typing = document.getElementById('ai-typing');
                        if (typing) typing.remove();

                        // Display AI response
                        chatBox.innerHTML += `<div><b>AI:</b> ${escapeHtml(data.text)}</div>`;
                        chatBox.scrollTop = chatBox.scrollHeight;

                        // Add to history
                        history.push({ role: 'assistant', content: data.text });

                        // Trim history if needed (keep last N turns)
                        const maxHistory = <?php echo (int)$this->max_history; ?>;
                        if (maxHistory > 0 && history.length > maxHistory * 2) {
                            history = history.slice(-maxHistory * 2);
                        }

                        // Save to sessionStorage
                        sessionStorage.setItem('ai_chat_history', JSON.stringify(history));
                    } catch (err) {
                        console.error(err);
                    }
                    es.close();
                };

                es.onerror = function() {
                    const typing = document.getElementById('ai-typing');
                    if (typing) typing.remove();
                    chatBox.innerHTML += `<div><b>AI:</b> <i>Connection error. Please try again.</i></div>`;
                    es.close();
                };
            }

            sendBtn.addEventListener('click', sendMessage);
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') sendMessage();
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

new AI_Support_Chatbot();