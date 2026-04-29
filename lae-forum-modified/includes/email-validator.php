<?php
/**
 * Advanced Email Validation System
 * Blocks slurs, disposable emails, gibberish, and alt-like addresses
 */

class EmailValidator {
    // Banned words/slurs - expandable list
    private static array $bannedWords = [
        'polski', 'femboy', 'nigger', 'nigga', 'faggot', 'fag', 'retard', 
        'kike', 'spic', 'chink', 'gook', 'tranny', 'cunt', 'whore',
        'slut', 'bitch', 'cock', 'dick', 'pussy', 'penis', 'vagina',
        'porn', 'xxx', 'anal', 'sex', 'nude', 'naked', 'hentai',
        'nazi', 'hitler', 'kkk', 'jihad', 'isis', 'terrorist',
        // Common alt indicators
        'alt', 'fake', 'temp', 'throw', 'spam', 'trash', 'junk',
        'test123', 'noname', 'anonymous', 'nobody', 'someone'
    ];

    // Disposable email domains
    private static array $disposableDomains = [
        // Major disposable services
        'tempmail.com', 'temp-mail.org', 'tempmailo.com', 'tempmailaddress.com',
        '10minutemail.com', '10minutemail.net', '10minmail.com', '10mail.org',
        'guerrillamail.com', 'guerrillamail.org', 'guerrillamail.net', 'guerrillamail.biz',
        'guerrillamail.de', 'grr.la', 'pokemail.net', 'spam4.me',
        'mailinator.com', 'mailinator.net', 'mailinator.org', 'mailinator2.com',
        'maildrop.cc', 'mailnesia.com', 'mailcatch.com', 'mailsac.com',
        'throwaway.email', 'throwawaymail.com', 'throam.com',
        'getairmail.com', 'getnada.com', 'nada.email',
        'yopmail.com', 'yopmail.fr', 'yopmail.net', 'cool.fr.nf', 'jetable.fr.nf',
        'trashmail.com', 'trashmail.net', 'trashmail.org', 'trashemail.de',
        'fakeinbox.com', 'fakemailgenerator.com', 'emailondeck.com',
        'mohmal.com', 'dispostable.com', 'mailexpire.com',
        'mintemail.com', 'tempinbox.com', 'mytemp.email',
        'sharklasers.com', 'guerrillamail.info', 'spam.la',
        'discard.email', 'discardmail.com', 'spambog.com',
        'mailnull.com', 'spamgourmet.com', 'antispam.de',
        'mail-temp.com', 'temp.headstrong.de', 'fakeinbox.info',
        'emailfake.com', 'generator.email', 'emkei.cz',
        'crazymailing.com', 'tempr.email', 'tmail.ws',
        'tmpmail.org', 'tmpmail.net', 'moakt.com', 'moakt.ws',
        'inboxbear.com', 'anonbox.net', 'anonymbox.com',
        'burnermail.io', 'burner.kiwi', 'easytrashmail.com',
        'dropmail.me', 'harakirimail.com', 'mailforspam.com',
        'spambox.us', 'spamfree24.org', 'spamherelots.com',
        'tempmailbox.net', 'wegwerfmail.de', 'wegwerfmail.net',
        'einrot.com', 'emailisvalid.com', 'emlpro.com',
        'tempsky.com', 'inboxkitten.com', 'emailtemporario.com.br',
        'receiveee.com', 'mail7.io', 'mailtemp.net',
        'mt2015.com', 'mt2014.com', 'thankyou2010.com',
        'imgof.com', 'imgv.de', 'trbvm.com', 'klzlv.com',
        'vomoto.com', 'tmail.io', 'byom.de', 'bareed.ws'
    ];

    // Suspicious TLDs often used for throwaway emails
    private static array $suspiciousTLDs = [
        'xyz', 'top', 'wang', 'win', 'bid', 'loan', 'work', 'click',
        'gdn', 'stream', 'download', 'racing', 'review', 'party',
        'science', 'cricket', 'webcam', 'trade', 'date', 'faith',
        'accountant', 'men', 'win', 'kim', 'pink', 'red'
    ];

    /**
     * Validate an email address with comprehensive checks
     * @return array ['valid' => bool, 'error' => string|null, 'reason' => string|null]
     */
    public static function validate(string $email): array {
        $email = strtolower(trim($email));
        
        // Basic format validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::fail('Please enter a valid email address.', 'invalid_format');
        }

