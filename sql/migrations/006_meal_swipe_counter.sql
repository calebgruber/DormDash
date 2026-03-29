-- Migration 006: Meal swipe counter for orders
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `meal_swipes_applied` INT NOT NULL DEFAULT 0
  COMMENT 'Number of meal swipes applied by customer at checkout';
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `meal_swipe_credit` DECIMAL(10,2) NOT NULL DEFAULT 0.00
  COMMENT 'Dollar amount covered by meal swipes';

INSERT INTO `app_settings` (`key`, `value`) VALUES
  ('meal_swipe_value', '8.00')
ON DUPLICATE KEY UPDATE `key` = `key`;
