-- Migration 003: Application enhancements
-- Error logs, restaurant hours, meal groups, item customizations, support chat,
-- user photos, saved locations, orders text-order support, branding settings.

-- ── Error logging ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `app_error_logs` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `level`      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
  `message`    TEXT NOT NULL,
  `context`    TEXT,
  `url`        VARCHAR(500),
  `user_id`    INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Restaurant enhancements ───────────────────────────────────────────────
ALTER TABLE `restaurants` ADD COLUMN `banner_image`        VARCHAR(255) DEFAULT NULL;
ALTER TABLE `restaurants` ADD COLUMN `allow_text_order`    TINYINT(1)   NOT NULL DEFAULT 0
  COMMENT 'Show text-box ordering option (dining hall style – always 1 meal)';
ALTER TABLE `restaurants` ADD COLUMN `meal_swipe_eligible` TINYINT(1)   NOT NULL DEFAULT 0
  COMMENT 'Orders here can be paid with a meal swipe';

-- ── Restaurant hours (one row per restaurant per day of week) ─────────────
CREATE TABLE IF NOT EXISTS `restaurant_hours` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `restaurant_id` INT       NOT NULL,
  `day_of_week`   TINYINT   NOT NULL COMMENT '0=Sunday 1=Monday … 6=Saturday',
  `open_time`     TIME      DEFAULT NULL,
  `close_time`    TIME      DEFAULT NULL,
  `is_closed`     TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_rest_day` (`restaurant_id`, `day_of_week`),
  FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Menu item enhancements ────────────────────────────────────────────────
ALTER TABLE `menu_items` ADD COLUMN `image_path` VARCHAR(255) DEFAULT NULL;

-- ── Item customization option groups ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `item_option_groups` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `menu_item_id` INT          NOT NULL,
  `name`         VARCHAR(100) NOT NULL COMMENT 'e.g. "Size", "Toppings"',
  `type`         ENUM('single','multiple') NOT NULL DEFAULT 'single',
  `required`     TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Choices within each option group ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `item_option_choices` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `option_group_id` INT           NOT NULL,
  `name`            VARCHAR(100)  NOT NULL COMMENT 'e.g. "Large", "Extra Cheese"',
  `price_modifier`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sort_order`      INT           NOT NULL DEFAULT 0,
  FOREIGN KEY (`option_group_id`) REFERENCES `item_option_groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Order items: store selected customizations as JSON ────────────────────
ALTER TABLE `order_items` ADD COLUMN `options_json` JSON DEFAULT NULL
  COMMENT 'Selected customization choices at time of order';

-- ── Meal groups (collections of items/categories that equal one meal swipe) ─
CREATE TABLE IF NOT EXISTS `meal_groups` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `restaurant_id` INT          NOT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `description`   TEXT,
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Requirements for each meal group ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `meal_group_requirements` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `meal_group_id` INT     NOT NULL,
  `category_id`   INT     DEFAULT NULL COMMENT 'Any item from this category counts',
  `menu_item_id`  INT     DEFAULT NULL COMMENT 'This specific item',
  `min_qty`       TINYINT NOT NULL DEFAULT 1,
  FOREIGN KEY (`meal_group_id`) REFERENCES `meal_groups`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`)   REFERENCES `menu_categories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`menu_item_id`)  REFERENCES `menu_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── User profile enhancements ─────────────────────────────────────────────
ALTER TABLE `users` ADD COLUMN `selfie_path`     VARCHAR(255) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `more_card_photo` VARCHAR(255) DEFAULT NULL;

-- ── Saved delivery locations ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_locations` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT          NOT NULL,
  `label`      VARCHAR(100) NOT NULL,
  `address`    VARCHAR(255) NOT NULL,
  `building`   VARCHAR(100) DEFAULT NULL,
  `room`       VARCHAR(50)  DEFAULT NULL,
  `is_default` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Orders: dining-hall text orders ──────────────────────────────────────
ALTER TABLE `orders` ADD COLUMN `custom_description` TEXT DEFAULT NULL
  COMMENT 'Freeform description for dining-hall text orders';
ALTER TABLE `orders` ADD COLUMN `meal_group_id` INT DEFAULT NULL
  COMMENT 'Meal group applied at checkout (if any)';
ALTER TABLE `orders` MODIFY COLUMN `order_type`
  ENUM('dining_hall','prepaid_pickup','dining_hall_text') NOT NULL DEFAULT 'dining_hall';

-- ── Support messages ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_messages` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `order_id`      INT          NOT NULL,
  `sender_id`     INT          NOT NULL,
  `message`       TEXT         NOT NULL,
  `is_from_admin` TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`       DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`)  REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── New app settings ──────────────────────────────────────────────────────
INSERT INTO `app_settings` (`key`, `value`) VALUES
  ('email_from_address',      'no-reply@purchase.edu'),
  ('email_from_name',         'DormDash'),
  ('app_logo_path',           ''),
  ('app_favicon_path',        ''),
  ('closing_warning_minutes', '45'),
  ('order_cutoff_minutes',    '15')
ON DUPLICATE KEY UPDATE `key` = `key`;
