-- Migration: Create hilos_setting table
-- Framework-level settings storage; the settings collection registered by
-- HilosDbContext reads it (copied from framework/backend/Database/Migration/Stub).

CREATE TABLE `hilos_setting` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(100) NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT 'string',
    `value` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
