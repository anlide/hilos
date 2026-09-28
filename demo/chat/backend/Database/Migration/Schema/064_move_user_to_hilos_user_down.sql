-- Rollback: Return people to the demo user table.

-- The development rollback restores the former name limit.
UPDATE `hilos_user`
SET `name` = LEFT(`name`, 64)
WHERE CHAR_LENGTH(`name`) > 64;

ALTER TABLE `hilos_user`
    MODIFY COLUMN `name` VARCHAR(64) NOT NULL,
    RENAME INDEX `idx_user_admin` TO `admin`,
    RENAME INDEX `idx_user_block` TO `block`,
    RENAME INDEX `idx_user_last_activity` TO `last_activity`;

RENAME TABLE `hilos_user` TO `user`;
