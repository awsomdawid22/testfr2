<?php
/**
 * LAE FiveM Moderation API
 * 
 * This API allows the FiveM server to:
 * - Check if a player is banned
 * - Submit new infractions (bans, kicks, warns)
 * - Query player history
 * 
 * Authentication: Uses the fivem_secret key from settings
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/moderation.php';

// Load site settings
$siteSettings = include __DIR__ . '/../includes/settings.php';
$API_SECRET = $siteSettings['fivem_secret'] ?? '';

// Validate API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if (empty($API_SECRET) || $apiKey !== $API_SECRET) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * GET /api/moderation.php?action=check_ban&identifier=steam:xxxx
 * Check if a player is banned
 */
if ($action === 'check_ban') {
    $identifier = $_GET['identifier'] ?? '';
    $discordId = $_GET['discord'] ?? '';
    $playerName = $_GET['name'] ?? '';
    
    if (!$identifier && !$discordId) {
        echo json_encode(['success' => false, 'error' => 'Missing identifier']);
        exit;
    }
    
    // Check for active bans
    $where = [];
    $params = [];
    
    if ($identifier) {
        $where[] = "player_identifier = ?";
        $params[] = $identifier;
    }
    if ($discordId) {
        $where[] = "player_discord_id = ?";
        $params[] = $discordId;
    }
    
    $whereClause = implode(' OR ', $where);
    $stmt = $db->prepare("
        SELECT * FROM infractions 
        WHERE ($whereClause) 
          AND type = 'ban' 
          AND is_active = 1 
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute($params);
    $ban = $stmt->fetch();
    
    if ($ban) {
        echo json_encode([
            'success' => true,
            'banned' => true,
            'ban' => [
                'id' => $ban['infraction_id'],
                'reason' => $ban['reason'],
                'issued_by' => $ban['issued_by_name'],
                'issued_at' => $ban['created_at'],
                'expires_at' => $ban['expires_at'],
                'permanent' => $ban['expires_at'] === null,
            ]
        ]);
    } else {
        echo json_encode(['success' => true, 'banned' => false]);
    }
    exit;
}

/**
 * GET /api/moderation.php?action=player_history&identifier=steam:xxxx
 * Get player infraction history
 */
if ($action === 'player_history') {
    $identifier = $_GET['identifier'] ?? '';
    $discordId = $_GET['discord'] ?? '';
    
    if (!$identifier && !$discordId) {
        echo json_encode(['success' => false, 'error' => 'Missing identifier']);
        exit;
    }
    
    $where = [];
    $params = [];
    
    if ($identifier) {
        $where[] = "player_identifier = ?";
        $params[] = $identifier;
    }
    if ($discordId) {
        $where[] = "player_discord_id = ?";
        $params[] = $discordId;
    }
    
    $whereClause = implode(' OR ', $where);
    $stmt = $db->prepare("
        SELECT infraction_id, type, reason, issued_by_name, created_at, 
               expires_at, is_active, removed_by, removed_at
        FROM infractions 
        WHERE ($whereClause)
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $history = $stmt->fetchAll();
    
    // Summary counts
    $summary = [
        'total' => count($history),
        'bans' => 0,
        'kicks' => 0,
        'warns' => 0,
        'notes' => 0,
        'active_bans' => 0,
    ];
    
    foreach ($history as $inf) {
        $summary[$inf['type'] . 's']++;
        if ($inf['type'] === 'ban' && $inf['is_active'] && 
            ($inf['expires_at'] === null || strtotime($inf['expires_at']) > time())) {
            $summary['active_bans']++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'history' => $history
    ]);
    exit;
}

/**
 * POST /api/moderation.php?action=create_infraction
 * Create a new infraction from the FiveM server
 */
if ($action === 'create_infraction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $type = $input['type'] ?? '';
    $playerName = $input['player_name'] ?? '';
    $reason = $input['reason'] ?? '';
    $identifier = $input['identifier'] ?? null;
    $discordId = $input['discord_id'] ?? null;
    $duration = $input['duration'] ?? null; // e.g., "7d", "24h", "perm"
    $staffName = $input['staff_name'] ?? 'FiveM Server';
    $staffId = $input['staff_id'] ?? 0; // Forum user ID if known
    $notes = $input['notes'] ?? null;
    
    // Validate
    if (!in_array($type, ['ban', 'kick', 'warn', 'note'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid type. Must be: ban, kick, warn, or note']);
        exit;
    }
    if (!$playerName || !$reason) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields: player_name, reason']);
        exit;
    }
    
    try {
        $infraction = createInfraction(
            $type,
            $playerName,
            $reason,
            $identifier,
            $discordId,
            $duration,
            (int)$staffId,
            $staffName,
            'fivem', // issued_via
            $notes
        );
        
        // Post to Discord mod log if enabled
        $discordMsgId = postModLogEmbed($infraction);
        if ($discordMsgId) {
            setInfractionDiscordMsg($infraction['id'], $discordMsgId);
        }
        
        echo json_encode([
            'success' => true,
            'infraction' => [
                'id' => $infraction['infraction_id'],
                'type' => $infraction['type'],
                'player' => $infraction['player_name'],
                'reason' => $infraction['reason'],
                'expires_at' => $infraction['expires_at'],
                'permanent' => $infraction['expires_at'] === null,
            ]
        ]);
    } catch (Exception $e) {
        error_log('[ModAPI] Create infraction failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to create infraction']);
    }
    exit;
}

/**
 * POST /api/moderation.php?action=remove_ban
 * Remove/revoke an active ban
 */
if ($action === 'remove_ban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $infractionId = $input['infraction_id'] ?? '';
    $removedBy = $input['removed_by'] ?? 'FiveM Server';
    $reason = $input['reason'] ?? '';
    
    if (!$infractionId) {
        echo json_encode(['success' => false, 'error' => 'Missing infraction_id']);
        exit;
    }
    
    $success = removeInfraction($infractionId, $removedBy, $reason);
    
    if ($success) {
        echo json_encode(['success' => true, 'message' => "Ban $infractionId has been removed"]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ban not found or already removed']);
    }
    exit;
}

/**
 * GET /api/moderation.php?action=active_bans
 * Get all currently active bans (for server startup sync)
 */
if ($action === 'active_bans') {
    $stmt = $db->query("
        SELECT infraction_id, player_name, player_identifier, player_discord_id,
               reason, issued_by_name, created_at, expires_at
        FROM infractions 
        WHERE type = 'ban' 
          AND is_active = 1 
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC
    ");
    $bans = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'count' => count($bans),
        'bans' => $bans
    ]);
    exit;
}

// Unknown action
echo json_encode(['success' => false, 'error' => 'Unknown action']);
