<?php
/**
 * FiveM Server Status Receiver API
 * 
 * This endpoint receives player data from the lae-web-status FiveM resource
 * and caches it for the website to display.
 * 
 * The FiveM resource sends POST requests with player data every 10 seconds.
 * The server-status.php endpoint reads this cached data to display on the site.
 */

// CORS headers for FiveM server
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Load configuration
require_once __DIR__ . '/../includes/config.php';

// Load site settings for secret key
$siteSettings = [];
$settingsFile = __DIR__ . '/../includes/settings.php';
if (file_exists($settingsFile)) {
    $siteSettings = include $settingsFile;
}

// Get the secret key from settings
// Default key is provided but should be changed in admin settings
$validSecret = $siteSettings['fivem_secret'] ?? 'CHANGE_THIS_TO_A_RANDOM_SECRET_KEY';

// Get and validate JSON payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid JSON payload',
        'hint' => 'Make sure you are sending valid JSON data'
    ]);
    exit;
}

// Verify secret key
if (!isset($data['secret']) || $data['secret'] !== $validSecret) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Invalid or missing secret key',
        'hint' => 'Make sure the secret key in config.lua matches your website admin settings'
    ]);
    exit;
}

// Validate required fields
if (!isset($data['server']) || !isset($data['timestamp'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required fields',
        'hint' => 'Payload must include server object and timestamp'
    ]);
    exit;
}

// Extract and sanitize server data
$serverData = [
    'online' => true,
    'timestamp' => (int)($data['timestamp'] ?? time()),
    'players' => (int)($data['server']['players'] ?? 0),
    'maxPlayers' => (int)($data['server']['maxPlayers'] ?? 128),
    'hostname' => substr($data['server']['hostname'] ?? 'Unknown', 0, 128),
    'uptime' => (int)($data['server']['uptime'] ?? 0),
    'playerList' => []
];

// Sanitize player list
if (isset($data['playerList']) && is_array($data['playerList'])) {
    foreach ($data['playerList'] as $player) {
        if (!is_array($player)) continue;
        
        $playerData = [
            'id' => (int)($player['id'] ?? 0),
            'name' => substr($player['name'] ?? 'Unknown', 0, 64),
            'ping' => (int)($player['ping'] ?? 0)
        ];
        
        // Optionally include identifiers (sanitized)
        if (isset($player['discord'])) {
            $playerData['discord'] = preg_replace('/[^0-9]/', '', $player['discord']);
        }
        if (isset($player['steam'])) {
            $playerData['steam'] = substr($player['steam'], 0, 64);
        }
        
        $serverData['playerList'][] = $playerData;
    }
}

// Create cache directory if it doesn't exist
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    if (!mkdir($cacheDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create cache directory']);
        exit;
    }
}

// Write data to cache file
$cacheFile = $cacheDir . '/server-status.json';
$success = file_put_contents($cacheFile, json_encode($serverData, JSON_PRETTY_PRINT));

if ($success !== false) {
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'players' => $serverData['players'],
        'timestamp' => $serverData['timestamp']
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to write cache file',
        'hint' => 'Check file permissions on the cache directory'
    ]);
}
