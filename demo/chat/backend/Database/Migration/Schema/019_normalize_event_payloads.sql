-- Migration: Normalize chat event payloads into scalar detail tables
-- Created: 2026-05-09
-- Index: 019

UPDATE `user`
SET `name` = LEFT(`name`, 64)
WHERE CHAR_LENGTH(`name`) > 64;

ALTER TABLE `user`
    MODIFY COLUMN `name` VARCHAR(64) NOT NULL;

CREATE TABLE `event_message` (
    `event_id` INT UNSIGNED NOT NULL,
    `author_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `author_bot_id` INT UNSIGNED NULL DEFAULT NULL,
    `message` TEXT NOT NULL,
    PRIMARY KEY (`event_id`),
    KEY `author_user_id` (`author_user_id`),
    KEY `author_bot_id` (`author_bot_id`),
    CONSTRAINT `fk_event_message_event` FOREIGN KEY (`event_id`) REFERENCES `event` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_event_message_user` FOREIGN KEY (`author_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_event_message_bot` FOREIGN KEY (`author_bot_id`) REFERENCES `bot` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `event_user_registration` (
    `event_id` INT UNSIGNED NOT NULL,
    `target_user_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`event_id`),
    KEY `target_user_id` (`target_user_id`),
    CONSTRAINT `fk_event_user_registration_event` FOREIGN KEY (`event_id`) REFERENCES `event` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_event_user_registration_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `event_user_rename` (
    `event_id` INT UNSIGNED NOT NULL,
    `target_user_id` INT UNSIGNED NOT NULL,
    `actor_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `old_name` VARCHAR(64) NOT NULL,
    `new_name` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`event_id`),
    KEY `target_user_id` (`target_user_id`),
    KEY `actor_user_id` (`actor_user_id`),
    CONSTRAINT `fk_event_user_rename_event` FOREIGN KEY (`event_id`) REFERENCES `event` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_event_user_rename_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `user` (`id`),
    CONSTRAINT `fk_event_user_rename_actor_user` FOREIGN KEY (`actor_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `event_message` (`event_id`, `author_user_id`, `author_bot_id`, `message`)
SELECT
    `id`,
    `user_id`,
    `bot_id`,
    CASE
        WHEN `data` IS NOT NULL AND JSON_VALID(`data`) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.message')), '')
        ELSE ''
    END
FROM `event`
WHERE `type` = 'message_sent';

INSERT INTO `event_user_registration` (`event_id`, `target_user_id`)
SELECT `id`, `user_id`
FROM `event`
WHERE `type` = 'user_registered'
    AND `user_id` IS NOT NULL;

INSERT INTO `event_user_rename` (`event_id`, `target_user_id`, `actor_user_id`, `old_name`, `new_name`)
SELECT
    `id`,
    `user_id`,
    CASE
        WHEN `type` = 'user_renamed' THEN `user_id`
        WHEN `data` IS NOT NULL AND JSON_VALID(`data`)
            THEN NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.adminUserId')) AS UNSIGNED), 0)
        ELSE NULL
    END,
    LEFT(
        CASE
            WHEN `data` IS NOT NULL AND JSON_VALID(`data`) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.oldName')), '')
            ELSE ''
        END,
        64
    ),
    LEFT(
        CASE
            WHEN `data` IS NOT NULL AND JSON_VALID(`data`) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.newName')), '')
            ELSE ''
        END,
        64
    )
FROM `event`
WHERE `type` IN ('user_renamed', 'user_renamed_by_admin')
    AND `user_id` IS NOT NULL;

ALTER TABLE `event_attachment`
    DROP FOREIGN KEY `fk_event_attachment_event`,
    ADD CONSTRAINT `fk_event_attachment_event_message` FOREIGN KEY (`event_id`) REFERENCES `event_message` (`event_id`) ON DELETE CASCADE;

ALTER TABLE `event`
    DROP FOREIGN KEY `fk_event_user`,
    DROP FOREIGN KEY `fk_event_bot`,
    DROP KEY `user_id`,
    DROP KEY `bot_id`,
    DROP COLUMN `user_id`,
    DROP COLUMN `bot_id`,
    DROP COLUMN `data`;
