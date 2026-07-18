-- Migration: Create hilos_identity table
-- Created: 2026-07-18
-- Index: 022
-- Description:
--   Activate the framework-owned auth identity table (HIL-160) in the chat demo
--   so email+password login (HIL-162) can resolve a `password`-type identity by
--   (type, identifier=email) and verify it. One user may own many identities.
--   Copied from the framework stub Database/Migration/Stub/create_hilos_identity.sql.
--
-- No DB-level foreign key to `user`: identity→user integrity is enforced in
-- application code (INDEX on user_id supports it), matching the session-table
-- convention. `identifier` uses utf8mb4_bin for exact/case-sensitive lookups;
-- email identifiers are lowercased by the writing leaf before insert.
--
-- `secret` (password hash; NULL for external methods) is NOT ORM-mapped (see
-- @object-exclude on the Identity entity): it is written and verified only
-- inside the identity layer, so the hash never crosses the object, view,
-- frontend, or cross-worker sync boundary.

CREATE TABLE `hilos_identity` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `type` ENUM('password', 'oauth', 'magic_link', 'sms', 'passkey') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `secret` VARCHAR(255) DEFAULT NULL,
    `provider` VARCHAR(100) DEFAULT NULL,
    `verified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_identity_type_identifier` (`type`, `identifier`),
    KEY `idx_identity_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
