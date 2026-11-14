-- hilos-framework: {"format":1,"kind":"change","units":["legal_acceptance"],"parts":[{"changeId":"20261114014747194725_HIL-1925_export_legal_moments","unit":"legal_acceptance","upSha256":"36b5e2f4e383725c91c18584fe3e516e567c35f96c7420a8a5d30f2cb07de278","downSha256":"1ce360988dc5916d94a4591732a11dde107163751eb6a4aedb76fd8692bbabad"}],"upSha256":"36b5e2f4e383725c91c18584fe3e516e567c35f96c7420a8a5d30f2cb07de278","downSha256":"1ce360988dc5916d94a4591732a11dde107163751eb6a4aedb76fd8692bbabad"}
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
