-- Migration: Store a person's theme choice on the framework user row (HIL-1427)
-- Created: 2026-10-09
-- Index: 050
--
-- Registration saves the guest browser's choice with the new account. Later edits
-- are written by that person's agent; null means the person never chose a theme.

ALTER TABLE `hilos_user`
    ADD COLUMN `theme_pick` ENUM('light', 'dark', 'system') NULL DEFAULT NULL AFTER `last_activity`;
