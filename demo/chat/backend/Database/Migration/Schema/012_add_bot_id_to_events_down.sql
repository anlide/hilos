-- Migration Rollback: Add bot_id column to event table
-- Created: 2026-03-01
-- Index: 012

ALTER TABLE `event`
    DROP FOREIGN KEY `fk_event_bot`,
    DROP KEY `bot_id`,
    DROP COLUMN `bot_id`;
