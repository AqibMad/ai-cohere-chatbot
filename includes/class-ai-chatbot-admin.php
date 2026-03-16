<?php
class AI_Support_Chatbot_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
    }

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
            
            update_option('ai_escalation_keywords', sanitize_textarea_field($_POST['escalation_keywords'] ?? ''));
            update_option('ai_sentiment_threshold', floatval($_POST['sentiment_threshold'] ?? -0.3));
            update_option('ai_enable_escalation', isset($_POST['enable_escalation']) ? 1 : 0);
            update_option('ai_agents_online_status', sanitize_text_field($_POST['agent_status'] ?? 'offline'));
            
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
        
        $escalation_keywords  = get_option('ai_escalation_keywords', 'refund,complaint,urgent,contract,support,help,cancel');
        $sentiment_threshold  = get_option('ai_sentiment_threshold', -0.3);
        $enable_escalation    = get_option('ai_enable_escalation', 1);
        $agent_status         = get_option('ai_agents_online_status', 'offline');
        
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1>AI Support Chatbot</h1>
            <nav class="nav-tab-wrapper">
                <a href="?page=ai-support-chatbot&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=ai-support-chatbot&tab=agents" class="nav-tab <?php echo $tab === 'agents' ? 'nav-tab-active' : ''; ?>">Agent Dashboard</a>
                <a href="?page=ai-support-chatbot&tab=chats" class="nav-tab <?php echo $tab === 'chats' ? 'nav-tab-active' : ''; ?>">Chat History</a>
            </nav>

            <?php if ($tab === 'settings') : ?>
            <form method="post">
                <?php wp_nonce_field('ai_chatbot_settings'); ?>
                <h2>API Configuration</h2>
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
                    <tr><th>Max conversation history</th><td><input type="number" name="max_history" value="<?php echo esc_attr($max_history); ?>" min="0" max="20" /><p class="description">Number of previous messages to keep for context.</p></td></tr>
                    <tr><th>Post Types to Index</th><td><?php foreach ($all_post_types as $pt) : ?><label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $selected_post_types)); ?> /> <?php echo esc_html($pt->label); ?></label><br /><?php endforeach; ?></td></tr>
                </table>

                <h2>Human Support Handoff</h2>
                <table class="form-table">
                    <tr><th>Enable Escalation</th><td><label><input type="checkbox" name="enable_escalation" <?php checked($enable_escalation); ?> /> Allow users to escalate to human agents</label></td></tr>
                    <tr><th>Escalation Keywords <br/>(comma-separated)</th><td><textarea name="escalation_keywords" class="large-text" rows="4"><?php echo esc_textarea($escalation_keywords); ?></textarea><p class="description">Chat containing these keywords will be flagged for escalation.</p></td></tr>
                    <tr><th>Sentiment Threshold</th><td><input type="number" name="sentiment_threshold" value="<?php echo esc_attr($sentiment_threshold); ?>" min="-1" max="1" step="0.1" /><p class="description">Scores below this will trigger automatic escalation (negative = unhappy).</p></td></tr>
                </table>

                <h2>Agent Availability Status</h2>
                <table class="form-table">
                    <tr><th>Agent Status</th><td>
                        <select name="agent_status">
                            <option value="offline" <?php selected($agent_status, 'offline', true); ?>>🔴 Offline (AI handles all chats)</option>
                            <option value="online" <?php selected($agent_status, 'online', true); ?>>🟢 Online (Show agent available)</option>
                        </select>
                        <p class="description">Set your agent availability status. Online status will show a green indicator to users and allow them to request an agent.</p>
                    </td></tr>
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

            <?php elseif ($tab === 'agents') : ?>
            <h2>Support Agents</h2>
            <p>Manage support agents who can take over chats from the AI.</p>
            <?php $this->render_agent_dashboard(); ?>

            <?php elseif ($tab === 'chats') : ?>
            <h2>Chat History</h2>
            <p>View all customer conversations and handoff status.</p>
            <?php $this->render_chat_history(); ?>

            <?php endif; ?>
        </div>
        <?php
    }

    private function render_agent_dashboard() {
        global $wpdb;
        
        $session_table = $wpdb->prefix . 'ai_chat_sessions';
        $msg_table = $wpdb->prefix . 'ai_chat_messages';
        
        $chats = $wpdb->get_results("
            SELECT s.id, s.name, s.email, s.phone, s.status, s.requires_escalation, s.escalation_reason, 
                   s.started_at, COUNT(m.id) as message_count
            FROM $session_table s
            LEFT JOIN $msg_table m ON s.id = m.session_id
            WHERE s.status IN ('waiting', 'human_handling')
            GROUP BY s.id
            ORDER BY s.requires_escalation DESC, s.started_at ASC
        ");
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Started</th>
                    <th>Messages</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($chats)) : ?>
                <tr><td colspan="7">No pending chats</td></tr>
                <?php else : ?>
                <?php foreach ($chats as $chat) : ?>
                <tr>
                    <td><?php echo esc_html($chat->name); ?></td>
                    <td><?php echo esc_html($chat->email); ?></td>
                    <td><span style="padding:4px 8px;border-radius:4px;background:<?php echo $chat->status === 'waiting' ? '#fff8e1' : '#e1f5ff'; ?>"><?php echo ucfirst(str_replace('_', ' ', $chat->status)); ?></span></td>
                    <td><?php echo $chat->requires_escalation ? esc_html($chat->escalation_reason ?: 'Escalated') : '-'; ?></td>
                    <td><?php echo esc_html(date('H:i', strtotime($chat->started_at))); ?></td>
                    <td><?php echo intval($chat->message_count); ?></td>
                    <td><button class="button button-small view-chat-btn" data-session="<?php echo intval($chat->id); ?>">View Chat</button></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <script>
        document.querySelectorAll('.view-chat-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const sessionId = this.dataset.session;
                window.open('?page=ai-support-chatbot&tab=chats&session=' + sessionId, '_blank');
            });
        });
        </script>
        <?php
    }

    private function render_chat_history() {
        global $wpdb;
        
        $session_id = intval($_GET['session'] ?? 0);
        $session_table = $wpdb->prefix . 'ai_chat_sessions';
        $msg_table = $wpdb->prefix . 'ai_chat_messages';
        
        if ($session_id) {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $session_table WHERE id = %d",
                $session_id
            ));
            
            if (!$session) {
                echo '<p>Chat not found</p>';
                return;
            }
            
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $msg_table WHERE session_id = %d ORDER BY created_at ASC",
                $session_id
            ));
            
            ?>
            <div style="background:#f5f5f5;padding:20px;border-radius:8px;max-width:600px;">
                <h3><?php echo esc_html($session->name); ?> (<?php echo esc_html($session->email); ?>)</h3>
                <p><strong>Phone:</strong> <?php echo esc_html($session->phone); ?></p>
                <p><strong>Status:</strong> <?php echo ucfirst(str_replace('_', ' ', $session->status)); ?></p>
                
                <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:15px;max-height:400px;overflow-y:auto;margin:15px 0;">
                    <?php foreach ($messages as $msg) : ?>
                    <div style="margin:10px 0;padding:10px;background:<?php echo $msg->role === 'user' ? '#e3f2fd' : '#f5f5f5'; ?>;border-radius:4px;">
                        <strong><?php echo esc_html(ucfirst($msg->role)); ?>:</strong> <?php echo nl2br(esc_html($msg->message)); ?>
                        <div style="font-size:12px;color:#999;margin-top:5px;"><?php echo esc_html(date('H:i', strtotime($msg->created_at))); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($session->status !== 'resolved') : ?>
                <form style="display:flex;gap:10px;">
                    <textarea id="agent-response" placeholder="Type your response..." style="flex:1;padding:10px;border:1px solid #ddd;border-radius:4px;"></textarea>
                    <button type="button" id="send-agent-response" class="button button-primary">Send</button>
                </form>
                <div id="agent-response-status"></div>
                <script>
                document.getElementById('send-agent-response').addEventListener('click', function() {
                    const message = document.getElementById('agent-response').value;
                    if (!message.trim()) return;
                    
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ai_agent_update_chat',
                            session_id: <?php echo intval($session_id); ?>,
                            message: message
                        })
                    }).then(r => r.json()).then(data => {
                        if (data.success) {
                            document.getElementById('agent-response').value = '';
                            document.getElementById('agent-response-status').textContent = 'Message sent!';
                            setTimeout(() => location.reload(), 1000);
                        }
                    });
                });
                </script>
                <div style="margin-top:10px;">
                    <button type="button" id="mark-resolved-btn" class="button button-secondary">Mark as Resolved</button>
                </div>
                <script>
                document.getElementById('mark-resolved-btn').addEventListener('click', function() {
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ai_mark_resolved',
                            session_id: <?php echo intval($session_id); ?>
                        })
                    }).then(r => r.json()).then(data => {
                        if (data.success) {
                            alert('Chat marked as resolved');
                            history.back();
                        }
                    });
                });
                </script>
                <?php endif; ?>
            </div>
            <?php
        } else {
            $chats = $wpdb->get_results("
                SELECT s.id, s.name, s.email, s.status, s.started_at, COUNT(m.id) as message_count
                FROM $session_table s
                LEFT JOIN $msg_table m ON s.id = m.session_id
                GROUP BY s.id
                ORDER BY s.started_at DESC
                LIMIT 50
            ");
            
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Messages</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chats as $chat) : ?>
                    <tr>
                        <td><?php echo esc_html($chat->name); ?></td>
                        <td><?php echo esc_html($chat->email); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $chat->status)); ?></td>
                        <td><?php echo esc_html(human_time_diff(strtotime($chat->started_at)) . ' ago'); ?></td>
                        <td><?php echo intval($chat->message_count); ?></td>
                        <td><a href="?page=ai-support-chatbot&tab=chats&session=<?php echo intval($chat->id); ?>" class="button button-small">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
    }
}