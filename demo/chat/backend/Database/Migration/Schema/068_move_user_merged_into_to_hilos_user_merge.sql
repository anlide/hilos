-- Migration: Move the merged-account marker to the framework hilos_user_merge table (HIL-1199).
-- Match the create_hilos_user_merge.sql stub.
-- Index: 068
--
-- A merged account is the framework's now: the sessions library writes a row of hilos_user_merge
-- in the merge transaction, and asks it whether an account was folded into another one. Every
-- account the demo's merged_into column marked is carried over. The column never recorded the
-- moment, so merged_at is NULL on each of them; a survivor erased since leaves survivor_user_id
-- NULL - the key onto hilos_user would not stand otherwise, and the account stays merged.
-- Then the column and its index go.

CREATE TABLE `hilos_user_merge` (
    `user_id` INT UNSIGNED NOT NULL,
    `survivor_user_id` INT UNSIGNED DEFAULT NULL,
    `merged_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`user_id`),
    KEY `idx_user_merge_survivor` (`survivor_user_id`),
    CONSTRAINT `fk_user_merge_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_user_merge_survivor` FOREIGN KEY (`survivor_user_id`) REFERENCES `hilos_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`)
SELECT u.`id`, s.`id`, NULL
FROM `hilos_user` u
LEFT JOIN `hilos_user` s ON s.`id` = u.`merged_into`
WHERE u.`merged_into` IS NOT NULL;

ALTER TABLE `hilos_user`
    DROP INDEX `merged_into`,
    DROP COLUMN `merged_into`;
