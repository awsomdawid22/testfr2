<?php
/**
 * AutoMod Engine
 * Automatic content moderation and spam detection
 */

class AutoMod {
    private static ?array $settings = null;
    private static ?array $filters = null;
    private static ?\PDO $db = null;

    /**
     * Initialize AutoMod with database connection
     */
    public static function init(\PDO $db): void {
        self::$db = $db;
        self::loadSettings();
        self::loadFilters();
    }

    /**
     * Load AutoMod settings from site settings
     */
    private static function loadSettings(): void {
        $settingsFile = __DIR__ . '/settings.php';
        $siteSettings = file_exists($settingsFile) ? include $settingsFile : [];
        
        self::$settings = [
            'enabled' => ($siteSettings['automod_enabled'] ?? '1') === '1',
            'spam_threshold' => (int)($siteSettings['automod_spam_threshold'] ?? 3),
            'spam_window' => (int)($siteSettings['automod_spam_window'] ?? 60), // seconds
            'new_user_days' => (int)($siteSettings['automod_new_user_days'] ?? 7),
            'new_user_post_limit' => (int)($siteSettings['automod_new_user_post_limit'] ?? 10),
            'link_check_enabled' => ($siteSettings['automod_link_check'] ?? '1') === '1',
            'profanity_action' => $siteSettings['automod_profanity_action'] ?? 'flag', // flag, warn, delete
            'spam_action' => $siteSettings['automod_spam_action'] ?? 'warn',
            'auto_ban_threshold' => (int)($siteSettings['automod_auto_ban_threshold'] ?? 5),
        ];
    }

    /**
     * Load content filters from database
     */
    private static function loadFilters(): void {
        self::$filters = ['words' => [], 'patterns' => [], 'domains' => []];
        
        if (!self::$db) return;
        
        try {
            $stmt = self::$db->query("SELECT type, value, action FROM content_filters WHERE is_active = 1");
            while ($row = $stmt->fetch()) {
                switch ($row['type']) {
                    case 'word':
                        self::$filters['words'][] = ['value' => strtolower($row['value']), 'action' => $row['action']];
                        break;
                    case 'pattern':
                        self::$filters['patterns'][] = ['value' => $row['value'], 'action' => $row['action']];
                        break;
                    case 'domain':
                        self::$filters['domains'][] = ['value' => strtolower($row['value']), 'action' => $row['action']];
                        break;
                }
            }
        } catch (\Exception $e) {
            // Table may not exist yet
        }
    }

    /**
     * Check content for violations
     * @return array ['allowed' => bool, 'violations' => array, 'action' => string|null]
     */
    public static function checkContent(string $content, int $userId, string $contentType = 'post'): array {
        if (!self::$settings['enabled']) {
            return ['allowed' => true, 'violations' => [], 'action' => null];
        }

        $violations = [];
        $maxAction = null;

        // Check for banned words
        $wordViolations = self::checkBannedWords($content);
        foreach ($wordViolations as $v) {
            $violations[] = $v;
            $maxAction = self::getHigherAction($maxAction, $v['action']);
        }

        // Check for patterns
        $patternViolations = self::checkPatterns($content);
        foreach ($patternViolations as $v) {
            $violations[] = $v;
            $maxAction = self::getHigherAction($maxAction, $v['action']);
        }

        // Check for suspicious links
        if (self::$settings['link_check_enabled']) {
            $linkViolations = self::checkLinks($content, $userId);
            foreach ($linkViolations as $v) {
                $violations[] = $v;
                $maxAction = self::getHigherAction($maxAction, $v['action']);
            }
        }

        // Log violations
        if ($violations) {
            self::logAction($userId, $maxAction ?? 'flag', implode(', ', array_column($violations, 'reason')), $contentType, null, substr($content, 0, 500));
        }

        $allowed = !in_array($maxAction, ['block', 'delete']);

        return [
            'allowed' => $allowed,
            'violations' => $violations,
            'action' => $maxAction,
            'message' => $allowed ? null : self::getViolationMessage($violations)
        ];
    }

    /**
     * Check for banned words in content
     */
    private static function checkBannedWords(string $content): array {
        $violations = [];
        $contentLower = strtolower($content);
        $contentCleaned = preg_replace('/[^a-z0-9]/', '', $contentLower);

        foreach (self::$filters['words'] as $filter) {
            $word = $filter['value'];
            
            // Direct match
            if (strpos($contentLower, $word) !== false || strpos($contentCleaned, $word) !== false) {
                $violations[] = [
                    'type' => 'banned_word',
                    'value' => $word,
                    'action' => $filter['action'],
                    'reason' => "Contains banned word: $word"
                ];
                // Update match count
                self::incrementFilterCount($word, 'word');
            }
        }

        return $violations;
    }

