ALTER TABLE `hilos_identity`
    ADD COLUMN `password_changed_at` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `verified`,
    ADD COLUMN `provider_name` TEXT NULL AFTER `password_changed_at`;
