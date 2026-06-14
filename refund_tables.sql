-- ============================================
-- Stripe Refund System Tables
-- ============================================

-- Main table for refund requests
CREATE TABLE IF NOT EXISTS `stripe_refund_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `payment_id` INT(11) NOT NULL,
  `payment_intent_id` VARCHAR(255) NOT NULL,
  `charge_id` VARCHAR(255) DEFAULT NULL,
  `original_amount` DECIMAL(10,2) NOT NULL,
  `refund_amount` DECIMAL(10,2) NOT NULL,
  `refund_type` ENUM('full', 'partial') DEFAULT 'partial',
  `reason` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'processing', 'completed', 'failed') DEFAULT 'pending',
  `requested_by` VARCHAR(100) DEFAULT NULL,
  `reviewed_by` VARCHAR(100) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `stripe_refund_id` VARCHAR(255) DEFAULT NULL,
  `failure_reason` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_payment_intent_id` (`payment_intent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `idx_created_at` (`created_at`),
  FOREIGN KEY (`payment_id`) REFERENCES `stripe_payments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add refund_amount column to stripe_payments if not exists
ALTER TABLE `stripe_payments`
ADD COLUMN IF NOT EXISTS `refunded_amount` DECIMAL(10,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS `refundable_amount` DECIMAL(10,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS `total_refunds` INT(11) DEFAULT 0,
ADD COLUMN IF NOT EXISTS `last_refund_date` DATETIME DEFAULT NULL;

-- ============================================
-- Insert sample refund reasons (can be customized)
-- ============================================

CREATE TABLE IF NOT EXISTS `stripe_refund_reasons` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `reason_code` VARCHAR(50) NOT NULL,
  `reason_text` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `display_order` INT(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_reason_code` (`reason_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `stripe_refund_reasons` (`reason_code`, `reason_text`, `display_order`) VALUES
('duplicate_payment', 'Duplicate payment', 1),
('service_cancelled', 'Service cancelled', 2),
('service_not_rendered', 'Service not rendered', 3),
('customer_request', 'Customer request - Other', 4),
('billing_error', 'Billing error', 5),
('price_adjustment', 'Price adjustment', 6),
('insurance_adjustment', 'Insurance adjustment', 7),
('other', 'Other (requires notes)', 99)
ON DUPLICATE KEY UPDATE `reason_text` = VALUES(`reason_text`);
