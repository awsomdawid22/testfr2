# LAE Moderation - FiveM Resource Setup

This resource integrates your FiveM server with the LAE forum moderation system. Bans, kicks, and warnings issued in-game will appear on the website, and bans from the website will be enforced in-game.

---

## Features

- **Ban Sync**: Bans from the website are automatically enforced in-game
- **In-Game Commands**: `/ban`, `/kick`, `/warn`, `/note`, `/history`, `/unban`
- **Discord Integration**: All actions are logged to your Discord mod-log channel
- **Player History**: Staff can check a player's infraction history in-game
- **Automatic Ban Check**: Players are checked against the ban list on connect

---

## Installation

### Step 1: Copy the Resource

1. Copy the `lae_moderation` folder to your server's `resources` folder
2. Add `ensure lae_moderation` to your `server.cfg`

### Step 2: Configure the API Key

1. Go to your forum's **Admin > Site Settings**
2. Scroll down to the **FiveM Moderation API** section
3. Click **Generate API Key** to create a new key
4. Copy the generated key (starts with `lae_`)
5. Open `lae_moderation/config.lua` and paste it:

```lua
Config.API = {
    BaseURL = "https://laexperiencefivem.com",
    ApiKey = "lae_your_generated_key_here",  -- Paste your key here
    Endpoint = "/api/moderation.php",
}
```

### Step 3: Set Up Permissions

Add these ACE permissions to your `server.cfg`:

```cfg
# Moderator permissions (kick, warn, note, history)
add_ace group.moderator lae.moderator allow

# Admin permissions (ban, unban, permanent bans)
add_ace group.admin lae.moderator allow
add_ace group.admin lae.admin allow

# Grant to specific players
add_principal identifier.steam:110000123456789 group.admin
add_principal identifier.discord:123456789012345678 group.moderator
```

---

## Commands

| Command | Permission | Description |
|---------|------------|-------------|
| `/ban <id> <duration> <reason>` | lae.moderator | Ban a player |
| `/kick <id> <reason>` | lae.moderator | Kick a player |
| `/warn <id> <reason>` | lae.moderator | Warn a player |
| `/note <id> <note>` | lae.moderator | Add a staff note |
| `/history <id>` | lae.moderator | View player history |
| `/unban <ban_id> [reason]` | lae.admin | Remove a ban |

### Duration Formats

- `1h` - 1 hour
- `12h` - 12 hours
- `1d` - 1 day
- `7d` - 7 days
- `30d` - 30 days
- `perm` - Permanent (requires admin permission)

### Examples

```
/ban 1 7d RDM in multiple scenarios
/kick 3 AFK for extended period
/warn 5 Please read the rules regarding RP standards
/note 2 Possible alt account, monitor closely
/history 1
/unban LAE-0042 Appeal approved
```

---

## Exports (For Developers)

Use these exports in your other resources:

```lua
-- Check if a player is banned
local isBanned = exports['lae_moderation']:IsPlayerBanned(source)

-- Get player's full history
local history = exports['lae_moderation']:GetPlayerHistory(source)

-- Create an infraction programmatically
exports['lae_moderation']:CreateInfraction(
    "warn",           -- type: ban, kick, warn, note
    source,           -- player source
    "Reason here",    -- reason
    "7d",             -- duration (nil for non-bans)
    "Anti-Cheat"      -- staff name
)

-- Force sync bans from website
exports['lae_moderation']:SyncBans()
```

---

## API Endpoints

The resource communicates with these API endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?action=check_ban` | GET | Check if a player is banned |
| `?action=active_bans` | GET | Get all active bans |
| `?action=player_history` | GET | Get player's infraction history |
| `?action=create_infraction` | POST | Create a new infraction |
| `?action=remove_ban` | POST | Remove/revoke a ban |

All requests require the `X-API-Key` header with your API key (generated in Admin > Site Settings).

---

## Troubleshooting

### "Invalid API key" error
- Go to Admin > Site Settings > FiveM Moderation API and generate a key if you haven't
- Make sure the `ApiKey` in config.lua matches the generated key exactly
- Check that your forum URL is correct (no trailing slash)

### Bans not syncing
- Run `lae_sync` in the server console to force a sync
- Check that `Config.Bans.SyncOnStart` is `true`

### Commands not working
- Verify your ACE permissions are set up correctly
- Check that you have the correct permission (`lae.moderator` or `lae.admin`)

### Connection timeout
- Ensure your forum server is accessible from your FiveM server
- Check firewall rules if hosted separately

---

## Console Commands

| Command | Description |
|---------|-------------|
| `lae_sync` | Force sync bans from website (console only) |

---

## Support

For issues with this resource, visit the LAE forums or contact the development team.
