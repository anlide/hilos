-- Rollback: Bring the merged_into column back from the framework merge table.
-- Index: 068
--
-- A row whose survivor was erased has no survivor to point at, so that account comes back
-- un-merged - a loss of the rollback, like the neighbouring moves to framework tables.

ALTER TABLE `hilos_user`
    ADD COLUMN `merged_into` INT UNSIGNED DEFAULT NULL AFTER `block`,
    ADD KEY `merged_into` (`merged_into`);

UPDATE `hilos_user` u
JOIN `hilos_user_merge` m ON m.`user_id` = u.`id`
SET u.`merged_into` = m.`survivor_user_id`;

DROP TABLE IF EXISTS `hilos_user_merge`;
