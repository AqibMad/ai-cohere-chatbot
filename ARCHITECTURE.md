# System Architecture & Data Flow

## 🏗️ Overall Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    WORDPRESS SITE                            │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────────────────────────────────────────────┐  │
│  │         FRONTEND (Customer-Facing)                   │  │
│  ├──────────────────────────────────────────────────────┤  │
│  │  Chat Widget (Shortcode)                             │  │
│  │  ├─ User Form (name, email, phone)                   │  │
│  │  ├─ Chat Messages (AI + future Agent)                │  │
│  │  ├─ Input Field                                       │  │
│  │  ├─ [Send Message] button                            │  │
│  │  └─ [Connect to Human] button (NEW)                  │  │
│  └──────────────────────────────────────────────────────┘  │
│           ↓                                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │     AJAX HANDLERS (wp-admin/admin-ajax.php)          │  │
│  ├──────────────────────────────────────────────────────┤  │
│  │  ✓ ai_stream_chat                                    │  │
│  │  ✓ ai_save_chat_session (NEW: returns session_id)   │  │
│  │  ✓ ai_save_chat                                      │  │
│  │  ✓ ai_request_escalation (NEW)                       │  │
│  │  ✓ ai_agent_update_chat (NEW)                        │  │
│  │  ✓ ai_get_agent_chats (NEW)                          │  │
│  │  ✓ ai_mark_resolved (NEW)                            │  │
│  └──────────────────────────────────────────────────────┘  │
│           ↓                                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │     AI LOGIC LAYER                                   │  │
│  ├──────────────────────────────────────────────────────┤  │
│  │  • generate_embedding() → Cohere API                 │  │
│  │  • semantic_search() → Search similar posts          │  │
│  │  • hybrid_search() → WP search + semantic            │  │
│  │  • stream_chat() → Get AI response from Cohere       │  │
│  │  • should_escalate() (NEW) → Check keywords+sentiment│  │
│  │  • calculate_sentiment() (NEW) → Sentiment analysis  │  │
│  └──────────────────────────────────────────────────────┘  │
│           ↓                                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │     BACKEND APIs                                     │  │
│  ├──────────────────────────────────────────────────────┤  │
│  │  ⊙ Cohere API (embeddings + chat)                    │  │
│  │  ⊙ Cohere Models:                                    │  │
│  │    - embed-english-v2.0 (embeddings)                 │  │
│  │    - command-a-03-2025 (chat responses)              │  │
│  └──────────────────────────────────────────────────────┘  │
│           ↓                                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │     DATABASE LAYER                                   │  │
│  ├──────────────────────────────────────────────────────┤  │
│  │                                                       │  │
│  │  wp_ai_post_embeddings                               │  │
│  │  ├─ post_id                                           │  │
│  │  └─ embedding (vector)                               │  │
│  │                                                       │  │
│  │  wp_ai_chat_sessions (ENHANCED)                      │  │
│  │  ├─ id (session)                                      │  │
│  │  ├─ name, email, phone                               │  │
│  │  ├─ status ← NEW                                      │  │
│  │  ├─ agent_id ← NEW                                    │  │
│  │  ├─ sentiment_score ← NEW                             │  │
│  │  ├─ requires_escalation ← NEW                         │  │
│  │  ├─ escalation_reason ← NEW                           │  │
│  │  └─ timestamps                                        │  │
│  │                                                       │  │
│  │  wp_ai_chat_messages                                 │  │
│  │  ├─ session_id                                        │  │
│  │  ├─ role (user/assistant/agent)                      │  │
│  │  ├─ message                                           │  │
│  │  └─ created_at                                        │  │
│  │                                                       │  │
│  │  wp_ai_chat_agents (NEW)                             │  │
│  │  ├─ user_id                                           │  │
│  │  ├─ is_online                                         │  │
│  │  ├─ max_chats / current_chats                         │  │
│  │  └─ last_seen                                         │  │
│  │                                                       │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐  │
│  │     ADMIN PANEL (NEW TABS)                           │  │
│  ├──────────────────────────────────────────────────────┤  │
│  │  1. Settings                                          │  │
│  │     ├─ API Config (existing)                          │  │
│  │     └─ Escalation Config (NEW)                        │  │
│  │  2. Agent Dashboard (NEW) ← Main interface for agents│  │
│  │     ├─ View waiting chats                             │  │
│  │     ├─ See escalation reasons                         │  │
│  │     └─ Quick access to view chat                      │  │
│  │  3. Chat History (NEW)                                │  │
│  │     ├─ List view of all chats                         │  │
│  │     └─ Full chat viewer & response interface          │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔄 Message Flow Diagram

