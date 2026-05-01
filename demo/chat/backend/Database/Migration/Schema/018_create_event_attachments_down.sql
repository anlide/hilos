-- Migration Rollback: Move chat event attachments to event JSON
-- Index: 018
-- Notes: legacy file_shared event types are not restored; attachments are folded into message_sent data.

UPDATE `event` e
JOIN (
    SELECT
        `event_id`,
        JSON_ARRAYAGG(JSON_OBJECT(
            'originalFilename', `filename`,
            'mimeType', `mime_type`,
            'storedName', `stored_name`,
            'downloadToken', SUBSTRING_INDEX(`stored_name`, '.', 1)
        )) AS `attachments`
    FROM `event_attachment`
    GROUP BY `event_id`
) a ON a.`event_id` = e.`id`
SET e.`data` = JSON_SET(
    CASE
        WHEN e.`data` IS NOT NULL AND JSON_VALID(e.`data`) THEN e.`data`
        ELSE JSON_OBJECT('message', '')
    END,
    '$.attachments',
    a.`attachments`
)
WHERE e.`type` = 'message_sent';

DROP TABLE `event_attachment`;
