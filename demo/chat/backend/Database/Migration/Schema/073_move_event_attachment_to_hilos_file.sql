-- Migration: Move chat attachments onto the files registry (HIL-144)
-- Created: 2026-10-02
-- Index: 073
--
-- An attachment is now a link from an event to a row of hilos_file: the registry keeps the
-- name, the type and the file itself, and event_attachment keeps only which file belongs to
-- which message. file_id holds the row without a cascade on purpose - the database refusing
-- to remove a file some attachment still names is how the files library learns the chat
-- still links it.
--
-- Every attachment published before becomes a bound registry row of its own under the same
-- stored_name: the files directory of the registry is the directory these files were
-- published into, so no file moves. The owner is the author of the message (0 when the author
-- is gone), and the files are given to anyone signed in, as the registry's chat files are.
-- size is 0 and content_hash 64 zeros: a migration is SQL only and cannot read a file, and a
-- fingerprint of zeros matches no real one, so the duplicate check never refuses a new file
-- over these. They live until the next history cleanup, which runs every 30 minutes.
--
-- The chat's own total limit becomes the registry's: a stored value of
-- chat_attachment_max_total_bytes moves to files.max_total_bytes unless that key is stored
-- already, and the old key goes.

INSERT INTO `hilos_file` (`stored_name`, `filename`, `mime_type`, `size`, `content_hash`, `owner_user_id`, `visibility`, `bound`)
SELECT ea.`stored_name`, ea.`filename`, ea.`mime_type`, 0, REPEAT('0', 64), COALESCE(em.`author_user_id`, 0), 'authenticated', 1
FROM `event_attachment` ea
JOIN `event_message` em ON em.`event_id` = ea.`event_id`
ORDER BY ea.`id`;

ALTER TABLE `event_attachment`
    ADD COLUMN `file_id` INT UNSIGNED NULL AFTER `event_id`;

UPDATE `event_attachment` ea
JOIN `hilos_file` f ON f.`stored_name` = ea.`stored_name` COLLATE utf8mb4_bin
SET ea.`file_id` = f.`id`;

ALTER TABLE `event_attachment`
    DROP KEY `stored_name`,
    DROP COLUMN `filename`,
    DROP COLUMN `mime_type`,
    DROP COLUMN `stored_name`,
    MODIFY `file_id` INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY `uk_event_attachment_file` (`file_id`),
    ADD CONSTRAINT `fk_event_attachment_file` FOREIGN KEY (`file_id`) REFERENCES `hilos_file` (`id`);

UPDATE `hilos_setting`
SET `key` = 'files.max_total_bytes'
WHERE `key` = 'chat_attachment_max_total_bytes'
    AND NOT EXISTS (
        SELECT 1 FROM (SELECT `id` FROM `hilos_setting` WHERE `key` = 'files.max_total_bytes') AS `stored`
    );

DELETE FROM `hilos_setting` WHERE `key` = 'chat_attachment_max_total_bytes';
