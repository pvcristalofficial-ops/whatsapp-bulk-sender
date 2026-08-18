-- WhatsApp Bulk Sender Pro Database Schema
-- Compatible with MySQL 5.7+ and 8.0+

CREATE DATABASE IF NOT EXISTS `whatsapp_bulk_sender` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `whatsapp_bulk_sender`;

-- Admins Table
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contacts Table
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(30) NOT NULL UNIQUE,
  `city` VARCHAR(100) DEFAULT NULL,
  `course` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Templates Table
CREATE TABLE IF NOT EXISTS `templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `language` VARCHAR(10) NOT NULL DEFAULT 'en_US',
  `category` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `meta_template_id` VARCHAR(255) DEFAULT NULL,
  `header_variables` TEXT DEFAULT NULL,
  `body_variables` TEXT DEFAULT NULL,
  `footer_text` VARCHAR(255) DEFAULT NULL,
  `buttons_json` TEXT DEFAULT NULL,
  `components_json` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_meta_template_id` (`meta_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campaigns Table
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `template_id` INT NOT NULL,
  `status` ENUM('Pending', 'Sending', 'Paused', 'Completed') DEFAULT 'Pending',
  `scheduled_at` DATETIME DEFAULT NULL,
  `total_contacts` INT DEFAULT 0,
  `sent_count` INT DEFAULT 0,
  `delivered_count` INT DEFAULT 0,
  `read_count` INT DEFAULT 0,
  `failed_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campaign Contacts (Queue/Status mapping)
CREATE TABLE IF NOT EXISTS `campaign_contacts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT NOT NULL,
  `contact_id` INT NOT NULL,
  `status` ENUM('Pending', 'Sent', 'Delivered', 'Read', 'Failed') DEFAULT 'Pending',
  `message_id` VARCHAR(255) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `idx_campaign_contact` (`campaign_id`, `contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Logs Table
CREATE TABLE IF NOT EXISTS `logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT DEFAULT NULL,
  `contact_id` INT DEFAULT NULL,
  `request_payload` LONGTEXT DEFAULT NULL,
  `response_payload` LONGTEXT DEFAULT NULL,
  `status` ENUM('Success', 'Failed') DEFAULT 'Success',
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed Messages Retry Table
CREATE TABLE IF NOT EXISTS `failed_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `campaign_contact_id` INT NOT NULL,
  `error_message` TEXT DEFAULT NULL,
  `retry_count` INT DEFAULT 0,
  `last_attempt_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`campaign_contact_id`) REFERENCES `campaign_contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for performance
CREATE INDEX `idx_contacts_phone` ON `contacts` (`phone`);
CREATE INDEX `idx_campaign_contacts_status` ON `campaign_contacts` (`status`);
CREATE INDEX `idx_campaign_contacts_msg_id` ON `campaign_contacts` (`message_id`);
CREATE INDEX `idx_logs_campaign_id` ON `logs` (`campaign_id`);

-- Seed Default Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('access_token', ''),
('phone_number_id', ''),
('business_account_id', ''),
('api_version', 'v23.0'),
('webhook_verify_token', 'whatsapp_verify_token_123')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- Seed Initial Admin (Password: admin123)
-- bcrypt hash of admin123
INSERT INTO `admins` (`email`, `password`, `name`) VALUES
('admin@example.com', '$2y$10$nHdzLdfPRFRnz0GNvniL6.HASxf1fgAvI1Bm6gnqL0R9s6tEmMfZu', 'Administrator')
ON DUPLICATE KEY UPDATE `email` = `email`;
