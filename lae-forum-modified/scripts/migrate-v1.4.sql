-- ============================================================
-- LAE Forums Migration v1.4 — Moderation System
-- Run this on your existing database to add infraction tables
-- ============================================================

CREATE TABLE IF NOT EXISTS infractions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    infraction_id VARCHAR(12) NOT NULL UNIQUE,
    type         ENUM('ban','kick','warn','note') NOT NULL,
    player_name  VARCHAR(100) NOT NULL,
    player_identifier VARCHAR(100),
    player_discord_id VARCHAR(30) DEFAULT NULL,
    reason       TEXT NOT NULL,
    duration     VARCHAR(50) DEFAULT NULL,
    expires_at   DATETIME DEFAULT NULL,
    issued_by_id INT DEFAULT NULL,
    issued_by_name VARCHAR(100) NOT NULL,
    issued_via   ENUM('website','discord') DEFAULT 'website',
    is_active    TINYINT(1) DEFAULT 1,
    removed_by   VARCHAR(100) DEFAULT NULL,
    removed_at   DATETIME DEFAULT NULL,
    remove_reason TEXT DEFAULT NULL,
    notes        TEXT DEFAULT NULL,
    discord_msg_id VARCHAR(30) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issued_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_player_name (player_name),
    INDEX idx_identifier  (player_identifier),
    INDEX idx_type        (type),
    INDEX idx_active      (is_active)
);

CREATE TABLE IF NOT EXISTS infraction_seq (
    id INT NOT NULL DEFAULT 0
);
INSERT IGNORE INTO infraction_seq VALUES (0);

SELECT 'Migration v1.4 complete — moderation system ready.' AS status;
