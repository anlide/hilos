-- Migration: Create hilos_user_photo table (stub/reference).
-- Copy this SQL to a project migration. One row belongs to one person, and the
-- registry file stays protected by a foreign key until the photo row is removed.

CREATE TABLE `hilos_user_photo` (
    `user_id` INT UNSIGNED NOT NULL,
    `file_id` INT UNSIGNED NOT NULL,
    `set_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uk_user_photo_file` (`file_id`),
    CONSTRAINT `fk_user_photo_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_user_photo_file` FOREIGN KEY (`file_id`) REFERENCES `hilos_file` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
