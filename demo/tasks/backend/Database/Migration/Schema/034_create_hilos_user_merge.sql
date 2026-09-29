-- Migration: Create the framework hilos_user_merge table (HIL-1199).
-- Match the create_hilos_user_merge.sql stub.
-- Index: 034
--
-- The merge table is required by the framework sign-in feature: the framework asks it whether
-- an account was folded into another one before it grants rights, blocks, schedules a deletion
-- or names the administrators. Account merge is not wired in this demo, so the table stays empty.

CREATE TABLE `hilos_user_merge` (
    `user_id` INT UNSIGNED NOT NULL,
    `survivor_user_id` INT UNSIGNED DEFAULT NULL,
    `merged_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`user_id`),
    KEY `idx_user_merge_survivor` (`survivor_user_id`),
    CONSTRAINT `fk_user_merge_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_user_merge_survivor` FOREIGN KEY (`survivor_user_id`) REFERENCES `hilos_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
