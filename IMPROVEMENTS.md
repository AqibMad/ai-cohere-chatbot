# AI Customer Agent Plugin - Improvements Guide

## 🎯 What's New

Your AI chatbot now supports **human support escalation** with automatic detection and agent management. Users can seamlessly transfer to human agents when needed.

---

## 📊 Database Improvements

### Enhanced `ai_chat_sessions` Table
```sql
NEW FIELDS ADDED:
- status: VARCHAR(50) - Track chat state:
  * 'ai_handling' (default) → AI is responding
  * 'waiting' → Waiting for human agent
  * 'human_handling' → Agent is handling
  * 'resolved' → Chat completed

- agent_id: BIGINT - Which agent is handling
- sentiment_score: FLOAT - Auto-calculated sentiment (-1 to 1)
- requires_escalation: TINYINT - Flag for escalation needed
- escalation_reason: VARCHAR(255) - Why it was escalated
- escalated_at: DATETIME - When escalation occurred
- resolved_at: DATETIME - When chat was resolved
```

### New `ai_chat_agents` Table
```sql
Tracks human support agents:
- user_id: Link to WordPress user
- is_online: Agent availability status
- max_chats: Maximum concurrent chats
- current_chats: Active chats now
- last_seen: Last activity timestamp
```

---

## ⚙️ Admin Panel Features

### Tab 1: Settings
**API Configuration** - Same as before
- Cohere API Key
- Embedding & Chat Models
- Search settings

**NEW: Human Support Handoff**
- ✅ Enable Escalation - Toggle escalation feature ON/OFF
- 🔑 Escalation Keywords - Comma-separated keywords that trigger escalation:
  ```
  Default: refund,complaint,urgent,contract,support,help,cancel
  Add more keywords relevant to your business
  ```
- 😞 Sentiment Threshold - Auto-escalate unhappy customers:
  ```
  Default: -0.3 (scale: -1=very negative to 1=very positive)
  Lower values = more sensitive to negative sentiment
  ```

### Tab 2: Agent Dashboard
- View all **waiting chats** automatically
- See escalation reason for each
- Quick view of chat count per customer
- One-click access to full conversation

### Tab 3: Chat History
- Browse all customer conversations
- **View individual chats** with full message history
- **Respond as agent** - Type and send messages directly
- **Mark as resolved** - Close completed chats
- See customer info (name, email, phone)

---

## 🎤 Frontend Escalation Button

### Where Users See It
After entering the chat, users see a new footer section:
```
[Connect to Human] ← Button to request human agent
```

### What Happens When User Clicks
1. Chat is marked as "waiting"
2. Escalation reason is logged
3. Admin gets notification (via Agent Dashboard tab)
4. Button disables with confirmation message
5. Human agent sees it in their queue

---

## 🔍 Automatic Escalation Detection

### Keyword Matching
If user's message contains any escalation keyword:
- Chat is automatically flagged
- Status changes to "waiting"
- Agent dashboard shows priority

### Sentiment Analysis
Simple NLP analyzes message for:
**Positive words:** great, good, excellent, love, happy, thanks, appreciate, perfect, satisfied  
**Negative words:** bad, terrible, hate, angry, frustrated, disappointed, complaint, problem

If sentiment < threshold → Auto-escalate

---

## 👥 How Agents Respond

### For WordPress Admins (Support Agents)

1. **Go to:** AI Chatbot → Agent Dashboard tab
2. **See:** All waiting/active chats sorted by priority
3. **Click:** "View Chat" button
4. **View:** Full conversation history
5. **Type:** Your response in the text area
6. **Click:** "Send" button
7. **Message:** Saved to chat and visible to customer
8. **When Done:** Click "Mark as Resolved"

### Example Flow
```
Customer: "I want a refund!"
↓ AI detects "refund" keyword
↓ Chat escalated to agent
↓ Agent sees in dashboard
↓ Agent opens chat, reads history
↓ Agent types: "I understand. Let me help you process that."
↓ Customer sees agent's response in real-time
↓ Agent marks as resolved when done
```

---

## 📝 API Endpoints (For Developers)

### New AJAX Actions

#### 1. Request Escalation
```javascript
POST /wp-admin/admin-ajax.php
action: 'ai_request_escalation'
session_id: 123
reason: 'User requested support'
```

#### 2. Get Agent Chats
```javascript
POST /wp-admin/admin-ajax.php
action: 'ai_get_agent_chats'
// Returns all waiting/active chats
```

#### 3. Agent Update Chat
```javascript
POST /wp-admin/admin-ajax.php
action: 'ai_agent_update_chat'
session_id: 123
message: 'Response from agent'
```

