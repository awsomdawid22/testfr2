<?php
// ============================================================
// LAE Moderation System — Core Functions
// ============================================================

/**
 * Generate next infraction ID like LAE-0042
 */
function generateInfractionId(): string {
    $db = getDB();
    $db->exec("UPDATE infraction_seq SET id = LAST_INSERT_ID(id + 1)");
    $seq = (int)$db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    return INFRACTION_ID_PREFIX . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Parse duration string into a DateTime or null (permanent)
 * e.g. "7d" → +7 days, "24h" → +24 hours, "perm" → null
 */
function parseDuration(string $dur): ?string {
    $dur = strtolower(trim($dur));
    if (!$dur || $dur === 'perm' || $dur === 'permanent') return null;
    $map = [
        'h' => 'hours',
        'd' => 'days',
        'w' => 'weeks',
        'm' => 'months',
    ];
    if (preg_match('/^(\d+)([hdwm])$/', $dur, $m)) {
        return date('Y-m-d H:i:s', strtotime("+{$m[1]} {$map[$m[2]]}"));
    }
    // Try plain number as days
    if (is_numeric($dur)) {
        return date('Y-m-d H:i:s', strtotime("+{$dur} days"));
    }
    return null;
}

/**
 * Format duration for display
 */
function formatDuration(?string $dur): string {
    if (!$dur) return 'Permanent';
    return $dur;
}

/**
 * Create an infraction (ban/kick/warn/note)
 * Returns the infraction array on success or throws on failure.
 */
function createInfraction(
    string $type,
    string $playerName,
    string $reason,
    ?string $playerIdentifier,
    ?string $playerDiscordId,
    ?string $duration,        // raw string like "7d", "perm", null
    int $issuedById,
    string $issuedByName,
    string $issuedVia = 'website',
    ?string $notes = null
): array {
    $db           = getDB();
    $infractionId = generateInfractionId();
    $expiresAt    = $duration ? parseDuration($duration) : null;

    $db->prepare("
        INSERT INTO infractions
            (infraction_id, type, player_name, player_identifier, player_discord_id,
             reason, duration, expires_at, issued_by_id, issued_by_name,
             issued_via, is_active, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)
    ")->execute([
        $infractionId, $type, $playerName, $playerIdentifier, $playerDiscordId,
        $reason, $duration, $expiresAt, $issuedById, $issuedByName,
        $issuedVia, $notes
    ]);

    $id = (int)$db->lastInsertId();
    return getInfractionById($id);
}

/**
 * Get single infraction by DB id
 */
function getInfractionById(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM infractions WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Get single infraction by infraction_id string (LAE-0001)
 */
function getInfractionByCode(string $code): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM infractions WHERE infraction_id = ?");
    $stmt->execute([strtoupper($code)]);
    return $stmt->fetch() ?: null;
}

/**
 * Remove (deactivate) an infraction
 */
function removeInfraction(string $code, string $removedBy, string $removeReason = ''): bool {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE infractions
        SET is_active = 0, removed_by = ?, removed_at = NOW(), remove_reason = ?
        WHERE infraction_id = ? AND is_active = 1
    ");
    $stmt->execute([$removedBy, $removeReason, strtoupper($code)]);
    return $stmt->rowCount() > 0;
}

/**
 * Get all infractions for a player (by name or identifier)
 */
function getPlayerInfractions(string $search, bool $activeOnly = false): array {
    $db = getDB();
    $where = $activeOnly ? " AND is_active = 1" : "";
    $stmt = $db->prepare("
        SELECT * FROM infractions
        WHERE (LOWER(player_name) = LOWER(?) OR player_identifier = ? OR player_discord_id = ?)
        $where
        ORDER BY created_at DESC
    ");
    $stmt->execute([$search, $search, $search]);
    return $stmt->fetchAll();
}

/**
 * Post a Discord embed to the mod-log channel via the MOD bot
 */
function postModLogEmbed(array $infraction): ?string {
    if (!defined('MOD_BOT_ENABLED') || !MOD_BOT_ENABLED) return null;
    if (!defined('MOD_LOG_CHANNEL') || !MOD_LOG_CHANNEL) return null;

    $colors = [
        'ban'  => 0xE63946,  // red
        'kick' => 0xC9A227,  // gold
        'warn' => 0xF4A261,  // orange
        'note' => 0x3D7EBF,  // blue
    ];
    $icons = [
        'ban'  => '🔨',
        'kick' => '👢',
        'warn' => '⚠️',
        'note' => '📝',
    ];
    $type    = $infraction['type'];
    $color   = $colors[$type] ?? 0x888888;
    $icon    = $icons[$type]  ?? '•';
    $expires = $infraction['expires_at']
        ? date('M j Y, g:i A', strtotime($infraction['expires_at'])) . ' UTC'
        : 'Permanent';

    $fields = [
        ['name' => 'Player',    'value' => $infraction['player_name'],    'inline' => true],
        ['name' => 'Action',    'value' => strtoupper($type),             'inline' => true],
        ['name' => 'ID',        'value' => '`' . $infraction['infraction_id'] . '`', 'inline' => true],
        ['name' => 'Reason',    'value' => $infraction['reason'],         'inline' => false],
        ['name' => 'Issued By', 'value' => $infraction['issued_by_name'], 'inline' => true],
        ['name' => 'Via',       'value' => ucfirst($infraction['issued_via']), 'inline' => true],
    ];

    if ($infraction['player_identifier']) {
        $fields[] = ['name' => 'Identifier', 'value' => '`' . $infraction['player_identifier'] . '`', 'inline' => true];
    }
    if ($type === 'ban') {
        $fields[] = ['name' => 'Expires', 'value' => $expires, 'inline' => false];
    }
    if ($infraction['notes']) {
        $fields[] = ['name' => 'Staff Note', 'value' => $infraction['notes'], 'inline' => false];
    }

    $embed = [
        'title'       => "{$icon} " . strtoupper($type) . " — " . $infraction['player_name'],
        'color'       => $color,
        'fields'      => $fields,
        'footer'      => ['text' => 'LAE Moderation System · ' . SITE_URL],
        'timestamp'   => date('c', strtotime($infraction['created_at'])),
    ];

    $payload = json_encode(['embeds' => [$embed]]);
    $ch = curl_init("https://discord.com/api/v10/channels/" . MOD_LOG_CHANNEL . "/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bot ' . MOD_BOT_TOKEN,
            'Content-Type: application/json',
            'User-Agent: LAEForums/1.0',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 201) {
        $data = json_decode($resp, true);
        return $data['id'] ?? null;
    }
    error_log("[Mod] Discord post failed ($code): $resp");
    return null;
}

/**
 * Update discord_msg_id on an infraction after posting
 */
function setInfractionDiscordMsg(int $id, string $msgId): void {
    getDB()->prepare("UPDATE infractions SET discord_msg_id = ? WHERE id = ?")
           ->execute([$msgId, $id]);
}

/**
 * Colour/label helpers
 */
function infractionTypeColor(string $type): string {
    return ['ban'=>'#e63946','kick'=>'#c9a227','warn'=>'#f4a261','note'=>'#3d7ebf'][$type] ?? '#888';
}
function infractionTypeLabel(string $type): string {
    return ['ban'=>'Ban','kick'=>'Kick','warn'=>'Warning','note'=>'Note'][$type] ?? ucfirst($type);
}
function infractionTypeIcon(string $type): string {
    return ['ban'=>'fa-ban','kick'=>'fa-person-walking-arrow-right','warn'=>'fa-triangle-exclamation','note'=>'fa-note-sticky'][$type] ?? 'fa-circle';
}
