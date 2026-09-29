-- Rollback: Bring the rename lines of the feed back out of the framework rename journal.
-- Index: 070
--
-- Every journal row a feed event shows goes back to event_user_rename and leaves the journal.
-- A row with no event - its line was cleared with the room's history - stays in the journal:
-- it is the person's history, and a rollback has no right to erase it. Names longer than the
-- former column are cut to it.

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
    CONSTRAINT `fk_event_user_rename_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `hilos_user` (`id`),
    CONSTRAINT `fk_event_user_rename_actor_user` FOREIGN KEY (`actor_user_id`) REFERENCES `hilos_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `event_user_rename` (`event_id`, `target_user_id`, `actor_user_id`, `old_name`, `new_name`)
SELECT `event_id`, `user_id`, `renamed_by_user_id`, LEFT(`old_name`, 64), LEFT(`new_name`, 64)
FROM `hilos_user_rename`
WHERE `event_id` IS NOT NULL
ORDER BY `event_id`;

DELETE FROM `hilos_user_rename` WHERE `event_id` IS NOT NULL;

ALTER TABLE `hilos_user_rename`
    DROP FOREIGN KEY `fk_user_rename_event`,
    DROP INDEX `uk_user_rename_event`,
    DROP COLUMN `event_id`;
