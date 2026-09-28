-- Migration: Create hilos_user table (stub/reference).
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_user.sql).
-- A person belongs to the framework. Projects add columns by extending the whole
-- ORM chain under users (docs/agents/orm/inheritance.md).

CREATE TABLE `hilos_user` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `admin` TINYINT(1) NOT NULL DEFAULT 0,
    `block` TINYINT(1) NOT NULL DEFAULT 0,
    `last_activity` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_admin` (`admin`),
    KEY `idx_user_block` (`block`),
    KEY `idx_user_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
