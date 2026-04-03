# Discord OAuth + Role Assignment — Setup Guide

## What this does

When a user connects their Discord account on the Settings page:
1. They are redirected to Discord to authorise your app
2. Discord sends back their Discord ID
3. The forum saves their Discord ID and marks them as verified
4. The bot **automatically adds them to your Discord server** (if they aren't already in it)
5. The bot **assigns the Verified role** to them instantly

---

## Step 1 — Create a Discord Application

1. Go to https://discord.com/developers/applications
2. Click **New Application** → name it `LAE Forums` → Create
3. Go to the **OAuth2** tab in the left sidebar
4. Under **Redirects**, click **Add Redirect** and enter:
   ```
   https://laexperiencefivem.com/discord-callback.php
   ```
5. Click **Save Changes**
6. Copy the **Client ID** and **Client Secret** — you'll need these

---

## Step 2 — Create a Discord Bot

1. In the same application, click **Bot** in the left sidebar
2. Click **Add Bot** → Yes, do it!
3. Under **Token**, click **Reset Token** and copy the token
4. Scroll down to **Privileged Gateway Intents** and enable:
   - ✅ **Server Members Intent**
5. Click **Save Changes**

---

## Step 3 — Invite the Bot to Your Server

1. Go to **OAuth2 → URL Generator** in the left sidebar
2. Under **Scopes**, tick: `bot`
3. Under **Bot Permissions**, tick:
   - `Manage Roles`
   - `Create Instant Invite` (optional, for auto-join)
4. Copy the generated URL and open it in your browser
5. Select your LAE Discord server and authorise

> ⚠️ **Important:** The bot's role must be **above** the Verified role in your server's role list, or it won't be able to assign it. Go to Server Settings → Roles and drag the bot's role above "Verified".

---

## Step 4 — Get Your Server and Role IDs

**Enable Developer Mode in Discord:**
- Discord Settings → Advanced → Enable Developer Mode

**Get Server ID:**
- Right-click your server name → **Copy Server ID**

**Get Verified Role ID:**
- Go to Server Settings → Roles → right-click the Verified role → **Copy Role ID**

---

## Step 5 — Update config.php

Open `includes/config.php` and fill in:

```php
define('DISCORD_ENABLED',       true);
define('DISCORD_CLIENT_ID',     '1234567890123456789');   // From Step 1
define('DISCORD_CLIENT_SECRET', 'abcdefg...');            // From Step 1
define('DISCORD_BOT_TOKEN',     'MTIz...');               // From Step 2
define('DISCORD_GUILD_ID',      '9876543210987654321');   // From Step 4 (Server ID)
define('DISCORD_VERIFIED_ROLE', '1122334455667788990');   // From Step 4 (Role ID)
```

---

## Step 6 — Test It

1. Log into your forum with a test account
2. Go to **Settings** → you'll see the **Discord Account** card
3. Click **Connect with Discord**
4. Authorise the app
5. You should be redirected back with a success message
6. Check your Discord server — the test account should have the Verified role

---

## Troubleshooting

| Problem | Fix |
|---|---|
| "Role could not be auto-assigned" | Bot role is below Verified in role hierarchy — drag it above |
| "Failed to exchange OAuth code" | Check Client ID/Secret are correct, redirect URI matches exactly |
| User not added to server | They need to be in the server already if `guilds.join` scope isn't approved |
| 403 from Discord API | Bot doesn't have Manage Roles permission |

---

## How it looks to users

- **Settings page** shows a purple **Connect with Discord** button
- After linking: shows their Discord ID, a green "Linked" badge, and a Re-link/Unlink option
- Their forum profile shows "Discord linked" indicator
