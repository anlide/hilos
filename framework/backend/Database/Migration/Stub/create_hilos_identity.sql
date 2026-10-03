-- Migration: Create hilos_identity table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_identity.sql)
--
-- Framework-standardized auth identity table (HIL-160). Pluggable multi-method:
-- one person may own many identities keyed by (type, identifier).
-- A user may have no password and no email; email/phone are just identifiers.
--
-- The key onto hilos_user is RESTRICT: account erasure deletes identities in its
-- transaction before the person row. The user_id index supports that lookup.
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
    KEY `idx_identity_user` (`user_id`),
    CONSTRAINT `fk_identity_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
