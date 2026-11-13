ALTER TABLE `hilos_account_deletion`
    ADD COLUMN `started_at` BIGINT NULL DEFAULT NULL AFTER `effective_at`;
