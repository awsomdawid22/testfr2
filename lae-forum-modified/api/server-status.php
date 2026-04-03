<?php
/**
 * FiveM Server Status API
 * 
 * This endpoint returns live player data from your FiveM server.
 * It first checks for cached data from the lae-web-status resource,
 * then falls back to direct server query if no cache exists.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=10'); // Cache for 10 seconds

require_once __DIR__ . '/../includes/config.php';

// ============================================
// First, check for cached data from FiveM resource
// ============================================
$cacheFile = __DIR__ . '/../cache/server-status.json';
$useCache = false;

if (file_exists($cacheFile)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    // Use cache if it's less than 60 seconds old
    if ($cacheData && isset($cacheData['timestamp'])) {
        $cacheAge = time() - $cacheData['timestamp'];
        if ($cacheAge < 60) {
            $useCache = true;
        }
    }
}

// If we have fresh cache from the FiveM resource, use it
if ($useCache && $cacheData) {
    echo json_encode([
        'online' => true,
        'players' => $cacheData['players'] ?? 0,
        'maxPlayers' => $cacheData['maxPlayers'] ?? 128,
        'serverName' => $cacheData['hostname'] ?? 'Los Angeles Experience',
        'playerList' => $cacheData['playerList'] ?? [],
        'lastUpdated' => date('c', $cacheData['timestamp']),
        'source' => 'fivem-resource'
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============================================
// Fallback: Direct FiveM Server Query
// ============================================

// Default configuration
$serverIp = '127.0.0.1';
$serverPort = 30120;

// Load from site settings if available
$settingsFile = __DIR__ . '/../includes/settings.php';
if (file_exists($settingsFile)) {
    $settings = include $settingsFile;
    if (!empty($settings['server_ip'])) {
        // Parse IP:port format
        $parts = explode(':', $settings['server_ip']);
        $serverIp = $parts[0];
        if (isset($parts[1])) {
            $serverPort = (int)$parts[1];
        }
    }
}

// ============================================
// Fetch server info from FiveM
// ============================================

function fetchFiveMServerInfo($ip, $port, $timeout = 5) {
    $url = "http://{$ip}:{$port}/info.json";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

function fetchFiveMPlayers($ip, $port, $timeout = 5) {
    $url = "http://{$ip}:{$port}/players.json";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

function fetchFromTxAdmin($txAdminUrl, $token = null) {
    $url = rtrim($txAdminUrl, '/') . '/status';
    
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

// ============================================
// Get server status
// ============================================

$result = [
    'online' => false,
    'players' => 0,
    'maxPlayers' => 0,
    'serverName' => '',
    'playerList' => [],
    'lastUpdated' => date('c'),
    'source' => 'direct-query'
];

try {
    // Try direct FiveM query first
    $serverInfo = fetchFiveMServerInfo($serverIp, $serverPort);
    $players = fetchFiveMPlayers($serverIp, $serverPort);
    
    if ($serverInfo !== null) {
        $result['online'] = true;
        $result['serverName'] = $serverInfo['vars']['sv_projectName'] ?? $serverInfo['vars']['sv_hostname'] ?? 'FiveM Server';
        $result['maxPlayers'] = $serverInfo['vars']['sv_maxClients'] ?? 128;
        
        if (is_array($players)) {
            $result['players'] = count($players);
            
            // Include player list (limited info for privacy)
            foreach ($players as $player) {
                $result['playerList'][] = [
                    'id' => $player['id'] ?? 0,
                    'name' => $player['name'] ?? 'Unknown',
                    'ping' => $player['ping'] ?? 0,
                ];
            }
            
            // Sort by name
            usort($result['playerList'], function($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
        }
    }
} catch (Exception $e) {
    // Server offline or unreachable
    $result['error'] = 'Unable to connect to server';
}

echo json_encode($result, JSON_PRETTY_PRINT);
