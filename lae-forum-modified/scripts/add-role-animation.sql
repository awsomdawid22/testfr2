-- Add animation column to roles table
ALTER TABLE roles ADD COLUMN animation VARCHAR(50) DEFAULT NULL AFTER badge_color;
