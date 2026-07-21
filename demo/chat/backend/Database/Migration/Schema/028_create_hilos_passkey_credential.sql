-- Migration: Create hilos_passkey_credential table
-- Created: 2026-07-21
-- Index: 028
-- Description:
--   Activate the framework-owned passkey/WebAuthn credential sidecar (HIL-284) in
--   the chat demo so passkey register/login can store and resolve a credential.
--   A passkey is a thin `hilos_identity` anchor row (type=passkey,
--   identifier=credential_id, secret=NULL, verified=1) plus one crypto row here.
--   Copied from the framework stub Database/Migration/Stub/create_hilos_passkey_credential.sql.
--
-- No DB-level foreign key to `hilos_identity` or `user`: identity→user and
-- credential→identity integrity is enforced in application code (INDEX on
-- identity_id / user_id supports it), matching the identity/session convention.
--
-- `credential_id` (the authenticator's base64url credential id) uses utf8mb4_bin so
-- it compares exactly and is UNIQUE across the table (an assertion resolves the row
-- by it). `public_key` holds the credential's public key as PEM (converted once from
-- the COSE key at registration); it is public material, not a secret. `user_handle`
-- is the WebAuthn user handle (one per user, reused across a user's passkeys)
-- provisioned now so usernameless/discoverable login (HIL-400) needs no migration.
-- `label` and `last_used_at` are seeded for passkey management (HIL-404).

CREATE TABLE `hilos_passkey_credential` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identity_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `credential_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `public_key` TEXT NOT NULL,
    `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `transports` VARCHAR(255) DEFAULT NULL,
    `aaguid` VARCHAR(36) DEFAULT NULL,
    `user_handle` VARBINARY(64) NOT NULL,
    `label` VARCHAR(100) DEFAULT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_passkey_credential_id` (`credential_id`),
    KEY `idx_passkey_identity` (`identity_id`),
    KEY `idx_passkey_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
