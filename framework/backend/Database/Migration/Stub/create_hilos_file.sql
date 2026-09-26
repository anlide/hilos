-- Migration: Create hilos_file table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_file.sql)
--
-- Registry of published files (HIL-131, HIL-336). One row is one file kept in the
-- project's files directory under `stored_name`. A row is born unbound (`bound` = 0);
-- once the project has written its own link to the file it calls
-- Hilos::$files->markBound(), and the files library flips the flag. The library's
-- janitor removes unbound rows older than the `files.unbound_ttl_hours` setting,
-- the row first and the file on disk after it.
--
-- `owner_user_id` is a soft reference without a foreign key only because the person
-- table still belongs to the project (epic HIL-1133).
--
-- `stored_name` is the name on disk, so it compares byte for byte (utf8mb4_bin): a
-- filesystem tells case apart. `filename` is the name the uploader gave the file.
--
-- `visibility` has no DEFAULT on purpose: who may be given the file is the
-- publisher's to say, and a default would decide the access for it.

CREATE TABLE `hilos_file` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `stored_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(255) NOT NULL,
    `size` BIGINT UNSIGNED NOT NULL,
    `owner_user_id` INT UNSIGNED NOT NULL,
    `visibility` ENUM('public', 'authenticated', 'owner') NOT NULL,
    `bound` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_file_stored_name` (`stored_name`),
    KEY `idx_file_bound_created` (`bound`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
