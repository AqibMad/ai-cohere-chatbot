<?php
class AI_Support_Chatbot_API {

    private $api_key;
    private $embedding_model;
    private $chat_model;
    private $search_limit;
    private $similarity_threshold;
    private $post_types;
    private $max_history;

    public function __construct() {
        $this->api_key              = get_option('ai_cohere_api_key', '');
        $this->embedding_model      = get_option('ai_embedding_model', 'embed-english-v3.0');
        $this->chat_model           = get_option('ai_chat_model', 'command-r-plus-03-2025');
        $this->search_limit         = get_option('ai_search_limit', 10);
        $this->similarity_threshold = get_option('ai_similarity_threshold', 0.3);
        $this->post_types           = get_option('ai_post_types', ['post', 'page']);
        $this->max_history          = get_option('ai_max_history', 5);

        add_action('wp_ajax_ai_generate_embeddings', [$this, 'ajax_generate_embeddings']);
    }

    /* =========================
       🔥 QUERY REWRITING
    ========================= */
    private function rewrite_query($query) {

        $response = wp_remote_post('https://api.cohere.com/v2/chat', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body' => json_encode([
                'model' => 'command-r-plus-03-2025',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Convert this user question into short search keywords only. No explanation.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $query
                    ]
                ],
                'temperature' => 0
            ]),
            'timeout' => 20
        ]);

        if (is_wp_error($response)) return $query;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $this->extract_cohere_text($data);

        return $text ? trim($text) : $query;
    }

    /* =========================
       🔥 EMBEDDING
    ========================= */
    public function generate_embedding($text) {

        $response = wp_remote_post('https://api.cohere.com/v2/embed', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body' => json_encode([
                'model' => $this->embedding_model,
                'texts' => [$text]
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data['embeddings']['float'][0] ?? false;
    }

    /* =========================
       🔥 COSINE SIMILARITY
    ========================= */
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

    /* =========================
       🔥 SEMANTIC SEARCH (IMPROVED)
    ========================= */
    private function semantic_search($query, $limit = 10) {

        global $wpdb;

        $rewritten = $this->rewrite_query($query);
        $query_emb = $this->generate_embedding($rewritten);

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

        return get_posts([
            'post__in' => $ids,
            'orderby'  => 'post__in',
            'post_type'=> $this->post_types
        ]);
    }

    /* =========================
       🔥 HYBRID SEARCH (IMPROVED)
    ========================= */
    private function hybrid_search($query, $limit = 10) {

        $rewritten = $this->rewrite_query($query);
        $combined  = $query . " " . $rewritten;

        // Keyword search
        $wp_results = get_posts([
            's'              => $combined,
            'posts_per_page' => $limit * 2,
            'post_type'      => $this->post_types,
            'post_status'    => 'publish'
        ]);

        // Semantic search
        $semantic = $this->semantic_search($combined, $limit * 2);

        // Merge + unique
        $merged = array_merge($wp_results, $semantic);
        $unique = [];

        foreach ($merged as $p) {
            $unique[$p->ID] = $p;
        }

        return array_slice($unique, 0, $limit);
    }

    /* =========================
       🔥 CONTEXT BUILDER
    ========================= */
    public function get_relevant_context($query) {

        $posts = $this->hybrid_search($query, $this->search_limit);

        $context = '';

        foreach ($posts as $post) {

            $context .= "=== DOCUMENT ===\n";
            $context .= "Title: " . $post->post_title . "\n";
            $context .= "Content: " . wp_strip_all_tags(wp_trim_words($post->post_content, 500)) . "\n\n";
        }

        if (empty($context)) {
            $context = "No relevant information found.";
        }

        return $context;
    }

    /* =========================
       🔥 CHAT HANDLER
    ========================= */
    public function ajax_stream_chat() {

        if (empty($this->api_key)) {
            wp_send_json_error(['text' => 'Chatbot not configured']);
        }

        $query = sanitize_text_field($_POST['message'] ?? '');
        if (!$query) {
            wp_send_json_error(['text' => 'Empty message']);
        }

        $context = $this->get_relevant_context($query);

        $system_prompt = "You are a professional customer support assistant.\n";
        $system_prompt .= "Answer ONLY from the provided context.\n";
        $system_prompt .= "If not found, say: I don’t have that information.\n\n";
        $system_prompt .= "Context:\n" . $context;

        $response = wp_remote_post('https://api.cohere.com/v2/chat', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body' => json_encode([
                'model' => $this->chat_model,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $query]
                ],
                'temperature' => 0.3
            ]),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['text' => 'Connection error']);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $this->extract_cohere_text($data);

        wp_send_json_success([
            'text' => $text ?: 'No response'
        ]);
    }

    /* =========================
       🔥 RESPONSE EXTRACTOR
    ========================= */
    private function extract_cohere_text($data) {

        if (isset($data['message']['content'][0]['text'])) {
            return $data['message']['content'][0]['text'];
        }

        return $data['text'] ?? null;
    }
}