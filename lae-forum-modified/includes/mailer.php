<?php
// ============================================
// LAE Forum — Email System
// Uses Brevo (formerly Sendinblue) SMTP
// Configure constants in config.php
// ============================================

// ---- Email config constants (add these to config.php) ----
// define('MAIL_ENABLED',  true);
// define('MAIL_HOST',     'smtp-relay.brevo.com');
// define('MAIL_PORT',     587);
// define('MAIL_USERNAME', 'your-brevo-login@email.com');  // Your Brevo account email
// define('MAIL_PASSWORD', 'your-brevo-smtp-key');          // Brevo SMTP key (NOT account password)
// define('MAIL_FROM',     'noreply@laexperiencefivem.com');
// define('MAIL_FROM_NAME','Los Angeles Experience');
// ----------------------------------------------------------

/**
 * Lightweight SMTP mailer — no dependencies, works with Brevo SMTP relay.
 * Supports STARTTLS on port 587.
 */
class LAEMailer {

    private string $host;
    private int    $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $lastError = '';

    public function __construct() {
        $this->host      = defined('MAIL_HOST')      ? MAIL_HOST      : 'smtp-relay.brevo.com';
        $this->port      = defined('MAIL_PORT')      ? MAIL_PORT      : 587;
        $this->username  = defined('MAIL_USERNAME')  ? MAIL_USERNAME  : '';
        $this->password  = defined('MAIL_PASSWORD')  ? MAIL_PASSWORD  : '';
        $this->fromEmail = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@laexperiencefivem.com';
        $this->fromName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Los Angeles Experience';
    }

    public function getLastError(): string {
        return $this->lastError;
    }

