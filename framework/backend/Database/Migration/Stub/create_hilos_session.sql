-- Migration: Create hilos_session table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_session.sql)
--
-- Framework-standardized session table (HIL-361). Separates the session
-- (transient, cookie-token keyed, may be anonymous) from the durable, project-
-- owned `user`. A session is anonymous (`user_id` NULL) or authenticated
-- (`user_id` bound after login/register); no visitor is auto-promoted to a user.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary. Session→user integrity is enforced in
-- application code (the bind action), matching the identity-layer convention and
-- keeping the anonymous-vs-authenticated split independent of the user row.
--
-- `token` uses utf8mb4_bin so the cookie-token lookup is exact / case-sensitive.
--
-- `impersonator_user_id` (HIL-166) remembers the real admin behind an active
-- impersonation: NULL means the session acts as its own bound user; a set value
-- means the current `user_id` is a target the admin is acting as, and the marker
-- holds the admin to restore on stop.

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
