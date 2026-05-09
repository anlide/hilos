-- Migration Rollback: Fold scalar event detail tables back into event.data
-- Index: 019

ALTER TABLE `event`
    ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
    ADD COLUMN `bot_id` INT UNSIGNED NULL DEFAULT NULL AFTER `user_id`,
    ADD COLUMN `data` TEXT NULL DEFAULT NULL AFTER `timestamp`,
    ADD KEY `user_id` (`user_id`),
    ADD KEY `bot_id` (`bot_id`),
    ADD CONSTRAINT `fk_event_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_event_bot` FOREIGN KEY (`bot_id`) REFERENCES `bot` (`id`) ON DELETE SET NULL;

UPDATE `event` e
JOIN `event_message` m ON m.`event_id` = e.`id`
SET
    e.`user_id` = m.`author_user_id`,
    e.`bot_id` = m.`author_bot_id`,
    e.`data` = JSON_OBJECT('message', m.`message`)
WHERE e.`type` = 'message_sent';

UPDATE `event` e
JOIN `event_user_registration` r ON r.`event_id` = e.`id`
SET e.`user_id` = r.`target_user_id`
WHERE e.`type` = 'user_registered';

UPDATE `event` e
JOIN `event_user_rename` r ON r.`event_id` = e.`id`
SET
    e.`user_id` = r.`target_user_id`,
    e.`data` = CASE
        WHEN e.`type` = 'user_renamed_by_admin'
            THEN JSON_OBJECT('oldName', r.`old_name`, 'newName', r.`new_name`, 'adminUserId', r.`actor_user_id`)
        ELSE JSON_OBJECT('oldName', r.`old_name`, 'newName', r.`new_name`)
    END
WHERE e.`type` IN ('user_renamed', 'user_renamed_by_admin');

ALTER TABLE `event_attachment`
    DROP FOREIGN KEY `fk_event_attachment_event_message`,
    ADD CONSTRAINT `fk_event_attachment_event` FOREIGN KEY (`event_id`) REFERENCES `event` (`id`) ON DELETE CASCADE;

DROP TABLE `event_user_rename`;
DROP TABLE `event_user_registration`;
DROP TABLE `event_message`;

ALTER TABLE `user`
    MODIFY COLUMN `name` VARCHAR(255) NOT NULL;
