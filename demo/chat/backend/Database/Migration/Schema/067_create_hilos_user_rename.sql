-- Migration: Create the framework hilos_user_rename table (HIL-1195).
-- Match the create_hilos_user_rename.sql stub.
-- Index: 067
--
-- The rename journal is required by the framework sign-in feature: the users library writes it.
-- This demo still renames through its own handler and its event_user_rename rows; they move
-- here with HIL-1196, so the table starts empty.

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
