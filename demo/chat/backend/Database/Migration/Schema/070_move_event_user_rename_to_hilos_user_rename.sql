-- Migration: Move the rename lines of the feed into the framework rename journal (HIL-1196).
-- Match the chat UserRename entity: the framework's hilos_user_rename plus event_id.
-- Index: 070
--
-- Renaming a person is the framework's: the users library writes the name and a row of
-- hilos_user_rename, and chat links that row to the event of its feed. The journal gets chat's
-- column - the event that shows the rename, one row per event, NULL once the room's history
-- is cleared - and every event_user_rename row moves into it. Chat always recorded who did the
-- rename (the person themselves, or the administrator, NULL when unknown or erased), so
-- actor_user_id lands in renamed_by_user_id as it is; the moment is the event's.

ALTER TABLE `hilos_user_rename`
    ADD COLUMN `event_id` INT UNSIGNED NULL DEFAULT NULL,
    ADD UNIQUE KEY `uk_user_rename_event` (`event_id`),
    ADD CONSTRAINT `fk_user_rename_event` FOREIGN KEY (`event_id`) REFERENCES `event` (`id`) ON DELETE SET NULL;

INSERT INTO `hilos_user_rename` (`user_id`, `renamed_by_user_id`, `old_name`, `new_name`, `renamed_at`, `event_id`)
SELECT r.`target_user_id`, r.`actor_user_id`, r.`old_name`, r.`new_name`, e.`timestamp`, r.`event_id`
FROM `event_user_rename` r
JOIN `event` e ON e.`id` = r.`event_id`
ORDER BY r.`event_id`;

DROP TABLE `event_user_rename`;
