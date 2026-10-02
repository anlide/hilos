-- Migration rollback: Move chat attachments onto the files registry (HIL-144)
-- Index: 073
--
-- The name, the type and the stored name come back onto event_attachment from the registry
-- rows it linked, and those rows go - the files stay where they are, in the directory the chat
-- published them into. Their image copies go with the rows (the copies' foreign key cascades);
-- the copies' files are left on disk. The registry's total limit returns to the chat's key.

ALTER TABLE `event_attachment`
    ADD COLUMN `filename` VARCHAR(255) NULL AFTER `file_id`,
    ADD COLUMN `mime_type` VARCHAR(255) NULL AFTER `filename`,
    ADD COLUMN `stored_name` VARCHAR(255) NULL AFTER `mime_type`;

UPDATE `event_attachment` ea
JOIN `hilos_file` f ON f.`id` = ea.`file_id`
SET ea.`filename` = f.`filename`, ea.`mime_type` = f.`mime_type`, ea.`stored_name` = f.`stored_name`;

ALTER TABLE `event_attachment`
    DROP FOREIGN KEY `fk_event_attachment_file`;

DELETE f FROM `hilos_file` f
JOIN `event_attachment` ea ON ea.`file_id` = f.`id`;

ALTER TABLE `event_attachment`
    DROP KEY `uk_event_attachment_file`,
    DROP COLUMN `file_id`,
    MODIFY `filename` VARCHAR(255) NOT NULL,
    MODIFY `mime_type` VARCHAR(255) NOT NULL,
    MODIFY `stored_name` VARCHAR(255) NOT NULL,
    ADD UNIQUE KEY `stored_name` (`stored_name`);

UPDATE `hilos_setting`
SET `key` = 'chat_attachment_max_total_bytes'
WHERE `key` = 'files.max_total_bytes';