    /**
     * Check for pattern matches
     */
    private static function checkPatterns(string $content): array {
        $violations = [];

        foreach (self::$filters['patterns'] as $filter) {
            if (preg_match('/' . $filter['value'] . '/i', $content)) {
                $violations[] = [
                    'type' => 'pattern_match',
                    'value' => $filter['value'],
                    'action' => $filter['action'],
                    'reason' => "Matches blocked pattern"
                ];
                self::incrementFilterCount($filter['value'], 'pattern');
            }
        }

        return $violations;
    }

    /**
     * Check for suspicious links
     */
    private static function checkLinks(string $content, int $userId): array {
        $violations = [];
        
        // Extract URLs
        preg_match_all('/https?:\/\/[^\s<>"\']+/i', $content, $matches);
        $urls = $matches[0] ?? [];

        // Check if user is new
        $isNewUser = self::isNewUser($userId);

        foreach ($urls as $url) {
            $domain = parse_url($url, PHP_URL_HOST);
            if (!$domain) continue;

            $domain = strtolower(preg_replace('/^www\./', '', $domain));

            // Check against blocked domains
            foreach (self::$filters['domains'] as $filter) {
                if ($domain === $filter['value'] || str_ends_with($domain, '.' . $filter['value'])) {
                    $violations[] = [
                        'type' => 'blocked_domain',
                        'value' => $domain,
                        'action' => $filter['action'],
                        'reason' => "Link to blocked domain: $domain"
                    ];
                    self::incrementFilterCount($filter['value'], 'domain');
                }
            }

            // New users posting links - flag for review
            if ($isNewUser && !$violations) {
                $violations[] = [
                    'type' => 'new_user_link',
                    'value' => $domain,
                    'action' => 'flag',
                    'reason' => "New user posting link to: $domain"
                ];
            }
        }

        return $violations;
    }

    /**
     * Check for spam behavior
     */
    public static function checkSpam(int $userId, string $content): array {
        if (!self::$settings['enabled'] || !self::$db) {
            return ['is_spam' => false, 'reason' => null];
        }

        // Check post frequency
        $window = self::$settings['spam_window'];
        $threshold = self::$settings['spam_threshold'];

        try {
            $stmt = self::$db->prepare("
                SELECT COUNT(*) FROM posts 
                WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$userId, $window]);
            $recentPosts = (int)$stmt->fetchColumn();

            if ($recentPosts >= $threshold) {
                self::logAction($userId, 'warn', "Spam detected: $recentPosts posts in $window seconds", 'spam');
                return [
                    'is_spam' => true,
                    'reason' => "You're posting too quickly. Please wait a moment before posting again.",
                    'action' => self::$settings['spam_action']
                ];
            }

            // Check for duplicate content
            $stmt = self::$db->prepare("
                SELECT COUNT(*) FROM posts 
                WHERE user_id = ? AND content = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$userId, $content]);
            $duplicates = (int)$stmt->fetchColumn();

