-- Migration: Create hilos_identity table
-- Created: 2026-08-28
-- Index: 010
-- Description:
--   Activate the framework-owned auth identity table (HIL-160) in this demo, so
--   the users library that HIL-622 owns can resolve an identity by
--   (type, identifier) and verify it. One account may own many identities: a
--   password, a magic link, a phone number, a passkey anchor and one row per
--   OAuth provider all sit here side by side.
--
--   This demo had no auth handler at all until HIL-634; the table arrives with
--   the AUTH feature and nothing else fills it.
--
--   Copied from the framework stub Database/Migration/Stub/create_hilos_identity.sql.
--
-- Framework-standardized auth identity table (HIL-160). Pluggable multi-method:
-- one project-owned `user` may own many identities keyed by (type, identifier).
-- A user may have no password and no email; email/phone are just identifiers.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary. Integrity and delete-cascade of a user's
-- identities are enforced in application code (INDEX on user_id supports it).
--
-- `identifier` uses utf8mb4_bin so lookups are exact / case-sensitive
-- (oauth 'provider:subject', passkey credential-id). Email/phone identifiers are
-- normalized (lowercase / E.164) by the writing leaf before insert.
--
-- `secret` (password hash; NULL for external methods) is intentionally NOT mapped
-- in the Entity ORM layer (see @object-exclude on the Identity entity): it is
-- written and verified only inside the identity layer, so the hash never crosses
-- the object, view, frontend, or cross-worker sync boundary.

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
