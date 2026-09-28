-- Rollback: Return people to the demo user table.

ALTER TABLE `hilos_user`
    MODIFY COLUMN `last_activity` DATETIME NULL DEFAULT NULL,
    RENAME INDEX `idx_user_admin` TO `admin`,
    RENAME INDEX `idx_user_block` TO `block`,
    RENAME INDEX `idx_user_last_activity` TO `last_activity`;

RENAME TABLE `hilos_user` TO `user`;
