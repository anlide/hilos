-- Migration: Create hilos_oauth_provider table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_oauth_provider.sql)
--
-- Admin-side configuration of one OAuth sign-in provider (HIL-286): one row per
-- provider the project declares, holding what the administrator entered for it.
-- A column left NULL is not configured here, and the provider falls back to its
-- env value and then to its recipe; a row that does not exist means the same for
-- every column. The set of providers is the project's code, not this table.
--
-- `provider_key` uses utf8mb4_bin so lookups are exact / case-sensitive
-- ('oauth:github'), the same reason as the identifier of hilos_identity.
--
-- `client_secret` is intentionally NOT mapped in the Entity ORM layer (see
-- @object-exclude on the OAuthProvider entity): it is written and read only inside
-- the provider layer, so the secret never crosses the object, view, frontend, or
-- cross-worker sync boundary. It is stored as entered; the framework ships no
-- at-rest encryption for it.

CREATE TABLE `hilos_oauth_provider` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider_key` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `client_id` VARCHAR(255) DEFAULT NULL,
    `client_secret` VARCHAR(255) DEFAULT NULL,
    `scope` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_oauth_provider_key` (`provider_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
