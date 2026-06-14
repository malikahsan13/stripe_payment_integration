-- ============================================
-- Stripe Payment Integration Tables
-- ============================================

-- Main table for storing payment transactions
CREATE TABLE IF NOT EXISTS `stripe_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `patient_id` VARCHAR(50) NOT NULL,
  `patient_name` VARCHAR(255) NOT NULL,
  `patient_email` VARCHAR(255) DEFAULT NULL,
  `claim_id` VARCHAR(50) DEFAULT NULL,
  `encounter_id` VARCHAR(50) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) DEFAULT 'USD',
  `status` VARCHAR(50) DEFAULT 'pending',
  `stripe_payment_intent_id` VARCHAR(255) DEFAULT NULL,
  `stripe_payment_method_id` VARCHAR(255) DEFAULT NULL,
  `stripe_charge_id` VARCHAR(255) DEFAULT NULL,
  `stripe_session_id` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `metadata` TEXT DEFAULT NULL,
  `failure_reason` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `paid_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_patient_id` (`patient_id`),
  KEY `idx_claim_id` (`claim_id`),
  KEY `idx_encounter_id` (`encounter_id`),
  KEY `idx_stripe_payment_intent_id` (`stripe_payment_intent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for storing payment logs (for debugging and audit trail)
CREATE TABLE IF NOT EXISTS `stripe_payment_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `payment_id` INT(11) DEFAULT NULL,
  `log_type` VARCHAR(50) NOT NULL,
  `log_message` TEXT NOT NULL,
  `request_data` TEXT DEFAULT NULL,
  `response_data` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_log_type` (`log_type`),
  KEY `idx_created_at` (`created_at`),
  FOREIGN KEY (`payment_id`) REFERENCES `stripe_payments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for storing Stripe webhook events
CREATE TABLE IF NOT EXISTS `stripe_webhook_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `stripe_event_id` VARCHAR(255) NOT NULL UNIQUE,
  `event_type` VARCHAR(100) NOT NULL,
  `payment_intent_id` VARCHAR(255) DEFAULT NULL,
  `event_data` TEXT NOT NULL,
  `processed` TINYINT(1) DEFAULT 0,
  `processed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_stripe_event_id` (`stripe_event_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_payment_intent_id` (`payment_intent_id`),
  KEY `idx_processed` (`processed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert a record for configuration (can also use bt_app_properties)
CREATE TABLE IF NOT EXISTS `stripe_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `config_key` VARCHAR(100) NOT NULL UNIQUE,
  `config_value` TEXT NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default configuration keys (values to be updated by admin)
INSERT INTO `stripe_config` (`config_key`, `config_value`, `description`) VALUES
('stripe_publishable_key', '', 'Stripe Publishable Key (pk_...)'),
('stripe_secret_key', '', 'Stripe Secret Key (sk_...)'),
('stripe_webhook_secret', '', 'Stripe Webhook Secret (whsec_...)'),
('stripe_success_url', '', 'URL to redirect after successful payment'),
('stripe_cancel_url', '', 'URL to redirect after cancelled payment'),
('stripe_currency', 'USD', 'Default currency for payments')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`);
