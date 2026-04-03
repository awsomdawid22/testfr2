<?php
// ============================================
// Discord OAuth2 Callback
// https://laexperiencefivem.com/discord-callback.php
// ============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Must start session BEFORE requireLogin so state is accessible on callback
startSecureSession();
requireLogin();
$currentUser = getCurrentUser();
$db = getDB();

// Purge expired OAuth states (> 15 min old)
try {
    $db->exec("DELETE FROM oauth_states WHERE created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
} catch (\Throwable $e) {
    // Table may not exist on older installs — create it silently
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS oauth_states (
            id INT AUTO_INCREMENT PRIMARY KEY,
            state VARCHAR(64) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            provider VARCHAR(20) DEFAULT 'discord',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_state (state)
        )");
    } catch (\Throwable $e2) {}
}

// ── Helper ───────────────────────────────────────────────────────────────────
function discordRequest(string $method, string $endpoint, array $data = [], string $token = '', bool $isBot = false): ?array {
    $url = 'https://discord.com/api/v10' . $endpoint;

    if ($isBot) {
        $headers = ['Authorization: Bot ' . DISCORD_BOT_TOKEN, 'Content-Type: application/json'];
    } elseif ($token) {
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    } else {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'LAEForums/1.0',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            ($isBot || $token) ? json_encode($data) : http_build_query($data)
        );
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data ? json_encode($data) : '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bot ' . DISCORD_BOT_TOKEN,
            'Content-Type: application/json',
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[Discord] cURL error on $method $endpoint: $curlErr");
        return null;
    }
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

function discordRedirect(string $param, string $msg): void {
    header('Location: ' . SITE_URL . '/settings.php?' . $param . '=' . urlencode($msg));
    exit;
}

// ── Step 1: Initiate OAuth flow ──────────────────────────────────────────────
if (isset($_GET['connect'])) {
    if (!defined('DISCORD_ENABLED') || !DISCORD_ENABLED) {
        discordRedirect('discord_err', 'Discord integration is not yet configured. Contact an admin.');
    }

    $state = bin2hex(random_bytes(24));

    // Store state in session (primary) AND database (fallback — handles SameSite=Lax edge cases)
    $_SESSION['discord_oauth_state']   = $state;
    $_SESSION['discord_oauth_user_id'] = $currentUser['id'];

    try {
        $db->prepare("REPLACE INTO oauth_states (state, user_id, provider) VALUES (?,?,'discord')")
           ->execute([$state, $currentUser['id']]);
    } catch (\Throwable $e) {
        error_log("[Discord] Could not save state to DB: " . $e->getMessage());
    }

    $params = http_build_query([
        'client_id'     => DISCORD_CLIENT_ID,
        'redirect_uri'  => DISCORD_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'identify guilds.join',
        'state'         => $state,
        'prompt'        => 'consent',
    ]);

    header('Location: https://discord.com/api/oauth2/authorize?' . $params);
    exit;
}

