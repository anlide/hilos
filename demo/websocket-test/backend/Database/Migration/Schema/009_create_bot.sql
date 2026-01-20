-- Migration: Create bot and moderator tables
-- Created: 2025-01-XX
-- Index: 009

CREATE TABLE `bot` (
    `id_bot` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `style` VARCHAR(100) DEFAULT NULL COMMENT 'Chat style (e.g., friendly, professional, casual)',
    `topics` TEXT DEFAULT NULL COMMENT 'JSON array of topics the bot can chat about',
    `personality` TEXT DEFAULT NULL COMMENT 'JSON object describing bot personality traits',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_bot`),
    KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `moderator` (
    `id_moderator` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `check_adult_content` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Check for adult content',
    `check_violence` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Check for violence and war topics',
    `check_profanity` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Check for profanity',
    `check_spam` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Check for spam',
    `check_hate_speech` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Check for hate speech',
    `sensitivity_level` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Sensitivity level 1-10 (1=lenient, 10=strict)',
    `additional_rules` TEXT DEFAULT NULL COMMENT 'JSON array of additional moderation rules',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_moderator`),
    KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
