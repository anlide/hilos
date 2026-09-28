-- Migration: Move people to the framework hilos_user table (HIL-1133).
-- Match the create_hilos_user.sql stub; chat keeps its own merge column.
-- Index: 064

RENAME TABLE `user` TO `hilos_user`;

ALTER TABLE `hilos_user`
    MODIFY COLUMN `name` VARCHAR(255) NOT NULL,
    RENAME INDEX `admin` TO `idx_user_admin`,
    RENAME INDEX `block` TO `idx_user_block`,
    RENAME INDEX `last_activity` TO `idx_user_last_activity`;
