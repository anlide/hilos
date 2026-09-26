-- Activates the files registry (HIL-336) for demo/chat; copied from the framework stub
-- create_hilos_file.sql, which explains every column. The published files it registers live
-- in the directory chat attachments are published to today, so moving attachments onto the
-- registry (HIL-144) moves no file.

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
