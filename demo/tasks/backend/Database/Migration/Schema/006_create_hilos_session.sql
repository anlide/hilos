-- Migration: Move the session token off the user row onto hilos_session
-- Created: 2026-08-16
-- Index: 006
-- Description:
--   HIL-407 moves this demo onto the framework session layer (HIL-361). The
--   cookie token stops being a column on the project `user` row and becomes a
--   first-class session row with its own lifetime, rotation and impersonation
--   support. The framework-standardized table is created verbatim from the
--   framework stub (utf8mb4_bin token, framework index names), every live
--   token is carried over as an authenticated session of the user that held
--   it, and the column and its unique index are dropped.
--
--   The carry-over keeps people signed in across the migration: a browser
--   presenting its old cookie resolves to the session row minted here and
--   finds the same user behind it. `created_at` is stamped now because the
--   user row never recorded when its token was issued; `last_seen_at` takes
--   the user's last activity when there is one. `expires_at` stays NULL —
--   open-ended, which is what these tokens were before the move.
--
--   No DB-level foreign key to `user`: session->user integrity stays in
--   application code (the bind action), matching the identity-layer
--   convention and the framework stub this table is copied from.

CREATE TABLE `hilos_session` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `impersonator_user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session_token` (`token`),
    KEY `idx_session_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `hilos_session`
    (`token`, `user_id`, `created_at`, `last_seen_at`)
SELECT
    `session_token`, `id`, NOW(), COALESCE(`last_activity`, NOW())
FROM `user`
WHERE `session_token` IS NOT NULL;

ALTER TABLE `user`
    DROP INDEX `session_token`,
    DROP COLUMN `session_token`;
