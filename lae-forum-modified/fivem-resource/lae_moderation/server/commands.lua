--[[
    LAE Moderation - Staff Commands
    
    Commands:
    /ban <id> <duration> <reason> - Ban a player
    /kick <id> <reason> - Kick a player
    /warn <id> <reason> - Warn a player
    /history <id> - Check player history
    /unban <ban_id> <reason> - Remove a ban
]]

-- ============================================
-- Helper Functions
-- ============================================

local function HasPermission(source, permission)
    if source == 0 then return true end -- Console always has permission
    return IsPlayerAceAllowed(source, permission)
end

local function GetTargetPlayer(idOrName)
    local id = tonumber(idOrName)
    if id then
        if GetPlayerName(id) then
            return id
        end
    end
    
    -- Search by name
    for _, playerId in ipairs(GetPlayers()) do
        if GetPlayerName(playerId):lower():find(idOrName:lower()) then
            return tonumber(playerId)
        end
    end
    
    return nil
end

local function GetPlayerIdentifiers(source)
    local identifiers = {}
    for i = 0, GetNumPlayerIdentifiers(source) - 1 do
        local id = GetPlayerIdentifier(source, i)
        if id then
            local prefix = id:match("^(%w+):")
            if prefix then
                identifiers[prefix] = id
            end
        end
    end
    return identifiers
end

local function NotifyPlayer(source, message, isError)
    if source == 0 then
        print(message)
    else
        TriggerClientEvent('lae:notify', source, message, isError)
    end
end

local function LogAction(staffSource, action, targetName, reason)
    local staffName = staffSource == 0 and "Console" or GetPlayerName(staffSource)
    print(("[LAE] %s: %s %s - %s"):format(action:upper(), staffName, targetName, reason))
end

local function MakeAPIRequest(action, params, method, body)
    local url = Config.API.BaseURL .. Config.API.Endpoint .. "?action=" .. action
    
    if params then
        for k, v in pairs(params) do
            url = url .. "&" .. k .. "=" .. (v or "")
        end
    end
    
    local headers = {
        ["Content-Type"] = "application/json",
        ["X-API-Key"] = Config.API.SecretKey,
    }
    
    local p = promise.new()
    
    if method == "POST" then
        PerformHttpRequest(url, function(code, data)
            if code == 200 or code == 201 then
                p:resolve(json.decode(data))
            else
                p:resolve(nil)
            end
        end, "POST", json.encode(body), headers)
    else
        PerformHttpRequest(url, function(code, data)
            if code == 200 then
                p:resolve(json.decode(data))
            else
                p:resolve(nil)
            end
        end, "GET", "", headers)
    end
    
    return Citizen.Await(p)
end

-- ============================================
-- Ban Command
-- ============================================

RegisterCommand('ban', function(source, args, rawCommand)
    if not HasPermission(source, Config.Staff.AcePermission) then
        NotifyPlayer(source, "You don't have permission to use this command.", true)
        return
    end
    
    if #args < 3 then
        NotifyPlayer(source, "Usage: /ban <player_id> <duration> <reason>", true)
        NotifyPlayer(source, "Duration: 1h, 12h, 1d, 7d, 30d, perm", false)
        return
    end
    
    local targetId = GetTargetPlayer(args[1])
    if not targetId then
        NotifyPlayer(source, "Player not found.", true)
        return
    end
    
    local duration = args[2]:lower()
    local reason = table.concat(args, " ", 3)
    
    -- Check if perm ban requires admin permission
    if (duration == "perm" or duration == "permanent") and 
       not HasPermission(source, Config.Staff.AdminAcePermission) then
        NotifyPlayer(source, "You need admin permission for permanent bans.", true)
        return
    end
    
    local targetName = GetPlayerName(targetId)
    local identifiers = GetPlayerIdentifiers(targetId)
    local staffName = source == 0 and "Console" or GetPlayerName(source)
    
    -- Create infraction via API
    local result = MakeAPIRequest("create_infraction", nil, "POST", {
        type = "ban",
        player_name = targetName,
        reason = reason,
        identifier = identifiers.steam or identifiers.license,
        discord_id = identifiers.discord and identifiers.discord:gsub("discord:", "") or nil,
        duration = duration,
        staff_name = staffName,
        staff_id = 0,
    })
    
    if result and result.success then
        local banId = result.infraction.id
        local expires = result.infraction.permanent and "Permanent" or result.infraction.expires_at
        
        -- Kick the player
        DropPlayer(targetId, ([[
=====================================
     LOS ANGELES EXPERIENCE
=====================================

You have been banned from this server.

Ban ID: %s
Reason: %s
Expires: %s

Appeal at: https://laexperiencefivem.com/appeal.php
=====================================
]]):format(banId, reason, expires))
        
        NotifyPlayer(source, ("Banned %s [%s] - %s"):format(targetName, banId, reason), false)
        LogAction(source, "BAN", targetName, reason)
        
        -- Notify all staff online
        for _, playerId in ipairs(GetPlayers()) do
            if HasPermission(playerId, Config.Staff.AcePermission) and playerId ~= source then
                TriggerClientEvent('lae:notify', playerId, 
                    ("%s banned %s: %s"):format(staffName, targetName, reason), false)
            end
        end
    else
        NotifyPlayer(source, "Failed to create ban. Check server console.", true)
    end
end, false)

