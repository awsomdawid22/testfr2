-- ============================================================
-- LAE Forums Migration v1.5
-- Remember-me, multi-roles, profile banner, view dedup
-- ============================================================

CREATE TABLE IF NOT EXISTS remember_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token_hash),
    INDEX idx_user  (user_id)
);

CREATE TABLE IF NOT EXISTS thread_views (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    thread_id  INT NOT NULL,
    viewer_key VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_view (thread_id, viewer_key),
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS banner VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS extra_roles TEXT DEFAULT NULL;

SELECT 'Migration v1.5 complete.' AS status;
