-- Migration: Create session table
-- Created: 2026-07-18
-- Index: 021
-- Description:
--   Separate session (transient, cookie-token, may be anonymous) from user
--   (durable account). A session is anonymous (user_id NULL) or authenticated
--   (user_id bound after login/register). Replaces the fused legacy model where
--   the session token lived as a unique column on `user` (dropped in 022).

-- No DB-level foreign key to `user`: session→user integrity is enforced in
-- application code (the bind action), matching the identity-layer convention and
-- keeping the anonymous-vs-authenticated split independent of the user row.

CREATE TABLE `session` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token` VARCHAR(64) NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `token` (`token`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
