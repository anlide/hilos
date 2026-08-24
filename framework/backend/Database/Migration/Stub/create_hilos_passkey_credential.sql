-- Migration: Create hilos_passkey_credential table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_passkey_credential.sql)
--
-- WebAuthn/passkey credential sidecar (HIL-284). A passkey is a thin
-- `hilos_identity` anchor row (type=passkey, identifier=credential_id, secret=NULL,
-- verified=1) plus one crypto row here. Splitting the crypto off the identity table
-- keeps the identity contract lean and shared across all auth methods while this
-- table carries the WebAuthn-specific material.
--
-- No DB-level foreign key to `hilos_identity` or the project `user` table: framework
-- stubs never FK across the framework/project boundary. `identity_id` links the
-- anchor row and `user_id` is denormalized for the per-user credential list
-- (HIL-404) and the resident-key resolution (HIL-400); both carry an INDEX for the
-- application-side cascade.
--
-- `credential_id` (the authenticator's base64url credential id) uses utf8mb4_bin so
-- it compares exactly and is UNIQUE across the table (an assertion resolves the row
-- by it). `public_key` holds the credential's public key as PEM (converted once from
-- the COSE key at registration); it is public material, not a secret. `user_handle`
-- is the WebAuthn user handle (random_bytes, one per user, reused across a user's
-- passkeys) provisioned now so usernameless/discoverable login (HIL-400) needs no
-- migration. `label` and `last_used_at` are seeded for passkey management (HIL-404).
--
-- `algorithm` is the COSE identifier of the signature suite the key was enrolled
-- with (HIL-658), and it is stored rather than derived because the key material
-- does not carry it: for RSA the PKCS1 and PSS schemes share one kind of key, so a
-- PEM alone cannot say which one a login must check against. It has no DEFAULT on
-- purpose — a caller that forgot to pass it should fail loudly instead of quietly
-- registering as ES256. SMALLINT is signed, which the COSE identifiers need: they
-- run from -7 down past -257.

CREATE TABLE `hilos_passkey_credential` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identity_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `credential_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `public_key` TEXT NOT NULL,
    `algorithm` SMALLINT NOT NULL,
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
