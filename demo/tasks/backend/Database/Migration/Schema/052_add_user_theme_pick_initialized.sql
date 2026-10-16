-- Migration: Distinguish a first theme adoption from an explicit reset (HIL-1534)
-- Created: 2026-10-16
-- Index: 052

ALTER TABLE `hilos_user`
    ADD COLUMN `theme_pick_initialized` BOOLEAN NOT NULL DEFAULT 0 AFTER `theme_pick`;

UPDATE `hilos_user`
SET `theme_pick_initialized` = 1
WHERE `theme_pick` IS NOT NULL;
