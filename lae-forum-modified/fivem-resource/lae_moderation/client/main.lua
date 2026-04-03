--[[
    LAE Moderation - Client Script
    
    Handles:
    - Warning display to players
    - Staff notifications
]]

-- ============================================
-- Warning Display
-- ============================================

RegisterNetEvent('lae:showWarning', function(reason, warnId, staffName)
    -- Display warning notification
    SendNUIMessage({
        type = 'warning',
        reason = reason,
        id = warnId,
        staff = staffName,
    })
    
    -- Also show native notification
    BeginTextCommandThefeedPost("STRING")
    AddTextComponentSubstringPlayerName("~r~WARNING RECEIVED~s~\n" .. reason:sub(1, 99))
    EndTextCommandThefeedPostTicker(true, true)
    
    -- Play sound
    PlaySoundFrontend(-1, "CHECKPOINT_NORMAL", "HUD_MINI_GAME_SOUNDSET", true)
end)

-- ============================================
-- Staff Notifications
-- ============================================

RegisterNetEvent('lae:notify', function(message, isError)
    local color = isError and "~r~" or "~g~"
    
    BeginTextCommandThefeedPost("STRING")
    AddTextComponentSubstringPlayerName(color .. "[LAE] ~s~" .. message)
    EndTextCommandThefeedPostTicker(false, true)
    
    if isError then
        PlaySoundFrontend(-1, "ERROR", "HUD_FRONTEND_DEFAULT_SOUNDSET", true)
    else
        PlaySoundFrontend(-1, "SELECT", "HUD_FRONTEND_DEFAULT_SOUNDSET", true)
    end
end)

-- ============================================
-- Warning Acknowledgment UI (Optional NUI)
-- ============================================

-- If you want a full-screen warning popup, you can add NUI here
-- For now, we just use native notifications

print("[LAE Moderation] Client script loaded")
