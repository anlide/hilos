-- Migration: Add bot_id column to event table
-- Created: 2026-03-01
-- Index: 012
-- Description: Allow events to be authored by bots (user_id or bot_id, mutually exclusive for authored events)

ALTER TABLE `event`
    ADD COLUMN `bot_id` INT UNSIGNED NULL DEFAULT NULL AFTER `user_id`,
    ADD KEY `bot_id` (`bot_id`),
    ADD CONSTRAINT `fk_event_bot` FOREIGN KEY (`bot_id`) REFERENCES `bot` (`id`) ON DELETE SET NULL;
