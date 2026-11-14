ALTER TABLE `hilos_legal_acceptance` ADD COLUMN `__hil1925_accepted_at` BIGINT NULL AFTER `accepted_at`;
UPDATE `hilos_legal_acceptance` SET `__hil1925_accepted_at` = UNIX_TIMESTAMP(`accepted_at`) * 1000;
ALTER TABLE `hilos_legal_acceptance`
    DROP INDEX `idx_legal_acceptance_document_revision`,
    DROP INDEX `idx_legal_acceptance_accepted_at`,
    DROP COLUMN `accepted_at`,
    CHANGE COLUMN `__hil1925_accepted_at` `accepted_at` BIGINT NOT NULL,
    ADD KEY `idx_legal_acceptance_document_revision` (`document`, `revision_id`, `accepted_at`),
    ADD KEY `idx_legal_acceptance_accepted_at` (`accepted_at`);
