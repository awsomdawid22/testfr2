-- Advanced Moderation System Database Migration
-- Run this script to add all necessary tables and columns for the moderation system

-- ============================================
-- User Sessions Tracking
-- ============================================
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    country VARCHAR(100),
    city VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- IP History Tracking
-- ============================================
CREATE TABLE IF NOT EXISTS ip_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    action VARCHAR(50) NOT NULL, -- 'login', 'register', 'post', 'password_change', etc.
    user_agent TEXT,
    country VARCHAR(100),
    city VARCHAR(100),
    is_vpn TINYINT(1) DEFAULT 0,
    is_proxy TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AutoMod Action Log
-- ============================================
CREATE TABLE IF NOT EXISTS automod_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action_type VARCHAR(50) NOT NULL, -- 'warn', 'mute', 'flag', 'delete', 'ban'
    reason TEXT NOT NULL,
    content_type VARCHAR(50), -- 'post', 'thread', 'profile', 'email', etc.
    content_id INT,
    content_preview TEXT, -- Preview of flagged content
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    was_appealed TINYINT(1) DEFAULT 0,
    reviewed_by INT,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_severity (severity),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Content Filters (Banned words/domains/patterns)
-- ============================================
CREATE TABLE IF NOT EXISTS content_filters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('word', 'domain', 'pattern', 'username') NOT NULL,
    value VARCHAR(255) NOT NULL,
    action ENUM('block', 'flag', 'warn', 'shadow') DEFAULT 'block',
    reason VARCHAR(255),
    added_by INT,
    is_active TINYINT(1) DEFAULT 1,
    match_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_filter (type, value),
    INDEX idx_type (type),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Linked Accounts Detection
-- ============================================
CREATE TABLE IF NOT EXISTS linked_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id_1 INT NOT NULL,
    user_id_2 INT NOT NULL,
    link_type ENUM('same_ip', 'similar_email', 'same_device', 'manual') NOT NULL,
    confidence_score INT DEFAULT 50, -- 0-100
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmed_by INT,
    confirmed_at TIMESTAMP NULL,
    is_false_positive TINYINT(1) DEFAULT 0,
    notes TEXT,
    INDEX idx_user_id_1 (user_id_1),
    INDEX idx_user_id_2 (user_id_2),
    INDEX idx_link_type (link_type),
    FOREIGN KEY (user_id_1) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id_2) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Staff Notes on Users
-- ============================================
CREATE TABLE IF NOT EXISTS staff_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    author_id INT NOT NULL,
    note TEXT NOT NULL,
    is_pinned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_author_id (author_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Add new columns to users table
-- ============================================
-- Registration IP
ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_ip VARCHAR(45) AFTER email_verified;

-- Moderation status flags
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_muted TINYINT(1) DEFAULT 0 AFTER is_banned;
ALTER TABLE users ADD COLUMN IF NOT EXISTS muted_until TIMESTAMP NULL AFTER is_muted;
ALTER TABLE users ADD COLUMN IF NOT EXISTS mute_reason VARCHAR(255) AFTER muted_until;

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_flagged TINYINT(1) DEFAULT 0 AFTER mute_reason;
ALTER TABLE users ADD COLUMN IF NOT EXISTS flag_reason VARCHAR(255) AFTER is_flagged;

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_trusted TINYINT(1) DEFAULT 0 AFTER flag_reason;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_restricted TINYINT(1) DEFAULT 0 AFTER is_trusted;
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_locked TINYINT(1) DEFAULT 0 AFTER is_restricted;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lock_reason VARCHAR(255) AFTER account_locked;

-- Risk and quality scores
ALTER TABLE users ADD COLUMN IF NOT EXISTS risk_score INT DEFAULT 0 AFTER lock_reason;
ALTER TABLE users ADD COLUMN IF NOT EXISTS trust_score INT DEFAULT 50 AFTER risk_score;

-- Security tracking
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_ip VARCHAR(45) AFTER trust_score;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_user_agent TEXT AFTER last_ip;
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INT DEFAULT 0 AFTER last_user_agent;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_failed_login TIMESTAMP NULL AFTER failed_login_attempts;

-- Force actions
ALTER TABLE users ADD COLUMN IF NOT EXISTS force_password_reset TINYINT(1) DEFAULT 0 AFTER last_failed_login;
ALTER TABLE users ADD COLUMN IF NOT EXISTS force_logout TINYINT(1) DEFAULT 0 AFTER force_password_reset;

-- Add indexes for new columns
CREATE INDEX IF NOT EXISTS idx_is_muted ON users(is_muted);
CREATE INDEX IF NOT EXISTS idx_is_flagged ON users(is_flagged);
CREATE INDEX IF NOT EXISTS idx_is_trusted ON users(is_trusted);
CREATE INDEX IF NOT EXISTS idx_risk_score ON users(risk_score);
CREATE INDEX IF NOT EXISTS idx_registration_ip ON users(registration_ip);
CREATE INDEX IF NOT EXISTS idx_last_ip ON users(last_ip);

-- ============================================
-- AutoMod Settings (stored in site_settings but defining defaults here)
-- ============================================
-- These will be managed through the settings.php file, but we'll insert defaults
-- This is handled by the PHP code, not SQL

-- ============================================
-- Insert default content filters
-- ============================================
INSERT IGNORE INTO content_filters (type, value, action, reason) VALUES
('word', 'nigger', 'block', 'Racial slur'),
('word', 'nigga', 'block', 'Racial slur'),
('word', 'faggot', 'block', 'Homophobic slur'),
('word', 'kike', 'block', 'Antisemitic slur'),
('word', 'spic', 'block', 'Ethnic slur'),
('word', 'chink', 'block', 'Racial slur'),
('word', 'tranny', 'block', 'Transphobic slur'),
('word', 'retard', 'flag', 'Ableist slur'),
('word', 'femboy', 'block', 'Inappropriate term'),
('word', 'polski', 'block', 'Blocked term'),
('domain', 'tempmail.com', 'block', 'Disposable email'),
('domain', 'guerrillamail.com', 'block', 'Disposable email'),
('domain', 'mailinator.com', 'block', 'Disposable email'),
('domain', '10minutemail.com', 'block', 'Disposable email'),
('domain', 'yopmail.com', 'block', 'Disposable email'),
('domain', 'throwaway.email', 'block', 'Disposable email');
