fx_version 'cerulean'
game 'gta5'

name 'LAE Moderation'
description 'Los Angeles Experience - Integrated Moderation System'
author 'LAE Development'
version '1.0.0'

-- Server scripts only (ban checks, API calls)
server_scripts {
    'config.lua',
    'server/main.lua',
    'server/commands.lua',
}

-- Client scripts (optional notifications)
client_scripts {
    'client/main.lua',
}

-- Dependencies
dependencies {
    '/server:5848', -- Requires server build 5848+
}
