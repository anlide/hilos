DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_legal_acceptance` WHERE `accepted_at` < 1000 OR `accepted_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legal acceptance moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_legal_acceptance` ADD COLUMN `__hil1925_accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `accepted_at`;
    UPDATE `hilos_legal_acceptance` SET `__hil1925_accepted_at` = FROM_UNIXTIME(FLOOR(`accepted_at` / 1000));
    ALTER TABLE `hilos_legal_acceptance`
        DROP INDEX `idx_legal_acceptance_document_revision`,
        DROP INDEX `idx_legal_acceptance_accepted_at`,
        DROP COLUMN `accepted_at`,
        CHANGE COLUMN `__hil1925_accepted_at` `accepted_at` TIMESTAMP NOT NULL,
        ADD KEY `idx_legal_acceptance_document_revision` (`document`, `revision_id`, `accepted_at`),
        ADD KEY `idx_legal_acceptance_accepted_at` (`accepted_at`);
END$$
DELIMITER ;
