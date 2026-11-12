ALTER TABLE `hilos_second_factor_trust`
    ADD COLUMN `granted_at` BIGINT NOT NULL DEFAULT 0 AFTER `created_at`;

UPDATE `hilos_second_factor_trust` SET `granted_at` = `created_at`;

ALTER TABLE `hilos_second_factor_trust`
    MODIFY COLUMN `granted_at` BIGINT NOT NULL;