            if ($duplicates > 0) {
                self::logAction($userId, 'warn', "Duplicate content detected", 'spam');
                return [
                    'is_spam' => true,
                    'reason' => "You've already posted this content. Please avoid duplicate posts.",
                    'action' => 'warn'
                ];
            }
        } catch (\Exception $e) {
            // Ignore errors
        }

        return ['is_spam' => false, 'reason' => null];
    }

    /**
     * Check if user is within new user restriction period
     */
    public static function isNewUser(int $userId): bool {
        if (!self::$db) return false;

        try {
            $stmt = self::$db->prepare("SELECT created_at, is_trusted FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) return false;
            if ($user['is_trusted']) return false; // Trusted users bypass

            $accountAge = (time() - strtotime($user['created_at'])) / 86400;
            return $accountAge < self::$settings['new_user_days'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if new user has exceeded post limit
     */
    public static function checkNewUserLimit(int $userId): array {
        if (!self::$db || !self::isNewUser($userId)) {
            return ['exceeded' => false];
        }

        try {
            $stmt = self::$db->prepare("
                SELECT COUNT(*) FROM posts 
                WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$userId]);
            $todayPosts = (int)$stmt->fetchColumn();

            $limit = self::$settings['new_user_post_limit'];
            if ($todayPosts >= $limit) {
                return [
                    'exceeded' => true,
                    'reason' => "New accounts are limited to $limit posts per day. Please try again tomorrow."
                ];
            }

            return ['exceeded' => false, 'remaining' => $limit - $todayPosts];
        } catch (\Exception $e) {
            return ['exceeded' => false];
        }
    }

    /**
     * Log an automod action
     */
    public static function logAction(int $userId, string $actionType, string $reason, string $contentType = null, ?int $contentId = null, ?string $preview = null): void {
        if (!self::$db) return;

        $severity = match($actionType) {
            'ban' => 'critical',
            'delete', 'block' => 'high',
            'warn', 'mute' => 'medium',
            default => 'low'
        };

        try {
            $stmt = self::$db->prepare("
                INSERT INTO automod_log (user_id, action_type, reason, content_type, content_id, content_preview, severity)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $actionType, $reason, $contentType, $contentId, $preview, $severity]);

            // Check if user should be auto-banned
            self::checkAutoBan($userId);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Check if user should be auto-banned based on violation count
     */
    private static function checkAutoBan(int $userId): void {
        if (!self::$db || self::$settings['auto_ban_threshold'] <= 0) return;

        try {
            $stmt = self::$db->prepare("
                SELECT COUNT(*) FROM automod_log 
                WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND action_type IN ('warn', 'delete', 'block')
            ");
            $stmt->execute([$userId]);
            $violations = (int)$stmt->fetchColumn();

            if ($violations >= self::$settings['auto_ban_threshold']) {
                // Auto-mute the user
                self::$db->prepare("UPDATE users SET is_muted = 1, mute_reason = 'AutoMod: Too many violations' WHERE id = ?")
                    ->execute([$userId]);
                self::logAction($userId, 'mute', "Auto-muted: $violations violations in 7 days", 'automod');
            }
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Get the higher severity action
     */
    private static function getHigherAction(?string $current, string $new): string {
        $priority = ['flag' => 1, 'warn' => 2, 'shadow' => 3, 'block' => 4, 'delete' => 5, 'ban' => 6];
        
        if (!$current) return $new;
        
        return ($priority[$new] ?? 0) > ($priority[$current] ?? 0) ? $new : $current;
    }

    /**
     * Increment filter match count
     */
    private static function incrementFilterCount(string $value, string $type): void {
        if (!self::$db) return;

        try {
            self::$db->prepare("UPDATE content_filters SET match_count = match_count + 1 WHERE value = ? AND type = ?")
                ->execute([$value, $type]);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Get user-friendly violation message
     */
    private static function getViolationMessage(array $violations): string {
        if (!$violations) return '';

        $types = array_unique(array_column($violations, 'type'));
        
        if (in_array('banned_word', $types)) {
            return "Your message contains inappropriate content and cannot be posted.";
        }
        if (in_array('blocked_domain', $types)) {
            return "Your message contains a link to a blocked website.";
        }
        if (in_array('pattern_match', $types)) {
            return "Your message was blocked by our content filter.";
        }

        return "Your message was blocked by our moderation system.";
    }

    /**
     * Check if user can post (considering mute, flag, restrictions)
     */
    public static function canUserPost(array $user): array {
        // Check if muted
        if ($user['is_muted'] ?? false) {
            // Check if mute has expired
            if (!empty($user['muted_until']) && strtotime($user['muted_until']) < time()) {
                // Mute expired, should be cleared
                return ['allowed' => true];
            }
            return [
                'allowed' => false,
                'reason' => 'Your account is muted. ' . ($user['mute_reason'] ? "Reason: {$user['mute_reason']}" : '')
            ];
        }

        // Check if restricted
        if ($user['is_restricted'] ?? false) {
            return [
                'allowed' => false,
                'reason' => 'Your account is restricted. Please contact staff for assistance.'
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Get AutoMod statistics
     */
    public static function getStats(): array {
        if (!self::$db) return [];

        try {
            $stats = [];

            // Today's actions
            $stmt = self::$db->query("
                SELECT action_type, COUNT(*) as count 
                FROM automod_log 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY action_type
            ");
            $stats['today'] = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            // This week
            $stmt = self::$db->query("
                SELECT COUNT(*) FROM automod_log 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stats['week_total'] = (int)$stmt->fetchColumn();

            // Top triggered filters
            $stmt = self::$db->query("
                SELECT value, type, match_count 
                FROM content_filters 
                WHERE match_count > 0 
                ORDER BY match_count DESC 
                LIMIT 10
            ");
            $stats['top_filters'] = $stmt->fetchAll();

            return $stats;
        } catch (\Exception $e) {
            return [];
        }
    }
}

/**
 * Helper function to quickly check content
 */
function checkAutoMod(string $content, int $userId, string $contentType = 'post'): array {
    try {
        $db = getDB();
        AutoMod::init($db);
        return AutoMod::checkContent($content, $userId, $contentType);
    } catch (\Exception $e) {
        return ['allowed' => true, 'violations' => [], 'action' => null];
    }
}

/**
 * Helper to check spam
 */
function checkSpam(int $userId, string $content): array {
    try {
        $db = getDB();
        AutoMod::init($db);
        return AutoMod::checkSpam($userId, $content);
    } catch (\Exception $e) {
        return ['is_spam' => false, 'reason' => null];
    }
}
