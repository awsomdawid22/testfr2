--[[
    LAE Moderation - Server Main Script
    
    Handles:
    - Player connection ban checks
    - API communication with the LAE website
    - Ban synchronization
]]

local BanCache = {} -- Local cache of active bans

-- ============================================
-- API Helper Functions
-- ============================================

local function MakeAPIRequest(action, params, method, body)
    local url = Config.API.BaseURL .. Config.API.Endpoint .. "?action=" .. action
    
    -- Add params to URL for GET requests
    if params then
        for k, v in pairs(params) do
            url = url .. "&" .. k .. "=" .. (v or "")
        end
    end
    
    local headers = {
        ["Content-Type"] = "application/json",
        ["X-API-Key"] = Config.API.ApiKey,
    }
    
    local p = promise.new()
    
    if method == "POST" then
        PerformHttpRequest(url, function(code, data, resultHeaders)
            if code == 200 or code == 201 then
                local decoded = json.decode(data)
                p:resolve(decoded)
            else
                if Config.Logging.Debug then
                    print(("[LAE] API Error %d: %s"):format(code, data or "No response"))
                end
                p:resolve(nil)
            end
        end, "POST", json.encode(body), headers)
    else
        PerformHttpRequest(url, function(code, data, resultHeaders)
            if code == 200 then
                local decoded = json.decode(data)
                p:resolve(decoded)
            else
                if Config.Logging.Debug then
                    print(("[LAE] API Error %d: %s"):format(code, data or "No response"))
                end
                p:resolve(nil)
            end
        end, "GET", "", headers)
    end
    
    return Citizen.Await(p)
end

local function DebugLog(msg)
    if Config.Logging.Debug then
        print("[LAE Debug] " .. msg)
    end
end

-- ============================================
-- Identifier Helpers
-- ============================================

local function GetPlayerIdentifiers(source)
    local identifiers = {
        steam = nil,
        license = nil,
        discord = nil,
        xbl = nil,
        live = nil,
        fivem = nil,
        ip = nil,
    }
    
    for i = 0, GetNumPlayerIdentifiers(source) - 1 do
        local id = GetPlayerIdentifier(source, i)
        if id then
            local prefix = id:match("^(%w+):")
            if prefix and identifiers[prefix] ~= nil then
                identifiers[prefix] = id
            end
        end
    end
    
    return identifiers
end

local function GetPrimaryIdentifier(source)
    local ids = GetPlayerIdentifiers(source)
    -- Prefer steam, then license, then discord
    return ids.steam or ids.license or ids.fivem or ids.discord
end

local function GetDiscordId(source)
    local ids = GetPlayerIdentifiers(source)
    if ids.discord then
        return ids.discord:gsub("discord:", "")
    end
    return nil
end

-- ============================================
-- Ban Checking
-- ============================================

local function CheckPlayerBan(source, identifier, discordId, playerName)
    DebugLog(("Checking ban for %s (ID: %s, Discord: %s)"):format(playerName, identifier or "none", discordId or "none"))
    
    -- First check local cache
    if identifier and BanCache[identifier] then
        local cachedBan = BanCache[identifier]
        -- Check if still valid
        if not cachedBan.expires_at or os.time() < cachedBan.expires_timestamp then
            return cachedBan
        else
            BanCache[identifier] = nil
        end
    end
    
    -- Query API
    local result = MakeAPIRequest("check_ban", {
        identifier = identifier,
        discord = discordId,
        name = playerName,
    })
    
    if result and result.banned then
        local ban = result.ban
        -- Cache it
        if identifier then
            BanCache[identifier] = {
                id = ban.id,
                reason = ban.reason,
                issued_by = ban.issued_by,
                expires_at = ban.expires_at,
                expires_timestamp = ban.expires_at and (os.time() + 86400) or nil, -- Cache for 1 day max
                permanent = ban.permanent,
            }
        end
        return result.ban
    end
    
    return nil
end