### Normal Flow (No Escalation)
```
Customer:
  "What's your return policy?"
       ↓
AI Detects no escalation keywords
       ↓
Check sentiment: Neutral (0.1)
       ↓
Not below threshold (-0.3)
       ↓
Semantic search finds relevant posts
       ↓
Cohere generates response
       ↓
Customer sees: "Our return policy is..."
       ↓
Session status: ai_handling ✓
```

### Escalation Flow
```
Customer:
  "I want a refund now!"
       ↓
AI Detects "refund" keyword
       ↓
Additional check: Negative sentiment (-0.5)
       ↓
Triggers: should_escalate() → TRUE
       ↓
Update session:
  • status = "waiting"
  • requires_escalation = 1
  • escalation_reason = "AI detected escalation trigger"
  • escalated_at = NOW()
       ↓
Customer sees: "Please wait, connecting to agent..."
       ↓
Agent Dashboard shows new "waiting" chat
       ↓
Admin clicks "View Chat"
       ↓
Admin types response & sends
       ↓
Session status → "human_handling"
       ↓
Customer sees agent's response
       ↓
Agent marks resolved
       ↓
Session status → "resolved"
       ↓
resolved_at = NOW() ✓
```

### Manual Escalation Flow
```
Customer clicks: [Connect to Human] button
       ↓
JavaScript validates session exists
       ↓
POST request to ai_request_escalation
       ↓
Update session:
  • status = "waiting"
  • requires_escalation = 1
  • escalation_reason = "User requested human support"
  • escalated_at = NOW()
       ↓
Button disabled + confirmation message
       ↓
Same as above from "Agent Dashboard shows..."
```

---

## 👥 Role-Based Access

```
┌─────────────────────────────────────┐
│         CUSTOMER (Visitor)          │
├─────────────────────────────────────┤
│ ✓ Chat with AI                      │
│ ✓ Click "Connect to Human" button    │
│ ✓ View chat history (session storage)│
│ ✗ Cannot see admin panel             │
│ ✗ Cannot respond as agent            │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│    WORDPRESS ADMIN/AGENT            │
├─────────────────────────────────────┤
│ ✓ Access all admin menu pages        │
│ ✓ View Agent Dashboard               │
│ ✓ View all customer chats            │
│ ✓ Respond to escalated chats         │
│ ✓ Mark chats as resolved             │
│ ✓ Configure escalation settings      │
│ ✓ View chat analytics (database)     │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│   SUPER ADMIN                       │
├─────────────────────────────────────┤
│ ✓ All Agent capabilities             │
│ ✓ Manage other agents                │
│ ✓ Delete/archive chats               │
│ ✓ Export chat data                   │
│ ✓ Modify plugin settings             │
└─────────────────────────────────────┘
```

---

## 📊 Chat Status Progression

```
START
  │
  ├─→ "ai_handling" (Default)
  │   │
  │   ├─→ User satisfied
  │   │   └─→ User closes chat
  │   │       └─→ [END - No Escalation]
  │   │
  │   └─→ User types escalation keyword/bad sentiment
  │       └─→ Auto-detect: should_escalate() = TRUE
  │           └─→ "waiting"
  │               │
  │               ├─→ User manually clicks "Connect to Human"
  │               │   └─→ "waiting" (confirmed)
  │               │
  │               └─→ Admin assigns
  │                   └─→ "human_handling"
  │                       │
  │                       ├─→ Agent responds
  │                       │
  │                       └─→ Issue resolved
  │                           └─→ "resolved"
  │                               └─→ resolved_at = NOW()
  │                                   └─→ [END - Escalation Complete]
  │
  └─→ User manually clicks "Connect to Human" (anytime)
      └─→ "waiting"
          └─→ [Same as above]
```

---

## 🔐 Data Security

```
Input → Sanitization → Database → Output
  │          │              │         │
  │          │              │         └─ Escaping
  │          │              │
  │          │              └─ Prepared Statements
  │          │
  │          └─ sanitize_text_field()
  │             sanitize_textarea_field()
  │             sanitize_email()
  │
  └─ User input from frontend
     Email addresses
     Chat messages
     Customer names
```

---

## 🎯 Session Tracking

