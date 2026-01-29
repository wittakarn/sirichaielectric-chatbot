# Admin Dashboard - Human Agent Takeover Guide

**Last Updated:** January 29, 2026
**Feature:** Chatbot Pause/Resume for Human Agent Takeover

---

## Table of Contents

1. [Overview](#overview)
2. [Setup Instructions](#setup-instructions)
3. [Using the Dashboard](#using-the-dashboard)
4. [User Commands](#user-commands)
5. [Auto-Resume Feature](#auto-resume-feature)
6. [LINE Developer Documentation](#line-developer-documentation)
7. [Troubleshooting](#troubleshooting)

---

## Overview

The Admin Dashboard allows human agents to take over conversations from the chatbot when users need personalized assistance. This feature provides:

- **Web-based dashboard** for viewing paused conversations
- **Session-based authentication** for security
- **LINE user display names** fetched from LINE Profile API
- **Manual pause/resume controls** for specific conversations
- **Auto-resume functionality** for conversations paused too long
- **Real-time monitoring** with auto-refresh

### How It Works

```
User requests human agent
    ↓
Chatbot pauses (stops responding)
    ↓
Agent sees paused conversation in dashboard
    ↓
Agent responds via LINE Official Account Manager
    ↓
Agent resumes chatbot when done
    ↓
Chatbot continues normal operation
```

---

## Setup Instructions

### Step 1: Generate Password Hash

Generate a secure password hash for admin login:

```bash
cd /path/to/sirichaielectric-chatbot
php admin/generate-password-hash.php

# Or provide password as argument
php admin/generate-password-hash.php mySecurePassword123
```

Copy the generated hash.

### Step 2: Configure Environment Variables

Update `.env` or `.env.local`:

```bash
# Admin Dashboard Configuration
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=<paste_the_hash_from_step_1>

# Auto-resume timeout (optional, default: 30)
AUTO_RESUME_TIMEOUT_MINUTES=30
```

**Security Note:** Never commit `.env` or `.env.local` to git!

### Step 3: Set Up Auto-Resume Cron Job (Optional)

To automatically resume conversations after timeout:

```bash
# Edit crontab
crontab -e

# Add this line (runs every 15 minutes)
*/15 * * * * /usr/bin/php /path/to/sirichaielectric-chatbot/cron/auto-resume-chatbot.php >> /path/to/logs/auto-resume.log 2>&1
```

### Step 4: Access the Dashboard

Navigate to: `https://yourdomain.com/sirichaielectric-chatbot/admin/`

Login with your configured username and password.

---

## Using the Dashboard

### Login

1. Navigate to `/admin/` or `/admin/login.php`
2. Enter your username and password
3. Click "Login"

### Dashboard Features

#### 1. Paused Conversations Table

Shows all conversations currently paused and waiting for human agents:

- **Conversation ID**: Unique identifier (e.g., `line_U1234567890abcdef`)
- **Platform**: LINE or API
- **User**: Display name (fetched from LINE) and user ID
- **Paused At**: When the chatbot was paused
- **Duration**: How long it's been paused
- **Actions**: Resume button

#### 2. Manual Controls

Manually pause or resume chatbot for any conversation:

1. Enter the conversation ID
2. Click "Pause Bot" to stop chatbot responses
3. Click "Resume Bot" to re-enable chatbot

#### 3. Auto-Resume Timeout

Click "Auto-Resume Timeout" to manually trigger auto-resume for all conversations that have exceeded the timeout period.

### Logout

Click the "Logout" button in the top-right corner.

---

## User Commands

Users can request human agents using these commands:

### Pause Commands (User Requests Agent)

Thai commands:
- `ติดต่อพนักงาน` - Contact staff
- `คุยกับพนักงาน` - Talk to staff
- `ขอคุยกับพนักงาน` - Request to talk to staff
- `ต้องการคุยกับพนักงาน` - Want to talk to staff

English commands:
- `/human`
- `/agent`

**Response:** Chatbot sends confirmation and pauses

### Resume Commands (Re-enable Chatbot)

Thai commands:
- `เปิดแชทบอท` - Turn on chatbot
- `เปิดบอท` - Turn on bot

English commands:
- `/bot`
- `/resume`
- `/on`
- `/chatbot`

**Response:** Chatbot sends confirmation and resumes

---

## Auto-Resume Feature

### Purpose

Prevents conversations from being paused indefinitely if agents forget to resume the chatbot.

### Configuration

Set in `.env` or `.env.local`:

```bash
AUTO_RESUME_TIMEOUT_MINUTES=30  # Default: 30 minutes
```

### Triggering Auto-Resume

**Option 1: Cron Job (Recommended)**

```bash
# Runs every 15 minutes
*/15 * * * * /usr/bin/php /path/to/cron/auto-resume-chatbot.php
```

**Option 2: Manual via Dashboard**

Click "Auto-Resume Timeout" button in the dashboard

**Option 3: Manual via CLI**

```bash
php cron/auto-resume-chatbot.php
```

### How It Works

1. Checks all paused conversations
2. Finds conversations paused longer than configured timeout
3. Automatically resumes those conversations
4. Logs the action

---

## LINE Developer Documentation

### LINE Profile API

The dashboard fetches user display names using LINE's Get Profile API:

**Endpoint:** `https://api.line.me/v2/bot/profile/{userId}`

**Reference:** [Get Profile - LINE Developers](https://developers.line.biz/en/reference/messaging-api/#get-profile)

**Headers:**
```
Authorization: Bearer {CHANNEL_ACCESS_TOKEN}
```

**Response:**
```json
{
  "userId": "U1234567890abcdef",
  "displayName": "John Doe",
  "pictureUrl": "https://...",
  "statusMessage": "Hello!"
}
```

### LINE Chat Mode

**Important:** When "Chat Mode" is enabled in LINE Official Account Manager:
- Webhooks are **disabled**
- Chatbot cannot respond
- All messages must be handled manually by agents

**Best Practice:** Keep Chat Mode disabled and use the pause/resume feature instead.

**References:**
- [Receive messages (webhook)](https://developers.line.biz/en/docs/messaging-api/receiving-messages/)
- [Using the Messaging API from a module channel](https://developers.line.biz/en/docs/partner-docs/module-technical-using-messaging-api/)

### Chat Control API (Enterprise)

For enterprise customers, LINE provides a Chat Control API for programmatic control:

- **activated** event: Module channel becomes active
- **deactivated** event: Module channel becomes standby
- **mode** property: "active" or "standby"

**Reference:** [Control chat initiative (Chat Control)](https://developers.line.biz/en/docs/partner-docs/module-technical-chat-control/)

---

## Troubleshooting

### Cannot Login

**Issue:** "Invalid username or password"

**Solutions:**
1. Verify `ADMIN_USERNAME` in `.env`
2. Regenerate password hash:
   ```bash
   php admin/generate-password-hash.php yourpassword
   ```
3. Update `ADMIN_PASSWORD_HASH` in `.env`
4. Clear browser cookies/cache

### Display Names Show "Unknown"

**Issue:** LINE user display names not appearing

**Solutions:**
1. Verify `LINE_CHANNEL_ACCESS_TOKEN` in `.env`
2. Test LINE API access:
   ```bash
   curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://api.line.me/v2/bot/profile/USER_ID
   ```
3. Check error logs for API failures

### Paused Conversations Not Appearing

**Issue:** Dashboard shows "No paused conversations"

**Solutions:**
1. Check database:
   ```sql
   SELECT * FROM conversations WHERE is_chatbot_active = 0;
   ```
2. Verify webhook is processing pause commands:
   ```bash
   tail -f /path/to/error.log | grep "paused chatbot"
   ```
3. Test manually by sending pause command via LINE

### Auto-Resume Not Working

**Issue:** Conversations not auto-resuming

**Solutions:**
1. Check cron job is running:
   ```bash
   crontab -l
   ```
2. Check cron job logs:
   ```bash
   tail -f /path/to/logs/auto-resume.log
   ```
3. Test manually:
   ```bash
   php cron/auto-resume-chatbot.php
   ```
4. Verify timeout setting in `.env`:
   ```bash
   grep AUTO_RESUME_TIMEOUT_MINUTES .env
   ```

### Dashboard Not Loading

**Issue:** 404 or blank page

**Solutions:**
1. Verify file structure:
   ```bash
   ls -la admin/
   # Should show: auth.php, dashboard.php, login.php, logout.php, index.php
   ```
2. Check file permissions:
   ```bash
   chmod 644 admin/*.php
   ```
3. Check server error logs

---

## Security Best Practices

1. **Strong Passwords:** Use complex passwords (12+ characters, mixed case, numbers, symbols)
2. **HTTPS Only:** Always use HTTPS for admin dashboard access
3. **Restrict Access:** Use IP whitelisting or VPN for admin access
4. **Session Timeout:** Browser sessions expire on inactivity
5. **Log Monitoring:** Regularly review login attempts in error logs
6. **Password Rotation:** Change admin password periodically

---

## Architecture

### File Structure

```
sirichaielectric-chatbot/
├── admin/
│   ├── auth.php                    # Authentication utilities
│   ├── login.php                   # Login page
│   ├── dashboard.php               # Main dashboard
│   ├── logout.php                  # Logout handler
│   ├── index.php                   # Redirect to dashboard/login
│   └── generate-password-hash.php  # Password hash generator
├── cron/
│   └── auto-resume-chatbot.php     # Auto-resume cron job
├── LineProfileService.php          # LINE Profile API client
├── ConversationManager.php         # Conversation service layer
├── repository/
│   └── ConversationRepository.php  # Pause/resume database methods
└── line-webhook.php                # Handles pause/resume commands
```

### Database Schema

```sql
ALTER TABLE conversations
ADD COLUMN is_chatbot_active TINYINT(1) NOT NULL DEFAULT 1,
ADD COLUMN paused_at TIMESTAMP NULL DEFAULT NULL,
ADD INDEX idx_chatbot_active (is_chatbot_active);
```

---

## Support

For issues or questions:
- Review this documentation
- Check [PROJECT.md](PROJECT.md) for architecture details
- Review LINE Developer documentation
- Check error logs

---

**Version:** 1.0.0
**Last Updated:** January 29, 2026
**Sirichai Electric Chatbot Team**
