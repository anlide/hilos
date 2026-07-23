-- Migration: Create hilos_user_verification table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_user_verification.sql)
--
-- Framework-standardized verification challenge (HIL-365). One typed row backs
-- both email-confirmation (register_confirm) and password-recovery
-- (password_reset); email_change is reserved for the Profile-cluster consumer
-- (HIL-298). This replaces the reference stack's four copy-pasted per-flow tables.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary. `user_id` is nullable because a request
-- may be issued before the owning user is resolved; the INDEX on it supports the
-- application-side cascade.
--
-- `identifier` uses utf8mb4_bin so the target email compares exactly; the writing
-- leaf lowercases it before insert.
--
-- `code_hash` (bcrypt hash of the delivered code) is intentionally NOT mapped in
-- the Entity ORM layer (see @object-exclude on the UserVerification entity): it is
-- written and verified only inside the verification layer, so the hash never
-- crosses the object, view, frontend, or cross-worker sync boundary.

CREATE TABLE `hilos_user_verification` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `code_hash` VARCHAR(255) DEFAULT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` TIMESTAMP NOT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_uv_type_identifier` (`type`, `identifier`),
    KEY `idx_uv_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