-- ============================================
-- Kick Command
-- ============================================

RegisterCommand('kick', function(source, args, rawCommand)
    if not HasPermission(source, Config.Staff.AcePermission) then
        NotifyPlayer(source, "You don't have permission to use this command.", true)
        return
    end
    
    if #args < 2 then
        NotifyPlayer(source, "Usage: /kick <player_id> <reason>", true)
        return
    end
    
    local targetId = GetTargetPlayer(args[1])
    if not targetId then
        NotifyPlayer(source, "Player not found.", true)
        return
    end
    
    local reason = table.concat(args, " ", 2)
    local targetName = GetPlayerName(targetId)
    local identifiers = GetPlayerIdentifiers(targetId)
    local staffName = source == 0 and "Console" or GetPlayerName(source)
    
    -- Create infraction via API
    local result = MakeAPIRequest("create_infraction", nil, "POST", {
        type = "kick",
        player_name = targetName,
        reason = reason,
        identifier = identifiers.steam or identifiers.license,
        discord_id = identifiers.discord and identifiers.discord:gsub("discord:", "") or nil,
        duration = nil,
        staff_name = staffName,
        staff_id = 0,
    })
    
    if result and result.success then
        -- Kick the player
        DropPlayer(targetId, ([[
=====================================
     LOS ANGELES EXPERIENCE
=====================================

You have been kicked from this server.

Reason: %s

You may rejoin immediately.
=====================================
]]):format(reason))
        
        NotifyPlayer(source, ("Kicked %s - %s"):format(targetName, reason), false)
        LogAction(source, "KICK", targetName, reason)
    else
        -- Still kick even if API fails
        DropPlayer(targetId, "You have been kicked: " .. reason)
        NotifyPlayer(source, ("Kicked %s (API log failed) - %s"):format(targetName, reason), false)
    end
end, false)

-- ============================================
-- Warn Command
-- ============================================

RegisterCommand('warn', function(source, args, rawCommand)
    if not HasPermission(source, Config.Staff.AcePermission) then
        NotifyPlayer(source, "You don't have permission to use this command.", true)
        return
    end
    
    if #args < 2 then
        NotifyPlayer(source, "Usage: /warn <player_id> <reason>", true)
        return
    end
    
    local targetId = GetTargetPlayer(args[1])
    if not targetId then
        NotifyPlayer(source, "Player not found.", true)
        return
    end
    
    local reason = table.concat(args, " ", 2)
    local targetName = GetPlayerName(targetId)
    local identifiers = GetPlayerIdentifiers(targetId)
    local staffName = source == 0 and "Console" or GetPlayerName(source)
    
    -- Create infraction via API
    local result = MakeAPIRequest("create_infraction", nil, "POST", {
        type = "warn",
        player_name = targetName,
        reason = reason,
        identifier = identifiers.steam or identifiers.license,
        discord_id = identifiers.discord and identifiers.discord:gsub("discord:", "") or nil,
        duration = nil,
        staff_name = staffName,
        staff_id = 0,
    })
    
    if result and result.success then
        local warnId = result.infraction.id
        
        -- Notify the warned player
        TriggerClientEvent('lae:showWarning', targetId, reason, warnId, staffName)
        
        NotifyPlayer(source, ("Warned %s [%s] - %s"):format(targetName, warnId, reason), false)
        LogAction(source, "WARN", targetName, reason)
    else
        NotifyPlayer(source, "Failed to create warning. Check server console.", true)
    end
end, false)

-- ============================================
-- History Command
-- ============================================