```
SESSION LIFECYCLE:

1. Customer fills form
   └─ save_chat_session() creates record
      └─ returns session_id to frontend

2. Frontend stores session_id in JavaScript
   └─ Passes with every message

3. Each message saved to ai_chat_messages
   └─ Linked via session_id

4. Status updates on session record
   └─ Track current handling state

5. Timestamp tracking
   └─ started_at (when session created)
   └─ escalated_at (when escalation triggered)
   └─ resolved_at (when chat completed)

6. Agent assignment
   └─ agent_id stores which admin handled it
```

---

## 📈 Performance Considerations

```
Optimization Areas:

1. Embeddings
   - Generated on-demand for queries
   - Cached in database for posts
   - Hourly cron job updates old embeddings

2. Sentiment Analysis
   - Simple keyword matching (no API call)
   - Calculated on message receipt
   - Cached in sentiment_score

3. Chat Queries
   - Indexed by session_id
   - Indexed by status (for dashboard)
   - Limited to recent chats (pagination recommended)

4. Rate Limiting
   - Per IP: 10 requests/minute
   - Prevents abuse

Recommended Indexes:
  - wp_ai_chat_messages.session_id
  - wp_ai_chat_sessions.status
  - wp_ai_chat_sessions.agent_id
  - wp_ai_chat_sessions.email
```

---

## 🔗 Plugin Dependencies

```
Required:
  • WordPress 5.0+
  • Cohere API Account
  • Cohere API Key

Optional (Recommended):
  • WordPress admin for agent accounts
  • PHP 7.4+
  • MySQL 5.7+

External APIs:
  • cohere.com/v2/embed (embeddings)
  • cohere.com/v2/chat (chat responses)
```

---

## 📱 Frontend Architecture

```
index (Chat Widget Container)
│
├─ Chat Toggle Button (💬)
│  └─ Unread Badge
│
├─ Chat Panel
│  │
│  ├─ Header
│  │  ├─ Title
│  │  └─ Close Button
│  │
│  ├─ Body (Two states)
│  │  │
│  │  ├─ STATE 1: User Form (Initial)
│  │  │  ├─ Name field
│  │  │  ├─ Phone field
│  │  │  ├─ Email field
│  │  │  └─ Start Conversation button
│  │  │
│  │  └─ STATE 2: Chat Area (Active)
│  │     │
│  │     ├─ Chat Box (scrollable)
│  │     │  ├─ Message bubbles (user + AI)
│  │     │  ├─ Avatars
│  │     │  ├─ Timestamps
│  │     │  └─ Typing indicator
│  │     │
│  │     ├─ Input Area
│  │     │  ├─ Input field
│  │     │  └─ Send button
│  │     │
│  │     └─ Footer (NEW)
│  │        ├─ [Connect to Human] button
│  │        └─ Status message
│
└─ Session Storage (Browser)
   ├─ ai_chat_history (messages)
   └─ ai_chat_user (user info)
```

---

## 🎓 Code Structure

```
AI_Support_Chatbot (Main Class)

├─ INITIALIZATION
│  └─ __construct()
│
├─ DATABASE
│  ├─ create_table()
│  └─ activate_cron() / deactivate_cron()
│
├─ ADMIN
│  ├─ admin_menu()
│  ├─ admin_page()
│  ├─ render_agent_dashboard() (NEW)
│  └─ render_chat_history() (NEW)
│
├─ EMBEDDINGS & SEARCH
│  ├─ generate_embedding()
│  ├─ generate_embeddings()
│  ├─ cosine_similarity()
│  ├─ semantic_search()
│  └─ hybrid_search()
│
├─ CHAT HANDLING
│  ├─ extract_cohere_text()
│  ├─ stream_chat()
│  ├─ check_rate_limit()
│  └─ cron_generate_embeddings()
│
├─ ESCALATION & SENTIMENT (NEW)
│  ├─ calculate_sentiment()
│  ├─ should_escalate()
│  ├─ request_escalation()
│  ├─ agent_update_chat()
│  ├─ get_agent_chats()
│  └─ mark_resolved()
│
├─ FRONTEND UI
│  └─ chatbot_ui() [Includes React-like JS]
│
└─ DATA PERSISTENCE
   ├─ save_chat_session()
   └─ save_chat()
```

This architecture ensures:
- ✅ Clean separation of concerns
- ✅ Easy to extend with new features
- ✅ Secure handling of customer data
- ✅ Efficient database queries
- ✅ Responsive frontend interface