local function FormatBanMessage(ban)
    local expires = ban.permanent and "Never (Permanent)" or ban.expires_at
    return Config.Bans.KickMessage:format(
        ban.id or "Unknown",
        ban.reason or "No reason provided",
        expires
    )
end

-- ============================================
-- Player Connection Handler
-- ============================================

AddEventHandler('playerConnecting', function(name, setKickReason, deferrals)
    local source = source
    
    if not Config.Bans.CheckOnConnect then
        return
    end
    
    deferrals.defer()
    
    -- Brief wait for identifiers to load
    Wait(100)
    
    deferrals.update("Checking ban status...")
    
    local identifier = GetPrimaryIdentifier(source)
    local discordId = GetDiscordId(source)
    
    if not identifier then
        deferrals.update("Unable to verify identity...")
        Wait(1000)
        deferrals.done()
        return
    end
    
    local ban = CheckPlayerBan(source, identifier, discordId, name)
    
    if ban then
        DebugLog(("Player %s is banned: %s"):format(name, ban.id))
        deferrals.done(FormatBanMessage(ban))
        return
    end
    
    deferrals.done()
end)

-- ============================================
-- Ban Synchronization
-- ============================================

local function SyncBansFromWebsite()
    DebugLog("Syncing bans from website...")
    
    local result = MakeAPIRequest("active_bans", nil)
    
    if result and result.success then
        BanCache = {}
        
        for _, ban in ipairs(result.bans) do
            local identifier = ban.player_identifier
            if identifier then
                BanCache[identifier] = {
                    id = ban.infraction_id,
                    reason = ban.reason,
                    issued_by = ban.issued_by_name,
                    expires_at = ban.expires_at,
                    expires_timestamp = ban.expires_at and (os.time() + 86400) or nil,
                    permanent = ban.expires_at == nil,
                }
            end
        end
        
        print(("[LAE] Synced %d active bans from website"):format(result.count))
        return true
    else
        print("[LAE] Failed to sync bans from website")
        return false
    end
end

-- Sync on resource start
if Config.Bans.SyncOnStart then
    CreateThread(function()
        Wait(5000) -- Wait for server to fully start
        SyncBansFromWebsite()
    end)
end

-- Periodic sync
if Config.Bans.SyncInterval > 0 then
    CreateThread(function()
        while true do
            Wait(Config.Bans.SyncInterval * 60 * 1000)
            SyncBansFromWebsite()
        end
    end)
end

-- ============================================
-- Exports
-- ============================================

-- Check if a player is banned (for other resources)
exports('IsPlayerBanned', function(source)
    local identifier = GetPrimaryIdentifier(source)
    local discordId = GetDiscordId(source)
    local name = GetPlayerName(source) or "Unknown"
    return CheckPlayerBan(source, identifier, discordId, name) ~= nil
end)

-- Get player's infraction history
exports('GetPlayerHistory', function(source)
    local identifier = GetPrimaryIdentifier(source)
    local discordId = GetDiscordId(source)
    
    return MakeAPIRequest("player_history", {
        identifier = identifier,
        discord = discordId,
    })
end)

-- Force sync bans
exports('SyncBans', function()
    return SyncBansFromWebsite()
end)

-- Create infraction from other resources
exports('CreateInfraction', function(type, playerSource, reason, duration, staffName)
    local identifier = GetPrimaryIdentifier(playerSource)
    local discordId = GetDiscordId(playerSource)
    local playerName = GetPlayerName(playerSource) or "Unknown"
    
    return MakeAPIRequest("create_infraction", nil, "POST", {
        type = type,
        player_name = playerName,
        reason = reason,
        identifier = identifier,
        discord_id = discordId,
        duration = duration,
        staff_name = staffName or "FiveM Server",
        staff_id = 0,
    })
end)

-- Console command to force sync
RegisterCommand("lae_sync", function(source)
    if source ~= 0 then
        print("This command can only be run from the server console")
        return
    end
    SyncBansFromWebsite()
end, true)

print("[LAE Moderation] Server script loaded")
