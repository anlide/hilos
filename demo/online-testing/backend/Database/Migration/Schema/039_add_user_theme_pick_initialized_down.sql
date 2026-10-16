-- Rollback: Remove only the account theme initialization flag (HIL-1534)
-- Created: 2026-10-16
-- Index: 039

ALTER TABLE `hilos_user`
    DROP COLUMN `theme_pick_initialized`;
