-- Migration: Create user settings table
-- Created: 2025-11-11
-- Index: 003

CREATE TABLE `user_setting` (
    `id_user_setting` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_user` INT UNSIGNED NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT DEFAULT NULL,
    PRIMARY KEY (`id_user_setting`),
    UNIQUE KEY `id_user_key` (`id_user`, `key`),
    KEY `id_user` (`id_user`),
    CONSTRAINT `fk_user_setting_user` FOREIGN KEY (`id_user`) REFERENCES `user` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

