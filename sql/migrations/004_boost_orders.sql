-- Migration 004: Boost orders, pending order status, support typing indicator

-- ── Orders: add 'pending' status for orders awaiting payment ─────────────
-- Default changes to 'pending' so newly inserted orders are not shown to
-- couriers until Stripe payment is confirmed and status is set to 'open'.
ALTER TABLE `orders`
  MODIFY COLUMN `status`
    ENUM('pending','open','accepted','card_collected','food_collected','delivered','cancelled')
    NOT NULL DEFAULT 'pending';

-- Fix existing orders stuck in open+pending (Stripe SDK was missing; webhook never fired).
-- Mark payment as paid so couriers can see and accept them.
UPDATE `orders`
SET `payment_status` = 'paid'
WHERE `status` = 'open'
  AND `payment_status` = 'pending'
  AND `stripe_checkout_session_id` IS NULL;

-- ── Orders: boost order support ───────────────────────────────────────────
ALTER TABLE `orders`
  ADD COLUMN `boost_payment_method` ENUM('in_person','boost_app') DEFAULT NULL
    COMMENT 'How the customer paid for their Boost order';

ALTER TABLE `orders`
  MODIFY COLUMN `order_type`
    ENUM('dining_hall','prepaid_pickup','dining_hall_text','boost_order')
    NOT NULL DEFAULT 'dining_hall';

-- ── Support: typing indicator ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_typing` (
  `order_id`  INT        NOT NULL,
  `is_admin`  TINYINT(1) NOT NULL DEFAULT 0,
  `typed_at`  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`, `is_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

