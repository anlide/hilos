-- Migration: Move people to the framework hilos_user table (HIL-1133).
-- Match the create_hilos_user.sql stub.
-- Index: 031

RENAME TABLE `user` TO `hilos_user`;

ALTER TABLE `hilos_user`
    MODIFY COLUMN `last_activity` TIMESTAMP NULL DEFAULT NULL,
    RENAME INDEX `admin` TO `idx_user_admin`,
    RENAME INDEX `block` TO `idx_user_block`,
    RENAME INDEX `last_activity` TO `idx_user_last_activity`;