// ── Step 2: OAuth callback ───────────────────────────────────────────────────
if (isset($_GET['code'])) {
    $returnedState = trim($_GET['state'] ?? '');
    $code          = trim($_GET['code']  ?? '');

    if (!$returnedState || !$code) {
        discordRedirect('discord_err', 'Incomplete response from Discord. Please try again.');
    }

    // Validate state — session first, DB as fallback
    $stateValid = false;

    $sessionState  = $_SESSION['discord_oauth_state']   ?? null;
    $sessionUserId = $_SESSION['discord_oauth_user_id'] ?? null;

    if ($sessionState && hash_equals($sessionState, $returnedState) && (int)$sessionUserId === $currentUser['id']) {
        $stateValid = true;
        unset($_SESSION['discord_oauth_state'], $_SESSION['discord_oauth_user_id']);
    } else {
        // Session cookie may have been blocked on the cross-site redirect — check DB
        try {
            $stmt = $db->prepare("SELECT user_id FROM oauth_states WHERE state = ? AND user_id = ? AND provider = 'discord'");
            $stmt->execute([$returnedState, $currentUser['id']]);
            if ($stmt->fetch()) {
                $stateValid = true;
                $db->prepare("DELETE FROM oauth_states WHERE state = ?")->execute([$returnedState]);
            }
        } catch (\Throwable $e) {
            error_log("[Discord] DB state check failed: " . $e->getMessage());
        }
    }

    if (!$stateValid) {
        discordRedirect('discord_err', 'Security check failed. Please try again. If this keeps happening, clear your browser cookies and retry.');
    }

    if (!defined('DISCORD_ENABLED') || !DISCORD_ENABLED) {
        discordRedirect('discord_err', 'Discord integration is not enabled.');
    }

    // Exchange code for access token
    $tokenResp = discordRequest('POST', '/oauth2/token', [
        'client_id'     => DISCORD_CLIENT_ID,
        'client_secret' => DISCORD_CLIENT_SECRET,
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => DISCORD_REDIRECT_URI,
    ]);

    if (!$tokenResp || $tokenResp['code'] !== 200 || empty($tokenResp['data']['access_token'])) {
        $detail = $tokenResp['data']['error_description'] ?? $tokenResp['data']['error'] ?? 'HTTP ' . ($tokenResp['code'] ?? '?');
        error_log("[Discord] Token exchange failed: " . json_encode($tokenResp['data'] ?? []));
        discordRedirect('discord_err', "Could not connect to Discord ($detail). Please try again.");
    }

    $accessToken = $tokenResp['data']['access_token'];

    // Fetch Discord user
    $userResp = discordRequest('GET', '/users/@me', [], $accessToken);

    if (!$userResp || $userResp['code'] !== 200 || empty($userResp['data']['id'])) {
        discordRedirect('discord_err', 'Could not retrieve your Discord profile. Please try again.');
    }

    $dUser     = $userResp['data'];
    $discordId = $dUser['id'];
    $discordName = $dUser['username'];
    if (!empty($dUser['discriminator']) && $dUser['discriminator'] !== '0') {
        $discordName .= '#' . $dUser['discriminator'];
    }

    // Conflict check — another account already linked to this Discord ID
    $conflict = $db->prepare("SELECT username FROM users WHERE discord_id = ? AND id != ?")->execute([$discordId, $currentUser['id']]);
    $conflict  = $db->prepare("SELECT username FROM users WHERE discord_id = ? AND id != ?");
    $conflict->execute([$discordId, $currentUser['id']]);
    $conflict = $conflict->fetch();

    if ($conflict) {
        discordRedirect('discord_err', "This Discord account is already linked to another forum account ({$conflict['username']}). Unlink it there first.");
    }

    // Save Discord ID to user
    $db->prepare("UPDATE users SET discord_id = ? WHERE id = ?")
       ->execute([$discordId, $currentUser['id']]);
    logAudit($currentUser['id'], 'link_discord', 'user', $currentUser['id'], "Discord: $discordName ($discordId)");

    $roleAssigned = false;

    if (DISCORD_BOT_TOKEN && DISCORD_GUILD_ID && DISCORD_VERIFIED_ROLE) {
        // Add to guild (silent if already member)
        discordRequest('PUT', '/guilds/' . DISCORD_GUILD_ID . '/members/' . $discordId,
            ['access_token' => $accessToken], '', true);

        // Assign Verified role
        $roleResp = discordRequest('PUT',
            '/guilds/' . DISCORD_GUILD_ID . '/members/' . $discordId . '/roles/' . DISCORD_VERIFIED_ROLE,
            [], '', true);
        $roleAssigned = ($roleResp && $roleResp['code'] === 204);

        if (!$roleAssigned) {
            error_log("[Discord] Role assign failed for $discordId: HTTP {$roleResp['code']} — {$roleResp['raw']}");
        }
    }

    $msg = "Discord account $discordName linked!";
    if ($roleAssigned)               $msg .= " Your Verified role has been assigned in the server.";
    elseif (DISCORD_BOT_TOKEN)       $msg .= " (Role auto-assign failed — ensure the bot role is above Verified in role hierarchy.)";

    discordRedirect('discord_msg', $msg);
}

// ── Unlink ───────────────────────────────────────────────────────────────────
if (isset($_GET['unlink'])) {
    if (!verifyCSRF($_GET['csrf'] ?? '')) {
        discordRedirect('discord_err', 'Invalid security token.');
    }
    $db->prepare("UPDATE users SET discord_id = NULL WHERE id = ?")->execute([$currentUser['id']]);
    logAudit($currentUser['id'], 'unlink_discord', 'user', $currentUser['id']);
    discordRedirect('discord_msg', 'Discord account unlinked successfully.');
}

// ── Discord returned an error (user cancelled, etc.) ─────────────────────────
if (isset($_GET['error'])) {
    $desc = $_GET['error_description'] ?? $_GET['error'] ?? 'Unknown error';
    discordRedirect('discord_err', 'Discord authorisation was cancelled: ' . $desc);
}

header('Location: ' . SITE_URL . '/settings.php');
exit;
