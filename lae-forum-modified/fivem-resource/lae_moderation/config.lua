--[[
    LAE Moderation - Configuration
    
    IMPORTANT: Keep your API key secret! Never share this file publicly.
]]

Config = {}

-- API Configuration
Config.API = {
    -- Your forum URL (no trailing slash)
    BaseURL = "https://laexperiencefivem.com",
    
    -- Your FiveM Moderation API key from Admin > Site Settings > FiveM Moderation API
    -- Generate this key in the admin panel, then copy it here
    ApiKey = "YOUR_API_KEY_HERE",
    
    -- API endpoint path
    Endpoint = "/api/moderation.php",
}

-- Ban System Settings
Config.Bans = {
    -- Check bans when player connects
    CheckOnConnect = true,
    
    -- Sync all active bans from website on server start
    SyncOnStart = true,
    
    -- How often to re-sync bans (in minutes, 0 to disable)
    SyncInterval = 30,
    
    -- Kick message shown to banned players
    KickMessage = [[
=====================================
     LOS ANGELES EXPERIENCE
=====================================

You have been banned from this server.

Ban ID: %s
Reason: %s
Expires: %s

If you believe this is a mistake, you may
appeal at: https://laexperiencefivem.com/appeal.php

=====================================
]],
}

-- Staff Settings
Config.Staff = {
    -- ACE permission required to use moderation commands
    -- Players with this ACE can use /ban, /kick, /warn commands
    AcePermission = "lae.moderator",
    
    -- Higher permission for permanent bans
    AdminAcePermission = "lae.admin",
}

-- Logging
Config.Logging = {
    -- Print debug messages to server console
    Debug = false,
    
    -- Log all moderation actions
    LogActions = true,
}

-- Discord Webhook (optional, for server-side logging)
-- Leave empty to disable, or use your mod-log webhook
Config.DiscordWebhook = ""
