<?php
class AI_Support_Chatbot_Agent {

    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_ai_request_escalation', [$this, 'request_escalation']);
        add_action('wp_ajax_nopriv_ai_request_escalation', [$this, 'request_escalation']);
        add_action('wp_ajax_ai_agent_update_chat', [$this, 'agent_update_chat']);
        add_action('wp_ajax_ai_get_agent_chats', [$this, 'get_agent_chats']);
        add_action('wp_ajax_ai_mark_resolved', [$this, 'mark_resolved']);
        add_action('wp_ajax_ai_fetch_agent_messages', [$this, 'fetch_agent_messages']);
        add_action('wp_ajax_nopriv_ai_fetch_agent_messages', [$this, 'fetch_agent_messages']);
        add_action('wp_ajax_ai_check_agent_availability', [$this, 'check_agent_availability']);
        add_action('wp_ajax_nopriv_ai_check_agent_availability', [$this, 'check_agent_availability']);
        add_action('wp_ajax_ai_agent_ping', [$this, 'agent_ping']);
        add_action('admin_footer', [$this, 'admin_agent_heartbeat_script']);
    }

    /**
     * Check if any agent is currently online (via heartbeat).
     */
    public function is_agent_available() {
        global $wpdb;
        $agent_table = $wpdb->prefix . 'ai_chat_agents';
        $timeout_seconds = 120;
        $threshold = date('Y-m-d H:i:s', time() - $timeout_seconds);
        $online_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $agent_table WHERE is_online = 1 AND last_seen >= %s",
            $threshold
        )));

        // Also consider manual override setting
        $manual_status = get_option('ai_agents_online_status', 'offline');
        return ($online_count > 0 || $manual_status === 'online');
    }

    /**
     * AJAX: Check agent availability.
     */
    public function check_agent_availability() {
        $available = $this->is_agent_available();
        wp_send_json_success([
            'agent_available' => $available
        ]);
    }

    /**
     * AJAX: User requests escalation to human agent.
     */
    public function request_escalation() {
        global $wpdb;
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? 'User requested human support');
        
        if (!$session_id) {
            wp_send_json_error(['text' => 'Invalid session']);
        }

        $agent_available = $this->is_agent_available();
        if (!$agent_available) {
            $admin_email = get_option('admin_email');
            $contact_page = get_page_by_path('contact');
            $contact_url = $contact_page ? get_permalink($contact_page->ID) : '';
            $message = "No agents are currently online. Please contact us at $admin_email";
            if ($contact_url) {
                $message .= " or visit $contact_url.";
            } else {
                $message .= '.';
            }
            $message .= " I'll still do my best to help you while you wait.";

            wp_send_json_success([
                'text' => $message,
                'agent_available' => false
            ]);
        }
        
        $table = $wpdb->prefix . 'ai_chat_sessions';
        $wpdb->update($table, [
            'status' => 'waiting',
            'requires_escalation' => 1,
            'escalation_reason' => $reason,
            'escalated_at' => current_time('mysql')
        ], ['id' => $session_id]);
        
        wp_send_json_success([
            'text' => 'Your request has been sent to our support team.',
            'agent_available' => true
        ]);
    }

    /**
     * AJAX: Agent sends a message to a chat.
     */
    public function agent_update_chat() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['text' => 'Unauthorized']);
        }
        
        global $wpdb;
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $agent_id = get_current_user_id();
        
        if (!$session_id || empty($message)) {
            wp_send_json_error(['text' => 'Invalid input']);
        }
        
        $msg_table = $wpdb->prefix . 'ai_chat_messages';
        $session_table = $wpdb->prefix . 'ai_chat_sessions';
        
        $wpdb->update($session_table, [
            'status' => 'human_handling',
            'agent_id' => $agent_id
        ], ['id' => $session_id]);
        
        $wpdb->insert($msg_table, [
            'session_id' => $session_id,
            'role' => 'AGENT',
            'message' => $message
        ]);
        
        wp_send_json_success(['text' => 'Message sent']);
    }

    /**
     * AJAX: Get list of chats waiting or being handled.
     */
    public function get_agent_chats() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Unauthorized']);
        }
        
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
            ORDER BY s.escalated_at DESC, s.started_at DESC
        ");
        
        wp_send_json_success($chats);
    }

    /**
     * AJAX: Mark a chat as resolved.
     */
    public function mark_resolved() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['text' => 'Unauthorized']);
        }
        
        global $wpdb;
        
        $session_id = intval($_POST['session_id'] ?? 0);
        if (!$session_id) {
            wp_send_json_error(['text' => 'Invalid session']);
        }
        
        $table = $wpdb->prefix . 'ai_chat_sessions';
        $wpdb->update($table, [
            'status' => 'resolved',
            'resolved_at' => current_time('mysql')
        ], ['id' => $session_id]);
        
        wp_send_json_success(['text' => 'Chat marked as resolved']);
    }

    /**
     * AJAX: Poll for new agent messages (frontend).
     */
    public function fetch_agent_messages() {
        global $wpdb;
        $session_id = intval($_POST['session_id'] ?? 0);
        $since_id   = intval($_POST['since_id'] ?? 0);

        if (!$session_id) {
            wp_send_json_error(['error' => 'Invalid session']);
        }

        $table = $wpdb->prefix . 'ai_chat_messages';
        $sql   = $wpdb->prepare("SELECT * FROM $table WHERE session_id = %d AND role = %s", $session_id, 'AGENT');
        if ($since_id) {
            $sql .= $wpdb->prepare(" AND id > %d", $since_id);
        }
        $sql .= " ORDER BY id ASC";

        $msgs = $wpdb->get_results($sql);
        wp_send_json_success($msgs);
    }

    /**
     * AJAX: Agent heartbeat (keeps agent online).
     */
    public function agent_ping() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        global $wpdb;
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error();
        }

        $table = $wpdb->prefix . 'ai_chat_agents';
        $now = current_time('mysql');

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d", $user_id));
        if ($existing) {
            $wpdb->update($table, [
                'is_online' => 1,
                'last_seen' => $now
            ], ['id' => $existing]);
        } else {
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'is_online' => 1,
                'last_seen' => $now
            ]);
        }

        wp_send_json_success();
    }

    /**
     * Injects heartbeat script in admin footer.
     */
    public function admin_agent_heartbeat_script() {
        if (!current_user_can('manage_options')) return;
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
        (function(){
            const ping = () => fetch('<?php echo esc_js($ajaxUrl); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'ai_agent_ping' })
            }).catch(()=>{});
            ping();
            setInterval(ping, 30000);
        })();
        </script>
        <?php
    }
}