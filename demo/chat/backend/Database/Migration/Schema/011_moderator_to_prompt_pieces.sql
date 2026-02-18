-- Migration: Replace moderator table with moderator_prompt_piece
-- Created: 2025-02-18
-- Index: 011
-- Description:
--   - Drop moderator table
--   - Create moderator_prompt_piece (id, section, prompt_piece)

DROP TABLE IF EXISTS `moderator`;

CREATE TABLE `moderator_prompt_piece` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `section` ENUM('name_rule', 'message_rule') NOT NULL,
    `prompt_piece` TEXT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
