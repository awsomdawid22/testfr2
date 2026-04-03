-- ============================================================
-- LAE Forums Migration v1.3
-- Adds: Support Staff role
-- Run this if upgrading from an existing installation
-- ============================================================

-- Add support role (between developer priority=40 and police_chief priority=35)
INSERT IGNORE INTO roles 
    (name, display_name, color, badge_color, priority, can_post, can_create_threads, can_moderate, can_admin, can_ban)
VALUES
    ('support', 'Support Staff', '#1abc9c', '#001410', 38, 1, 1, 0, 0, 0);

-- Verify
SELECT id, name, display_name, color, priority FROM roles ORDER BY priority DESC;
