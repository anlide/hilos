ALTER TABLE `hilos_access_log` ADD COLUMN `__hil1924_occurred_at` BIGINT NULL AFTER `occurred_at`;
UPDATE `hilos_access_log` SET `__hil1924_occurred_at` = UNIX_TIMESTAMP(`occurred_at`) * 1000;
ALTER TABLE `hilos_access_log`
    DROP INDEX `idx_access_log_user`,
    DROP INDEX `idx_access_log_occurred`,
    DROP COLUMN `occurred_at`,
    CHANGE COLUMN `__hil1924_occurred_at` `occurred_at` BIGINT NOT NULL,
    ADD KEY `idx_access_log_user` (`user_id`, `occurred_at`),
    ADD KEY `idx_access_log_occurred` (`occurred_at`);
