<?php
/**
 * Maintenance Mode Page
 * Displayed when maintenance_mode is enabled in site settings
 */

// Get site settings for display
$siteName = $siteSettings['site_name'] ?? 'Los Angeles Experience';
$discordUrl = $siteSettings['discord_url'] ?? '#';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode - <?= htmlspecialchars($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --accent: #d4af37;
            --accent-hover: #c9a227;
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --border: rgba(255,255,255,0.08);
            --red: #e74c3c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Rajdhani', sans-serif;
            background: var(--bg0);
            color: var(--t0);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        /* Background Effects */
        .bg-grid {
            position: fixed;
            inset: 0;
            background-image: 
                linear-gradient(rgba(212, 175, 55, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(212, 175, 55, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
            z-index: 0;
        }
        
        .bg-glow {
            position: fixed;
            top: -50%;
            left: 50%;
            transform: translateX(-50%);
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.08) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }
        
        .container {
            position: relative;
            z-index: 1;
            text-align: center;
            padding: 40px;
            max-width: 600px;
        }
        
        .logo {
            width: 180px;
            height: auto;
            margin-bottom: 30px;
            filter: drop-shadow(0 0 20px rgba(212, 175, 55, 0.3));
        }
        
        .icon {
            font-size: 4rem;
            color: var(--accent);
            margin-bottom: 20px;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }
        
        h1 {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 3rem;
            letter-spacing: 3px;
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--accent), #fff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            font-size: 1.2rem;
            color: var(--t0);
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .status-box {
            background: var(--bg1);
            border: 1px solid var(--b0);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid rgba(231, 76, 60, 0.3);
            border-radius: 50px;
            color: var(--red);
            font-weight: 600;
            font-size: 0.95rem;
        }
        
        .status-indicator i {
            animation: blink 1.5s ease-in-out infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        
        .info-text {
            color: var(--t0);
            font-size: 0.95rem;
            margin-top: 15px;
        }
        
        .buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-family: 'Rajdhani', sans-serif;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-accent {
            background: var(--accent);
            color: #000;
        }
        
        .btn-accent:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(212, 175, 55, 0.3);
        }
        
        .btn-ghost {
            background: transparent;
            color: var(--t0);
            border: 1px solid var(--b0);
        }
        
        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--accent);
        }
        
        .admin-notice {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--b0);
        }
        
        .admin-notice p {
            color: var(--t0);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        
        .admin-notice a {
            color: var(--accent);
            text-decoration: none;
        }
        
        .admin-notice a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="bg-grid"></div>
    <div class="bg-glow"></div>
    
    <div class="container">
        <img src="<?= SITE_URL ?>/public/images/LARPWhite.png" alt="<?= htmlspecialchars($siteName) ?>" class="logo">
        
        <div class="icon">
            <i class="fas fa-wrench"></i>
        </div>
        
        <h1>Under Maintenance</h1>
        
        <p class="subtitle">
            We're currently performing scheduled maintenance to improve your experience.
            Please check back shortly.
        </p>
        
        <div class="status-box">
            <div class="status-indicator">
                <i class="fas fa-circle"></i>
                Maintenance In Progress
            </div>
            <p class="info-text">
                Our team is working hard to bring you new features and improvements.
                Thank you for your patience!
            </p>
        </div>
        
        <div class="buttons">
            <?php if ($discordUrl && $discordUrl !== '#'): ?>
            <a href="<?= htmlspecialchars($discordUrl) ?>" class="btn btn-accent" target="_blank">
                <i class="fab fa-discord"></i> Join Discord
            </a>
            <?php endif; ?>
            <button onclick="location.reload()" class="btn btn-ghost">
                <i class="fas fa-rotate"></i> Refresh Page
            </button>
        </div>
        
        <div class="admin-notice">
            <p>Staff member? <a href="<?= SITE_URL ?>/login.php">Login here</a> to access the site.</p>
        </div>
    </div>
</body>
</html>
