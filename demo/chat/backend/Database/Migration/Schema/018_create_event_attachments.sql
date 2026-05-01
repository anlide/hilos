-- Migration: Move chat event attachments to a dedicated table
-- Created: 2026-05-01
-- Index: 018

CREATE TABLE `event_attachment` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id` INT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `event_id` (`event_id`),
    UNIQUE KEY `stored_name` (`stored_name`),
    CONSTRAINT `fk_event_attachment_event` FOREIGN KEY (`event_id`) REFERENCES `event` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `event_attachment` (`event_id`, `filename`, `mime_type`, `stored_name`)
SELECT
    `id`,
    COALESCE(
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.originalFilename')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.filename')), ''),
        'file'
    ),
    COALESCE(
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.mimeType')), ''),
        'application/octet-stream'
    ),
    JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.storedName'))
FROM `event`
WHERE
    `type` = 'file_shared'
    AND `data` IS NOT NULL
    AND JSON_VALID(`data`)
    AND JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.storedName')) IS NOT NULL
    AND JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.storedName')) <> '';

INSERT INTO `event_attachment` (`event_id`, `filename`, `mime_type`, `stored_name`)
SELECT
    e.`id`,
    COALESCE(NULLIF(j.`original_filename`, ''), NULLIF(j.`filename`, ''), 'file'),
    COALESCE(NULLIF(j.`mime_type`, ''), 'application/octet-stream'),
    j.`stored_name`
FROM `event` e
JOIN JSON_TABLE(
    CASE
        WHEN e.`data` IS NOT NULL AND JSON_VALID(e.`data`) THEN e.`data`
        ELSE '{"attachments":[]}'
    END,
    '$.attachments[*]'
    COLUMNS (
        `original_filename` VARCHAR(255) PATH '$.originalFilename' NULL ON EMPTY NULL ON ERROR,
        `filename` VARCHAR(255) PATH '$.filename' NULL ON EMPTY NULL ON ERROR,
        `mime_type` VARCHAR(255) PATH '$.mimeType' NULL ON EMPTY NULL ON ERROR,
        `stored_name` VARCHAR(255) PATH '$.storedName' NULL ON EMPTY NULL ON ERROR
    )
) j
WHERE
    e.`type` = 'message_sent'
    AND j.`stored_name` IS NOT NULL
    AND j.`stored_name` <> '';

UPDATE `event`
SET `data` = JSON_REMOVE(`data`, '$.attachments')
WHERE
    `type` = 'message_sent'
    AND `data` IS NOT NULL
    AND JSON_VALID(`data`)
    AND JSON_CONTAINS_PATH(`data`, 'one', '$.attachments');

UPDATE `event`
SET
    `type` = 'message_sent',
    `data` = JSON_OBJECT('message', '')
WHERE `type` = 'file_shared';