#### 4. Mark Resolved
```javascript
POST /wp-admin/admin-ajax.php
action: 'ai_mark_resolved'
session_id: 123
```

---

## 🚀 Setup Instructions

### 1. Activate the Updated Plugin
- The database tables are created automatically on activation
- If tables already exist, they'll be updated with new columns

### 2. Configure Escalation Settings
- Go to **AI Chatbot → Settings**
- Check "Enable Escalation"
- Add your custom keywords
- Adjust sentiment threshold if needed
- Save settings

### 3. Set Up Support Agents
- Create/designate WordPress admin users as agents
- They can access the **Agent Dashboard** tab
- Set their availability by marking online/offline

### 4. Test It Out
- Open the chat widget on frontend
- Type a message with an escalation keyword (e.g., "refund")
- Watch the **Agent Dashboard** for the new chat
- Click "View Chat" to respond

---

## 🎯 Configuration Examples

### Example 1: E-commerce Store
```
Escalation Keywords: refund,return,cancel,damaged,wrong,shipping,deliver
Sentiment Threshold: -0.2 (lower = more sensitive)
```

### Example 2: SaaS/Software
```
Escalation Keywords: bug,crash,error,urgent,emergency,downtime,api,integration,issue
Sentiment Threshold: -0.3 (standard)
```

### Example 3: Services Business
```
Escalation Keywords: complaint,urgent,help,emergency,asap,critical,support,cancel
Sentiment Threshold: -0.4 (higher = less sensitive)
```

---

## 📊 Database Queries (For Analytics)

### Get All Escalated Chats
```sql
SELECT * FROM wp_ai_chat_sessions 
WHERE requires_escalation = 1 
ORDER BY escalated_at DESC;
```

### Get Chats by Agent
```sql
SELECT * FROM wp_ai_chat_sessions 
WHERE agent_id = 5 AND status = 'human_handling' 
ORDER BY started_at DESC;
```

### Get Average Resolution Time
```sql
SELECT 
  AVG(TIMESTAMPDIFF(MINUTE, started_at, resolved_at)) as avg_minutes
FROM wp_ai_chat_sessions
WHERE resolved_at IS NOT NULL;
```

### Get Escalation Rate
```sql
SELECT 
  COUNT(CASE WHEN requires_escalation=1 THEN 1 END) / COUNT(*) * 100 as escalation_rate
FROM wp_ai_chat_sessions;
```

---

## 🔧 Troubleshooting

### Escalation Button Not Appearing
- ✅ Confirm "Enable Escalation" is checked in settings
- ✅ JavaScript console should not show errors
- ✅ Refresh the page

### Chats Not Escalating Automatically
- ✅ Check your keywords are in lowercase
- ✅ Verify sentiment threshold makes sense (-1 to 1 scale)
- ✅ Check your Cohere API quota (chat analysis uses API calls)

### Agent Can't See Chats
- ✅ User must have "manage_options" capability (Admin/Super Admin)
- ✅ Chat must be in "waiting" or "human_handling" status
- ✅ Check if chats were created before plugin update

### Messages Not Saving
- ✅ Verify database tables exist (check wp_ai_chat_messages)
- ✅ Confirm session_id is being passed correctly
- ✅ Check WordPress error log for SQL errors

---

## 💡 Advanced Features You Can Add

### Future Enhancements
1. **Live Chat Updates** - WebSocket for real-time messages
2. **Email Notifications** - Alert agents of new escalations
3. **Chat Queue** - Show estimated wait time
4. **User Ratings** - Rate agent responses after resolution
5. **Chat Transcripts** - Email chat history to customer
6. **Canned Responses** - Pre-written agent responses
7. **Multi-language Support** - Auto-translate escalated chats
8. **Analytics Dashboard** - Charts for escalation trends
9. **Chatbot Learning** - Track which escalations could be auto-resolved
10. **Mobile App** - Agent app for phone responses

---

## 📞 Summary

Your plugin now has:
- ✅ Automatic escalation detection (keywords + sentiment)
- ✅ Human agent dashboard with full chat history
- ✅ Live chat response from agents
- ✅ Session tracking and chat status management
- ✅ Admin configuration for escalation rules
- ✅ User-facing "Connect to Human" button

**The flow:**
User sends message → AI responds → If escalation keyword/negative sentiment detected → Chat marked "waiting" → Agent sees in dashboard → Agent responds → Customer sees real-time updates → Resolved ✓

---

**Need help?** Check the plugin settings or review the API endpoints section.
