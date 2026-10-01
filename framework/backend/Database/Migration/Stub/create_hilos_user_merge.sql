-- Migration: Create hilos_user_merge table (stub/reference).
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_user_merge.sql).
-- A merged account belongs to the framework (HIL-1199): one row per account folded into
-- another - which account, into which, when - written by the sessions library in the
-- transaction of the merge. The row is keyed by the folded account, so an account is
-- folded at most once. The database clears survivor_user_id if the survivor's row is
-- removed first (SET NULL); erasure removes folded accounts before their survivor
-- (HIL-1200), so an empty survivor belongs only to older rows. merged_at is NULL on rows carried
-- over from a project's former column, which never recorded the moment. The keys onto
-- hilos_user are born here (docs/agents/architecture/people-table.md, Foreign Keys Onto
-- The Person).

CREATE TABLE `hilos_user_merge` (
    `user_id` INT UNSIGNED NOT NULL,
    `survivor_user_id` INT UNSIGNED DEFAULT NULL,
    `merged_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`user_id`),
    KEY `idx_user_merge_survivor` (`survivor_user_id`),
    CONSTRAINT `fk_user_merge_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_user_merge_survivor` FOREIGN KEY (`survivor_user_id`) REFERENCES `hilos_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
