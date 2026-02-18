-- Migration Rollback: Replace moderator_prompt_piece with moderator table
-- Created: 2025-02-18
-- Index: 011

DROP TABLE IF EXISTS `moderator_prompt_piece`;

CREATE TABLE `moderator` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `check_adult_content` TINYINT(1) NOT NULL DEFAULT 1,
    `check_violence` TINYINT(1) NOT NULL DEFAULT 1,
    `check_profanity` TINYINT(1) NOT NULL DEFAULT 1,
    `check_spam` TINYINT(1) NOT NULL DEFAULT 1,
    `check_hate_speech` TINYINT(1) NOT NULL DEFAULT 1,
    `sensitivity_level` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `additional_rules` TEXT DEFAULT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
