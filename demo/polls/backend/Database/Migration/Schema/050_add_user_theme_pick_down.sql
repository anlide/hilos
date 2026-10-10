-- Rollback: Remove the person's theme choice (HIL-1427)
-- Created: 2026-10-09
-- Index: 050

ALTER TABLE `hilos_user`
    DROP COLUMN `theme_pick`;
