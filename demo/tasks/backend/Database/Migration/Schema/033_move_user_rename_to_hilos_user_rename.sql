-- Migration: Move the rename journal to the framework hilos_user_rename table (HIL-1195).
-- Match the create_hilos_user_rename.sql stub.
-- Index: 033
--
-- Renaming a person is the framework's now: the users library writes the name and one row of
-- hilos_user_rename in one transaction. The demo's own user_rename rows are carried over under
-- their ids. They never recorded who did the rename, so renamed_by_user_id is NULL on every
-- one of them - the owner's word on 2026-09-28: "Пофиг на старые строки" (never mind the
-- old rows).

CREATE TABLE `hilos_user_rename` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `renamed_by_user_id` INT UNSIGNED DEFAULT NULL,
    `old_name` VARCHAR(255) NOT NULL,
    `new_name` VARCHAR(255) NOT NULL,
    `renamed_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_rename_user` (`user_id`),
    KEY `idx_user_rename_renamed_by` (`renamed_by_user_id`),
    CONSTRAINT `fk_user_rename_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_user_rename_renamed_by` FOREIGN KEY (`renamed_by_user_id`) REFERENCES `hilos_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `hilos_user_rename` (`id`, `user_id`, `renamed_by_user_id`, `old_name`, `new_name`, `renamed_at`)
SELECT `id`, `target_user_id`, NULL, `old_name`, `new_name`, `timestamp`
FROM `user_rename`
ORDER BY `id`;

DROP TABLE `user_rename`;
