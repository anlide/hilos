-- Rollback: Return the rename journal to the demo user_rename table.
-- Index: 033
--
-- The shape of 003_create_user_rename.sql, its key now on hilos_user. Who did a rename has
-- no column there and is lost, and a name longer than the old 64 characters is cut to it:
-- a rollback with a loss of precision, accepted.

CREATE TABLE `user_rename` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_user_id` INT UNSIGNED NOT NULL,
    `old_name` VARCHAR(64) NOT NULL,
    `new_name` VARCHAR(64) NOT NULL,
    `timestamp` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `target_user_id` (`target_user_id`),
    KEY `timestamp` (`timestamp`),
    CONSTRAINT `fk_user_rename_target_user`
        FOREIGN KEY (`target_user_id`) REFERENCES `hilos_user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `user_rename` (`id`, `target_user_id`, `old_name`, `new_name`, `timestamp`)
SELECT `id`, `user_id`, LEFT(`old_name`, 64), LEFT(`new_name`, 64), `renamed_at`
FROM `hilos_user_rename`
ORDER BY `id`;

DROP TABLE `hilos_user_rename`;
