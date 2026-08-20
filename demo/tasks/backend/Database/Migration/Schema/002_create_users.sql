-- Migration: Create user table
-- Created: 2026-06-20
-- Index: 002
-- Description:
--   Durable user identity for the tasks demo: the monopolistic tasks
--   worker registers a user per session token on handshake and reuses it on
--   reconnect, replacing the former agent-local ephemeral identity.
--   The admin/block operator flags back the Hilos users admin table activated
--   in a later slice; presence is computed at runtime, never stored.

CREATE TABLE `user` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `admin` TINYINT(1) NOT NULL DEFAULT 0,
    `block` TINYINT(1) NOT NULL DEFAULT 0,
    `session_token` VARCHAR(32) DEFAULT NULL,
    `last_activity` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `session_token` (`session_token`),
    KEY `admin` (`admin`),
    KEY `block` (`block`),
    KEY `last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
