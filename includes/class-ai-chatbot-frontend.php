<?php
class AI_Support_Chatbot_Frontend {

    public function __construct() {
        add_shortcode('ai_chatbot', [$this, 'chatbot_ui']);

        add_action('wp_ajax_ai_stream_chat', [$this, 'handle_stream_chat']);
        add_action('wp_ajax_nopriv_ai_stream_chat', [$this, 'handle_stream_chat']);

        add_action('wp_ajax_ai_save_chat_session', [$this, 'save_chat_session']);
        add_action('wp_ajax_nopriv_ai_save_chat_session', [$this, 'save_chat_session']);

        add_action('wp_ajax_ai_save_chat', [$this, 'save_chat']);
        add_action('wp_ajax_nopriv_ai_save_chat', [$this, 'save_chat']);
    }

    public function handle_stream_chat() {
        $api = new AI_Support_Chatbot_API();
        $api->ajax_stream_chat();
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
            'phone'=>$phone,
            'status'=>'ai_handling'
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
        $session_id = intval($_POST['session_id'] ?? 0);

        if(!$user || !$message){
            wp_send_json_error();
        }

        $session = null;
        if ($session_id) {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $session_table WHERE id = %d",
                $session_id
            ));
        }
        
        if (!$session) {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $session_table WHERE email=%s ORDER BY id DESC LIMIT 1",
                $user['email']
            ));
        }

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
            #ai-chat-header h4{margin:0;font-size:16px;letter-spacing:.2px;color:#fff;}
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
            .avatar-agent{background:#4a90e2}
            .bubble.agent{background:#e8f4ff;border:1px solid #c3ddff}
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
                            <h4>(MEC), Inc. AI Support</h4>
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
                            <button id="ai-start-chat">Start conversation</button>
                        </div>
                    </div>

                    <div id="ai-chat-area" style="display:none;">
                        <div id="ai-chat-box" role="log" aria-live="polite" aria-relevant="additions"></div>

                        <div id="ai-chat-input-area">
                            <input id="ai-chat-input" type="text" placeholder="Ask a question about our site, services, or docs..." aria-label="Type your question" />
                            <button id="ai-chat-send" aria-label="Send message">Send</button>
                        </div>

                        <!-- <div style="padding:12px;border-top:1px solid #e6e7eb;background:#f7f8fb;display:flex;gap:8px;align-items:center;">
                            <div id="ai-agent-status" style="display:flex;align-items:center;gap:8px;flex:1;">
                                <span id="ai-status-dot" style="width:10px;height:10px;border-radius:50%;background:#999;display:inline-block;"></span>
                                <span id="ai-status-text" style="font-size:13px;color:#666;">Connecting...</span>
                            </div>
                            <button id="ai-request-agent-btn" class="button button-small" style="display:none;">Request Agent</button>
                        </div> -->
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function(){
                const ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
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

                let history = [];
                try {
                    const raw = sessionStorage.getItem('ai_chat_history');
                    if(raw) history = JSON.parse(raw) || [];
                } catch(e){ history = []; }

                let userData = null;
                try{
                    const u = sessionStorage.getItem('ai_chat_user');
                    if(u) userData = JSON.parse(u);
                }catch(e){ userData = null; }

                let sessionId = null;
                try{
                    const s = sessionStorage.getItem('ai_chat_session');
                    if(s) sessionId = parseInt(s,10) || null;
                } catch(e){ sessionId = null; }

                let unreadCount = 0;
                function updateUnreadBadge(){
                    if(unreadCount > 0){
                        unreadBadge.style.display = 'flex';
                        unreadBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                    } else unreadBadge.style.display = 'none';
                }
                updateUnreadBadge();

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
                    let label = '';
                    if(role === 'user'){
                        span.className = 'msg-avatar avatar-user';
                        label = (userData && userData.name ? userData.name.charAt(0).toUpperCase() : 'Y');
                    } else if(role === 'agent'){
                        span.className = 'msg-avatar avatar-agent';
                        label = 'AG';
                    } else {
                        span.className = 'msg-avatar avatar-ai';
                        label = 'AI';
                    }
                    span.setAttribute('aria-hidden', 'true');
                    span.textContent = label;
                    return span;
                }

                function appendMessageToDOM(message, incrementUnread = true){
                    const row = document.createElement('div');
                    let rowClass = 'ai';
                    if(message.role === 'user') rowClass = 'user';
                    else if(message.role === 'agent') rowClass = 'agent';
                    row.className = 'msg-row ' + rowClass;

                    const bubble = document.createElement('div');
                    let bubbleClass = 'ai';
                    if(message.role === 'user') bubbleClass = 'user';
                    else if(message.role === 'agent') bubbleClass = 'agent';
                    bubble.className = 'bubble ' + bubbleClass;

                    if(message.role === 'assistant' && message.isTyping){
                        bubble.innerHTML = '<div class="typing-dots" aria-hidden="true"><span></span><span></span><span></span></div><div style="font-size:12px;color:var(--muted);margin-top:6px">AI is typing…</div>';
                    } else {
                        bubble.innerHTML = '<div style="white-space:pre-wrap;">' + escapeHtml(message.content) + '</div>';
                        const meta = document.createElement('div');
                        meta.className = 'meta';
                        meta.innerHTML = '<time datetime="'+(message.ts||'')+'">'+formatTime(message.ts)+'</time>';
                        bubble.appendChild(meta);
                    }

                    if(message.role === 'assistant' || message.role === 'agent'){
                        row.appendChild(createAvatar(message.role));
                        row.appendChild(bubble);
                    } else {
                        row.appendChild(bubble);
                        row.appendChild(createAvatar('user'));
                    }

                    chatBox.appendChild(row);

                    if(incrementUnread && panel.classList.contains('open') === false && (message.role === 'assistant' || message.role === 'agent')){
                        unreadCount++;
                        updateUnreadBadge();
                    }
                }

                renderHistory();

                toggleBtn.addEventListener('click', function(){
                    const open = panel.classList.toggle('open');
                    toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if(open){
                        unreadCount = 0; updateUnreadBadge();
                        if(userData && userData.name && userData.email){
                            userForm.style.display = 'none';
                            chatArea.style.display = 'flex';
                            if(sessionId){
                                checkAgentAvailability();
                                updateStatusDisplay();
                            }
                        } else {
                            userForm.style.display = 'flex';
                            chatArea.style.display = 'none';
                        }
                        setTimeout(()=> { if(chatArea.style.display!=='none') input.focus(); }, 220);
                    }
                });

                closeBtn.addEventListener('click', function(){ panel.classList.remove('open'); toggleBtn.setAttribute('aria-expanded','false'); });

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

                    fetch(ajaxUrl, {
                        method:'POST',
                        headers: { 'Content-Type':'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ai_save_chat_session',
                            user: JSON.stringify(userData),
                            initial: 'started'
                        })
                    }).then(r => r.json()).then(data => {
                        if (data.success && data.data && data.data.session_id) {
                            sessionId = data.data.session_id;
                            try{ sessionStorage.setItem('ai_chat_session', sessionId); }catch(e){}
                            checkAgentAvailability();
                            setInterval(checkAgentAvailability, 6000);
                            pollAgentMessages();
                            setInterval(pollAgentMessages, 4000);
                        }
                    }).catch(()=>{});
                });

                let typingPlaceholder = null;
                let agentAvailable = false;

                function checkAgentAvailability(){
                    if (!sessionId) return;
                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ai_check_agent_availability'
                        })
                    }).then(r => r.json()).then(data => {
                        if (data && data.success && data.data) {
                            agentAvailable = data.data.agent_available || false;
                            updateStatusDisplay();
                        }
                    }).catch(()=>{});
                }

                function updateStatusDisplay(){
                    const statusDot = document.getElementById('ai-status-dot');
                    const statusText = document.getElementById('ai-status-text');
                    const requestBtn = document.getElementById('ai-request-agent-btn');
                    
                    if (agentAvailable) {
                        requestBtn.style.display = 'inline-block';
                        requestBtn.disabled = false;
                        requestBtn.textContent = 'Request Human Agent';
                        statusDot.style.background = '#2ccc71';
                        statusText.textContent = '🟢 Agent Available';
                    } else {
                        requestBtn.style.display = 'none';
                        statusDot.style.background = '#95a5a6';
                        statusText.textContent = '🤖 AI Chat';
                    }
                }

                function makeCleanHistoryFrom(historyArray){
                    return (historyArray || []).filter(m => !m.isTyping).map(m => {
                        const role = (m.role === 'user') ? 'USER' : (m.role === 'assistant' ? 'CHATBOT' : (m.role || '').toUpperCase());
                        return {
                            role: role,
                            message: (m.content || m.message || '')
                        };
                    });
                }

                let lastAgentId = 0;

                function pollAgentMessages(){
                    if (!sessionId) return;
                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ai_fetch_agent_messages',
                            session_id: sessionId,
                            since_id: lastAgentId
                        })
                    }).then(r => r.json()).then(data => {
                        if(data && data.success && Array.isArray(data.data)){
                            data.data.forEach(msg => {
                                if(msg.role === 'AGENT'){
                                    const aiMsg = {
                                        role: 'agent',
                                        content: msg.message,
                                        ts: new Date().toISOString()
                                    };
                                    history.push(aiMsg);
                                    try{ sessionStorage.setItem('ai_chat_history', JSON.stringify(history)); }catch(e){}
                                    appendMessageToDOM(aiMsg, false);
                                    scrollToBottom();
                                    lastAgentId = Math.max(lastAgentId, parseInt(msg.id,10));

                                    // Hide request button and update status after first agent message
                                    const requestBtn = document.getElementById('ai-request-agent-btn');
                                    requestBtn.style.display = 'none';
                                    document.getElementById('ai-status-text').textContent = '💬 Agent Online';
                                    document.getElementById('ai-status-dot').style.background = '#2ccc71';
                                }
                            });
                        }
                    }).catch(()=>{});
                }

                if(sessionId){
                    checkAgentAvailability();
                    setInterval(checkAgentAvailability, 6000);
                    pollAgentMessages();
                    setInterval(pollAgentMessages,4000);
                }

                async function sendMessage(){
                    const text = input.value.trim();
                    if(!text) return;

                    const userMsg = { role: 'user', content: text, ts: new Date().toISOString() };
                    history.push(userMsg);
                    try{ sessionStorage.setItem('ai_chat_history', JSON.stringify(history)); }catch(e){}
                    appendMessageToDOM(userMsg, false);
                    input.value = '';
                    scrollToBottom();

                    // Save user message to server
                    if (sessionId && userData) {
                        fetch(ajaxUrl, {
                            method:'POST',
                            headers: { 'Content-Type':'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'ai_save_chat',
                                session_id: sessionId,
                                user: JSON.stringify(userData),
                                message: JSON.stringify({ role: 'user', message: text }),
                                full_history: JSON.stringify(makeCleanHistoryFrom(history))
                            })
                        }).catch(()=>{});
                    }

                    const typingMsg = { role:'assistant', isTyping:true, ts: new Date().toISOString() };
                    appendMessageToDOM(typingMsg, false);
                    typingPlaceholder = chatBox.lastChild;

                    const cleanedHistory = makeCleanHistoryFrom(history);

                    try {
                        const resp = await fetch(ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'ai_stream_chat',
                                message: text,
                                history: JSON.stringify(cleanedHistory),
                                session_id: sessionId || '',
                                user: userData ? JSON.stringify(userData) : ''
                            })
                        });

                        const result = await resp.json();

                        if(typingPlaceholder) typingPlaceholder.remove();

                        if (result.success && result.data && result.data.agent_handling) {
                            // Agent is handling – do nothing
                            scrollToBottom();
                        } else {
                            let answer = '';
                            if (result && result.success && result.data && result.data.text){
                                answer = result.data.text;
                            } else if (result && result.data && result.data.text) {
                                answer = result.data.text;
                            } else {
                                answer = 'Sorry — I could not get a response. Please try again.';
                            }

                            const aiMsg = { role: 'assistant', content: answer, ts: new Date().toISOString() };
                            history.push(aiMsg);
                            try{ sessionStorage.setItem('ai_chat_history', JSON.stringify(history)); }catch(e){}
                            appendMessageToDOM(aiMsg, false);
                            scrollToBottom();

                            fetch(ajaxUrl, {
                                method:'POST',
                                headers: { 'Content-Type':'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'ai_save_chat',
                                    session_id: sessionId || '',
                                    user: JSON.stringify(userData || {}),
                                    message: JSON.stringify({ role: 'CHATBOT', message: answer }),
                                    full_history: JSON.stringify(makeCleanHistoryFrom(history))
                                })
                            }).catch(()=>{});
                        }

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

                document.getElementById('ai-request-agent-btn').addEventListener('click', function() {
                    if (!sessionId) {
                        alert('Please send a message first');
                        return;
                    }
                    
                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ai_request_escalation',
                            session_id: sessionId,
                            reason: 'User requested to speak with human agent'
                        })
                    }).then(r => r.json()).then(data => {
                        if (data.success && data.data) {
                            if (data.data.text) {
                                const msg = { role: 'assistant', content: data.data.text, ts: new Date().toISOString() };
                                history.push(msg);
                                try{ sessionStorage.setItem('ai_chat_history', JSON.stringify(history)); }catch(e){}
                                appendMessageToDOM(msg, false);
                                scrollToBottom();
                            }

                            if (data.data.agent_available) {
                                const statusText = document.getElementById('ai-status-text');
                                statusText.textContent = '⏳ Waiting for agent...';
                                const btn = document.getElementById('ai-request-agent-btn');
                                btn.disabled = true;
                                btn.textContent = 'Agent Requested';
                            }
                        } else {
                            const errMsg = (data && data.data && data.data.text) ? data.data.text : 'Could not send request. Please try again.';
                            alert(errMsg);
                        }
                    });
                });

                document.addEventListener('keydown', function(e){
                    if(e.key === 'Escape') panel.classList.remove('open');
                });

            })();
        </script>
        <?php
        return ob_get_clean();
    }
}