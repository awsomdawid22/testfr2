-- ============================================================
-- LAE Forum Migration: v1.1 → v1.2
-- Run this on EXISTING installations only.
-- New installs: use database.sql directly.
-- ============================================================

-- Per-category role permissions table
CREATE TABLE IF NOT EXISTS category_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    role_id INT NOT NULL,
    can_post TINYINT(1) DEFAULT 1,
    can_create_threads TINYINT(1) DEFAULT 1,
    UNIQUE KEY unique_cat_role (category_id, role_id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

-- Example: Lock Announcements so only Owner/Co-Owner/Head Admin can post/create threads
-- (Uncomment and adjust role IDs to match your setup)
-- INSERT INTO category_permissions (category_id, role_id, can_post, can_create_threads)
-- SELECT c.id, r.id, 1, 1
-- FROM categories c, roles r
-- WHERE c.slug = 'announcements' AND r.name IN ('owner','co_owner','head_admin');

-- OAuth state table for Discord linking (avoids SameSite=Strict session issues)
CREATE TABLE IF NOT EXISTS oauth_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state VARCHAR(64) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    provider VARCHAR(20) DEFAULT 'discord',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_state (state),
    INDEX idx_created (created_at)
);
