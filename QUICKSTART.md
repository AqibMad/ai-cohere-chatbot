# AI Chatbot Plugin - Quick Reference

## 🎯 Key Features Added

### 1. Automatic Escalation Detection
- **Keyword matching** - Detects refund, complaint, urgent, etc.
- **Sentiment analysis** - Flags unhappy customers
- **Admin configuration** - Customize keywords & thresholds

### 2. Admin Dashboard
**Tab: Settings**
- Enable/disable escalation
- Custom escalation keywords (comma-separated)
- Sentiment threshold (-1 to 1 scale)

**Tab: Agent Dashboard**
- View all waiting chats
- See escalation reason
- Quick view button for each chat

**Tab: Chat History**
- View individual chats
- Respond as human agent
- Mark chats as resolved

### 3. Frontend Changes
- New "Connect to Human" button in chat footer
- Click to request human agent escalation
- Shows status: "A human agent will be with you shortly"

### 4. Database Improvements
New columns in `wp_ai_chat_sessions`:
- `status` - ai_handling | waiting | human_handling | resolved
- `agent_id` - Which agent is handling
- `sentiment_score` - Automatic sentiment analysis
- `requires_escalation` - Flag for escalated chats
- `escalation_reason` - Why it was escalated

---

## ⚡ Quick Setup

### Step 1: Go to Admin Settings
```
Dashboard → AI Chatbot → Settings
```

### Step 2: Enable Escalation
```
☑ Enable Escalation
```

### Step 3: Set Keywords
```
Escalation Keywords:
refund,complaint,urgent,contract,support,help,cancel,issue,problem
```

### Step 4: Adjust Sentiment (Optional)
```
Sentiment Threshold: -0.3
(Lower = more sensitive to negative messages)
```

### Step 5: Save
```
Click "Save Settings"
```

---

## 👥 For Support Agents

### View New Chats
1. Go to **AI Chatbot → Agent Dashboard**
2. See all waiting chats with priority flag
3. Click "View Chat" to open conversation

### Respond to Customer
1. Read the chat history
2. Type your response in the text box
3. Click "Send"
4. Message appears instantly in customer's chat

### Complete the Chat
1. After helping customer, click "Mark as Resolved"
2. Chat is closed and archived

---

## 🎤 For Customers (Frontend)

### Start Chat
1. Click chat icon in bottom right
2. Enter name and email
3. Click "Start conversation"

### Chat with AI
1. Type your question
2. AI responds with relevant information

### Request Human Agent
1. If AI can't help, click "Connect to Human" button
2. Message shows: "A human agent will be with you shortly"
3. Human agent joins the conversation
4. Continue chatting with human agent

---

## 🔍 How Escalation Works

```
Customer types: "I need a refund"
         ↓
AI detects "refund" keyword (or negative sentiment)
         ↓
Chat status → "waiting"
         ↓
Admin sees in Agent Dashboard
         ↓
Agent opens chat history
         ↓
Agent responds to customer
         ↓
Chat status → "human_handling"
         ↓
Customer sees agent's response
         ↓
Agent completes and marks "resolved"
```

---

## ⚙️ Configuration Presets

### Strict (More Escalations)
```
Keywords: problem,issue,help,support,urgent,asap,contact,cancel,refund,complaint
Sentiment: -0.2
```

### Standard (Recommended)
```
Keywords: refund,complaint,urgent,contract,cancel,issue,bug
Sentiment: -0.3
```

### Relaxed (Fewer Escalations)
```
Keywords: refund,urgent,contract,cancel,emergency
Sentiment: -0.5
```

---

## 📊 Monitoring

### Check Agent Dashboard Regularly
- New chats appear with "waiting" status
- Red flag shows escalation reason
- Sort by most recent or by priority

### View Chat History
- Complete conversation log
- Track agent responses
- See resolution time

### Database Stats
```sql
-- Escalation rate
SELECT COUNT(CASE WHEN requires_escalation=1 THEN 1 END) 
FROM wp_ai_chat_sessions;

-- Active chats waiting for agent
SELECT * FROM wp_ai_chat_sessions 
WHERE status = 'waiting';

-- Chat by agent
SELECT agent_id, COUNT(*) FROM wp_ai_chat_sessions 
GROUP BY agent_id;
```

---

## 🚨 Common Issues

| Issue | Solution |
|-------|----------|
| Escalation button missing | Enable in Settings → Human Support Handoff |
| No chats in dashboard | Check if chat status is "waiting" or "human_handling" |
| Sentiment not working | Verify API key and check error log |
| Agent can't respond | User must be WordPress Admin (manage_options) |
| Messages not saving | Check wp_ai_chat_messages table exists |

---

## 📱 Mobile Considerations

- Escalation button responsive on mobile
- Chat history works on smartphones
- Agent dashboard optimized for tablet
- Full functionality on all devices

---

## 🔐 Security Notes

- Only WordPress admins can see Agent Dashboard
- Only admins can respond to chats
- Session IDs track conversations
- Email from contact form validated
- All messages sanitized before DB

---

## 📞 Next Steps

1. ✅ Enable escalation in settings
2. ✅ Test with a keyword like "refund"
3. ✅ Check Agent Dashboard for new chat
4. ✅ Send test response as agent
5. ✅ Monitor adoption with your team

---

**Version:** 2.3 (With Human Escalation)  
**Last Updated:** March 2026  
**Status:** Ready for Production
