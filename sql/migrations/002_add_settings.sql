-- Migration 002: Add app_settings table
-- Stores key/value application configuration including theme preference

CREATE TABLE IF NOT EXISTS `app_settings` (
  `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
  `value`      TEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `app_settings` (`key`, `value`) VALUES
  ('theme',    'light'),
  ('app_name', 'DormDash')
ON DUPLICATE KEY UPDATE `key` = `key`;
