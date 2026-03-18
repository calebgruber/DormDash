-- Migration 005: Login banner and additional app settings
INSERT INTO `app_settings` (`key`, `value`) VALUES
  ('login_banner_path', '')
ON DUPLICATE KEY UPDATE `key` = `key`;