        // Extract parts
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return self::fail('Please enter a valid email address.', 'invalid_format');
        }

        [$localPart, $domain] = $parts;

        // Check for banned words in local part
        $bannedCheck = self::checkBannedWords($localPart);
        if ($bannedCheck) {
            return self::fail(
                "Sorry, this email contains inappropriate content (\"$bannedCheck\") and cannot be used. Please use a different email address.",
                'banned_word'
            );
        }

        // Check disposable domains
        if (self::isDisposableDomain($domain)) {
            return self::fail(
                'Disposable/temporary email addresses are not allowed. Please use a permanent email from a trusted provider (Gmail, Outlook, Yahoo, etc.).',
                'disposable_domain'
            );
        }

        // Check suspicious TLD
        $tld = self::getTLD($domain);
        if (in_array($tld, self::$suspiciousTLDs)) {
            // Don't outright block, but flag for review
            // For now, we'll allow but this could be made stricter
        }

        // Check for gibberish in local part
        $gibberishCheck = self::isGibberish($localPart);
        if ($gibberishCheck['is_gibberish']) {
            return self::fail(
                "This email address appears to be invalid or randomly generated. Please use a real email address. (Reason: {$gibberishCheck['reason']})",
                'gibberish'
            );
        }

        // Check for excessive numbers (alt-like pattern)
        if (self::hasExcessiveNumbers($localPart)) {
            return self::fail(
                'This email contains too many numbers which often indicates a throwaway account. Please use your primary email address.',
                'excessive_numbers'
            );
        }

        // Check for keyboard walk patterns (qwerty, asdf, etc.)
        if (self::isKeyboardWalk($localPart)) {
            return self::fail(
                'This email address appears to be randomly typed. Please use a real email address.',
                'keyboard_walk'
            );
        }

        // Check for repeated characters
        if (self::hasExcessiveRepeats($localPart)) {
            return self::fail(
                'This email address contains excessive repeated characters. Please use a real email address.',
                'excessive_repeats'
            );
        }

        // Check minimum meaningful length
        $stripped = preg_replace('/[^a-z]/', '', $localPart);
        if (strlen($stripped) < 3) {
            return self::fail(
                'This email address is too short or lacks sufficient characters. Please use a real email address.',
                'too_short'
            );
        }

        return ['valid' => true, 'error' => null, 'reason' => null];
    }

    /**
     * Check for banned words in the email local part
     */
    private static function checkBannedWords(string $localPart): ?string {
        // Remove numbers and special chars for word matching
        $cleaned = preg_replace('/[^a-z]/', '', $localPart);
        
        foreach (self::$bannedWords as $word) {
            // Direct match
            if (strpos($cleaned, $word) !== false) {
                return $word;
            }
            
            // Leet speak variations
            $leetVariations = self::generateLeetVariations($word);
            foreach ($leetVariations as $variation) {
                if (strpos($cleaned, $variation) !== false) {
                    return $word;
                }
            }
        }
        
        return null;
    }

    /**
     * Generate common leet speak variations of a word
     */
    private static function generateLeetVariations(string $word): array {
        $leet = [
            'a' => ['4', '@'],
            'e' => ['3'],
            'i' => ['1', '!'],
            'o' => ['0'],
            's' => ['5', '$'],
            't' => ['7'],
            'l' => ['1'],
            'b' => ['8'],
            'g' => ['9'],
        ];
        
        $variations = [$word];
        
        // Generate single-substitution variations
        foreach ($leet as $letter => $replacements) {
            foreach ($replacements as $replacement) {
                $variations[] = str_replace($letter, $replacement, $word);
            }
        }
        
        return array_unique($variations);
    }

    /**
     * Check if domain is a known disposable email service
     */
    private static function isDisposableDomain(string $domain): bool {
        // Direct match
        if (in_array($domain, self::$disposableDomains)) {
            return true;
        }
        
        // Check subdomains
        foreach (self::$disposableDomains as $disposable) {
            if (str_ends_with($domain, '.' . $disposable)) {
                return true;
            }
        }
        
        // Check for common disposable patterns in domain
        $disposablePatterns = [
            '/temp.*mail/i', '/mail.*temp/i', '/throw.*mail/i', '/trash.*mail/i',
            '/fake.*mail/i', '/spam.*mail/i', '/junk.*mail/i', '/10.*min/i',
            '/minute.*mail/i', '/dispos/i', '/guerrilla/i', '/mailinator/i'
        ];
        
        foreach ($disposablePatterns as $pattern) {
            if (preg_match($pattern, $domain)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if local part is gibberish
     */
    private static function isGibberish(string $localPart): array {
        // Remove common separators and numbers for analysis
        $cleaned = preg_replace('/[0-9._\-+]/', '', $localPart);
        
        if (strlen($cleaned) < 3) {
            return ['is_gibberish' => false, 'reason' => null];
        }

        // Check consonant clustering (more than 4 consonants in a row)
        if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/i', $cleaned)) {
            return ['is_gibberish' => true, 'reason' => 'unusual letter patterns'];
        }

        // Check vowel ratio (should be roughly 30-60% for English)
        $vowelCount = preg_match_all('/[aeiou]/i', $cleaned);
        $ratio = $vowelCount / strlen($cleaned);
        if ($ratio < 0.15 && strlen($cleaned) > 5) {
            return ['is_gibberish' => true, 'reason' => 'lacks sufficient vowels'];
        }
        if ($ratio > 0.75 && strlen($cleaned) > 5) {
            return ['is_gibberish' => true, 'reason' => 'excessive vowels'];
        }

        // Check for random-looking character sequences using bigram analysis
        $suspiciousBigrams = ['xz', 'zx', 'qx', 'xq', 'vx', 'xv', 'zq', 'qz', 
                             'jx', 'xj', 'wx', 'xw', 'vq', 'qv', 'zj', 'jz',
                             'fq', 'qf', 'gx', 'xg', 'hx', 'xh', 'kx', 'xk'];
        $bigramCount = 0;
        foreach ($suspiciousBigrams as $bigram) {
            if (stripos($cleaned, $bigram) !== false) {
                $bigramCount++;
            }
        }
        if ($bigramCount >= 2) {
            return ['is_gibberish' => true, 'reason' => 'unusual character combinations'];
        }

        // Check entropy (randomness) - high entropy often means gibberish
        $entropy = self::calculateEntropy($cleaned);
        if ($entropy > 4.2 && strlen($cleaned) > 6) {
            return ['is_gibberish' => true, 'reason' => 'appears randomly generated'];
        }

        return ['is_gibberish' => false, 'reason' => null];
    }

    /**
     * Calculate Shannon entropy of a string
     */
    private static function calculateEntropy(string $str): float {
        $str = strtolower($str);
        $len = strlen($str);
        if ($len === 0) return 0;

        $freq = [];
        for ($i = 0; $i < $len; $i++) {
            $char = $str[$i];
            $freq[$char] = ($freq[$char] ?? 0) + 1;
        }

        $entropy = 0;
        foreach ($freq as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }

    /**
     * Check for excessive numbers in email
     */
    private static function hasExcessiveNumbers(string $localPart): bool {
        // Count digits
        $digitCount = preg_match_all('/[0-9]/', $localPart);
        $totalLen = strlen($localPart);
        
        // More than 60% numbers is suspicious
        if ($totalLen > 0 && ($digitCount / $totalLen) > 0.6) {
            return true;
        }
        
        // More than 6 consecutive digits (like user123456789)
        if (preg_match('/[0-9]{7,}/', $localPart)) {
            return true;
        }
        
        // Pattern like name + many random numbers
        if (preg_match('/[a-z]{1,3}[0-9]{5,}$/i', $localPart)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check for keyboard walk patterns
     */
    private static function isKeyboardWalk(string $localPart): bool {
        $cleaned = preg_replace('/[^a-z0-9]/', '', strtolower($localPart));
        
        $keyboardWalks = [
            'qwerty', 'qwert', 'asdf', 'asdfg', 'asdfgh', 'zxcv', 'zxcvb',
            'qazwsx', 'qaswed', '1234567', '123456789', 'abcdef', 'abcdefg',
            'password', 'pass123', 'admin', 'user123', 'test', 'testing',
            'aaaaaa', 'bbbbbb', 'cccccc', 'qqqqqq', 'asdfjkl', 'jklasdf'
        ];
        
        foreach ($keyboardWalks as $walk) {
            if (strpos($cleaned, $walk) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check for excessive repeated characters
     */
    private static function hasExcessiveRepeats(string $localPart): bool {
        // More than 3 of the same character in a row
        if (preg_match('/(.)\1{3,}/', $localPart)) {
            return true;
        }
        
        // Same 2-char pattern repeated 3+ times (e.g., "ababab")
        if (preg_match('/(.{2})\1{2,}/', $localPart)) {
            return true;
        }
        
        return false;
    }

    /**
     * Get TLD from domain
     */
    private static function getTLD(string $domain): string {
        $parts = explode('.', $domain);
        return end($parts);
    }

    /**
     * Return failure result
     */
    private static function fail(string $error, string $reason): array {
        return ['valid' => false, 'error' => $error, 'reason' => $reason];
    }

    /**
     * Add a custom banned word (for admin use)
     */
    public static function addBannedWord(string $word): void {
        self::$bannedWords[] = strtolower(trim($word));
    }

    /**
     * Add a custom disposable domain (for admin use)
     */
    public static function addDisposableDomain(string $domain): void {
        self::$disposableDomains[] = strtolower(trim($domain));
    }

    /**
     * Load custom filters from database
     */
    public static function loadCustomFilters(\PDO $db): void {
        try {
            $stmt = $db->query("SELECT type, value FROM content_filters WHERE type IN ('word', 'domain')");
            while ($row = $stmt->fetch()) {
                if ($row['type'] === 'word') {
                    self::addBannedWord($row['value']);
                } elseif ($row['type'] === 'domain') {
                    self::addDisposableDomain($row['value']);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist yet, ignore
        }
    }
}

/**
 * Quick validation function for use in registration
 */
function validateEmail(string $email): array {
    return EmailValidator::validate($email);
}