RegisterCommand('history', function(source, args, rawCommand)
    if not HasPermission(source, Config.Staff.AcePermission) then
        NotifyPlayer(source, "You don't have permission to use this command.", true)
        return
    end
    
    if #args < 1 then
        NotifyPlayer(source, "Usage: /history <player_id>", true)
        return
    end
    
    local targetId = GetTargetPlayer(args[1])
    if not targetId then
        NotifyPlayer(source, "Player not found.", true)
        return
    end
    
    local targetName = GetPlayerName(targetId)
    local identifiers = GetPlayerIdentifiers(targetId)
    
    NotifyPlayer(source, ("Fetching history for %s..."):format(targetName), false)
    
    local result = MakeAPIRequest("player_history", {
        identifier = identifiers.steam or identifiers.license,
        discord = identifiers.discord and identifiers.discord:gsub("discord:", "") or nil,
    })
    
    if result and result.success then
        local s = result.summary
        NotifyPlayer(source, "----------------------------", false)
        NotifyPlayer(source, ("History for %s"):format(targetName), false)
        NotifyPlayer(source, ("Total: %d | Bans: %d | Kicks: %d | Warns: %d"):format(
            s.total, s.bans, s.kicks, s.warns), false)
        NotifyPlayer(source, ("Active Bans: %d"):format(s.active_bans), false)
        
        -- Show last 5 infractions
        if result.history and #result.history > 0 then
            NotifyPlayer(source, "Recent infractions:", false)
            for i = 1, math.min(5, #result.history) do
                local inf = result.history[i]
                NotifyPlayer(source, ("  [%s] %s - %s"):format(
                    inf.infraction_id, inf.type:upper(), inf.reason:sub(1, 50)), false)
            end
        end
        NotifyPlayer(source, "----------------------------", false)
    else
        NotifyPlayer(source, "Failed to fetch history.", true)
    end
end, false)

-- ============================================
-- Unban Command
-- ============================================

RegisterCommand('unban', function(source, args, rawCommand)
    if not HasPermission(source, Config.Staff.AdminAcePermission) then
        NotifyPlayer(source, "You need admin permission to unban.", true)
        return
    end
    
    if #args < 1 then
        NotifyPlayer(source, "Usage: /unban <ban_id> [reason]", true)
        NotifyPlayer(source, "Example: /unban LAE-0042 Appeal accepted", false)
        return
    end
    
    local banId = args[1]:upper()
    local reason = #args > 1 and table.concat(args, " ", 2) or "No reason provided"
    local staffName = source == 0 and "Console" or GetPlayerName(source)
    
    local result = MakeAPIRequest("remove_ban", nil, "POST", {
        infraction_id = banId,
        removed_by = staffName,
        reason = reason,
    })
    
    if result and result.success then
        NotifyPlayer(source, ("Removed ban %s - %s"):format(banId, reason), false)
        LogAction(source, "UNBAN", banId, reason)
        
        -- Sync bans to update cache
        exports['lae_moderation']:SyncBans()
    else
        NotifyPlayer(source, result and result.error or "Failed to remove ban.", true)
    end
end, false)

-- ============================================
-- Staff Note Command
-- ============================================

RegisterCommand('note', function(source, args, rawCommand)
    if not HasPermission(source, Config.Staff.AcePermission) then
        NotifyPlayer(source, "You don't have permission to use this command.", true)
        return
    end
    
    if #args < 2 then
        NotifyPlayer(source, "Usage: /note <player_id> <note>", true)
        return
    end
    
    local targetId = GetTargetPlayer(args[1])
    if not targetId then
        NotifyPlayer(source, "Player not found.", true)
        return
    end
    
    local note = table.concat(args, " ", 2)
    local targetName = GetPlayerName(targetId)
    local identifiers = GetPlayerIdentifiers(targetId)
    local staffName = source == 0 and "Console" or GetPlayerName(source)
    
    local result = MakeAPIRequest("create_infraction", nil, "POST", {
        type = "note",
        player_name = targetName,
        reason = note,
        identifier = identifiers.steam or identifiers.license,
        discord_id = identifiers.discord and identifiers.discord:gsub("discord:", "") or nil,
        duration = nil,
        staff_name = staffName,
        staff_id = 0,
    })
    
    if result and result.success then
        NotifyPlayer(source, ("Added note for %s [%s]"):format(targetName, result.infraction.id), false)
        LogAction(source, "NOTE", targetName, note)
    else
        NotifyPlayer(source, "Failed to add note.", true)
    end
end, false)

print("[LAE Moderation] Commands loaded")