    /**
     * Send an email via SMTP with STARTTLS
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool {
        if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
            // Email disabled — log and return true so the site still works
            error_log("[LAE Mailer] Email disabled. Would send to: $toEmail | Subject: $subject");
            return true;
        }

        if (empty($this->username) || empty($this->password)) {
            $this->lastError = 'SMTP credentials not configured.';
            error_log('[LAE Mailer] ' . $this->lastError);
            return false;
        }

        if (empty($textBody)) {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $htmlBody));
            $textBody = preg_replace('/\s+/', ' ', $textBody);
        }

        try {
            // Open socket
            $errno = 0; $errstr = '';
            $socket = fsockopen($this->host, $this->port, $errno, $errstr, 10);
            if (!$socket) {
                throw new RuntimeException("Cannot connect to SMTP: $errstr ($errno)");
            }
            stream_set_timeout($socket, 15);

            // Read greeting
            $this->read($socket);

            // EHLO
            $this->write($socket, "EHLO laexperiencefivem.com\r\n");
            $ehloResp = $this->read($socket);

            // STARTTLS
            $this->write($socket, "STARTTLS\r\n");
            $tlsResp = $this->read($socket);
            if (strpos($tlsResp, '220') === false) {
                throw new RuntimeException("STARTTLS failed: $tlsResp");
            }

            // Upgrade to TLS
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Failed to enable TLS encryption');
            }

            // EHLO again after TLS
            $this->write($socket, "EHLO laexperiencefivem.com\r\n");
            $this->read($socket);

            // AUTH LOGIN
            $this->write($socket, "AUTH LOGIN\r\n");
            $this->read($socket);
            $this->write($socket, base64_encode($this->username) . "\r\n");
            $this->read($socket);
            $this->write($socket, base64_encode($this->password) . "\r\n");
            $authResp = $this->read($socket);
            if (strpos($authResp, '235') === false) {
                throw new RuntimeException("SMTP AUTH failed: $authResp");
            }

            // MAIL FROM
            $this->write($socket, "MAIL FROM:<{$this->fromEmail}>\r\n");
            $this->read($socket);

            // RCPT TO
            $this->write($socket, "RCPT TO:<{$toEmail}>\r\n");
            $rcptResp = $this->read($socket);
            if (strpos($rcptResp, '250') === false) {
                throw new RuntimeException("RCPT TO rejected: $rcptResp");
            }

            // DATA
            $this->write($socket, "DATA\r\n");
            $this->read($socket);

            // Build MIME message
            $boundary = 'LAE_' . md5(uniqid());
            $date = date('r');
            $msgId = '<' . uniqid('lae', true) . '@laexperiencefivem.com>';
            $encodedFrom = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
            $encodedTo   = '=?UTF-8?B?' . base64_encode($toName) . '?=';
            $encodedSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';

            $headers  = "From: {$encodedFrom} <{$this->fromEmail}>\r\n";
            $headers .= "To: {$encodedTo} <{$toEmail}>\r\n";
            $headers .= "Reply-To: {$this->fromEmail}\r\n";
            $headers .= "Subject: {$encodedSubj}\r\n";
            $headers .= "Date: {$date}\r\n";
            $headers .= "Message-ID: {$msgId}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $headers .= "X-Mailer: LAE Forum Mailer\r\n";

            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($textBody)) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
            $body .= "--{$boundary}--\r\n";

            $this->write($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
            $dataResp = $this->read($socket);
            if (strpos($dataResp, '250') === false) {
                throw new RuntimeException("Message rejected: $dataResp");
            }

            // QUIT
            $this->write($socket, "QUIT\r\n");
            fclose($socket);

            error_log("[LAE Mailer] Email sent to $toEmail | Subject: $subject");
            return true;

        } catch (RuntimeException $e) {
            $this->lastError = $e->getMessage();
            error_log('[LAE Mailer] Error: ' . $this->lastError);
            if (isset($socket) && is_resource($socket)) fclose($socket);
            return false;
        }
    }

    private function write($socket, string $data): void {
        fwrite($socket, $data);
    }

    private function read($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break; // Last line of response
        }
        return $response;
    }
}

// ============================================
// Email Templates
// ============================================

function getEmailWrapper(string $title, string $bodyHtml): string {
    $siteUrl  = defined('SITE_URL') ? SITE_URL : 'https://laexperiencefivem.com';
    $logoB64  = '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';
    $logoSrc  = "data:image/png;base64,{$logoB64}";

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#08080a;font-family:'Segoe UI',Helvetica,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#08080a;padding:32px 16px">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">

      <!-- Logo Header -->
      <tr>
        <td style="background:#0d0d0f;border:1px solid #1c1c28;border-bottom:1px solid #222230;border-radius:12px 12px 0 0;padding:0;text-align:center;overflow:hidden">
          <img src="{$logoSrc}" alt="Los Angeles Experience" width="560" style="display:block;margin:0 auto;max-width:100%;height:auto;border-radius:4px">
        </td>
      </tr>

      <!-- Divider bar -->
      <tr>
        <td style="background:linear-gradient(90deg,#111116 0%,#2a2a3a 40%,#ffffff 50%,#2a2a3a 60%,#111116 100%);height:1px;border-left:1px solid #1c1c28;border-right:1px solid #1c1c28"></td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="background:#0d0d0f;border-left:1px solid #1c1c28;border-right:1px solid #1c1c28;padding:36px 44px">
          {$bodyHtml}
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#080808;border:1px solid #1c1c28;border-top:1px solid #161620;border-radius:0 0 12px 12px;padding:20px 44px;text-align:center">
          <p style="color:#383840;font-size:11px;margin:0;line-height:1.8">
            This email was sent by Los Angeles Experience Forums.<br>
            If you did not request this, you can safely ignore it.<br>
            <a href="{$siteUrl}" style="color:#666680;text-decoration:none">{$siteUrl}</a>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail(string $toEmail, string $username, string $resetToken): bool {
    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://laexperiencefivem.com';
    $resetUrl = $siteUrl . '/reset-password.php?token=' . urlencode($resetToken);
    $expiry = '2 hours';

    $body = <<<HTML
<h2 style="color:#f0f0f8;font-size:22px;font-weight:700;letter-spacing:0.5px;margin:0 0 8px">Password Reset Request</h2>
<p style="color:#55556a;font-size:12px;text-transform:uppercase;letter-spacing:2px;margin:0 0 28px">Requested for your account</p>

<p style="color:#b8b8cc;font-size:15px;line-height:1.8">
  Hi <strong style="color:#f0f0f8">{$username}</strong>,<br><br>
  We received a request to reset the password for your LAE Forums account.
  Click the button below to set a new password. This link expires in <strong style="color:#f0f0f8">{$expiry}</strong>.
</p>

<div style="text-align:center;margin:32px 0">
  <a href="{$resetUrl}"
     style="display:inline-block;background:#ffffff;color:#08080a;text-decoration:none;padding:14px 36px;border-radius:6px;font-weight:700;font-size:15px;letter-spacing:1px">
    RESET MY PASSWORD
  </a>
</div>

<p style="color:#555560;font-size:12px;line-height:1.8;border-top:1px solid #1a1a26;padding-top:20px;margin-top:24px">
  If the button doesn't work, copy and paste this link into your browser:<br>
  <a href="{$resetUrl}" style="color:#aaaacc;word-break:break-all">{$resetUrl}</a>
</p>

<p style="color:#444450;font-size:12px;margin-top:10px">
  If you didn't request a password reset, your account is safe — just ignore this email.
  Someone may have entered your email address by mistake.
</p>
HTML;

    $html = getEmailWrapper('Password Reset · LAE Forums', $body);
    $mailer = new LAEMailer();
    return $mailer->send($toEmail, $username, 'Reset Your LAE Forums Password', $html);
}

/**
 * Send welcome / email verification email
 */
function sendWelcomeEmail(string $toEmail, string $username, string $verifyToken): bool {
    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://laexperiencefivem.com';
    $verifyUrl = $siteUrl . '/verify-email.php?token=' . urlencode($verifyToken);

    $body = <<<HTML
<h2 style="color:#f0f0f8;font-size:22px;font-weight:700;letter-spacing:0.5px;margin:0 0 8px">Welcome to LAE Forums!</h2>
<p style="color:#55556a;font-size:12px;text-transform:uppercase;letter-spacing:2px;margin:0 0 28px">One last step — verify your email</p>

<p style="color:#b8b8cc;font-size:15px;line-height:1.8">
  Hey <strong style="color:#f0f0f8">{$username}</strong>, welcome to the Los Angeles Experience community!<br><br>
  Please verify your email address to unlock full access to the forums.
</p>

<div style="text-align:center;margin:32px 0">
  <a href="{$verifyUrl}"
     style="display:inline-block;background:#ffffff;color:#08080a;text-decoration:none;padding:14px 36px;border-radius:6px;font-weight:700;font-size:15px;letter-spacing:1px">
    VERIFY MY EMAIL
  </a>
</div>

<p style="color:#555560;font-size:12px;line-height:1.8;border-top:1px solid #1a1a26;padding-top:20px;margin-top:24px">
  If the button doesn't work:<br>
  <a href="{$verifyUrl}" style="color:#aaaacc;word-break:break-all">{$verifyUrl}</a>
</p>
HTML;

    $html = getEmailWrapper('Welcome to LAE Forums!', $body);
    $mailer = new LAEMailer();
    return $mailer->send($toEmail, $username, 'Welcome to LAE Forums — Verify Your Email', $html);
}

/**
 * Send application status update email
 */
function sendApplicationStatusEmail(string $toEmail, string $username, string $position, string $status, string $notes = ''): bool {
    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://laexperiencefivem.com';

    $statusColors  = ['accepted'=>'#2ecc71','denied'=>'#e74c3c','reviewing'=>'#3498db'];
    $statusLabels  = ['accepted'=>'Accepted ✓','denied'=>'Not Accepted','reviewing'=>'Under Review'];
    $statusMessages = [
        'accepted' => "Congratulations! Your application for <strong style=\"color:#ffffff\">{$position}</strong> has been <strong style=\"color:#2ecc71\">accepted</strong>. A staff member will be in touch shortly with next steps.",
        'denied'   => "Thank you for your interest. After careful review, your application for <strong style=\"color:#ffffff\">{$position}</strong> was <strong style=\"color:#e74c3c\">not accepted</strong> at this time. You are welcome to reapply in the future.",
        'reviewing' => "Your application for <strong style=\"color:#ffffff\">{$position}</strong> is now <strong style=\"color:#3498db\">under review</strong> by our staff team. We'll update you once a decision has been made.",
    ];

    $color   = $statusColors[$status]  ?? '#888';
    $label   = $statusLabels[$status]  ?? ucfirst($status);
    $message = $statusMessages[$status] ?? "Your application status has been updated to: $status";

    $notesHtml = $notes ? "<div style=\"background:#0d0d0f;border-left:3px solid {$color};padding:12px 16px;margin-top:20px;border-radius:0 6px 6px 0\"><p style=\"color:#888;font-size:12px;margin:0 0 4px;text-transform:uppercase;letter-spacing:1px\">Staff Notes</p><p style=\"color:#cccccc;font-size:14px;margin:0\">{$notes}</p></div>" : '';

    $body = <<<HTML
<h2 style="color:#f0f0f8;font-size:22px;font-weight:700;letter-spacing:0.5px;margin:0 0 8px">Application Update</h2>
<p style="color:#55556a;font-size:12px;text-transform:uppercase;letter-spacing:2px;margin:0 0 28px">Your staff application status has changed</p>

<div style="background:#0a0a0e;border:1px solid #222230;border-radius:8px;padding:18px 22px;margin-bottom:24px">
  <div>
    <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:2px">Status</div>
    <div style="font-size:20px;font-weight:700;color:{$color};margin-top:4px">{$label}</div>
  </div>
</div>

<p style="color:#b8b8cc;font-size:15px;line-height:1.8">
  Hi <strong style="color:#f0f0f8">{$username}</strong>,<br><br>
  {$message}
</p>
{$notesHtml}

<div style="text-align:center;margin:28px 0">
  <a href="{$siteUrl}/forum.php?cat=applications"
     style="display:inline-block;background:#141418;border:1px solid #444455;color:#ccccdd;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:700;font-size:14px">
    View Application
  </a>
</div>
HTML;

    $html    = getEmailWrapper("Application Update · LAE Forums", $body);
    $subject = "Your LAE Application — {$label}";
    $mailer  = new LAEMailer();
    return $mailer->send($toEmail, $username, $subject, $html);
}

/**
 * Send ban appeal status update email
 */
function sendAppealStatusEmail(string $toEmail, string $username, string $status, string $notes = ''): bool {
    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://laexperiencefivem.com';

    $statusColors   = ['accepted'=>'#2ecc71','denied'=>'#e74c3c','reviewing'=>'#3498db'];
    $statusMessages = [
        'accepted' => "Great news — your ban appeal has been <strong style=\"color:#2ecc71\">approved</strong>. Your account has been unbanned and you may now log in and participate in the community again.",
        'denied'   => "After careful review, your ban appeal has been <strong style=\"color:#e74c3c\">denied</strong>. The original ban will remain in place. If you believe this is an error, please contact a senior staff member.",
        'reviewing' => "Your ban appeal is now <strong style=\"color:#3498db\">under review</strong> by the staff team. We'll send you another update once a decision has been reached.",
    ];

    $color   = $statusColors[$status]  ?? '#888';
    $label   = ucfirst($status);
    $message = $statusMessages[$status] ?? "Your appeal status has been updated to: $status";
    $notesHtml = $notes ? "<div style=\"background:#0d0d0f;border-left:3px solid {$color};padding:12px 16px;margin-top:20px;border-radius:0 6px 6px 0\"><p style=\"color:#888;font-size:12px;margin:0 0 4px;text-transform:uppercase;letter-spacing:1px\">Staff Notes</p><p style=\"color:#cccccc;font-size:14px;margin:0\">{$notes}</p></div>" : '';

    $body = <<<HTML
<h2 style="color:#f0f0f8;font-size:22px;font-weight:700;letter-spacing:0.5px;margin:0 0 8px">Ban Appeal Update</h2>
<p style="color:#55556a;font-size:12px;text-transform:uppercase;letter-spacing:2px;margin:0 0 28px">Your ban appeal status has changed</p>

<p style="color:#b8b8cc;font-size:15px;line-height:1.8">
  Hi <strong style="color:#f0f0f8">{$username}</strong>,<br><br>
  {$message}
</p>
{$notesHtml}

<div style="text-align:center;margin:28px 0">
  <a href="{$siteUrl}"
     style="display:inline-block;background:#141418;border:1px solid #444455;color:#ccccdd;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:700;font-size:14px">
    Visit LAE Forums
  </a>
</div>
HTML;

    $html    = getEmailWrapper("Ban Appeal Update · LAE Forums", $body);
    $subject = "Your LAE Ban Appeal — " . ucfirst($status);
    $mailer  = new LAEMailer();
    return $mailer->send($toEmail, $username, $subject, $html);
}

// ── New message notification email ────────────────────────────────────────────
function sendNewMessageEmail(string $toEmail, string $toUsername, string $fromUsername, string $subject, string $preview): bool {
    $preview = mb_substr(strip_tags($preview), 0, 200);
    if (mb_strlen($preview) === 200) $preview .= '…';

    $html = getEmailWrapper("New Message from {$fromUsername}", "
        <h2 style='margin:0 0 8px;font-size:1.4rem'>📬 You have a new message</h2>
        <p style='color:#aaa;margin:0 0 20px'>Hi <strong style='color:#fff'>{$toUsername}</strong>, you received a message on the LAE Forums.</p>
        <div style='background:#1a1a22;border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:16px;margin-bottom:20px'>
            <div style='font-size:0.75rem;letter-spacing:2px;text-transform:uppercase;color:#666;margin-bottom:6px'>From</div>
            <div style='font-weight:700;font-size:1rem;color:#f2f2f4'>{$fromUsername}</div>
            <div style='font-size:0.75rem;letter-spacing:2px;text-transform:uppercase;color:#666;margin:12px 0 6px'>Subject</div>
            <div style='font-weight:700;color:#f2f2f4'>" . htmlspecialchars($subject) . "</div>
            <div style='font-size:0.75rem;letter-spacing:2px;text-transform:uppercase;color:#666;margin:12px 0 6px'>Preview</div>
            <div style='color:#9898a8;font-style:italic;line-height:1.6'>" . htmlspecialchars($preview) . "</div>
        </div>
        <a href='" . SITE_URL . "/messages.php'
           style='display:inline-block;background:#e63946;color:#fff;text-decoration:none;padding:12px 28px;border-radius:3px;font-weight:700;letter-spacing:1px;text-transform:uppercase;font-size:0.85rem'>
            Read Message
        </a>
        <p style='color:#555;font-size:0.8rem;margin-top:20px'>You can manage your notification preferences in your account settings.</p>
    ");

    $text = "Hi {$toUsername},\n\n{$fromUsername} sent you a message on LAE Forums.\n\nSubject: {$subject}\n\nPreview:\n{$preview}\n\nRead it here: " . SITE_URL . "/messages.php";

    $mailer = new LAEMailer();
    return $mailer->send($toEmail, $toUsername, "New message from {$fromUsername} · LAE Forums", $html, $text);
}


// ── Thread reply notification email ───────────────────────────────────────────
function sendThreadReplyEmail(
    string $toEmail,
    string $toUsername,
    string $replierUsername,
    string $threadTitle,
    string $snippet,
    string $threadUrl
): bool {
    $safeTitle    = htmlspecialchars($threadTitle,    ENT_QUOTES, 'UTF-8');
    $safeReplier  = htmlspecialchars($replierUsername, ENT_QUOTES, 'UTF-8');
    $safeSnippet  = htmlspecialchars($snippet,         ENT_QUOTES, 'UTF-8');
    $safeUsername = htmlspecialchars($toUsername,       ENT_QUOTES, 'UTF-8');

    $html = getEmailWrapper("New reply in: {$safeTitle}", "
        <h2 style='margin:0 0 6px;font-size:1.3rem'>💬 New reply on your thread</h2>
        <p style='color:#9898a8;margin:0 0 22px;font-size:0.9rem'>
            Hi <strong style='color:#f2f2f4'>{$safeUsername}</strong> —
            <strong style='color:#f2f2f4'>{$safeReplier}</strong> just replied to a thread you're following.
        </p>

        <div style='background:#0d0d14;border:1px solid rgba(255,255,255,0.08);border-left:3px solid #e63946;border-radius:4px;padding:16px 18px;margin-bottom:22px'>
            <div style='font-size:0.68rem;letter-spacing:2px;text-transform:uppercase;color:#55556a;margin-bottom:8px'>Thread</div>
            <div style='font-weight:700;font-size:1rem;color:#f2f2f4;margin-bottom:12px'>{$safeTitle}</div>
            <div style='font-size:0.68rem;letter-spacing:2px;text-transform:uppercase;color:#55556a;margin-bottom:6px'>Reply preview</div>
            <div style='color:#9898a8;font-size:0.88rem;line-height:1.65;font-style:italic'>&ldquo;{$safeSnippet}&rdquo;</div>
        </div>

        <a href='" . htmlspecialchars($threadUrl, ENT_QUOTES, 'UTF-8') . "'
           style='display:inline-block;background:#e63946;color:#fff;text-decoration:none;
                  padding:11px 26px;border-radius:3px;font-weight:700;
                  letter-spacing:1px;text-transform:uppercase;font-size:0.82rem'>
            Read Reply →
        </a>

        <p style='color:#38384a;font-size:0.75rem;margin-top:22px;line-height:1.6'>
            You received this because you posted in this thread.<br>
            To stop receiving these, you can manage notification preferences in your
            <a href='" . SITE_URL . "/settings.php' style='color:#55556a'>account settings</a>.
        </p>
    ");

    $text = "Hi {$toUsername},\n\n"
          . "{$replierUsername} replied to \"{$threadTitle}\".\n\n"
          . "Preview:\n\"{$snippet}\"\n\n"
          . "Read the reply: {$threadUrl}\n\n"
          . "— Los Angeles Experience Forums";

    $mailer = new LAEMailer();
    return $mailer->send(
        $toEmail,
        $toUsername,
        "{$replierUsername} replied to your thread · LAE Forums",
        $html,
        $text
    );
}
