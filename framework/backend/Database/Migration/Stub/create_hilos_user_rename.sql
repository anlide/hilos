-- Migration: Create hilos_user_rename table (stub/reference).
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_user_rename.sql).
-- The rename journal of a person belongs to the framework (HIL-1195): one row per rename,
-- written by the people library in the same transaction as hilos_user.name.
-- renamed_by_user_id is the person who did the rename - the renamed person's own id when
-- they renamed themselves, NULL when the author is not a person (the system, a console).
-- Rows carried over from a project's former journal have NULL there: it never recorded
-- the author. An author whose account is erased leaves the row standing with NULL
-- (SET NULL); the row is the renamed person's history. The keys onto hilos_user are born
-- here (docs/agents/architecture/people-table.md, Foreign Keys Onto The Person).

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
