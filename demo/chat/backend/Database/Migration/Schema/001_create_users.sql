-- Migration: Create user table
-- Created: 2025-11-11
-- Index: 001

CREATE TABLE `user` (
    `id_user` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `password_hash` VARCHAR(64) NOT NULL,
    `salt` VARCHAR(32) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `theme` VARCHAR(50) DEFAULT NULL,
    `admin` TINYINT(1) NOT NULL DEFAULT 0,
    `block` TINYINT(1) NOT NULL DEFAULT 0,
    `will_delete` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id_user`),
    KEY `admin` (`admin`),
    KEY `block` (`block`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

