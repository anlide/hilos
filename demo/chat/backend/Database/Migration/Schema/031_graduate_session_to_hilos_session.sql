-- Migration: Graduate the session table to the framework-owned hilos_session
-- Created: 2026-07-23
-- Index: 031
-- Description:
--   HIL-361 graduates the session layer into the framework: the session ORM now
--   lives in Hilos\Database and maps the standardized `hilos_session` table
--   (utf8mb4_bin token, framework index names). This migration transitions the
--   chat-owned `session` table (created in 021, impersonator added in 023) onto
--   that framework table by creating hilos_session, moving every row across, and
--   dropping the old table. Migrations 021/023 stay in history for schema parity;
--   the down migration recreates `session` exactly as they left it.
--
--   No DB-level foreign key to `user`: session->user integrity stays in
--   application code (the bind action), matching the identity-layer convention.

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
    (`id`, `token`, `user_id`, `impersonator_user_id`, `created_at`, `last_seen_at`, `expires_at`)
SELECT
    `id`, `token`, `user_id`, `impersonator_user_id`, `created_at`, `last_seen_at`, `expires_at`
FROM `session`;

DROP TABLE `session`;
