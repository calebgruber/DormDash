-- Migration 001: Initial schema
-- Creates all core tables for DormDash

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('customer','courier','admin') NOT NULL DEFAULT 'customer',
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `phone` VARCHAR(20),
  `dorm` VARCHAR(100),
  `mor_last4` VARCHAR(4),
  `mor_photo_path` VARCHAR(255),
  `courier_approved` TINYINT(1) NOT NULL DEFAULT 0,
  `stripe_account_id` VARCHAR(100),
  `email_verify_token` VARCHAR(64),
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `restaurants` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('dining_hall','einstein','boba','other') NOT NULL DEFAULT 'dining_hall',
  `location` VARCHAR(200),
  `description` TEXT,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `menu_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `restaurant_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT,
  `base_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `meal_eligible` TINYINT(1) NOT NULL DEFAULT 1,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (`category_id`) REFERENCES `menu_categories`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `restaurant_id` INT NOT NULL,
  `order_type` ENUM('dining_hall','prepaid_pickup') NOT NULL DEFAULT 'dining_hall',
  `status` ENUM('open','accepted','card_collected','food_collected','delivered','cancelled') NOT NULL DEFAULT 'open',
  `food_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `service_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tip_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `stripe_payment_intent_id` VARCHAR(100),
  `stripe_checkout_session_id` VARCHAR(100),
  `courier_id` INT,
  `mor_payment_type` VARCHAR(50),
  `boost_order_number` VARCHAR(100),
  `customer_name` VARCHAR(100),
  `delivery_address` VARCHAR(255),
  `customer_notes` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`),
  FOREIGN KEY (`courier_id`) REFERENCES `users`(`id`)
);

CREATE TABLE IF NOT EXISTS `order_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `menu_item_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `customizations_text` TEXT,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`)
);

CREATE TABLE IF NOT EXISTS `order_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `type` ENUM('placed','accepted','card_collected','food_collected','delivered','cancelled') NOT NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `courier_id` INT,
  `note` TEXT,
  `photo_path` VARCHAR(255),
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`courier_id`) REFERENCES `users`(`id`)
);

CREATE TABLE IF NOT EXISTS `courier_applications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
);

CREATE TABLE IF NOT EXISTS `payment_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `delivery_fee_type` ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  `delivery_fee_value` DECIMAL(10,2) NOT NULL DEFAULT 2.00,
  `service_fee_type` ENUM('flat','percent') NOT NULL DEFAULT 'percent',
  `service_fee_value` DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  `tip_suggestions` JSON NOT NULL
);

INSERT INTO `payment_config` (`delivery_fee_type`, `delivery_fee_value`, `service_fee_type`, `service_fee_value`, `tip_suggestions`)
VALUES ('flat', 2.00, 'percent', 5.00, '[0.10, 0.15, 0.20, 0.25]')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO `restaurants` (`name`, `type`, `location`, `description`, `active`) VALUES
('The Dining Hall', 'dining_hall', 'Main Building', 'Main campus dining hall with daily rotating menu', 1),
('Einstein Bros Bagels', 'einstein', 'Student Center', 'Bagels, sandwiches, coffee and more', 1),
('Boba Tea Co', 'boba', 'Student Center', 'Bubble tea and Asian-inspired drinks', 1)
ON DUPLICATE KEY UPDATE id = id;
